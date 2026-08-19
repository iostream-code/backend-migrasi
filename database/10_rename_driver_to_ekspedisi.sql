-- RENAME satu-kali (langkah 10 dari database/): app ini berkembang dari
-- "driver tracking" jadi "aplikasi ekspedisi" yang lebih luas (nanti akan
-- mencakup manajemen surat jalan juga, sebagai modul terpisah menyusul).
-- Prefix tabel diganti driver_* -> ekspedisi_* supaya namanya konsisten
-- dengan identitas app yang baru.
--
-- MySQL RENAME TABLE bersifat atomik utk banyak tabel sekaligus, dan
-- otomatis memperbarui semua FOREIGN KEY yang menunjuk ke tabel yang
-- di-rename -- tidak perlu drop/recreate constraint manapun.
--
-- CATATAN: nama index & constraint INTERNAL (mis. `fk_driver_t_trip_driver`,
-- `driver_m_supir_user_id_unique`) SENGAJA TIDAK ikut di-rename di sini --
-- itu detail implementasi MySQL, tidak pernah dirujuk dari kode aplikasi
-- manapun, jadi aman dibiarkan memuat nama lama (utang kosmetik kecil, bukan
-- fungsional).
--
-- File migration 01-09 SENGAJA TIDAK diedit/di-rename -- itu arsip historis
-- apa yang benar-benar dijalankan saat itu (tabelnya memang bernama driver_*
-- waktu dibuat). Kode aplikasi (PHP) per commit ini sudah 100% merujuk nama
-- BARU (ekspedisi_*) -- app TIDAK AKAN JALAN sampai migration ini dijalankan.
--
-- Jalankan manual: mysql -u <user> -p <database_produksi> < database/10_rename_driver_to_ekspedisi.sql

RENAME TABLE
  driver_m_supir            TO ekspedisi_m_supir,
  driver_m_admin_access     TO ekspedisi_m_admin_access,
  driver_t_trip             TO ekspedisi_t_trip,
  driver_t_trip_photo       TO ekspedisi_t_trip_photo,
  driver_t_location         TO ekspedisi_t_location,
  driver_t_pengajuan_biaya  TO ekspedisi_t_pengajuan_biaya;
