-- ekspedisi-apk-backend -- skema tabel domain ekspedisi (langkah 1 dari database/).
--
-- KONSOLIDASI KETIGA (2026-08-20): file 04-06 (dokumen KTP/SIM/STNK supir,
-- master perusahaan ekspedisi lokal + FK id_expedisi) digabung ke sini --
-- SEMUA langkah itu sudah dijalankan di produksi (lihat README.md bagian
-- "Dokumen supir" & "Master perusahaan ekspedisi eksternal" utk riwayat
-- lengkapnya). Pola yang sama diulang lagi dari 2 konsolidasi sebelumnya:
-- KONSOLIDASI KEDUA (2026-08-20): 9 file bernomor (01 skema dasar, 02-03
-- seed, 04-09 create/alter modul surat jalan) digabung jadi 1 snapshot.
-- KONSOLIDASI PERTAMA (2026-08-19): 10 file evolusi desain awal digabung
-- jadi 1. Riwayat evolusi step-by-step tetap ada di git log kalau perlu
-- ditelusuri lagi -- file yang sudah dikonsolidasi TIDAK ADA lagi di
-- `database/`, cuma snapshot akhirnya yang dipertahankan di sini.
--
-- BUKAN migration Laravel/framework apa pun -- jalankan manual sekali, urut:
--   mysql -u <user> -p <database_produksi> < database/01_schema.sql
--   mysql -u <user> -p <database_produksi> < database/02_seed_admin_access.sql
--   mysql -u <user> -p <database_produksi> < database/03_seed_dummy_drivers.sql   # opsional
--
-- Kalau nanti ada perubahan skema baru: tambah file baru bernomor berikutnya
-- (mis. 04_...sql), JANGAN edit file yang sudah pernah dijalankan di produksi.
--
-- Ditulis mengikuti gaya penulisan tabel di db_dump.sql (backend-production):
-- ENGINE=InnoDB, utf8mb4/utf8mb4_unicode_ci, PK `id` bigint unsigned
-- AUTO_INCREMENT, FK eksplisit -- termasuk FK ke shared_m_users karena tabel
-- ini hidup di database PRODUKSI YANG SAMA (bukan database terpisah), cuma
-- dikelola dari codebase ini.
--
-- Tidak menyentuh/mengubah tabel manapun yang sudah ada (shared_m_users, hrm_*, dst),
-- dan tidak menyentuh `surat_jalan`/`t_penjualan_header`/`m_expedisi` lama milik
-- backend-production (dibaca READ-ONLY saja dari PHP, lihat README.md).

