# backend-migrasi

**Ladang migrasi bertahap** backend internal Koperindo, keluar dari monolith `backend-production`
(Laravel 5.6/PHP 7.1) — satu Slim 4 app, **tanpa ORM, tanpa migration** (PDO + SQL mentah
langsung, skema tabel didefinisikan di [`database/`](database), satu subfolder per modul, file
bernomor urut dijalankan manual). **Terpisah** dari `backend-production` sebagai codebase, tapi
**login pakai akun pegawai yang sama** (`shared_m_users`) dan tabel domain tiap modul hidup di
**database produksi yang sama** — jadi tidak butuh cutover auth big-bang per modul yang dipindah,
lihat bagian "Modul" di bawah.

Repo ini dulu bernama `ekspedisi-apk-backend` (isinya cuma modul Ekspedisi). Direname
2026-08-21 setelah disepakati jadi rumah bersama utk migrasi modul-modul lain juga (Inventory
duluan, lihat "Modul" di bawah) -- **bukan pivot ganti domain**, modul Ekspedisi yang sudah ada
TIDAK berubah sama sekali (path/behavior identik, cuma lokasi file & namespace class-nya yang
pindah, lihat `src/Ekspedisi/`).

## Modul

- **Ekspedisi** (`src/Ekspedisi/`) — tracking supir (internal & eksternal) + manajemen surat
  jalan. **Live**, dipakai [`ekspedisi-apk`](../ekspedisi-apk) (Cordova). Route-nya **diprefix
  `/ekspedisi`** (`/ekspedisi/login`, `/ekspedisi/admin/sj`, `/ekspedisi/driver/me`, dst).
  **[BERUBAH 2026-08-22]** — dulu flat tanpa prefix (historis, dari sebelum repo ini
  multi-modul), diubah setelah `inventory-apk` kepergok memanggil `/login` polos dan malah kena
  `AuthController` modul ini (waktu itu memang tanpa prefix, jadi Slim mencocokkan ke sini
  duluan) — login gudang "berhasil" tersambung tapi balikin bentuk response Ekspedisi
  (`role: driver/admin`, bukan `AdminGudang`/`StaffGudang`), diam-diam gagal redirect di FE.
  `ekspedisi-apk` (frontend, `src/js/{api,auth,versionCheck}.js`) sudah disesuaikan di sesi yang
  sama. **Perlu dikoordinasikan sebelum deploy**: build `ekspedisi-apk` yang sudah ter-install
  di device supir/admin dan masih pakai path lama akan langsung putus begitu backend ini
  di-deploy, sampai device itu update ke build baru — lihat catatan lengkap di
  `src/Ekspedisi/routes.php`.
  **[DISEDERHANAKAN 2026-08-23]** Modul surat jalan (`ekspedisi_t_surat_jalan`,
  `App\Ekspedisi\Support\SuratJalan`/`SuratJalanController`) dapat 4 perubahan sekaligus,
  detail lengkap & alasan penuh ada di README `ekspedisi-apk` bagian "Penyederhanaan
  2026-08-23: 2 tab, nomor SJ manual" (bukan diduplikasi di sini):
  1. Tab "SPK" (FE) & endpoint `GET /admin/spk-belum-sj`/`AdminController::spkBelumSj()`
     DIHAPUS — `App\Ekspedisi\Support\SpkReadyKirim::listBelumSj()` ikut dihapus, `find()`
     dipertahankan (masih dipakai `createTrip()`).
  2. Nomor SJ (`no_surat_jalan`) **diinput manual admin**, bukan lagi auto-generate — kolom baru
     `nomor_urut` (int unsigned, unique) jadi sumber kebenaran, `no_surat_jalan` diturunkan darinya
     (`create()`/`update()`). Baris dari checkpoint foto supir (`upsertFromTripPhoto()`) TIDAK LAGI
     auto-assign nomor (method `assignNomor()` lama sudah dihapus). Skema kolom ini awalnya file
     migrasi terpisah (`04_nomor_sj_manual.sql`), **sudah digabung ke `database/ekspedisi/
     01_schema.sql`** sejak "Konsolidasi Keempat" (2026-08-23, lihat catatan di kepala file itu) --
     fresh install baru cukup 1 file skema, tidak perlu jalankan file migrasi terpisah lagi.
  3. `SuratJalan::list()` dipecah jadi `listSearch()` (mode `q` diisi, SQL biasa) &
     `listWithGaps()` (mode default — sisip baris VIRTUAL `missing: true` utk nomor yang hilang
     dalam tahun difilter, lintas semua status).
  4. Aturan baru "1 SJ boleh lintas SPK, TAPI cuma kalau semua dari klien yang sama" —
     `PenjualanItemLookup` sekarang bawa `client_id`/`client_nama` per lini (JOIN
     `t_penjualan_header`+`m_client`), divalidasi di `SuratJalanController::store()`.
