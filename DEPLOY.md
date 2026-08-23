# Deploy ke shared hosting

Panduan ini utk deploy `backend-migrasi` (Slim 4 / PHP 8.1, tanpa ORM, PDO
manual -- lihat README.md) ke shared hosting (cPanel/DirectAdmin/dsb). Mengikuti
pola deploy manual (zip/upload) yang sudah dipakai `backend-production` &
aplikasi Cordova legacy di workspace ini -- **bukan** CI/CD lewat git push.

Database MySQL yang dipakai app ini **SAMA PERSIS** dengan `backend-production`
(`indokoper.com` / db `tasindo`, lihat `.env` lokal) -- kalau skema
`ekspedisi_*` (`database/ekspedisi/01_schema.sql`) sudah pernah dijalankan ke
DB itu sebelumnya (cek riwayat kerja / README), **langkah database di bawah
bisa dilewati**, tinggal deploy kodenya saja. (Sudah kejadian utk DB produksi
saat ini -- termasuk kolom `nomor_urut` hasil "Konsolidasi Keempat"
2026-08-23, lihat catatan di kepala `01_schema.sql` -- skip langkah 7.)

## 1. Yang perlu disiapkan di hosting

- **PHP 8.1 atau lebih baru** -- di cPanel: menu *MultiPHP Manager* /
  *Select PHP Version*, pilih versi utk domain/subdomain yang dipakai.
  Composer.json app ini strict `"php": "^8.1"`, versi lebih lama (7.x, umum
  jadi default shared hosting) akan gagal total dari `composer install`.
- **Ekstensi PHP aktif**: `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`.
  Plus **`gd`** (opsional tapi disarankan -- dipakai `PhotoStorage` utk
  konversi foto upload ke WEBP; kalau tidak ada, app tetap jalan tapi foto
  disimpan apa adanya tanpa dikonversi, lihat `src/Support/PhotoStorage.php`).
  Cek/centang semua ini di *MultiPHP INI Editor* / *PHP Extensions*.
- **Akses MySQL** ke database yang sama dengan `backend-production` (host,
  nama db, user, password -- sudah ada di `.env` lokal, JANGAN commit file
  ini, salin manual ke server).
- **SSH + Composer**, kalau hosting menyediakan (banyak hosting Indonesia
  kelas menengah/atas kasih SSH). Kalau TIDAK ada SSH, lihat opsi B di
  langkah 3.
- Idealnya **domain/subdomain terpisah** khusus utk API ini (mis.
  `ekspedisi-api.indokoper.com`), dengan **document root diarahkan ke folder
  `public/`** milik repo ini -- bukan root repo. Ini pengaturan paling aman &
  paling standar utk app PHP model front-controller (Slim/Laravel/dll).

## 2. Upload kode

`vendor/` dan `.env` sengaja di-`.gitignore`, jadi **tidak ikut ter-push ke
git** -- keduanya harus disiapkan terpisah di server (langkah 3 & 4).

Opsi upload (pilih salah satu, sesuai fasilitas hosting):

- **Git (kalau hosting punya akses git/SSH)**: `git clone`/`git pull` repo ini
  langsung di server, di folder di luar `public_html` kalau bisa (supaya kode
  yang bukan `public/` tidak otomatis ke-serve web, lihat langkah 5).
- **Zip upload (paling umum di shared hosting murah)**: dari mesin dev,
  `git archive -o backend-migrasi.zip HEAD` (cuma isi yang sudah
  di-commit, `vendor/`/`.env` otomatis tidak ikut karena di-gitignore) lalu
  upload lewat File Manager cPanel / FTP, extract di server.

## 3. Install dependency (`vendor/`)

**Opsi A -- ada SSH & Composer di server (disarankan):**

```bash
cd /path/ke/repo
composer install --no-dev --optimize-autoloader
```

`--no-dev` penting (skip dependency development-only, kalau ada), `--optimize-autoloader`
mempercepat autoload di production (sudah di-set jadi default lewat
`config.optimize-autoloader` di `composer.json`, flag ini menegaskan ulang).

