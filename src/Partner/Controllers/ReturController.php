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
 * Port dari backend-production App\Http\Controllers\API\Partner\ReturController
 * ke Slim/PDO polos. Response envelope {status, message, data} (KEY 'status'
 * BOOLEAN, BUKAN 'success' seperti Partner/Material/Delivery controller
 * sibling-nya) dikutip apa adanya -- inventory-apk/partner.js cek `res.status`
 * murni utk endpoint retur ini, beda dari endpoint Partner lain yang cek
 * `res.success`. Inkonsistensi ini SUDAH ADA di backend-production, bukan
 * salah porting.
 */
class ReturController extends Controller
{
    private const ALASAN_VALID = ['RUSAK', 'CACAT_PRODUKSI', 'TIDAK_SESUAI_SPEK', 'SALAH_KIRIM', 'LAINNYA'];

    /**
     * POST /partner/retur/input-retur (multipart)
     * Port dari inputRetur().
     */
    public function inputRetur(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $idDetailPengiriman = (int) ($body['id_detail_pengiriman'] ?? 0);
        $tanggalRetur = trim((string) ($body['tanggal_retur'] ?? ''));
        $jumlahRetur = (int) ($body['jumlah_retur'] ?? 0);
        $alasanRetur = (string) ($body['alasan_retur'] ?? '');
        $keterangan = self::nullableString($body['keterangan'] ?? null);
        $biayaRetur = isset($body['biaya_retur']) && $body['biaya_retur'] !== '' ? (int) $body['biaya_retur'] : 0;
        $username = trim((string) ($body['username'] ?? ''));

        if (
            $idDetailPengiriman <= 0 || $tanggalRetur === '' || $jumlahRetur < 1
            || !in_array($alasanRetur, self::ALASAN_VALID, true) || $username === '' || $biayaRetur < 0
        ) {
            return $this->json($response, ['status' => false, 'message' => 'Validasi gagal'], 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM partner_detail_pengiriman WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $idDetailPengiriman]);
            $detailPengiriman = $stmt->fetch();
            if (!$detailPengiriman) {
                throw new \RuntimeException('Data pengiriman tidak ditemukan');
            }

            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(jumlah_retur), 0) FROM partner_detail_retur WHERE id_detail_pengiriman = :id');
            $sumStmt->execute(['id' => $idDetailPengiriman]);
            $totalReturSebelumnya = (int) $sumStmt->fetchColumn();

            $sisaYangBisaDiretur = (int) $detailPengiriman['jumlah_diterima'] - $totalReturSebelumnya;
            if ($jumlahRetur > $sisaYangBisaDiretur) {
                throw new \RuntimeException("Jumlah retur melebihi sisa yang bisa diretur. Sisa: {$sisaYangBisaDiretur}");
            }

            $baseDir = __DIR__ . '/../../../public/uploads/retur';
            $fotoPath = PhotoStorage::save($request, 'foto_bukti_retur', $baseDir, 'uploads/retur', 'retur_' . time() . '_' . uniqid());

            $now = date('Y-m-d H:i:s');
            $ins = $pdo->prepare(
                'INSERT INTO partner_detail_retur
                    (id_detail_pengiriman, tanggal_retur, jumlah_retur, alasan_retur, keterangan, foto_bukti_retur,
                     biaya_retur, status, jumlah_diterima, user_record, dt_record)
                 VALUES (:id, :tgl, :jml, :alasan, :ket, :foto, :biaya, :status, 0, :user, :now)'
            );
            $ins->execute([
                'id' => $idDetailPengiriman,
                'tgl' => $tanggalRetur,
                'jml' => $jumlahRetur,
                'alasan' => $alasanRetur,
                'ket' => $keterangan,
                'foto' => $fotoPath,
                'biaya' => $biayaRetur,
                'status' => 'PROSES',
                'user' => $username,
                'now' => $now,
            ]);
            $idRetur = (int) $pdo->lastInsertId();

