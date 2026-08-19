<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\SuratJalan;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Modul surat jalan MILIK app ini sendiri (ekspedisi_t_surat_jalan) --
 * independen dari tabel surat_jalan lama milik backend-production (lihat
 * App\Support\SuratJalanLookup, cuma read-only ke sana, tidak berhubungan
 * dgn controller ini).
 */
class SuratJalanController extends Controller
{
    /**
     * GET /admin/sj
     * query opsional: status, penjualan_id
     */
    public function index(Request $request, Response $response): Response
    {
        $filters = $request->getQueryParams();
        return $this->json($response, SuratJalan::list(Database::connection(), $filters));
    }

    /**
     * GET /admin/sj/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $sj = SuratJalan::find(Database::connection(), (int) $args['id']);
        if (!$sj) {
            return $this->error($response, 'Surat jalan tidak ditemukan.', 404);
        }

        return $this->json($response, $sj);
    }

    /**
     * POST /admin/sj
     * Bikin SJ manual dari layar admin -- trip_id opsional (boleh tidak
     * terkait trip manapun).
     * body: { trip_id?, penjualan_id?, driver_id?, tujuan?, kendaraan?, plat?, jumlah_kirim?, catatan? }
     */
    public function store(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $body = (array) $request->getParsedBody();

        if (!empty($body['trip_id'])) {
            $exists = $pdo->prepare('SELECT 1 FROM ekspedisi_t_trip WHERE id = :id LIMIT 1');
            $exists->execute(['id' => (int) $body['trip_id']]);
            if (!$exists->fetchColumn()) {
                return $this->error($response, 'Trip tidak ditemukan.');
            }
        }
        if (!empty($body['driver_id'])) {
            $exists = $pdo->prepare('SELECT 1 FROM ekspedisi_m_supir WHERE id = :id LIMIT 1');
            $exists->execute(['id' => (int) $body['driver_id']]);
            if (!$exists->fetchColumn()) {
                return $this->error($response, 'Supir tidak ditemukan.');
            }
        }

        $id = SuratJalan::create($pdo, [
            'trip_id' => !empty($body['trip_id']) ? (int) $body['trip_id'] : null,
            'penjualan_id' => $body['penjualan_id'] ?? null,
            'driver_id' => !empty($body['driver_id']) ? (int) $body['driver_id'] : null,
            'tujuan' => $body['tujuan'] ?? null,
            'kendaraan' => $body['kendaraan'] ?? null,
            'plat' => $body['plat'] ?? null,
            'jumlah_kirim' => !empty($body['jumlah_kirim']) ? (int) $body['jumlah_kirim'] : null,
            'catatan' => $body['catatan'] ?? null,
            'created_by' => (int) $request->getAttribute('user_id'),
        ]);

        return $this->json($response, SuratJalan::find($pdo, $id), 201);
    }

    /**
     * PUT /admin/sj/{id}
     * Lengkapi/koreksi field SJ (mis. yang auto-dibuat dari checkpoint foto
     * supir, biasanya minim data -- admin isi kendaraan/plat/jumlah_kirim belakangan).
     * body: { tujuan?, kendaraan?, plat?, jumlah_kirim?, catatan? }
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $id = (int) $args['id'];

        if (!SuratJalan::find($pdo, $id)) {
            return $this->error($response, 'Surat jalan tidak ditemukan.', 404);
        }

        $body = (array) $request->getParsedBody();
        SuratJalan::update($pdo, $id, $body);

        return $this->json($response, SuratJalan::find($pdo, $id));
    }
}