**Opsi B -- tidak ada SSH/Composer di server:**

Jalankan `composer install --no-dev --optimize-autoloader` di mesin dev
(lokal), lalu **upload folder `vendor/` hasilnya** bareng sisa kode lewat
File Manager/FTP (opsi zip di langkah 2, tambahkan `vendor/` ke dalam zip
manual krn `git archive` tidak akan menyertakannya).

## 4. Konfigurasi `.env`

Copy `.env.example` jadi `.env` di server (bukan lewat git -- upload/isi manual
lewat File Manager), lalu isi:

```bash
APP_ENV=production
APP_DEBUG=false          # WAJIB false di production -- kalau true, semua
                          # exception/stack trace PHP ke-expose ke response
                          # JSON (lihat src/bootstrap.php errorMiddleware),
                          # bisa bocorin detail internal ke siapa pun yg hit API.
APP_URL=https://ekspedisi-api.indokoper.com   # domain sungguhan API ini,
                          # dipakai nyusun URL publik foto checkpoint

DB_HOST=indokoper.com    # sama dgn backend-production, lihat .env lokal
DB_PORT=3306
DB_DATABASE=tasindo
DB_USERNAME=...
DB_PASSWORD=...

JWT_SECRET=...           # WAJIB diganti dari default template, string acak
                          # panjang -- generate lokal: openssl rand -base64 48
JWT_TTL_HOURS=720
```

Set permission `.env` seketat mungkin (`chmod 640` atau sesuai kebijakan
hosting) -- isinya kredensial DB + JWT secret.

## 5. Document root -> folder `public/`

**Opsi A (disarankan) -- hosting bisa atur document root custom:**

Di cPanel: *Domains* / *Subdomains* -> saat bikin subdomain, isi *Document
Root* langsung ke `.../backend-migrasi/public` (bukan folder repo-nya).
Dengan ini, `src/`, `vendor/`, `database/`, `.env`, `composer.json` semua
otomatis TIDAK bisa diakses lewat URL sama sekali (di luar webroot) --
paling aman, tidak perlu proteksi tambahan.

**Opsi B -- hosting cuma kasih 1 document root tetap (mis. `public_html`),
tidak bisa diarahkan ke subfolder:**

Taruh seluruh isi repo (termasuk `vendor/`, `.env` yang sudah diisi) langsung
di `public_html/`. Repo ini sudah menyertakan **`.htaccess` di root** yang
otomatis redirect semua request ke `public/index.php` DAN blokir akses
langsung ke `composer.json`, `composer.lock`, `.env`, `*.sql`, serta folder
`src/`/`vendor/`/`database/` -- tapi ini fallback, **kalau opsi A
memungkinkan, pakai opsi A**. Verifikasi proteksinya jalan setelah deploy:
`https://domain-anda/composer.json` dan `https://domain-anda/.env` HARUS
403/404, bukan nampilin isi filenya.

`public/.htaccess` (buat mod_rewrite front-controller Slim + terusin header
`Authorization`, dipakai JWT Bearer) sudah ada juga di repo -- pastikan
module `mod_rewrite` aktif di server (hampir selalu aktif default di shared
hosting berbasis Apache/LiteSpeed cPanel; kalau pakai Nginx murni tanpa
Apache compat layer, perlu rule setara manual, lihat catatan Nginx di bawah).

## 6. Folder upload foto (`public/uploads/`)

`public/uploads/trips`, `public/uploads/sj`, `public/uploads/drivers` dibuat
otomatis oleh `PhotoStorage::save()` saat upload pertama kali per konteks
(`mkdir($baseDir, 0755, true)`, lihat `src/Support/PhotoStorage.php`) -- tidak
perlu dibuat manual. Yang perlu dipastikan: **folder `public/uploads/`
writable oleh user proses PHP** (biasanya user cPanel yang sama, jarang jadi
masalah di shared hosting selama permission default `public_html` tidak
diubah manual jadi read-only).

Kalau upload foto gagal (`path` balik `null` dari response API tapi tidak ada
error lain), cek dulu permission folder ini duluan sebelum curiga ke tempat
lain -- `PhotoStorage::save()` sengaja tidak melempar exception kalau gagal
tulis file, cuma balik `null`.

