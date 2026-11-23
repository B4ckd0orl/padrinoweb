<?php
declare(strict_types=1);

/* ============================================================
   Fichas: pages/<id>.json (descripción + capturas)
   ============================================================ */

function cargar_ficha(string $id): array
{
    if (!id_valido($id)) return ['about' => [], 'shots' => [], 'summary' => ''];
    $file = STORE_PAGES . '/' . $id . '.json';
    if (!is_file($file)) return ['about' => [], 'shots' => [], 'summary' => ''];

    $cache = CACHE_DIR . '/fichas/' . $id . '.json';
    $srcTs = @filemtime($file) ?: 0;
    if (is_file($cache) && (@filemtime($cache) ?: 0) >= $srcTs) {
        $j = json_decode((string)@file_get_contents($cache), true);
        if (is_array($j)) return $j;
    }

    $j = json_decode((string)@file_get_contents($file), true);
    if (!is_array($j)) $j = [];

    $about = [];
    foreach ((array)($j['about'] ?? []) as $p) {
        $p = texto_visible(trim((string)$p));      // descripción: texto visible, sin nombres ajenos
        if ($p !== '') $about[] = $p;
        if (count($about) >= 6) break;
    }
    $shots = [];
    foreach ((array)($j['shots'] ?? []) as $s) {
        if (is_array($s) && !empty($s['url']) && filter_var($s['url'], FILTER_VALIDATE_URL)) {
            $shots[] = ['url' => (string)$s['url'], 'thumb' => (string)($s['thumb'] ?? $s['url'])];
        }
        if (count($shots) >= 8) break;
    }
    $out = [
        'about'   => $about,
        'shots'   => $shots,
        'summary' => texto_visible(trim((string)($j['summary'] ?? ''))),
    ];

    @mkdir(dirname($cache), 0775, true);
    @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $out;
}
