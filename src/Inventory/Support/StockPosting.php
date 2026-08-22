<?php

declare(strict_types=1);

namespace App\Inventory\Support;

use InvalidArgumentException;
use PDO;

/**
 * SATU-SATUNYA tempat yang boleh INSERT ke wh_log_stock_mutation dan UPDATE
 * wh_t_stock_balance -- port 1:1 dari backend-production
 * App\Services\Shared\StockPostingService (Eloquent) ke PDO polos. Semua
 * modul yang menyentuh stok (Opname, StockIn, StockOut; Transfer nanti)
 * WAJIB lewat sini supaya WAC (weighted average cost) konsisten & balance
 * selalu benar.
 *
 * `decrement_outstanding_in`/`decrement_outstanding_out` (2026-08-22, susulan
 * StockIn/StockOut) -- port dari flag opsional yang sama di
 * StockPostingService asli, default 0 (tidak dipakai Opname sama sekali).
 *
 * PENTING: postIn()/postOut() WAJIB dipanggil di dalam transaksi PDO yang
 * sudah jalan (beginTransaction() sudah dipanggil pemanggil) -- class ini
 * TIDAK membuka transaksinya sendiri.
 */
class StockPosting
{
    /**
     * Post stok MASUK (qty_on_hand naik). Update avg_unit_cost pakai WAC.
     *
     * @param array{
     *   warehouse_id:int, material_id:int, qty:float, unit_cost?:float,
     *   transaction_type:string, reference_type?:string, reference_id?:int,
     *   reference_number?:string, remarks?:string, created_by?:int,
     *   decrement_outstanding_in?:float
     * } $data
     * @return int id baris wh_log_stock_mutation yang baru di-insert
     */
    public static function postIn(PDO $pdo, array $data): int
    {
        self::requirePositiveQty($data, 'postIn');

        $balance = self::lockOrCreateBalance($pdo, (int) $data['warehouse_id'], (int) $data['material_id']);
        $qty = (float) $data['qty'];
        $cost = (float) ($data['unit_cost'] ?? 0);

        $oldQty = (float) $balance['qty_on_hand'];
        $oldAvg = (float) $balance['avg_unit_cost'];
        $newQty = $oldQty + $qty;
        $newAvg = $newQty > 0 ? (($oldQty * $oldAvg) + ($qty * $cost)) / $newQty : $oldAvg;

        // Kalau receive PO (atau retur produksi masuk), outstanding_in berkurang
        // (barang yg ditunggu sudah datang) -- port dari
        // StockPostingService::postIn() asli, TIDAK dipakai Opname (default 0).
        $decIn = (float) ($data['decrement_outstanding_in'] ?? 0);
        $newOutstandingIn = $decIn > 0
            ? max(0, (float) $balance['qty_outstanding_in'] - $decIn)
            : (float) $balance['qty_outstanding_in'];

        $newAvailable = $newQty - (float) $balance['qty_outstanding_out'];

        $upd = $pdo->prepare(
            'UPDATE wh_t_stock_balance
             SET qty_on_hand = :qty, avg_unit_cost = :avg, qty_outstanding_in = :outin, qty_available = :avail, last_mutation_at = :now1, updated_at = :now2
             WHERE id = :id'
        );
        $now = date('Y-m-d H:i:s');
        $upd->execute(['qty' => $newQty, 'avg' => $newAvg, 'outin' => $newOutstandingIn, 'avail' => $newAvailable, 'now1' => $now, 'now2' => $now, 'id' => $balance['id']]);

        return self::insertMutation($pdo, $data, $qty, 0, $cost, $newQty);
    }

