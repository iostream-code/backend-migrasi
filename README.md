# driver-apk-backend

Backend khusus untuk [`driver-apk`](../driver-apk) (Cordova, tracking supir + monitor
admin). **Slim 4, tanpa ORM, tanpa migration** — PDO + SQL mentah langsung, skema tabel
didefinisikan di [`database/`](database) (file bernomor urut, dijalankan manual sekali per
file). Project **terpisah**
dari `backend-production`, tapi **login pakai akun pegawai yang sama** (`shared_m_users`) dan
tabel domainnya (`driver_*`) hidup di **database produksi yang sama** — cuma dikelola dari
codebase ini.

## Kenapa bukan Laravel/Lumen?

Versi awal project ini sempat dibuat pakai Laravel 13 + Sanctum, lalu dibongkar ulang atas
permintaan: filenya dianggap terlalu besar untuk kebutuhan sekecil ini, dan skema tabel harus
ikut pola apa adanya di `db_dump.sql` (SQL mentah, bukan lewat migration framework).

Lumen (alternatif "Laravel versi kecil") sengaja **tidak** dipakai — Lumen 10.x adalah rilis
terakhirnya, cuma dapat bug fix sampai Februari 2024 dan security fix sampai Agustus 2025.
Per sekarang sudah lebih dari setahun tanpa patch keamanan sama sekali, terlalu berisiko untuk
project baru.

**Slim 4** dipilih sebagai gantinya: micro-framework PSR-7/PSR-15 yang masih aktif
dikembangkan, `vendor/` cuma ~2MB (dibanding puluhan MB kalau pakai Laravel penuh), dan karena
tidak punya ORM bawaan, otomatis cocok dengan permintaan "tanpa migration" — kita memang tidak
punya pilihan lain selain SQL mentah.

## Arsitektur singkat

- **Tanpa migration**: [`database/`](database) berisi `CREATE TABLE`/`ALTER`/seed mentah,
  satu file per langkah, **bernomor urut sesuai urutan eksekusi** (`01_schema.sql`,
  `02_seed_admin_access.sql`, dst — lihat isi folder untuk daftar lengkap & urutannya).
  Ditulis mengikuti gaya persis tabel-tabel baru di `db_dump.sql` (`ENGINE=InnoDB`,
  `utf8mb4`/`utf8mb4_unicode_ci`, PK `id bigint unsigned AUTO_INCREMENT`, FK eksplisit —
  termasuk FK ke `shared_m_users` karena sekarang satu database yang sama). Jalankan manual,
  urut nomornya, satu-satu: `mysql -u <user> -p <database> < database/01_schema.sql`, dst.
  Tidak ada `php artisan migrate`/runner apa pun — kalau nanti ada perubahan skema baru, tambah
  file baru dengan nomor berikutnya (mis. `05_...sql`), jangan edit file yang sudah pernah
  dijalankan.
- **Tanpa ORM**: semua query pakai PDO + prepared statement langsung di tiap Controller
  (`src/Controllers/`). `src/Database.php` cuma wrapper koneksi PDO tipis (singleton).
- **Auth JWT stateless** (`firebase/php-jwt`): `POST /login` cek `username`+`password` ke
  `shared_m_users` (`password_verify()` — hash bcrypt `shared_m_users` kompatibel langsung
  dengan fungsi native PHP, tidak perlu Laravel `Hash` facade), lalu terbitkan token HS256
  berisi `{ sub: user_id, role, exp }`. **Tidak ada tabel penyimpan token** — server cukup
  verifikasi signature+expiry tiap request (`src/Middleware/AuthMiddleware.php`). Konsekuensi:
  token tidak bisa di-revoke satu-satu sebelum expired; kalau butuh itu, perlu tambah
  blacklist terpisah nanti.
- **Role**: tidak ada kolom role di `shared_m_users`. Admin/dispatcher ditentukan lewat
  whitelist tabel `driver_m_admin_access` (ada baris untuk `user_id` itu → admin). User lain
  otomatis diperlakukan sebagai supir. Profil `driver_m_supir` dibuat otomatis saat pertama
  login (`App\Support\SupirProfile::ensure()`), atau bisa di-provision lebih dulu oleh admin
  lewat `POST /admin/drivers` (fitur "Tambah Supir" di frontend) — keduanya idempotent &
  ujung-ujungnya manggil helper yang sama.
- **Endpoint admin digerbangi server-side** oleh `src/Middleware/AdminOnlyMiddleware.php` —
  cek `role` dari token, bukan cuma dipercaya dari router client-side.
