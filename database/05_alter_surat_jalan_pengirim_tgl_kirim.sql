-- Tambah kolom pengirim & tgl_kirim ke ekspedisi_t_surat_jalan (langkah 5 dari
-- database/) -- hasil review perbandingan dengan alur input SJ asli di
-- surat-jalan-apk (POST /surat-jalan-proses): field itu ada di sana
-- (`pengirim` -- nama org yang serah-terima barang, terpisah dari nama supir;
-- `tgl_kirim_penjualan_id` -- tanggal kirim yang bisa beda dari tanggal SJ
-- dibuat) tapi belum ada di skema modul SJ milik app ini.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/05_alter_surat_jalan_pengirim_tgl_kirim.sql

ALTER TABLE `ekspedisi_t_surat_jalan`
  ADD COLUMN `pengirim` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nama orang yang serah-terima barang -- bisa beda dari nama supir/ekspedisi_m_supir' AFTER `plat`,
  ADD COLUMN `tgl_kirim` date DEFAULT NULL COMMENT 'Tanggal kirim -- bisa beda dari created_at (waktu record dibuat)' AFTER `jumlah_kirim`;
