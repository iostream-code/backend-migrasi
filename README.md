# ekspedisi-apk-backend

Backend aplikasi **Ekspedisi** (dulu bernama "driver-apk" / tracking supir — sedang berkembang
jadi aplikasi ekspedisi yang lebih luas, rencana ke depan juga mencakup modul manajemen surat
jalan, menyusul terpisah). Frontend-nya [`ekspedisi-apk`](../ekspedisi-apk) (Cordova). **Slim 4,
tanpa ORM, tanpa migration** — PDO + SQL mentah langsung, skema tabel didefinisikan di
[`database/`](database) (file bernomor urut, dijalankan manual sekali per file). Project
**terpisah** dari `backend-production`, tapi **login pakai akun pegawai yang sama**
(`shared_m_users`) dan tabel domainnya (`ekspedisi_*`) hidup di **database produksi yang sama**
— cuma dikelola dari codebase ini.

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

## Riwayat nama: driver-apk-backend → ekspedisi-apk-backend

Project ini awalnya khusus tracking supir internal ("driver-apk"). Berkembang jadi aplikasi
ekspedisi yang lebih luas (supir internal + eksternal, plotting SPK, rencana modul surat jalan)
— nama & prefix tabel di-rename mengikuti (`driver_*` → `ekspedisi_*`, lihat
`database/10_rename_driver_to_ekspedisi.sql`). Migration `01`–`09` **sengaja tidak diedit** —
itu arsip historis apa yang benar-benar dijalankan saat itu (tabelnya memang bernama `driver_*`
waktu dibuat); kode aplikasi (PHP) sekarang 100% merujuk nama baru. Detail lengkap kenapa &
pertimbangannya ada di histori git (commit rename).

## Arsitektur singkat

- **Tanpa migration**: [`database/`](database) berisi `CREATE TABLE`/`ALTER`/`RENAME`/seed
  mentah, satu file per langkah, **bernomor urut sesuai urutan eksekusi** (`01_schema.sql`,
  `02_seed_admin_access.sql`, dst — lihat isi folder untuk daftar lengkap & urutannya).
  Ditulis mengikuti gaya persis tabel-tabel baru di `db_dump.sql` (`ENGINE=InnoDB`,
  `utf8mb4`/`utf8mb4_unicode_ci`, PK `id bigint unsigned AUTO_INCREMENT`, FK eksplisit —
  termasuk FK ke `shared_m_users` karena sekarang satu database yang sama). Jalankan manual,
  urut nomornya, satu-satu: `mysql -u <user> -p <database> < database/01_schema.sql`, dst.
  Tidak ada `php artisan migrate`/runner apa pun — kalau nanti ada perubahan skema baru, tambah
  file baru dengan nomor berikutnya (mis. `11_...sql`), jangan edit file yang sudah pernah
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
  whitelist tabel `ekspedisi_m_admin_access` (ada baris untuk `user_id` itu → admin). User lain
  otomatis diperlakukan sebagai supir. Profil `ekspedisi_m_supir` dibuat otomatis saat pertama
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

**Instalasi baru** (belum pernah jalankan file `database/` manapun) — jalankan **semua** file
urut nomor, `01` sampai yang terbaru, TANPA kecuali. Ini penting: `01`–`09` historis membuat
tabel bernama `driver_*` (nama lama, apa adanya saat pertama ditulis) — **baru file `10` yang
me-rename ke `ekspedisi_*`**. Kode aplikasi (PHP) di repo ini cuma kenal nama `ekspedisi_*` —
kalau berhenti di file `09` dan lupa jalankan `10`, app ini tidak akan jalan sama sekali (semua
query gagal, tabel "tidak ditemukan"):

