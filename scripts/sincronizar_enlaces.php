<?php
declare(strict_types=1);

/* ============================================================
   scripts/sincronizar_enlaces.php — recolector CLI de fuentes
   extra para los juegos que no tienen ning.n enlace (plan de
   enlaces, Fase 1). SOLO CLI: nunca se ejecuta desde la web.

   - Descarga cat.logos/listas en cache/fuentes/ (TTL 7 d.as;
     todo bloqueado por .htaccess: nada de esto es servible).
   - Cruce por ID exacto -> t.tulo exacto -> difuso (Jaccard
     >= 0,85 o contenci.n) -> b.squeda REST del sitio.
   - Extrae enlaces directos de las fichas (payloads base64),
     los filtra por la allowlist de hosts de archivo y los
     valida: ID en el payload -> confirmado; sin ID solo se
     acepta t.tulo exacto; lo difuso sin ID va a "dudosos".
   - Escribe cache/fuentes/externos.json (solo confirmados;
     el campo "origin" es interno y jam.s se sirve por HTTP)
     + informe en cache/logs/.

   Uso:
     php scripts/sincronizar_enlaces.php             # sync completo (~4-5 min)
     php scripts/sincronizar_enlaces.php --solo-pegasus   # solo cat.logos por ID
     php scripts/sincronizar_enlaces.php --sin-fetch     # reutiliza HTML en cach.
     php scripts/sincronizar_enlaces.php --warm         # regenera cache/rels/
     php scripts/sincronizar_enlaces.php --revisar      # detalla los dudosos
     php scripts/sincronizar_enlaces.php --force        # ignora TTL
   ============================================================ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por consola\n");
}

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/seguridad.php';
require __DIR__ . '/../inc/catalogo.php';
require __DIR__ . '/../inc/descargas.php';

/* ---------------- par metros ---------------- */
$ARGS     = array_slice($argv, 1);
$SOLPEG   = in_array('--solo-pegasus', $ARGS, true);
$SINFETCH = in_array('--sin-fetch', $ARGS, true);
$WARM     = in_array('--warm', $ARGS, true);
$REVISAR  = in_array('--revisar', $ARGS, true);
$FORCE    = in_array('--force', $ARGS, true);

const UA_SYNC       = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const TTL_LISTAS    = 604800;   // 7 d.as
const TTL_POSTS     = 604800;
const FUENTES_A     = ['pippo', 'dlps', 'pfs', 'zer0'];
const MAX_EXT_JUEGO = 5;        // mimo de enlaces externos por juego
const JACCARD_MIN   = 0.85;

$DIR_A    = CACHE_DIR . '/fuentes/pegasus';
$DIR_B    = CACHE_DIR . '/fuentes/listas';
$DIR_P    = CACHE_DIR . '/fuentes/posts';
$EXT_FILE = CACHE_DIR . '/fuentes/externos.json';

$t0      = microtime(true);
$out     = [];
$linea   = function (string $t = '') use (&$out) { $out[] = $t; echo $t, "\n"; };
$errores = [];
$ultimaReq = 0.0;

/* ---------------- utilidades ---------------- */

/**
 * GET con cach. en disco (TTL) y ~1 petici.n por segundo en red.
 * Devuelve el cuerpo o null (el error queda en $errores).
 */
function http_get(string $url, ?string $cache = null, int $ttl = TTL_POSTS, bool $force = false): ?string
{
    global $errores, $ultimaReq;
    if ($cache !== null && !$force && is_file($cache) && (time() - (int)filemtime($cache)) < $ttl) {
        $c = (string)@file_get_contents($cache);
        if ($c !== '') return $c;
    }
    $espera = 950000 - (int)((microtime(true) - $ultimaReq) * 1000000);
    if ($espera > 0) usleep($espera);
    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: " . UA_SYNC . "\r\n"
            . "Accept: text/html,application/json,*/*\r\n"
            . "Accept-Language: es-ES,es;q=0.9\r\n",
        'timeout'         => 45,
        'follow_location' => 1,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    $ultimaReq = microtime(true);
    $st = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', (string)$http_response_header[0], $m)) {
        $st = (int)$m[1];
    }
    if ($data === false || $data === '' || $st >= 400 || $st === 0) {
        $errores[] = ($st ?: 'ERR') . ' ' . $url;
        return null;
    }
    if ($cache !== null) {
        @mkdir(dirname($cache), 0775, true);
        @file_put_contents($cache, $data, LOCK_EX);
    }
    return $data;
}