- **Inventory** (`src/Inventory/`) — migrasi bertahap dari `backend-production`
  (`app/Http/Controllers/API/Inventory/*`, `Route::prefix('inventory')` di `routes/api.php`
  sana). Route-nya **diprefix `/inventory`** (dari awal MEMANG begitu, beda dari Ekspedisi yang
  baru menyusul) supaya dua modul tidak pernah bentrok path. **Status (2026-08-22):** Auth
  (`POST /inventory/login`, gate divisi Gudang
  `divisi_id=8`+`kode='WH'`), Config (`check-version`, `CONFIG_ID=VERSION_INVENTORY_PUSAT`),
  Material (CRUD + upload foto + doc-numbering), Opname (state machine penuh: sesi/scan/submit/
  approve/reject), Home Dashboard (+ create/list Purchase Request, tab PO), Stock In (receive PO
  + **Stock In Manual**, 2026-08-23), dan Stock Out (issue ke produksi + **Stock Out Manual**,
  2026-08-23) -- semua endpoint yang benar-benar dipanggil `inventory-apk` saat ini (lihat
  "Selesai" di `inventory-apk/ROADMAP.md` utk daftar persis) sudah diporting & **diverifikasi
  live** terhadap database produksi, termasuk posting stok WAC asli lewat
  `Support/StockPosting.php` (sekarang juga dukung
  `decrement_outstanding_in`/`decrement_outstanding_out`, dipakai StockIn/StockOut). Stock In
  sengaja TIDAK replikasi integrasi shadow-SJ (Surat Jalan) versi asli -- lihat docblock
  `Inventory/Controllers/StockInController.php`. **Stock In/Out Manual** (transaksi ad-hoc di
  luar PO/request produksi, **AdminGudang-only** -- gate role ditambahkan sadar, versi Laravel
  asli tidak punya sama sekali) posting ke `wh_t_stock_adjustment` (tabel yang sama dgn adjustment
  Opname), detail lengkap di `inventory-apk/ROADMAP.md`. **Retur Produksi (Stock Out)**
  (2026-08-24 -- sisi GUDANG saja: Inbox/Riwayat/Detail + Approve/Terima/Tolak atas retur yang
  DIBUAT di `produksi-apk`/`backend-production` (`POST /produksi/retur-material`, tabel
  `prd_t_retur_produksi(_detail)` SHARED dgn DB yang sama, endpoint create-nya SENGAJA TIDAK
  diporting) -- state machine SUBMITTED→APPROVED→RECEIVED (stok naik cuma di RECEIVED, lewat
  `StockPosting::postIn()`) atau reject ke CANCELLED dari SUBMITTED/APPROVED (tanpa gerak stok di
  keduanya), AdminGudang-only utk approve/receive/reject (versi asli TANPA AUTH SAMA SEKALI).
  Kolom `rejected_at/rejected_by/rejected_reason` ternyata belum ada di skema live (drift dari
  `db_dump.sql`) -- ditambahkan via `database/inventory/01_add_retur_produksi_reject_columns.sql`
  (file skema pertama modul Inventory). Retur ke supplier + replacement (Stock In) masih backlog,
  lebih kompleks -- lihat `inventory-apk/ROADMAP.md`. Endpoint lain (Done/History, export,
  approve/reject/cancel PR, dst) masih backlog, belum ada UI-nya di FE atau bukan aksi gudang.
  **⚠️ Bug data SHARED ditemukan (belum diperbaiki di sumbernya)**: baris
  `cfg_m_doc_number` utk `'PR'` py `reset_period=MONTHLY` tapi `format_pattern` (`PR-{NNNNN}`)
  tidak menyisipkan tahun/bulan -- reset bulanan bikin nomor collide dgn PR nyata dari bulan
  sebelumnya (`uniq_pr_number` violation). Diredam di `HomeController::createPurchaseRequest()`
  (sync ke max aktual dulu, pola sama dgn `MaterialController`), TAPI akar masalahnya ada di
  tabel `cfg_m_doc_number` sendiri yang dipakai BARENG `backend-production` -- kemungkinan besar
  backend-production kena bug yang sama tiap pergantian bulan. Perlu keputusan terpisah (ganti
  `reset_period` jadi `NONE`, atau tambah `{YY}{MM}` ke `format_pattern`), di luar scope porting.
  **Cutover LOCAL sudah jalan** (2026-08-22, susulan) — frontend [`inventory-apk`](../inventory-apk)
  `APP_CONFIG.API_BASE_URL` LOCAL sekarang ke sini, bukan lagi `backend-production` (production
  build masih nunjuk `backend-production`, belum disesuaikan). Detail lengkap (query per
  endpoint, bug yang ditemukan & diperbaiki, hasil verifikasi live) ada di
  `inventory-apk/ROADMAP.md`, bukan diduplikasi di sini.
- **Partner** (`src/Partner/`) — port dari `backend-production` `App\Http\Controllers\API\Partner\*`
  (`PartnerController`/`MaterialController`/`DeliveryController`/`ReturController`), dipakai
  [`inventory-apk`](../inventory-apk) halaman Partner. Route-nya **diprefix `/partner`** (SAMA
  dgn backend-production, path tidak berubah -- cuma host-nya pindah). Cuma endpoint yang
  dipanggil `inventory-apk` yang diporting (list transaksi, material, delivery/terima, retur) --
  `get-partner-data`/`approve`/`add-payment`/`transaksi/{id}/status`/`delete`/`get-partner-summary`
  TIDAK, dipakai app lain atau tidak dipanggil sama sekali. **[Beda dari backend-production]**
  di sana TANPA auth sama sekali; di sini digerbangi JWT (`AuthMiddleware`), keputusan sadar user
  saat porting (2026-08-22). Response envelope per-controller SENGAJA beda-beda (`{success}` vs
  `{status}` boolean) -- dikutip apa adanya dari backend-production, bukan salah porting. Detail
  & hasil verifikasi live ada di `inventory-apk/ROADMAP.md`.
- **Purchasing** (`src/Purchasing/`) — port dari `backend-production` `App\Http\Controllers\API\PurchasingController`
  (SEMUA method-nya, cuma 3), dipakai `inventory-apk` halaman Logo (tracking foto stiker/resin
  barang custom sebelum kirim). Domainnya penjualan/produksi
  (`t_penjualan_header`/`t_penjualan_detail_performa`/`m_client`), bukan Partner ataupun
  Inventory -- makanya modul sendiri. Route-nya **diprefix `/purchasing`** (BARU -- di
  backend-production top-level tanpa prefix sama sekali). Sama seperti Partner: digerbangi JWT
  di sini walau backend-production-nya tidak. Detail & hasil verifikasi live ada di
  `inventory-apk/ROADMAP.md`.

Modul baru = folder `src/<NamaModul>/` baru (routes.php + Controllers/) + satu baris mount di
`src/bootstrap.php`. Infra generik (`Database.php`, `Support/Jwt.php`, `Support/PhotoStorage.php`,
`Middleware/AuthMiddleware.php`, `Controllers/Controller.php`) tetap di `src/` root, dipakai
bersama semua modul -- lihat "Struktur" di bawah. Auth/Config **TIDAK** digeneralisasi jadi satu
class bersama walau kelihatan generic (pola yang sama dipakai `backend-production`: tiap app py
`config_id`/resolusi role sendiri) -- konsisten dgn konvensi workspace ini, duplikasi kecil
lebih disukai drpd abstraksi paksa utk hal yang beda bentuk per modul.

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

**Konsolidasi KEDUA (2026-08-20):** folder `database/` sempat bertambah lagi jadi 9 file
bernomor (`01`–`09`) setelah modul surat jalan dibangun bertahap (create tabel → alter
pengirim/tgl_kirim → alter validasi → create tabel item breakdown → rename kolom → tambah
kolom `asal`). Semua langkah itu sudah dijalankan sungguhan di produksi (lihat bagian "Modul
surat jalan" di bawah), jadi file `04`–`09` digabung balik ke `01_schema.sql` (snapshot skema
akhir, dipakai utk fresh install baru) — pola yang sama diulang seperti konsolidasi pertama di
atas. `migrate_legacy_surat_jalan.php` TIDAK ikut, karena itu operasi DATA sekali-jalan (sudah
pernah dijalankan), bukan skema.

## Arsitektur singkat

- **Tanpa migration**: [`database/`](database) berisi satu subfolder per modul (mis.
  [`database/ekspedisi/`](database/ekspedisi) — Inventory dkk belum py subfolder sendiri, lihat
  bagian "Modul" di atas), isinya `CREATE TABLE`/`ALTER`/`RENAME`/seed mentah, satu file per
  langkah, **bernomor urut sesuai urutan eksekusi** (`01_schema.sql`, `02_seed_admin_access.sql`,
  dst — lihat isi folder modulnya untuk daftar lengkap & urutannya). Ditulis mengikuti gaya persis
  tabel-tabel baru di `db_dump.sql` (`ENGINE=InnoDB`, `utf8mb4`/`utf8mb4_unicode_ci`, PK `id
  bigint unsigned AUTO_INCREMENT`, FK eksplisit — termasuk FK ke `shared_m_users` karena sekarang
  satu database yang sama). Jalankan manual, urut nomornya, satu-satu: `mysql -u <user> -p
  <database> < database/ekspedisi/01_schema.sql`, dst. Tidak ada `php artisan migrate`/runner apa
  pun — kalau nanti ada perubahan skema baru, tambah file baru dengan nomor berikutnya (mis.
  `04_...sql`), jangan edit file yang sudah pernah dijalankan **KECUALI** dikonsolidasikan balik
  ke `01_schema.sql` setelah dikonfirmasi jalan di produksi (pola "Konsolidasi Ke-N", lihat
  catatan di kepala tiap `01_schema.sql` modul, dan `SHOW CREATE TABLE` utk verifikasi sebelum
  konsolidasi — jangan asal gabung tanpa cek).
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
  file kembali, beda dari pendekatan `Storage::disk()` Laravel). Foto SJ (`ekspedisi_t_surat_jalan`)
  pola sama, folder `public/uploads/sj/{id}/`. Kedua jalur lewat `App\Support\PhotoStorage::save()`
  (2026-08-20, sebelumnya inline duplikat di 2 controller) — nama file per SLOT konteksnya
  (`berangkat`/`serah_terima`/`sj` utk checkpoint trip, `bukti`/`validasi` utk SJ), BUKAN
  timestamp, jadi re-upload ke slot yang sama TIMPA file lama di disk (dulu numpuk file basi
  tiap re-upload, sekarang tidak). Semua foto dikonversi ke **WEBP** (`imagewebp()`, kualitas 82)
  kalau server-nya punya GD dgn dukungan WebP (`function_exists('imagewebp')`, dicek runtime) —
  fallback simpan apa adanya (ekstensi asli) kalau tidak ada, supaya upload tetap jalan di
  environment tanpa GD (dev sandbox ini contohnya) alih-alih gagal total. Pastikan `ext-gd`
  ter-install di server produksi kalau mau konversi WebP-nya benar-benar aktif.

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
mysql -u <username> -p <database> < database/ekspedisi/01_schema.sql             # bikin 9 tabel ekspedisi_* baru (TIDAK menyentuh tabel lain)
mysql -u <username> -p <database> < database/ekspedisi/02_seed_admin_access.sql  # edit daftar username dulu, lihat di bawah
mysql -u <username> -p <database> < database/ekspedisi/03_seed_dummy_drivers.sql # opsional
php -S 127.0.0.1:8000 -t public
```

Edit dulu daftar `username` di [`database/ekspedisi/02_seed_admin_access.sql`](database/ekspedisi/02_seed_admin_access.sql)
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
| POST | `/config/check-version` | publik | `{ current_version_code }` (integer, Android versionCode) → `{ status: 'success', is_valid: bool, config: {...}\|null }` — bandingkan `>=` ke `config_value_minimal` baris `config_id='VERSION_EKSPEDISI_PUSAT'` di tabel `config` (shared, backend-production, READ-ONLY). `config: null` + `is_valid: true` (fail-open) kalau baris belum di-seed. Lihat bagian "Cek versi app" di bawah |
| POST | `/logout` | token | stateless (JWT) — tidak ada yang dihapus di server, dipertahankan utk kompatibilitas kontrak FE |
| GET | `/driver/whoami` | token | `{ role, user }` — dipertahankan utk kompatibilitas kontrak lama |
| GET | `/driver/me` | token | `{ id, name, status, active_trips: [...] }` |
| POST | `/driver/status` | token | `{ status: 'online'\|'resting'\|'offline' }` |
| POST | `/driver/location` | token | `{ lat, lng, speed, heading, accuracy, recorded_at }` |
| GET | `/driver/trip/{trip}` | token | `{ id, destination, status, completed_steps, current_step_label }` |
| POST | `/driver/trip/{trip}/photo` | token | multipart: `photo`, `type` (`berangkat`\|`serah_terima`\|`sj`), `lat`, `lng` |
| POST | `/driver/trip/{trip}/complete` | token | — |
| GET | `/admin/drivers` | token + admin | Supir yang **SEDANG mengirim saja** (2026-08-20, dulu SEMUA supir tanpa syarat — lihat "Tab Ekspedisi jadi murni monitoring" di bawah) → `[{ id, tipe, name, status, lat, lng, current_step_label }]` — `tipe`: `internal`\|`eksternal` |
| POST | `/admin/drivers` | token + admin | **multipart** (2026-08-20, dulu JSON polos). Internal: `{ tipe: 'internal', username }` + file `foto_sim` (**WAJIB**) (idempotent, cari akun `shared_m_users`). Eksternal: `{ tipe: 'eksternal', nama, telepon?, id_expedisi? }` + file `foto_ktp`, `foto_sim`, `foto_stnk` (**KETIGANYA WAJIB**) (bukan pegawai, tidak bisa login). → `{ id, name, status, tipe, foto_*: path }`, 201, 422 kalau dokumen wajib belum lengkap |
| POST | `/admin/drivers/{driver}/documents` | token + admin | multipart, semua field OPSIONAL (isi salah satu/lebih, minimal 1): `foto_sim?`, `foto_ktp?`, `foto_stnk?` → `{ foto_sim, foto_ktp, foto_stnk }` (URL). Lengkapi/ganti dokumen supir yang SUDAH ADA — dipakai buat supir internal yang ke-provision otomatis lewat login pertama (`SupirProfile::ensure()`, tidak pernah lewat `POST /admin/drivers` sama sekali sehingga tidak punya dokumen), atau ganti foto yang salah/kadaluarsa |
| GET | `/admin/drivers/{driver}` | token + admin | `{ id, tipe, name, phone, status, trips: [...] }` |
| POST | `/admin/drivers/{driver}/trip` | token + admin | `{ destination, no_surat_jalan?, penjualan_id? }` — keduanya opsional, kalau diisi WAJIB cocok baris asli (lihat bagian Integrasi di bawah). Jalur MANUAL/independen dari SPK-SJ (mis. errand internal) — dipakai layar "Perjalanan Baru" (`adminNewTrip.js`), BUKAN jalur assignment utama lagi sejak 2026-08-20 (lihat `POST /admin/sj`) |
| POST | `/admin/trips/{trip}/complete` | token + admin | Tandai trip selesai secara manual — **HANYA** untuk trip milik supir `tipe='eksternal'` (422 kalau supirnya internal). Satu-satunya cara menyelesaikan trip supir eksternal, karena mereka tidak punya akun & tidak bisa panggil `/driver/trip/{trip}/photo`+`/complete` sendiri. Lihat bagian "Supir eksternal" di bawah |
| GET | `/admin/surat-jalan/{no}` | token + admin | Cek 1 nomor SJ asli (READ-ONLY ke `surat_jalan` milik `backend-production`) → `{ no_surat_jalan, tanggal, kendaraan, plat, pengirim, valid_cs, penjualan_id, client_nama, client_alamat }`, 404 kalau tidak ketemu |
| GET | `/admin/spk-belum-sj` | token + admin | Daftar SPK ready-kirim yang **belum ada SJ sama sekali** (`SpkReadyKirim::listBelumSj()`, dipakai tab "SPK" ekspedisi-apk). Query opsional: `q` (cari nama client/no SPK), `page` (default 1), `per_page` (default 20, maks 100) → `{ data: [...], total, page, per_page }`. (`GET /admin/spk-ready-kirim`, "belum diplot ke supir", DIHAPUS 2026-08-20 bareng "Plot SPK ke Supir" — lihat bagian di bawah) |
| GET | `/admin/ekspedisi` | token + admin | Master perusahaan ekspedisi eksternal (2026-08-20, MILIK app ini sendiri — `ekspedisi_m_ekspedisi`, dulu READ-ONLY ke `m_expedisi`, lihat bagian "Master perusahaan ekspedisi eksternal" di bawah). Query opsional `all=1` (semua termasuk nonaktif, layar kelola) — default cuma aktif (dropdown Tambah Supir Eksternal) → `[{ id, kode_ekspedisi, nama_ekspedisi, pic, alamat, no_telp, is_active }]` |
| POST | `/admin/ekspedisi` | token + admin | `{ kode_ekspedisi?, nama_ekspedisi (WAJIB), pic?, alamat?, no_telp? }` → 201, `{ ...perusahaan baru }`, `is_active` selalu mulai `1` |
| PUT | `/admin/ekspedisi/{id}` | token + admin | Field opsional (update parsial): `{ kode_ekspedisi?, nama_ekspedisi?, pic?, alamat?, no_telp?, is_active? }` — `is_active: false` = nonaktifkan (bukan hapus baris) → `{ ...perusahaan terupdate }`, 404 kalau tidak ditemukan |
| POST | `/admin/trips/{trip}/pengajuan-biaya` | token + admin | `{ nominal_diajukan, keterangan? }` → 201. `nominal_diajukan` input manual admin. Berlaku utk trip supir internal maupun eksternal |
| GET | `/admin/trips/{trip}/pengajuan-biaya` | token + admin | Riwayat pengajuan biaya utk 1 trip → `[{ id, trip_id, nominal_diajukan, status, nominal_disetujui, catatan_finance, ... }]` |
| GET | `/admin/sj/spk/{penjualan_id}/items` | token + admin | Lini produk 1 SPK + sisa qty yang belum terkirim (READ-ONLY, lihat `App\Support\PenjualanItemLookup`) → `[{ penjualan_detail_performa_id, penjualan_jenis, penjualan_qty, terkirim, sisa }]`, 404 kalau SPK tidak ditemukan |
| GET | `/admin/sj` | token + admin | Daftar surat jalan **milik app ini sendiri** (`ekspedisi_t_surat_jalan`, independen dari `surat_jalan` lama). Query opsional: `status`, `penjualan_id`, `q` (cari no_surat_jalan/tujuan/penerima/nama supir/no SPK yang disentuh), `page` (default 1), `per_page` (default 20, maks 100) → `{ data: [{ id, no_surat_jalan, trip_id, penjualan_id, driver_id, nama_supir, tujuan, kendaraan, plat, penerima, jumlah_kirim, asal, items: [{ penjualan_detail_performa_id, penjualan_id, penjualan_jenis, jumlah_kirim }], client_names: [...], foto_surat_jalan, status, ... }], total, page, per_page }` — `penjualan_id` di level item beda-beda kalau SJ ini lintas SPK, header `penjualan_id` cuma keisi jalur trip-linked lama. `asal` = `native`/`migrasi_legacy` (lihat "Migrasi data historis" di bawah). `client_names` (2026-08-20, baru) = array nama klien (`m_client.client_nama`) dari SPK yang disentuh SJ ini, lihat `App\Support\SuratJalan::resolveClientNames()` — dipakai kolom "Klien" di tabel `ekspedisi-apk` |
| POST | `/admin/sj` | token + admin | `{ trip_id?, driver_id (WAJIB), tujuan?, kendaraan?, plat?, penerima?, jumlah_kirim?, tgl_kirim?, catatan?, items?: [{ penjualan_detail_performa_id, jumlah_kirim }] }` → 201, 422 kalau `driver_id` kosong. `items` BOLEH berisi lini produk dari beberapa SPK berbeda sekaligus (tidak ada `penjualan_id` di body lagi — SPK-nya diketahui per-item). Kalau `items` diisi, `jumlah_kirim` dihitung otomatis dari total item & tiap item divalidasi ulang satu-satu ke sisa qty terkini (422 kalau melebihi). **`trip_id` biasanya TIDAK perlu dikirim** (2026-08-20) — kalau kosong & `driver_id`-nya supir **internal**, backend OTOMATIS bikin trip baru & menautkannya (gantiin langkah "Plot SPK ke Supir" yang dihapus, lihat bagian di bawah); supir **eksternal** sengaja TIDAK dibikinkan trip |
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

Alur "kapan sebuah SPK siap dikirim" (jadi acuan tab "SPK" di `ekspedisi-apk`), ditelusuri dari
kode `backend-production`:

1. Order dibuat, CS isi `penjualan_tanggal_kirim` (tanggal kirim yang diminta customer).
2. Saat alamat kirim disimpan (`ServiceController::updateAlamatKirim()`), sistem cek status
   pembayaran: **belum lunas** → `shipment_status = 'requested'` (kirim notifikasi Firebase,
   minta approval — **ini titik "admin mengajukan ke finance"** yang disebut). **Sudah lunas**
   → langsung `shipment_status = 'approved'`, tanpa approval.
3. `ServiceController::approveShipment()` (endpoint terpisah, approve/reject) memindahkan
   `requested` → `approved`/`rejected`.
4. Order dengan `shipment_status = 'approved'` DAN `status_pengirman = 'belum_selesai'` siap
   dikirim.

**Sudah dites & LIVE dengan data asli** (2026-08-19): dari database produksi, ada 2.005 order
`pending`, 8 `requested` (menunggu approval), 7 `approved` — 3 di antaranya `approved` +
`belum_selesai` (siap kirim sungguhan, bukan data test).

Ada juga endpoint `Ekspedisi\EkspedisiController::getSpkReadyKirim()` di `backend-production`
dengan query serupa (plus sistem rekomendasi ekspedisi luar berbasis skor harga/kecepatan/
histori ketepatan waktu: `getRekomendasiEkspedisi`, `setEkspedisiPenjualan`, tabel
`m_expedisi`/`m_expedisi_tarif`/`t_pengiriman`) — **tapi tidak dipanggil app manapun di
workspace ini, dan tabelnya kosong/cuma data test di database live.** Sengaja **tidak**
diintegrasikan ke sini.

**Satu-satunya jalur ke `ekspedisi-apk` sekarang: tab "SPK" — halaman awal admin.** Kriteria
"siap tapi belum X" di sini: **belum ada SJ sama sekali** (`SpkReadyKirim::listBelumSj()`,
`GET /admin/spk-belum-sj`). Aksi per baris cuma **"Surat Jalan"** (navigasi ke
`adminNewSuratJalan.js` dengan `penjualan_id` dititip lewat `prefill.js`, jadi grup SPK pertama
otomatis) — dari situ admin langsung pilih supir & submit, **tidak ada langkah plotting
terpisah lagi** (lihat "Tab Ekspedisi jadi murni monitoring" di bawah).

Sejak 1 SJ boleh lintas SPK (lihat "Breakdown per lini produk SPK" di bawah), `listBelumSj()`
cek **DUA jalur** buat "sudah ada SJ" — header `ekspedisi_t_surat_jalan.penjualan_id` (trip-linked
lama) ATAU `ekspedisi_t_surat_jalan_item` JOIN `t_penjualan_detail_performa` (manual, breakdown
per produk). Kalau cuma cek header saja, SPK yang SJ-nya tercatat lewat item (bukan header) akan
salah muncul lagi sebagai "belum ada SJ" padahal sudah ada.

### Tab Ekspedisi jadi murni monitoring (2026-08-20)

**Keputusan produk:** dengan tab SPK & SJ sudah ada, halaman **"Plot SPK ke Supir"**
(`adminSpkKirim.js`, dulu drill-down dari tab Ekspedisi — daftar SPK ready-kirim belum diplot,
admin pilih supir, klik "Plot" bikin `ekspedisi_t_trip` tertaut `penjualan_id`) dianggap
**redundan** — assignment supir kan sudah melekat ke pengiriman (SJ), dan pengiriman sudah
melekat ke SPK, jadi tidak perlu ada langkah plotting TERPISAH SEBELUM SJ ada lagi. **DIHAPUS**
sepenuhnya: `adminSpkKirim.js` (frontend), `GET /admin/spk-ready-kirim` +
`AdminController::spkReadyKirim()`, `App\Support\SpkReadyKirim::list()`.

Tab "Ekspedisi" sekarang **murni monitoring** — cuma nampilin lokasi & kondisi supir yang
**SEDANG melakukan pengiriman**, bukan lagi tempat assignment. `AdminController::drivers()`
(`GET /admin/drivers`) di-filter server-side, "sedang mengirim" = py trip aktif
(`ekspedisi_t_trip.status='in_progress'`) **ATAU** py SJ yang belum tervalidasi
(`ekspedisi_t_surat_jalan.status` IN `draft`/`terkirim`) — dua jalur independen krn supir
internal & eksternal sekarang punya jalur beda (lihat di bawah). Supir yang TIDAK sedang mengirim
tidak muncul sama sekali (dulu SEMUA supir tanpa syarat).

**Assignment sekarang murni lewat `driver_id` di `POST /admin/sj`** (bukan langkah terpisah lagi)
— tapi trip (`ekspedisi_t_trip`) TETAP relevan utk supir **internal**, krn itu yang dipakai supir
lihat tugasnya & checkpoint foto sendiri lewat app-nya (`driverWorkflow.js`, 3 langkah
berangkat/serah_terima/sj). Makanya `SuratJalanController::store()` **OTOMATIS bikin trip** kalau
`trip_id` tidak dikirim eksplisit & `driver_id`-nya supir internal (destination dari `tujuan`,
`penjualan_id` diisi kalau SJ ini cuma nyentuh TEPAT 1 SPK) — trip baru itu langsung ditautkan
sbg `trip_id` SJ yang baru dibikin, jadi begitu supir upload checkpoint foto `type=sj`,
`SuratJalan::upsertFromTripPhoto()` (`findByTrip()`) nemu SJ yang SAMA (bukan bikin baris baru)
& update `foto_surat_jalan`/`status`-nya di tempat. Supir **eksternal** SENGAJA tidak pernah
dibikinkan trip lagi (tidak bisa login/checkpoint apa pun) — status "sedang mengirim"-nya cukup
dibaca dari status SJ itu langsung, tanpa perantara trip.

Trip lama peninggalan "Plot SPK ke Supir" (sebelum dihapus) yang MASIH `in_progress` tetap
kebaca normal oleh filter monitoring di atas (backward-compat) — cuma jalur PEMBUATAN trip
barunya yang berubah, skema `ekspedisi_t_trip` sendiri tidak disentuh sama sekali.

### Supir eksternal / pihak ketiga (`ekspedisi_m_supir.tipe = 'eksternal'`)

**(Riwayat desain, lihat histori git: percobaan pertama pakai tabel terpisah
`driver_t_ekspedisi` — SPK langsung ke perusahaan ekspedisi, tanpa lewat konsep "supir" —
DIBATALKAN & di-drop, belum sempat ada data/UI. File-nya sendiri sudah tidak ada lagi di
`database/` setelah konsolidasi migration. Diganti pendekatan di bawah, lebih sederhana: satu
alur "supir" utk internal maupun eksternal.)**

`ekspedisi_m_supir` punya kolom `tipe` (`internal`/`eksternal`, lihat `database/01_schema.sql`). Supir
eksternal (bukan pegawai — freelance/lepas, atau bekerja utk perusahaan ekspedisi tertentu)
**tidak punya akun `shared_m_users` sama sekali** — `user_id` NULL, `nama_eksternal`/
`telepon_eksternal` diisi langsung, `id_expedisi` (FK asli ke `ekspedisi_m_ekspedisi.id` sejak
2026-08-20, dulu tautan logis ke `m_expedisi` — lihat bagian "Master perusahaan ekspedisi
eksternal" di bawah) NULL kalau lepas/independen. Konsekuensi penting: **supir eksternal tidak bisa login ke app
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

Karena ini tetap `ekspedisi_m_supir` biasa, dropdown "pilih supir" (`adminNewSuratJalan.js` saat
bikin SJ, atau `adminNewTrip.js` saat "Perjalanan Baru" manual) menampilkan internal & eksternal
sekaligus, `POST /admin/drivers/{driver}/trip` sama persis dipakai untuk keduanya. Tidak ada
endpoint/tabel terpisah lagi utk "serahkan ke ekspedisi". **Catatan (2026-08-20):** sejak SJ
tidak lagi otomatis bikin trip utk supir eksternal (lihat "Tab Ekspedisi jadi murni monitoring"
di atas), trip eksternal cuma tercipta lewat jalur MANUAL "Perjalanan Baru" — kalau admin tidak
pernah pakai jalur itu utk supir eksternal, tombol "Tandai Selesai" di bawah ini jadi jarang
kepakai (bukan hilang, cuma tidak ada trip yang perlu diselesaikan lewat situ lagi utk alur SJ
biasa).

`App\Support\Ekspedisi` (dulu `ExpedisiLookup`, READ-ONLY ke `m_expedisi` — DIHAPUS) — sekarang
CRUD penuh ke `ekspedisi_m_ekspedisi` (tabel lokal), dipakai `GET/POST /admin/ekspedisi` +
`PUT /admin/ekspedisi/{id}`. Lihat bagian "Master perusahaan ekspedisi eksternal" di bawah.

### Master perusahaan ekspedisi eksternal (2026-08-20)

**Keputusan produk:** dengan tab SPK & SJ sudah ada, admin butuh kelola (tambah/edit/nonaktifkan)
data perusahaan ekspedisi eksternal langsung dari app ini, bukan cuma dropdown read-only.
Ditelusuri dulu ke `m_expedisi` (backend-production, yang sebelumnya dibaca `ExpedisiLookup`) —
ternyata tabel itu **sudah punya CRUD lengkap sendiri** di sana (`save`/`update`/
`delete-master-ekspedisi`, terdaftar di `routes/api.php`), cuma **tidak dipanggil frontend
manapun** di workspace ini, dan cuma berisi 1 baris data test ("TEST EKPEDISI JAYA") di database
live.

**Keputusan: tabel baru independen (`ekspedisi_m_ekspedisi`), BUKAN CRUD langsung ke
`m_expedisi`.** Alasannya sama persis dgn keputusan `ekspedisi_t_surat_jalan` independen dari
`surat_jalan` lama (lihat bagian "Modul surat jalan" di atas) — `m_expedisi` sudah py "pemilik"
(backend-production) dgn CRUD sendiri; nulis ke situ juga dari codebase terpisah ini berisiko 2
sistem independen saling menimpa tanpa koordinasi kalau backend-production suatu saat benar-benar
mengaktifkan CRUD-nya sendiri. Dicek dulu ke data produksi sebelum tabel ini dibikin: **tidak ada
satu pun baris `ekspedisi_m_supir` yang py `id_expedisi` terisi** saat itu, jadi tidak ada data
lama yang perlu dimigrasikan dari `m_expedisi` — mulai dari tabel kosong aman, dan `ekspedisi_m_supir.id_expedisi` sekalian diubah dari tautan logis jadi **FK asli** (tipe kolom `int` → `bigint unsigned` biar cocok dgn `ekspedisi_m_ekspedisi.id` — sekarang bagian skema konsolidasi `database/01_schema.sql`, lihat "Konsolidasi KETIGA" di file itu).

`App\Support\Ekspedisi` (`database/01_schema.sql`) — CRUD standar (`list()` dgn
`$includeInactive` opsional, `find()`, `create()`, `update()` parsial termasuk toggle
`is_active`). `EkspedisiController` (baru, dipisah dari `AdminController` yang sudah besar,
mengikuti pola `SuratJalanController`) expose lewat `GET`/`POST /admin/ekspedisi` +
`PUT /admin/ekspedisi/{id}` — kontrak `GET` (default, tanpa `?all=1`) SENGAJA dipertahankan sama
persis (field berubah nama tapi bentuknya array polos) supaya dropdown "Perusahaan Ekspedisi"
yang sudah ada (`adminNewDriver.js`) tidak perlu banyak berubah. **Nonaktifkan (`is_active=0`),
bukan hapus baris** — riwayat supir/trip yang pernah ditautkan ke perusahaan itu tetap utuh.

**`database/migrate_m_expedisi_ke_ekspedisi_m_ekspedisi.php`** (script DATA sekali-jalan,
OPSIONAL — BUKAN bagian skema 01-03, WAJIB dijalankan SETELAH `01_schema.sql`) — duplikasi baris
`m_expedisi` (backend-production, TIDAK PERNAH ditulis/diubah script ini) ke
`ekspedisi_m_ekspedisi`, kolom `kode_expedisi`/`nama_expedisi`/`pic`/`alamat`/`no_telp`/
`is_active` disalin apa adanya (`no_rekening`/`nama_bank`/`nama_rekening` TIDAK ikut, di luar
cakupan skema tujuan), `id` selalu BARU (auto-increment sendiri, dua tabel independen tanpa
keterkaitan primary key). Idempotent — identitas 1 baris ditentukan dari `kode_expedisi`
(NOT NULL + UNIQUE di sumber), baris yang `kode_ekspedisi`-nya sudah ada di tujuan DILEWATI
(tidak ditimpa). **SUDAH DIJALANKAN sungguhan di produksi (2026-08-20)** — 1 baris ("TEST
EKPEDISI JAYA", kode `EXP-20260418-7620`) berhasil disalin, dites ulang lewat SELECT langsung ke
tabel tujuan setelahnya. Aman dijalankan lagi kapan pun (idempotent, mis. kalau `m_expedisi`
sumber nambah baris baru belakangan):
```bash
php database/migrate_m_expedisi_ke_ekspedisi_m_ekspedisi.php --dry-run  # preview, tidak nulis apa-apa
php database/migrate_m_expedisi_ke_ekspedisi_m_ekspedisi.php            # jalankan sungguhan
```

### Dokumen supir (foto KTP/SIM/STNK, 2026-08-20)

`ekspedisi_m_supir` dapat 3 kolom baru (sekarang bagian skema konsolidasi `database/01_schema.sql`,
**sudah ter-apply di produksi**, lihat bagian Setup di atas): `foto_sim`, `foto_ktp`, `foto_stnk`
(semua nullable, path relatif — pola sama seperti kolom foto
lain di app ini, disimpan lewat `App\Support\PhotoStorage::save()` jadi **WEBP**). Validasi
"wajib"-nya di level Controller, BUKAN `NOT NULL` di skema:

- **SIM wajib utk SEMUA supir** (internal maupun eksternal, sama-sama nyetir).
- **KTP & STNK tambahan wajib CUMA utk supir eksternal** (bukan pegawai — identitas & aset
  kendaraan tidak terverifikasi lewat status kepegawaian/aset perusahaan seperti supir internal).
- `POST /admin/drivers` (`createDriver()`/`createDriverEksternal()`) cek KEBERADAAN file
  SEBELUM insert apa pun — gagal validasi = tidak ada baris/upload yang kesentuh sama sekali,
  bukan baris "setengah jadi".

Kolom TETAP nullable krn 2 alasan (lihat komentar lengkap di `01_schema.sql`): (1) profil supir
INTERNAL bisa ke-provision OTOMATIS saat login pertama (`SupirProfile::ensure()`, dipanggil dari
`AuthController::login()`) — **tanpa lewat `POST /admin/drivers` sama sekali**, jadi tidak ada
titik mana pun buat isi dokumennya saat itu; (2) data supir yang sudah ada sebelum migration ini
otomatis NULL dulu. **`POST /admin/drivers/{driver}/documents`** (baru) nutup gap itu — admin bisa
lengkapi/ganti dokumen supir yang SUDAH ADA kapan saja, field opsional (isi salah satu/lebih).
`GET /admin/drivers/{driver}` sekarang ikut balikin `foto_sim`/`foto_ktp`/`foto_stnk` (URL
lengkap, atau `null` kalau belum diisi) buat ditampilkan di layar Detail Supir.

Foto disimpan di `public/uploads/drivers/{driver_id}/` (folder baru, konsisten dgn pola
per-konteks `uploads/trips/{id}/`+`uploads/sj/{id}/` yang sudah ada) — nama file `sim.webp`/
`ktp.webp`/`stnk.webp` (bukan timestamp, sama alasannya seperti `PhotoStorage` di tempat lain:
upload ulang ke slot yang sama TIMPA file lama, tidak numpuk sampah).

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

`no_surat_jalan` **auto-generated** setelah insert (format `SJ_YYYYMMDD_xxxx`, sebelum
2026-08-21 separatornya `-`; `xxxx` = id GLOBAL auto-increment dipadding 4 digit, BUKAN reset
per hari; tahun tetap di depan -- bukan `DDMMYYYY` -- supaya nomornya otomatis terurut
kronologis kalau di-sort sbg teks) — `App\Support\SuratJalan::assignNomor()`, dipanggil dari `create()` maupun
`upsertFromTripPhoto()`. **Sengaja tetap auto-generate**, tidak diketik manual seperti
`no_surat_jalan`/`SJ_...` di `surat-jalan-apk` — keputusan dipertahankan setelah dibandingkan
langsung ke alur input SJ asli (lihat catatan di bawah). `PUT /admin/sj/{id}` buat admin
melengkapi/koreksi field non-foto (mis. SJ yang auto-dibuat dari checkpoint biasanya minim data
— `kendaraan`/`plat`/`jumlah_kirim` belum terisi, admin lengkapi belakangan).

**Perbandingan dengan alur input SJ asli di `surat-jalan-apk` (2026-08-19):** ditelusuri
`surat_jalan.js`/`surat_jalan.html` (`POST /surat-jalan-proses`) buat cek field apa saja yang
benar-benar dipakai di lapangan. Dua gap pertama ditutup — kolom `pengirim` (nama orang yang
serah-terima barang, terpisah dari nama supir) dan `tgl_kirim` (tanggal kirim, bisa beda dari
`created_at`) ditambah (kini bagian skema konsolidasi `database/01_schema.sql`); endpoint
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
- **`pengirim` di-RENAME jadi `penerima`** (kini bagian skema konsolidasi `database/01_schema.sql`) —
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

Tabel `ekspedisi_t_surat_jalan_item` (bagian skema konsolidasi `database/01_schema.sql`)
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
`ekspedisi_t_surat_jalan.asal` (bagian skema konsolidasi `database/01_schema.sql`, enum
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
Idempotent, **1 transaksi PER DOKUMEN** (bukan 1 transaksi besar utk semua baris seperti versi
awal — direvisi setelah dites ke data produksi asli: kalau 1 transaksi besar & ada 1 dokumen
bermasalah di tengah ~1500 dokumen, SEMUA yang sudah berhasil ikut rollback, harus diulang dari
nol). Dokumen yang gagal di-skip & dilaporkan di akhir (nomor + pesan error), dokumen lain yang
sudah berhasil TETAP tersimpan; jalankan lagi scriptnya setelah masalahnya ditinjau (idempotent,
yang sudah berhasil tidak akan diulang lagi). Per `no_surat_jalan`: `no_surat_jalan` ASLI
dipertahankan (bukan digenerate ulang) — **KECUALI kalau ada tabrakan** (lihat "Disambiguasi" di
bawah), `status` diseragamkan `terkirim` (data lama tidak punya info andal yang bisa dipetakan
ke draft/tervalidasi — `valid_cs` di dump ini seragam `1` semua). Empat keputusan lain yang
lossy-tapi-disengaja (dua pertama ditemukan waktu benar-benar dites ke data produksi):
- **Disambiguasi `no_surat_jalan`** — `surat_jalan` lama TIDAK py UNIQUE constraint di kolom
  itu, dan ternyata ada kasus 2 DOKUMEN FISIK BERBEDA (jam beda, isi baris beda) dikasih nomor
  yang cuma beda kapitalisasi (mis. `"SJ_003/Amb-c-11/2024"` vs `"SJ_003/amb-c-11/2024"`) —
  bentrok pas insert krn UNIQUE KEY `ekspedisi_t_surat_jalan.no_surat_jalan` pakai collation
  case-INSENSITIVE (`utf8mb4_unicode_ci`). Dideteksi & dikasih suffix `" (migrasi-2)"`,
  `" (migrasi-3)"`, dst sebelum insert (dihitung SEBELUM proses, konsisten antar-run) — kedua
  dokumen tetap tersimpan terpisah, bukan digabung/dibuang.
- **`jumlah_kirim` NULL diisi 0** — sebagian baris `surat_jalan` lama ternyata punya
  `jumlah_kirim` NULL (kolomnya nullable di skema lama, beda dari
  `ekspedisi_t_surat_jalan_item.jumlah_kirim` yang NOT NULL). Baris & fotonya tetap tercatat
  (bukan dilewati), dilaporkan di akhir. Baris tanpa `penjualan_detail_performa_id` (kalau ada)
  beda kasus — DILEWATI per-baris (bukan diisi apa-apa), krn tidak bisa jadi item apa pun tanpa itu.
- **`driver_id` tetap NULL** — kolom `pengirim` di data lama cuma teks bebas (465 nilai unik
  gaya `"Yoyo (diambil)"`/`"Haer/gojek"`), tidak match andal ke `ekspedisi_m_supir` manapun.
  Nilai aslinya disimpan di `catatan` (**bukan** dipetakan ke `penerima` — beda arti, `penerima`
  = PIC di tujuan, bukan nama pengirim/kurir).
- **`foto_surat_jalan` diisi URL ABSOLUT** ke host lama
  (`https://indokoper.com/foto_surat_jalan/{filename}`, 95% baris py nama file valid) — TIDAK
  disalin fisik ke `public/uploads/` app ini. Frontend (`adminSuratJalan.js`, `fotoUrl()`) sudah
  disesuaikan biar bisa nampilin URL absolut apa adanya (dites langsung, foto beneran kebuka
  dari host lama).

