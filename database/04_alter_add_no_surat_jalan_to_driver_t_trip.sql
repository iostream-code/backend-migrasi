-- ALTER satu-kali (langkah 4 dari database/): tambah kolom no_surat_jalan ke
-- driver_t_trip. WAJIB dijalankan setelah 01_schema.sql -- 01 SENGAJA tidak
-- menyertakan kolom ini (schema aslinya sebelum integrasi surat_jalan dibuat),
-- jadi baik instalasi baru maupun lama sama-sama butuh menjalankan file ini.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/04_alter_add_no_surat_jalan_to_driver_t_trip.sql
--
-- Tautan LOGIS (bukan FOREIGN KEY sungguhan) ke surat_jalan.no_surat_jalan
-- (tabel lama milik backend-production, TIDAK ikut diubah apa pun di sini).
-- Sengaja bukan FK asli krn no_surat_jalan BUKAN kolom unik di surat_jalan
-- (1 no SJ = banyak baris, 1 baris per item produk).

ALTER TABLE driver_t_trip
  ADD COLUMN no_surat_jalan VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    AFTER destination,
  ADD INDEX driver_t_trip_no_surat_jalan_index (no_surat_jalan);
