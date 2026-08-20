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
— nama & prefix tabel ikut diganti (`driver_*` → `ekspedisi_*`).

**Catatan riwayat migration (2026-08-19):** sempat ada 10 file migration terpisah (create →
alter → tabel eksperimen yang dibatalkan → drop → alter lagi → rename `driver_*` →
`ekspedisi_*`), hasil evolusi desain beberapa sesi berturut-turut. Karena data produksi masih
nol/dummy semua saat itu, tabel lama di-drop manual dan seluruh migration **dikonsolidasikan
jadi satu `01_schema.sql` bersih** (drop-in replacement, struktur akhirnya identik — cuma
riwayat langkah-per-langkahnya yang dirapikan). Riwayat evolusi lengkap (kenapa desain berubah
beberapa kali) tetap ada di histori git kalau perlu ditelusuri lagi.

## Arsitektur singkat

- **Tanpa migration**: [`database/`](database) berisi `CREATE TABLE`/`ALTER`/`RENAME`/seed
  mentah, satu file per langkah, **bernomor urut sesuai urutan eksekusi** (`01_schema.sql`,
  `02_seed_admin_access.sql`, dst — lihat isi folder untuk daftar lengkap & urutannya).
  Ditulis mengikuti gaya persis tabel-tabel baru di `db_dump.sql` (`ENGINE=InnoDB`,
  `utf8mb4`/`utf8mb4_unicode_ci`, PK `id bigint unsigned AUTO_INCREMENT`, FK eksplisit —
  termasuk FK ke `shared_m_users` karena sekarang satu database yang sama). Jalankan manual,
  urut nomornya, satu-satu: `mysql -u <user> -p <database> < database/01_schema.sql`, dst.
  Tidak ada `php artisan migrate`/runner apa pun — kalau nanti ada perubahan skema baru, tambah
  file baru dengan nomor berikutnya (mis. `04_...sql`), jangan edit file yang sudah pernah
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

```bash
mysql -u <username> -p <database> < database/01_schema.sql             # bikin 6 tabel ekspedisi_* baru (TIDAK menyentuh tabel lain)
mysql -u <username> -p <database> < database/02_seed_admin_access.sql  # edit daftar username dulu, lihat di bawah
mysql -u <username> -p <database> < database/03_seed_dummy_drivers.sql # opsional
php -S 127.0.0.1:8000 -t public
```

Edit dulu daftar `username` di [`database/02_seed_admin_access.sql`](database/02_seed_admin_access.sql)
sebelum menjalankannya — cari berdasarkan `username` (bukan `user_id` mentah) supaya tidak
perlu lihat-lihat ID manual, dan aman dijalankan berkali-kali (idempotent) kalau nanti mau
nambah admin lagi. Query terakhir di skrip itu langsung menampilkan siapa saja yang berhasil
ke-seed, untuk verifikasi. Tanpa ini tidak ada seorang pun yang bisa login sebagai
admin/dispatcher (`ekspedisi_m_admin_access` mulai kosong).

