<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Simpan file foto upload (multipart) ke disk, dipakai bareng oleh
 * DriverController (checkpoint trip) & SuratJalanController (foto SJ) --
 * dua konteks beda folder, tapi logika simpan+konversinya sama persis.
 *
 * Konversi ke WEBP (2026-08-20) lewat GD (`imagewebp()`) -- SEMUA foto upload
 * disimpan ulang sbg .webp, kualitas 82 (kompromi ukuran file/detail visual,
 * cukup utk foto bukti pengiriman, bukan dokumen yang butuh presisi tinggi).
 * Kalau GD/dukungan WebP TIDAK ada di server (dicek runtime lewat
 * `function_exists`, bukan diasumsikan) -- fallback simpan APA ADANYA sesuai
 * ekstensi asli file yang diupload, supaya upload tetap jalan (tidak fatal)
 * walau tidak sempat dikonversi. Nama file TANPA timestamp (`$filenameWithoutExt`
 * ditentukan pemanggil sesuai konteksnya, mis. "berangkat"/"bukti"/"validasi")
 * -- upload ulang ke slot yang sama otomatis TIMPA file lama di disk (bukan
 * numpuk file baru tiap re-upload kayak sebelumnya, yang bikin file lama jadi
 * sampah tidak ditunjuk kolom manapun lagi di database).
 */
class PhotoStorage
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const WEBP_QUALITY = 82;

    /**
     * @param string $field Nama field multipart, biasanya 'photo'.
     * @param string $baseDir Path absolut folder tujuan (dibuat otomatis kalau belum ada).
     * @param string $publicPrefix Prefix path relatif yg disimpan ke kolom DB (mis. "uploads/trips/12").
     * @param string $filenameWithoutExt Nama file tanpa ekstensi, deskriptif sesuai konteks (mis. "berangkat").
     * @return string|null Path relatif (utk kolom `path`/`foto_*`), atau null kalau tidak ada file/melebihi batas ukuran.
     */
    public static function save(Request $request, string $field, string $baseDir, string $publicPrefix, string $filenameWithoutExt): ?string
    {
        $files = $request->getUploadedFiles();
        $photo = $files[$field] ?? null;
        if ($photo === null || $photo->getError() !== UPLOAD_ERR_OK) {
            return null;
        }
        if ($photo->getSize() > self::MAX_BYTES) {
            return null;
        }

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        $tmpPath = $baseDir . '/.tmp_' . uniqid();
        $photo->moveTo($tmpPath);

        if (function_exists('imagewebp') && function_exists('imagecreatefromstring')) {
            $raw = file_get_contents($tmpPath);
            $image = $raw !== false ? @imagecreatefromstring($raw) : false;
            if ($image !== false) {
                $filename = "{$filenameWithoutExt}.webp";
                imagewebp($image, "{$baseDir}/{$filename}", self::WEBP_QUALITY);
                imagedestroy($image);
                unlink($tmpPath);

                return "{$publicPrefix}/{$filename}";
            }
        }

        // Fallback (GD/WebP tidak tersedia, atau file-nya gagal didecode sbg
        // gambar) -- simpan apa adanya, pertahankan ekstensi asli dari nama
        // file yang diupload client.
        $ext = strtolower(pathinfo($photo->getClientFilename() ?? '', PATHINFO_EXTENSION)) ?: 'jpg';
        $filename = "{$filenameWithoutExt}.{$ext}";
        rename($tmpPath, "{$baseDir}/{$filename}");

        return "{$publicPrefix}/{$filename}";
    }
}
