<?php

declare(strict_types=1);

namespace App\Ekspedisi\Support;

use PDO;

/**
 * "SJ Tarik" (2026-08-29) -- CRUD ke `pur_t_surat_jalan`/`pur_t_surat_jalan_detail`,
 * tabel MILIK modul Purchase di `backend-production`, dibaca/ditulis LANGSUNG
 * dari sini (satu database produksi yang sama, pola SAMA PERSIS dgn
 * `Ekspedisi\Support\SuratJalan::markPenjualanSelesai()` yang nulis ke
 * `t_penjualan_header`) -- BUKAN lewat HTTP ke backend-production.
 *
 * Kenapa modul ini ada: submenu "PO" `ekspedisi-apk` (frontend) sudah lebih
 * dulu dibangun mengasumsikan endpoint `/admin/sj-po/*` ini ADA, tapi
 * `PoSuratJalanController` belum pernah dibuat di `backend-migrasi` -- modul
 * ini nge-port ulang logika `backend-production`
 * `app/Http/Controllers/API/Purchase/SuratJalanController.php` &
 * `PoReadinessController.php` (Laravel/Eloquent) jadi raw PDO, SUPAYA
 * PERILAKUNYA SAMA PERSIS (status lifecycle PO, validasi qty, dll) --
 * bukan desain baru dari nol. Baca 2 file itu kalau ada keraguan soal alasan
 * di balik satu baris query di sini, itu sumber kebenarannya.
 *
 * **`sj_direction` SELALU 'IN'** (barang MASUK ke gudang Pusat dari
 * supplier/gudang lain, ditarik oleh supir Ekspedisi) -- 'OUT' di skema ini
 * khusus utk retur balik ke supplier (`retur_purchase_id`), tidak relevan di
 * modul ini.
 *
 * **Nomor SJ pakai `SJ-PUR-{NNNNN}` via `cfg_m_doc_number`** (doc_type
 * `SJ_PUR`, row-nya SUDAH ADA di tabel produksi, dipakai bareng
 * `backend-production` `DocNumberHelper` -- counter SATU-SATUNYA, jadi nomor
 * TIDAK PERNAH bentrok mau lahir dari sisi mana pun). List/detail di modul
 * ini SENGAJA difilter `sj_number LIKE 'SJ-PUR-%'` (persis pola
 * `doc_type=SJ_PUR` di `backend-production`) -- supaya SJ biasa (dibuat
 * lewat `purchase-finance-apk`, prefix `SJ-` polos) tidak ikut nyampur di
 * sini, dua populasi baris yang beda tanggung jawab meski 1 tabel fisik.
 */
class PoSuratJalan
{
    /**
     * GET /admin/sj-po/approved-po -- PO status APPROVED (BELUM 'READY'),
     * dipakai kartu "PO Menunggu Siap Kirim" (`adminSuratJalanPo.js`).
     */
    public static function approvedPo(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT po.id AS po_id, po.po_number, po.supplier_id,
                    COALESCE(s.supplier_nama, s.code, '-') AS supplier_name
             FROM pur_t_purchase_order po
             LEFT JOIN shared_m_supplier s ON s.supplier_id = po.supplier_id
             WHERE po.deleted_at IS NULL AND po.status = 'APPROVED'
             ORDER BY po.po_date ASC"
        );
        $pos = $stmt->fetchAll();
        if (!$pos) {
            return [];
        }

        $itemsByPoId = self::batchPoItems($pdo, array_column($pos, 'po_id'));