-- Master perusahaan ekspedisi eksternal, MILIK app ini sendiri -- HARUS dibuat
-- SEBELUM ekspedisi_m_supir di bawah (kolom id_expedisi-nya FK ke sini).
-- Independen dari `m_expedisi` backend-production (yang punya CRUD sendiri
-- tapi tidak dipakai frontend manapun di workspace ini) -- lihat README.md
-- bagian "Master perusahaan ekspedisi eksternal" utk alasan lengkap, dan
-- database/migrate_m_expedisi_ke_ekspedisi_m_ekspedisi.php kalau mau
-- menduplikasi data lama dari sana (OPSIONAL, bukan wajib).
CREATE TABLE `ekspedisi_m_ekspedisi` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kode_ekspedisi` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ekspedisi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pic` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alamat` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `no_telp` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  -- Nonaktifkan (bukan hapus baris) kalau perusahaan sudah tidak dipakai lagi
  -- -- riwayat supir/trip yang pernah ditautkan ke sini tetap utuh.
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ekspedisi_m_ekspedisi_kode_ekspedisi_unique` (`kode_ekspedisi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  -- FK ASLI ke ekspedisi_m_ekspedisi.id (tabel lokal di atas) -- dulu tautan
  -- logis ke m_expedisi.id_expedisi (backend-production), diarahkan ulang
  -- 2026-08-20 (lihat README.md bagian "Master perusahaan ekspedisi eksternal").
  `id_expedisi` bigint unsigned DEFAULT NULL COMMENT 'FK ke ekspedisi_m_ekspedisi.id -- NULL kalau supir eksternal lepas/independen',
  -- Dokumen supir (2026-08-20) -- foto_sim WAJIB semua tipe (validasi di
  -- Controller, bukan NOT NULL di sini -- lihat catatan panjang di README.md
  -- bagian "Dokumen supir" soal kenapa tetap nullable), foto_ktp/foto_stnk
  -- WAJIB tambahan cuma utk tipe='eksternal'. Path relatif, format WEBP
  -- (App\Support\PhotoStorage), folder public/uploads/drivers/{id}/.
  `foto_sim` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path relatif foto SIM -- wajib utk SEMUA supir (internal & eksternal)',
  `foto_ktp` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path relatif foto KTP -- wajib cuma utk supir eksternal',
  `foto_stnk` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path relatif foto STNK -- wajib cuma utk supir eksternal',
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
  CONSTRAINT `fk_ekspedisi_m_supir_user` FOREIGN KEY (`user_id`) REFERENCES `shared_m_users` (`user_id`),
  CONSTRAINT `fk_ekspedisi_m_supir_ekspedisi` FOREIGN KEY (`id_expedisi`) REFERENCES `ekspedisi_m_ekspedisi` (`id`) ON DELETE SET NULL
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

-- Modul surat jalan MILIK app ini sendiri, independen dari tabel `surat_jalan`
-- lama milik backend-production (yang masih aktif dipakai surat-jalan-apk/
-- produksi-apk/finance-apk -- SENGAJA tidak disentuh/ditulisi sama sekali).
--
-- Dua jalur pengisian (lihat App\Support\SuratJalan & Controllers\SuratJalanController):
--   1. Otomatis: checkpoint foto "sj" yang diupload supir di akhir trip
--      (POST /driver/trip/{trip}/photo, type=sj) upsert 1 baris di sini,
--      tertaut ke trip_id itu.
--   2. Manual: admin bikin/lengkapi SJ langsung dari layar admin (POST/PUT
--      /admin/sj), trip_id boleh NULL kalau tidak terkait trip manapun, driver_id
--      WAJIB (divalidasi di controller, bukan NOT NULL di skema -- supaya tidak
--      berisiko ke baris lama/jalur lain kalau kebutuhannya berubah lagi nanti).
--
-- FK ke ekspedisi_t_trip & ekspedisi_m_supir itu FK ASLI (tabel milik app ini
-- sendiri). penjualan_id tautan LOGIS (bukan FK asli) ke
-- t_penjualan_header.penjualan_id milik backend-production -- HANYA keisi dari
-- jalur trip-linked (upsertFromTripPhoto(), selalu 1 SPK per trip). Jalur manual
-- (store()) TIDAK mengisi kolom ini lagi -- SPK-nya cuma bisa diketahui per-item
-- lewat ekspedisi_t_surat_jalan_item (lihat di bawah), karena 1 SJ manual boleh
-- lintas beberapa SPK sekaligus.
--
-- `asal` (native/migrasi_legacy) menandai baris hasil migrasi data historis dari
-- `surat_jalan` lama (script sekali-jalan terpisah, lihat
-- database/migrate_legacy_surat_jalan.php) -- dipakai App\Support\PenjualanItemLookup
-- supaya baris migrasi tidak kehitung dobel saat menjumlahkan sisa qty (sisi
-- `surat_jalan` asalnya sudah mewakilinya).
CREATE TABLE `ekspedisi_t_surat_jalan` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `no_surat_jalan` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Auto-generated setelah insert (format SJ_YYYYMMDD_xxxx, sebelum 2026-08-21 separatornya "-"), lihat App\\Support\\SuratJalan::assignNomor() -- kecuali baris migrasi_legacy, no aslinya dipertahankan',
  `trip_id` bigint unsigned DEFAULT NULL COMMENT 'FK ke ekspedisi_t_trip.id -- NULL kalau SJ dibuat manual admin tanpa trip',
  `penjualan_id` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tautan logis opsional ke t_penjualan_header.penjualan_id (backend-production) -- cuma keisi dari jalur trip-linked, lihat catatan di atas',
  `driver_id` bigint unsigned DEFAULT NULL COMMENT 'FK ke ekspedisi_m_supir.id -- NULL utk baris migrasi_legacy (tidak match andal ke supir manapun)',
  `tujuan` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kendaraan` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plat` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `penerima` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nama penerima/PIC di tujuan -- opsional, bantu supir tahu siapa yang harus dihubungi/diserahi barang',
  `jumlah_kirim` int DEFAULT NULL COMMENT 'Kalau ada items (lihat ekspedisi_t_surat_jalan_item), dihitung otomatis dari total semua item',
  `tgl_kirim` date DEFAULT NULL COMMENT 'Tanggal kirim -- bisa beda dari created_at (waktu record dibuat)',
  `foto_surat_jalan` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path relatif (native) atau URL absolut ke indokoper.com (migrasi_legacy) -- pola sama seperti ekspedisi_t_trip_photo',
  `foto_validasi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Foto SJ fisik final yang sudah ditandatangani penerima -- diupload admin, TERPISAH dari foto_surat_jalan (bukti checkpoint lapangan)',
  `divalidasi_oleh` int DEFAULT NULL COMMENT 'FK ke shared_m_users.user_id -- admin yang melakukan validasi',
  `divalidasi_at` datetime DEFAULT NULL,
  `catatan` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Baris migrasi_legacy: menyimpan nilai asli kolom pengirim (teks bebas, tidak match andal ke supir manapun)',
  `status` enum('draft','terkirim','tervalidasi') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft = belum ada foto, terkirim = ada foto (checkpoint supir/upload admin), tervalidasi = admin sudah upload foto SJ final bertandatangan',
  `asal` enum('native','migrasi_legacy') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'native' COMMENT 'native = dibuat dari app ini (manual atau checkpoint supir); migrasi_legacy = hasil migrasi data historis surat_jalan lama, lihat migrate_legacy_surat_jalan.php',
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
  CONSTRAINT `fk_ekspedisi_t_surat_jalan_driver` FOREIGN KEY (`driver_id`) REFERENCES `ekspedisi_m_supir` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ekspedisi_t_surat_jalan_divalidasi_oleh` FOREIGN KEY (`divalidasi_oleh`) REFERENCES `shared_m_users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Breakdown per-produk dari 1 SJ, MILIK app ini sendiri -- BEDA dari
-- `surat_jalan` lama yang 1-baris-per-lini-produk (1 dokumen SJ fisik jadi
-- tersebar di banyak baris, semua share no_surat_jalan yang sama). Di sini
-- 1 dokumen SJ fisik = 1 baris header (ekspedisi_t_surat_jalan) + N baris
-- item -- lebih match ke bentuk dokumen aslinya (1 SJ bisa antar beberapa
-- produk/SPK sekaligus) & gampang ditampilkan sbg 1 kartu di UI.
--
-- penjualan_detail_performa_id tautan LOGIS (bukan FK asli) ke
-- t_penjualan_detail_performa.penjualan_detail_performa_id milik
-- backend-production (SPK-nya sendiri baru diketahui lewat JOIN itu, tidak ada
-- kolom penjualan_id langsung di tabel ini -- lihat App\Support\PenjualanItemLookup).
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
