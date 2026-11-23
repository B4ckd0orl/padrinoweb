<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';   // norm_txt() para la búsqueda fuzzy
require_once __DIR__ . '/seguridad.php'; // texto_visible(): sin nombres ajenos en pantalla (§0)

/* ============================================================
   Catálogo: parsea catalog.js (app PS5) → cache/catalog.json
   Solo lectura sobre ../padrino-store
   ============================================================ */

/** Extrae el literal JS de un array/objeto iniciado en $marca (con manejo de strings y escapes) */
function js_literal(string $raw, string $marca): ?string
{
    $pos = strpos($raw, $marca);
    if ($pos === false) return null;
    $i = $pos + strlen($marca) - 1;          // posición del '[' o '{'
    $open  = $raw[$i];
    $close = $open === '[' ? ']' : '}';
    $depth = 0; $inStr = false; $esc = false; $len = strlen($raw);
    for ($j = $i; $j < $len; $j++) {
        $ch = $raw[$j];
        if ($inStr) {
            if ($esc) { $esc = false; }
            elseif ($ch === '\\') { $esc = true; }
            elseif ($ch === '"') { $inStr = false; }
            continue;
        }
        if ($ch === '"') { $inStr = true; continue; }
        if ($ch === $open) { $depth++; }
        elseif ($ch === $close) {
            $depth--;
            if ($depth === 0) return substr($raw, $i, $j - $i + 1);
        }
    }
    return null;
}

/** JSON tolerante: quita comas colgantes típicas del JS */
function json_tolerante(string $s): ?array
{
    $s = preg_replace('/,\s*([\]\}])/', '$1', $s);
    $d = json_decode($s, true);
    return is_array($d) ? $d : null;
}

/* ============================================================
   PORTADAS QUE NO CORRESPONDEN A SU JUEGO (revisión visual)
   ------------------------------------------------------------
   COVER_SIN_LOCAL: la cover local está mal pero art.cover sí es
                    buena → se descarta solo el archivo local.
   COVER_MALA:      local Y art.cover malas → se usan los
                    respaldos de art.coverFallbacks (o la primera
                    captura de pages/<id>.json).
   COVER_FB:        índice del respaldo preferido cuando hay varios.
   ============================================================ */
const COVER_SIN_LOCAL = ['CUSA50089', 'CUSA26268'];                    // MARS 2120 · Luckslinger
const COVER_MALA     = ['CUSA04885', 'PPSA07411', 'PPSA36543',
                        'PPSA25859', 'PPSA26127'];                    // RE4 2005 · RE4 · Poppy Ch4 · College FB 26 · Madden 26
const COVER_FB       = ['PPSA07411' => 1, 'PPSA26127' => 1];          // portada estándar, no la de edición
/* Hosts caídos (verificado 2026-11-17: sin respuesta). El art.cover no sirve,
   se ignora y manda el respaldo (capturas/fallbacks). Quitar de la lista si
   el host vuelve. */
const COVER_CAIDA    = ['CUSA29431'];                                 // F1 22 · cover.spectrumlibrary.online caído

/** Imagen http(s) válida, o '' */
function url_valida(mixed $u): string
{
    return (is_string($u) && preg_match('#^https?://#', $u)) ? $u : '';
}

/**
 * Último recurso de portada cuando no hay cover local ni art.cover usable:
 *   1) art.coverFallbacks (índice preferido COVER_FB)
 *   2) la primera captura real de pages/<id>.json
 *      (acepta string u objeto { url, thumb } — antes solo string y nunca servía)
 *   3) art.hero del catálogo
 */