**Sudah dijalankan sungguhan di produksi (2026-08-20)** setelah 2 putaran perbaikan berdasar
error nyata yang muncul (jumlah_kirim NULL, tabrakan no_surat_jalan case-insensitive — keduanya
sudah diceritakan di atas) — hasil akhir: 1.508 baris `ekspedisi_t_surat_jalan` ber-`asal =
'migrasi_legacy'`, konsisten dengan hitungan `--dry-run` sebelumnya (3.160 baris → 1.508
dokumen). `surat_jalan` (tabel lama) tetap utuh sepenuhnya — script ini cuma pernah `SELECT`
dari situ, tidak sekalipun `UPDATE`/`DELETE`/`TRUNCATE`.

#### Pagination & search (2026-08-20)

Ditambahkan setelah migrasi historis di atas bikin `ekspedisi_t_surat_jalan` punya 1.500+ baris
sekaligus — `GET /admin/sj` & `GET /admin/spk-belum-sj` yang sebelumnya fetch semua baris tanpa
batas jadi berat. Keduanya sekarang terima `q` (search) + `page`/`per_page` (default 20, maks
100 lewat `min(100, ...)`) dan return `{ data, total, page, per_page }` (dulu array polos —
**breaking change** ke kontrak respons, semua konsumen frontend sudah disesuaikan). `total`
dihitung dari query `COUNT(*)` terpisah pakai `WHERE` yang sama (tanpa `LIMIT`), `LIMIT`/`OFFSET`
diinterpolasi langsung ke SQL (bukan bind parameter) — aman krn keduanya sudah di-cast `(int)`
lebih dulu di PHP, bukan dari string mentah.

