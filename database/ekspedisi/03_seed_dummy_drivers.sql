-- Seed profil supir dummy (tabel ekspedisi_m_supir) untuk ekspedisi-apk-backend.
--
-- Biasanya TIDAK perlu dijalankan manual -- baris ekspedisi_m_supir otomatis
-- dibuat sendiri begitu pegawai pertama kali login lewat app (lihat
-- App\Support\SupirProfile::ensure()), atau lewat fitur "Tambah Supir" di
-- tampilan Admin (POST /admin/drivers, internal maupun eksternal). Skrip ini
-- cuma untuk pre-provision beberapa supir INTERNAL dummy sebelum ada yang
-- benar-benar login/ditambahkan lewat app -- mis. buat demo/testing map &
-- daftar supir dulu. (Supir eksternal tidak bisa diseed dari sini -- tidak
-- py akun shared_m_users, tambahkan lewat POST /admin/drivers langsung.)
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/03_seed_dummy_drivers.sql
-- Aman dijalankan berkali-kali (idempotent) -- username yang sudah py profil
-- supir dilewati (ON DUPLICATE KEY UPDATE no-op), bukan bikin baris duplikat/error.

-- ============================================================
-- EDIT DI SINI: isi username (BUKAN user_id) pegawai yang mau di-provision
-- sebagai supir dummy. Harus sama persis dengan kolom `username` di
-- shared_m_users -- tambah baris lagi (dipisah koma) kalau lebih dari satu.
-- ============================================================
INSERT INTO ekspedisi_m_supir (user_id, driver_status)
SELECT user_id, 'offline' FROM shared_m_users
WHERE username IN (
    'TstItai',
    'TstArya',
    'TstCrysna'
)
-- Nama tabel WAJIB di-qualify di sini (pelajaran dari 02_seed_admin_access.sql) --
-- tanpa itu `user_id` ambigu (ada di ekspedisi_m_supir maupun di shared_m_users
-- lewat SELECT di atas).
ON DUPLICATE KEY UPDATE ekspedisi_m_supir.user_id = ekspedisi_m_supir.user_id;

-- Verifikasi: tampilkan semua profil supir internal yang ada sekarang (dummy +
-- yang sudah pernah login/ditambahkan sungguhan). Kalau username di atas salah
-- ketik / tidak ada di shared_m_users, baris itu diam-diam TIDAK ikut
-- ter-insert (INSERT...SELECT skip, bukan error).
SELECT s.id, s.user_id, u.username, u.nama_lengkap, s.driver_status
FROM ekspedisi_m_supir s
JOIN shared_m_users u ON u.user_id = s.user_id
ORDER BY s.id;