/** Normalizaci.n de t.tulo para igualdad exacta (min.sculas, sin acentos ni s.mbolos). */
function norm_ex(string $s): string
{
    $s = mb_strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5), 'UTF-8');
    $s = strtr($s, [
        // acentos → ASCII
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c',
        // romanos, sigma y variantes tipográficas
        'ⅰ'=>'i','ⅱ'=>'ii','ⅲ'=>'iii','ⅳ'=>'iv','ⅴ'=>'v','ⅵ'=>'vi','ⅶ'=>'vii','ⅷ'=>'viii','ⅸ'=>'ix','ⅹ'=>'x',
        'Ⅰ'=>'i','Ⅱ'=>'ii','Ⅲ'=>'iii','Ⅳ'=>'iv','Ⅴ'=>'v','Ⅵ'=>'vi','Ⅶ'=>'vii','Ⅷ'=>'viii','Ⅸ'=>'ix','Ⅹ'=>'x',
        'σ'=>'sigma','Σ'=>'sigma','ς'=>'sigma','∀'=>'a',
        // marcas registradas → espacio (Patapon™2 → "patapon 2")
        '®'=>' ','™'=>' ','℠'=>' ','©'=>' ',
        // apostrofos → nada (Assassin's → assassins; Let's → lets)
        "'"=>'', '’'=>'', 'ʼ'=>'', '`'=>'',
        // puntuación → espacio
        '‘'=>' ','“'=>' ','”'=>' ','–'=>' ','—'=>' ','…'=>' ','·'=>' ','-'=>' ',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/', ' ', $s));
    // ruido de región y de listas: "Let's Sing 8 (Spain)" → "lets sing 8"
    $s = preg_replace(
        '/\b(spain|germany|france|japan|italy|europe|usa|uk|espana|deutschland|ps4|ps5|pkg|download|free|retail|iso|update)\b/',
        ' ', $s
    ) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Tokens para Jaccard: sin romanos ni palabras vac.as (patr. cruce_total). */
function tok(string $s): array
{
    $t = norm_ex($s);
    if ($t === '') return [];
    $t = preg_replace('/\b(iv|iii|ii|v|vi|vii|viii|ix|x|xi|xii|xiii)\b/', ' ', $t) ?? $t;
    $t = preg_replace('/\b(the|of|and|a|an|to|de|la|el)\b/', ' ', $t) ?? $t;
    return array_values(array_filter(explode(' ', trim($t))));
}

function jaccard(array $a, array $b): float
{
    if (!$a || !$b) return 0.0;
    $sa = array_unique($a);
    $sb = array_unique($b);
    $union = count(array_unique(array_merge($sa, $sb)));
    return $union > 0 ? count(array_intersect($sa, $sb)) / $union : 0.0;
}

/** Extrae enlaces, etiquetas, ID, contrase.a y tama.o de una ficha. */
function extraer_post(string $html, string $id): array
{
    $payloads = [];
    if (preg_match_all('/data-payload="([A-Za-z0-9+\/=]+)"/', $html, $m)) {
        foreach ($m[1] as $p) {
            $d = base64_decode($p, true);
            if ($d !== false && $d !== '') $payloads[] = $d;
        }
    }
    $nucleo = $payloads ? html_entity_decode(implode("\n", $payloads), ENT_QUOTES | ENT_HTML5) : '';
    $txt    = $nucleo !== '' ? $nucleo : $html;

    // URLs directas (solo hosts de la allowlist de la web)
    preg_match_all('#https?://[^\s"\'<>\\\\]+#', $txt, $uu);
    $urls = [];
    foreach (array_unique($uu[0]) as $u) {
        $u = rtrim($u, ".,);]\"'");
        if (str_starts_with($u, '//')) $u = 'https:' . $u;
        if (filter_var($u, FILTER_VALIDATE_URL) && host_permitido($u)) $urls[$u] = true;
    }

    // etiquetas: texto de los <a> que apuntan a cada URL
    $labels = [];
    if (preg_match_all('/<a[^>]*href="([^"]+)"[^>]*>([^<]{1,60})<\/a>/i', $txt, $am, PREG_SET_ORDER)) {
        foreach ($am as $a) {
            $hu = html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5);
            if (!isset($labels[$hu])) $labels[$hu] = trim(html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5));
        }
    }

    // contrase.a y tama.o: solo del n.cleo de payloads (no del HTML entero)
    $pass = null;
    $sizeGb = null;
    if ($nucleo !== '') {
        if (preg_match('/(?:password|contrase[ñn]a|pass)\s*[:=]\s*([^\s<]{2,40})/i', $nucleo, $pm)) {
            $pass = trim($pm[1]);
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*GB\b/i', $nucleo, $sm)) {
            $sizeGb = (float)str_replace(',', '.', $sm[1]);
        }
    }

    return [
        'urls'    => array_keys($urls),
        'labels'  => $labels,
        'pass'    => $pass,
        'sizeGb'  => $sizeGb,
        // el ID puede estar en el HTML visible (búsqueda por ID) o en los payloads
        'idEn'    => stripos($html, $id) !== false || ($nucleo !== '' && stripos($nucleo, $id) !== false),
        'nucleo'  => $nucleo,
    ];
}