        return array_map(static fn (array $po) => [
            'po_id' => (int) $po['po_id'],
            'po_number' => $po['po_number'],
            'supplier_name' => $po['supplier_name'],
            'items' => $itemsByPoId[(int) $po['po_id']] ?? [],
        ], $pos);
    }

    /**
     * Dipanggil PoSuratJalanController::markReady() -- port
     * `PoReadinessController::confirm()` (backend-production), TAPI
     * `qty_ready` per lini di-ISI OTOMATIS dari sisa outstanding (=
     * `qty_ordered` dikurangi total `qty_ready` yang PERNAH dikonfirmasi
     * sebelumnya) -- kartu "Siap Kirim" `ekspedisi-apk` cuma 1 tombol polos,
     * TIDAK ADA UI input qty per lini kayak versi Jakarta aslinya (di luar
     * scope MVP kartu ini), jadi defaultnya "semua sisa PO ini dianggap
     * siap sekaligus". `$photoPath` WAJIB (kolom `pur_t_po_readiness.photo_path`
     * NOT NULL) -- pemanggil (controller) yang urus upload filenya, di sini
     * cuma terima path/URL jadinya.
     *
     * @throws \InvalidArgumentException pesan siap ditampilkan ke user (validasi bisnis)
     */
    public static function markReady(PDO $pdo, int $poId, string $photoPath, ?int $userId): array
    {
        $poStmt = $pdo->prepare('SELECT id, po_number, status FROM pur_t_purchase_order WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $poStmt->execute(['id' => $poId]);
        $po = $poStmt->fetch();
        if (!$po) {
            throw new \InvalidArgumentException('Purchase Order tidak ditemukan.');
        }

        // Hanya APPROVED, READY, atau PARTIAL_RECEIVED yang bisa dikonfirmasi
        // (SAMA PERSIS dgn PoReadinessController::confirm()).
        if (!in_array($po['status'], ['APPROVED', 'READY', 'PARTIAL_RECEIVED'], true)) {
            throw new \InvalidArgumentException(
                "Status PO saat ini '{$po['status']}' tidak bisa dikonfirmasi siap. Hanya APPROVED, READY, atau PARTIAL_RECEIVED yang bisa."
            );
        }

        $detailStmt = $pdo->prepare('SELECT id, qty_ordered FROM pur_t_purchase_order_detail WHERE purchase_order_id = :po_id');
        $detailStmt->execute(['po_id' => $poId]);
        $details = $detailStmt->fetchAll();
        if (!$details) {
            throw new \InvalidArgumentException('PO ini tidak punya lini item.');
        }

        $detailIds = array_column($details, 'id');
        $placeholders = implode(',', array_fill(0, count($detailIds), '?'));
        $readyStmt = $pdo->prepare(
            "SELECT po_detail_id, SUM(qty_ready) AS total FROM pur_t_po_readiness_detail
             WHERE po_detail_id IN ($placeholders) GROUP BY po_detail_id"
        );
        $readyStmt->execute($detailIds);
        $readyTotals = [];
        foreach ($readyStmt->fetchAll() as $row) {
            $readyTotals[(int) $row['po_detail_id']] = (float) $row['total'];
        }

        // Lini yang MASIH ada sisa belum pernah dikonfirmasi siap -- itu yang
        // dimasukkan ke readiness baru ini (qty_ready = sisa outstandingnya).
        $items = [];
        foreach ($details as $d) {
            $detailId = (int) $d['id'];
            $outstanding = (float) $d['qty_ordered'] - ($readyTotals[$detailId] ?? 0.0);
            if ($outstanding > 0.0001) {
                $items[] = ['po_detail_id' => $detailId, 'qty_ready' => $outstanding];
            }
        }

        if (!$items) {
            throw new \InvalidArgumentException('Semua item pada PO ini sudah dikonfirmasi siap seluruhnya. Tidak perlu konfirmasi ulang.');
        }

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare(
                'INSERT INTO pur_t_po_readiness (purchase_order_id, confirmed_by, confirmed_at, photo_path, created_at, updated_at)
                 VALUES (:po_id, :user_id, :confirmed_at, :photo, :created_at, :updated_at)'
            )->execute([
                'po_id' => $poId,
                'user_id' => $userId,
                'confirmed_at' => $now,
                'photo' => $photoPath,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $readinessId = (int) $pdo->lastInsertId();

            $insertDetail = $pdo->prepare(
                'INSERT INTO pur_t_po_readiness_detail (readiness_id, po_detail_id, qty_ready, created_at)
                 VALUES (:readiness_id, :po_detail_id, :qty_ready, :created_at)'
            );
            foreach ($items as $item) {
                $insertDetail->execute([
                    'readiness_id' => $readinessId,
                    'po_detail_id' => $item['po_detail_id'],
                    'qty_ready' => $item['qty_ready'],
                    'created_at' => $now,
                ]);
            }

            $pdo->prepare(
                "UPDATE pur_t_purchase_order
                 SET status = 'READY', ready_at = :ready_at, ready_by = :ready_by,
                     latest_readiness_id = :readiness_id, updated_at = :updated_at
                 WHERE id = :id"
            )->execute([
                'ready_at' => $now,
                'ready_by' => $userId,
                'readiness_id' => $readinessId,
                'updated_at' => $now,
                'id' => $poId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['po_number' => $po['po_number'], 'status' => 'READY'];
    }

    /**
     * GET /admin/sj-po/outstanding-po -- port `outstandingPoDetails()`
     * (backend-production): PO status READY, cuma lini yang
     * `qty_shippable = max(0, qty_ordered - qty_shipped) > 0`, PO ikut
     * dikeluarkan seluruhnya kalau tidak ada lini shippable-nya sama sekali.
     */
    public static function outstandingPo(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT po.id AS po_id, po.po_number, po.supplier_id,
                    COALESCE(s.supplier_nama, s.code, '-') AS supplier_name
             FROM pur_t_purchase_order po
             LEFT JOIN shared_m_supplier s ON s.supplier_id = po.supplier_id
             WHERE po.deleted_at IS NULL AND po.status = 'READY'
             ORDER BY po.supplier_id ASC, po.po_date ASC"
        );
        $pos = $stmt->fetchAll();
        if (!$pos) {
            return [];
        }

        $itemsByPoId = self::batchPoItems($pdo, array_column($pos, 'po_id'));

        $result = [];
        foreach ($pos as $po) {
            $poId = (int) $po['po_id'];
            $shippable = [];
            foreach ($itemsByPoId[$poId] ?? [] as $item) {
                $qtyShippable = max(0.0, $item['qty_ordered'] - $item['qty_shipped']);
                if ($qtyShippable > 0) {
                    $shippable[] = [
                        'po_detail_id' => $item['po_detail_id'],
                        'material_name' => $item['material_name'],
                        'unit_code' => $item['unit_code'],
                        'qty_shippable' => $qtyShippable,
                    ];
                }
            }
            if ($shippable) {
                $result[] = [
                    'po_id' => $poId,
                    'po_number' => $po['po_number'],
                    'supplier_name' => $po['supplier_name'],
                    'items' => $shippable,
                ];
            }
        }

        return $result;
    }

    /**
     * Dipanggil batch dari approvedPo()/outstandingPo() -- 1 query gabungan
     * material+unit utk SEMUA PO sekaligus (pola sama dgn
     * `Ekspedisi\Support\SuratJalan::batchItemsByRowId()`), bukan N+1 per PO.
     * @return array<int, array<int, array>> purchase_order_id => baris lini item
     */
    private static function batchPoItems(PDO $pdo, array $poIds): array
    {
        if (!$poIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($poIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT d.id AS po_detail_id, d.purchase_order_id, d.material_id, d.unit_id,
                    d.qty_ordered, d.qty_shipped,
                    COALESCE(m.name, '-') AS material_name, COALESCE(u.code, '-') AS unit_code
             FROM pur_t_purchase_order_detail d
             LEFT JOIN wh_m_material m ON m.id = d.material_id
             LEFT JOIN shared_m_unit u ON u.id = d.unit_id
             WHERE d.purchase_order_id IN ($placeholders)
             ORDER BY d.id"
        );
        $stmt->execute($poIds);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['purchase_order_id']][] = [
                'po_detail_id' => (int) $row['po_detail_id'],
                'material_name' => $row['material_name'],
                'unit_code' => $row['unit_code'],
                'qty_ordered' => (float) $row['qty_ordered'],
                'qty_shipped' => (float) $row['qty_shipped'],
            ];
        }

        return $grouped;
    }

    /**
     * POST /admin/sj-po -- port `store()` (backend-production). `$data`:
     * `items` (wajib, min 1, `[{po_detail_id, qty}, ...]`), `driver_name`,
     * `vehicle_number`, `notes`, `created_by`.
     *
     * **1 SJ = 1 PO (MVP, SAMA dgn keputusan FE `adminNewSuratJalanPo.js`)**
     * -- divalidasi ULANG di sini (bukan cuma percaya FE): semua
     * `po_detail_id` di `items` harus mengarah ke `purchase_order_id` yang
     * SAMA, exception kalau tidak.
     *
     * **TIDAK memvalidasi qty terhadap sisa outstanding di sini** (SAMA
     * PERSIS dgn `store()` backend-production) -- itu sengaja ditunda sampai
     * confirm() (dgn `SELECT ... FOR UPDATE`, race-safe), supaya DRAFT boleh
     * dibuat bebas dulu (mis. 2 SJ draft dari PO yang sama), baru divalidasi
     * beneran pas salah satunya mau di-confirm jadi SENT.
     *
     * @throws \InvalidArgumentException pesan siap ditampilkan ke user
     */
    public static function create(PDO $pdo, array $data): array
    {
        $items = $data['items'] ?? [];
        if (!$items) {
            throw new \InvalidArgumentException('items wajib diisi minimal 1.');
        }

        $pdo->beginTransaction();
        try {
            $poId = null;
            $podRows = [];
            foreach ($items as $item) {
                $podId = (int) ($item['po_detail_id'] ?? 0);
                $qty = (float) ($item['qty'] ?? 0);
                if ($podId <= 0 || $qty <= 0) {
                    throw new \InvalidArgumentException('po_detail_id dan qty (> 0) wajib diisi tiap item.');
                }

                $podStmt = $pdo->prepare('SELECT * FROM pur_t_purchase_order_detail WHERE id = :id LIMIT 1');
                $podStmt->execute(['id' => $podId]);
                $pod = $podStmt->fetch();
                if (!$pod) {
                    throw new \InvalidArgumentException("PO detail #{$podId} tidak ditemukan.");
                }

                if ($poId === null) {
                    $poId = (int) $pod['purchase_order_id'];
                } elseif ($poId !== (int) $pod['purchase_order_id']) {
                    throw new \InvalidArgumentException('Semua item harus dari PO yang sama (1 SJ = 1 PO).');
                }

                $podRows[$podId] = $pod;
            }

            $poStmt = $pdo->prepare('SELECT * FROM pur_t_purchase_order WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $poStmt->execute(['id' => $poId]);
            $po = $poStmt->fetch();
            if (!$po) {
                throw new \InvalidArgumentException('Purchase Order tidak ditemukan.');
            }

            $sjNumber = self::nextDocNumber($pdo, 'SJ_PUR');
            $now = date('Y-m-d H:i:s');

            $pdo->prepare(
                "INSERT INTO pur_t_surat_jalan
                    (sj_number, sj_date, sj_direction, supplier_id, warehouse_id, transporter_name,
                     vehicle_number, notes, status, created_at, created_by)
                 VALUES
                    (:sj_number, :sj_date, 'IN', :supplier_id, :warehouse_id, :transporter_name,
                     :vehicle_number, :notes, 'DRAFT', :created_at, :created_by)"
            )->execute([
                'sj_number' => $sjNumber,
                'sj_date' => $now,
                'supplier_id' => $po['supplier_id'],
                'warehouse_id' => $po['warehouse_id'],
                'transporter_name' => $data['driver_name'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_at' => $now,
                'created_by' => $data['created_by'] ?? null,
            ]);
            $sjId = (int) $pdo->lastInsertId();

            $detailInsert = $pdo->prepare(
                'INSERT INTO pur_t_surat_jalan_detail
                    (surat_jalan_id, purchase_order_id, material_id, po_detail_id, unit_id, qty, qty_received)
                 VALUES
                    (:surat_jalan_id, :purchase_order_id, :material_id, :po_detail_id, :unit_id, :qty, 0)'
            );
            foreach ($items as $item) {
                $podId = (int) $item['po_detail_id'];
                $pod = $podRows[$podId];
                $detailInsert->execute([
                    'surat_jalan_id' => $sjId,
                    'purchase_order_id' => $poId,
                    'material_id' => $pod['material_id'],
                    'po_detail_id' => $podId,
                    'unit_id' => $pod['unit_id'],
                    'qty' => (float) $item['qty'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['id' => $sjId, 'sj_number' => $sjNumber];
    }

    /**
     * GET /admin/sj-po -- `$statuses` array kosong = semua status.
     * Difilter `sj_direction='IN'` + `sj_number LIKE 'SJ-PUR-%'` (lihat
     * docblock kelas) supaya SJ biasa punya `purchase-finance-apk` tidak
     * ikut nyampur. LIMIT 200 polos tanpa pagination (lihat docblock FE
     * `adminSuratJalanPo.js` -- volume masih jauh dari situ, MVP).
     */
    public static function list(PDO $pdo, array $statuses): array
    {
        $where = ["sj.deleted_at IS NULL", "sj.sj_direction = 'IN'", "sj.sj_number LIKE 'SJ-PUR-%'"];
        $params = [];

        if ($statuses) {
            $placeholders = [];
            foreach ($statuses as $i => $status) {
                $key = "status{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $status;
            }
            $where[] = 'sj.status IN (' . implode(',', $placeholders) . ')';
        }

        $stmt = $pdo->prepare(
            "SELECT sj.id, sj.sj_number, sj.sj_date, sj.transporter_name, sj.vehicle_number,
                    sj.status, sj.sent_at, sj.received_at, sj.notes,
                    COALESCE(s.supplier_nama, s.code, '-') AS supplier_name
             FROM pur_t_surat_jalan sj
             LEFT JOIN shared_m_supplier s ON s.supplier_id = sj.supplier_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY sj.sj_date DESC, sj.id DESC
             LIMIT 200'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * GET /admin/sj-po/{id}.
     */
    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT sj.*, COALESCE(s.supplier_nama, s.code, '-') AS supplier_name
             FROM pur_t_surat_jalan sj
             LEFT JOIN shared_m_supplier s ON s.supplier_id = sj.supplier_id
             WHERE sj.id = :id AND sj.deleted_at IS NULL AND sj.sj_number LIKE 'SJ-PUR-%'
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['items'] = self::detailRows($pdo, $id);

        return $row;
    }

    /**
     * Baris `pur_t_surat_jalan_detail` + material/unit -- dipakai find()
     * (ditampilkan apa adanya ke FE) DAN confirm() (butuh `po_detail_id`/
     * `purchase_order_id`/`qty` mentahnya jg, bukan cuma yg ditampilkan).
     */
    private static function detailRows(PDO $pdo, int $sjId): array
    {
        $stmt = $pdo->prepare(
            "SELECT d.id, d.po_detail_id, d.purchase_order_id, d.material_id, d.unit_id, d.qty,
                    COALESCE(m.name, '-') AS material_name, COALESCE(u.code, '-') AS unit_code
             FROM pur_t_surat_jalan_detail d
             LEFT JOIN wh_m_material m ON m.id = d.material_id
             LEFT JOIN shared_m_unit u ON u.id = d.unit_id
             WHERE d.surat_jalan_id = :id
             ORDER BY d.id"
        );
        $stmt->execute(['id' => $sjId]);

        return $stmt->fetchAll();
    }

    /**
     * POST /admin/sj-po/{id}/confirm -- port `confirm()` (backend-production):
     * DRAFT -> SENT. Validasi qty_shipped kumulatif vs qty_ordered per lini
     * PO, dgn `SELECT ... FOR UPDATE` (race-safe kalau ada SJ draft LAIN dari
     * PO yang sama di-confirm nyaris bersamaan) -- FAIL-FAST, tidak ada yang
     * ke-commit kalau SATU SAJA lini melebihi qty PO. Sukses: increment
     * `qty_shipped` per lini + `recalcPoStatus()`.
     *
     * @throws \InvalidArgumentException status SJ tidak valid (bukan DRAFT / tidak ketemu)
     * @throws \RuntimeException qty melebihi sisa PO (fail-fast di dalam transaction)
     */
    public static function confirm(PDO $pdo, int $id, ?int $userId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM pur_t_surat_jalan WHERE id = :id AND deleted_at IS NULL AND sj_number LIKE 'SJ-PUR-%' LIMIT 1");
        $stmt->execute(['id' => $id]);
        $sj = $stmt->fetch();
        if (!$sj) {
            throw new \InvalidArgumentException('Surat Jalan tidak ditemukan.');
        }
        if ($sj['status'] !== 'DRAFT') {
            throw new \InvalidArgumentException("Hanya SJ berstatus DRAFT yang bisa di-confirm. Status saat ini: {$sj['status']}.");
        }

        $details = self::detailRows($pdo, $id);
        $podIds = array_values(array_unique(array_filter(array_map(
            static fn (array $d) => (int) $d['po_detail_id'],
            $details
        ))));

        $pdo->beginTransaction();
        try {
            $podMap = [];
            if ($podIds) {
                $placeholders = implode(',', array_fill(0, count($podIds), '?'));
                $lockStmt = $pdo->prepare("SELECT * FROM pur_t_purchase_order_detail WHERE id IN ($placeholders) FOR UPDATE");
                $lockStmt->execute($podIds);
                foreach ($lockStmt->fetchAll() as $row) {
                    $podMap[(int) $row['id']] = $row;
                }

                foreach ($details as $detail) {
                    $podId = (int) $detail['po_detail_id'];
                    if (!$podId || !isset($podMap[$podId])) {
                        continue;
                    }
                    $pod = $podMap[$podId];
                    $currentShipped = (float) $pod['qty_shipped'];
                    $qtyOrdered = (float) $pod['qty_ordered'];
                    $newShipped = $currentShipped + (float) $detail['qty'];

                    if ($newShipped > $qtyOrdered + 0.0001) {
                        throw new \RuntimeException(
                            "Qty kirim untuk material #{$pod['material_id']} ({$newShipped}) melebihi qty PO ({$qtyOrdered}). Sudah terkirim: {$currentShipped}."
                        );
                    }
                }
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare(
                "UPDATE pur_t_surat_jalan SET status = 'SENT', sent_at = :sent_at, updated_at = :updated_at, updated_by = :user_id WHERE id = :id"
            )->execute(['sent_at' => $now, 'updated_at' => $now, 'user_id' => $userId, 'id' => $id]);

            $poIdsToRecalc = [];
            foreach ($details as $detail) {
                $podId = (int) $detail['po_detail_id'];
                if (!$podId) {
                    continue;
                }
                $pdo->prepare('UPDATE pur_t_purchase_order_detail SET qty_shipped = qty_shipped + :qty WHERE id = :id')
                    ->execute(['qty' => (float) $detail['qty'], 'id' => $podId]);
                $poIdsToRecalc[(int) $detail['purchase_order_id']] = true;
            }
            foreach (array_keys($poIdsToRecalc) as $recalcPoId) {
                self::recalcPoStatus($pdo, $recalcPoId);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['id' => $id, 'status' => 'SENT'];
    }

    /**
     * Port `recalcPoStatus()` (backend-production, PENTING baca komentar
     * aslinya di sana -- ada riwayat bug fix 2026-08-26 yang alasannya
     * dipertahankan PERSIS di sini): jangan resurrect status akhir/manual
     * (CLOSED/REJECTED/CANCELLED); kalau belum ada yang shipped/received sama
     * sekali, PERTAHANKAN status manual yang sudah dicapai (APPROVED/READY)
     * alih-alih dipaksa balik ke APPROVED.
     */
    private static function recalcPoStatus(PDO $pdo, ?int $poId): void
    {
        if (!$poId) {
            return;
        }

        $poStmt = $pdo->prepare('SELECT status FROM pur_t_purchase_order WHERE id = :id LIMIT 1');
        $poStmt->execute(['id' => $poId]);
        $po = $poStmt->fetch();
        if (!$po) {
            return;
        }

        if (in_array($po['status'], ['CLOSED', 'REJECTED', 'CANCELLED'], true)) {
            return;
        }

        $sumStmt = $pdo->prepare(
            'SELECT SUM(qty_ordered) AS total_ordered, SUM(qty_shipped) AS total_shipped,
                    SUM(GREATEST(0, qty_received - qty_returned)) AS total_received
             FROM pur_t_purchase_order_detail WHERE purchase_order_id = :id'
        );
        $sumStmt->execute(['id' => $poId]);
        $sums = $sumStmt->fetch();

        $totalOrdered = (float) ($sums['total_ordered'] ?? 0);
        $totalShipped = (float) ($sums['total_shipped'] ?? 0);
        $totalReceived = (float) ($sums['total_received'] ?? 0);

        if ($totalOrdered <= 0) {
            return;
        }

        if ($totalReceived >= $totalOrdered) {
            $newStatus = 'RECEIVED';
        } elseif ($totalReceived > 0) {
            $newStatus = 'PARTIAL_RECEIVED';
        } elseif ($totalShipped > 0) {
            $newStatus = 'SENT';
        } else {
            $newStatus = in_array($po['status'], ['APPROVED', 'READY'], true) ? $po['status'] : 'APPROVED';
        }

        if ($po['status'] !== $newStatus) {
            $pdo->prepare('UPDATE pur_t_purchase_order SET status = :status, updated_at = :now WHERE id = :id')
                ->execute(['status' => $newStatus, 'now' => date('Y-m-d H:i:s'), 'id' => $poId]);
        }
    }

    /**
     * Port `DocNumberHelper::generate()` (backend-production, Laravel) --
     * lock+increment `cfg_m_doc_number` (row `SJ_PUR` SUDAH ADA di tabel
     * produksi, format `SJ-PUR-{NNNNN}`, reset bulanan). Counter SATU
     * SATUNYA dipakai bareng backend-production supaya nomor tidak pernah
     * bentrok. WAJIB dipanggil di DALAM transaction yang sudah dibuka
     * pemanggil (create()) -- fungsi ini TIDAK buka transaction sendiri
     * (PDO tidak dukung nested transaction sungguhan).
     */
    private static function nextDocNumber(PDO $pdo, string $docType): string
    {
        $stmt = $pdo->prepare('SELECT * FROM cfg_m_doc_number WHERE doc_type = :doc_type FOR UPDATE');
        $stmt->execute(['doc_type' => $docType]);
        $cfg = $stmt->fetch();
        if (!$cfg) {
            throw new \RuntimeException("Doc type {$docType} tidak terdaftar di cfg_m_doc_number.");
        }

        $now = new \DateTimeImmutable();
        $resetPeriod = strtoupper($cfg['reset_period']);
        $lastResetDate = $cfg['last_reset_date'];
        $needReset = self::shouldResetDocNumber($resetPeriod, $lastResetDate, $now);
        $nextNumber = $needReset ? 1 : ((int) $cfg['last_number'] + 1);

        $pdo->prepare('UPDATE cfg_m_doc_number SET last_number = :n, last_reset_date = :d, updated_at = :now WHERE id = :id')
            ->execute([
                'n' => $nextNumber,
                'd' => $needReset ? $now->format('Y-m-d') : $lastResetDate,
                'now' => $now->format('Y-m-d H:i:s'),
                'id' => $cfg['id'],
            ]);

        return self::formatDocNumber($cfg['format_pattern'], $now, $nextNumber);
    }

    private static function shouldResetDocNumber(string $resetPeriod, ?string $lastResetDate, \DateTimeImmutable $now): bool
    {
        if ($resetPeriod === 'NONE' || !$lastResetDate) {
            return !$lastResetDate;
        }
        $last = new \DateTimeImmutable($lastResetDate);

        return $resetPeriod === 'MONTHLY'
            ? $last->format('Ym') !== $now->format('Ym')
            : $last->format('Y') !== $now->format('Y');
    }

    private static function formatDocNumber(string $pattern, \DateTimeImmutable $now, int $number): string
    {
        return strtr($pattern, [
            '{YY}' => $now->format('y'),
            '{YYYY}' => $now->format('Y'),
            '{MM}' => $now->format('m'),
            '{DD}' => $now->format('d'),
            '{NNNNN}' => str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            '{NNNN}' => str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            '{NNN}' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
        ]);
    }
}
