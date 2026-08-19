-- ekspedisi-apk-backend -- skema tabel domain ekspedisi (langkah 1 dari database/).
--
-- RESET BERSIH (2026-08-19): sebelum ini ada 10 file migration terpisah
-- (create -> alter -> create tabel yg dibatalkan -> drop -> alter lagi ->
-- rename driver_* -> ekspedisi_*) hasil evolusi desain beberapa sesi. Karena
-- data produksi masih nol/dummy semua, tabel lama di-drop manual & migration
-- dikonsolidasi jadi 1 file bersih ini -- riwayat evolusi lengkapnya tetap
-- ada di git log kalau perlu ditelusuri lagi.
--
-- BUKAN migration Laravel/framework apa pun -- jalankan manual sekali:
--   mysql -u <user> -p <database_produksi> < database/01_schema.sql
--   mysql -u <user> -p <database_produksi> < database/02_seed_admin_access.sql
--   mysql -u <user> -p <database_produksi> < database/03_seed_dummy_drivers.sql   # opsional
--
-- Ditulis mengikuti gaya penulisan tabel di db_dump.sql (backend-production):
-- ENGINE=InnoDB, utf8mb4/utf8mb4_unicode_ci, PK `id` bigint unsigned
-- AUTO_INCREMENT, FK eksplisit -- termasuk FK ke shared_m_users karena tabel
-- ini hidup di database PRODUKSI YANG SAMA (bukan database terpisah), cuma
-- dikelola dari codebase ini.
--
-- Tidak menyentuh/mengubah tabel manapun yang sudah ada (shared_m_users, hrm_*, dst).

CREATE TABLE `ekspedisi_m_supir` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  -- 'internal' = pegawai (akun shared_m_users, user_id terisi). 'eksternal' =
  -- bukan pegawai (freelance/lepas atau bekerja utk perusahaan ekspedisi
  -- tertentu) -- user_id NULL, tidak bisa login ke app ini sama sekali,
  -- murni catatan dispatch buat admin.
  `tipe` enum('internal','eksternal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal',
  `user_id` int DEFAULT NULL COMMENT 'FK ke shared_m_users.user_id -- NULL kalau tipe=eksternal',
  `nama_eksternal` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nama supir eksternal -- diisi kalau tipe=eksternal (tidak ada shared_m_users utk di-JOIN)',
  `telepon_eksternal` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_expedisi` int DEFAULT NULL COMMENT 'Tautan logis opsional ke m_expedisi.id_expedisi -- NULL kalau supir eksternal lepas/independen',
  `driver_status` enum('online','resting','offline') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `last_lat` decimal(10,7) DEFAULT NULL,
  `last_lng` decimal(10,7) DEFAULT NULL,
  `last_ping_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ekspedisi_m_supir_user_id_unique` (`user_id`),
  KEY `ekspedisi_m_supir_tipe_index` (`tipe`),
  KEY `ekspedisi_m_supir_id_expedisi_index` (`id_expedisi`),
  CONSTRAINT `fk_ekspedisi_m_supir_user` FOREIGN KEY (`user_id`) REFERENCES `shared_m_users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ekspedisi_m_admin_access` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  -- Whitelist: ADA baris utk user_id ini -> admin/dispatcher. Tidak ada -> supir.
  `user_id` int NOT NULL COMMENT 'FK ke shared_m_users.user_id',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ekspedisi_m_admin_access_user_id_unique` (`user_id`),
  CONSTRAINT `fk_ekspedisi_m_admin_access_user` FOREIGN KEY (`user_id`) REFERENCES `shared_m_users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ekspedisi_t_trip` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `driver_id` bigint unsigned NOT NULL COMMENT 'FK ke ekspedisi_m_supir.id (internal maupun eksternal)',
  `destination` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  -- Tautan LOGIS (bukan FK asli) ke surat_jalan.no_surat_jalan (tabel lama
  -- milik backend-production) -- biasanya baru terisi SETELAH SJ fisik
  -- dibuat. Bukan FK asli krn no_surat_jalan bukan kolom unik di surat_jalan
  -- (1 no SJ = banyak baris, 1 baris per item produk).
  `no_surat_jalan` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  -- Tautan LOGIS (bukan FK asli) ke t_penjualan_header.penjualan_id (SPK) --
  -- dipakai plotting SEBELUM SJ fisiknya ada (lihat SpkReadyKirim).
  `penjualan_id` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('in_progress','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ekspedisi_t_trip_driver_id_status_index` (`driver_id`,`status`),
  KEY `ekspedisi_t_trip_no_surat_jalan_index` (`no_surat_jalan`),
  KEY `ekspedisi_t_trip_penjualan_id_index` (`penjualan_id`),
  CONSTRAINT `fk_ekspedisi_t_trip_driver` FOREIGN KEY (`driver_id`) REFERENCES `ekspedisi_m_supir` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ekspedisi_t_trip_photo` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint unsigned NOT NULL COMMENT 'FK ke ekspedisi_t_trip.id',
  -- Sinkron dgn Trip::STEPS / TripPresenter::STEPS di kode PHP.
  `type` enum('berangkat','serah_terima','sj') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'path relatif di public/uploads, bukan URL penuh',
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Satu checkpoint cuma boleh py 1 foto aktif per trip -- upload ulang
  -- checkpoint yg sama pakai updateOrCreate() di controller, bukan insert baru.
  UNIQUE KEY `ekspedisi_t_trip_photo_trip_id_type_unique` (`trip_id`,`type`),
  CONSTRAINT `fk_ekspedisi_t_trip_photo_trip` FOREIGN KEY (`trip_id`) REFERENCES `ekspedisi_t_trip` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ekspedisi_t_location` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `driver_id` bigint unsigned NOT NULL COMMENT 'FK ke ekspedisi_m_supir.id',
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `speed` decimal(8,2) DEFAULT NULL,
  `heading` decimal(8,2) DEFAULT NULL,
  `accuracy` decimal(8,2) DEFAULT NULL,
  `recorded_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ekspedisi_t_location_driver_id_recorded_at_index` (`driver_id`,`recorded_at`),
  CONSTRAINT `fk_ekspedisi_t_location_driver` FOREIGN KEY (`driver_id`) REFERENCES `ekspedisi_m_supir` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ekspedisi_t_pengajuan_biaya` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint unsigned NOT NULL COMMENT 'FK ke ekspedisi_t_trip.id',
  `nominal_diajukan` decimal(15,2) NOT NULL COMMENT 'Input manual admin, bukan hasil hitungan sistem',
  `keterangan` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Alasan/rincian dari admin',
  `status` enum('diajukan','disetujui','ditolak') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'diajukan',
  `nominal_disetujui` decimal(15,2) DEFAULT NULL COMMENT 'Diisi finance saat approve -- boleh beda dari nominal_diajukan (approve sebagian)',
  `catatan_finance` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disetujui_oleh` int DEFAULT NULL COMMENT 'user_id (shared_m_users) yang approve/reject',
  `disetujui_at` datetime DEFAULT NULL,
  `created_by` int NOT NULL COMMENT 'user_id (shared_m_users) admin yang mengajukan',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ekspedisi_t_pengajuan_biaya_trip_id_index` (`trip_id`),
  KEY `ekspedisi_t_pengajuan_biaya_status_index` (`status`),
  CONSTRAINT `fk_ekspedisi_t_pengajuan_biaya_trip` FOREIGN KEY (`trip_id`) REFERENCES `ekspedisi_t_trip` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