/**
 * Convierte un hallazgo en entradas de externos.json: orden por velocidad
 * de host, una por proveedor, formato precalculado, sin repetir URL.
 * @return array<int, array>
 */
function entradas_desde_hallazgo(array $h, string $origen, ?string $post): array
{
    $urls = $h['urls'];
    usort($urls, fn($a, $b) => prioridad_host($a) <=> prioridad_host($b));

    $out = [];
    $vistos = [];
    foreach ($urls as $u) {
        if (count($out) >= MAX_EXT_JUEGO) break;
        $prov = provider_de_host($u);
        $k = strtolower($prov);
        if ($prov === '' || isset($vistos[$k])) continue;
        $vistos[$k] = true;

        // etiqueta: ancla del enlace → filename cercano → nombre del host
        $lab = (string)($h['labels'][$u] ?? '');
        if (preg_match('/^(click|aqui|aquí|download|descarga|enlace|link|here|este|esta|pagina|página)$/i', $lab)) $lab = '';
        if ($lab === '' && $h['nucleo'] !== '') {
            $pos = strpos($h['nucleo'], $u);
            if ($pos !== false) {
                $vent = substr($h['nucleo'], max(0, $pos - 300), 600);
                if (preg_match('/[\w.\-]{4,}\.(pkg|7z|rar|zip|exfat|ffpfs|fpkg|tar|tgz)\b/i', $vent, $fm)) {
                    $lab = $fm[0];
                }
            }
        }
        if ($lab === '') $lab = $prov;

        $out[] = [
            'provider' => $prov,
            'label'    => $lab,
            'url'      => $u,
            'format'   => formato_enlace(['url' => $u, 'label' => $lab, 'format' => ''], null),
            'sizeGb'   => $h['sizeGb'],
            'pass'     => $h['pass'],
            'origin'   => $origen,   // interno: jam.s se sirve por HTTP
            'post'     => $post,     // interno: jam.s se sirve por HTTP
        ];
    }
    return $out;
}

