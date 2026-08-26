<?php

declare(strict_types=1);

namespace App\Ekspedisi\Controllers;

use App\Controllers\Controller;
use App\Database;
use App\Support\DocumentNumber;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * SJ TARIK -- submenu "PO" di tab SJ (ekspedisi-apk), 2026-08-26.
 *
 * Beda TOTAL dari SuratJalanController.php di folder ini (submenu
 * "Customer", `ekspedisi_t_surat_jalan`, skema MILIK app ini sendiri) --
 * controller ini baca/tulis `pur_t_surat_jalan(_detail)` +
 * `pur_t_purchase_order(_detail)`, skema MILIK modul Purchase di
 * backend-production (Laravel). Ditaruh di modul Ekspedisi (bukan
 * Purchasing) krn 2 alasan: (1) `src/js/api.js` di ekspedisi-apk cuma bisa
 * manggil prefix '/ekspedisi' (hardcoded di request()/uploadFile()), nambah
 * modul kedua di FE cuma utk ini tidak sepadan; (2) secara domain ini soal
 * assignment supir+kendaraan utk SJ Tarik -- ranah Ekspedisi, bukan
 * Purchasing (harga/approval). Pola "modul Slim baca/tulis langsung tabel
 * pur_t_*" SUDAH ADA duluan di Inventory\StockInController (lihat file itu)
 * -- bukan preseden baru.
 *
 * **Kenapa fitur ini ada**: sebelumnya alur "Terbitkan SJ Jakarta" di
 * produksi-apk minta Jakarta isi no. SJ/pengemudi/plat/tanggal -- padahal
 * dokumen fisiknya terbit dari PUSAT. Diperbaiki (2026-08-26): SJ (dgn
 * detail pengiriman lengkap) sekarang dibuat & di-confirm DI SINI oleh tim
 * ekspedisi Pusat, Jakarta (produksi-apk, SuratJalanController::confirmJakarta())
 * cukup KONFIRMASI PENERIMAAN pakai foto begitu barang sampai.
 *
 * Alur status: DRAFT (dibuat di sini) -> SENT (confirm() di sini,
 * qty_shipped naik) -> RECEIVED (confirm-jakarta di produksi-apk,
 * qty_received naik, LUAR jangkauan controller ini sepenuhnya).
 */
class PoSuratJalanController extends Controller
{
    /**
     * GET /admin/sj-po/outstanding-po
     * PO status READY yang masih py sisa qty utk dikirim -- sumber data
     * form "Buat SJ Tarik". Port dari backend-production
     * SuratJalanController::outstandingPoDetails() (Eloquent) ke PDO polos,
     * bentuk response disamakan (po_id/po_number/supplier_name/items[]).
     * Query opsional: supplier_id.
     */
    public function outstandingPo(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $params = $request->getQueryParams();
        $supplierId = !empty($params['supplier_id']) ? (int) $params['supplier_id'] : null;

        $sql = "SELECT po.id, po.po_number, po.po_date, po.status, po.supplier_id,
                       s.supplier_nama AS supplier_name
                FROM pur_t_purchase_order po
                LEFT JOIN shared_m_supplier s ON s.supplier_id = po.supplier_id
                WHERE po.status = 'READY' AND po.deleted_at IS NULL";
        $args = [];
        if ($supplierId) {
            $sql .= ' AND po.supplier_id = :sup';
            $args['sup'] = $supplierId;
        }
        $sql .= ' ORDER BY po.supplier_id ASC, po.po_date ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $pos = $stmt->fetchAll();

        $result = [];
        foreach ($pos as $po) {
            $detStmt = $pdo->prepare(
                'SELECT pod.id AS po_detail_id, pod.material_id, pod.qty_ordered, pod.qty_shipped, pod.qty_received,
                        m.code AS material_code, m.name AS material_name, u.code AS unit_code
                 FROM pur_t_purchase_order_detail pod
                 LEFT JOIN wh_m_material m ON m.id = pod.material_id
                 LEFT JOIN shared_m_unit u ON u.id = m.unit_id
                 WHERE pod.purchase_order_id = :po'
            );
            $detStmt->execute(['po' => $po['id']]);

            $items = [];
            foreach ($detStmt->fetchAll() as $d) {
                $qtyShippable = max(0.0, (float) $d['qty_ordered'] - (float) $d['qty_shipped']);
                if ($qtyShippable <= 0) {
                    continue; // sudah full-shipped -- tidak relevan lagi di form Buat SJ
                }
                $items[] = [
                    'po_detail_id' => (int) $d['po_detail_id'],
                    'material_id' => (int) $d['material_id'],
                    'material_name' => $d['material_name'] ?? '-',
                    'unit_code' => $d['unit_code'] ?? '-',
                    'qty_ordered' => (float) $d['qty_ordered'],
                    'qty_shipped' => (float) $d['qty_shipped'],
                    'qty_shippable' => $qtyShippable,
                ];
            }
            if (empty($items)) {
                continue; // seluruh item PO ini sudah full-shipped
            }

            $result[] = [
                'po_id' => (int) $po['id'],
                'po_number' => $po['po_number'],
                'po_date' => $po['po_date'],
                'supplier_id' => $po['supplier_id'] !== null ? (int) $po['supplier_id'] : null,
                'supplier_name' => $po['supplier_name'] ?? '-',
                'items' => $items,
            ];
        }

        return $this->json($response, $result);
    }

