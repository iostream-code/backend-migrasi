<?php

declare(strict_types=1);

namespace App\Partner\Controllers;

use App\Controllers\Controller;
use App\Database;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Port dari backend-production App\Http\Controllers\API\Partner\PartnerController
 * (Eloquent/query builder) ke Slim/PDO polos. Response envelope dikutip apa
 * adanya dari sana ({success, data}, BUKAN {status:1|0} seperti modul
 * Inventory) supaya kompatibel persis dgn inventory-apk/src/pages/partner/partner.js
 * yang sudah ada.
 *
 * TIDAK diport di pass ini: getPartnerAppsIndex (POST /partner/get-partner-data,
 * dipakai partner-produksi-apk, app terpisah -- BUKAN inventory-apk),
 * approveCompletion, addPartnerTransactionPayment, updatePartnerTransaksiStatus,
 * deletePartner, getPartnerSummary -- semuanya TIDAK dipanggil inventory-apk
 * (dicek via grep src/pages/partner/partner.js), jadi di luar scope.
 */
class PartnerController extends Controller
{
    /**
     * GET /partner/
     * Port dari getPartnerTransactionIndex().
     */
    public function index(Request $request, Response $response): Response
    {
        $pdo = Database::connection();

        $stmt = $pdo->query(
            'SELECT
                pt.id_partner_transaksi, pt.id_partner, pt.tgl_deadline, pt.status, pt.status_produksi,
                pt.status_penerimaan, pt.status_approval, pt.status_bayar, pt.bukti_foto_pembayaran,
                pt.jumlah, pt.jumlah_diterima, pt.total_harga_produksi, pt.dt_record,
                pdp.penjualan_id, pdp.penjualan_jenis, p.nama_partner, p.kota,
                ph.penjualan_tanggal, pdp.penjualan_detail_performa_id, c.client_nama
             FROM partner_transaksi pt
             JOIN m_partner p ON pt.id_partner = p.id_partner
             JOIN t_penjualan_detail_performa pdp ON pt.penjualan_detail_performa_id = pdp.penjualan_detail_performa_id
             JOIN t_penjualan_header ph ON pdp.penjualan_id = ph.penjualan_id
             JOIN m_client c ON ph.client_id = c.client_id
             ORDER BY pt.id_partner_transaksi DESC'
        );
        $rows = $stmt->fetchAll();

        $ids = array_column($rows, 'id_partner_transaksi');
        $details = $this->fetchDetailsByTransaksi($pdo, $ids);
        $returByTransaksi = $this->fetchReturByTransaksi($pdo, $ids);

        $data = array_map(function ($row) use ($details, $returByTransaksi) {
            $id = (int) $row['id_partner_transaksi'];
            $returRows = $returByTransaksi[$id] ?? [];

            $totalReturAktif = 0;
            $totalReturSelesai = 0;
            foreach ($returRows as $r) {
                if (in_array($r['status_retur'], ['BELUM', 'PROSES'], true)) {
                    $totalReturAktif += (int) $r['jumlah_retur'];
                } elseif ($r['status_retur'] === 'SELESAI') {
                    $totalReturSelesai += (int) $r['jumlah_retur'];
                }
            }

            $row['details'] = $details[$id] ?? [];
            $row['retur_data'] = $returRows;
            $row['total_retur_aktif'] = $totalReturAktif;
            $row['total_retur_selesai'] = $totalReturSelesai;
            return $row;
        }, $rows);

        return $this->json($response, ['success' => true, 'data' => $data]);
    }

    /** @return array<int,array> keyed by id_partner_transaksi */
    private function fetchDetailsByTransaksi(PDO $pdo, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, nama, jumlah, harga, total_harga, foto_bukti_material, id_partner_transaksi,
                    id_kategori_material, id_material, dt_created, dt_modified
             FROM partner_transaksi_detail
             WHERE id_partner_transaksi IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id_partner_transaksi']][] = $row;
        }
        return $out;
    }

    /** @return array<int,array> keyed by id_partner_transaksi */
    private function fetchReturByTransaksi(PDO $pdo, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT
                dp.id_partner_transaksi, dp.id AS id_detail_pengiriman, dp.jumlah_diterima, dp.jumlah_retur,
                dr.id_retur, dr.tanggal_retur, dr.alasan_retur, dr.keterangan AS keterangan_retur,
                dr.status AS status_retur, dr.tanggal_diterima AS tanggal_diterima_retur,
                dr.jumlah_diterima AS jumlah_diterima_retur, dr.foto_bukti_retur, dr.foto_bukti_terima_retur
             FROM partner_detail_pengiriman dp
             LEFT JOIN partner_detail_retur dr ON dp.id = dr.id_detail_pengiriman
             WHERE dp.id_partner_transaksi IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id_partner_transaksi']][] = $row;
        }
        return $out;
    }
}