/* ============================================================
   0) OBJETIVOS: juegos sin fuentes (m.s los ya resueltos)
   ============================================================ */
$linea('============================================================');
$linea(' SINCRONIZA ENLACES EXTERNOS - Padrino Store Web');
$linea(' ' . date('Y-m-d H:i:s') . '  ·  PHP ' . PHP_VERSION);
$linea('============================================================');

$cat  = cargar_catalogo();
$prev = is_file($EXT_FILE) ? (json_decode((string)@file_get_contents($EXT_FILE), true) ?: []) : [];
$prevSet = array_flip(array_keys((array)($prev['games'] ?? [])));

$targets = [];
$revisados = 0;
foreach ($cat['games'] as $g) {
    $id = (string)$g['id'];
    $tiene = cargar_descargas($id, $cat['zstd'][$id] ?? null) !== [];
    if ($tiene && !isset($prevSet[$id])) continue;
    $targets[$id] = (string)$g['title'];
    $revisados++;
}
$linea('');
$linea('[0] OBJETIVOS');
$linea('    juegos revisados: ' . number_format($revisados) . ' · a sincronizar: ' . number_format(count($targets)));

/* ============================================================
   1) FUENTE A: cat.logos por titleId (sin fetch de fichas)
   ============================================================ */
$linea('');
$linea('[1] CATALOGOS POR ID');
$idxA = [];   // titleId => [ ['url'=>, 'label'=>, 'sizeGb'=>] ]
foreach (FUENTES_A as $slug) {
    $raw = http_get('https://pegasus-dl.dev/catalogs/' . $slug . '.json', "$DIR_A/$slug.json", TTL_LISTAS, $FORCE);
    if ($raw === null) { $linea('    ! ' . $slug . ': sin datos'); continue; }
    $j = json_decode($raw, true);
    if (!is_array($j)) { $linea('    ! ' . $slug . ': JSON inv.lido'); continue; }
    $n = 0;
    foreach ((array)($j['packages'] ?? []) as $p) {
        if (strtolower((string)($p['category'] ?? 'game')) !== 'game') continue;   // nada de DLC
        $id = strtoupper((string)($p['titleId'] ?? ''));
        if ($id === '') continue;
        $sizeGb = isset($p['sizeBytes']) && is_numeric($p['sizeBytes']) && $p['sizeBytes'] > 0
            ? round((float)$p['sizeBytes'] / 1073741824, 2) : null;
        foreach ((array)($p['downloadLinks'] ?? []) as $dl) {
            $u = (string)($dl['url'] ?? '');
            if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL) || !host_permitido($u)) continue;
            $idxA[$id][] = ['url' => $u, 'label' => trim((string)($dl['name'] ?? '')), 'sizeGb' => $sizeGb];
            $n++;
        }
    }
    $linea('    ' . str_pad($slug, 8) . ' enlaces utilizables: ' . number_format($n));
}

$matchA = [];
foreach (array_keys($targets) as $id) {
    if (isset($idxA[$id])) $matchA[$id] = $idxA[$id];
}
$linea('    objetivos cubiertos por ID exacto: ' . count($matchA));

/* ============================================================
   2) FUENTE B: listas por t.tulo (exacto + difuso)
   ============================================================ */
$idxB = [];   // norm_ex(t.tulo) => url de ficha
$tokB = [];   // norm_ex(t.tulo) => tokens
$candB = [];  // id => ['url','metodo','score']

