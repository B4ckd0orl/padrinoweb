<?php
declare(strict_types=1);

/* ============================================================
   scripts/estatico.php — genera el sitio estático en dist/
   (GitHub Pages no ejecuta PHP: este CLI renderiza el HTML y los
   JSON que consume assets/app.js en modo data-estatico).

   Genera:
     index.html                catálogo (sin filtros; los aplica el JS)
     juego/<ID>/index.html     una ficha por SKU
     datos/catalogo.json       filtrado/búsqueda/paginación en el navegador
     datos/fuentes/<ID>.json   modal de descargas (mismo payload que api/detalle.php)
     assets/ fonts/            CSS/JS/logo/fuentes (con las rutas ya resueltas)
     404.html + .nojekyll

   Uso (CLI):
     php scripts/estatico.php

   Entorno (opcionales):
     PADRINO_BASE_PATH   '' (raíz) o '/padrinoweb'   [def. '']
     PADRINO_BASE_URL    URL pública del sitio        [def. b4ckd0orl.github.io/padrinoweb]
     PADRINO_STORE       repo de datos (SOLO LECTURA)
     PADRINO_COVERS_CDN  CDN de covers del repo de datos
   ============================================================ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por consola\n");
}

define('ESTATICO', true);                    // antes de cualquier require

require __DIR__ . '/../inc/detalle_api.php'; // trae config/helpers/seguridad/catalogo/fichas/descargas

error_reporting(E_ALL);
ini_set('display_errors', '0');              // los avisos no deben contaminar el HTML renderizado
ini_set('log_errors', '1');
set_time_limit(0);
ini_set('memory_limit', '1024M');

const DIST = WEB_ROOT . '/dist';

function logln(string $s): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

function cp_arch(string $src, string $dst): void
{
    if (!is_file($src)) throw new RuntimeException('Falta el archivo de origen: ' . $src);
    @mkdir(dirname($dst), 0777, true);
    if (!copy($src, $dst)) throw new RuntimeException('No se pudo copiar: ' . $src);
}

function bytes_fmt(int $b): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($b < 1024) return round($b, 1) . ' ' . $u;
        $b /= 1024;
    }
    return round($b, 1) . ' TB';
}

/**
 * Post-proceso de cada página renderizada:
 *  - data-estatico en <html> (conmuta assets/app.js al modo estático)
 *  - <meta> robots noindex + CSP (en modo web van en cabeceras)
 *  - pre.js bloqueante en el <head> del índice
 *  - index.php → index.html · asset.php?f=logo → assets/logo.png
 */
function post_estatico(string $h, bool $esIndex): string
{
    $h = str_replace('<html lang="es"', '<html lang="es" data-estatico', $h);
    $metas = '<meta name="robots" content="noindex, nofollow">' . csp_meta();
    $h = str_replace('<meta charset="UTF-8">', '<meta charset="UTF-8">' . $metas, $h);
    if ($esIndex) {
        $h = str_replace('<link rel="stylesheet"',
            '<script src="assets/pre.js"></script><link rel="stylesheet"', $h);
    }
    $h = str_replace('index.php', 'index.html', $h);
    /* URLs limpias: nunca se muestra "index.html" en la barra de dirección.
       './' resuelve al directorio ('/padrinoweb/') tanto en la raíz como
       bajo <base> de las fichas. */
    $h = str_replace(['href="index.html', 'action="index.html'], ['href="./', 'action="./'], $h);
    $h = str_replace('asset.php?f=logo', 'assets/logo.png', $h);
    $h = (string)preg_replace('#asset\.php\?f=torrent(?:&amp;|&)id=([A-Za-z0-9]+)#', 'torrents/$1.torrent', $h);
    return $h;
}

/* ============================================================
   1. limpiar dist/
   ============================================================ */
$t0 = microtime(true);
logln('== Build estático Padrino Store ==');
logln('BASE_PATH="' . BASE_PATH_ESTATICO . '"  URL_BASE=' . URL_BASE_ESTATICO);
logln('STORE=' . STORE_ROOT);