function portada_respaldo(array $g, string $id): string
{
    $art = is_array($g['art'] ?? null) ? $g['art'] : [];

    // 1) respaldos curados de cover
    $fb = is_array($art['coverFallbacks'] ?? null) ? $art['coverFallbacks'] : [];
    $fb = array_values(array_filter($fb, 'url_valida'));
    if ($fb) {
        $i = (int)(COVER_FB[$id] ?? 0);
        return (string)$fb[min($i, count($fb) - 1)];
    }

    // 2) primera captura de la ficha
    $pg = @file_get_contents(STORE_PAGES . '/' . $id . '.json');
    if (is_string($pg) && $pg !== '') {
        $j = json_decode($pg, true);
        foreach ((is_array($j) && is_array($j['shots'] ?? null)) ? $j['shots'] : [] as $s) {
            if (is_string($s)) { $u = url_valida($s); if ($u !== '') return $u; }
            elseif (is_array($s)) {
                foreach (['thumb', 'url'] as $k) {          // {url, thumb}: la miniatura pesa menos
                    $u = url_valida($s[$k] ?? null);
                    if ($u !== '') return $u;
                }
            }
        }
    }

    // 3) banner del catálogo
    return url_valida($art['hero'] ?? null);
}

/** Catálogo completo (slim) con caché. */
function cargar_catalogo(): array
{
    $cache = CACHE_DIR . '/catalog.json';
    $srcTs = @filemtime(STORE_CATALOG) ?: 0;

    if (is_file($cache) && (@filemtime($cache) ?: 0) >= $srcTs) {
        $j = json_decode((string)@file_get_contents($cache), true);
        if (is_array($j) && isset($j['games'])) return $j;
    }

    $raw = @file_get_contents(STORE_CATALOG);
    if ($raw === false) return ['games' => [], 'zstd' => [], 'genres' => [], 'plat' => [], 'ts' => 0];

    $lit = js_literal($raw, 'var games = [');
    $games = $lit ? json_tolerante($lit) : null;
    if (!is_array($games)) $games = [];

    // twins: segundo bloque del catálogo (la app exporta { games, twins })
    $litT = js_literal($raw, 'var twins = [');
    $twins = $litT ? json_tolerante($litT) : null;
    if (is_array($twins)) $games = array_merge($games, $twins);

    // ZSTD: snapshot de la comunidad (68 items)
    $zstd = [];
    $zraw = @filemtime(STORE_ZSTD) ? @file_get_contents(STORE_ZSTD) : null;
    if ($zraw !== null) {
        $zlit = js_literal($zraw, 'var snapshot = {');
        $snap = $zlit ? json_tolerante($zlit) : null;
        if (is_array($snap) && !empty($snap['items']) && is_array($snap['items'])) {
            foreach ($snap['items'] as $it) {
                if (!empty($it['id']) && id_valido($it['id'])) {
                    $zstd[$it['id']] = [
                        'version'  => (string)($it['version'] ?? ''),
                        'size'     => (string)($it['size'] ?? ''),
                        'firmware' => (string)($it['firmware'] ?? ''),
                        'ampr'     => (string)($it['ampr'] ?? ''),
                        'notes'    => texto_visible((string)($it['notes'] ?? '')),
                        'credits'  => (string)($it['credits'] ?? ''),   // no se muestra en pantalla
                        'links'    => array_values(array_filter((array)($it['links'] ?? []), 'is_string')),
                    ];
                }
            }
        }
    }

    // Slim: solo los campos que usa la web (mucho menos memoria)
    $slim = [];
    $genres = []; $plat = ['ps5' => 0, 'ps4' => 0]; $direct = 0;
    foreach ($games as $g) {
        if (!is_array($g) || empty($g['id']) || !id_valido($g['id'])) continue;
        $id = $g['id'];
        $gs = array_values(array_filter((array)($g['genres'] ?? []), 'is_string'));

        // --- portada: local válida → art.cover → respaldos → hero → logo
        $esLocal = cover_local_path($id) !== '';   // covers/ de la app PS5 o covers/ de la web
        $artUrl  = url_valida(is_array($g['art'] ?? null) ? ($g['art']['cover'] ?? null) : null);
        if (in_array($id, COVER_SIN_LOCAL, true)) $esLocal = false;
        if (in_array($id, COVER_MALA, true)) { $esLocal = false; $artUrl = ''; }
        if (in_array($id, COVER_CAIDA, true)) $artUrl = '';            // host de art.cover caído
        if ($artUrl === '') $artUrl = portada_respaldo($g, $id);

        $slim[] = [
            'id'    => $id,
            'title' => (string)($g['title'] ?? $id),
            'plat'  => (($g['platform'] ?? '') === 'ps4') ? 'ps4' : 'ps5',
            'gen'   => $gs,
            'size'  => isset($g['sizeGb']) && is_numeric($g['sizeGb']) ? round((float)$g['sizeGb'], 3) : null,
            'mc'    => isset($g['metacritic']) && is_numeric($g['metacritic']) ? (int)$g['metacritic'] : null,
            'year'  => isset($g['year']) && is_numeric($g['year']) ? (int)$g['year'] : null,
            'dev'   => (string)($g['developer'] ?? ''),
            'pub'   => (string)($g['publisher'] ?? ''),
            'dir'   => !empty($g['direct']),
            'prov'  => array_values(array_filter((array)($g['providers'] ?? []), 'is_string')),
            'tint'  => (string)($g['tint'] ?? ''),
            'cov'   => $esLocal,
            'art'   => $artUrl,
            'rec'   => isset($g['recommendations']) && is_numeric($g['recommendations']) ? (int)$g['recommendations'] : 0,
            'added' => (string)($g['added'] ?? ''),
        ];
        $p = end($slim)['plat'];
        $plat[$p] = ($plat[$p] ?? 0) + 1;
        if (!empty($g['direct'])) $direct++;
        foreach ($gs as $gv) { $genres[$gv] = ($genres[$gv] ?? 0) + 1; }
    }

    arsort($genres);
    $out = ['games' => $slim, 'zstd' => $zstd, 'genres' => $genres, 'plat' => $plat, 'direct' => $direct, 'ts' => time()];
    @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $out;
}