if (!$SOLPEG) {
    $linea('');
    $linea('[2] LISTAS POR TITULO');
    $listas = [
        ['ps4', 'https://dlpsgame.com/list-all-game-ps4/'],
        ['ps5', 'https://dlpsgame.com/list-game-ps5/'],
    ];
    foreach ($listas as [$k, $u]) {
        $html = http_get($u, "$DIR_B/$k.html", TTL_LISTAS, $FORCE);
        if ($html === null) { $linea('    ! lista ' . $k . ': sin datos'); continue; }
        $n = 0;
        preg_match_all('/<a class="title" href="(https:\/\/dlpsgame\.com\/[^"]+)">([^<]+)<\/a>/', $html, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $t = norm_ex($x[2]);
            if ($t === '' || strlen($t) < 3 || isset($idxB[$t])) continue;
            $idxB[$t] = $x[1];
            $tokB[$t] = tok($t);
            $n++;
        }
        $linea('    ' . str_pad($k, 4) . ' titulos nuevos: ' . number_format($n));
    }
    $linea('    indice total: ' . number_format(count($idxB)));

    $nExacto = 0;
    if ($idxB) {
        foreach (array_keys($targets) as $id) {
            if (isset($matchA[$id])) continue;
            $nt = norm_ex($targets[$id]);
            if ($nt === '') continue;
            if (isset($idxB[$nt])) {
                $candB[$id] = ['url' => $idxB[$nt], 'metodo' => 'exacto', 'score' => 1.0];
                $nExacto++;
                continue;
            }
            // difuso: contenci.n de subcadena o Jaccard >= 0,85
            $tt = tok($nt);
            $best = [0.0, '', 'fuzzy'];
            foreach ($idxB as $t => $url) {
                if (strlen($nt) >= 5 && strlen($t) >= 5 && (str_contains($t, $nt) || str_contains($nt, $t))) {
                    if ($best[0] < 1.0) $best = [1.0, $url, 'contencion'];
                    continue;
                }
                $j = jaccard($tt, $tokB[$t]);
                if ($j > $best[0]) $best = [$j, $url, 'fuzzy'];
            }
            if ($best[0] >= JACCARD_MIN && $best[1] !== '') {
                $candB[$id] = ['url' => $best[1], 'metodo' => $best[2], 'score' => round($best[0], 3)];
            }
        }
    }
    $nDif = 0;
    foreach ($candB as $c) if ($c['metodo'] !== 'exacto') $nDif++;
    $linea('    exactos: ' . $nExacto . ' · difusos/contencion: ' . $nDif);
}

/* ============================================================
   3) BUSQUEDA REST para los que siguen sin candidato
   ============================================================ */
if (!$SOLPEG) {
    $linea('');
    $linea('[3] BUSQUEDA REST DE LOS QUE QUEDAN');
    $pendientes = [];
    foreach (array_keys($targets) as $id) {
        if (!isset($matchA[$id]) && !isset($candB[$id])) $pendientes[] = $id;
    }
    $linea('    sin candidato todavia: ' . count($pendientes));
    $nWpEx = 0;
    $nWpDif = 0;
    foreach ($pendientes as $id) {
        $q = rawurlencode($targets[$id]);
        $raw = http_get(
            "https://dlpsgame.com/wp-json/wp/v2/posts?search=$q&_fields=title,link&per_page=10",
            "$DIR_P/wp_" . md5($q) . '.json',
            TTL_POSTS,
            $FORCE
        );
        if ($raw === null) continue;
        $posts = json_decode($raw, true);
        if (!is_array($posts) || !$posts) continue;
        $nt  = norm_ex($targets[$id]);
        $tt  = tok($nt);
        $best = [0.0, '', 'wp_fuzzy'];
        foreach ($posts as $p) {
            $pt = norm_ex((string)($p['title']['rendered'] ?? ''));
            $ln = (string)($p['link'] ?? '');
            if ($ln === '') continue;
            if ($pt !== '' && $pt === $nt) { $best = [1.0, $ln, 'wp_exacto']; break; }
            $j = jaccard($tt, tok($pt));
            if ($j > $best[0]) $best = [$j, $ln, 'wp_fuzzy'];
        }
        if ($best[1] === '') continue;
        if ($best[2] === 'wp_exacto') {
            $candB[$id] = ['url' => $best[1], 'metodo' => 'wp_exacto', 'score' => 1.0];
            $nWpEx++;
        } elseif ($best[0] >= JACCARD_MIN) {
            $candB[$id] = ['url' => $best[1], 'metodo' => 'wp_fuzzy', 'score' => round($best[0], 3)];
            $nWpDif++;
        }
    }
    $linea('    exactos: ' . $nWpEx . ' · difusos: ' . $nWpDif);
}

