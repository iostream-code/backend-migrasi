-- DROP satu-kali (langkah 8 dari database/): driver_t_ekspedisi (dibuat di
-- 06_...sql) DIBATALKAN -- desain "ekspedisi luar sebagai tabel terpisah,
-- tidak lewat konsep supir" diganti pendekatan yang lebih sederhana: supir
-- eksternal sekarang jadi salah satu TIPE baris driver_m_supir (lihat
-- 07_alter_driver_m_supir_add_eksternal.sql), jadi jalur plotting SPK yang
-- sudah ada (driver_t_trip) otomatis berlaku juga utk ekspedisi luar --
-- tidak perlu tabel/endpoint terpisah lagi.
--
-- Aman di-drop: tabel ini belum pernah punya baris data sama sekali sejak
-- dibuat, dan belum ada kode/UI manapun yang menulis ke sana.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/08_drop_driver_t_ekspedisi.sql

DROP TABLE IF EXISTS driver_t_ekspedisi;