**Sudah dites end-to-end terhadap MySQL produksi asli** (bukan cuma sandbox dev) — login
sungguhan (`POST /login` dgn kredensial `shared_m_users` real), query `shared_m_users` (331
baris), seed admin (`ITAI`), `POST /admin/drivers` (bikin profil supir, internal & eksternal),
`POST /admin/drivers/{driver}/trip` dengan `penjualan_id` SPK asli, dan
`GET /admin/surat-jalan/{no}` (join ke `surat_jalan` produksi, dapat data client asli) semuanya
sudah dicoba langsung & berhasil — semua terhadap struktur yang skema-nya identik dgn
`01_schema.sql` saat ini (dites sebelum konsolidasi migration, lihat bagian "Riwayat nama" di
atas), belum dites ulang dari `01_schema.sql` yang baru dikonsolidasi ini secara harfiah, tapi
strukturnya sudah dikonfirmasi sama persis lewat `SHOW CREATE TABLE` di database live.

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
| POST | `/admin/trips/{trip}/complete` | token + admin | Tandai trip selesai secara manual — **HANYA** untuk trip milik supir `tipe='eksternal'` (422 kalau supirnya internal). Satu-satunya cara menyelesaikan trip supir eksternal, karena mereka tidak punya akun & tidak bisa panggil `/driver/trip/{trip}/photo`+`/complete` sendiri. Lihat bagian "Supir eksternal" di bawah |
| GET | `/admin/surat-jalan/{no}` | token + admin | Cek 1 nomor SJ asli (READ-ONLY ke `surat_jalan` milik `backend-production`) → `{ no_surat_jalan, tanggal, kendaraan, plat, pengirim, valid_cs, penjualan_id, client_nama, client_alamat }`, 404 kalau tidak ketemu |
| GET | `/admin/spk-ready-kirim` | token + admin | Daftar SPK yang sudah disetujui utk dikirim tapi **belum diplot ke supir manapun** (READ-ONLY ke `t_penjualan_header`, `SpkReadyKirim::list()`) → `[{ penjualan_id, no_spk, client_nama, kota_asal, kota_tujuan, penjualan_tanggal_kirim, tgl_cs_deadline, penjualan_total_qty }]` |
| GET | `/admin/spk-belum-sj` | token + admin | Daftar SPK ready-kirim yang **belum ada SJ sama sekali** (kriteria beda, independen dari baris di atas — `SpkReadyKirim::listBelumSj()`, dipakai tab "SPK" ekspedisi-apk) → bentuk field sama persis |
| GET | `/admin/ekspedisi` | token + admin | Daftar perusahaan ekspedisi aktif (READ-ONLY ke `m_expedisi`) → `[{ id_expedisi, kode_expedisi, nama_expedisi, pic, no_telp }]` — dipakai dropdown opsional saat Tambah Supir Eksternal |
| POST | `/admin/trips/{trip}/pengajuan-biaya` | token + admin | `{ nominal_diajukan, keterangan? }` → 201. `nominal_diajukan` input manual admin. Berlaku utk trip supir internal maupun eksternal |
| GET | `/admin/trips/{trip}/pengajuan-biaya` | token + admin | Riwayat pengajuan biaya utk 1 trip → `[{ id, trip_id, nominal_diajukan, status, nominal_disetujui, catatan_finance, ... }]` |
| GET | `/admin/sj/spk/{penjualan_id}/items` | token + admin | Lini produk 1 SPK + sisa qty yang belum terkirim (READ-ONLY, lihat `App\Support\PenjualanItemLookup`) → `[{ penjualan_detail_performa_id, penjualan_jenis, penjualan_qty, terkirim, sisa }]`, 404 kalau SPK tidak ditemukan |
| GET | `/admin/sj` | token + admin | Daftar surat jalan **milik app ini sendiri** (`ekspedisi_t_surat_jalan`, independen dari `surat_jalan` lama) — query opsional `?status=`/`?penjualan_id=` → `[{ id, no_surat_jalan, trip_id, penjualan_id, driver_id, nama_supir, tujuan, kendaraan, plat, penerima, jumlah_kirim, asal, items: [{ penjualan_detail_performa_id, penjualan_id, penjualan_jenis, jumlah_kirim }], foto_surat_jalan, status, ... }]` — `penjualan_id` di level item beda-beda kalau SJ ini lintas SPK, header `penjualan_id` cuma keisi jalur trip-linked lama. `asal` = `native`/`migrasi_legacy` (lihat "Migrasi data historis" di bawah) |
| POST | `/admin/sj` | token + admin | `{ trip_id?, driver_id (WAJIB), tujuan?, kendaraan?, plat?, penerima?, jumlah_kirim?, tgl_kirim?, catatan?, items?: [{ penjualan_detail_performa_id, jumlah_kirim }] }` → 201, 422 kalau `driver_id` kosong. Bikin SJ manual, tidak harus terkait trip. `items` BOLEH berisi lini produk dari beberapa SPK berbeda sekaligus (tidak ada `penjualan_id` di body lagi — SPK-nya diketahui per-item). Kalau `items` diisi, `jumlah_kirim` dihitung otomatis dari total item & tiap item divalidasi ulang satu-satu ke sisa qty terkini (422 kalau melebihi) |
| GET | `/admin/sj/{id}` | token + admin | Detail 1 SJ |
| PUT | `/admin/sj/{id}` | token + admin | `{ tujuan?, kendaraan?, plat?, penerima?, jumlah_kirim?, tgl_kirim?, catatan? }` — lengkapi/koreksi field (foto TIDAK lewat sini, lihat endpoint di bawah) |
| POST | `/admin/sj/{id}/photo` | token + admin | multipart: `photo`. Lampirkan/ganti foto SJ manual (SJ dari checkpoint supir sudah otomatis punya foto) — begitu terisi, `status` naik ke `terkirim` |
| POST | `/admin/sj/{id}/validasi` | token + admin | multipart: `photo`. Langkah PENUTUP alur SJ — admin upload foto SJ fisik final yang sudah ditandatangani penerima (dibawa balik supir), isi `foto_validasi` + `status` jadi `tervalidasi` + catat `divalidasi_oleh`/`divalidasi_at`. 422 kalau SJ ini sudah tervalidasi |

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

