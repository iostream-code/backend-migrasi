-- Tabel baru (langkah 9 dari database/): driver_t_pengajuan_biaya --
-- pengajuan biaya admin ke finance utk satu trip (supir internal MAUPUN
-- eksternal/ekspedisi -- keduanya sama-sama bisa butuh biaya jalan/ongkir).
-- nominal_diajukan SENGAJA input manual admin, bukan hasil hitungan sistem
-- (mis. dari m_expedisi_tarif) -- sesuai permintaan, admin yang menentukan
-- angka yang mau diajukan.
--
-- FK ASLI ke driver_t_trip (bukan tautan logis) -- keduanya tabel milik app
-- ini sendiri, jadi aman, beda dari kolom yang menunjuk ke tabel
-- backend-production.
--
-- Alur status: diajukan -> disetujui/ditolak (approval finance -- endpoint
-- utk approve/reject BELUM dibuat sesi ini, lihat README).
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/09_create_driver_t_pengajuan_biaya.sql

CREATE TABLE `driver_t_pengajuan_biaya` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint unsigned NOT NULL COMMENT 'FK ke driver_t_trip.id',
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
  KEY `driver_t_pengajuan_biaya_trip_id_index` (`trip_id`),
  KEY `driver_t_pengajuan_biaya_status_index` (`status`),
  CONSTRAINT `fk_driver_t_pengajuan_biaya_trip` FOREIGN KEY (`trip_id`) REFERENCES `driver_t_trip` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
