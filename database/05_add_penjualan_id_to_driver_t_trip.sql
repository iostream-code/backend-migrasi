-- ALTER satu-kali (langkah 5 dari database/): tambah kolom penjualan_id ke
-- driver_t_trip -- tautan LOGIS (bukan FOREIGN KEY sungguhan) ke
-- t_penjualan_header.penjualan_id (tabel lama milik backend-production,
-- TIDAK ikut diubah apa pun di sini).
--
-- Beda dari no_surat_jalan (04_...): penjualan_id dipakai buat menautkan trip
-- ke SPK SEBELUM surat_jalan-nya ada -- alur aslinya: order disetujui utk
-- dikirim (t_penjualan_header.shipment_status='approved', lihat
-- App\Support\SpkReadyKirim) -> admin plotting driver di sini (penjualan_id
-- terisi) -> baru belakangan (kadang beda hari, beda app) SJ fisik dibuat
-- (no_surat_jalan baru terisi kalau admin tautkan manual).
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/05_add_penjualan_id_to_driver_t_trip.sql

ALTER TABLE driver_t_trip
  ADD COLUMN penjualan_id VARCHAR(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    AFTER no_surat_jalan,
  ADD INDEX driver_t_trip_penjualan_id_index (penjualan_id);
