<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$allowedExt = '/\.(jpe?g|png|webp)$/i';

// Mode BARU: ?url=<full https:// URL> -- untuk foto yang bukan di folder lokal
// server ini, mis. dari migrasi.koperindo.id (surat jalan jalur baru,
// backend-migrasi, path bersubfolder "uploads/trips/{id}/..."). Domain itu
// tidak kirim header Access-Control-Allow-Origin sendiri, jadi browser CS
// Desktop (origin beda: localhost/file://) diblokir CORS kalau fetch
// langsung. Di-proxy lewat sini: server yang fetch (curl, tidak kena CORS),
// lalu diteruskan ke browser dengan header CORS di atas.
// Domain di-whitelist supaya proxy ini tidak jadi open relay bebas comot
// URL apa saja.
if (!empty($_GET['url'])) {
    $url = $_GET['url'];

    if (!preg_match($allowedExt, parse_url($url, PHP_URL_PATH) ?: '')) {
        http_response_code(400);
        exit;
    }

    $host = parse_url($url, PHP_URL_HOST);
    $allowedHosts = [
        'migrasi.koperindo.id',
        'www.migrasi.koperindo.id',
        'indokoper.com',
        'www.indokoper.com',
    ];
    if (!in_array($host, $allowedHosts, true)) {
        http_response_code(403);
        exit;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        http_response_code(502);
        exit;
    }

    header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    echo $body;
    exit;
}

// Mode LAMA: ?file=namafile.jpg -- foto SJ lama, disimpan flat (tanpa
// subfolder) di direktori yang sama dengan proxy.php ini. Dipertahankan
// apa adanya supaya caller lama (kalau ada) tidak putus.
$file = basename($_GET['file'] ?? '');
if (!$file || !preg_match($allowedExt, $file)) {
    http_response_code(400);
    exit;
}

$path = __DIR__ . '/' . $file;
if (!file_exists($path)) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($path);
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($path);