**Keputusan modul surat jalan (2026-08-19): SKEMA SENDIRI, independen.** Ini tabel
`surat_jalan` LAMA milik `backend-production`, dipakai LIVE oleh 3 app lain di luar workspace
ekspedisi ini — modul surat jalan yang dibangun di app ini (lihat subbagian
`ekspedisi_t_surat_jalan` di bawah) SENGAJA tidak menulis ke tabel ini sama sekali, supaya
tidak ada risiko coupling ke app lain yang masih live. Konsekuensinya: SJ yang dibuat lewat
`surat-jalan-apk` (dkk) dan SJ yang dibuat lewat `ekspedisi-apk` hidup di 2 tempat terpisah,
tidak otomatis nyambung satu sama lain.

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

Yang sudah ada di sisi `ekspedisi-apk`: halaman **"Plot SPK ke Supir"** (`adminSpkKirim.js`,
drill-down dari tab Ekspedisi) — daftar SPK ready-kirim **belum diplot**, admin pilih supir dari
dropdown per baris (internal & eksternal sekaligus), klik "Plot" langsung bikin `ekspedisi_t_trip`
tertaut ke `penjualan_id` itu (destination di-compose otomatis dari `client_nama` + `kota_tujuan`).

**Varian kedua (2026-08-20): tab "SPK" — halaman awal admin.** Kriteria "siap tapi belum X" di
sini beda: **belum ada SJ sama sekali** (`SpkReadyKirim::listBelumSj()`, `GET /admin/spk-belum-sj`),
bukan belum diplot. Dua hal independen — 1 SPK bisa sudah diplot ke supir (hilang dari "Plot SPK
ke Supir") tapi SJ-nya belum dibuat (masih muncul di tab "SPK"), atau sebaliknya. Aksi per baris
di tab ini cuma **"Surat Jalan"** (navigasi ke `adminNewSuratJalan.js` dengan `penjualan_id`
dititip lewat `prefill.js`, jadi grup SPK pertama otomatis) — plotting supir tetap di tab
Ekspedisi, dua concern sengaja dipisah.

Sejak 1 SJ boleh lintas SPK (lihat "Breakdown per lini produk SPK" di bawah), `listBelumSj()`
cek **DUA jalur** buat "sudah ada SJ" — header `ekspedisi_t_surat_jalan.penjualan_id` (trip-linked
lama) ATAU `ekspedisi_t_surat_jalan_item` JOIN `t_penjualan_detail_performa` (manual, breakdown
per produk). Kalau cuma cek header saja, SPK yang SJ-nya tercatat lewat item (bukan header) akan
salah muncul lagi sebagai "belum ada SJ" padahal sudah ada.

### Supir eksternal / pihak ketiga (`ekspedisi_m_supir.tipe = 'eksternal'`)

**(Riwayat desain, lihat histori git: percobaan pertama pakai tabel terpisah
`driver_t_ekspedisi` — SPK langsung ke perusahaan ekspedisi, tanpa lewat konsep "supir" —
DIBATALKAN & di-drop, belum sempat ada data/UI. File-nya sendiri sudah tidak ada lagi di
`database/` setelah konsolidasi migration. Diganti pendekatan di bawah, lebih sederhana: satu
alur "supir" utk internal maupun eksternal.)**

`ekspedisi_m_supir` punya kolom `tipe` (`internal`/`eksternal`, lihat `database/01_schema.sql`). Supir
eksternal (bukan pegawai — freelance/lepas, atau bekerja utk perusahaan ekspedisi tertentu)
**tidak punya akun `shared_m_users` sama sekali** — `user_id` NULL, `nama_eksternal`/
`telepon_eksternal` diisi langsung, `id_expedisi` (tautan logis opsional ke `m_expedisi`)
NULL kalau lepas/independen. Konsekuensi penting: **supir eksternal tidak bisa login ke app
ini sama sekali** — tidak ada kredensial, murni catatan dispatch buat admin. Checkpoint
foto (`berangkat`/`serah_terima`/`sj`) di `ekspedisi_t_trip_photo` jadi tidak relevan utk trip
tipe ini (tidak ada yang bisa upload dari sisi supir).

**Keputusan (2026-08-19): admin tandai selesai manual.** `POST /admin/trips/{trip}/complete`
(`AdminController::completeTripManual()`) langsung set `status='completed'` tanpa syarat 3
checkpoint foto — **tapi ditolak 422 kalau `tipe` supir pemilik trip itu `internal`**, supaya
admin tidak bisa membypass kewajiban checkpoint foto supir internal lewat jalur ini. Tombol
"Tandai Selesai" muncul di `ekspedisi-apk` (`adminDriverDetail.js`) cuma untuk trip aktif milik
supir eksternal. Konsekuensi: trip eksternal yang selesai lewat jalur ini **tidak pernah punya**
baris `ekspedisi_t_trip_photo` — riwayat foto di detail supir akan kosong utk trip tipe ini,
itu memang diharapkan, bukan bug.

Karena ini tetap `ekspedisi_m_supir` biasa, jalur yang SUDAH ADA otomatis berlaku: satu dropdown
"pilih supir" di `adminSpkKirim.js` menampilkan internal & eksternal sekaligus, `POST
/admin/drivers/{driver}/trip` sama persis dipakai untuk keduanya. Tidak ada endpoint/tabel
terpisah lagi utk "serahkan ke ekspedisi".

`App\Support\ExpedisiLookup` — query READ-ONLY ke `m_expedisi`/`m_expedisi_tarif`, dipakai
`GET /admin/ekspedisi` buat dropdown opsional "Perusahaan Ekspedisi" saat Tambah Supir
Eksternal. `tarif()` masih ada tapi belum disambungkan ke endpoint manapun.

### Pengajuan biaya ke finance (`ekspedisi_t_pengajuan_biaya`)

Tabel (lihat `database/01_schema.sql`), **FK asli** ke `ekspedisi_t_trip` (bukan tautan logis — dua-
duanya tabel milik app ini sendiri). Berlaku utk trip supir internal MAUPUN eksternal — admin
input `nominal_diajukan` **manual** (bukan hasil hitungan sistem/tarif), opsional `keterangan`.
Status `diajukan` → `disetujui`/`ditolak` (kolom `nominal_disetujui`, `catatan_finance`,
`disetujui_oleh`, `disetujui_at` sudah disiapkan di skema).

**Belum ada**: endpoint approve/reject (siapa yang berperan sebagai "finance" di app ini belum
diputuskan — role baru, atau admin yang sama?), dan UI di `ekspedisi-apk` utk submit pengajuan
(backend `POST`/`GET /admin/trips/{trip}/pengajuan-biaya` sudah ada & siap dipakai, tinggal
form-nya).

### Modul surat jalan (`ekspedisi_t_surat_jalan`)

Tabel MILIK app ini sendiri (`database/04_...sql`) — **independen total** dari `surat_jalan`
lama (lihat keputusan di bagian atas). Dua jalur pengisian:

1. **Otomatis dari checkpoint foto.** Saat supir upload foto checkpoint `type=sj`
   (`POST /driver/trip/{trip}/photo`, workflow yang sudah ada), `DriverController::uploadPhoto()`
   sekalian memanggil `SuratJalan::upsertFromTripPhoto()` — bikin (atau update, kalau re-upload)
   baris di `ekspedisi_t_surat_jalan` tertaut ke `trip_id` itu, `driver_id`/`tujuan`/`penjualan_id`
   diisi otomatis dari data trip, `status` langsung `terkirim`. Supir tidak perlu tahu/lihat
   modul SJ ini sama sekali — murni efek samping otomatis dari alur checkpoint yang sudah ada.
2. **Manual dari admin.** `POST /admin/sj` (`SuratJalanController::store()`) — admin bikin SJ
   langsung tanpa trip (`trip_id` NULL), `driver_id` **WAJIB** (2026-08-20, dulu opsional — lihat
   catatan di bawah), field lain isi sendiri, foto opsional lewat `POST /admin/sj/{id}/photo`
   setelah SJ-nya dibuat. `status` mulai dari `draft` (belum ada foto) sampai `foto_surat_jalan`
   terisi.

`no_surat_jalan` **auto-generated** setelah insert (format `SJ-YYYYMMDD-xxxx`, `xxxx` = id
dipadding 4 digit) — `App\Support\SuratJalan::assignNomor()`, dipanggil dari `create()` maupun
`upsertFromTripPhoto()`. **Sengaja tetap auto-generate**, tidak diketik manual seperti
`no_surat_jalan`/`SJ_...` di `surat-jalan-apk` — keputusan dipertahankan setelah dibandingkan
langsung ke alur input SJ asli (lihat catatan di bawah). `PUT /admin/sj/{id}` buat admin
melengkapi/koreksi field non-foto (mis. SJ yang auto-dibuat dari checkpoint biasanya minim data
— `kendaraan`/`plat`/`jumlah_kirim` belum terisi, admin lengkapi belakangan).

**Perbandingan dengan alur input SJ asli di `surat-jalan-apk` (2026-08-19):** ditelusuri
`surat_jalan.js`/`surat_jalan.html` (`POST /surat-jalan-proses`) buat cek field apa saja yang
benar-benar dipakai di lapangan. Dua gap pertama ditutup — kolom `pengirim` (nama orang yang
serah-terima barang, terpisah dari nama supir) dan `tgl_kirim` (tanggal kirim, bisa beda dari
`created_at`) ditambah lewat `database/05_alter_surat_jalan_pengirim_tgl_kirim.sql`; endpoint
foto (`POST /admin/sj/{id}/photo`, lihat tabel API di atas) ditambah supaya SJ manual juga bisa
punya foto.

**Revisi (2026-08-20): breakdown qty per lini produk TERNYATA bukan sesuatu yang boleh
diabaikan.** Ditelusuri lebih dalam ke `ServiceController::suratJalanProses()` di
`backend-production` (bukan cuma sisi frontend `surat-jalan-apk`) — SJ di sana **SELALU melekat
ke SPK**, cuma satu hop tidak langsung: `surat_jalan.penjualan_detail_performa_id ->
t_penjualan_detail_performa.penjualan_id`, bukan kolom `penjualan_id` langsung. Keputusan
awal ("sengaja tidak diikuti, beda skema beda tujuan") **dibatalkan** setelah dicek ulang
realitas di lapangan memang selalu begini. Lihat detail lengkap di bagian "Breakdown per lini
produk SPK" di bawah.

**Revisi lagi (2026-08-20, hari yang sama): dua koreksi lanjutan** setelah breakdown per lini
produk jalan & dipakai:
- **`pengirim` di-RENAME jadi `penerima`** (`database/08_rename_pengirim_ke_penerima.sql`) —
  ternyata tumpang tindih konsep sama `driver_id`/supir yang sudah ada (SJ selalu punya supir,
  jadi "siapa yang mengirim" sudah terjawab lewat itu). Field yang justru berguna: nama
  penerima/PIC di tujuan, supaya supir tahu siapa yang harus dihubungi/diserahi barang.
- **`driver_id` jadi WAJIB** di `POST /admin/sj` (dulu opsional, "SJ boleh dibuat dulu, supirnya
  belakangan") — kolom DB-nya sendiri tetap nullable (tidak ada migration NOT NULL, supaya tidak
  berisiko ke baris lama/jalur lain), validasi wajibnya cuma di level `SuratJalanController::store()`.

FK ke `ekspedisi_t_trip`/`ekspedisi_m_supir` **FK asli** (tabel sendiri). `penjualan_id` tautan
LOGIS ke `t_penjualan_header.penjualan_id` (backend-production), pola sama seperti
`ekspedisi_t_trip.penjualan_id`.

#### Breakdown per lini produk SPK (2026-08-20)

Tabel baru `ekspedisi_t_surat_jalan_item` (`database/07_create_ekspedisi_t_surat_jalan_item.sql`)
— **beda dari `surat_jalan` lama** yang 1-baris-per-lini-produk (1 dokumen SJ fisik jadi
tersebar di banyak baris, semua share `no_surat_jalan` yang sama). Di sini 1 dokumen SJ fisik =
1 baris header (`ekspedisi_t_surat_jalan`) + N baris item (`ekspedisi_t_surat_jalan_item`,
`penjualan_detail_performa_id` tautan LOGIS ke `t_penjualan_detail_performa`, `jumlah_kirim`
per lini) — lebih match ke bentuk dokumen aslinya (1 SJ bisa antar beberapa produk sekaligus)
& gampang ditampilkan sebagai 1 kartu di UI, `SuratJalan::items()` di-attach otomatis ke tiap
baris `list()`/`find()`.

**`App\Support\PenjualanItemLookup`** (READ-ONLY, baru) — dua method:
- `lines($pdo, $penjualanId)` — semua lini produk 1 SPK + sisa qty, dipakai
  `GET /admin/sj/spk/{penjualan_id}/items` (frontend tombol "Tambah" di form "Buat Surat Jalan").
- `findLine($pdo, $penjualanDetailPerformaId)` — 1 lini produk by id, TERLEPAS dari SPK mana
  asalnya, dipakai `SuratJalanController::store()` buat validasi ulang tiap item saat submit
  (lihat di bawah).

Krusial di keduanya: sisa dihitung dari **DUA sumber sekaligus** — `surat_jalan` (tabel LAMA
milik backend-production, masih dipakai `surat-jalan-apk` dkk) **dan**
`ekspedisi_t_surat_jalan_item` (tabel baru app ini) — supaya SPK yang sama, kalau kebetulan
pernah/sedang dikirim lewat KEDUA app, tidak dobel-hitung sisa qty-nya dan menyebabkan
over-shipment.

**Revisi (2026-08-20): 1 SJ boleh berisi lini produk dari LEBIH DARI 1 SPK sekaligus** (realitas
di lapangan: 1 truk/1 dokumen SJ fisik bisa sekali jalan angkut pesanan gabungan beberapa SPK).
Konsekuensinya:
- `SuratJalanController::store()` validasi **PER-ITEM**, bukan per-SPK lagi — tiap
  `penjualan_detail_performa_id` di `items[]` dicek satu-satu lewat `PenjualanItemLookup::findLine()`
  (tidak percaya `sisa` yang dikirim client, bisa basi kalau ada SJ lain masuk di antara admin
  buka form & submit), tolak 422 kalau melebihi sisa TERKINI atau id-nya tidak ditemukan. SPK
  masing-masing item baru ketahuan dari hasil `findLine()`/`SuratJalan::items()` (JOIN ke
  `t_penjualan_detail_performa.penjualan_id`), **tidak dikirim terpisah lewat body `penjualan_id`
  lagi** — parameter itu sudah dihapus dari kontrak `POST /admin/sj`.
- Kolom header `ekspedisi_t_surat_jalan.penjualan_id` **TIDAK diisi lagi** dari jalur manual
  (`store()`) — SPK-nya cuma bisa diketahui per-item lewat `items[].penjualan_id` sekarang,
  bukan di header. Kolom itu TETAP dipakai jalur trip-linked lama (`upsertFromTripPhoto()`),
  yang selalu 1 SPK per trip (tautan plotting, lihat SPK ready-kirim) — trip TIDAK pernah punya
  info per-lini-produk, jadi tidak ikut breakdown ini; kalau nanti mau breakdown juga di jalur
  checkpoint, itu perubahan terpisah.
- `SpkReadyKirim::listBelumSj()` (tab "SPK" ekspedisi-apk) ikut disesuaikan — dicek dari DUA
  jalur (header `penjualan_id` ATAU `ekspedisi_t_surat_jalan_item` JOIN
  `t_penjualan_detail_performa`), supaya SPK yang SJ-nya cuma tercatat lewat item (bukan header)
  tidak salah muncul lagi sebagai "belum ada SJ".
- Kalau `items` diisi, `jumlah_kirim` di header **dihitung otomatis** dari total SEMUA item
  (lintas SPK manapun) — field "Jumlah kirim" flat di form disembunyikan begitu ada minimal 1
  SPK ditambahkan.

**SJ tanpa SPK tetap didukung** (`items` opsional) — utk pengiriman lepas yang bukan dari SPK
mana pun (mis. sampel, transfer internal antar gudang). Jangan tambah SPK apa pun di form,
`jumlah_kirim` manual seperti sebelumnya.

#### Migrasi data historis `surat_jalan` (2026-08-20)

Ditelusuri dari `db_dump.sql`: `surat_jalan` (tabel lama, masih live) punya 6.027 baris total,
**3.160 di antaranya bertanggal ≥ 2024-01-01** (jadi 1.508 dokumen SJ unik kalau digrupkan by
`no_surat_jalan`). Struktur legacy cocok — 1 baris = 1 lini produk, persis pola
`ekspedisi_t_surat_jalan_item`, tinggal `GROUP BY no_surat_jalan` jadi header+item.

**Risiko utama yang HARUS ditangani lebih dulu:** `PenjualanItemLookup` menghitung sisa qty dari
DUA sumber — `surat_jalan` (dibaca langsung) DAN `ekspedisi_t_surat_jalan_item`. Kalau data yang
sama disalin ke `ekspedisi_t_surat_jalan_item` tanpa penanda apa-apa, tiap baris kehitung DUA
KALI dan sisa qty jadi keliru (lebih kecil dari seharusnya). **Solusi:** kolom baru
`ekspedisi_t_surat_jalan.asal` (`database/09_tambah_kolom_asal_surat_jalan.sql`, enum
`native`/`migrasi_legacy`, default `native`) — sisi `ekspedisi_t_surat_jalan_item` di
`PenjualanItemLookup::BASE_SELECT` sekarang JOIN ke header & filter `WHERE sj.asal = 'native'`,
jadi baris migrasi TIDAK ikut dihitung di sisi itu (sudah cukup terwakili lewat sisi
`surat_jalan` yang memang jadi sumber datanya). **Efek bersih migrasi ini ke perhitungan sisa
qty: NOL** — sisi `surat_jalan` sudah dibaca sejak awal (independen dari migrasi ini),
migrasinya cuma soal punya riwayat yang seragam & bisa dilihat dari tab SJ app ini.

**`database/migrate_legacy_surat_jalan.php`** (script PHP, BUKAN bagian urutan skema
01–09 — operasi DATA sekali-jalan, wajib jalan SETELAH migration 09 di atas):
```bash
php database/migrate_legacy_surat_jalan.php --dry-run          # preview, tidak nulis apa-apa
php database/migrate_legacy_surat_jalan.php --since=2024-01-01 # default -- ganti kalau perlu
```
Idempotent (aman diulang — skip `no_surat_jalan` yang sudah pernah bertanda
`asal='migrasi_legacy'`; `UNIQUE KEY` di `no_surat_jalan` jadi pengaman kedua), 1 transaksi utk
semua baris (gagal di tengah = rollback total, tidak ada state setengah-jadi). Per
`no_surat_jalan`: `no_surat_jalan` ASLI dipertahankan (bukan digenerate ulang), `status`
diseragamkan `terkirim` (data lama tidak punya info andal yang bisa dipetakan ke
draft/tervalidasi — `valid_cs` di dump ini seragam `1` semua). Dua keputusan lain yang
lossy-tapi-disengaja:
- **`driver_id` tetap NULL** — kolom `pengirim` di data lama cuma teks bebas (465 nilai unik
  gaya `"Yoyo (diambil)"`/`"Haer/gojek"`), tidak match andal ke `ekspedisi_m_supir` manapun.
  Nilai aslinya disimpan di `catatan` (**bukan** dipetakan ke `penerima` — beda arti, `penerima`
  = PIC di tujuan, bukan nama pengirim/kurir).
- **`foto_surat_jalan` diisi URL ABSOLUT** ke host lama
  (`https://indokoper.com/foto_surat_jalan/{filename}`, 95% baris py nama file valid) — TIDAK
  disalin fisik ke `public/uploads/` app ini. Frontend (`adminSuratJalan.js`, `fotoUrl()`) sudah
  disesuaikan biar bisa nampilin URL absolut apa adanya (dites langsung, foto beneran kebuka
  dari host lama).

Diverifikasi manual (2026-08-20): query SELECT+GROUP BY dijalankan terhadap database produksi
asli (bukan cuma dump offline) — 3.160 baris → 1.508 dokumen, angka konsisten dengan hitungan
dari `db_dump.sql`. Bagian INSERT belum dieksekusi di produksi (nunggu migration 09 dijalankan
dulu oleh operator, sesuai konvensi "jalankan manual" di `database/`).

#### Alur validasi (2026-08-19)

Proses fisik yang dimodelkan: admin bikin SJ (`draft`) → dokumen fisik dibawa supir → barang
diterima, SJ ditandatangani penerima → supir kembalikan SJ fisik yang sudah ditandatangani ke
admin → **admin** upload foto SJ final itu sekaligus menandai pengiriman **tervalidasi**. Tiga
status sekarang (`database/06_alter_surat_jalan_validasi.sql`):

- `draft` — belum ada foto sama sekali.
- `terkirim` — sudah ada `foto_surat_jalan` (checkpoint lapangan dari supir via
  `upsertFromTripPhoto()`, ATAU upload manual admin via `POST /admin/sj/{id}/photo`). Ini **cuma
  bukti ada foto**, BUKAN bukti sudah divalidasi — keputusan eksplisit: checkpoint supir tetap
  dipertahankan sebagai status antara, tidak dihapus/digantikan oleh alur validasi ini.
- `tervalidasi` — status akhir. Admin upload foto SJ fisik final (bertandatangan) lewat
  `POST /admin/sj/{id}/validasi` (`SuratJalan::validate()`), mengisi kolom **terpisah**
  `foto_validasi` (beda dari `foto_surat_jalan` — supaya foto bukti lapangan & foto closing
  tidak saling menimpa), `divalidasi_oleh` (`shared_m_users.user_id` admin), `divalidasi_at`.
  Begitu `tervalidasi`, `attachPhoto()`/`upsertFromTripPhoto()` yang jalan belakangan (mis. supir
  re-upload checkpoint) TIDAK boleh menurunkan status ini lagi — dijaga eksplisit lewat
  `IF(status = 'tervalidasi', status, 'terkirim')` di query UPDATE-nya.

Tidak ada jalur "tolak"/reject — SJ fisik bertandatangan dianggap bukti sah, sekali tervalidasi
dianggap final (beda dari `ekspedisi_t_pengajuan_biaya` yang punya `disetujui`/`ditolak`).

**Belum ada**: UI edit field non-foto (backend `PUT` sudah siap, belum ada form-nya di
`ekspedisi-apk` — foto bukti lapangan cuma bisa diisi pas create di form "Buat Surat Jalan",
tombol "Validasi" (foto final) ada di halaman daftar `adminSuratJalan.js`).

## Struktur

```
ekspedisi-apk-backend/
├── database/                 # SQL mentah, satu file per langkah, dijalankan manual URUT NOMOR
│   ├── 01_schema.sql            # CREATE TABLE 6 tabel ekspedisi_* -- konsolidasi bersih, bukan migration
│   ├── 02_seed_admin_access.sql  # seed whitelist admin/dispatcher (cari by username, idempotent)
│   ├── 03_seed_dummy_drivers.sql # pre-provision profil supir INTERNAL dummy (cari by username, idempotent)
│   ├── 04_create_ekspedisi_t_surat_jalan.sql # CREATE TABLE modul surat jalan MILIK app ini (lihat bagian di atas)
│   ├── 05_alter_surat_jalan_pengirim_tgl_kirim.sql # ALTER tambah kolom pengirim & tgl_kirim (lihat bagian di atas)
│   ├── 06_alter_surat_jalan_validasi.sql # ALTER tambah status 'tervalidasi' + foto_validasi/divalidasi_oleh/divalidasi_at
│   ├── 07_create_ekspedisi_t_surat_jalan_item.sql # CREATE TABLE breakdown per lini produk SPK (lihat bagian di atas)
│   ├── 08_rename_pengirim_ke_penerima.sql # RENAME kolom pengirim -> penerima (lihat bagian di atas)
│   ├── 09_tambah_kolom_asal_surat_jalan.sql # ALTER tambah kolom asal (native/migrasi_legacy)
│   └── migrate_legacy_surat_jalan.php # script DATA sekali-jalan (BUKAN skema) -- migrasi surat_jalan lama, lihat bagian di atas
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
    │   ├── SpkReadyKirim.php      # query READ-ONLY ke t_penjualan_header -- 2 varian, belum-diplot vs belum-ada-SJ
    │   ├── ExpedisiLookup.php     # query READ-ONLY ke m_expedisi/m_expedisi_tarif (dropdown Tambah Supir Eksternal)
    │   ├── PengajuanBiaya.php     # create/list ekspedisi_t_pengajuan_biaya
    │   ├── PenjualanItemLookup.php # query READ-ONLY ke t_penjualan_detail_performa -- sisa qty per lini produk SPK
    │   └── SuratJalan.php         # CRUD ekspedisi_t_surat_jalan (+ item breakdown) MILIK app ini
    ├── Middleware/
    │   ├── AuthMiddleware.php      # cek Authorization: Bearer <token>, taruh user_id/role di request
    │   └── AdminOnlyMiddleware.php  # tolak 403 kalau role token bukan 'admin'
    └── Controllers/
        ├── Controller.php     # helper json()/error() dipakai semua controller
        ├── AuthController.php  # login, logout
        ├── DriverController.php  # /driver/* (nama class dipertahankan -- soal "supir", bukan bagian rename ekspedisi_*)
        ├── AdminController.php   # /admin/*
        └── SuratJalanController.php # /admin/sj* (modul surat jalan MILIK app ini)
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
