<?php

declare(strict_types=1);

namespace App\Purchasing\Controllers;

use App\Controllers\Controller;
use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Port dari backend-production App\Http\Controllers\API\PurchasingController
 * (getDataPurchase/updateFileFotoPurchasing/updateFileResinPurchasing SAJA --
 * ketiganya satu-satunya method di controller itu) ke Slim/PDO polos. Dipakai
 * inventory-apk halaman Logo (src/pages/logo/logo.js) utk tracking foto
 * stiker/resin barang custom sebelum kirim.
 *
 * Domainnya penjualan/produksi (t_penjualan_header, t_penjualan_detail_performa,
 * m_client), BUKAN partner ataupun inventory -- makanya modul sendiri
 * (src/Purchasing/), bukan digabung ke salah satu dari itu.
 *
 * Response envelope 3 method di bawah SENGAJA beda-beda satu sama lain
 * (dikutip apa adanya dari backend-production, bukan salah porting):
 * getDataPurchase -> {message, status:int, data, cabang_pembantu} (status di
 * body angka 200/404/500, BUKAN HTTP status code asli -- response HTTP-nya
 * selalu 200); upload -> {status:'done'|'failed'} (string, tanpa 'success').
 *
 * [DISEDERHANAKAN dari versi asli] Kolom yang di-SELECT dari
 * t_penjualan_header/t_penjualan_detail_performa/m_client dipangkas ke yang
 * BENAR-BENAR dibaca logo.js (grep dilakukan sebelum porting) -- versi asli
 * Eloquent implisit SELECT * ketiga tabel (80+ kolom gabungan) krn tidak ada
 * ->select() eksplisit sama sekali, bukan kontrak yang disengaja.
 */
class LogoController extends Controller
{
    /**
     * POST /purchasing/get-data-purchase
     */
    public function getDataPurchase(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $perusahaan = (string) ($body['perusahaan_purchase_value'] ?? 'empty');
        $type = (string) ($body['type_purchase_filter'] ?? 'empty');
        $warna = (string) ($body['warna_purchase_filter'] ?? 'empty');
        $bantuan = (string) ($body['bantuan_purchase_filter'] ?? 'empty');

        $pdo = Database::connection();

        $sql = "SELECT
                    ph.penjualan_id, ph.penjualan_tanggal, ph.penjualan_tanggal_kirim,
                    pdp.penjualan_detail_performa_id, pdp.penjualan_qty, pdp.penjualan_jenis,
                    pdp.bantuan_cabang, pdp.foto_purchase_logo_selesai, pdp.foto_resin_selesai,
                    c.client_nama
                FROM t_penjualan_header ph
                JOIN t_penjualan_detail_performa pdp ON pdp.penjualan_id = ph.penjualan_id
                JOIN m_client c ON c.client_id = ph.client_id
                WHERE (pdp.status_produksi = 'proses' OR pdp.status_produksi IS NULL OR pdp.status_produksi = 'body')";
        $params = [];

        if ($perusahaan !== 'empty' && $perusahaan !== '') {
            $sql .= ' AND c.client_nama LIKE :perusahaan';
            $params['perusahaan'] = "%{$perusahaan}%";
        }
        if ($type !== 'empty' && $type !== '') {
            $sql .= ' AND pdp.penjualan_jenis LIKE :type';
            $params['type'] = "%{$type}%";
        }
        if ($warna !== 'empty' && $warna !== '') {
            $sql .= ' AND pdp.produk_keterangan_kustom LIKE :warna';
            $params['warna'] = "%{$warna}%";
        }
        if ($bantuan === 'empty') {
            // no-op, semua baris
        } elseif ($bantuan === 'Surabaya') {
            $sql .= ' AND pdp.bantuan_cabang IS NULL';
        } else {
            $sql .= ' AND pdp.bantuan_cabang LIKE :bantuan';
            $params['bantuan'] = "%{$bantuan}%";
        }

        $sql .= ' ORDER BY ph.penjualan_id ASC, ph.penjualan_tanggal_kirim ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        // foto_purchase_logo_selesai/foto_resin_selesai di DB cuma nama file
        // bare (mis. "foto_purchasing_logo1735000000.jpg", lihat
        // updateFotoField() di bawah -- disimpan ke public/foto_purchasing_logo/
        // resp. public/foto_purchasing_resin/, TANPA folder ikut ditulis ke
        // kolom). Diubah jadi URL absolut di sini (pola sama dgn
        // DeliveryController::bukti_penerimaan_url) supaya logo.js bisa pakai
        // langsung sbg <img src> tanpa tahu path fisik di server.
        $appUrl = rtrim($_ENV['APP_URL'], '/');
        foreach ($data as &$row) {
            $row['foto_purchase_logo_selesai'] = $row['foto_purchase_logo_selesai']
                ? $appUrl . '/foto_purchasing_logo/' . $row['foto_purchase_logo_selesai']
                : null;
            $row['foto_resin_selesai'] = $row['foto_resin_selesai']
                ? $appUrl . '/foto_purchasing_resin/' . $row['foto_resin_selesai']
                : null;
        }
        unset($row);

        if (empty($data)) {
            return $this->json($response, [
                'message' => 'Data tidak ditemukan',
                'status' => 404,
                'data' => $data,
                'cabang_pembantu' => '',
            ]);
        }

        $cabangStmt = $pdo->query("SELECT * FROM cabang WHERE status = 0");
        $cabangPembantu = $cabangStmt ? $cabangStmt->fetchAll() : [];

        return $this->json($response, [
            'message' => 'Data berhasil di proses',
            'status' => 200,
            'data' => $data,
            'cabang_pembantu' => $cabangPembantu,
        ]);
    }

