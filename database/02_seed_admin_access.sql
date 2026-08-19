-- Seed whitelist admin/dispatcher untuk driver-apk-backend (tabel driver_m_admin_access).
-- Tanpa ini tidak ada seorang pun yang bisa login sebagai admin di driver-apk --
-- semua akun shared_m_users otomatis diperlakukan sebagai supir kalau tidak ada
-- baris untuk user_id-nya di sini.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/02_seed_admin_access.sql
-- Aman dijalankan berkali-kali (idempotent) -- username yang sudah terdaftar
-- dilewati (ON DUPLICATE KEY UPDATE no-op), bukan bikin baris duplikat/error.

-- ============================================================
-- EDIT DI SINI: isi username (BUKAN user_id) pegawai yang mau dijadikan
-- admin/dispatcher app tracking supir ini. Harus sama persis dengan kolom
-- `username` di shared_m_users -- tambah baris lagi (dipisah koma) kalau
-- admin-nya lebih dari satu.
-- ============================================================
INSERT INTO driver_m_admin_access (user_id)
SELECT user_id FROM shared_m_users
WHERE username IN (
    'ITAI'
)
-- Nama tabel WAJIB di-qualify di sini -- tanpa itu `user_id` ambigu (ada di
-- driver_m_admin_access maupun di shared_m_users lewat SELECT di atas).
ON DUPLICATE KEY UPDATE driver_m_admin_access.user_id = driver_m_admin_access.user_id;

-- Verifikasi: tampilkan siapa saja yang sekarang jadi admin/dispatcher.
-- Kalau username di atas salah ketik / tidak ada di shared_m_users, baris itu
-- diam-diam TIDAK ikut ter-insert (INSERT...SELECT skip, bukan error) -- cocokkan
-- jumlah baris hasil query ini dengan jumlah username yang Anda isi di atas.
SELECT a.id, a.user_id, u.username, u.nama_lengkap, u.status_pegawai
FROM driver_m_admin_access a
JOIN shared_m_users u ON u.user_id = a.user_id
ORDER BY a.id;