`SuratJalan::list()`'s `q` nyari lintas `no_surat_jalan`/`tujuan`/`penerima`/nama supir
(`u.nama_lengkap`/`s.nama_eksternal`) **dan** no SPK yang disentuh (`EXISTS` ke
`ekspedisi_t_surat_jalan_item` JOIN `t_penjualan_detail_performa`) — jadi bisa cari 1 SJ lewat
nomor SPK-nya juga, tidak cuma no_surat_jalan.

**Jebakan PDO yang ditemukan waktu nulis ini (catat buat query lain ke depan):**
`Database.php` set `PDO::ATTR_EMULATE_PREPARES => false` (native prepares) — beda dari mode
emulated, native prepares TIDAK mengizinkan named placeholder yang sama dipakai lebih dari 1x
dalam 1 query (mis. `WHERE a LIKE :q OR b LIKE :q`), throw
`SQLSTATE[HY093]: Invalid parameter number`. Solusinya: kasih nama beda tiap occurrence
(`:q1`, `:q2`, dst) meski nilainya sama persis, bind semuanya ke value yang sama. Dites langsung
ke database produksi setelah fix (search "amb-c-11" balikin 7 hasil yang benar, termasuk baris
hasil disambiguasi `"... (migrasi-2)"` di atas).

**Urutan `ORDER BY` (2026-08-20):** `SuratJalan::list()` semula `ORDER BY sj.id DESC`. Ini keliru
buat 1.508 baris hasil migrasi historis — `id`-nya mencerminkan urutan PEMROSESAN skrip migrasi
(dikelompokkan per `no_surat_jalan`), bukan urutan tanggal dokumen aslinya, jadi baris tahun 2024
bisa nongol paling atas sementara baris hari ini terkubur di tengah. Diganti jadi
`ORDER BY sj.created_at DESC, sj.id DESC` — `created_at` diisi tanggal `surat_jalan.tanggal` asli
waktu migrasi (lihat bagian migrasi di atas), jadi konsisten mewakili tanggal dokumen sebenarnya
baik utk baris native maupun hasil migrasi. Dites langsung ke database produksi: baris `id`
tertinggi (3754, `SJ_b`, tanggal `2024-01-15`) sekarang benar-benar di bawah, baris tanggal
`2026-08-20` (hari ini) di paling atas. Tidak ditambah index baru di `created_at` — cuma 1.508
baris, filesort-nya masih murah tanpa index.