- **Foto checkpoint** disimpan langsung di `public/uploads/trips/{trip_id}/` (file statis,
  dilayani langsung oleh webserver/`php artisan serve` setara — tidak lewat PHP untuk baca
  file kembali, beda dari pendekatan `Storage::disk()` Laravel).

## Setup

```bash
composer install
cp .env.example .env
```

Isi `.env` — **kredensial database produksi & JWT secret harus diisi manual**, jangan pernah
commit nilai aslinya:

```
DB_HOST=<host MySQL produksi>
DB_PORT=3306
DB_DATABASE=<nama database yang sama dipakai backend-production>
DB_USERNAME=<username>
DB_PASSWORD=<password>
JWT_SECRET=<string acak panjang, mis. `openssl rand -base64 48`>
```

```bash
mysql -u <username> -p <database> < database/01_schema.sql   # bikin 5 tabel driver_* baru (TIDAK menyentuh tabel lain)
php -S 127.0.0.1:8000 -t public                                # dev server
```

Lalu **seed minimal 1 admin** — tanpa ini tidak ada seorang pun yang bisa login sebagai
admin/dispatcher (`driver_m_admin_access` mulai kosong). Edit dulu daftar `username` di
[`database/02_seed_admin_access.sql`](database/02_seed_admin_access.sql), lalu:

```bash
mysql -u <username> -p <database> < database/02_seed_admin_access.sql
```

Cari berdasarkan `username` (bukan `user_id` mentah) supaya tidak perlu lihat-lihat ID manual,
dan aman dijalankan berkali-kali (idempotent) kalau nanti mau nambah admin lagi. Query
terakhir di skrip itu langsung menampilkan siapa saja yang berhasil ke-seed, untuk verifikasi.