```bash
mysql -u <username> -p <database> < database/01_schema.sql
mysql -u <username> -p <database> < database/02_seed_admin_access.sql   # edit daftar username dulu, lihat di bawah
mysql -u <username> -p <database> < database/03_seed_dummy_drivers.sql  # opsional
mysql -u <username> -p <database> < database/04_alter_add_no_surat_jalan_to_driver_t_trip.sql
mysql -u <username> -p <database> < database/05_add_penjualan_id_to_driver_t_trip.sql
mysql -u <username> -p <database> < database/06_create_driver_t_ekspedisi.sql
mysql -u <username> -p <database> < database/07_alter_driver_m_supir_add_eksternal.sql
mysql -u <username> -p <database> < database/08_drop_driver_t_ekspedisi.sql
mysql -u <username> -p <database> < database/09_create_driver_t_pengajuan_biaya.sql
mysql -u <username> -p <database> < database/10_rename_driver_to_ekspedisi.sql   # WAJIB -- lihat catatan di atas
php -S 127.0.0.1:8000 -t public
```

**Instalasi yang sudah pernah jalankan `01`–`09` sebelumnya** (mis. lanjutan sesi sebelum rename
ini ada) — tinggal jalankan `10_rename_driver_to_ekspedisi.sql` saja.

Edit dulu daftar `username` di [`database/02_seed_admin_access.sql`](database/02_seed_admin_access.sql)
sebelum menjalankannya — cari berdasarkan `username` (bukan `user_id` mentah) supaya tidak
perlu lihat-lihat ID manual, dan aman dijalankan berkali-kali (idempotent) kalau nanti mau
nambah admin lagi. Query terakhir di skrip itu langsung menampilkan siapa saja yang berhasil
ke-seed, untuk verifikasi. Tanpa ini tidak ada seorang pun yang bisa login sebagai
admin/dispatcher (`ekspedisi_m_admin_access` mulai kosong).

**Sudah dites end-to-end terhadap MySQL produksi asli** (bukan cuma sandbox dev), sebelum
rename ke `ekspedisi_*` — login sungguhan (`POST /login` dgn kredensial `shared_m_users` real),
query `shared_m_users` (331 baris), seed admin (`ITAI`), `POST /admin/drivers` (bikin profil
supir), `POST /admin/drivers/{driver}/trip` dengan `penjualan_id` SPK asli, dan
`GET /admin/surat-jalan/{no}` (join ke `surat_jalan` produksi, dapat data client asli) semuanya
sudah dicoba langsung & berhasil. **Rename ke `ekspedisi_*` sendiri belum dites ulang di
lingkungan produksi** (butuh Anda jalankan `database/10_...sql` dulu) — struktur/logic-nya
sama persis, cuma nama tabel yang beda, risikonya rendah, tapi tetap perlu diverifikasi sekali
lagi setelah dijalankan.

## Kontrak API

Sama persis dengan versi Laravel sebelumnya — tidak ada perubahan kontrak yang dilihat
frontend, cuma implementasi & auth mechanism-nya yang beda (Bearer JWT, tetap tanpa cookie).

