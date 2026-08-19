-- Tabel baru (langkah 7 dari database/): ekspedisi_t_surat_jalan_item --
-- breakdown per-produk dari 1 SJ, MILIK app ini sendiri.
--
-- Konteks (2026-08-20): ditelusuri ulang alur input SJ asli di surat-jalan-apk
-- (`ServiceController::suratJalanProses()` di backend-production) -- SJ di
-- sana SELALU melekat ke SPK (`t_penjualan_header`) lewat 1 hop:
-- `surat_jalan.penjualan_detail_performa_id -> t_penjualan_detail_performa.penjualan_id`.
-- Tidak ada kolom penjualan_id langsung di `surat_jalan` -- linknya emang
-- per LINI PRODUK (`t_penjualan_detail_performa`), bukan per SPK utuh, karena
-- 1 SPK bisa berisi banyak jenis produk dan tiap lini bisa dikirim di waktu
-- (dan jumlah) berbeda-beda.
--
-- Modul SJ app ini sebelumnya cuma py 1 angka jumlah_kirim flat, tanpa
-- breakdown -- itu memang gap yang disengaja awalnya ("skema sendiri,
-- sesederhana mungkin"), tapi setelah dicek lagi realitas di lapangan SELALU
-- melekat ke SPK, jadi ditutup di sini. TETAP tabel terpisah dari
-- ekspedisi_t_surat_jalan (bukan 1-baris-per-lini seperti `surat_jalan` lama)
-- -- 1 dokumen SJ fisik = 1 baris header + N baris item, lebih match sama
-- bentuk dokumen aslinya (1 SJ bisa antar beberapa produk sekaligus) &
-- gampang tampilkan sebagai 1 kartu di UI.
--
-- penjualan_detail_performa_id tautan LOGIS (bukan FK asli) ke
-- t_penjualan_detail_performa.penjualan_detail_performa_id milik
-- backend-production, pola sama seperti ekspedisi_t_trip.penjualan_id.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/07_create_ekspedisi_t_surat_jalan_item.sql

CREATE TABLE `ekspedisi_t_surat_jalan_item` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `surat_jalan_id` bigint unsigned NOT NULL COMMENT 'FK ke ekspedisi_t_surat_jalan.id',
  `penjualan_detail_performa_id` int NOT NULL COMMENT 'Tautan LOGIS ke t_penjualan_detail_performa.penjualan_detail_performa_id (backend-production) -- 1 lini produk dalam SPK',
  `jumlah_kirim` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ekspedisi_t_surat_jalan_item_surat_jalan_id_index` (`surat_jalan_id`),
  KEY `ekspedisi_t_surat_jalan_item_pdp_id_index` (`penjualan_detail_performa_id`),
  CONSTRAINT `fk_ekspedisi_t_surat_jalan_item_sj` FOREIGN KEY (`surat_jalan_id`) REFERENCES `ekspedisi_t_surat_jalan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
