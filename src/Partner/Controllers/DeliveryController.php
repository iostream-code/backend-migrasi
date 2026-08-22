<?php

declare(strict_types=1);

namespace App\Partner\Controllers;

use App\Controllers\Controller;
use App\Database;
use App\Support\PhotoStorage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Port dari backend-production App\Http\Controllers\API\Partner\DeliveryController
 * ke Slim/PDO polos. Response envelope {success, data} dikutip apa adanya.
 */
class DeliveryController extends Controller
{
    /**
     * POST /partner/delivery
     * Port dari getDeliveryIndex().
     */
    public function index(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $idPartnerTransaksi = (int) ($body['id_partner_transaksi'] ?? 0);
        if ($idPartnerTransaksi <= 0) {
            return $this->error($response, 'Validasi gagal: id_partner_transaksi wajib diisi.', 422);
        }

        $pdo = Database::connection();

        $headerStmt = $pdo->prepare(
            'SELECT p.nama_partner, pt.jumlah, pt.item, pj.penjualan_id, pj.penjualan_tanggal,
                    pdp.penjualan_detail_performa_id AS id_partner_transaksi_detail
             FROM partner_transaksi pt
             JOIN m_partner p ON pt.id_partner = p.id_partner
             LEFT JOIN t_penjualan_detail_performa pdp ON pt.penjualan_detail_performa_id = pdp.penjualan_detail_performa_id
             LEFT JOIN t_penjualan_header pj ON pdp.penjualan_id = pj.penjualan_id
             WHERE pt.id_partner_transaksi = :id
             LIMIT 1'
        );
        $headerStmt->execute(['id' => $idPartnerTransaksi]);
        $header = $headerStmt->fetch();

        if (!$header) {
            return $this->json($response, ['success' => false, 'message' => 'Transaksi partner tidak ditemukan'], 404);
        }

        $delStmt = $pdo->prepare('SELECT * FROM partner_detail_pengiriman WHERE id_partner_transaksi = :id ORDER BY dt_record DESC');
        $delStmt->execute(['id' => $idPartnerTransaksi]);
        $deliveries = $delStmt->fetchAll();

        $deliveryIds = array_column($deliveries, 'id');
        $returByDelivery = $this->fetchRelevantReturByDelivery($pdo, $deliveryIds);

        $appUrl = rtrim($_ENV['APP_URL'], '/');
        $deliveries = array_map(function ($d) use ($returByDelivery, $appUrl) {
            $retur = $returByDelivery[(int) $d['id']] ?? null;
            $d['bukti_penerimaan_url'] = $d['bukti_penerimaan'] ? $appUrl . '/' . $d['bukti_penerimaan'] : null;
            $d['bukti_dokumen_penerimaan_url'] = $d['bukti_dokumen_penerimaan'] ? $appUrl . '/' . $d['bukti_dokumen_penerimaan'] : null;
            $d['id_retur'] = $retur['id_retur'] ?? null;
            $d['status_retur'] = $retur['status'] ?? null;
            return $d;
        }, $deliveries);

        return $this->json($response, [
            'success' => true,
            'data' => ['data' => [$header], 'deliveries' => $deliveries],
            'total_deliveries' => count($deliveries),
        ]);
    }

    /**
     * Untuk tiap delivery: retur PROSES kalau ada (masih perlu ditindaklanjuti),
     * kalau tidak ada baru retur SELESAI terbaru. Port dari
     * DeliveryController@getDeliveryIndex ($returByDelivery groupBy+map).
     *
     * @return array<int,array> keyed by id_detail_pengiriman
     */
    private function fetchRelevantReturByDelivery(PDO $pdo, array $deliveryIds): array
    {
        if (empty($deliveryIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($deliveryIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT * FROM partner_detail_retur WHERE id_detail_pengiriman IN ({$placeholders}) ORDER BY dt_record DESC"
        );
        $stmt->execute($deliveryIds);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['id_detail_pengiriman']][] = $row;
        }

        $out = [];
        foreach ($grouped as $deliveryId => $rows) {
            $proses = null;
            foreach ($rows as $r) {
                if ($r['status'] === 'PROSES') {
                    $proses = $r;
                    break;
                }
            }
            $out[$deliveryId] = $proses ?? $rows[0];
        }
        return $out;
    }

