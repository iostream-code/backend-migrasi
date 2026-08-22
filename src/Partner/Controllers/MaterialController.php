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
 * Port dari backend-production App\Http\Controllers\API\Partner\MaterialController
 * ke Slim/PDO polos. Response envelope {success, data} dikutip apa adanya.
 */
class MaterialController extends Controller
{
    /**
     * POST /partner/material
     * Port dari getMaterialByPartner().
     */
    public function index(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $idPartnerTransaksi = (int) ($body['id_partner_transaksi'] ?? 0);
        if ($idPartnerTransaksi <= 0) {
            return $this->error($response, 'Validasi gagal: id_partner_transaksi wajib diisi.', 422);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT id, nama, jumlah, harga, total_harga, id_kategori_material, id_material,
                    foto_bukti_material, dt_created, dt_modified
             FROM partner_transaksi_detail
             WHERE id_partner_transaksi = :id
             ORDER BY id ASC'
        );
        $stmt->execute(['id' => $idPartnerTransaksi]);
        $materials = $stmt->fetchAll();

        $grandTotal = 0;
        $materials = array_map(function ($m) use (&$grandTotal) {
            $grandTotal += (int) $m['total_harga'];
            $m['foto_bukti_material_url'] = $this->formatImageUrl($m['foto_bukti_material'], 'images/no-image.png');
            $m['has_photo'] = !empty($m['foto_bukti_material']);
            return $m;
        }, $materials);

        $infoStmt = $pdo->prepare(
            'SELECT p.nama_partner, p.kota, p.pic, p.no_hp, p.alamat,
                    pj.penjualan_id, pj.penjualan_tanggal, pj.penjualan_total_qty, pj.client_id,
                    pdp.penjualan_detail_performa_id, pdp.penjualan_qty, pdp.penjualan_jenis,
                    pt.tgl_deadline, pt.status, pt.status_produksi, pt.status_penerimaan, pt.item, pt.jumlah
             FROM partner_transaksi pt
             JOIN m_partner p ON pt.id_partner = p.id_partner
             LEFT JOIN t_penjualan_detail_performa pdp ON pt.penjualan_detail_performa_id = pdp.penjualan_detail_performa_id
             JOIN t_penjualan_header pj ON pdp.penjualan_id = pj.penjualan_id
             WHERE pt.id_partner_transaksi = :id
             LIMIT 1'
        );
        $infoStmt->execute(['id' => $idPartnerTransaksi]);
        $partnerInfo = $infoStmt->fetch() ?: null;

        $clientInfo = null;
        if ($partnerInfo && $partnerInfo['client_id']) {
            $clientStmt = $pdo->prepare(
                'SELECT client_nama, client_kota, client_telp, client_alamat FROM m_client WHERE client_id = :id'
            );
            $clientStmt->execute(['id' => $partnerInfo['client_id']]);
            $clientInfo = $clientStmt->fetch() ?: null;
        }

        return $this->json($response, [
            'success' => true,
            'data' => [
                'material' => $materials,
                'partner_info' => $partnerInfo,
                'client_info' => $clientInfo,
                'grand_total' => $grandTotal,
                'total_items' => count($materials),
            ],
        ]);
    }

    /**
     * POST /partner/material/add-partner-material (multipart)
     * Port dari addMaterialByPartner().
     */
    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $idPartnerTransaksi = (int) ($body['id_partner_transaksi'] ?? 0);
        $nama = trim((string) ($body['nama'] ?? ''));
        $jumlah = (int) ($body['jumlah'] ?? 0);
        $harga = (int) ($body['harga'] ?? 0);
        $idKategoriMaterial = !empty($body['id_kategori_material']) ? (int) $body['id_kategori_material'] : null;
        $idMaterial = !empty($body['id_material']) ? (int) $body['id_material'] : null;

        if ($idPartnerTransaksi <= 0 || $nama === '' || $jumlah < 1 || $harga < 0) {
            return $this->error($response, 'Validasi gagal: id_partner_transaksi, nama, jumlah, harga wajib diisi dengan benar.', 422);
        }

        $pdo = Database::connection();

        $chk = $pdo->prepare('SELECT 1 FROM partner_transaksi WHERE id_partner_transaksi = :id LIMIT 1');
        $chk->execute(['id' => $idPartnerTransaksi]);
        if (!$chk->fetch()) {
            return $this->error($response, 'Validasi gagal: id_partner_transaksi tidak ditemukan.', 422);
        }

        $pdo->beginTransaction();
        try {
            $totalHarga = $jumlah * $harga;
            $now = date('Y-m-d H:i:s');

            $ins = $pdo->prepare(
                'INSERT INTO partner_transaksi_detail
                    (id_partner_transaksi, nama, jumlah, harga, total_harga, id_kategori_material, id_material, dt_created, dt_modified)
                 VALUES (:trx, :nama, :jumlah, :harga, :total, :kat, :mat, :now1, :now2)'
            );
            $ins->execute([
                'trx' => $idPartnerTransaksi,
                'nama' => $nama,
                'jumlah' => $jumlah,
                'harga' => $harga,
                'total' => $totalHarga,
                'kat' => $idKategoriMaterial,
                'mat' => $idMaterial,
                'now1' => $now,
                'now2' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();

            $fotoPath = $this->uploadMaterialPhoto($request, $idPartnerTransaksi, $id);
            if ($fotoPath !== null) {
                $upd = $pdo->prepare('UPDATE partner_transaksi_detail SET foto_bukti_material = :p WHERE id = :id');
                $upd->execute(['p' => $fotoPath, 'id' => $id]);
            }

            $pdo->commit();

            return $this->json($response, [
                'success' => true,
                'message' => 'Material berhasil ditambahkan',
                'data' => ['id' => $id, 'total_harga' => $totalHarga, 'foto_bukti_material' => $fotoPath],
            ], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function uploadMaterialPhoto(Request $request, int $idPartnerTransaksi, int $detailId): ?string
    {
        $baseDir = __DIR__ . '/../../../public/uploads/partner/material';
        $name = "material_{$idPartnerTransaksi}_{$detailId}_" . time();
        return PhotoStorage::save($request, 'foto_bukti_material', $baseDir, 'uploads/partner/material', $name);
    }

    private function formatImageUrl(?string $path, ?string $default = null): string
    {
        if (!$path) {
            $fallback = $default ?? 'images/no-image.png';
            return rtrim($_ENV['APP_URL'], '/') . '/' . ltrim($fallback, '/');
        }
        $path = ltrim($path, '/');
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        return rtrim($_ENV['APP_URL'], '/') . '/' . $path;
    }
}
