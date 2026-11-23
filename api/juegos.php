<?php
declare(strict_types=1);

/* ============================================================
   api/juegos.php — catálogo paginado/filtrado en JSON
     ?q=&plat=&gen=&zstd=&dir=&size=&mcmin=&orden=&pag=
   ============================================================ */

require dirname(__DIR__) . '/inc/config.php';
require dirname(__DIR__) . '/inc/seguridad.php';
require dirname(__DIR__) . '/inc/catalogo.php';

$rl = rate_limit('api', RL_MAX_API);
if (!$rl['ok']) json_out(['error' => 'too_many_requests'], 429);

$cat = cargar_catalogo();

$genRaw = $_GET['gen'] ?? [];
if (!is_array($genRaw)) $genRaw = [$genRaw];
$gen = array_values(array_filter(array_map(fn($x) => trim((string)$x), $genRaw), fn($x) => $x !== ''));

$res = filtrar_catalogo($cat, [
    'q'     => mb_substr(trim((string)($_GET['q'] ?? '')), 0, Q_MAX),
    'plat'  => in_array($_GET['plat'] ?? '', ['ps5', 'ps4'], true) ? $_GET['plat'] : '',
    'gen'   => $gen,                             // array de géneros (OR)
    'zstd'  => !empty($_GET['zstd']),
    'dir'   => !empty($_GET['dir']),
    'size'  => in_array($_GET['size'] ?? '', ['s', 'm', 'l'], true) ? $_GET['size'] : '',
    'mcmin' => (string)($_GET['mcmin'] ?? ''),
    'orden' => in_array($_GET['orden'] ?? '', ['added', 'rating', 'title', 'size', 'year'], true) ? $_GET['orden'] : 'added',
    'pag'   => (string)($_GET['pag'] ?? '1'),
]);

$zset = $cat['zstd'];
$items = array_map(fn($g) => [
    'id' => $g['id'], 'title' => $g['title'], 'plat' => $g['plat'],
    'gen' => $g['gen'], 'size' => $g['size'], 'mc' => $g['mc'],
    'year' => $g['year'], 'dev' => $g['dev'], 'dir' => $g['dir'],
    'zstd' => isset($zset[$g['id']]), 'tint' => $g['tint'], 'cov' => !empty($g['cov']),
    'art' => (string)($g['art'] ?? ''), 'added' => (string)($g['added'] ?? ''),
    'coverUrl' => !empty($g['cov']) ? url_cover($g['id']) : ((string)($g['art'] ?? '') ?: 'asset.php?f=logo'),
], $res['items']);

json_out([
    'items' => $items,
    'total' => $res['total'],
    'pag'   => $res['pag'],
    'pags'  => $res['pags'],
    'por'   => POR_PAGINA,
]);
