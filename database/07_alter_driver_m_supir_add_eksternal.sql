-- ALTER satu-kali (langkah 7 dari database/): driver_m_supir sekarang bisa
-- merepresentasikan supir INTERNAL (pegawai, akun shared_m_users -- pola
-- lama) ATAU supir EKSTERNAL (bukan pegawai -- lepas/independen atau bekerja
-- utk perusahaan ekspedisi tertentu). Satu tabel, satu alur "Tambah Supir",
-- satu dropdown pilih supir di SPK Siap Kirim -- tidak ada lagi tabel
-- terpisah utk ekspedisi (lihat 08_..., yang men-drop driver_t_ekspedisi).
--
-- user_id SEKARANG NULLABLE -- NULL kalau tipe='eksternal' (bukan pegawai,
-- tidak ada baris shared_m_users utk ditautkan, tidak bisa login ke app ini
-- sama sekali -- murni catatan dispatch buat admin).
--
-- id_expedisi: tautan LOGIS (bukan FK asli, alasan sama seperti kolom
-- serupa sebelumnya) ke m_expedisi.id_expedisi -- OPSIONAL, NULL kalau
-- supir eksternal ini lepas/independen (tidak terikat perusahaan manapun).
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/07_alter_driver_m_supir_add_eksternal.sql

ALTER TABLE driver_m_supir
  ADD COLUMN tipe ENUM('internal','eksternal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal'
    AFTER id,
  MODIFY COLUMN user_id INT NULL COMMENT 'FK ke shared_m_users.user_id -- NULL kalau tipe=eksternal',
  ADD COLUMN nama_eksternal VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'Nama supir eksternal -- diisi kalau tipe=eksternal (tidak ada shared_m_users utk di-JOIN)'
    AFTER user_id,
  ADD COLUMN telepon_eksternal VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    AFTER nama_eksternal,
  ADD COLUMN id_expedisi INT DEFAULT NULL
    COMMENT 'Tautan logis opsional ke m_expedisi.id_expedisi -- NULL kalau supir eksternal lepas/independen'
    AFTER telepon_eksternal,
  ADD INDEX driver_m_supir_tipe_index (tipe),
  ADD INDEX driver_m_supir_id_expedisi_index (id_expedisi);