    /**
     * POST /partner/delivery/add-delivery (multipart)
     * Port dari addDelivery().
     */
    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $idPartnerTransaksi = (int) ($body['id_partner_transaksi'] ?? 0);
        $tanggalDiterima = trim((string) ($body['tanggal_diterima'] ?? ''));
        $jumlahDiterima = (int) ($body['jumlah_diterima'] ?? 0);
        $jumlahBelumDiterima = (int) ($body['jumlah_belum_diterima'] ?? -1);
        $namaPenerima = trim((string) ($body['nama_penerima'] ?? ''));

        if ($idPartnerTransaksi <= 0 || $tanggalDiterima === '' || $jumlahDiterima < 1 || $jumlahBelumDiterima < 0 || $namaPenerima === '') {
            return $this->error($response, 'Validasi gagal: id_partner_transaksi, tanggal_diterima, jumlah_diterima, jumlah_belum_diterima, nama_penerima wajib diisi dengan benar.', 422);
        }

        $pdo = Database::connection();

        $chk = $pdo->prepare('SELECT 1 FROM partner_transaksi WHERE id_partner_transaksi = :id LIMIT 1');
        $chk->execute(['id' => $idPartnerTransaksi]);
        if (!$chk->fetch()) {
            return $this->error($response, 'Validasi gagal: id_partner_transaksi tidak ditemukan.', 422);
        }

        $pdo->beginTransaction();
        try {
            $baseDir = __DIR__ . '/../../../public/uploads/partner/penerimaan';
            $buktiPath = PhotoStorage::save($request, 'bukti_penerimaan', $baseDir, 'uploads/partner/penerimaan', 'penerimaan_' . time() . '_' . uniqid());
            $dokumenPath = PhotoStorage::save($request, 'bukti_dokumen_penerimaan', $baseDir, 'uploads/partner/penerimaan', 'dokumen_' . time() . '_' . uniqid());

            $now = date('Y-m-d H:i:s');
            $ins = $pdo->prepare(
                'INSERT INTO partner_detail_pengiriman
                    (id_partner_transaksi, tanggal_kirim, tanggal_diterima, jumlah_kirim, jumlah_diterima,
                     jumlah_belum_diterima, jumlah_rusak, jumlah_retur, nama_penerima, bukti_penerimaan,
                     bukti_dokumen_penerimaan, dt_record, dt_modified)
                 VALUES (:trx, :tgl1, :tgl2, :jkirim, :jterima, :jbelum, 0, 0, :nama, :bukti, :dok, :now1, :now2)'
            );
            $ins->execute([
                'trx' => $idPartnerTransaksi,
                'tgl1' => $tanggalDiterima,
                'tgl2' => $tanggalDiterima,
                'jkirim' => $jumlahDiterima,
                'jterima' => $jumlahDiterima,
                'jbelum' => $jumlahBelumDiterima,
                'nama' => $namaPenerima,
                'bukti' => $buktiPath,
                'dok' => $dokumenPath,
                'now1' => $now,
                'now2' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();

            $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(jumlah_diterima), 0) AS total FROM partner_detail_pengiriman WHERE id_partner_transaksi = :id');
            $totalStmt->execute(['id' => $idPartnerTransaksi]);
            $totalDiterima = (int) $totalStmt->fetchColumn();

            $pdo->prepare('UPDATE partner_transaksi SET jumlah_diterima = :t WHERE id_partner_transaksi = :id')
                ->execute(['t' => $totalDiterima, 'id' => $idPartnerTransaksi]);

            $pdo->commit();

            return $this->json($response, [
                'success' => true,
                'message' => 'Penerimaan berhasil ditambahkan',
                'data' => ['id' => $id],
            ], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