    /**
     * GET /admin/sj-po
     * List SJ Tarik (pur_t_surat_jalan, sj_direction=IN) -- tabel submenu
     * "PO". Query opsional: status (CSV, mis. "DRAFT,SENT").
     */
    public function index(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $params = $request->getQueryParams();

        $sql = "SELECT sj.id, sj.sj_number, sj.sj_date, sj.status, sj.supplier_id, sj.transporter_name,
                       sj.vehicle_number, sj.sent_at, sj.received_at, sj.notes,
                       s.supplier_nama AS supplier_name
                FROM pur_t_surat_jalan sj
                LEFT JOIN shared_m_supplier s ON s.supplier_id = sj.supplier_id
                WHERE sj.sj_direction = 'IN' AND sj.deleted_at IS NULL";
        $args = [];
        if (!empty($params['status'])) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $params['status']))));
            if (!empty($statuses)) {
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $sql .= " AND sj.status IN ({$placeholders})";
                $args = $statuses;
            }
        }
        $sql .= ' ORDER BY sj.sj_date DESC, sj.id DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        return $this->json($response, $stmt->fetchAll());
    }

    /**
     * GET /admin/sj-po/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $id = (int) $args['id'];

        $stmt = $pdo->prepare(
            "SELECT sj.*, s.supplier_nama AS supplier_name
             FROM pur_t_surat_jalan sj
             LEFT JOIN shared_m_supplier s ON s.supplier_id = sj.supplier_id
             WHERE sj.id = :id AND sj.deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $sj = $stmt->fetch();
        if (!$sj) {
            return $this->error($response, 'Surat jalan tidak ditemukan.', 404);
        }

        $itemStmt = $pdo->prepare(
            'SELECT sjd.id, sjd.material_id, sjd.po_detail_id, sjd.qty, sjd.qty_received,
                    m.name AS material_name, u.code AS unit_code,
                    pod.purchase_order_id, po.po_number
             FROM pur_t_surat_jalan_detail sjd
             LEFT JOIN wh_m_material m ON m.id = sjd.material_id
             LEFT JOIN shared_m_unit u ON u.id = m.unit_id
             LEFT JOIN pur_t_purchase_order_detail pod ON pod.id = sjd.po_detail_id
             LEFT JOIN pur_t_purchase_order po ON po.id = pod.purchase_order_id
             WHERE sjd.surat_jalan_id = :id'
        );
        $itemStmt->execute(['id' => $id]);
        $sj['items'] = $itemStmt->fetchAll();

        return $this->json($response, $sj);
    }

    /**
     * POST /admin/sj-po
     * Bikin SJ Tarik baru (status DRAFT).
     * body: { driver_name (wajib), vehicle_number (wajib), notes?,
     *         items: [{po_detail_id, qty}, ...] (wajib, min 1) }
     *
     * `driver_name` teks bebas (BUKAN FK ke ekspedisi_m_supir -- kolom
     * pur_t_surat_jalan.transporter_name emang varchar polos). FE tetap
     * boleh isi field ini dari dropdown supir Ekspedisi (GET /admin/drivers,
     * endpoint yang SUDAH ADA) kalau mau, tinggal kirim namanya sbg string.
     *
     * Semua item WAJIB dari PO dgn supplier YANG SAMA (1 SJ = 1 supplier) --
     * kalau beda, buat SJ terpisah. Setiap po_detail_id divalidasi ULANG di
     * server (status PO harus masih READY, qty tidak melebihi sisa) --
     * jangan percaya begitu saja outstanding-po-details yang mungkin sudah
     * basi sejak form dibuka.
     */
    public function store(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $body = (array) $request->getParsedBody();

        $driverName = trim((string) ($body['driver_name'] ?? ''));
        if ($driverName === '') {
            return $this->error($response, 'Nama supir wajib diisi.');
        }
        $vehicleNumber = trim((string) ($body['vehicle_number'] ?? ''));
        if ($vehicleNumber === '') {
            return $this->error($response, 'Nomor kendaraan wajib diisi.');
        }

        $rawItems = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (empty($rawItems)) {
            return $this->error($response, 'Minimal 1 item harus dipilih.');
        }

        $pdo->beginTransaction();
        try {
            $lines = [];
            $supplierId = null;
            $supplierName = null;
            $warehouseId = null;

            foreach ($rawItems as $raw) {
                $podId = (int) ($raw['po_detail_id'] ?? 0);
                $qty = (float) ($raw['qty'] ?? 0);
                if ($podId <= 0 || $qty <= 0) {
                    continue;
                }

                $stmt = $pdo->prepare(
                    "SELECT pod.*, po.supplier_id, po.warehouse_id, po.status AS po_status
                     FROM pur_t_purchase_order_detail pod
                     JOIN pur_t_purchase_order po ON po.id = pod.purchase_order_id
                     WHERE pod.id = :id FOR UPDATE"
                );
                $stmt->execute(['id' => $podId]);
                $pod = $stmt->fetch();
                if (!$pod) {
                    throw new \RuntimeException("Item PO #{$podId} tidak ditemukan.");
                }
                if ($pod['po_status'] !== 'READY') {
                    throw new \RuntimeException("PO utk item #{$podId} sudah bukan status READY (sekarang: {$pod['po_status']}) -- kemungkinan sudah diproses SJ lain, refresh & coba lagi.");
                }

                $sisa = (float) $pod['qty_ordered'] - (float) $pod['qty_shipped'];
                if ($qty > $sisa + 0.0001) {
                    throw new \RuntimeException("Qty kirim item #{$podId} ({$qty}) melebihi sisa yang belum dikirim ({$sisa}).");
                }

                if ($supplierId === null) {
                    $supplierId = (int) $pod['supplier_id'];
                    $warehouseId = (int) $pod['warehouse_id'];
                } elseif ($supplierId !== (int) $pod['supplier_id']) {
                    throw new \RuntimeException('Semua item harus dari PO dengan supplier yang sama -- buat SJ terpisah untuk supplier lain.');
                }

                $lines[] = [
                    'po_detail_id' => $podId,
                    'purchase_order_id' => (int) $pod['purchase_order_id'],
                    'material_id' => (int) $pod['material_id'],
                    'unit_id' => $pod['unit_id'] !== null ? (int) $pod['unit_id'] : null,
                    'qty' => $qty,
                ];
            }

            if (empty($lines)) {
                throw new \RuntimeException('Minimal 1 item dengan qty > 0 harus dipilih.');
            }

            $sjNumber = DocumentNumber::next($pdo, 'SJ_PUR');
            $now = date('Y-m-d H:i:s');

            $ins = $pdo->prepare(
                "INSERT INTO pur_t_surat_jalan
                    (sj_number, sj_date, sj_direction, supplier_id, warehouse_id, transporter_name, vehicle_number, notes, status, created_by, created_at)
                 VALUES (:num, :now1, 'IN', :sup, :wh, :drv, :veh, :notes, 'DRAFT', :uid, :now2)"
            );
            $ins->execute([
                'num' => $sjNumber,
                'now1' => $now,
                'sup' => $supplierId,
                'wh' => $warehouseId,
                'drv' => $driverName,
                'veh' => $vehicleNumber,
                'notes' => isset($body['notes']) && trim((string) $body['notes']) !== '' ? trim((string) $body['notes']) : null,
                'uid' => (int) $request->getAttribute('user_id'),
                'now2' => $now,
            ]);
            $sjId = (int) $pdo->lastInsertId();

            $detIns = $pdo->prepare(
                'INSERT INTO pur_t_surat_jalan_detail (surat_jalan_id, purchase_order_id, material_id, po_detail_id, unit_id, qty, qty_received)
                 VALUES (:sj, :po, :mat, :pod, :unit, :qty, 0)'
            );
            foreach ($lines as $l) {
                $detIns->execute([
                    'sj' => $sjId,
                    'po' => $l['purchase_order_id'],
                    'mat' => $l['material_id'],
                    'pod' => $l['po_detail_id'],
                    'unit' => $l['unit_id'],
                    'qty' => $l['qty'],
                ]);
            }

            $pdo->commit();

            return $this->json($response, ['id' => $sjId, 'sj_number' => $sjNumber], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $this->error($response, $e->getMessage());
        }
    }

    /**
     * POST /admin/sj-po/{id}/confirm
     * DRAFT -> SENT: tandai SJ Tarik sudah benar-benar berangkat. Increment
     * qty_shipped tiap PO detail terkait + recalc status PO induk. Port dari
     * backend-production SuratJalanController::confirm() (Eloquent) ke PDO
     * polos.
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $id = (int) $args['id'];

        $stmt = $pdo->prepare('SELECT * FROM pur_t_surat_jalan WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
        $sj = $stmt->fetch();
        if (!$sj) {
            return $this->error($response, 'Surat jalan tidak ditemukan.', 404);
        }
        if ($sj['status'] !== 'DRAFT') {
            return $this->error($response, "Hanya SJ berstatus DRAFT yang bisa di-confirm. Status saat ini: {$sj['status']}.");
        }

        $itemStmt = $pdo->prepare('SELECT * FROM pur_t_surat_jalan_detail WHERE surat_jalan_id = :id');
        $itemStmt->execute(['id' => $id]);
        $items = $itemStmt->fetchAll();

        $pdo->beginTransaction();
        try {
            // Lock semua PO detail terkait dulu, validasi fail-fast SEBELUM
            // ada apa pun yang di-commit (sama pola dgn backend-production).
            $podIds = array_values(array_unique(array_filter(array_map(
                fn ($it) => $it['po_detail_id'] !== null ? (int) $it['po_detail_id'] : null,
                $items
            ))));
            $podMap = [];
            if (!empty($podIds)) {
                $placeholders = implode(',', array_fill(0, count($podIds), '?'));
                $lockStmt = $pdo->prepare("SELECT * FROM pur_t_purchase_order_detail WHERE id IN ({$placeholders}) FOR UPDATE");
                $lockStmt->execute($podIds);
                foreach ($lockStmt->fetchAll() as $row) {
                    $podMap[(int) $row['id']] = $row;
                }
            }

            $touchedPoIds = [];
            foreach ($items as $it) {
                if (empty($it['po_detail_id'])) {
                    continue;
                }
                $pod = $podMap[(int) $it['po_detail_id']] ?? null;
                if (!$pod) {
                    continue;
                }
                $newShipped = (float) $pod['qty_shipped'] + (float) $it['qty'];
                if ($newShipped > (float) $pod['qty_ordered'] + 0.0001) {
                    throw new \RuntimeException("Qty kirim untuk material #{$pod['material_id']} ({$newShipped}) melebihi qty PO ({$pod['qty_ordered']}).");
                }
                $touchedPoIds[(int) $pod['purchase_order_id']] = true;
            }

            $now = date('Y-m-d H:i:s');
            $upd = $pdo->prepare("UPDATE pur_t_surat_jalan SET status = 'SENT', sent_at = :now, updated_at = :now2 WHERE id = :id");
            $upd->execute(['now' => $now, 'now2' => $now, 'id' => $id]);

            foreach ($items as $it) {
                if (empty($it['po_detail_id'])) {
                    continue;
                }
                $incStmt = $pdo->prepare('UPDATE pur_t_purchase_order_detail SET qty_shipped = qty_shipped + :qty WHERE id = :id');
                $incStmt->execute(['qty' => $it['qty'], 'id' => $it['po_detail_id']]);
            }

            foreach (array_keys($touchedPoIds) as $poId) {
                $this->recalcPoStatus($pdo, $poId);
            }

            $pdo->commit();

            return $this->json($response, ['id' => $id, 'status' => 'SENT']);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $this->error($response, $e->getMessage());
        }
    }

    /**
     * Port dari backend-production SuratJalanController::recalcPoStatus()
     * -- VERSI SUDAH DIPERBAIKI (2026-08-26, lihat commit
     * fix/po-recalc-status-shipped di backend-production): pertimbangkan
     * qty_shipped (bukan cuma qty_received), jangan paksa status mundur ke
     * APPROVED kalau belum ada yg dikirim/diterima tapi statusnya sudah
     * manual APPROVED/READY, dan jangan resurrect status akhir
     * (CLOSED/REJECTED/CANCELLED). SENGAJA duplikat manual (bukan panggil
     * app Laravel lain) -- app ini murni Slim/PDO, pola "duplikasi bukan
     * trait" yang sudah dipraktikkan di seluruh workspace ini (lihat juga
     * Inventory\StockInController::recalculatePoStatus(), versi PARSIAL yg
     * juga sudah benar tapi ditulis sebelum bug qty_shipped ini
     * terdokumentasi -- confirm() controller Inventory itu tidak pernah
     * menyentuh qty_shipped jadi tidak pernah kena bug ini).
     */
    private function recalcPoStatus(PDO $pdo, int $poId): void
    {
        $poStmt = $pdo->prepare('SELECT status FROM pur_t_purchase_order WHERE id = :id');
        $poStmt->execute(['id' => $poId]);
        $currentStatus = $poStmt->fetchColumn();
        if ($currentStatus === false) {
            return;
        }
        if (in_array($currentStatus, ['CLOSED', 'REJECTED', 'CANCELLED'], true)) {
            return;
        }

        $detStmt = $pdo->prepare('SELECT qty_ordered, qty_shipped, qty_received, qty_returned FROM pur_t_purchase_order_detail WHERE purchase_order_id = :id');
        $detStmt->execute(['id' => $poId]);
        $items = $detStmt->fetchAll();
        if (empty($items)) {
            return;
        }

        $totalOrdered = 0.0;
        $totalReceived = 0.0;
        $totalShipped = 0.0;
        foreach ($items as $it) {
            $totalOrdered += (float) $it['qty_ordered'];
            $totalReceived += max(0.0, (float) $it['qty_received'] - (float) $it['qty_returned']);
            $totalShipped += (float) $it['qty_shipped'];
        }
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
            $newStatus = in_array($currentStatus, ['APPROVED', 'READY'], true) ? $currentStatus : 'APPROVED';
        }

        if ($newStatus !== $currentStatus) {
            $upd = $pdo->prepare('UPDATE pur_t_purchase_order SET status = :s, updated_at = :now WHERE id = :id');
            $upd->execute(['s' => $newStatus, 'now' => date('Y-m-d H:i:s'), 'id' => $poId]);
        }
    }
}
