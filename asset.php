<?php
declare(strict_types=1);

/* ============================================================
   asset.php — sirve covers, fuentes y logo desde padrino-store
   (fuera de htdocs; todo validado con whitelist, sin path traversal)
     ?f=cover&id=PPSA04609&h=<firma>   → covers/<id>.jpg  (URL firmada HMAC)
     ?f=font&n=inter|mono              → fuentes ttf
     ?f=logo                           → sce_sys/icon0.png
   ============================================================ */

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/seguridad.php';

seg_headers();

if (!permitir_referer()) { http_response_code(403); exit('Forbidden'); }

/* límite de descargas por IP (evita vaciar el catálogo con un script) */
$rl = rate_limit('asset', RL_MAX_ASSET);
if (!$rl['ok']) { http_response_code(429); header('Retry-After: ' . RL_VENTANA); exit('Too many requests'); }

$f = $_GET['f'] ?? '';

switch ($f) {
    case 'cover': {
        $id = strtoupper((string)($_GET['id'] ?? ''));
        if (!id_valido($id)) { http_response_code(400); exit('Bad id'); }
        if (!firma_cover_ok($id, isset($_GET['h']) ? (string)$_GET['h'] : null)) {
            http_response_code(403); exit('Firma invalida');
        }
        $path = cover_local_path($id);            // covers/ de la app PS5 o covers/ de la web
        $ext  = $path !== '' ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : 'jpg';
        $type = match ($ext) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            default => 'image/jpeg',
        };
        $name = $id . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        break;
    }
    case 'font': {
        $map = [
            'inter' => ['Inter-Regular.ttf',        'font/ttf', 'inter.ttf'],
            'mono'  => ['JetBrainsMono-Regular.ttf', 'font/ttf', 'mono.ttf'],
        ];
        $n = (string)($_GET['n'] ?? '');
        if (!isset($map[$n])) { http_response_code(400); exit('Bad font'); }
        $path = STORE_ASSETS . '/' . $map[$n][0];
        $type = $map[$n][1];
        $name = $map[$n][2];
        break;
    }
    case 'logo': {
        $path = STORE_SCE . '/icon0.png';
        $type = 'image/png';
        $name = 'logo.png';
        break;
    }
    case 'torrent': {
        $id = strtoupper((string)($_GET['id'] ?? ''));
        if (!id_valido($id)) { http_response_code(400); exit('Bad id'); }
        $path = CACHE_DIR . '/torrents/' . $id . '.torrent';
        $type = 'application/x-bittorrent';
        $name = $id . '.torrent';
        break;
    }
    default:
        http_response_code(400); exit('Bad request');
}

if (!is_file($path)) { http_response_code(404); exit('Not found'); }

$size  = filesize($path);
$mtime = filemtime($path);
$etag  = '"' . md5($path . $mtime . $size) . '"';

header('Cache-Control: public, max-age=604800, immutable');
header('ETag: ' . $etag);
header('Content-Type: ' . $type);
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
$disp = $f === 'torrent' ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disp . '; filename="' . $name . '"');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

$fp = fopen($path, 'rb');
if ($fp === false) { http_response_code(500); exit; }
while (!feof($fp)) {
    $buf = fread($fp, 262144);
    if ($buf !== false) echo $buf;
}
fclose($fp);
exit;