#### Alur validasi (2026-08-19)

Proses fisik yang dimodelkan: admin bikin SJ (`draft`) → dokumen fisik dibawa supir → barang
diterima, SJ ditandatangani penerima → supir kembalikan SJ fisik yang sudah ditandatangani ke
admin → **admin** upload foto SJ final itu sekaligus menandai pengiriman **tervalidasi**. Tiga
status sekarang (bagian skema konsolidasi `database/01_schema.sql`):

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

Restrukturisasi multi-modul 2026-08-21 (lihat "Modul" di atas) -- infra generik di `src/` root,
tiap domain di `src/<Modul>/` sendiri dgn `routes.php` + `Controllers/` + `Support/` +
`Middleware/` (kalau perlu) masing-masing.

```
backend-migrasi/
├── database/
│   ├── ekspedisi/                # SQL mentah modul Ekspedisi, satu file per langkah, URUT NOMOR
│   │   ├── 01_schema.sql            # CREATE TABLE 9 tabel ekspedisi_* (master ekspedisi lokal, supir
│   │   │                              # +dokumen KTP/SIM/STNK, modul surat jalan +breakdown produk
│   │   │                              # +alur validasi) -- konsolidasi KETIGA (2026-08-20), bukan
│   │   │                              # migration, lihat header file
│   │   ├── 02_seed_admin_access.sql  # seed whitelist admin/dispatcher (cari by username, idempotent)
│   │   ├── 03_seed_dummy_drivers.sql # pre-provision profil supir INTERNAL dummy (cari by username, idempotent)
│   │   ├── migrate_legacy_surat_jalan.php # script DATA sekali-jalan (BUKAN skema) -- migrasi surat_jalan lama, lihat bagian di atas
│   │   └── migrate_m_expedisi_ke_ekspedisi_m_ekspedisi.php # script DATA sekali-jalan, OPSIONAL -- duplikasi m_expedisi, lihat bagian "Master perusahaan ekspedisi eksternal"
│   └── inventory/                # BELUM ADA -- tabel modul Inventory sudah live di
│                                  # backend-production, migrasi ini soal kode dulu, bukan skema.
│                                  # Folder ini dibuat kalau porting nanti butuh skema baru/beda.
├── public/
│   ├── index.php             # front controller
│   └── uploads/{trips,sj,drivers}/{id}/  # foto checkpoint/SJ/dokumen supir, disajikan langsung sbg file statis
└── src/
    ├── bootstrap.php          # composition root: bangun Slim App, middleware global (CORS,
    │                            # error handler), lalu mount routes.php tiap modul. TIDAK berisi
    │                            # route table -- itu tanggung jawab tiap modul sendiri.
    ├── Database.php            # wrapper koneksi PDO (singleton) -- SHARED semua modul
    ├── Support/
    │   ├── Jwt.php               # terbitkan & verifikasi token HS256 -- SHARED semua modul
    │   └── PhotoStorage.php      # simpan foto upload -> WEBP (GD, fallback apa adanya) -- SHARED
    ├── Middleware/
    │   └── AuthMiddleware.php    # cek Authorization: Bearer <token>, taruh user_id/role di request -- SHARED
    ├── Controllers/
    │   └── Controller.php        # helper json()/error() dipakai semua controller -- SHARED
    ├── Support/
    │   └── DocumentNumber.php     # next()/syncToAtLeast() thd cfg_m_doc_number -- SHARED (company-wide,
    │                               # bukan spesifik 1 modul), dipakai Inventory, bisa dipakai modul lain nanti
    ├── Ekspedisi/                 # MODUL -- lihat "Modul" di atas, path/behavior TIDAK berubah
    │   ├── routes.php               # route table modul ini (dipindah dari bootstrap.php lama)
    │   ├── Support/
    │   │   ├── SupirProfile.php       # ambil/buat baris ekspedisi_m_supir (dipakai Auth & DriverController)
    │   │   ├── TripPresenter.php      # format baris ekspedisi_t_trip -> shape JSON, konstanta STEPS
    │   │   ├── SuratJalanLookup.php   # query READ-ONLY ke surat_jalan (integrasi, lihat bagian di atas)
    │   │   ├── SpkReadyKirim.php      # query READ-ONLY ke t_penjualan_header -- SPK ready-kirim yg belum ada SJ
    │   │   ├── Ekspedisi.php          # CRUD ekspedisi_m_ekspedisi -- master perusahaan ekspedisi eksternal, MILIK modul ini
    │   │   ├── PengajuanBiaya.php     # create/list ekspedisi_t_pengajuan_biaya
    │   │   ├── PenjualanItemLookup.php # query READ-ONLY ke t_penjualan_detail_performa -- sisa qty per lini produk SPK
    │   │   └── SuratJalan.php         # CRUD ekspedisi_t_surat_jalan (+ item breakdown) MILIK modul ini
    │   ├── Middleware/
    │   │   └── AdminOnlyMiddleware.php  # tolak 403 kalau role token bukan 'admin'
    │   └── Controllers/
    │       ├── AuthController.php  # login, logout (isAdmin() query ekspedisi_m_admin_access -- MILIK modul ini, bukan generic)
    │       ├── DriverController.php  # /driver/* (nama class dipertahankan -- soal "supir", bukan bagian rename ekspedisi_*)
    │       ├── AdminController.php   # /admin/*
    │       ├── SuratJalanController.php # /admin/sj* (modul surat jalan MILIK modul ini)
    │       ├── EkspedisiController.php # /admin/ekspedisi* (master perusahaan ekspedisi eksternal MILIK modul ini)
    │       └── ConfigController.php   # /config/check-version, CONFIG_ID='VERSION_EKSPEDISI_PUSAT' (MILIK modul ini)
    └── Inventory/                  # MODUL BARU -- lihat "Modul" di atas utk status per-endpoint
        ├── routes.php                # /inventory/login, /config/check-version (publik) + grup authed:
        │                               # /inventory/ping, /material/*, /opname/*
        ├── Support/
        │   ├── ApiEnvelope.php        # trait apiSuccess()/apiError()/apiNotFound() -- shape {status,message,data}
        │   │                           # spt kontrak Laravel lama, BEDA dari Controller::json()/error() milik Ekspedisi
        │   └── StockPosting.php       # postIn()/postOut() -- WAC avg-cost, ledger wh_log_stock_mutation,
        │                               # SELALU dijalankan di dalam transaksi PDO yang sama dgn caller
        └── Controllers/
            ├── AuthController.php     # POST /inventory/login -- gate divisi Gudang, role dari jabatan
            ├── ConfigController.php   # CONFIG_ID='VERSION_INVENTORY_PUSAT' (MILIK modul ini)
            ├── MaterialController.php # master barang: list/search/filter, CRUD, upload foto, doc-numbering
            └── OpnameController.php   # stock take: sesi/scan/submit/approve/reject, posting via StockPosting
```