| Method | Endpoint | Auth | Body / Catatan |
|---|---|---|---|
| POST | `/login` | publik | `{ username, password }` → `{ token, role, user: { id, name } }` |
| POST | `/logout` | token | stateless (JWT) — tidak ada yang dihapus di server, dipertahankan utk kompatibilitas kontrak FE |
| GET | `/driver/whoami` | token | `{ role, user }` — dipertahankan utk kompatibilitas kontrak lama |
| GET | `/driver/me` | token | `{ id, name, status, active_trips: [...] }` |
| POST | `/driver/status` | token | `{ status: 'online'\|'resting'\|'offline' }` |
| POST | `/driver/location` | token | `{ lat, lng, speed, heading, accuracy, recorded_at }` |
| GET | `/driver/trip/{trip}` | token | `{ id, destination, status, completed_steps, current_step_label }` |
| POST | `/driver/trip/{trip}/photo` | token | multipart: `photo`, `type` (`berangkat`\|`serah_terima`\|`sj`), `lat`, `lng` |
| POST | `/driver/trip/{trip}/complete` | token | — |
| GET | `/admin/drivers` | token + admin | `[{ id, tipe, name, status, lat, lng, current_step_label }]` — `tipe`: `internal`\|`eksternal` |
| POST | `/admin/drivers` | token + admin | Internal: `{ tipe: 'internal', username }` (idempotent, cari akun `shared_m_users`). Eksternal: `{ tipe: 'eksternal', nama, telepon?, id_expedisi? }` (bukan pegawai, tidak bisa login). → `{ id, name, status, tipe }`, 201 |
| GET | `/admin/drivers/{driver}` | token + admin | `{ id, tipe, name, phone, status, trips: [...] }` |
| POST | `/admin/drivers/{driver}/trip` | token + admin | `{ destination, no_surat_jalan?, penjualan_id? }` — keduanya opsional, kalau diisi WAJIB cocok baris asli (lihat bagian Integrasi di bawah) |
| GET | `/admin/surat-jalan/{no}` | token + admin | Cek 1 nomor SJ asli (READ-ONLY ke `surat_jalan` milik `backend-production`) → `{ no_surat_jalan, tanggal, kendaraan, plat, pengirim, valid_cs, penjualan_id, client_nama, client_alamat }`, 404 kalau tidak ketemu |
| GET | `/admin/spk-ready-kirim` | token + admin | Daftar SPK yang sudah disetujui utk dikirim tapi belum diplot ke supir manapun (READ-ONLY ke `t_penjualan_header`) → `[{ penjualan_id, no_spk, client_nama, kota_asal, kota_tujuan, penjualan_tanggal_kirim, tgl_cs_deadline, penjualan_total_qty }]` |
| GET | `/admin/ekspedisi` | token + admin | Daftar perusahaan ekspedisi aktif (READ-ONLY ke `m_expedisi`) → `[{ id_expedisi, kode_expedisi, nama_expedisi, pic, no_telp }]` — dipakai dropdown opsional saat Tambah Supir Eksternal |
| POST | `/admin/trips/{trip}/pengajuan-biaya` | token + admin | `{ nominal_diajukan, keterangan? }` → 201. `nominal_diajukan` input manual admin. Berlaku utk trip supir internal maupun eksternal |
| GET | `/admin/trips/{trip}/pengajuan-biaya` | token + admin | Riwayat pengajuan biaya utk 1 trip → `[{ id, trip_id, nominal_diajukan, status, nominal_disetujui, catatan_finance, ... }]` |

`{driver}`/`{trip}` di URL adalah **id `ekspedisi_m_supir`/`ekspedisi_t_trip`** (bukan `user_id`
`shared_m_users`). Route path-nya sendiri (`/driver/*`, `/admin/drivers`) sengaja TIDAK ikut
di-rename ke `/ekspedisi/*` — itu kontrak yang sudah dipakai frontend, rename cuma di level
tabel database + nama project/repo, bukan API path.

## Integrasi dengan `backend-production`

### `surat_jalan`

`ekspedisi_t_trip` punya kolom opsional **`no_surat_jalan`**, tautan LOGIS (bukan FK asli) ke
`surat_jalan.no_surat_jalan` — tabel lama milik `backend-production` (`latin1`, ~6.255 baris,
dipakai `surat-jalan-apk`/`produksi-apk`/`finance-apk`). Bukan FK asli karena
`no_surat_jalan` **bukan kolom unik** di `surat_jalan` — 1 nomor SJ = banyak baris (1 baris
per item produk dalam pengiriman itu).

**Catatan buat rencana modul surat jalan ke depan**: ini tabel `surat_jalan` LAMA milik
`backend-production`, dipakai LIVE oleh 3 app lain di luar workspace ekspedisi ini. Kalau nanti
modul surat jalan benar-benar dipindah/dibangun di sini, perlu diputuskan dulu: pakai tabel lama
ini (risiko coupling ke app lain), atau bikin skema `ekspedisi_t_surat_jalan` sendiri (duplikasi,
tapi lebih aman/independen). Belum diputuskan — sengaja ditunda sampai scope-nya jelas.

Yang sudah ada:
- `App\Support\SuratJalanLookup` — query **READ-ONLY** (`surat_jalan` JOIN
  `t_penjualan_detail_performa` JOIN `t_penjualan_header` LEFT JOIN `m_client`) buat validasi
  & preview info SJ (nama client, kendaraan, plat, pengirim). Tidak pernah menulis ke tabel
  manapun di luar `ekspedisi_*`.
- `POST /admin/drivers/{driver}/trip` menolak (422) kalau `no_surat_jalan` diisi tapi tidak
  cocok SJ manapun — mencegah typo bikin tautan basi.