if (is_dir(DIST)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(DIST, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
} else {
    mkdir(DIST, 0777, true);
}
foreach (['/assets', '/assets/covers', '/fonts', '/datos', '/datos/fuentes'] as $d) {
    @mkdir(DIST . $d, 0777, true);
}

/* ============================================================
   2. dependencias de caché que en Actions arrancarían vacías
   ============================================================ */
$p = priors_fmt();
if (empty($p['psrc'])) {
    logln('· cache/fmtpriors.json ausente → reconstruyendo desde los releases…');
    $p = priors_fmt(true);
}
if (!is_file(CACHE_DIR . '/fuentes/externos.json')) {
    logln('! AVISO: cache/fuentes/externos.json no existe: sin fuentes externas en el build.');
    logln('  (debe ir versionado en el repo: es la salida de scripts/sincronizar_enlaces.php)');
}

/* ============================================================
   3. catálogo
   ============================================================ */
$cat  = cargar_catalogo();
$zset = $cat['zstd'];
$nGames = count($cat['games']);
logln('· catálogo: ' . $nGames . ' juegos · ' . count($cat['genres']) . ' géneros');

/* ============================================================
   4. assets (CSS con rutas ya resueltas + JS + logo + fuentes + covers)
   ============================================================ */
$css = (string)file_get_contents(WEB_ROOT . '/assets/style.css');
$css = str_replace("../asset.php?f=font&n=inter", "../fonts/inter.ttf", $css);
$css = str_replace("../asset.php?f=font&n=mono",  "../fonts/mono.ttf",  $css);
$css .= <<<'CSS'

/* ---- build estático: mientras pre.js aplica los filtros de la URL,
       la zona dinámica permanece oculta (evita el flash de resultados
       sin filtrar). Sin JS, pre.js no añade la clase y nada se oculta. ---- */
html.pre .grid, html.pre .tg-banner, html.pre .nov-wrap, html.pre .more-wrap,
html.pre .pages, html.pre .bar .res, html.pre .fchip, html.pre #side .opt { visibility: hidden; }
CSS;
file_put_contents(DIST . '/assets/style.css', $css);

foreach (['app.js', 'guard.js', 'pre.js'] as $f) {
    cp_arch(WEB_ROOT . '/assets/' . $f, DIST . '/assets/' . $f);
}
cp_arch(STORE_SCE . '/icon0.png', DIST . '/assets/logo.png');
cp_arch(STORE_ASSETS . '/Inter-Regular.ttf',         DIST . '/fonts/inter.ttf');
cp_arch(STORE_ASSETS . '/JetBrainsMono-Regular.ttf', DIST . '/fonts/mono.ttf');

$nCov = 0;
foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
    foreach (glob(COVERS_WEB . '/*.' . $ext) ?: [] as $f) {
        cp_arch($f, DIST . '/assets/covers/' . basename($f));
        $nCov++;
    }
}
$nTor = 0;
foreach (glob(CACHE_DIR . '/torrents/*.torrent') ?: [] as $f) {
    cp_arch($f, DIST . '/torrents/' . basename($f));
    $nTor++;
}
logln('· assets listos (covers propias: ' . $nCov . ($nTor ? ', torrents: ' . $nTor : '') . ')');

/* ============================================================
   5. datos/catalogo.json — espejo del payload de api/juegos.php
   ============================================================ */