/* ============================================================
   3b) BÚSQUEDA POR ID: el ID en texto plano está indexado
   ============================================================ */
$linea('');
$linea('[3b] BUSQUEDA POR ID DIRECTO');
$nId = 0;
foreach (array_keys($targets) as $id) {
    if (isset($matchA[$id]) || isset($candB[$id])) continue;
    $raw = http_get(
        'https://dlpsgame.com/wp-json/wp/v2/posts?search=' . rawurlencode($id) . '&_fields=title,link&per_page=5',
        "$DIR_P/wp_id_" . $id . '.json',
        TTL_POSTS,
        $FORCE
    );
    if ($raw === null) continue;
    $posts = json_decode($raw, true);
    if (!is_array($posts) || !$posts) continue;
    $ln = (string)($posts[0]['link'] ?? '');
    if ($ln === '') continue;
    // método "wp_id": solo se acepta si el fetch confirma que el ID aparece
    $candB[$id] = ['url' => $ln, 'metodo' => 'wp_id', 'score' => 1.0];
    $nId++;
}
$linea('    candidatos por ID: ' . $nId);

/* ============================================================
   4) FICHAS: fetch (1 req/s) + extracci.n + validaci.n
   ============================================================ */
$linea('');
$linea('[4] FICHAS' . ($SINFETCH ? ' (solo cache: --sin-fetch)' : ' (~1 petici.n/segundo)'));

$confirmados = [];   // id => entradas
$porMetodo   = [];
$erroresFetch = [];

// Fuente A: confirmados sin fetch (ID exacto)
foreach ($matchA as $id => $links) {
    $h = [
        'urls' => array_column($links, 'url'),
        'labels' => [],
        'pass' => null,
        'sizeGb' => $links[0]['sizeGb'] ?? null,
        'idEn' => true,
        'nucleo' => '',
    ];
    // etiqueta por URL desde el name del enlace
    foreach ($links as $l) if ($l['label'] !== '') $h['labels'][$l['url']] = $l['label'];
    $ents = entradas_desde_hallazgo($h, 'A', null);
    if ($ents) { $confirmados[$id] = $ents; $porMetodo['A'] = ($porMetodo['A'] ?? 0) + 1; }
}

$fetchList = [];
foreach ($candB as $id => $c) $fetchList[$id] = $c['url'];
$linea('    fichas por traer: ' . count($fetchList));

$n = 0;
$tot = count($fetchList);
foreach ($fetchList as $id => $url) {
    $n++;
    $cache = $DIR_P . '/' . md5($url) . '.html';
    if ($SINFETCH) {
        $html = is_file($cache) ? (string)@file_get_contents($cache) : null;
        if ($html === null || $html === '') {
            $errores[] = 'sin cache (--sin-fetch): ' . $url;
            $erroresFetch[$id] = true;
            continue;
        }
    } else {
        $html = http_get($url, $cache, TTL_POSTS, $FORCE);
        if ($html === null) { $erroresFetch[$id] = true; continue; }
    }

    $h = extraer_post($html, $id);
    $metodo = $candB[$id]['metodo'];
    $score  = $candB[$id]['score'];

    // validaci.n anti-falsos-positivos
    $confirmado = $h['idEn'] || in_array($metodo, ['exacto', 'wp_exacto'], true);
    if (!$confirmado) {
        $dudosos[] = [
            'id' => $id, 'title' => $targets[$id], 'metodo' => $metodo,
            'score' => $score, 'post' => $url, 'urls' => count($h['urls']),
        ];
        continue;
    }
    $ents = entradas_desde_hallazgo($h, 'B', $url);
    if ($ents) {
        $confirmados[$id] = $ents;
        $porMetodo[$metodo] = ($porMetodo[$metodo] ?? 0) + 1;
    } else {
        $sinEnlacesId[$id] = ['id' => $id, 'title' => $targets[$id], 'motivo' => 'sin_enlaces_permitidos', 'post' => $url];
    }
    if ($n % 25 === 0) $linea('    ... ' . $n . '/' . $tot);
}
$dudosos = $dudosos ?? [];
$sinEnlacesId = $sinEnlacesId ?? [];
$linea('    fichas procesadas: ' . $n . '/' . $tot);