- `GET /admin/surat-jalan/{no}` — dipakai tombol "Cek" di form "Perjalanan Baru" (`ekspedisi-apk`)
  buat preview sebelum submit.

**Yang SENGAJA belum dikerjakan** (di luar cakupan tabel `ekspedisi_*`, butuh keputusan/otorisasi
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
`surat_jalan`), itu keputusan terpisah, di luar scope perubahan `ekspedisi_*` yang sudah
dikerjakan.

### SPK ready-kirim (`t_penjualan_header`)

`ekspedisi_t_trip` juga punya kolom opsional **`penjualan_id`**, tautan LOGIS ke
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
sederhana, dikecualikan berdasar `ekspedisi_t_trip.penjualan_id`, bukan `t_pengiriman_detail`)
supaya tidak bergantung pada sistem ekspedisi luar backend-production yang belum pernah hidup.

Yang sudah ada di sisi `ekspedisi-apk`: halaman **"SPK Siap Kirim"** (`adminSpkKirim.js`) —
daftar SPK ready-kirim, admin pilih supir dari dropdown per baris (internal & eksternal
sekaligus), klik "Plot" langsung bikin `ekspedisi_t_trip` tertaut ke `penjualan_id` itu
(destination di-compose otomatis dari `client_nama` + `kota_tujuan`).

### Supir eksternal / pihak ketiga (`ekspedisi_m_supir.tipe = 'eksternal'`)

**(Riwayat desain: percobaan pertama pakai tabel terpisah `driver_t_ekspedisi` — SPK langsung
ke perusahaan ekspedisi, tanpa lewat konsep "supir" — DIBATALKAN & di-drop di
`08_drop_driver_t_ekspedisi.sql`, belum sempat ada data/UI. Diganti pendekatan di bawah, lebih
sederhana: satu alur "supir" utk internal maupun eksternal.)**

`ekspedisi_m_supir` punya kolom `tipe` (`internal`/`eksternal`, `database/07_...sql`). Supir
eksternal (bukan pegawai — freelance/lepas, atau bekerja utk perusahaan ekspedisi tertentu)
**tidak punya akun `shared_m_users` sama sekali** — `user_id` NULL, `nama_eksternal`/
`telepon_eksternal` diisi langsung, `id_expedisi` (tautan logis opsional ke `m_expedisi`)
NULL kalau lepas/independen. Konsekuensi penting: **supir eksternal tidak bisa login ke app
ini sama sekali** — tidak ada kredensial, murni catatan dispatch buat admin. Checkpoint
foto (`berangkat`/`serah_terima`/`sj`) di `ekspedisi_t_trip_photo` jadi tidak relevan utk trip
tipe ini (tidak ada yang bisa upload dari sisi supir) — **belum ada keputusan** gimana
menandai trip eksternal selesai tanpa checkpoint foto, masih gap terbuka.

Karena ini tetap `ekspedisi_m_supir` biasa, jalur yang SUDAH ADA otomatis berlaku: satu dropdown
"pilih supir" di `adminSpkKirim.js` menampilkan internal & eksternal sekaligus, `POST
/admin/drivers/{driver}/trip` sama persis dipakai untuk keduanya. Tidak ada endpoint/tabel
terpisah lagi utk "serahkan ke ekspedisi".

`App\Support\ExpedisiLookup` — query READ-ONLY ke `m_expedisi`/`m_expedisi_tarif`, dipakai
`GET /admin/ekspedisi` buat dropdown opsional "Perusahaan Ekspedisi" saat Tambah Supir
Eksternal. `tarif()` masih ada tapi belum disambungkan ke endpoint manapun.

### Pengajuan biaya ke finance (`ekspedisi_t_pengajuan_biaya`)

Tabel (`database/09_...sql`), **FK asli** ke `ekspedisi_t_trip` (bukan tautan logis — dua-
duanya tabel milik app ini sendiri). Berlaku utk trip supir internal MAUPUN eksternal — admin
input `nominal_diajukan` **manual** (bukan hasil hitungan sistem/tarif), opsional `keterangan`.
Status `diajukan` → `disetujui`/`ditolak` (kolom `nominal_disetujui`, `catatan_finance`,
`disetujui_oleh`, `disetujui_at` sudah disiapkan di skema).

