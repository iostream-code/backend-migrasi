-- driver-apk-backend -- skema tabel domain driver.
--
-- BUKAN migration Laravel/framework apa pun -- jalankan manual sekali:
--   mysql -u <user> -p <database_produksi> < schema.sql
--
-- Ditulis mengikuti gaya penulisan tabel di db_dump.sql (backend-production):
-- ENGINE=InnoDB, utf8mb4/utf8mb4_unicode_ci, PK `id` bigint unsigned
-- AUTO_INCREMENT (pola tabel baru spt hrm_log_payroll), FK eksplisit --
-- termasuk FK ke shared_m_users karena tabel ini hidup di database PRODUKSI
-- YANG SAMA (bukan database terpisah), cuma dikelola dari codebase ini.
--
-- Tidak menyentuh/mengubah tabel manapun yang sudah ada (shared_m_users, hrm_*, dst).

CREATE TABLE `driver_m_supir` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'FK ke shared_m_users.user_id -- 1 baris per pegawai yang pernah login sbg supir',
  `driver_status` enum('online','resting','offline') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `last_lat` decimal(10,7) DEFAULT NULL,
  `last_lng` decimal(10,7) DEFAULT NULL,
  `last_ping_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `driver_m_supir_user_id_unique` (`user_id`),
  CONSTRAINT `fk_driver_m_supir_user` FOREIGN KEY (`user_id`) REFERENCES `shared_m_users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `driver_m_admin_access` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'FK ke shared_m_users.user_id -- ADA baris = admin/dispatcher, TIDAK ada = supir',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `driver_m_admin_access_user_id_unique` (`user_id`),
  CONSTRAINT `fk_driver_m_admin_access_user` FOREIGN KEY (`user_id`) REFERENCES `shared_m_users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `driver_t_trip` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `driver_id` bigint unsigned NOT NULL COMMENT 'FK ke driver_m_supir.id',
  `destination` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  -- Tautan LOGIS (bukan FOREIGN KEY sungguhan) ke surat_jalan.no_surat_jalan
  -- (tabel lama milik backend-production, TIDAK disentuh skema/kodenya --
  -- lihat catatan integrasi di README). Sengaja bukan FK asli krn
  -- no_surat_jalan BUKAN kolom unik di surat_jalan (1 no SJ = banyak baris,
  -- 1 baris per item produk). Nullable -- tidak semua trip harus py SJ resmi.
  `no_surat_jalan` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('in_progress','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `driver_t_trip_driver_id_status_index` (`driver_id`,`status`),
  KEY `driver_t_trip_no_surat_jalan_index` (`no_surat_jalan`),
  CONSTRAINT `fk_driver_t_trip_driver` FOREIGN KEY (`driver_id`) REFERENCES `driver_m_supir` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `driver_t_trip_photo` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint unsigned NOT NULL COMMENT 'FK ke driver_t_trip.id',
  `type` enum('berangkat','serah_terima','sj') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'path relatif di public/uploads, bukan URL penuh',
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `driver_t_trip_photo_trip_id_type_unique` (`trip_id`,`type`),
  CONSTRAINT `fk_driver_t_trip_photo_trip` FOREIGN KEY (`trip_id`) REFERENCES `driver_t_trip` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `driver_t_location` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `driver_id` bigint unsigned NOT NULL COMMENT 'FK ke driver_m_supir.id',
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `speed` decimal(8,2) DEFAULT NULL,
  `heading` decimal(8,2) DEFAULT NULL,
  `accuracy` decimal(8,2) DEFAULT NULL,
  `recorded_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `driver_t_location_driver_id_recorded_at_index` (`driver_id`,`recorded_at`),
  CONSTRAINT `fk_driver_t_location_driver` FOREIGN KEY (`driver_id`) REFERENCES `driver_m_supir` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
