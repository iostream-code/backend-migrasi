-- Ganti kolom pengirim -> penerima di ekspedisi_t_surat_jalan (langkah 8 dari
-- database/).
--
-- Konteks (2026-08-20): "pengirim" (nama org yang serah-terima barang, dulu
-- dicontek dari surat_jalan.pengirim lama) ternyata tumpang tindih sama
-- konsep supir (ekspedisi_m_supir) yang sudah ada -- SJ ini selalu punya
-- supir, jadi "siapa yang mengirim" sudah terjawab lewat driver_id. Field
-- yang justru berguna: nama PENERIMA/PIC di tujuan, supaya supir tahu siapa
-- yang harus dihubungi/diserahi barang begitu sampai. Kolom di-RENAME (bukan
-- drop+create baru) krn semantiknya cuma digeser, bukan dihapus.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/08_rename_pengirim_ke_penerima.sql

ALTER TABLE `ekspedisi_t_surat_jalan`
  CHANGE COLUMN `pengirim` `penerima` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nama penerima/PIC di tujuan -- opsional, bantu supir tahu siapa yang harus dihubungi/diserahi barang';
