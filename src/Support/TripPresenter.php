<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Format baris driver_t_trip jadi shape JSON yang dipakai frontend, dipakai
 * bareng oleh DriverController & AdminController supaya current_step_label
 * dihitung dengan cara yang sama persis di kedua tempat.
 */
class TripPresenter
{
    public const STEPS = ['berangkat', 'serah_terima', 'sj'];

    private const LABELS = [
        'berangkat' => 'Foto Berangkat',
        'serah_terima' => 'Serah Terima Barang',
        'sj' => 'Foto SJ',
    ];

    public static function completedSteps(PDO $pdo, int $tripId): array
    {
        $stmt = $pdo->prepare('SELECT type FROM driver_t_trip_photo WHERE trip_id = :trip_id');
        $stmt->execute(['trip_id' => $tripId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function nextStepLabel(array $completedSteps): string
    {
        foreach (self::STEPS as $step) {
            if (!in_array($step, $completedSteps, true)) {
                return self::LABELS[$step];
            }
        }
        return 'Selesai';
    }

    public static function format(PDO $pdo, array $trip): array
    {
        $completed = self::completedSteps($pdo, (int) $trip['id']);

        return [
            'id' => (int) $trip['id'],
            'destination' => $trip['destination'],
            'no_surat_jalan' => $trip['no_surat_jalan'] ?? null,
            'penjualan_id' => $trip['penjualan_id'] ?? null,
            'status' => $trip['status'],
            'completed_steps' => $completed,
            'current_step_label' => self::nextStepLabel($completed),
        ];
    }
}