/** Índice id → posición para búsqueda rápida (una vez por petición) */
function indice_por_id(array $cat): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];
    foreach ($cat['games'] as $i => $g) $memo[$g['id']] = $i;
    return $memo;
}

/** Detalle de un juego (catálogo) */
function juego_por_id(array $cat, string $id): ?array
{
    if (!id_valido($id)) return null;
    $ix = indice_por_id($cat);
    return isset($ix[$id]) ? $cat['games'][$ix[$id]] : null;
}

/** Puntuación de un SKU para elegir representante de su título */
function sku_score(array $g): int
{
    return (!empty($g['cov']) ? 8 : 0)      // portada local válida
         + (!empty($g['art']) ? 4 : 0)      // portada remota
         + ($g['size'] !== null ? 2 : 0)    // tiene tamaño
         + ($g['mc'] !== null ? 1 : 0);     // tiene Metacritic
}

/**
 * Deduplica por título normalizado: al BUSCAR, un juego = un resultado.
 * Conserva el orden del primer SKU de cada título; empates → el más
 * reciente, luego el menor ID (estabilidad).
 */
function dedup_titulos(array $items): array
{
    $ord = []; $best = [];
    foreach ($items as $g) {
        $k = norm_txt($g['title']);
        if (!isset($best[$k])) { $best[$k] = $g; $ord[] = $k; continue; }
        $a = sku_score($g); $b = sku_score($best[$k]);
        if ($a > $b
            || ($a === $b && ($g['added'] ?? '') > ($best[$k]['added'] ?? ''))
            || ($a === $b && ($g['added'] ?? '') === ($best[$k]['added'] ?? '') && $g['id'] < $best[$k]['id'])) {
            $best[$k] = $g;
        }
    }
    return array_map(fn($k) => $best[$k], $ord);
}

