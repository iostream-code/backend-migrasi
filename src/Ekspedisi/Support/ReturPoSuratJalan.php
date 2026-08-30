<?php

declare(strict_types=1);

namespace App\Ekspedisi\Support;

use PDO;

/**
 * "Retur PO" (2026-08-30, BARU -- rombak alur Retur/PO) -- CRUD ke
 * `pur_t_retur_purchase`/`pur_t_retur_purchase_detail` DAN
 * `pur_t_surat_jalan`/`pur_t_surat_jalan_detail`, tabel-tabel MILIK modul
 * Purchase di `backend-production`, dibaca/ditulis LANGSUNG dari sini (satu
 * database produksi yang sama) -- pola SAMA PERSIS dgn sibling-nya
 * `PoSuratJalan.php` (baca docblock kelas itu utk konteks lengkap kenapa
 * pola ini dipakai, bukan HTTP ke backend-production).
 *
 * Nge-port perilaku `PurchaseReturService` (backend-production, khususnya
 * approve()/reject() yang menghasilkan status APPROVED) jadi raw PDO utk
 * bagian "jadwalkan pengiriman balik" -- bagian approve/reject retur
 * ITU SENDIRI tetap di backend-production (dipakai produksi-apk User Pusat,
 * lihat PurchaseReturController/Service di sana), modul ini HANYA menangani
 * langkah SETELAH retur APPROVED: Admin Ekspedisi menjadwalkan &
 * "mengirim" pengganti balik ke gudang.
 *
 * **`sj_direction` SELALU 'IN'** (barang pengganti MASUK ke gudang asal
 * retur, dari supplier) -- SAMA PERSIS alasannya dgn `PoSuratJalan.php`.
 * Baris SJ di sini DIBEDAKAN dari SJ-PO biasa lewat kolom `retur_purchase_id`
 * (header, terisi kalau semua item dari 1 retur) dan/atau
 * `pur_t_surat_jalan_detail.retur_detail_id` (per lini) -- keduanya sudah
 * ada di skema sejak awal utk keperluan ini persis.
 *
 * **1 SJ = 1 Retur (MVP, konsisten dgn PoSuratJalan::create())**.
 *
 * **Input SJ = bukti terima, 1 langkah** (sama pola dgn PoSuratJalan::create()
 * versi rombak 2026-08-30): SJ langsung `status='RECEIVED'`/
 * `'PARTIAL_RECEIVED'`, bukan 'DRAFT'. Bump `qty_replaced` di
 * `pur_t_retur_purchase_detail` LANGSUNG saat SJ dibuat (bukan nunggu
 * confirm terpisah), lalu recalc status retur (REPLACED/PARTIAL_REPLACED).
 *
 * **Nomor SJ pakai seri `SJ_PUR` yang SAMA dgn SJ-PO biasa** (bukan seri
 * baru) -- dibedakan lewat `retur_purchase_id`/`retur_detail_id` di query,
 * bukan prefix nomor. Menghindari perlu seed row `cfg_m_doc_number` baru.
 */
class ReturPoSuratJalan
{
    /**
     * GET /admin/sj-retur-po/outstanding-po -- retur status APPROVED +
     * retur_action=REPLACEMENT (disposisi lain tidak ada pengiriman fisik
     * balik yang perlu dijadwalkan), cuma lini yang masih ada sisa
     * `qty_returned - qty_replaced > 0`.
     */
    public static function outstandingRetur(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT r.id AS retur_id, r.retur_number, r.supplier_id, r.purchase_order_id,
                    COALESCE(s.supplier_nama, s.code, '-') AS supplier_name,
                    po.po_number
             FROM pur_t_retur_purchase r
             LEFT JOIN shared_m_supplier s ON s.supplier_id = r.supplier_id
             LEFT JOIN pur_t_purchase_order po ON po.id = r.purchase_order_id
             WHERE r.deleted_at IS NULL AND r.status = 'APPROVED' AND r.retur_action = 'REPLACEMENT'
             ORDER BY r.retur_date ASC"
        );
        $returs = $stmt->fetchAll();
        if (!$returs) {
            return [];
        }

        $itemsByReturId = self::batchReturItems($pdo, array_column($returs, 'retur_id'));

