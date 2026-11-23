<?php
declare(strict_types=1);

/* ============================================================
   api/info.php — metadatos del catálogo (para bots de Telegram
   y clientes externos). Rate-limit igual que el resto de APIs.
   Salida: { total, ps5, ps4, zstd, direct, genres:[{name,count}],
             updated, por_pagina, paginas }
   ============================================================ */

require dirname(__DIR__) . '/inc/config.php';
require dirname(__DIR__) . '/inc/helpers.php';
require dirname(__DIR__) . '/inc/seguridad.php';
require dirname(__DIR__) . '/inc/catalogo.php';

$rl = rate_limit('api', RL_MAX_API);
if (!$rl['ok']) json_out(['error' => 'too_many_requests'], 429);

$cat = cargar_catalogo();

/* última actualización real: el más reciente de catalog.js y su caché */
$ts = max(
    @filemtime(STORE_CATALOG) ?: 0,
    @filemtime(CACHE_DIR . '/catalog.json') ?: 0
);

$genres = [];
foreach (array_slice($cat['genres'], 0, 20, true) as $name => $n) {
    $genres[] = ['name' => (string)$name, 'count' => (int)$n];
}

json_out([
    'total'      => count($cat['games']),
    'ps5'        => (int)($cat['plat']['ps5'] ?? 0),
    'ps4'        => (int)($cat['plat']['ps4'] ?? 0),
    'zstd'       => count($cat['zstd']),
    'direct'     => (int)($cat['direct'] ?? 0),
    'genres'     => $genres,
    'updated'    => $ts > 0 ? date(DATE_ATOM, $ts) : null,
    'por_pagina' => POR_PAGINA,
    'paginas'    => max(1, (int)ceil(count($cat['games']) / POR_PAGINA)),
]);