/** Filtros + orden + paginación del catálogo */
function filtrar_catalogo(array $cat, array $f): array
{
    $q     = trim((string)($f['q'] ?? ''));
    $plat  = (string)($f['plat'] ?? '');      // '' | ps5 | ps4
    $gen   = $f['gen'] ?? '';                   // string (legacy) o array de géneros
    $zstd  = !empty($f['zstd']);
    $dir   = !empty($f['dir']);
    $size  = (string)($f['size'] ?? '');      // '' | s (<10) | m (10-50) | l (>50)
    $mcmin = isset($f['mcmin']) && $f['mcmin'] !== '' ? (int)$f['mcmin'] : 0;
    $orden = (string)($f['orden'] ?? 'added'); // added | rating | title | size | year
    $pag   = max(1, (int)($f['pag'] ?? 1));

    $zset = $cat['zstd'];
    $qn = $q !== '' ? norm_txt($q) : '';      // versión sin acentos para fuzzy
    $idq = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '');

    /* género: acepta string (legacy) o array (gen[]=Action&gen[]=RPG → OR) */
    $gens = is_array($gen) ? $gen : ($gen !== '' ? [$gen] : []);
    $gens = array_values(array_filter(array_map('strval', $gens), fn($x) => $x !== ''));

    $out = []; $outId = [];                   // $outId = coincidencias por prefijo de ID
    foreach ($cat['games'] as $g) {
        if ($plat !== '' && $g['plat'] !== $plat) continue;
        if ($gens && !array_intersect($gens, $g['gen'])) continue;   // OR entre géneros
        if ($zstd && !isset($zset[$g['id']])) continue;
        if ($dir && !$g['dir']) continue;
        if ($mcmin > 0 && ($g['mc'] ?? 0) < $mcmin) continue;
        if ($size !== '') {
            $s = $g['size'];
            if ($s === null) continue;
            if ($size === 's' && !($s < 10)) continue;
            if ($size === 'm' && !($s >= 10 && $s <= 50)) continue;
            if ($size === 'l' && !($s > 50)) continue;
        }
        if ($qn !== '') {
            /* búsqueda fuzzy: coincidencia sobre texto normalizado (sin acentos) */
            $hay  = norm_txt($g['title'] . ' ' . $g['id'] . ' ' . $g['dev'] . ' ' . $g['pub'] . ' ' . implode(' ', $g['gen']));
            $hayO = mb_strtolower($g['title'] . ' ' . $g['id'] . ' ' . $g['dev'] . ' ' . $g['pub'] . ' ' . implode(' ', $g['gen']));
            if ($idq !== '' && str_starts_with($g['id'], $idq)) {
                $outId[] = $g;                 // CUSA123 → prioridad máxima
                continue;
            }
            if (mb_strpos($hay, $qn) === false && mb_strpos($hayO, mb_strtolower($q)) === false) continue;
        }
        $out[] = $g;
    }
    $cmp = function ($a, $b) use ($orden) {
        switch ($orden) {
            case 'rating':  return ($b['mc'] ?? -1) <=> ($a['mc'] ?? -1) ?: strcmp($a['title'], $b['title']);
            case 'title':   return mb_strtolower($a['title']) <=> mb_strtolower($b['title']);
            case 'size':    return ($b['size'] ?? -1) <=> ($a['size'] ?? -1);
            case 'year':    return ($b['year'] ?? 0) <=> ($a['year'] ?? 0) ?: strcmp($a['title'], $b['title']);
            default:        // added (más recientes primero)
                return strcmp($b['added'], $a['added']) ?: strcmp($a['title'], $b['title']);
        }
    };
    /* los prefijos de ID van primero (ordenados entre sí); el resto, por el orden elegido */
    usort($outId, $cmp);
    usort($out, $cmp);
    $out = $outId ? array_merge($outId, $out) : $out;

    /* Al buscar: un solo resultado por juego (agrupa SKUs PS4/PS5 repetidos).
       Sin query se mantienen todos los SKUs (el catálogo es por SKU). */
    if ($q !== '') $out = dedup_titulos($out);

    $total = count($out);
    $pags  = max(1, (int)ceil($total / POR_PAGINA));
    $pag   = min($pag, $pags);
    $slice = array_slice($out, ($pag - 1) * POR_PAGINA, POR_PAGINA);

    return ['items' => $slice, 'total' => $total, 'pag' => $pag, 'pags' => $pags];
}
