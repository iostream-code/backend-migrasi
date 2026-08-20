<?php

declare(strict_types=1);

/**
 * Migrasi data HISTORIS dari `surat_jalan` (tabel lama milik backend-production,
 * masih live dipakai surat-jalan-apk/produksi-apk/finance-apk -- TIDAK PERNAH
 * ditulis/diubah oleh script ini, cuma di-SELECT) ke `ekspedisi_t_surat_jalan` +
 * `ekspedisi_t_surat_jalan_item` (tabel app ini).
 *
 * BUKAN bagian dari urutan skema 01_..sql s/d 09_..sql -- ini operasi DATA
 * sekali-jalan, bukan perubahan skema. WAJIB dijalankan SETELAH
 * database/09_tambah_kolom_asal_surat_jalan.sql (baca komentar di file itu
 * dulu -- ada risiko baris kehitung dobel di perhitungan sisa qty kalau
 * kolom `asal` belum ada).
 *
 * Yang dilakukan per no_surat_jalan (1 dokumen SJ fisik = 1 header + N item,
 * digrupkan dari baris-baris `surat_jalan` yang no_surat_jalan-nya sama --
 * kendaraan/plat/tanggal SAMA di semua baris utk 1 no_surat_jalan yang sama,
 * lihat db_dump.sql):
 * - Header `ekspedisi_t_surat_jalan`: no_surat_jalan ASLI dipertahankan (BUKAN
 *   di-generate ulang format SJ-YYYYMMDD-xxxx) -- KECUALI kalau collision
 *   (lihat "Disambiguasi" di bawah), asal='migrasi_legacy', status='terkirim'
 *   (seragam -- valid_cs di data lama seragam readonly=1, tidak ada info yang
 *   bisa dipetakan andal ke 'draft'/'tervalidasi').
 * - driver_id SENGAJA NULL -- kolom `pengirim` di data lama cuma teks bebas
 *   (ratusan nilai unik gaya "Yoyo (diambil)"/"Haer/gojek"), TIDAK match ke
 *   ekspedisi_m_supir/shared_m_users manapun secara andal. Nilai aslinya
 *   disimpan di `catatan` (BUKAN dipetakan ke `penerima` -- beda arti,
 *   penerima = PIC tujuan, bukan nama pengirim/kurir).
 * - foto_surat_jalan disimpan sbg URL ABSOLUT ke host lama
 *   (https://indokoper.com/foto_surat_jalan/{filename}) -- TIDAK disalin
 *   fisik. Frontend (adminSuratJalan.js, fotoUrl()) sudah disesuaikan buat
 *   nampilin URL absolut apa adanya (tidak digabung lagi dgn API_BASE_URL).
 * - Item `ekspedisi_t_surat_jalan_item`: penjualan_detail_performa_id +
 *   jumlah_kirim APA ADANYA dari tiap baris `surat_jalan` dalam grup itu.
 *
 * Disambiguasi no_surat_jalan (2026-08-20, ditemukan di data produksi asli):
 * `surat_jalan` lama TIDAK punya UNIQUE constraint di no_surat_jalan (1
 * no_surat_jalan boleh dipakai berkali-kali di data lama -- itu memang
 * asumsi normalnya krn 1 dokumen = banyak baris produk). Tapi kadang dua
 * DOKUMEN FISIK BERBEDA (tanggal/jam beda, isi baris beda) kebetulan dikasih
 * nomor yang cuma beda kapitalisasi (mis. "SJ_003/Amb-c-11/2024" vs
 * "SJ_003/amb-c-11/2024") -- keduanya jadi 2 GRUP TERPISAH di script ini
 * (grouping PHP case-SENSITIVE), tapi bentrok pas INSERT krn
 * `ekspedisi_t_surat_jalan.no_surat_jalan` UNIQUE KEY-nya pakai collation
 * `utf8mb4_unicode_ci` (case-INSENSITIVE). Solusinya: grup ke-2/ke-3/dst yang
 * no_surat_jalan-nya cuma beda kapitalisasi dari grup sebelumnya dikasih
 * suffix " (migrasi-2)", " (migrasi-3)", dst -- supaya kedua dokumen tetap
 * tersimpan terpisah (bukan digabung/dibuang), cuma nomornya dibedain
 * eksplisit. Suffix dihitung SEBELUM proses insert, konsisten di run manapun.
 *
 * Progres per-DOKUMEN, BUKAN 1 transaksi besar (2026-08-20, direvisi setelah
 * pengalaman: kalau 1 transaksi besar lalu ada 1 dokumen bermasalah di
 * tengah 1500-an dokumen, SEMUA yang sudah berhasil ikut rollback & harus
 * diulang dari nol). Sekarang tiap dokumen commit sendiri-sendiri -- kalau
 * ada yang gagal (mis. data aneh yang belum kepikiran), dokumen itu di-skip
 * & dilaporkan di akhir, dokumen lain yang sudah berhasil TETAP tersimpan.
 * Idempotent & aman diulang -- idempotency check + UNIQUE KEY jadi pengaman
 * ganda thd dobel-insert.
 *
 * Pakai: php database/migrate_legacy_surat_jalan.php [--since=2024-01-01] [--dry-run]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database;

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->load();

$since = '2024-01-01';
$dryRun = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--since=')) {
        $since = substr($arg, 8);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$pdo = Database::connection();

$stmt = $pdo->prepare(
    'SELECT no_surat_jalan, penjualan_detail_performa_id, jumlah_kirim, tanggal, kendaraan, plat,
            pengirim, foto_surat_jalan, tgl_di_kirim
     FROM surat_jalan
     WHERE tanggal >= :since
     ORDER BY no_surat_jalan, id'
);
$stmt->execute(['since' => $since]);
$rows = $stmt->fetchAll();

$groups = [];
foreach ($rows as $row) {
    $groups[$row['no_surat_jalan']][] = $row;
}

// Disambiguasi -- lihat komentar panjang di atas. Dihitung utk SEMUA grup
// (bukan cuma yang belum dimigrasi) supaya hasilnya konsisten antar-run.
$seenNormalized = [];
$finalNoSuratJalan = []; // no_surat_jalan asli (key grup) => no_surat_jalan final yang dipakai utk insert/cek
foreach (array_keys($groups) as $original) {
    // PHP otomatis mengubah key array yang berupa string angka murni (mis.
    // "12345") jadi int -- cast balik ke string dulu sebelum diproses.
    $original = (string) $original;
    $normalized = mb_strtoupper($original);
    $seenNormalized[$normalized] = ($seenNormalized[$normalized] ?? 0) + 1;
    $n = $seenNormalized[$normalized];
    $finalNoSuratJalan[$original] = $n > 1 ? "{$original} (migrasi-{$n})" : $original;
}

// Idempotency guard -- cek berdasar no_surat_jalan FINAL (setelah disambiguasi),
// supaya konsisten dgn apa yang benar-benar disimpan di run sebelumnya.
$already = $pdo->query("SELECT no_surat_jalan FROM ekspedisi_t_surat_jalan WHERE asal = 'migrasi_legacy'")
    ->fetchAll(PDO::FETCH_COLUMN);
$alreadyMigrated = array_flip($already);

$toMigrate = array_filter(
    array_keys($groups),
    fn ($original) => !isset($alreadyMigrated[$finalNoSuratJalan[$original]])
);

printf(
    "%d baris surat_jalan sejak %s -> %d dokumen SJ (no_surat_jalan unik), %d sudah pernah dimigrasi, %d akan diproses.\n",
    count($rows),
    $since,
    count($groups),
    count($groups) - count($toMigrate),
    count($toMigrate)
);

if (!$toMigrate) {
    echo "Tidak ada yang perlu dimigrasi.\n";
    exit(0);
}

$insertHeader = $pdo->prepare(
    "INSERT INTO ekspedisi_t_surat_jalan
        (no_surat_jalan, driver_id, tujuan, kendaraan, plat, jumlah_kirim, tgl_kirim,
         foto_surat_jalan, catatan, status, asal, created_at)
     VALUES
        (:no_surat_jalan, NULL, NULL, :kendaraan, :plat, :jumlah_kirim, :tgl_kirim,
         :foto, :catatan, 'terkirim', 'migrasi_legacy', :created_at)"
);
$insertItem = $pdo->prepare(
    'INSERT INTO ekspedisi_t_surat_jalan_item (surat_jalan_id, penjualan_detail_performa_id, jumlah_kirim)
     VALUES (:surat_jalan_id, :penjualan_detail_performa_id, :jumlah_kirim)'
);

$countHeader = 0;
$countItem = 0;
$countItemSkipped = 0;
$countQtyNulled = 0;
$failed = []; // no_surat_jalan final => pesan error, utk ditinjau manual

foreach ($toMigrate as $original) {
    $noSuratJalan = $finalNoSuratJalan[$original];
    $lines = $groups[$original];
    $first = $lines[0];

    // Sebagian baris surat_jalan lama ternyata punya jumlah_kirim NULL
    // (kolomnya nullable di skema lama, beda dari ekspedisi_t_surat_jalan_item
    // yang NOT NULL) -- diisi 0, BUKAN dilewati, supaya baris & fotonya
    // tetap tercatat (dihitung & dilaporkan di akhir biar kelihatan).
    // penjualan_detail_performa_id (FK-ish, WAJIB) beda kasus -- kalau NULL,
    // baris itu tidak bisa jadi item apa pun, jadi DILEWATI (bukan diisi 0).
    $validLines = [];
    foreach ($lines as $line) {
        if ($line['penjualan_detail_performa_id'] === null) {
            $countItemSkipped++;
            continue;
        }
        if ($line['jumlah_kirim'] === null) {
            $line['jumlah_kirim'] = 0;
            $countQtyNulled++;
        }
        $validLines[] = $line;
    }
    if (!$validLines) {
        continue; // semua baris di grup ini tidak punya penjualan_detail_performa_id -- lewati seluruh dokumen
    }
    $lines = $validLines;

    $jumlahKirim = array_sum(array_column($lines, 'jumlah_kirim'));
    $pengirimList = array_values(array_unique(array_filter(array_column($lines, 'pengirim'))));
    $catatan = 'Dimigrasi dari surat_jalan lama (backend-production)';
    if ($pengirimList) {
        $catatan .= ' -- pengirim (data lama): ' . implode(', ', $pengirimList);
    }
    $foto = ($first['foto_surat_jalan'] && $first['foto_surat_jalan'] !== '-')
        ? 'https://indokoper.com/foto_surat_jalan/' . $first['foto_surat_jalan']
        : null;
    $tglKirim = $first['tgl_di_kirim'] ?? $first['tanggal'];

    if ($dryRun) {
        $countHeader++;
        $countItem += count($lines);
        continue;
    }

    // 1 dokumen = 1 transaksi sendiri -- lihat komentar panjang di atas.
    $pdo->beginTransaction();
    try {
        $insertHeader->execute([
            'no_surat_jalan' => $noSuratJalan,
            'kendaraan' => $first['kendaraan'] ?: null,
            'plat' => $first['plat'] ?: null,
            'jumlah_kirim' => $jumlahKirim,
            'tgl_kirim' => $tglKirim ? date('Y-m-d', strtotime((string) $tglKirim)) : null,
            'foto' => $foto,
            'catatan' => $catatan,
            'created_at' => $first['tanggal'] ?: date('Y-m-d H:i:s'),
        ]);
        $suratJalanId = (int) $pdo->lastInsertId();

        foreach ($lines as $line) {
            $insertItem->execute([
                'surat_jalan_id' => $suratJalanId,
                'penjualan_detail_performa_id' => $line['penjualan_detail_performa_id'],
                'jumlah_kirim' => $line['jumlah_kirim'],
            ]);
        }

        $pdo->commit();
        $countHeader++;
        $countItem += count($lines);
    } catch (Throwable $e) {
        $pdo->rollBack();
        $failed[$noSuratJalan] = $e->getMessage();
    }
}

if ($dryRun) {
    printf("[DRY RUN] Akan bikin %d SJ header + %d baris item. Tidak ada perubahan disimpan.\n", $countHeader, $countItem);
} else {
    printf("Selesai: %d SJ header + %d baris item dimigrasi.\n", $countHeader, $countItem);
}
if ($countQtyNulled) {
    printf("Catatan: %d baris item punya jumlah_kirim NULL di data lama, diisi 0.\n", $countQtyNulled);
}
if ($countItemSkipped) {
    printf("Catatan: %d baris surat_jalan lama DILEWATI (penjualan_detail_performa_id NULL, tidak bisa jadi item).\n", $countItemSkipped);
}
if ($failed) {
    printf("\n%d dokumen GAGAL & DILEWATI (lainnya tetap tersimpan) -- tinjau manual, jalankan ulang script ini setelah diperbaiki (idempotent, yang lain tidak akan diulang):\n", count($failed));
    foreach ($failed as $no => $msg) {
        printf("  - %s: %s\n", $no, $msg);
    }
    exit(1);
}
