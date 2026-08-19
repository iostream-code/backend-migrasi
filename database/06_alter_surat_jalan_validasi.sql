-- Tambah alur validasi ke ekspedisi_t_surat_jalan (langkah 6 dari database/).
--
-- Proses fisik yang dimodelkan (2026-08-19): admin bikin SJ (draft) -> SJ
-- fisik dibawa supir -> barang diterima, SJ ditandatangani penerima -> supir
-- kembalikan SJ fisik yang sudah ditandatangani ke admin -> ADMIN upload foto
-- SJ final itu sekaligus menandai pengiriman tervalidasi. `terkirim` (dari
-- checkpoint foto supir di lapangan, ekspedisi_t_trip_photo type=sj, ATAU
-- foto yang diupload admin saat create manual) TETAP status antara -- itu
-- cuma bukti ada foto/evidence, BUKAN bukti sudah divalidasi. Skema tetap
-- SATU alur "sj" -- foto checkpoint supir & foto validasi admin disimpan di
-- kolom terpisah (foto_surat_jalan vs foto_validasi) supaya tidak saling
-- menimpa kalau keduanya pernah diisi.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/06_alter_surat_jalan_validasi.sql

ALTER TABLE `ekspedisi_t_surat_jalan`
  MODIFY COLUMN `status` enum('draft','terkirim','tervalidasi') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft = belum ada foto, terkirim = ada foto (checkpoint supir/upload admin), tervalidasi = admin sudah upload foto SJ final bertandatangan',
  ADD COLUMN `foto_validasi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Foto SJ fisik final yang sudah ditandatangani penerima -- diupload admin, TERPISAH dari foto_surat_jalan (bukti checkpoint lapangan)' AFTER `foto_surat_jalan`,
  ADD COLUMN `divalidasi_oleh` int DEFAULT NULL COMMENT 'FK ke shared_m_users.user_id -- admin yang melakukan validasi' AFTER `foto_validasi`,
  ADD COLUMN `divalidasi_at` datetime DEFAULT NULL AFTER `divalidasi_oleh`,
  ADD CONSTRAINT `fk_ekspedisi_t_surat_jalan_divalidasi_oleh` FOREIGN KEY (`divalidasi_oleh`) REFERENCES `shared_m_users` (`user_id`);