/* 4b) SEGUNDA PASADA: los dudosos (contención al juego equivocado) y los
       confirmados sin hosts permitidos prueban búsqueda por ID: el post
       correcto puede ser otro distinto y el ID en texto plano lo delata. */
$segunda = array_merge($dudosos, array_values($sinEnlacesId));
if ($segunda) {
    $linea('    segunda pasada por ID: ' . count($segunda));
    $dudIdx = [];
    foreach ($dudosos as $i => $d) $dudIdx[$d['id']] = $i;
    $sinIdx = [];
    foreach ($sinEnlacesId as $i => $s) $sinIdx[$s['id']] = $i;

    foreach ($segunda as $d) {
        $id  = $d['id'];
        $viejo = (string)($d['post'] ?? '');
        $raw = http_get(
            'https://dlpsgame.com/wp-json/wp/v2/posts?search=' . rawurlencode($id) . '&_fields=title,link&per_page=5',
            "$DIR_P/wp_id_" . $id . '.json',
            TTL_POSTS,
            $FORCE
        );
        if ($raw === null) continue;
        $posts = json_decode($raw, true);
        if (!is_array($posts) || !$posts) continue;

        foreach (array_slice($posts, 0, 3) as $p) {
            $ln = (string)($p['link'] ?? '');
            if ($ln === '' || $ln === $viejo) continue;   // el mismo post ya falló
            $html = http_get($ln, $DIR_P . '/' . md5($ln) . '.html', TTL_POSTS, $FORCE);
            if ($html === null) continue;
            $h = extraer_post($html, $id);
            if (!$h['idEn']) continue;                    // el ID no aparece: no sirve
            $ents = entradas_desde_hallazgo($h, 'B', $ln);
            if (!$ents) continue;
            $confirmados[$id] = $ents;
            $porMetodo['wp_id'] = ($porMetodo['wp_id'] ?? 0) + 1;
            if (isset($dudIdx[$id])) unset($dudosos[$dudIdx[$id]]);
            if (isset($sinIdx[$id])) unset($sinEnlacesId[$sinIdx[$id]]);
            break;
        }
    }
    $dudosos = array_values($dudosos);
    $sinEnlacesId = array_values($sinEnlacesId);
    $linea('    recuperados en la segunda pasada: ' . ($segundaCount = count($segunda) - count($dudosos) - count($sinEnlacesId)));
}

/* ============================================================
   5) RESULTADOS + escritura de externos.json + informes
   ============================================================ */
$linea('');
$linea('[5] RESULTADOS');

$dudIds = [];
foreach ($dudosos as $d) $dudIds[$d['id']] = true;
$sinIds = [];
foreach ($sinEnlacesId as $s) $sinIds[$s['id']] = true;

$sinCubrir = $sinEnlacesId;
foreach (array_keys($targets) as $id) {
    if (isset($confirmados[$id]) || isset($dudIds[$id]) || isset($sinIds[$id])) continue;
    $sinCubrir[] = [
        'id' => $id, 'title' => $targets[$id],
        'motivo' => isset($erroresFetch[$id]) ? 'fallo_fetch' : 'sin_candidato',
    ];
}

