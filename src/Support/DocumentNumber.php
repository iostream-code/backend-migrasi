<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Generate nomor dokumen dari tabel `cfg_m_doc_number` (SHARED -- tabel ini
 * dipakai lintas modul, bukan cuma Inventory, mis. 'MAT'/'OPN'/'ADJ' sekarang,
 * modul lain bisa daftar doc_type baru di sana kapan saja). Port 1:1 dari
 * backend-production App\Services\Shared\DocumentNumberService (Eloquent) ke
 * PDO polos -- lihat file itu utk versi aslinya.
 *
 * Format pattern placeholder: {YY} {YYYY} {MM} dan {N...} (jumlah N menentukan
 * zero-pad length, mis. {NNNN} = 4 digit).
 *
 * PENTING: next()/syncToAtLeast() WAJIB dipanggil dari dalam transaksi PDO
 * yang sudah jalan (beginTransaction() sudah dipanggil pemanggil) -- method
 * ini sendiri TIDAK membuka transaksi, cuma mengandalkan `SELECT ... FOR
 * UPDATE` di dalamnya utk row-lock (butuh transaksi aktif supaya lock-nya
 * berarti apa-apa).
 */
class DocumentNumber
{
    public static function next(PDO $pdo, string $docType): string
    {
        $stmt = $pdo->prepare('SELECT * FROM cfg_m_doc_number WHERE doc_type = :t FOR UPDATE');
        $stmt->execute(['t' => $docType]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException("Doc type '{$docType}' tidak terdaftar di cfg_m_doc_number.");
        }

        $now = new \DateTimeImmutable();
        $lastReset = $row['last_reset_date'] ? new \DateTimeImmutable($row['last_reset_date']) : null;
        $needReset = self::needsReset($row['reset_period'], $lastReset, $now);

        $nextNumber = $needReset ? 1 : ((int) $row['last_number']) + 1;

        $upd = $pdo->prepare(
            'UPDATE cfg_m_doc_number SET last_number = :n, last_reset_date = :d, updated_at = :u WHERE id = :id'
        );
        $upd->execute([
            'n' => $nextNumber,
            'd' => $needReset ? $now->format('Y-m-d') : $row['last_reset_date'],
            'u' => $now->format('Y-m-d H:i:s'),
            'id' => $row['id'],
        ]);

        return self::formatNumber($row['format_pattern'], $nextNumber, $now);
    }

    /**
     * Paksa counter ke nilai tertentu jika counter saat ini lebih kecil --
     * dipakai sebelum next() supaya code hasil generate tidak bentrok dengan
     * data yang mungkin sudah ada duluan (row terbesar aktual di tabel data,
     * lihat pemanggil di MaterialController::generateUniqueCode() dan
     * OpnameController approval, sama seperti versi Laravel-nya).
     */
    public static function syncToAtLeast(PDO $pdo, string $docType, int $minNumber): void
    {
        $stmt = $pdo->prepare('SELECT * FROM cfg_m_doc_number WHERE doc_type = :t FOR UPDATE');
        $stmt->execute(['t' => $docType]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }

        $now = new \DateTimeImmutable();
        $upd = $pdo->prepare(
            'UPDATE cfg_m_doc_number SET last_number = :n, last_reset_date = :d, updated_at = :u WHERE id = :id'
        );
        $upd->execute([
            'n' => max($minNumber, (int) $row['last_number']),
            'd' => $now->format('Y-m-d'),
            'u' => $now->format('Y-m-d H:i:s'),
            'id' => $row['id'],
        ]);
    }

    private static function needsReset(string $resetPeriod, ?\DateTimeImmutable $lastReset, \DateTimeImmutable $now): bool
    {
        if ($lastReset === null) {
            return true; // pertama kali -> mulai dari 1
        }
        if ($resetPeriod === 'NONE') {
            return false;
        }
        if ($resetPeriod === 'MONTHLY') {
            return $lastReset->format('Y-m') !== $now->format('Y-m');
        }
        if ($resetPeriod === 'YEARLY') {
            return $lastReset->format('Y') !== $now->format('Y');
        }
        return false;
    }

    private static function formatNumber(string $pattern, int $number, \DateTimeImmutable $now): string
    {
        $result = str_replace(
            ['{YYYY}', '{YY}', '{MM}'],
            [$now->format('Y'), $now->format('y'), $now->format('m')],
            $pattern
        );

        return preg_replace_callback('/\{(N+)\}/', function ($m) use ($number) {
            return str_pad((string) $number, strlen($m[1]), '0', STR_PAD_LEFT);
        }, $result);
    }
}
