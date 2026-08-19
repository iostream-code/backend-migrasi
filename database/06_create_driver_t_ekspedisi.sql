-- Tabel baru (langkah 6 dari database/): driver_t_ekspedisi -- pencatatan
-- pengiriman SPK yang diserahkan ke EKSPEDISI LUAR (pihak ketiga), paralel
-- dengan driver_t_trip (pengiriman pakai supir internal). Satu SPK dari
-- SpkReadyKirim ujungnya akan punya baris di driver_t_trip ATAU di sini,
-- tidak dua-duanya (dicegah di level query/aplikasi, bukan constraint DB --
-- pola sama seperti driver_t_trip.penjualan_id, lihat 05_...sql).
--
-- Tautan LOGIS (bukan FOREIGN KEY sungguhan) ke tabel backend-production:
--   - penjualan_id  -> t_penjualan_header.penjualan_id (SPK yang dikirim)
--   - id_expedisi   -> m_expedisi.id_expedisi (perusahaan ekspedisi)
-- Sengaja BUKAN FK asli meski dua-duanya kolom unik di tabel asal -- FK asli
-- lintas skema begini bikin backend-production kena constraint dari sini
-- tiap mau DELETE baris yang ternyata direferensikan (mis. hapus perusahaan
-- ekspedisi lama), padahal tim backend-production tidak tahu app ini ada.
-- nama_expedisi disimpan sebagai SNAPSHOT (bukan cuma id_expedisi) supaya
-- histori tetap benar walau nama di m_expedisi diedit belakangan.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/06_create_driver_t_ekspedisi.sql

CREATE TABLE `driver_t_ekspedisi` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `penjualan_id` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tautan logis ke t_penjualan_header.penjualan_id',
  `id_expedisi` int NOT NULL COMMENT 'Tautan logis ke m_expedisi.id_expedisi',
  `nama_expedisi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Snapshot m_expedisi.nama_expedisi saat diserahkan -- bukan selalu di-JOIN ulang',
  `no_resi` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Diisi belakangan begitu ekspedisi kasih nomor resi',
  `biaya_kirim` decimal(15,2) DEFAULT NULL,
  `status` enum('dijadwalkan','dikirim','sampai','batal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dijadwalkan',
  `catatan` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tgl_kirim` datetime DEFAULT NULL COMMENT 'Tanggal barang benar-benar diserahkan ke ekspedisi',
  `tgl_sampai_estimasi` datetime DEFAULT NULL,
  `tgl_sampai` datetime DEFAULT NULL COMMENT 'Tanggal konfirmasi barang sampai (diisi manual admin)',
  `created_by` int DEFAULT NULL COMMENT 'user_id (shared_m_users) admin yang menyerahkan ke ekspedisi',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `driver_t_ekspedisi_penjualan_id_index` (`penjualan_id`),
  KEY `driver_t_ekspedisi_id_expedisi_index` (`id_expedisi`),
  KEY `driver_t_ekspedisi_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