// si el fetch de una ficha fall,  se conserva su entrada anterior (plan §5)
$games = $confirmados;
$mantenidos = 0;
foreach (array_keys($erroresFetch) as $id) {
    if (!isset($games[$id]) && isset($prev['games'][$id]) && is_array($prev['games'][$id])) {
        $games[$id] = $prev['games'][$id];
        $mantenidos++;
    }
}

$salida = [
    'v'        => EXTS_CACHE_V,
    'generated'=> date(DATE_ATOM),
    'games'    => $games,
];
@mkdir(dirname($EXT_FILE), 0775, true);
file_put_contents($EXT_FILE, json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

$hosts = [];
foreach ($games as $ents) {
    foreach ($ents as $e) $hosts[(string)($e['provider'] ?? '?')] = ($hosts[(string)($e['provider'] ?? '?')] ?? 0) + 1;
}
arsort($hosts);

$nConf = count($confirmados);
$nDud  = count($dudosos);
$nSin  = count($sinCubrir);
$dur   = (int)round(microtime(true) - $t0);

$linea('    confirmados: ' . $nConf . ' · dudosos: ' . $nDud . ' · sin cubrir: ' . number_format($nSin) . ' · conservados por error: ' . $mantenidos);
$linea('    externos en cache: ' . number_format(array_sum($hosts)) . ' enlaces · ' . number_format(count($games)) . ' juegos');
$linea('    errores HTTP/red: ' . count($errores));
$linea('    duracion: ' . intdiv($dur, 60) . ' min ' . ($dur % 60) . ' s');

if ($sinCubrir) {
    $linea('');
    $linea('    sin cubrir (' . count($sinCubrir) . '):');
    foreach (array_slice($sinCubrir, 0, 25) as $s) {
        $linea('      ' . $s['id'] . ' | ' . $s['title'] . ' (' . $s['motivo'] . ')');
    }
    if (count($sinCubrir) > 25) $linea('      ... y ' . (count($sinCubrir) - 25) . ' m.s (en el JSON)');
}

if ($REVISAR && $dudosos) {
    $linea('');
    $linea('[REVISAR] DUDOSOS (' . count($dudosos) . '):');
    foreach ($dudosos as $d) {
        $linea(sprintf('    %s | %s | metodo=%s score=%.2f urls=%d', $d['id'], $d['title'], $d['metodo'], $d['score'], $d['urls']));
        $linea('        ' . $d['post']);
    }
}

/* --warm: regenera la cach. de releases de los afectados */
if ($WARM) {
    $linea('');
    $linea('[WARM] regenerando cache/rels de los juegos con fuentes externas');
    $w = 0;
    foreach (array_keys($salida['games']) as $id) {
        @unlink(CACHE_DIR . '/rels/' . $id . '.json');
        cargar_descargas($id, $cat['zstd'][$id] ?? null);
        $w++;
    }
    $linea('    regeneradas: ' . $w);
}

/* informes en cache/logs/ (patr.n auditoria.php) */
@mkdir(CACHE_DIR . '/logs', 0775, true);
$ts = date('Y-m-d-His');
$Informe     = CACHE_DIR . '/logs/sincroniza-enlaces-' . $ts . '.txt';
$InformeJson = CACHE_DIR . '/logs/sincroniza-enlaces-' . $ts . '.json';
$linea('');
$linea('informes: ' . $Informe);
file_put_contents($Informe, implode("\n", $out) . "\n", LOCK_EX);
file_put_contents($InformeJson, json_encode([
    'generado'            => date(DATE_ATOM),
    'duración_s'          => $dur,
    'objetivos'           => count($targets),
    'confirmados'         => $nConf,
    'por_método'          => $porMetodo,
    'dudosos'             => $dudosos,
    'sin_cubrir'          => $sinCubrir,
    'conservados_por_error' => $mantenidos,
    'hosts'               => $hosts,
    'errores'             => $errores,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

echo "\nListo. Confirmados: $nConf · dudosos: $nDud · sin cubrir: $nSin\n";