**Belum ada**: endpoint approve/reject (siapa yang berperan sebagai "finance" di app ini belum
diputuskan — role baru, atau admin yang sama?), dan UI di `ekspedisi-apk` utk submit pengajuan
(backend `POST`/`GET /admin/trips/{trip}/pengajuan-biaya` sudah ada & siap dipakai, tinggal
form-nya).

## Struktur

```
ekspedisi-apk-backend/
├── database/                 # SQL mentah, satu file per langkah, dijalankan manual URUT NOMOR
│   ├── 01_schema.sql            # CREATE TABLE 5 tabel (historis: driver_*, lihat 10) -- bukan migration, sekali jalan
│   ├── 02_seed_admin_access.sql  # seed whitelist admin/dispatcher (cari by username, idempotent)
│   ├── 03_seed_dummy_drivers.sql # pre-provision profil supir dummy (cari by username, idempotent)
│   ├── 04_alter_add_no_surat_jalan_to_driver_t_trip.sql  # ALTER: tambah kolom no_surat_jalan
│   ├── 05_add_penjualan_id_to_driver_t_trip.sql          # ALTER: tambah kolom penjualan_id
│   ├── 06_create_driver_t_ekspedisi.sql                  # (DIBATALKAN, lihat 08) CREATE TABLE driver_t_ekspedisi
│   ├── 07_alter_driver_m_supir_add_eksternal.sql         # ALTER: kolom tipe + kolom supir eksternal
│   ├── 08_drop_driver_t_ekspedisi.sql                    # DROP TABLE driver_t_ekspedisi (desain diganti 07)
│   ├── 09_create_driver_t_pengajuan_biaya.sql            # CREATE TABLE pengajuan biaya
│   └── 10_rename_driver_to_ekspedisi.sql                 # RENAME semua tabel driver_* -> ekspedisi_* (WAJIB)
├── public/
│   ├── index.php             # front controller
│   └── uploads/trips/{id}/   # foto checkpoint, disajikan langsung sbg file statis
└── src/
    ├── bootstrap.php          # bangun Slim App: middleware, CORS, error handler, routes
    ├── Database.php            # wrapper koneksi PDO (singleton)
    ├── Support/
    │   ├── Jwt.php               # terbitkan & verifikasi token HS256
    │   ├── SupirProfile.php       # ambil/buat baris ekspedisi_m_supir (dipakai Auth & DriverController)
    │   ├── TripPresenter.php      # format baris ekspedisi_t_trip -> shape JSON, konstanta STEPS
    │   ├── SuratJalanLookup.php   # query READ-ONLY ke surat_jalan (integrasi, lihat bagian di atas)
    │   ├── SpkReadyKirim.php      # query READ-ONLY ke t_penjualan_header (integrasi SPK ready-kirim)
    │   ├── ExpedisiLookup.php     # query READ-ONLY ke m_expedisi/m_expedisi_tarif (dropdown Tambah Supir Eksternal)
    │   └── PengajuanBiaya.php     # create/list ekspedisi_t_pengajuan_biaya
    ├── Middleware/
    │   ├── AuthMiddleware.php      # cek Authorization: Bearer <token>, taruh user_id/role di request
    │   └── AdminOnlyMiddleware.php  # tolak 403 kalau role token bukan 'admin'
    └── Controllers/
        ├── Controller.php     # helper json()/error() dipakai semua controller
        ├── AuthController.php  # login, logout
        ├── DriverController.php  # /driver/* (nama class dipertahankan -- soal "supir", bukan bagian rename ekspedisi_*)
        └── AdminController.php   # /admin/*
```

## Yang belum ada / perlu diputuskan ke depan

- **Modul surat jalan** — masih sebatas rencana (lihat catatan di bagian Integrasi di atas),
  belum ada desain/keputusan konkret. Sengaja ditunda terpisah dari rename ini.
- **`ekspedisi_t_location` akan tumbuh terus** (1 baris tiap ~30 detik per supir yang online) —
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