        $result = [];
        foreach ($returs as $r) {
            $returId = (int) $r['retur_id'];
            $outstanding = [];
            foreach ($itemsByReturId[$returId] ?? [] as $item) {
                $qtyOutstanding = max(0.0, $item['qty_returned'] - $item['qty_replaced']);
                if ($qtyOutstanding > 0) {
                    $outstanding[] = [
                        'retur_detail_id' => $item['retur_detail_id'],
                        'material_name' => $item['material_name'],
                        'unit_code' => $item['unit_code'],
                        'qty_outstanding' => $qtyOutstanding,
                    ];
                }
            }
            if ($outstanding) {
                $result[] = [
                    'retur_id' => $returId,
                    'retur_number' => $r['retur_number'],
                    'supplier_name' => $r['supplier_name'],
                    'po_number' => $r['po_number'],
                    'items' => $outstanding,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<int, array>> retur_purchase_id => baris lini item
     */
    private static function batchReturItems(PDO $pdo, array $returIds): array
    {
        if (!$returIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($returIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT d.id AS retur_detail_id, d.retur_purchase_id, d.material_id, d.unit_id, d.po_detail_id,
                    d.qty_returned, d.qty_replaced,
                    COALESCE(m.name, '-') AS material_name, COALESCE(u.code, '-') AS unit_code
             FROM pur_t_retur_purchase_detail d
             LEFT JOIN wh_m_material m ON m.id = d.material_id
             LEFT JOIN shared_m_unit u ON u.id = d.unit_id
             WHERE d.retur_purchase_id IN ($placeholders)
             ORDER BY d.id"
        );
        $stmt->execute($returIds);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['retur_purchase_id']][] = [
                'retur_detail_id' => (int) $row['retur_detail_id'],
                'material_id' => (int) $row['material_id'],
                'unit_id' => $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
                'po_detail_id' => $row['po_detail_id'] !== null ? (int) $row['po_detail_id'] : null,
                'material_name' => $row['material_name'],
                'unit_code' => $row['unit_code'],
                'qty_returned' => (float) $row['qty_returned'],
                'qty_replaced' => (float) $row['qty_replaced'],
            ];
        }

        return $grouped;
    }

    /**
     * POST /admin/sj-retur-po -- buat SJ retur SEKALIGUS bukti terima, 1
     * langkah (pola sama dgn PoSuratJalan::create() versi rombak). `$data`:
     * `items` (wajib, min 1, `[{retur_detail_id, qty}, ...]` -- qty yang
     * BENAR-BENAR diterima), `driver_name`, `vehicle_number`, `notes`,
     * `receive_photo_path` (wajib), `created_by`.
     *
     * @throws \InvalidArgumentException pesan siap ditampilkan ke user (validasi input)
     * @throws \RuntimeException qty melebihi sisa retur (fail-fast di dalam transaction)
     */
    public static function createAndReceive(PDO $pdo, array $data): array
    {
        $items = $data['items'] ?? [];
        if (!$items) {
            throw new \InvalidArgumentException('items wajib diisi minimal 1.');
        }
        if (empty($data['receive_photo_path'])) {
            throw new \InvalidArgumentException('Foto bukti terima wajib diunggah.');
        }

        $detailIds = [];
        foreach ($items as $item) {
            $detailId = (int) ($item['retur_detail_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            if ($detailId <= 0 || $qty <= 0) {
                throw new \InvalidArgumentException('retur_detail_id dan qty (> 0) wajib diisi tiap item.');
            }
            $detailIds[] = $detailId;
        }

        $pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($detailIds), '?'));
            $lockStmt = $pdo->prepare("SELECT * FROM pur_t_retur_purchase_detail WHERE id IN ($placeholders) FOR UPDATE");
            $lockStmt->execute($detailIds);
            $detailRows = [];
            foreach ($lockStmt->fetchAll() as $row) {
                $detailRows[(int) $row['id']] = $row;
            }

            $returId = null;
            foreach ($items as $item) {
                $detailId = (int) $item['retur_detail_id'];
                $detail = $detailRows[$detailId] ?? null;
                if (!$detail) {
                    throw new \InvalidArgumentException("Retur detail #{$detailId} tidak ditemukan.");
                }

                if ($returId === null) {
                    $returId = (int) $detail['retur_purchase_id'];
                } elseif ($returId !== (int) $detail['retur_purchase_id']) {
                    throw new \InvalidArgumentException('Semua item harus dari retur yang sama (1 SJ = 1 Retur).');
                }

                $qty = (float) $item['qty'];
                $qtyReturned = (float) $detail['qty_returned'];
                $qtyReplaced = (float) $detail['qty_replaced'];
                $qtyOutstanding = max(0.0, $qtyReturned - $qtyReplaced);
                if ($qty > $qtyOutstanding + 0.0001) {
                    throw new \RuntimeException(
                        "Qty terima untuk material #{$detail['material_id']} ({$qty}) melebihi sisa retur ({$qtyOutstanding})."
                    );
                }
            }

            $returStmt = $pdo->prepare('SELECT * FROM pur_t_retur_purchase WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $returStmt->execute(['id' => $returId]);
            $retur = $returStmt->fetch();
            if (!$retur) {
                throw new \InvalidArgumentException('Retur tidak ditemukan.');
            }
            if ($retur['status'] !== 'APPROVED') {
                throw new \InvalidArgumentException("Retur berstatus '{$retur['status']}' tidak bisa dijadwalkan. Hanya APPROVED.");
            }

            $sjNumber = self::nextDocNumber($pdo, 'SJ_PUR');
            $now = date('Y-m-d H:i:s');

            $pdo->prepare(
                "INSERT INTO pur_t_surat_jalan
                    (sj_number, sj_date, sj_direction, retur_purchase_id, supplier_id, warehouse_id,
                     transporter_name, vehicle_number, notes, receive_photo_path, status, sent_at, received_at, created_at, created_by)
                 VALUES
                    (:sj_number, :sj_date, 'IN', :retur_purchase_id, :supplier_id, :warehouse_id,
                     :transporter_name, :vehicle_number, :notes, :receive_photo_path, 'RECEIVED', :sent_at, :received_at, :created_at, :created_by)"
            )->execute([
                'sj_number' => $sjNumber,
                'sj_date' => $now,
                'retur_purchase_id' => $returId,
                'supplier_id' => $retur['supplier_id'],
                'warehouse_id' => $retur['warehouse_id'],
                'transporter_name' => $data['driver_name'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'receive_photo_path' => $data['receive_photo_path'],
                'sent_at' => $now,
                'received_at' => $now,
                'created_at' => $now,
                'created_by' => $data['created_by'] ?? null,
            ]);
            $sjId = (int) $pdo->lastInsertId();

            $detailInsert = $pdo->prepare(
                'INSERT INTO pur_t_surat_jalan_detail
                    (surat_jalan_id, purchase_order_id, material_id, po_detail_id, retur_detail_id, unit_id, qty, qty_received)
                 VALUES
                    (:surat_jalan_id, :purchase_order_id, :material_id, :po_detail_id, :retur_detail_id, :unit_id, :qty, :qty)'
            );
            $bumpStmt = $pdo->prepare(
                'UPDATE pur_t_retur_purchase_detail SET qty_replaced = qty_replaced + :qty WHERE id = :id'
            );
            foreach ($items as $item) {
                $detailId = (int) $item['retur_detail_id'];
                $detail = $detailRows[$detailId];
                $qty = (float) $item['qty'];
                $detailInsert->execute([
                    'surat_jalan_id' => $sjId,
                    'purchase_order_id' => $retur['purchase_order_id'],
                    'material_id' => $detail['material_id'],
                    'po_detail_id' => $detail['po_detail_id'],
                    'retur_detail_id' => $detailId,
                    'unit_id' => $detail['unit_id'],
                    'qty' => $qty,
                ]);
                $bumpStmt->execute(['qty' => $qty, 'id' => $detailId]);
            }

            self::recalcReturStatus($pdo, $returId);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['id' => $sjId, 'sj_number' => $sjNumber];
    }

    /**
     * Port `recalcPoStatus()` (PoSuratJalan.php, alasan sama) diterapkan ke
     * retur: jangan resurrect status akhir/manual (CLOSED/REJECTED/
     * CANCELLED); REPLACED kalau semua lini qty_replaced >= qty_returned,
     * PARTIAL_REPLACED kalau sebagian, biarkan APPROVED kalau belum ada
     * yang direplace sama sekali.
     */
    private static function recalcReturStatus(PDO $pdo, ?int $returId): void
    {
        if (!$returId) {
            return;
        }

        $returStmt = $pdo->prepare('SELECT status FROM pur_t_retur_purchase WHERE id = :id LIMIT 1');
        $returStmt->execute(['id' => $returId]);
        $retur = $returStmt->fetch();
        if (!$retur) {
            return;
        }

        if (in_array($retur['status'], ['CLOSED', 'REJECTED', 'CANCELLED'], true)) {
            return;
        }

        $sumStmt = $pdo->prepare(
            'SELECT SUM(qty_returned) AS total_returned, SUM(qty_replaced) AS total_replaced
             FROM pur_t_retur_purchase_detail WHERE retur_purchase_id = :id'
        );
        $sumStmt->execute(['id' => $returId]);
        $sums = $sumStmt->fetch();

        $totalReturned = (float) ($sums['total_returned'] ?? 0);
        $totalReplaced = (float) ($sums['total_replaced'] ?? 0);

        if ($totalReturned <= 0) {
            return;
        }

        if ($totalReplaced >= $totalReturned) {
            $newStatus = 'REPLACED';
        } elseif ($totalReplaced > 0) {
            $newStatus = 'PARTIAL_REPLACED';
        } else {
            $newStatus = 'APPROVED';
        }

        if ($retur['status'] !== $newStatus) {
            $pdo->prepare('UPDATE pur_t_retur_purchase SET status = :status, updated_at = :now WHERE id = :id')
                ->execute(['status' => $newStatus, 'now' => date('Y-m-d H:i:s'), 'id' => $returId]);
        }
    }

    /**
     * GET /admin/sj-retur-po -- `$statuses` array kosong = semua status.
     * Difilter `sj_direction='IN'` + baris yang TERTAUT retur
     * (`retur_purchase_id IS NOT NULL` -- SETIAP baris SJ dari modul ini
     * SELALU mengisi header ini, beda dari PO biasa yang selalu NULL di
     * situ, lihat PoSuratJalan::create()) supaya SJ-PO biasa tidak ikut
     * nyampur di sini.
     */
    public static function list(PDO $pdo, array $statuses): array
    {
        $where = ["sj.deleted_at IS NULL", "sj.sj_direction = 'IN'", "sj.retur_purchase_id IS NOT NULL"];
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
                    sj.status, sj.sent_at, sj.received_at, sj.notes, sj.receive_photo_path,
                    r.retur_number,
                    COALESCE(s.supplier_nama, s.code, '-') AS supplier_name
             FROM pur_t_surat_jalan sj
             LEFT JOIN pur_t_retur_purchase r ON r.id = sj.retur_purchase_id
             LEFT JOIN shared_m_supplier s ON s.supplier_id = sj.supplier_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY sj.sj_date DESC, sj.id DESC
             LIMIT 200'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * GET /admin/sj-retur-po/{id}.
     */
    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT sj.*, r.retur_number, COALESCE(s.supplier_nama, s.code, '-') AS supplier_name
             FROM pur_t_surat_jalan sj
             LEFT JOIN pur_t_retur_purchase r ON r.id = sj.retur_purchase_id
             LEFT JOIN shared_m_supplier s ON s.supplier_id = sj.supplier_id
             WHERE sj.id = :id AND sj.deleted_at IS NULL AND sj.retur_purchase_id IS NOT NULL
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $stmt2 = $pdo->prepare(
            "SELECT d.id, d.retur_detail_id, d.material_id, d.unit_id, d.qty,
                    COALESCE(m.name, '-') AS material_name, COALESCE(u.code, '-') AS unit_code
             FROM pur_t_surat_jalan_detail d
             LEFT JOIN wh_m_material m ON m.id = d.material_id
             LEFT JOIN shared_m_unit u ON u.id = d.unit_id
             WHERE d.surat_jalan_id = :id
             ORDER BY d.id"
        );
        $stmt2->execute(['id' => $id]);
        $row['items'] = $stmt2->fetchAll();

        return $row;
    }

    /**
     * Port `DocNumberHelper::generate()` -- SAMA PERSIS dgn
     * `PoSuratJalan::nextDocNumber()` (duplikasi sengaja, class terpisah,
     * lihat konvensi codebase ini soal duplikasi kecil vs trait).
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