$items = [];
foreach ($cat['games'] as $g) {
    $id = $g['id'];
    $items[] = [
        'id'       => $id,
        'title'    => $g['title'],
        'plat'     => $g['plat'],
        'gen'      => $g['gen'],
        'size'     => $g['size'],
        'mc'       => $g['mc'],
        'year'     => $g['year'],
        'dev'      => $g['dev'],
        'pub'      => $g['pub'],
        'dir'      => $g['dir'],
        'zstd'     => isset($zset[$id]),
        'tint'     => $g['tint'],
        'cov'      => !empty($g['cov']),
        'art'      => (string)($g['art'] ?? ''),
        'added'    => (string)($g['added'] ?? ''),
        'coverUrl' => !empty($g['cov']) ? url_cover($id) : ((string)($g['art'] ?? '') ?: logo_src()),
    ];
}
file_put_contents(
    DIST . '/datos/catalogo.json',
    json_encode(['por' => POR_PAGINA, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
logln('· datos/catalogo.json (' . count($items) . ' items, ' . bytes_fmt((int)filesize(DIST . '/datos/catalogo.json')) . ')');

/* ============================================================
   6. datos/fuentes/<ID>.json — payload de api/detalle.php
      (sin 'pass' ni 'ext': detalle_payload() los excluye)
   ============================================================ */
$nF = 0;
foreach ($cat['games'] as $g) {
    $pay = detalle_payload($g['id'], $cat);
    if ($pay === null) continue;
    $rawPay = json_encode($pay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $rawPay = (string)preg_replace('#asset\.php\?f=torrent&amp;id=([A-Za-z0-9]+)|asset\.php\?f=torrent&id=([A-Za-z0-9]+)#', 'torrents/$1$2.torrent', $rawPay);
    file_put_contents(
        DIST . '/datos/fuentes/' . $g['id'] . '.json',
        $rawPay
    );
    $nF++;
    if ($nF % 1000 === 0) logln('  · fuentes: ' . $nF . '/' . $nGames);
}
logln('· datos/fuentes: ' . $nF . ' ficheros');

/* ============================================================
   7. juego/<ID>/index.html — una ficha por SKU
      (index.php va después: define funciones que no se redeclaran)
   ============================================================ */
$nPag = 0;
foreach ($cat['games'] as $g) {
    $id = $g['id'];
    if (!id_valido($id) || juego_por_id($cat, $id) === null) continue;
    $_GET = ['id' => $id];
    $_SERVER['REQUEST_URI'] = '/juego/' . $id;    // sin juego.php?id= → sin 301
    ob_start();
    include WEB_ROOT . '/juego.php';
    $html = ob_get_clean();
    @mkdir(DIST . '/juego/' . $id, 0777, true);
    file_put_contents(DIST . '/juego/' . $id . '/index.html', post_estatico($html, false));
    $nPag++;
    if ($nPag % 1000 === 0) logln('  · fichas: ' . $nPag . '/' . $nGames);
}
logln('· fichas: ' . $nPag);

/* ============================================================
   8. index.html (sin filtros: los aplica assets/app.js en el cliente)
   ============================================================ */
$_GET = [];
$_SERVER['REQUEST_URI'] = '/index.html';
ob_start();
include WEB_ROOT . '/index.php';
$indexHtml = post_estatico(ob_get_clean(), true);
file_put_contents(DIST . '/index.html', $indexHtml);
logln('· index.html (' . bytes_fmt((int)strlen($indexHtml)) . ')');

/* ============================================================
   9. 404.html + .nojekyll
   ============================================================ */
$base = base_path();
file_put_contents(DIST . '/.nojekyll', '');
file_put_contents(DIST . '/404.html', <<<HTML
<!DOCTYPE html>
<html lang="es" data-estatico>
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Página no encontrada — Padrino Store</title>
<link rel="stylesheet" href="{$base}/assets/style.css">
<link rel="icon" href="{$base}/assets/logo.png">
</head>
<body style="display:grid;place-items:center;min-height:100vh;margin:0;background:#111;color:#eee;font-family:system-ui,sans-serif;text-align:center">
  <div style="padding:24px">
    <img src="{$base}/assets/logo.png" alt="" width="72" height="72" style="border-radius:16px">
    <h1 style="font-size:28px;margin:16px 0 6px">404 — Página no encontrada</h1>
    <p style="opacity:.75;margin:0 0 20px">Ese juego o dirección no existe en el catálogo.</p>
    <a href="{$base}/" style="display:inline-block;padding:12px 22px;border-radius:12px;background:#6c5ce7;color:#fff;text-decoration:none;font-weight:600">← Volver al catálogo</a>
  </div>
</body>
</html>
HTML);

/* ============================================================
   10. verificación: no debe quedar ninguna referencia a PHP
   ============================================================ */
$malos = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DIST, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    if (!in_array($ext, ['html', 'json', 'css'], true)) continue;
    $c = (string)@file_get_contents($f->getPathname());
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(DIST) + 1));
    if (strpos($c, 'asset.php') !== false || strpos($c, 'index.php') !== false) {
        $malos[] = $rel . ' → referencia PHP residual';
    }
    if ($ext === 'html' && preg_match('/(?:href|action)="index\.html/', $c)) {
        $malos[] = $rel . ' → enlace con index.html visible en la URL';
    }
    if ($ext === 'json' && $rel !== 'datos/catalogo.json' && preg_match('/"(pass|ext)"\s*:/', $c)) {
        $malos[] = $rel . ' → contiene claves internas (pass/ext)';
    }
}
if ($malos) {
    logln('! FALLOS DE VERIFICACIÓN (' . count($malos) . '):');
    foreach (array_slice($malos, 0, 20) as $m) logln('    ' . $m);
    exit(1);
}

/* ============================================================
   resumen
   ============================================================ */
$tot = 0;
$nArch = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DIST, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) { $tot += (int)$f->getSize(); $nArch++; }
}
logln('');
logln('== OK: dist/ con ' . number_format($nArch, 0, ',', '.') . ' archivos · ' . bytes_fmt($tot)
    . ' · ' . round(microtime(true) - $t0, 1) . ' s ==');
