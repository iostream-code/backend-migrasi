<?php

declare(strict_types=1);

namespace App\Ekspedisi\Support;

use PDO;

/**
 * CRUD ke ekspedisi_t_surat_jalan -- tabel MILIK app ini sendiri (bukan
 * tautan ke backend-production, beda dari SuratJalanLookup yang read-only ke
 * tabel surat_jalan lama). Dua jalur pengisian: otomatis dari checkpoint foto
 * "sj" (lihat upsertFromTripPhoto(), dipanggil DriverController::uploadPhoto())
 * dan manual dari admin (lihat SuratJalanController).
 */
class SuratJalan
{
    /**
     * $filters: status?, belum_tervalidasi? (bool-ish, `sj.status != 'tervalidasi'`
     * -- 2026-08-23, dipakai tab SJ mode AKTIF sejak kolom Status dicopot dari
     * tabel & "aktif" didefinisikan ulang jadi murni "belum tervalidasi"; kalau
     * `status` JUGA diisi, `status` menang, `belum_tervalidasi` diabaikan),
     * penjualan_id?, tahun? (filter YEAR(COALESCE(tgl_kirim, created_at)) --
     * tgl_kirim dipakai kalau ada krn itu yang tampil di kolom "Dikirim",
     * fallback ke created_at kalau tgl_kirim NULL, supaya baris tanpa tgl_kirim
     * tetap kena 1 tahun tertentu, bukan hilang dari SEMUA filter tahun), q?
     * (cari no_surat_jalan/tujuan/penerima/nama supir/nomor SPK yang disentuh),
     * page? (default 1), per_page? (default 20, maks 100).
     * Return: { data, total, page, per_page }.
     *
     * **Dua jalur (2026-08-23):** kalau `q` diisi, delegasi ke listSearch() --
     * SQL LIMIT/OFFSET biasa, TANPA deteksi nomor terlewat (pencarian teks bebas
     * tidak match konsep "rentang nomor berurutan"). Kalau `q` kosong, delegasi
     * ke listWithGaps() -- nomor SJ (`nomor_urut`) SEKARANG diinput manual
     * admin (lihat create()/update()), bisa ada nomor yang KELEWAT tidak
     * pernah diinput (kertas hilang/belum sempat dicatat) -- listWithGaps()
     * mengisi celah itu dgn baris VIRTUAL (`missing: true`, tidak ada di DB
     * sama sekali) supaya FE bisa menandainya merah sbg pengingat, BUKAN cuma
     * diam-diam hilang dari daftar.
     */
    public static function list(PDO $pdo, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));

        if (!empty($filters['q'])) {
            return self::listSearch($pdo, $filters, $page, $perPage);
        }

        return self::listWithGaps($pdo, $filters, $page, $perPage);
    }

    // `s.tipe AS driver_tipe` (2026-08-29) -- dipakai FE (ekspedisi-apk)
    // nentuin kapan tombol "Serah Terima" manual (khusus supir eksternal,
    // lihat attachSerahTerima()) ditampilkan di tabel/detail SJ.
    private const ROW_SELECT = "SELECT sj.*, COALESCE(u.nama_lengkap, s.nama_eksternal) AS nama_supir, v.nama_lengkap AS nama_validator, s.tipe AS driver_tipe";
    private const ROW_FROM = 'FROM ekspedisi_t_surat_jalan sj
             LEFT JOIN ekspedisi_m_supir s ON s.id = sj.driver_id
             LEFT JOIN shared_m_users u ON u.user_id = s.user_id
             LEFT JOIN shared_m_users v ON v.user_id = sj.divalidasi_oleh';

    /**
     * `status`/`belum_tervalidasi` sbg WHERE tambahan -- dipakai listSearch()
     * DAN listWithGaps() (baris numbered maupun unnumbered), supaya definisi
     * "aktif"/"riwayat" persis sama di kedua jalur.
     * @return array{0: string|null, 1: array} [klausa WHERE atau null, params]
     */
    private static function statusWhereClause(array $filters): array
    {
        if (!empty($filters['status'])) {
            return ['sj.status = :status', ['status' => $filters['status']]];
        }
        if (!empty($filters['belum_tervalidasi'])) {
            return ["sj.status != 'tervalidasi'", []];
        }

        return [null, []];
    }

    /**
     * Mode PENCARIAN (`q` diisi) -- SQL LIMIT/OFFSET langsung, TANPA deteksi
     * nomor terlewat (lihat komentar list()). Sort tetap diusahakan mendekati
     * urutan nomor (nomor_urut DESC, baris tanpa nomor di bawah) supaya
     * konsisten scr visual dgn listWithGaps(), walau di sini murni SQL biasa.
     */
    private static function listSearch(PDO $pdo, array $filters, int $page, int $perPage): array
    {
        $where = [];
        [$statusWhere, $statusParams] = self::statusWhereClause($filters);
        $params = $statusParams;
        if ($statusWhere) {
            $where[] = $statusWhere;
        }
        if (!empty($filters['penjualan_id'])) {
            $where[] = 'sj.penjualan_id = :penjualan_id';
            $params['penjualan_id'] = $filters['penjualan_id'];
        }
        if (!empty($filters['tahun'])) {
            $where[] = 'YEAR(COALESCE(sj.tgl_kirim, sj.created_at)) = :tahun';
            $params['tahun'] = (int) $filters['tahun'];
        }
        // Placeholder :q dipakai 6x di query yang sama -- PDO native prepares
        // (ATTR_EMULATE_PREPARES=false, lihat Database.php) TIDAK izinkan
        // named placeholder yang sama dipakai berkali-kali, jadi tiap
        // occurrence butuh nama beda meski nilainya sama persis.
        $where[] = "(sj.no_surat_jalan LIKE :q1 OR sj.tujuan LIKE :q2 OR sj.penerima LIKE :q3
                     OR u.nama_lengkap LIKE :q4 OR s.nama_eksternal LIKE :q5
                     OR EXISTS (
                         SELECT 1 FROM ekspedisi_t_surat_jalan_item sji
                         JOIN t_penjualan_detail_performa pdp ON pdp.penjualan_detail_performa_id = sji.penjualan_detail_performa_id
                         WHERE sji.surat_jalan_id = sj.id AND pdp.penjualan_id LIKE :q6
                     ))";
        $qLike = '%' . $filters['q'] . '%';
        foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $key) {
            $params[$key] = $qLike;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $countStmt = $pdo->prepare('SELECT COUNT(*) ' . self::ROW_FROM . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            self::ROW_SELECT . ' ' . self::ROW_FROM . $whereSql . '
             ORDER BY (sj.nomor_urut IS NULL) ASC, sj.nomor_urut DESC, sj.created_at DESC, sj.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        self::hydrateRows($pdo, $rows);

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Mode DEFAULT (`q` kosong) -- deteksi & sisipkan baris "nomor terlewat".
     *
     * **Kenapa dihitung LINTAS SEMUA STATUS** (bukan cuma status yang lagi
     * ditampilkan) -- nomor SJ (nomor_urut) adalah 1 urutan tunggal dari
     * kertas fisik, TIDAK terpisah per status. Kalau gap dihitung HANYA dari
     * baris yang match filter status view ini (mis. "belum tervalidasi"),
     * nomor yang sebenarnya ADA tapi kebetulan sudah tervalidasi (makanya
     * tidak match filter) akan SALAH ketandai "terlewat" -- padahal cuma lagi
     * tidak tampil di view ini. Makanya query #1 di bawah (himpunan nomor
     * lengkap) SENGAJA TIDAK ikut filter status, cuma filter tahun.
     *
     * Alur:
     * 1. Ambil SEMUA nomor_urut (lintas status) dalam tahun ini -> hitung
     *    nomor yang HILANG di antara min-max (missingNumbers()).
     * 2. Ambil baris NYATA yang match filter status VIEW ini + tahun + PUNYA
     *    nomor_urut ("numbered rows").
     * 3. Gabung #2 dengan baris VIRTUAL utk tiap nomor hilang dari #1, urut
     *    bareng DESC by nomor_urut -- baris hilang otomatis nongol di posisi
     *    yang pas di antara nomor tetangganya.
     * 4. Baris yang BELUM PUNYA nomor_urut sama sekali (legacy/checkpoint
     *    supir yang belum dilengkapi admin) TIDAK bisa ikut logika gap (tidak
     *    py nomor buat dibandingkan) -- ditaruh terpisah di BAWAH gabungan
     *    #3, urut created_at DESC seperti list lama.
     * 5. total = jumlah #2 + jumlah nomor hilang + jumlah #4. Paginate dengan
     *    array_slice atas gabungan SEMUA baris (bukan LIMIT/OFFSET SQL, krn
     *    baris virtual tidak ada di DB) -- aman selama volume per-tahun tidak
     *    ekstrem (ribuan), yang mana sesuai desain nomor kertas fisik per buku.
     */
    private static function listWithGaps(PDO $pdo, array $filters, int $page, int $perPage): array
    {
        $tahun = !empty($filters['tahun']) ? (int) $filters['tahun'] : null;
        $yearWhere = $tahun !== null ? 'YEAR(COALESCE(sj.tgl_kirim, sj.created_at)) = :tahun' : null;
        [$statusWhere, $statusParams] = self::statusWhereClause($filters);

        $extraWhere = [];
        $extraParams = [];
        if (!empty($filters['penjualan_id'])) {
            $extraWhere[] = 'sj.penjualan_id = :penjualan_id';
            $extraParams['penjualan_id'] = $filters['penjualan_id'];
        }

        // #1: himpunan nomor lengkap (lintas status, cuma filter tahun) -> gap.
        $numSql = 'SELECT nomor_urut FROM ekspedisi_t_surat_jalan sj WHERE nomor_urut IS NOT NULL' . ($yearWhere ? " AND $yearWhere" : '');
        $numStmt = $pdo->prepare($numSql);
        $numStmt->execute($tahun !== null ? ['tahun' => $tahun] : []);
        $allNumbers = array_map('intval', $numStmt->fetchAll(PDO::FETCH_COLUMN));
        $missing = self::missingNumbers($allNumbers);

        // #2: baris nyata numbered, match filter status view ini + tahun.
        $numberedWhere = array_merge(['sj.nomor_urut IS NOT NULL'], $statusWhere ? [$statusWhere] : [], $yearWhere ? [$yearWhere] : [], $extraWhere);
        $numberedParams = array_merge($statusParams, $extraParams);
        if ($yearWhere) {
            $numberedParams['tahun'] = $tahun;
        }
        $stmt = $pdo->prepare(
            self::ROW_SELECT . ' ' . self::ROW_FROM . ' WHERE ' . implode(' AND ', $numberedWhere) . '
             ORDER BY sj.nomor_urut DESC'
        );
        $stmt->execute($numberedParams);
        $numberedRows = $stmt->fetchAll();
        foreach ($numberedRows as &$row) {
            $row['nomor_urut'] = (int) $row['nomor_urut'];
            $row['missing'] = false;
        }
        unset($row);

        // #3: gabung numbered + virtual missing, urut DESC by nomor_urut.
        $combined = $numberedRows;
        foreach ($missing as $num) {
            $combined[] = self::missingRowStub($num);
        }
        usort($combined, static fn (array $a, array $b) => $b['nomor_urut'] <=> $a['nomor_urut']);

        // #4: baris tanpa nomor_urut sama sekali -- di bawah #3, created_at DESC.
        $unnumberedWhere = array_merge(['sj.nomor_urut IS NULL'], $statusWhere ? [$statusWhere] : [], $yearWhere ? [$yearWhere] : [], $extraWhere);
        $unnumberedParams = array_merge($statusParams, $extraParams);
        if ($yearWhere) {
            $unnumberedParams['tahun'] = $tahun;
        }
        $stmt = $pdo->prepare(
            self::ROW_SELECT . ' ' . self::ROW_FROM . ' WHERE ' . implode(' AND ', $unnumberedWhere) . '
             ORDER BY sj.created_at DESC, sj.id DESC'
        );
        $stmt->execute($unnumberedParams);
        $unnumberedRows = $stmt->fetchAll();
        foreach ($unnumberedRows as &$row) {
            $row['missing'] = false;
        }
        unset($row);

        $all = array_merge($combined, $unnumberedRows);
        $total = count($all);

        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($all, $offset, $perPage);

        self::hydrateRows($pdo, $pageRows);

        return ['data' => $pageRows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Nomor integer yang hilang di antara nilai terkecil-terbesar $numbers
     * (sudah diasumsikan semuanya bukan null). Array kosong -> tidak ada gap
     * (termasuk kalau $numbers sendiri kosong, tidak ada apa-apa buat dibandingkan).
     * @param int[] $numbers
     * @return int[]
     */
    private static function missingNumbers(array $numbers): array
    {
        if (!$numbers) {
            return [];
        }
        sort($numbers);
        $existing = array_flip($numbers);
        $missing = [];
        for ($n = $numbers[0]; $n <= end($numbers); $n++) {
            if (!isset($existing[$n])) {
                $missing[] = $n;
            }
        }

        return $missing;
    }

    /**
     * Baris VIRTUAL utk 1 nomor SJ yang terlewat -- TIDAK ADA di DB sama
     * sekali, cuma dibentuk di memori supaya FE bisa merender & menandainya
     * (`missing: true`) sbg pengingat "nomor ini belum pernah diinput".
     * Field lain SENGAJA null/kosong (tidak ada baris asli buat diisi).
     */
    private static function missingRowStub(int $nomor): array
    {
        return [
            'id' => null, 'no_surat_jalan' => 'SJ_' . $nomor, 'nomor_urut' => $nomor,
            'trip_id' => null, 'penjualan_id' => null, 'driver_id' => null, 'tujuan' => null,
            'kendaraan' => null, 'plat' => null, 'penerima' => null, 'jumlah_kirim' => null,
            'tgl_kirim' => null, 'foto_surat_jalan' => null, 'foto_serah_terima' => null, 'foto_validasi' => null,
            'divalidasi_oleh' => null, 'divalidasi_at' => null, 'catatan' => null,
            'status' => null, 'asal' => null, 'created_by' => null, 'created_at' => null,
            'updated_at' => null, 'nama_supir' => null, 'nama_validator' => null, 'driver_tipe' => null,
            'items' => [], 'trip_photos' => [], 'client_names' => [],
            'missing' => true,
        ];
    }

    /**
     * Batch hydration items/trip_photos/client_names, dipakai listSearch() &
     * listWithGaps() sekaligus (2026-08-23, diekstrak dari isi list() lama).
     * Baris VIRTUAL (`id === null`, dari missingRowStub()) dilewati -- sudah
     * py items/trip_photos/client_names kosong dari pembuatannya, tidak perlu
     * & tidak bisa di-query (tidak ada id sungguhan).
     *
     * (2026-08-20, alasan batching: dulu N+1 -- items()+resolveClientNames()
     * per baris di loop, sampai 40+ query cuma buat 1 halaman 20 baris -- DB
     * host beda dgn backend (indokoper.com), tiap round-trip jaringan
     * ~40-50ms, kekumpul jadi lambat BANGET meski tiap query individualnya
     * cepat. Diukur langsung ke produksi: turun dari ~1.8 detik jadi puluhan
     * ms setelah dibatch.)
     */
    private static function hydrateRows(PDO $pdo, array &$rows): void
    {
        $realRowIds = array_values(array_filter(array_column($rows, 'id'), static fn ($id) => $id !== null));
        $itemsByRowId = self::batchItemsByRowId($pdo, $realRowIds);

        $tripIds = array_values(array_unique(array_filter(array_column($rows, 'trip_id'))));
        $tripPhotosByTripId = self::batchTripPhotosByTripId($pdo, $tripIds);

        $penjualanIdsNeeded = [];
        foreach ($rows as &$row) {
            if ($row['id'] === null) {
                continue;
            }
            $row['items'] = $itemsByRowId[(int) $row['id']] ?? [];
            $row['trip_photos'] = $row['trip_id'] ? ($tripPhotosByTripId[(int) $row['trip_id']] ?? []) : [];
            foreach (self::penjualanIdsForRow($row) as $pid) {
                $penjualanIdsNeeded[$pid] = true;
            }
        }
        unset($row);

        $clientNameByPenjualanId = self::batchClientNamesByPenjualanId($pdo, array_keys($penjualanIdsNeeded));
        foreach ($rows as &$row) {
            if ($row['id'] === null) {
                continue;
            }
            $row['client_names'] = array_values(array_unique(array_filter(array_map(
                static fn (string $pid) => $clientNameByPenjualanId[$pid] ?? null,
                self::penjualanIdsForRow($row)
            ))));
        }
        unset($row);
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT sj.*, COALESCE(u.nama_lengkap, s.nama_eksternal) AS nama_supir, v.nama_lengkap AS nama_validator, s.tipe AS driver_tipe
             FROM ekspedisi_t_surat_jalan sj
             LEFT JOIN ekspedisi_m_supir s ON s.id = sj.driver_id
             LEFT JOIN shared_m_users u ON u.user_id = s.user_id
             LEFT JOIN shared_m_users v ON v.user_id = sj.divalidasi_oleh
             WHERE sj.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['items'] = self::items($pdo, $id);
        $row['trip_photos'] = $row['trip_id']
            ? (self::batchTripPhotosByTripId($pdo, [(int) $row['trip_id']])[(int) $row['trip_id']] ?? [])
            : [];
        $row['client_names'] = self::resolveClientNames($pdo, $row);

        return $row;
    }

    public static function findByTrip(PDO $pdo, int $tripId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ekspedisi_t_surat_jalan WHERE trip_id = :trip_id LIMIT 1');
        $stmt->execute(['trip_id' => $tripId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Daftar tahun yang BENERAN ADA di data (bukan range hardcode) -- dipakai
     * ngisi pilihan filter tahun di tab "SJ" FE. Sama COALESCE(tgl_kirim,
     * created_at) dgn filter di list() di atas, biar konsisten (tahun yang
     * ditawarkan di dropdown dijamin selalu punya >=1 baris kalau dipilih).
     * Data migrate_legacy_surat_jalan.php bisa mundur beberapa tahun -- JANGAN
     * diganti ke range hardcode (mis. "3 tahun terakhir"), sempat dicek
     * langsung ke produksi (2026-08-20) hasilnya 2024/2025/2026, tapi tidak
     * boleh diasumsikan tetap 3 kalau nanti ada migrasi data lama lagi.
     */
    public static function availableYears(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT DISTINCT YEAR(COALESCE(tgl_kirim, created_at)) AS tahun
             FROM ekspedisi_t_surat_jalan
             ORDER BY tahun DESC'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Breakdown per-lini produk dari 1 SJ (ekspedisi_t_surat_jalan_item),
     * di-JOIN ke t_penjualan_detail_performa (READ-ONLY, backend-production)
     * buat label produk (penjualan_jenis) DAN penjualan_id lini itu --
     * dipanggil find()/list() supaya GET /admin/sj sekalian bawa breakdown-nya.
     * penjualan_id per-item ini yang jadi sumber kebenaran "SJ ini menyentuh
     * SPK apa saja" (2026-08-20: 1 SJ boleh berisi lini produk dari BEBERAPA
     * SPK sekaligus -- kolom header ekspedisi_t_surat_jalan.penjualan_id
     * TIDAK CUKUP lagi buat itu, cuma dipakai jalur trip-linked lama yang
     * selalu 1 SPK per trip).
     */
    public static function items(PDO $pdo, int $suratJalanId): array
    {
        $stmt = $pdo->prepare(
            'SELECT i.id, i.penjualan_detail_performa_id, i.jumlah_kirim, pdp.penjualan_jenis, pdp.penjualan_id
             FROM ekspedisi_t_surat_jalan_item i
             LEFT JOIN t_penjualan_detail_performa pdp ON pdp.penjualan_detail_performa_id = i.penjualan_detail_performa_id
             WHERE i.surat_jalan_id = :surat_jalan_id
             ORDER BY i.id'
        );
        $stmt->execute(['surat_jalan_id' => $suratJalanId]);

        return $stmt->fetchAll();
    }

    /**
     * Versi BATCH dari items() -- 1 query buat SEMUA $suratJalanIds sekaligus
     * (dipakai list(), lihat catatan N+1 di sana), dikelompokkan per
     * surat_jalan_id di PHP setelahnya. Bentuk tiap baris SAMA PERSIS dgn
     * items() (kolom surat_jalan_id dibuang lagi setelah dipakai grouping,
     * supaya kontrak response API tidak berubah).
     * @return array<int, array> surat_jalan_id => baris item (bentuk sama dgn items())
     */
    private static function batchItemsByRowId(PDO $pdo, array $suratJalanIds): array
    {
        if (!$suratJalanIds) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($suratJalanIds as $i => $id) {
            $key = "sjid{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $id;
        }

        $stmt = $pdo->prepare(
            'SELECT i.surat_jalan_id, i.id, i.penjualan_detail_performa_id, i.jumlah_kirim, pdp.penjualan_jenis, pdp.penjualan_id
             FROM ekspedisi_t_surat_jalan_item i
             LEFT JOIN t_penjualan_detail_performa pdp ON pdp.penjualan_detail_performa_id = i.penjualan_detail_performa_id
             WHERE i.surat_jalan_id IN (' . implode(',', $placeholders) . ')
             ORDER BY i.surat_jalan_id, i.id'
        );
        $stmt->execute($params);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $sjId = (int) $row['surat_jalan_id'];
            unset($row['surat_jalan_id']);
            $grouped[$sjId][] = $row;
        }

        return $grouped;
    }

    /**
     * Foto checkpoint SUPIR (ekspedisi_t_trip_photo: berangkat/serah_terima/sj)
     * utk baris SJ yang trip_id-nya terisi -- dipakai modal "Detail Surat Jalan"
     * FE (2026-08-21) supaya foto lapangan supir ikut kelihatan, bukan cuma
     * foto_surat_jalan/foto_validasi milik SJ sendiri. Path relatif APA ADANYA
     * (sama pola dgn foto_surat_jalan/foto_validasi di atas) -- FE yang urus
     * prefix API_BASE_URL, BUKAN dibangun jadi URL absolut di sini (beda dari
     * AdminController::driverDetail(), yang memang butuh absolut krn dipakai
     * halaman lain).
     * @return array<int, array<int, array{type:string, path:string}>> trip_id => baris foto
     */
    private static function batchTripPhotosByTripId(PDO $pdo, array $tripIds): array
    {
        if (!$tripIds) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($tripIds as $i => $tripId) {
            $key = "tid{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $tripId;
        }

        $stmt = $pdo->prepare(
            'SELECT trip_id, type, path FROM ekspedisi_t_trip_photo
             WHERE trip_id IN (' . implode(',', $placeholders) . ')'
        );
        $stmt->execute($params);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['trip_id']][] = ['type' => $row['type'], 'path' => $row['path']];
        }

        return $grouped;
    }

    /**
     * Daftar penjualan_id yang disentuh 1 baris SJ -- dari items() (jalur manual,
     * bisa lintas beberapa SPK sekaligus) kalau ada, fallback ke kolom
     * penjualan_id di header (jalur trip-linked lama, selalu 1 SPK, tidak punya
     * baris item) kalau items kosong. Dipakai resolveClientNames() (single-row)
     * DAN list() (batch) supaya logikanya SAMA PERSIS di kedua jalur.
     */
    private static function penjualanIdsForRow(array $row): array
    {
        $penjualanIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item) => $item['penjualan_id'] ?? null,
            $row['items'] ?? []
        ))));

        if (!$penjualanIds && !empty($row['penjualan_id'])) {
            $penjualanIds = [$row['penjualan_id']];
        }

        return $penjualanIds;
    }

    /**
     * Versi BATCH dari resolveClientNames() -- 1 query ambil nama klien utk
     * SEMUA penjualan_id yang dibutuhkan lintas SEMUA baris di halaman sekaligus
     * (dipakai list(), lihat catatan N+1 di atas), dikembalikan sbg map supaya
     * pemanggil bisa susun ulang per baris tanpa query tambahan.
     * @return array<string, string> penjualan_id => client_nama
     */
    private static function batchClientNamesByPenjualanId(PDO $pdo, array $penjualanIds): array
    {
        if (!$penjualanIds) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($penjualanIds as $i => $penjualanId) {
            $key = "pid{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $penjualanId;
        }

        $stmt = $pdo->prepare(
            'SELECT p.penjualan_id, c.client_nama
             FROM t_penjualan_header p
             JOIN m_client c ON c.client_id = p.client_id
             WHERE p.penjualan_id IN (' . implode(',', $placeholders) . ')'
        );
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['penjualan_id']] = $row['client_nama'];
        }

        return $map;
    }

    /**
     * Nama klien (m_client.client_nama, READ-ONLY ke backend-production) dari
     * SPK yang disentuh SJ ini -- dari items() (jalur manual, breakdown per
     * lini produk, bisa lintas beberapa SPK/klien sekaligus) kalau ada,
     * fallback ke kolom penjualan_id di header (jalur trip-linked lama,
     * selalu 1 SPK, tidak punya baris item) kalau items kosong. Dipakai
     * find() (single-row, mis. GET /admin/sj/{id}) -- list() pakai versi
     * batch di atas, N+1 kalau method ini yang dipanggil per-baris di loop.
     */
    private static function resolveClientNames(PDO $pdo, array $row): array
    {
        $penjualanIds = self::penjualanIdsForRow($row);

        if (!$penjualanIds) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($penjualanIds as $i => $penjualanId) {
            $key = "pid{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $penjualanId;
        }

        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.client_nama
             FROM t_penjualan_header p
             JOIN m_client c ON c.client_id = p.client_id
             WHERE p.penjualan_id IN (' . implode(',', $placeholders) . ')'
        );
        $stmt->execute($params);

        return array_column($stmt->fetchAll(), 'client_nama');
    }

    /**
     * Dipanggil admin lewat POST /admin/sj -- bikin SJ manual, trip_id
     * boleh NULL (tidak terkait trip manapun). $data['items'] opsional --
     * array [{penjualan_detail_performa_id, jumlah_kirim}, ...], BOLEH berisi
     * lini produk dari beberapa SPK berbeda sekaligus (lihat
     * SuratJalanController::store(), yang sudah validasi sisa qty tiap item
     * SATU-SATU lewat App\Ekspedisi\Support\PenjualanItemLookup::findLine() sebelum
     * sampai sini -- makanya $data['penjualan_id'] SENGAJA tidak ada lagi di
     * sini, SPK-nya cuma bisa diketahui per-item lewat items()).
     *
     * `nomor_urut` (2026-08-23, WAJIB -- SuratJalanController::store() sudah
     * validasi int positif & keunikannya SEBELUM sampai sini) -- nomor kertas
     * SJ fisik diinput manual admin, `no_surat_jalan` diturunkan darinya
     * ('SJ_' . nomor_urut) langsung saat insert, BUKAN lagi auto-generate dari
     * id/tanggal lewat assignNomor() (method itu sudah dihapus).
     */
    public static function create(PDO $pdo, array $data): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO ekspedisi_t_surat_jalan
                (no_surat_jalan, nomor_urut, trip_id, penjualan_id, driver_id, tujuan, kendaraan, plat, penerima, jumlah_kirim, tgl_kirim, catatan, created_by)
             VALUES
                (:no_surat_jalan, :nomor_urut, :trip_id, :penjualan_id, :driver_id, :tujuan, :kendaraan, :plat, :penerima, :jumlah_kirim, :tgl_kirim, :catatan, :created_by)'
        );
        $insert->execute([
            'no_surat_jalan' => isset($data['nomor_urut']) ? ('SJ_' . $data['nomor_urut']) : null,
            'nomor_urut' => $data['nomor_urut'] ?? null,
            'trip_id' => $data['trip_id'] ?? null,
            'penjualan_id' => $data['penjualan_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'tujuan' => $data['tujuan'] ?? null,
            'kendaraan' => $data['kendaraan'] ?? null,
            'plat' => $data['plat'] ?? null,
            'penerima' => $data['penerima'] ?? null,
            'jumlah_kirim' => $data['jumlah_kirim'] ?? null,
            'tgl_kirim' => $data['tgl_kirim'] ?? null,
            'catatan' => $data['catatan'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();

        if (!empty($data['items'])) {
            $itemInsert = $pdo->prepare(
                'INSERT INTO ekspedisi_t_surat_jalan_item (surat_jalan_id, penjualan_detail_performa_id, jumlah_kirim)
                 VALUES (:surat_jalan_id, :penjualan_detail_performa_id, :jumlah_kirim)'
            );
            foreach ($data['items'] as $item) {
                $itemInsert->execute([
                    'surat_jalan_id' => $id,
                    'penjualan_detail_performa_id' => $item['penjualan_detail_performa_id'],
                    'jumlah_kirim' => $item['jumlah_kirim'],
                ]);
            }
        }

        return $id;
    }

    /**
     * `nomor_urut` (2026-08-23, opsional di sini -- WAJIB isi utk SJ baru,
     * tapi lewat endpoint ini dipakai juga buat MELENGKAPI nomor SJ yang
     * auto-dibuat dari checkpoint foto supir, upsertFromTripPhoto(), yang
     * TIDAK auto-assign nomor lagi) -- kalau diisi, `no_surat_jalan` ikut
     * diturunkan ulang ('SJ_' . nomor_urut), TIDAK bisa diedit terpisah dari
     * nomor_urut (keduanya SELALU sinkron). SuratJalanController::update()
     * sudah validasi keunikannya (exclude baris ini sendiri) sebelum sampai sini.
     */
    public static function update(PDO $pdo, int $id, array $data): void
    {
        $fields = ['tujuan', 'kendaraan', 'plat', 'penerima', 'jumlah_kirim', 'tgl_kirim', 'catatan'];
        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $set[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        if (array_key_exists('nomor_urut', $data)) {
            $set[] = 'nomor_urut = :nomor_urut';
            $set[] = 'no_surat_jalan = :no_surat_jalan';
            $params['nomor_urut'] = $data['nomor_urut'];
            $params['no_surat_jalan'] = $data['nomor_urut'] !== null ? ('SJ_' . $data['nomor_urut']) : null;
        }
        if (!$set) {
            return;
        }

        $pdo->prepare('UPDATE ekspedisi_t_surat_jalan SET ' . implode(', ', $set) . ' WHERE id = :id')
            ->execute($params);
    }

    /**
     * Dipanggil SuratJalanController::uploadPhoto() -- admin melampirkan foto
     * ke SJ yang dibuat manual (trip_id NULL, jadi tidak pernah lewat jalur
     * upsertFromTripPhoto()). Sama seperti checkpoint foto supir: begitu foto
     * terisi, status naik ke 'terkirim' -- KECUALI SJ ini sudah 'tervalidasi',
     * status akhir itu tidak boleh turun lagi cuma gara-gara ada foto baru.
     */
    public static function attachPhoto(PDO $pdo, int $id, string $photoPath): void
    {
        $pdo->prepare(
            "UPDATE ekspedisi_t_surat_jalan SET foto_surat_jalan = :path,
                status = IF(status = 'tervalidasi', status, 'terkirim') WHERE id = :id"
        )->execute(['path' => $photoPath, 'id' => $id]);
    }

    /**
     * Dipanggil SuratJalanController::uploadSerahTerima() -- admin melampirkan
     * bukti serah terima barang, KHUSUS SJ supir eksternal (dicek di
     * controller, bukan di sini). Beda dari attachPhoto()/validate(): kolom
     * ini ada tuk mengisi kekosongan checkpoint "serah_terima" yang supir
     * internal dapat otomatis lewat app (ekspedisi_t_trip_photo) tapi supir
     * eksternal tidak pernah bisa (tidak punya akun) -- murni dokumentasi
     * TAMBAHAN opsional, sengaja TIDAK mengubah `status` sama sekali (beda
     * dari attachPhoto() yang menaikkan status ke 'terkirim'). Boleh
     * ditimpa berkali-kali (mis. admin salah foto), tidak ada guard status
     * tervalidasi seperti attachPhoto() karena kolom ini tidak pernah jadi
     * bagian alur draft/terkirim/tervalidasi.
     */
    public static function attachSerahTerima(PDO $pdo, int $id, string $photoPath): void
    {
        $pdo->prepare(
            'UPDATE ekspedisi_t_surat_jalan SET foto_serah_terima = :path WHERE id = :id'
        )->execute(['path' => $photoPath, 'id' => $id]);
    }

    /**
     * Dipanggil SuratJalanController::validasi() -- ADMIN mengupload foto SJ
     * fisik final (sudah ditandatangani penerima, dibawa balik supir) sekaligus
     * menandai pengiriman ini tervalidasi. Beda dari attachPhoto()/
     * upsertFromTripPhoto() yang isi foto_surat_jalan (bukti lapangan) --
     * ini isi foto_validasi (bukti closing), status jadi 'tervalidasi', dan
     * dicatat siapa & kapan.
     *
     * (2026-08-28) Sekalian menutup SPK di sisi backend-production: semua
     * `t_penjualan_header` yang disentuh SJ ini di-set `status_pengirman` =
     * 'selesai' + `tgl_surat_jalan_selesai` = waktu validasi (lihat
     * markPenjualanSelesai()) -- sebelum ini TIDAK ADA jalur manapun yang
     * mengisi 2 kolom itu utk SJ yang dibuat lewat app ini, padahal dipakai
     * TagihanBroadcastController (backend-production) buat hitung keterlambatan
     * penagihan. Dibungkus 1 transaction supaya SJ tervalidasi tapi SPK gagal
     * ke-update (atau sebaliknya) tidak pernah kejadian setengah-setengah.
     *
     * (2026-08-29) Sekalian menutup trip terkait kalau masih `in_progress`
     * (lihat completeLinkedTrip()) -- SJ trip-linked yang divalidasi admin
     * padahal supirnya belum sempat/lupa checkpoint foto terakhir sebelumnya
     * bikin trip nyangkut in_progress SELAMANYA, dan `AdminController::drivers()`
     * masih menganggap supirnya "sedang mengirim" (kriteria #1: trip
     * in_progress) meski SJ-nya sendiri sudah closing -- otomatis diselesaikan
     * di sini supaya monitoring tidak nyangkut. Ikut 1 transaction yang sama.
     */
    public static function validate(PDO $pdo, int $id, string $photoPath, int $userId): void
    {
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE ekspedisi_t_surat_jalan
                 SET foto_validasi = :path, status = 'tervalidasi', divalidasi_oleh = :user_id, divalidasi_at = :now
                 WHERE id = :id"
            )->execute([
                'path' => $photoPath,
                'user_id' => $userId,
                'now' => $now,
                'id' => $id,
            ]);

            self::markPenjualanSelesai($pdo, $id, $now);
            self::completeLinkedTrip($pdo, $id, $now);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Dipanggil validate() -- kalau SJ ini trip-linked (`trip_id` terisi,
     * jalur checkpoint foto supir internal) dan trip-nya masih `in_progress`,
     * tandai `completed` sekalian. `WHERE status = 'in_progress'` di UPDATE
     * (bukan cuma di caller) supaya idempotent & aman dari race -- trip yang
     * sudah `completed` duluan (supir sempat checkpoint lengkap sebelum admin
     * validasi) tidak tersentuh, `completed_at`-nya TETAP waktu checkpoint asli,
     * bukan ketiban waktu validasi SJ.
     */
    private static function completeLinkedTrip(PDO $pdo, int $id, string $now): void
    {
        $stmt = $pdo->prepare('SELECT trip_id FROM ekspedisi_t_surat_jalan WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $tripId = $stmt->fetchColumn();
        if (!$tripId) {
            return;
        }

        $pdo->prepare(
            "UPDATE ekspedisi_t_trip SET status = 'completed', completed_at = :now
             WHERE id = :trip_id AND status = 'in_progress'"
        )->execute(['now' => $now, 'trip_id' => $tripId]);
    }

    /**
     * Dipanggil validate() -- kumpulkan semua penjualan_id (SPK) yang
     * disentuh SJ ini lalu tandai selesai di t_penjualan_header milik
     * backend-production (tabel di database produksi YANG SAMA, lihat
     * README backend-migrasi bagian "Modul" -- bukan lintas koneksi).
     *
     * Dua sumber, digabung (1 SJ manual boleh lintas beberapa SPK sekaligus,
     * lihat catatan store()/PenjualanItemLookup):
     * - ekspedisi_t_surat_jalan_item JOIN t_penjualan_detail_performa, utk SJ
     *   dgn breakdown per-item (jalur manual admin, store()/update()).
     * - kolom sj.penjualan_id langsung, fallback utk SJ trip-linked lama yang
     *   belum pernah diisi baris item (upsertFromTripPhoto(), selalu 1 SPK).
     */
    private static function markPenjualanSelesai(PDO $pdo, int $id, string $now): void
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT pdp.penjualan_id
             FROM ekspedisi_t_surat_jalan_item sji
             JOIN t_penjualan_detail_performa pdp ON pdp.penjualan_detail_performa_id = sji.penjualan_detail_performa_id
             WHERE sji.surat_jalan_id = :id"
        );
        $stmt->execute(['id' => $id]);
        $penjualanIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $direct = $pdo->prepare('SELECT penjualan_id FROM ekspedisi_t_surat_jalan WHERE id = :id');
        $direct->execute(['id' => $id]);
        $directId = $direct->fetchColumn();
        if ($directId) {
            $penjualanIds[] = $directId;
        }

        $penjualanIds = array_values(array_unique(array_filter($penjualanIds, fn ($v) => $v !== null && $v !== '')));
        if (!$penjualanIds) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($penjualanIds), '?'));
        $params = array_merge([$now], $penjualanIds);
        $pdo->prepare(
            "UPDATE t_penjualan_header SET status_pengirman = 'selesai', tgl_surat_jalan_selesai = ?
             WHERE penjualan_id IN ($placeholders)"
        )->execute($params);
    }

    /**
     * Dipanggil DriverController::uploadPhoto() saat type=sj -- upsert (by
     * trip_id) supaya re-upload checkpoint yang sama menimpa baris lama,
     * bukan bikin baris baru. driver_id/penjualan_id/tujuan diisi otomatis
     * dari data trip; admin bisa lengkapi kendaraan/plat/jumlah_kirim
     * belakangan lewat PUT /admin/sj/{id}.
     *
     * **TIDAK lagi auto-assign nomor (2026-08-23)** -- dulu ada panggilan
     * assignNomor() di sini (format SJ_YYYYMMDD_XXXX dari id/tanggal, method
     * itu sudah DIHAPUS). Sejak nomor SJ WAJIB diinput manual admin (cocok
     * nomor kertas fisik), baris yang lahir dari jalur checkpoint foto ini
     * mulai dgn no_surat_jalan/nomor_urut NULL -- admin lengkapi belakangan
     * lewat PUT /admin/sj/{id} begitu tahu nomor kertas SJ fisiknya (lihat
     * SuratJalan::update()).
     */
    public static function upsertFromTripPhoto(PDO $pdo, array $trip, int $driverId, string $photoPath): int
    {
        $existing = self::findByTrip($pdo, (int) $trip['id']);
        if ($existing) {
            $pdo->prepare(
                "UPDATE ekspedisi_t_surat_jalan SET foto_surat_jalan = :path,
                    status = IF(status = 'tervalidasi', status, 'terkirim') WHERE id = :id"
            )->execute(['path' => $photoPath, 'id' => $existing['id']]);

            return (int) $existing['id'];
        }

        $insert = $pdo->prepare(
            "INSERT INTO ekspedisi_t_surat_jalan
                (trip_id, penjualan_id, driver_id, tujuan, foto_surat_jalan, status)
             VALUES
                (:trip_id, :penjualan_id, :driver_id, :tujuan, :foto, 'terkirim')"
        );
        $insert->execute([
            'trip_id' => $trip['id'],
            'penjualan_id' => $trip['penjualan_id'] ?? null,
            'driver_id' => $driverId,
            'tujuan' => $trip['destination'] ?? null,
            'foto' => $photoPath,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