            $totalReturBaru = $totalReturSebelumnya + $jumlahRetur;
            $jumlahDiterimaBaru = (int) $detailPengiriman['jumlah_diterima'] - $jumlahRetur;

            $pdo->prepare(
                'UPDATE partner_detail_pengiriman SET jumlah_retur = :tr, jumlah_diterima = :jd, dt_modified = :now WHERE id = :id'
            )->execute(['tr' => $totalReturBaru, 'jd' => $jumlahDiterimaBaru, 'now' => $now, 'id' => $idDetailPengiriman]);

            $pdo->commit();

            return $this->json($response, [
                'status' => true,
                'message' => 'Data retur berhasil disimpan',
                'data' => ['id_retur' => $idRetur, 'jumlah_retur' => $jumlahRetur, 'total_retur' => $totalReturBaru],
            ], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->json($response, ['status' => false, 'message' => 'Gagal menyimpan data retur: ' . $e->getMessage()], 500);
            }
            throw $e;
        }
    }

    /**
     * POST /partner/retur/input-penerimaan-retur (multipart)
     * Port dari inputPenerimaanRetur().
     */
    public function inputPenerimaanRetur(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $idRetur = (int) ($body['id_retur'] ?? 0);
        $tanggalDiterima = trim((string) ($body['tanggal_diterima'] ?? ''));
        $jumlahDiterima = (int) ($body['jumlah_diterima'] ?? 0);
        $username = trim((string) ($body['username'] ?? ''));

        if ($idRetur <= 0 || $tanggalDiterima === '' || $jumlahDiterima < 1 || $username === '') {
            return $this->json($response, ['status' => false, 'message' => 'Validasi gagal'], 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM partner_detail_retur WHERE id_retur = :id FOR UPDATE');
            $stmt->execute(['id' => $idRetur]);
            $retur = $stmt->fetch();
            if (!$retur) {
                throw new \RuntimeException('Data retur tidak ditemukan');
            }
            if ($retur['status'] !== 'PROSES') {
                throw new \RuntimeException('Retur belum dikirim atau sudah selesai');
            }

            $sisaReturBelumDiterima = (int) $retur['jumlah_retur'] - (int) $retur['jumlah_diterima'];
            if ($jumlahDiterima > $sisaReturBelumDiterima) {
                throw new \RuntimeException("Jumlah diterima melebihi sisa retur yang belum diterima. Sisa: {$sisaReturBelumDiterima}");
            }

            $baseDir = __DIR__ . '/../../../public/uploads/terima_retur';
            $fotoPath = PhotoStorage::save($request, 'foto_bukti_terima_retur', $baseDir, 'uploads/terima_retur', 'terima_retur_' . time() . '_' . uniqid());

            $totalJumlahDiterima = (int) $retur['jumlah_diterima'] + $jumlahDiterima;
            $status = $totalJumlahDiterima >= (int) $retur['jumlah_retur'] ? 'SELESAI' : 'PROSES';
            $now = date('Y-m-d H:i:s');

            $sql = 'UPDATE partner_detail_retur SET tanggal_diterima = :tgl, jumlah_diterima = :jd, status = :status, user_modified = :user, dt_modified = :now';
            $params = ['tgl' => $tanggalDiterima, 'jd' => $totalJumlahDiterima, 'status' => $status, 'user' => $username, 'now' => $now, 'id' => $idRetur];
            if ($fotoPath !== null) {
                $sql .= ', foto_bukti_terima_retur = :foto';
                $params['foto'] = $fotoPath;
            }
            $sql .= ' WHERE id_retur = :id';
            $pdo->prepare($sql)->execute($params);

            if ($status === 'SELESAI') {
                $this->reconcileDeliveryAfterReturDone($pdo, (int) $retur['id_detail_pengiriman'], (int) $retur['jumlah_retur'], $now);
            }

            $dpStmt = $pdo->prepare('SELECT jumlah_retur, jumlah_diterima FROM partner_detail_pengiriman WHERE id = :id');
            $dpStmt->execute(['id' => $retur['id_detail_pengiriman']]);
            $detailPengiriman = $dpStmt->fetch() ?: ['jumlah_retur' => 0, 'jumlah_diterima' => 0];

            $pdo->commit();

            return $this->json($response, [
                'status' => true,
                'message' => 'Data penerimaan retur berhasil disimpan',
                'data' => [
                    'id_retur' => $idRetur,
                    'jumlah_diterima_sekarang' => $jumlahDiterima,
                    'total_jumlah_diterima_retur' => $totalJumlahDiterima,
                    'jumlah_retur' => (int) $retur['jumlah_retur'],
                    'sisa_belum_diterima' => (int) $retur['jumlah_retur'] - $totalJumlahDiterima,
                    'status' => $status,
                    'foto_bukti_terima_retur' => $fotoPath,
                    'detail_pengiriman' => [
                        'jumlah_retur_aktif' => (int) $detailPengiriman['jumlah_retur'],
                        'jumlah_diterima' => (int) $detailPengiriman['jumlah_diterima'],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->json($response, ['status' => false, 'message' => 'Gagal menyimpan data penerimaan retur: ' . $e->getMessage()], 500);
            }
            throw $e;
        }
    }

    /**
     * Kalau retur SELESAI: kembalikan qty penuh retur ini ke jumlah_diterima
     * pengiriman, & set jumlah_retur pengiriman ke total retur yg MASIH
     * BELUM/PROSES (bukan 0 polos -- delivery bisa punya beberapa retur
     * terpisah). Port persis dari inputPenerimaanRetur() asli.
     */
    private function reconcileDeliveryAfterReturDone(PDO $pdo, int $idDetailPengiriman, int $returJumlahReturTotal, string $now): void
    {
        $belumStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(jumlah_retur), 0) FROM partner_detail_retur
             WHERE id_detail_pengiriman = :id AND status IN ('BELUM', 'PROSES')"
        );
        $belumStmt->execute(['id' => $idDetailPengiriman]);
        $totalReturBelumSelesai = (int) $belumStmt->fetchColumn();

        $dpStmt = $pdo->prepare('SELECT jumlah_diterima FROM partner_detail_pengiriman WHERE id = :id FOR UPDATE');
        $dpStmt->execute(['id' => $idDetailPengiriman]);
        $jumlahDiterimaSaatIni = (int) ($dpStmt->fetchColumn() ?: 0);

        $jumlahDiterimaBaru = $jumlahDiterimaSaatIni + $returJumlahReturTotal;

        $pdo->prepare(
            'UPDATE partner_detail_pengiriman SET jumlah_retur = :tr, jumlah_diterima = :jd, dt_modified = :now WHERE id = :id'
        )->execute(['tr' => $totalReturBelumSelesai, 'jd' => $jumlahDiterimaBaru, 'now' => $now, 'id' => $idDetailPengiriman]);
    }

    /**
     * GET /partner/retur/get-retur-by-pengiriman/{idDetailPengiriman}
     * Port dari getReturByPengiriman().
     */
    public function byPengiriman(Request $request, Response $response, array $args): Response
    {
        $idDetailPengiriman = (int) ($args['idDetailPengiriman'] ?? 0);

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT pdr.*, pdp.tanggal_kirim, pdp.jumlah_diterima AS total_pengiriman, pt.item,
                    (pdr.jumlah_retur - pdr.jumlah_diterima) AS sisa_retur
             FROM partner_detail_retur pdr
             JOIN partner_detail_pengiriman pdp ON pdr.id_detail_pengiriman = pdp.id
             JOIN partner_transaksi pt ON pdp.id_partner_transaksi = pt.id_partner_transaksi
             WHERE pdr.id_detail_pengiriman = :id
             ORDER BY pdr.dt_record DESC'
        );
        $stmt->execute(['id' => $idDetailPengiriman]);

        return $this->json($response, [
            'status' => true,
            'message' => 'Data berhasil diambil',
            'data' => $stmt->fetchAll(),
        ]);
    }

    private static function nullableString($v): ?string
    {
        return (is_string($v) && trim($v) !== '') ? trim($v) : null;
    }
}