## Performa `GET /admin/sj` -- N+1 dibatch (2026-08-20)

Tab "SJ" FE sempat kerasa SANGAT lambat pas load (dilaporkan user, diukur langsung ke produksi).
Root cause: `SuratJalan::list()` dulu manggil `items()` + `resolveClientNames()` per BARIS di
dalam loop -- 1 halaman (20 baris) = sampai ~41 query (1 COUNT + 1 SELECT list utama + 20x items +
20x client_names, N+1 klasik). Query individualnya sendiri CEPAT (ada index di kolom yang relevan,
tabelnya juga cuma ~1500 baris) -- tapi `DB_HOST` (`indokoper.com`) beda host dari backend, jadi
tiap query bayar round-trip jaringan (~40-50ms terukur). 41 query x ~45ms = detik-an, padahal kerja
DB-nya sendiri cuma puluhan ms.

Fix: `items()`+`resolveClientNames()` per baris diganti `batchItemsByRowId()`+
`batchClientNamesByPenjualanId()` -- MASING-MASING cuma 1 query (`WHERE ... IN (...)`) buat SEMUA
baris di halaman sekaligus, dikelompokkan di PHP setelahnya (bukan di SQL). Total round-trip per
`list()` turun dari ~1+1+2N jadi TETAP 4 apa pun ukuran halamannya. Diukur langsung ke produksi
(2026-08-20, `tahun=2026`, 20 baris): **~1831ms -> ~186ms** (~10x). Diverifikasi hasilnya IDENTIK
dgn sebelum dibatch (dibandingkan baris-per-baris lewat `items()` yang lama).

