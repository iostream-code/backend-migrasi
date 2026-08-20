-- Tambah kolom asal ke ekspedisi_t_surat_jalan (langkah 9 dari database/) --
-- WAJIB dijalankan SEBELUM migrate_legacy_surat_jalan.php.
--
-- Kenapa perlu ini: PenjualanItemLookup menghitung sisa qty dari DUA sumber
-- -- surat_jalan (tabel LAMA backend-production, dibaca langsung) DAN
-- ekspedisi_t_surat_jalan_item (tabel app ini). Kalau data historis dari
-- surat_jalan lama di-migrasi (disalin) ke ekspedisi_t_surat_jalan_item,
-- baris yang sama kehitung DUA KALI (sekali dari sisi surat_jalan asalnya,
-- sekali lagi dari sisi salinannya) -- sisa qty jadi lebih kecil dari
-- seharusnya utk SPK manapun yang overlap.
--
-- Solusinya: tandai baris hasil migrasi dgn asal='migrasi_legacy', lalu
-- PenjualanItemLookup MENGECUALIKAN baris ber-asal itu dari sisi
-- ekspedisi_t_surat_jalan_item saat menjumlahkan (krn sisi surat_jalan
-- sudah cukup mewakilinya). Baris native (dibuat dari app ini, manual
-- maupun checkpoint supir) tidak terpengaruh sama sekali.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/09_tambah_kolom_asal_surat_jalan.sql

ALTER TABLE `ekspedisi_t_surat_jalan`
  ADD COLUMN `asal` enum('native','migrasi_legacy') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'native' COMMENT 'native = dibuat dari app ini (manual atau checkpoint supir); migrasi_legacy = hasil migrasi data historis surat_jalan lama, lihat migrate_legacy_surat_jalan.php' AFTER `status`;
