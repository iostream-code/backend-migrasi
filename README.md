# driver-apk-backend

Backend khusus untuk [`driver-apk`](../driver-apk) (Cordova, tracking supir + monitor
admin). **Slim 4, tanpa ORM, tanpa migration** — PDO + SQL mentah langsung, skema tabel
didefinisikan di [`schema.sql`](schema.sql) dan dijalankan manual sekali. Project **terpisah**
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

- **Tanpa migration**: [`schema.sql`](schema.sql) berisi `CREATE TABLE` mentah, ditulis
  mengikuti gaya persis tabel-tabel baru di `db_dump.sql` (`ENGINE=InnoDB`,
  `utf8mb4`/`utf8mb4_unicode_ci`, PK `id bigint unsigned AUTO_INCREMENT`, FK eksplisit —
  termasuk FK ke `shared_m_users` karena sekarang satu database yang sama). Jalankan manual:
  `mysql -u <user> -p <database> < schema.sql`. Tidak ada `php artisan migrate` atau sejenisnya.
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
  otomatis diperlakukan sebagai supir (profil `driver_m_supir` dibuat otomatis saat pertama
  login — lihat `App\Support\SupirProfile::ensure()`).
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
mysql -u <username> -p <database> < schema.sql   # bikin 5 tabel driver_* baru (TIDAK menyentuh tabel lain)
php -S 127.0.0.1:8000 -t public                   # dev server
```

Lalu **seed minimal 1 admin** — tanpa ini tidak ada seorang pun yang bisa login sebagai
admin/dispatcher (`driver_m_admin_access` mulai kosong). Edit dulu daftar `username` di
[`seed_admin_access.sql`](seed_admin_access.sql), lalu:

```bash
mysql -u <username> -p <database> < seed_admin_access.sql
```

Cari berdasarkan `username` (bukan `user_id` mentah) supaya tidak perlu lihat-lihat ID manual,
dan aman dijalankan berkali-kali (idempotent) kalau nanti mau nambah admin lagi. Query
terakhir di skrip itu langsung menampilkan siapa saja yang berhasil ke-seed, untuk verifikasi.

**Belum pernah dites terhadap MySQL beneran** — sandbox tempat project ini dibuat tidak punya
ekstensi `pdo_mysql` terpasang. Sudah divalidasi lewat: `php -l` di semua file, dan smoke test
end-to-end pakai dev server (`php -S`) untuk jalur yang tidak butuh DB — routing, parsing body
JSON, terbit & verifikasi token JWT, gerbang role admin (403 utk token role `driver`, lolos ke
titik query DB utk token role `admin`), CORS preflight. Jalankan `schema.sql` + coba
`POST /login` sungguhan di environment dev Anda sebelum dianggap final.

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
| GET | `/admin/drivers/{driver}` | token + admin | `{ id, name, phone, status, trips: [...] }` |
| POST | `/admin/drivers/{driver}/trip` | token + admin | `{ destination }` |

`{driver}`/`{trip}` di URL adalah **id `driver_m_supir`/`driver_t_trip`** (bukan `user_id`
`shared_m_users`).

## Struktur

```
driver-apk-backend/
├── schema.sql              # CREATE TABLE mentah -- jalankan manual, bukan migration
├── seed_admin_access.sql    # seed whitelist admin/dispatcher (cari by username, idempotent)
├── public/
│   ├── index.php             # front controller
│   └── uploads/trips/{id}/   # foto checkpoint, disajikan langsung sbg file statis
└── src/
    ├── bootstrap.php          # bangun Slim App: middleware, CORS, error handler, routes
    ├── Database.php            # wrapper koneksi PDO (singleton)
    ├── Support/
    │   ├── Jwt.php               # terbitkan & verifikasi token HS256
    │   ├── SupirProfile.php       # ambil/buat baris driver_m_supir (dipakai Auth & DriverController)
    │   └── TripPresenter.php      # format baris driver_t_trip -> shape JSON, konstanta STEPS
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