`items()`/`resolveClientNames()` (versi single-row, TIDAK di-N+1-kan) dipertahankan apa adanya --
masih dipakai `find()` (`GET /admin/sj/{id}`, cuma 1 baris, N+1 tidak relevan di sana).

**Kalau nanti masih kerasa lambat lagi setelah data jauh lebih besar** (skrng ~1500 baris, index
`status`/kolom lain di query WHERE list() belum ada krn di titik ini overhead-nya didominasi
round-trip jaringan, bukan biaya query DB-nya -- nambah index sekarang dampaknya nyaris tidak
kerasa) -- pertimbangkan nambah index ke `ekspedisi_t_surat_jalan.status` dan
`ekspedisi_t_surat_jalan(tgl_kirim, created_at)` KALAU EXPLAIN nunjukkin full table scan jadi
mahal. BUKAN prioritas sekarang -- N+1 di atas adalah 95%+ dari masalahnya.

## Filter tahun `GET /admin/sj` (2026-08-20)

`SuratJalan::list()` terima query param `tahun` opsional -- filter `YEAR(COALESCE(sj.tgl_kirim,
sj.created_at)) = :tahun` (fallback ke `created_at` krn `tgl_kirim` nullable, supaya baris tanpa
tanggal kirim tetap kena tepat 1 tahun, bukan hilang dari semua filter). `GET /admin/sj/years`
(`SuratJalan::availableYears()`) balikin daftar tahun yang BENERAN ADA di data (`SELECT DISTINCT
YEAR(...)`, bukan range hardcode) -- dipakai isi dropdown filter tahun di FE (`ekspedisi-apk`,
tab "SJ", sejajar kotak cari). Route ini WAJIB didaftarkan di `bootstrap.php` SEBELUM `GET
/admin/sj/{id}` (segmen path sama-sama 1 kata, kalau kebalik "years" ketangkep sbg `{id}`).