Lanjutkan dengan sisa file `database/` sesuai nomor urutnya (`03_...`, `04_...`, dst) — lihat
daftar lengkap & fungsi tiap file di bagian [Struktur](#struktur) di bawah.

**Sudah dites end-to-end terhadap MySQL produksi asli** (bukan cuma sandbox dev) — login
sungguhan (`POST /login` dgn kredensial `shared_m_users` real), query `shared_m_users` (331
baris), seed admin (`ITAI`), `POST /admin/drivers` (bikin profil supir), dan
`GET /admin/surat-jalan/{no}` (join ke `surat_jalan` produksi, dapat data client asli) semuanya
sudah dicoba langsung & berhasil.

## Kontrak API

Sama persis dengan versi Laravel sebelumnya — tidak ada perubahan kontrak yang dilihat
frontend, cuma implementasi & auth mechanism-nya yang beda (Bearer JWT, tetap tanpa cookie).

| Method | Endpoint | Auth | Body / Catatan |
|---|---|---|---|
| POST | `/login` | publik | `{ username, password }` → `{ token, role, user: { id, name } }` |
| POST | `/logout` | token | stateless (JWT) — tidak ada yang dihapus di server, dipertahankan utk kompatibilitas kontrak FE |
| GET | `/driver/whoami` | token | `{ role, user }` — dipertahankan utk kompatibilitas kontrak lama driver-apk |
| GET | `/driver/me` | token | `{ id, name, status, active_trips: [...] }` |
| POST | `/driver/status` | token | `{ status: 'online'\|'resting'\|'offline' }` |
| POST | `/driver/location` | token | `{ lat, lng, speed, heading, accuracy, recorded_at }` |
| GET | `/driver/trip/{trip}` | token | `{ id, destination, status, completed_steps, current_step_label }` |
| POST | `/driver/trip/{trip}/photo` | token | multipart: `photo`, `type` (`berangkat`\|`serah_terima`\|`sj`), `lat`, `lng` |
| POST | `/driver/trip/{trip}/complete` | token | — |
| GET | `/admin/drivers` | token + admin | `[{ id, name, status, lat, lng, current_step_label }]` |
| POST | `/admin/drivers` | token + admin | `{ username }` → `{ id, name, status }`, 201. Cari akun di `shared_m_users` lewat `username`, buat/pastikan profil `driver_m_supir`-nya ada (idempotent — kalau sudah py profil, yang lama dikembalikan apa adanya) |
| GET | `/admin/drivers/{driver}` | token + admin | `{ id, name, phone, status, trips: [...] }` |
| POST | `/admin/drivers/{driver}/trip` | token + admin | `{ destination, no_surat_jalan?, penjualan_id? }` — keduanya opsional, kalau diisi WAJIB cocok baris asli (lihat bagian Integrasi di bawah) |
| GET | `/admin/surat-jalan/{no}` | token + admin | Cek 1 nomor SJ asli (READ-ONLY ke `surat_jalan` milik `backend-production`) → `{ no_surat_jalan, tanggal, kendaraan, plat, pengirim, valid_cs, penjualan_id, client_nama, client_alamat }`, 404 kalau tidak ketemu |
| GET | `/admin/spk-ready-kirim` | token + admin | Daftar SPK yang sudah disetujui utk dikirim tapi belum diplot ke supir/ekspedisi manapun (READ-ONLY ke `t_penjualan_header`) → `[{ penjualan_id, no_spk, client_nama, kota_asal, kota_tujuan, penjualan_tanggal_kirim, tgl_cs_deadline, penjualan_total_qty }]` |
| GET | `/admin/ekspedisi` | token + admin | Daftar perusahaan ekspedisi aktif (READ-ONLY ke `m_expedisi`) → `[{ id_expedisi, kode_expedisi, nama_expedisi, pic, no_telp }]` |
| POST | `/admin/ekspedisi` | token + admin | `{ penjualan_id, id_expedisi, no_resi?, biaya_kirim?, catatan? }` → 201. Serahkan 1 SPK ke ekspedisi luar — bikin baris `driver_t_ekspedisi`, paralel dgn `POST /admin/drivers/{driver}/trip` tapi tidak terikat supir manapun |

`{driver}`/`{trip}` di URL adalah **id `driver_m_supir`/`driver_t_trip`** (bukan `user_id`
`shared_m_users`).

## Integrasi dengan `backend-production`

### `surat_jalan`

`driver_t_trip` punya kolom opsional **`no_surat_jalan`**, tautan LOGIS (bukan FK asli) ke
`surat_jalan.no_surat_jalan` — tabel lama milik `backend-production` (`latin1`, ~6.255 baris,
dipakai `surat-jalan-apk`/`produksi-apk`/`finance-apk`). Bukan FK asli karena
`no_surat_jalan` **bukan kolom unik** di `surat_jalan` — 1 nomor SJ = banyak baris (1 baris
per item produk dalam pengiriman itu).

Yang sudah ada:
- `App\Support\SuratJalanLookup` — query **READ-ONLY** (`surat_jalan` JOIN
  `t_penjualan_detail_performa` JOIN `t_penjualan_header` LEFT JOIN `m_client`) buat validasi
  & preview info SJ (nama client, kendaraan, plat, pengirim). Tidak pernah menulis ke tabel
  manapun di luar `driver_*`.
- `POST /admin/drivers/{driver}/trip` menolak (422) kalau `no_surat_jalan` diisi tapi tidak
  cocok SJ manapun — mencegah typo bikin tautan basi.
- `GET /admin/surat-jalan/{no}` — dipakai tombol "Cek" di form "Perjalanan Baru" (`driver-apk`)
  buat preview sebelum submit.

**Yang SENGAJA belum dikerjakan** (di luar cakupan tabel `driver_*`, butuh keputusan/otorisasi
terpisah sebelum disentuh):
- **Belum ada auto-fill/auto-create.** Trip TIDAK otomatis mengisi `destination`/`kendaraan`
  dari SJ yang ditautkan, dan checkpoint foto "sj" milik supir TIDAK otomatis mengisi
  `surat_jalan.foto_surat_jalan`. Keduanya berdiri sendiri, cuma ditautkan lewat nomor.
- **Alur validasi CS (`valid_cs`) tidak tersentuh.** Ada endpoint di `backend-production`
  yang men-set `valid_cs=1` (`POST /update-valid-notif-kirim`, `CsController::updateValidNotifKirim`
  — sekaligus men-trigger webhook finalisasi Point/komisi), TAPI setelah ditelusuri ke semua
  app frontend di workspace ini, **tidak ada satu pun yang memanggilnya** — `finance-apk`
  cuma menampilkan `valid_cs` read-only. Belum jelas mekanisme validasi CS yang sebenarnya
  dipakai sekarang. Perlu klarifikasi user sebelum ada tindakan lanjut di sisi ini.

Kalau nanti mau dilanjutkan (auto-fill, atau tombol "Validasi CS" di app ini yang manggil
`update-valid-notif-kirim` di `backend-production` via HTTP client — BUKAN nulis langsung ke
`surat_jalan`), itu keputusan terpisah, di luar scope perubahan `driver_*` yang sudah dikerjakan.

### SPK ready-kirim (`t_penjualan_header`)

`driver_t_trip` juga punya kolom opsional **`penjualan_id`**, tautan LOGIS ke
`t_penjualan_header.penjualan_id` — dipakai buat menautkan trip ke SPK **sebelum** SJ fisiknya
ada (beda dari `no_surat_jalan` yang baru relevan **setelah** SJ dibuat). Alur aslinya, ditelusuri
dari kode `backend-production`:

1. Order dibuat, CS isi `penjualan_tanggal_kirim` (tanggal kirim yang diminta customer).
2. Saat alamat kirim disimpan (`ServiceController::updateAlamatKirim()`), sistem cek status
   pembayaran: **belum lunas** → `shipment_status = 'requested'` (kirim notifikasi Firebase,
   minta approval — **ini titik "admin mengajukan ke finance"** yang disebut). **Sudah lunas**
   → langsung `shipment_status = 'approved'`, tanpa approval.
3. `ServiceController::approveShipment()` (endpoint terpisah, approve/reject) memindahkan
   `requested` → `approved`/`rejected`.
4. Order dengan `shipment_status = 'approved'` DAN `status_pengirman = 'belum_selesai'` siap
   diplot ke supir — ini yang ditampilkan `GET /admin/spk-ready-kirim`.

**Sudah dites & LIVE dengan data asli** (2026-08-19): dari database produksi, ada 2.005 order
`pending`, 8 `requested` (menunggu approval), 7 `approved` — 3 di antaranya `approved` +
`belum_selesai` (siap kirim sungguhan, bukan data test).

Ada juga endpoint `Ekspedisi\EkspedisiController::getSpkReadyKirim()` di `backend-production`
dengan query serupa (plus sistem rekomendasi ekspedisi luar berbasis skor harga/kecepatan/
histori ketepatan waktu: `getRekomendasiEkspedisi`, `setEkspedisiPenjualan`, tabel
`m_expedisi`/`m_expedisi_tarif`/`t_pengiriman`) — **tapi tidak dipanggil app manapun di
workspace ini, dan tabelnya kosong/cuma data test di database live.** Sengaja **tidak**
diintegrasikan ke sini — `App\Support\SpkReadyKirim` di project ini query sendiri (versi
sederhana, dikecualikan berdasar `driver_t_trip.penjualan_id`, bukan `t_pengiriman_detail`)
supaya tidak bergantung pada sistem ekspedisi luar yang belum pernah hidup. Kalau nanti mau
sekalian pakai jalur ekspedisi luar, itu scope terpisah — lihat histori git utk detail
pertimbangannya. **(Update: jalur ekspedisi luar sekarang ADA, lihat subbagian di bawah —
tapi tetap tabel driver_* sendiri, bukan menghidupkan sistem Ekspedisi backend-production yang
disebut di atas.)**

Yang sudah ada di sisi driver-apk: halaman **"SPK Siap Kirim"** (`adminSpkKirim.js`) — daftar
SPK ready-kirim, admin pilih supir dari dropdown per baris, klik "Plot" langsung bikin
`driver_t_trip` tertaut ke `penjualan_id` itu (destination di-compose otomatis dari
`client_nama` + `kota_tujuan`).

### Ekspedisi luar / pihak ketiga (`driver_t_ekspedisi`)

Tabel baru **`driver_t_ekspedisi`** (`database/06_...sql`) — paralel dengan `driver_t_trip`,
tapi utk SPK yang diserahkan ke ekspedisi luar (bukan supir internal). Satu SPK dari
`SpkReadyKirim` ujungnya masuk ke `driver_t_trip` ATAU `driver_t_ekspedisi`, tidak dua-duanya
— dicegah di level query (`SpkReadyKirim::list()` sekarang mengecualikan kedua tabel), bukan
constraint DB.

Kolom kunci: `penjualan_id` (tautan logis ke SPK, WAJIB diisi — beda dari `driver_t_trip` yang
opsional), `id_expedisi` (tautan logis ke `m_expedisi.id_expedisi`), `nama_expedisi`
(**snapshot**, bukan selalu di-JOIN ulang — supaya histori tetap benar walau nama ekspedisi
diedit belakangan di `m_expedisi`), `no_resi`/`biaya_kirim`/`catatan` (diisi manual, `no_resi`
biasanya belum ada di saat penyerahan), `status` (`dijadwalkan` → `dikirim`/`sampai`/`batal` —
mengikuti gaya `t_pengiriman.status` di `backend-production` tapi TABEL SENDIRI, tidak
menyentuh `t_pengiriman` yang memang belum pernah dipakai).

`App\Support\ExpedisiLookup` — query READ-ONLY ke `m_expedisi`/`m_expedisi_tarif` (daftar
ekspedisi aktif + tarif per rute kalau ada; `tarif()` sudah ada tapi belum disambungkan ke
endpoint manapun — perkiraan biaya opsional utk dikerjakan belakangan kalau perlu).

**Belum ada** (di luar scope "buatkan skema" sesi ini, tunggu keputusan lanjut): halaman admin
utk memilih ekspedisi & submit `POST /admin/ekspedisi` (endpoint backend-nya sudah ada &
sudah dites, tapi UI di `driver-apk` belum dibuat — saat ini cuma `driver_t_trip`/plotting
supir yang punya UI, lewat `adminSpkKirim.js`), dan status lifecycle
(`dikirim`/`sampai`/`batal`) belum ada endpoint utk diubah setelah baris dibuat.

## Struktur

```
driver-apk-backend/
├── database/                 # SQL mentah, satu file per langkah, dijalankan manual URUT NOMOR
│   ├── 01_schema.sql            # CREATE TABLE 5 tabel driver_* -- bukan migration, sekali jalan
│   ├── 02_seed_admin_access.sql  # seed whitelist admin/dispatcher (cari by username, idempotent)
│   ├── 03_seed_dummy_drivers.sql # pre-provision profil supir dummy (cari by username, idempotent)
│   ├── 04_alter_add_no_surat_jalan_to_driver_t_trip.sql  # ALTER: tambah driver_t_trip.no_surat_jalan
│   ├── 05_add_penjualan_id_to_driver_t_trip.sql          # ALTER: tambah driver_t_trip.penjualan_id
│   └── 06_create_driver_t_ekspedisi.sql                  # CREATE TABLE driver_t_ekspedisi (jalur ekspedisi luar)
├── public/
│   ├── index.php             # front controller
│   └── uploads/trips/{id}/   # foto checkpoint, disajikan langsung sbg file statis
└── src/
    ├── bootstrap.php          # bangun Slim App: middleware, CORS, error handler, routes
    ├── Database.php            # wrapper koneksi PDO (singleton)
    ├── Support/
    │   ├── Jwt.php               # terbitkan & verifikasi token HS256
    │   ├── SupirProfile.php       # ambil/buat baris driver_m_supir (dipakai Auth & DriverController)
    │   ├── TripPresenter.php      # format baris driver_t_trip -> shape JSON, konstanta STEPS
    │   ├── SuratJalanLookup.php   # query READ-ONLY ke surat_jalan (integrasi, lihat bagian di atas)
    │   ├── SpkReadyKirim.php      # query READ-ONLY ke t_penjualan_header (integrasi SPK ready-kirim)
    │   └── ExpedisiLookup.php     # query READ-ONLY ke m_expedisi/m_expedisi_tarif (integrasi ekspedisi luar)
    ├── Middleware/
    │   ├── AuthMiddleware.php      # cek Authorization: Bearer <token>, taruh user_id/role di request
    │   └── AdminOnlyMiddleware.php  # tolak 403 kalau role token bukan 'admin'
    └── Controllers/
        ├── Controller.php     # helper json()/error() dipakai semua controller
        ├── AuthController.php  # login, logout
        ├── DriverController.php  # /driver/*
        └── AdminController.php   # /admin/*
```

## Yang belum ada / perlu diputuskan ke depan

- **`driver_t_location` akan tumbuh terus** (1 baris tiap ~30 detik per supir yang online) —
  belum ada retensi/pruning. Perlu cron/`cron.d` terpisah untuk hapus data lebih lama dari
  N hari kalau volumenya jadi masalah (tidak ada scheduler bawaan seperti Laravel `schedule`
  di Slim, harus di-setup manual di level OS).
- **Token JWT tidak expire lebih cepat dari `JWT_TTL_HOURS`** dan tidak bisa di-revoke satu
  per satu (lihat catatan Auth di atas) — kalau ini jadi masalah nyata, opsinya nambah tabel
  blacklist token yang sudah dicabut (dicek di `AuthMiddleware`), atau pindah ke token opaque
  tersimpan di DB.
- Tidak ada rate limiting di `/login` — pertimbangkan tambah kalau app ini publik-facing.
- `public/uploads/` perlu dipastikan writable oleh proses PHP saat deploy sungguhan, dan
  idealnya di luar root `public/` yang di-serve langsung kalau butuh kontrol akses foto lebih
  ketat (mis. supir cuma boleh lihat foto tripnya sendiri) — saat ini SEMUA foto yang sudah
  diupload bisa diakses siapa pun yang tahu URL-nya (tidak ada pengecekan token saat GET file).