    /**
     * Post stok KELUAR (qty_on_hand turun). unit_cost keluaran pakai avg
     * cost SAAT INI (tidak mengubah WAC, cuma dicatat di ledger).
     *
     * @throws InvalidArgumentException kalau stok tidak cukup dan
     *         `allow_negative` (default false) tidak di-set true.
     */
    public static function postOut(PDO $pdo, array $data): int
    {
        self::requirePositiveQty($data, 'postOut');

        $balance = self::lockOrCreateBalance($pdo, (int) $data['warehouse_id'], (int) $data['material_id']);
        $qty = (float) $data['qty'];

        $oldQty = (float) $balance['qty_on_hand'];
        $newQty = $oldQty - $qty;

        if ($newQty < 0 && !($data['allow_negative'] ?? false)) {
            throw new InvalidArgumentException(
                "Stok tidak cukup untuk material #{$data['material_id']} di warehouse #{$data['warehouse_id']}. " .
                "On hand: {$oldQty}, diminta: {$qty}."
            );
        }

        $cost = (float) $balance['avg_unit_cost'];

        // Kalau issue ke produksi, outstanding_out berkurang (barang yg
        // direquest sudah keluar) -- port dari StockPostingService::postOut()
        // asli, TIDAK dipakai Opname (default 0).
        $decOut = (float) ($data['decrement_outstanding_out'] ?? 0);
        $newOutstandingOut = $decOut > 0
            ? max(0, (float) $balance['qty_outstanding_out'] - $decOut)
            : (float) $balance['qty_outstanding_out'];

        $newAvailable = $newQty - $newOutstandingOut;

        $upd = $pdo->prepare(
            'UPDATE wh_t_stock_balance
             SET qty_on_hand = :qty, qty_outstanding_out = :outout, qty_available = :avail, last_mutation_at = :now1, updated_at = :now2
             WHERE id = :id'
        );
        $now = date('Y-m-d H:i:s');
        $upd->execute(['qty' => $newQty, 'outout' => $newOutstandingOut, 'avail' => $newAvailable, 'now1' => $now, 'now2' => $now, 'id' => $balance['id']]);

        return self::insertMutation($pdo, $data, 0, $qty, $cost, $newQty);
    }

    private static function lockOrCreateBalance(PDO $pdo, int $warehouseId, int $materialId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM wh_t_stock_balance WHERE warehouse_id = :w AND material_id = :m FOR UPDATE'
        );
        $stmt->execute(['w' => $warehouseId, 'm' => $materialId]);
        $balance = $stmt->fetch();

        if ($balance) {
            return $balance;
        }

        $ins = $pdo->prepare(
            'INSERT INTO wh_t_stock_balance
                (warehouse_id, material_id, qty_on_hand, qty_outstanding_in, qty_outstanding_out, qty_available, avg_unit_cost, updated_at)
             VALUES (:w, :m, 0, 0, 0, 0, 0, :now)'
        );
        $ins->execute(['w' => $warehouseId, 'm' => $materialId, 'now' => date('Y-m-d H:i:s')]);
        $id = (int) $pdo->lastInsertId();

        $stmt2 = $pdo->prepare('SELECT * FROM wh_t_stock_balance WHERE id = :id');
        $stmt2->execute(['id' => $id]);
        return $stmt2->fetch();
    }

    private static function insertMutation(PDO $pdo, array $data, float $qtyIn, float $qtyOut, float $unitCost, float $balanceAfter): int
    {
        $ins = $pdo->prepare(
            'INSERT INTO wh_log_stock_mutation
                (mutation_date, warehouse_id, material_id, transaction_type, reference_type, reference_id,
                 reference_number, qty_in, qty_out, unit_cost, balance_after, remarks, created_by, created_at)
             VALUES
                (:mdate, :w, :m, :ttype, :rtype, :rid, :rnum, :qin, :qout, :cost, :bafter, :remarks, :createdBy, :now)'
        );
        $now = date('Y-m-d H:i:s');
        $ins->execute([
            'mdate' => $data['mutation_date'] ?? $now,
            'w' => $data['warehouse_id'],
            'm' => $data['material_id'],
            'ttype' => $data['transaction_type'],
            'rtype' => $data['reference_type'] ?? null,
            'rid' => $data['reference_id'] ?? null,
            'rnum' => $data['reference_number'] ?? null,
            'qin' => $qtyIn,
            'qout' => $qtyOut,
            'cost' => $unitCost,
            'bafter' => $balanceAfter,
            'remarks' => $data['remarks'] ?? null,
            'createdBy' => $data['created_by'] ?? null,
            'now' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function requirePositiveQty(array $data, string $method): void
    {
        if (!isset($data['qty']) || (float) $data['qty'] <= 0) {
            $got = $data['qty'] ?? 'null';
            throw new InvalidArgumentException("Qty harus > 0 untuk {$method}. Diterima: {$got}");
        }
    }
}
