-- Tabel baru (langkah 4 dari database/): ekspedisi_t_surat_jalan --
-- modul surat jalan MILIK app ini sendiri, independen dari tabel `surat_jalan`
-- lama milik backend-production (yang masih aktif dipakai surat-jalan-apk/
-- produksi-apk/finance-apk -- SENGAJA tidak disentuh/ditulisi sama sekali).
--
-- Dua jalur pengisian (lihat App\Support\SuratJalan & Controllers\SuratJalanController):
--   1. Otomatis: checkpoint foto "sj" yang diupload supir di akhir trip
--      (POST /driver/trip/{trip}/photo, type=sj) upsert 1 baris di sini,
--      tertaut ke trip_id itu.
--   2. Manual: admin bikin/lengkapi SJ langsung dari layar admin (POST/PUT
--      /admin/sj), trip_id boleh NULL kalau tidak terkait trip manapun.
--
-- FK ke ekspedisi_t_trip & ekspedisi_m_supir itu FK ASLI (tabel milik app ini
-- sendiri). penjualan_id tautan LOGIS (bukan FK asli) ke
-- t_penjualan_header.penjualan_id milik backend-production, sama seperti
-- ekspedisi_t_trip.penjualan_id.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/04_create_ekspedisi_t_surat_jalan.sql

CREATE TABLE `ekspedisi_t_surat_jalan` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `no_surat_jalan` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Auto-generated setelah insert (format SJ-YYYYMMDD-xxxx), lihat App\\Support\\SuratJalan::create()',
  `trip_id` bigint unsigned DEFAULT NULL COMMENT 'FK ke ekspedisi_t_trip.id -- NULL kalau SJ dibuat manual admin tanpa trip',
  `penjualan_id` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tautan logis opsional ke t_penjualan_header.penjualan_id (backend-production)',
  `driver_id` bigint unsigned DEFAULT NULL COMMENT 'FK ke ekspedisi_m_supir.id',
  `tujuan` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kendaraan` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plat` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jumlah_kirim` int DEFAULT NULL,
  `foto_surat_jalan` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'path relatif di public/uploads, pola sama seperti ekspedisi_t_trip_photo',
  `catatan` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','terkirim') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft = belum ada foto, terkirim = foto_surat_jalan sudah terisi',
  `created_by` int DEFAULT NULL COMMENT 'user_id (shared_m_users) admin yang bikin manual -- NULL kalau auto-created dari checkpoint supir',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ekspedisi_t_surat_jalan_no_surat_jalan_unique` (`no_surat_jalan`),
  -- Satu trip cuma boleh py 1 SJ (upsert by trip_id di jalur checkpoint foto) --
  -- NULL (SJ manual tanpa trip) boleh berulang, MySQL unique key izinkan banyak NULL.
  UNIQUE KEY `ekspedisi_t_surat_jalan_trip_id_unique` (`trip_id`),
  KEY `ekspedisi_t_surat_jalan_penjualan_id_index` (`penjualan_id`),
  KEY `ekspedisi_t_surat_jalan_driver_id_index` (`driver_id`),
  CONSTRAINT `fk_ekspedisi_t_surat_jalan_trip` FOREIGN KEY (`trip_id`) REFERENCES `ekspedisi_t_trip` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ekspedisi_t_surat_jalan_driver` FOREIGN KEY (`driver_id`) REFERENCES `ekspedisi_m_supir` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