    /**
     * POST /purchasing/update-file-foto-purchasing
     * Body (form-urlencoded/multipart, TAPI foto-nya STRING base64 data-URI,
     * BUKAN file upload asli -- kontrak lama Cordova, lihat logo.js
     * onPhotoSelected()): file_foto_purchase, penjualan_detail_performa_id_foto_purchasing_logo.
     */
    public function updateFileFotoPurchasing(Request $request, Response $response): Response
    {
        return $this->updateFotoField(
            $request,
            $response,
            'file_foto_purchase',
            'penjualan_detail_performa_id_foto_purchasing_logo',
            'foto_purchase_logo_selesai',
            'foto_purchasing_logo',
            __DIR__ . '/../../../public/foto_purchasing_logo'
        );
    }

    /**
     * POST /purchasing/update-file-resin-purchasing
     */
    public function updateFileResinPurchasing(Request $request, Response $response): Response
    {
        return $this->updateFotoField(
            $request,
            $response,
            'file_foto_resin',
            'penjualan_detail_performa_id_foto_purchasing_resin',
            'foto_resin_selesai',
            'foto_purchasing_resin',
            __DIR__ . '/../../../public/foto_purchasing_resin'
        );
    }

    private function updateFotoField(
        Request $request,
        Response $response,
        string $fileField,
        string $idField,
        string $column,
        string $filePrefix,
        string $baseDir
    ): Response {
        $body = (array) $request->getParsedBody();
        $dataUri = (string) ($body[$fileField] ?? '');
        $performaId = (int) ($body[$idField] ?? 0);

        $fileName = '-';
        if ($dataUri !== '' && $dataUri !== 'null' && preg_match('/^data:image\/(\w+);base64,(.+)$/', $dataUri, $m)) {
            $ext = strtolower($m[1]);
            $raw = base64_decode(str_replace(' ', '+', $m[2]), true);
            if ($raw !== false) {
                if (!is_dir($baseDir)) {
                    mkdir($baseDir, 0755, true);
                }
                $fileName = $filePrefix . time() . '.' . $ext;
                file_put_contents("{$baseDir}/{$fileName}", $raw);
            }
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("UPDATE t_penjualan_detail_performa SET {$column} = :name WHERE penjualan_detail_performa_id = :id");
        $ok = $stmt->execute(['name' => $fileName, 'id' => $performaId]);

        return $this->json($response, ['status' => ($ok && $stmt->rowCount() > 0) ? 'done' : 'failed']);
    }
}
