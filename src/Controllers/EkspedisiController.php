<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Ekspedisi;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Master data perusahaan ekspedisi EKSTERNAL (ekspedisi_m_ekspedisi, MILIK
 * app ini sendiri) -- lihat App\Support\Ekspedisi & database/01_schema.sql
 * utk alasan lengkap kenapa independen dari m_expedisi backend-production.
 */
class EkspedisiController extends Controller
{
    /**
     * GET /admin/ekspedisi
     * query opsional: all=1 -- kalau diisi, balikin SEMUA (termasuk
     * nonaktif, dipakai layar kelola perusahaan ekspedisi). Default cuma
     * yang aktif (dipakai dropdown "Perusahaan Ekspedisi" saat Tambah Supir
     * Eksternal) -- kontrak default TIDAK berubah dari sebelumnya.
     */
    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $includeInactive = !empty($q['all']);

        return $this->json($response, Ekspedisi::list(Database::connection(), $includeInactive));
    }

    /**
     * POST /admin/ekspedisi
     * body: { kode_ekspedisi?, nama_ekspedisi (WAJIB), pic?, alamat?, no_telp? }
     */
    public function store(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $body = (array) $request->getParsedBody();

        $nama = trim((string) ($body['nama_ekspedisi'] ?? ''));
        if ($nama === '') {
            return $this->error($response, 'Nama perusahaan ekspedisi wajib diisi.');
        }

        $id = Ekspedisi::create($pdo, [
            'kode_ekspedisi' => !empty($body['kode_ekspedisi']) ? trim((string) $body['kode_ekspedisi']) : null,
            'nama_ekspedisi' => $nama,
            'pic' => !empty($body['pic']) ? trim((string) $body['pic']) : null,
            'alamat' => !empty($body['alamat']) ? trim((string) $body['alamat']) : null,
            'no_telp' => !empty($body['no_telp']) ? trim((string) $body['no_telp']) : null,
        ]);

        return $this->json($response, Ekspedisi::find($pdo, $id), 201);
    }

    /**
     * PUT /admin/ekspedisi/{id}
     * body opsional (update parsial): { kode_ekspedisi?, nama_ekspedisi?, pic?, alamat?, no_telp?, is_active? }
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $id = (int) $args['id'];

        if (!Ekspedisi::find($pdo, $id)) {
            return $this->error($response, 'Perusahaan ekspedisi tidak ditemukan.', 404);
        }

        $body = (array) $request->getParsedBody();
        Ekspedisi::update($pdo, $id, $body);

        return $this->json($response, Ekspedisi::find($pdo, $id));
    }
}
