<?php
declare(strict_types=1);

/* ============================================================
   api/buscar.php?q=... — autocompletado (máx 10 resultados)
   Búsqueda fuzzy: normaliza acentos/diacríticos antes de comparar
   (norm_txt vive en inc/helpers.php, compartido con el catálogo)
   ============================================================ */

require dirname(__DIR__) . '/inc/config.php';
require dirname(__DIR__) . '/inc/helpers.php';
require dirname(__DIR__) . '/inc/seguridad.php';
require dirname(__DIR__) . '/inc/catalogo.php';

$rl = rate_limit('api', RL_MAX_API);
if (!$rl['ok']) json_out(['error' => 'too_many_requests'], 429);

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, Q_MAX);
if (mb_strlen($q) < 2) json_out(['items' => []]);

$cat = cargar_catalogo();

$ql  = mb_strtolower($q);
$qn  = norm_txt($q);                                  // version normalizada (sin acentos)
$idq = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '');   // CUSA123 -> prioridad

$exact = []; $idp = []; $prefix = []; $soft = [];
foreach ($cat['games'] as $g) {
    $t   = mb_strtolower($g['title']);
    $tn  = norm_txt($g['title']);                     // titulo normalizado
    $id  = mb_strtolower($g['id']);
    $dev = mb_strtolower($g['dev'] ?? '');
    $pub = mb_strtolower($g['pub'] ?? '');

    // busqueda exacta (original o normalizada)
    if ($id === $ql || $t === $ql || $tn === $qn) {
        $exact[] = $g;
    }
    // ID por prefijo: CUSA123 -> todo lo que empiece por eso (prioridad alta)
    elseif ($idq !== '' && str_starts_with($g['id'], $idq)) {
        $idp[] = $g;
    }
    // prefijo en titulo (original o normalizada)
    elseif (mb_strpos($t, $ql) === 0 || mb_strpos($tn, $qn) === 0) {
        $prefix[] = $g;
    }
    // coincidencia parcial en titulo, ID normalizado, dev o pub
    elseif (
        mb_strpos($t, $ql) !== false || mb_strpos($id, $ql) !== false ||
        mb_strpos($tn, $qn) !== false ||
        mb_strpos($dev, $ql) !== false || mb_strpos($pub, $ql) !== false
    ) {
        $soft[] = $g;
    }

    if (count($exact) + count($idp) + count($prefix) + count($soft) >= 150) break;
}

$zset = $cat['zstd'];
/* un juego = un resultado: agrupa SKUs repetidos (PS4/PS5, ediciones) */
$pick = array_slice(dedup_titulos(array_merge($exact, $idp, $prefix, $soft)), 0, 10);
json_out([
    'items' => array_map(fn($g) => [
        'id' => $g['id'], 'title' => $g['title'], 'plat' => $g['plat'],
        'size' => $g['size'], 'mc' => $g['mc'], 'zstd' => isset($zset[$g['id']]),
        'art' => (string)($g['art'] ?? ''), 'cov' => !empty($g['cov']),
        'coverUrl' => !empty($g['cov']) ? url_cover($g['id']) : ((string)($g['art'] ?? '') ?: 'asset.php?f=logo'),
    ], $pick),
]);