## 7. Database

Kemungkinan besar **sudah tidak perlu** (skema `ekspedisi_*` dijalankan ke DB
produksi yang sama dengan `backend-production`, di sesi kerja sebelumnya).
Verifikasi cepat lewat phpMyAdmin/CLI:

```sql
SHOW TABLES LIKE 'ekspedisi_%';
```

Kalau 9 tabel (`ekspedisi_m_ekspedisi`, `ekspedisi_m_supir`,
`ekspedisi_m_admin_access`, `ekspedisi_t_trip`, `ekspedisi_t_trip_photo`,
`ekspedisi_t_location`, `ekspedisi_t_pengajuan_biaya`,
`ekspedisi_t_surat_jalan`, `ekspedisi_t_surat_jalan_item`) sudah ada, skip
langkah ini. Kalau belum ada sama sekali (DB baru/fresh), jalankan urut:

```bash
mysql -h <host> -u <user> -p <database> < database/ekspedisi/01_schema.sql
mysql -h <host> -u <user> -p <database> < database/ekspedisi/02_seed_admin_access.sql
# 03_seed_dummy_drivers.sql cuma data dummy utk testing -- JANGAN dijalankan
# ke production kalau tidak mau ada supir palsu di data sungguhan.
```

`config` row utk version-check (`VERSION_EKSPEDISI_PUSAT`) juga perlu ada
(lihat README bagian "Cek versi app") -- cek dulu sebelum insert ulang:

```sql
SELECT * FROM config WHERE config_id = 'VERSION_EKSPEDISI_PUSAT';
```

## 8. Verifikasi setelah deploy

```bash
curl -i https://ekspedisi-api.indokoper.com/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"...","password":"..."}'
```

Harus balas JSON (bukan halaman error Apache/PHP fatal HTML) -- kalau
username/password salah pun harusnya tetap JSON `{"message": "..."}` sesuai
`errorMiddleware` di `src/bootstrap.php`, bukan HTML 500 generik (kalau HTML
generik yang muncul, kemungkinan besar `APP_DEBUG`/routing/`.htaccess` belum
benar, bukan masalah kredensial).

Cek juga:
- `Access-Control-Allow-Origin: *` ada di response header (dites dari
  browser devtools/`curl -i`) -- app frontend (`ekspedisi-apk`) jalan di
  origin beda (`file://` di WebView Android), butuh CORS ini.
- Upload foto (lewat app asli atau `curl -F`) beneran nyimpen file & balik
  path yang bisa diakses via `APP_URL/uploads/...`.

## 9. Terakhir: update `API_BASE_URL` di frontend

Setelah backend live di domain sungguhan, update `API_BASE_URL` di
`ekspedisi-apk/src/js/config.js` (repo frontend) ke URL production ini,
`npm run build` ulang, lalu build APK Android baru (lihat
`ekspedisi-apk/README.md` bagian "Build ke Android/iOS") -- APK yang sudah
ter-install di HP driver/admin sebelumnya masih nunjuk ke URL lama sampai
di-update manual (tidak ada OTA update kode, cuma version-check yang minta
user update manual, lihat README bagian "Cek versi app").

Kalau backend akhirnya di-serve HTTPS penuh (disarankan, JWT Bearer token
sebaiknya tidak lewat http polos), preference `usesCleartextTraffic` di
`ekspedisi-apk/config.xml` (diaktifkan awalnya utk development http) bisa
dipertimbangkan dicabut lagi -- di luar cakupan panduan ini, cek README
frontend bagian "Build ke Android/iOS" kalau mau lanjutkan.

## Catatan Nginx

Kalau hosting ternyata pakai Nginx murni (jarang di shared hosting
konsumer, tapi ada di VPS-managed-sebagai-shared-hosting), `.htaccess`
tidak berlaku -- perlu rule setara di `server{}` block (biasanya cuma bisa
diminta ke provider hosting, bukan dari sisi kita):

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

(document root tetap harus diarahkan ke `public/`.)