## Cek versi app (2026-08-20)

Pola yang SAMA dipakai app lain di workspace ini (`absensi-apk`, `finance-apk`, `admin-finance-apk`,
dst) — tabel `config` (key-value generik, PK `config_id`) di database PRODUKSI YANG SAMA
(`backend-production`, lihat `db_dump.sql`), dibaca READ-ONLY dari sini, TIDAK PERNAH ditulis oleh
app ini. `App\Controllers\ConfigController::checkVersion()` cari baris `config_id =
'VERSION_EKSPEDISI_PUSAT'` — konvensi penamaan `VERSION_<NAMA_APP>[_PUSAT]` sama persis dgn
`VERSION_ABSEN_PUSAT`/`VERSION_FINANCE_PUSAT`/dst yang sudah ada.

**Ikut konvensi TERBARU** (dipakai `API\Config\VersionController` di `backend-production`, yang
dipanggil `finance-apk`/`admin-finance-apk` — BUKAN pola lama `AbsenController::checkInternetAbsen()`
yang exact-match string & digabung sama urusan lain kayak ijin/last_login/password): frontend
kirim `current_version_code` **integer** (Android versionCode, naik 1 tiap rilis — BUKAN version
string "1.0.0"), backend bandingkan `>=` ke `config_value_minimal`. Lebih fleksibel drpd exact-match
— admin bisa naikkan syarat minimal tanpa perlu tahu persis versi apa saja yang beredar di device
masing-masing supir/admin.

**Skema `config`** (tabel shared, BUKAN dibuat/dikelola dari `database/` app ini — sudah ada &
dipakai banyak app lain, cukup di-INSERT 1 baris baru): `config_id` (PK, varchar), `config_keterangan`
(pesan yang ditampilkan ke user saat versi tidak valid), `config_value_minimal`/`config_value_maksimal`/
`config_value` (decimal(25,5), cuma `config_value_minimal` yang dipakai controller ini),
`config_value_string` (versi human-readable, TIDAK dipakai perbandingan, cuma informasi). **Baris
`VERSION_EKSPEDISI_PUSAT` WAJIB di-seed manual dulu** (tidak ada di `database/`, krn ini nulis ke
tabel MILIK backend-production, bukan skema app ini):
```sql
INSERT INTO config (config_id, config_keterangan, config_value_minimal, config_value_maksimal, config_value, config_value_string)
VALUES ('VERSION_EKSPEDISI_PUSAT', 'Aplikasi Versi Lama, Hubungi Divisi IT ', 1, 1, 1, '1.0.0');
```
**Sudah dites langsung ke database produksi (2026-08-20)** — baris ini SUDAH ADA & endpoint
dites live: `current_version_code: 1` → `is_valid: true` (cocok, `android-versionCode` awal
`ekspedisi-apk` juga 1); `current_version_code: 0` → `is_valid: false` dgn pesan
`config_keterangan` yang benar.

**Fail-open** kalau baris belum ada sama sekali (`config: null`, `is_valid: true`) — supaya admin
yang lupa seed tidak sampai mengunci SEMUA orang keluar app. Endpoint **publik** (di luar
`AuthMiddleware`, sama seperti `/login`) — app perlu bisa cek versi walau token sudah
expired/belum pernah login sama sekali.

Sisi frontend (`ekspedisi-apk`): `src/js/versionCheck.js` polling tiap 30 detik (pola sama dgn
`checkAppVersion()` sibling apps), `src/js/app-version.js` (auto-generate `bump-version.cjs`,
`npm run version:patch/minor/major/custom`) yang nyimpen `CURRENT_APP_VERSION_CODE` dikirim ke
endpoint ini. Detail lengkap di README `ekspedisi-apk`.

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
