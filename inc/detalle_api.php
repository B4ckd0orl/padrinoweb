<?php
declare(strict_types=1);

/* ============================================================
   detalle_api.php — payload de api/detalle.php
   Compartido con el build estático (scripts/estatico.php genera
   datos/fuentes/<id>.json con exactamente este payload).
   ============================================================ */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/catalogo.php';
require_once __DIR__ . '/fichas.php';
require_once __DIR__ . '/descargas.php';

/**
 * Detalle completo de un juego (ficha + fuentes de descarga).
 * @param array|null $cat catálogo ya cargado (evita releerlo en el build)
 * @return array|null null si el id no existe
 */
function detalle_payload(string $id, ?array $cat = null): ?array
{
    if (!id_valido($id)) return null;
    if ($cat === null) $cat = cargar_catalogo();

    $g = juego_por_id($cat, $id);
    if ($g === null) return null;

    $ficha = cargar_ficha($id);
    $zstd  = $cat['zstd'][$id] ?? null;
    $rels  = cargar_descargas($id, $zstd);
    foreach ($rels as &$rr) {
        unset($rr['ext']);        // marcador interno: nunca se sirve
        unset($rr['pass']);       // contraseña real de los RAR: jamás (plan §0)
    }
    unset($rr);
    $tieneCov = !empty($g['cov']);   // cover local válida (excluye las portadas malas)
    $artCover  = !empty($g['art']) ? (string)$g['art'] : '';
    $covSrc = $tieneCov ? url_cover($id) : ($artCover !== '' ? $artCover : logo_src());

    return [
        'id'      => $g['id'],
        'title'   => $g['title'],
        'plat'    => $g['plat'],
        'gen'     => $g['gen'],
        'size'    => $g['size'],
        'mc'      => $g['mc'],
        'year'    => $g['year'],
        'dev'     => $g['dev'],
        'pub'     => $g['pub'],
        'dir'     => $g['dir'],
        'tint'    => $g['tint'],
        'zstd'    => $zstd ? [
            'version'  => (string)($zstd['version'] ?? ''),
            'size'     => (string)($zstd['size'] ?? ''),
            'firmware' => (string)($zstd['firmware'] ?? ''),
            'ampr'     => (string)($zstd['ampr'] ?? ''),
            'notes'    => (string)($zstd['notes'] ?? ''),
        ] : null,
        'about'   => $ficha['about'],
        'summary' => $ficha['summary'],
        'shots'   => $ficha['shots'],
        'sources' => $rels,
        'cover'   => $covSrc,
        'hasCover'=> $tieneCov || !empty($g['art']),
    ];
}
