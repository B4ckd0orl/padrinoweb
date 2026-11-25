<?php
declare(strict_types=1);

/* ============================================================
   Descargas: releases/<id>.json + snapshot ZSTD → fuentes válidas
   - TODA fuente lleva formato visible (obligatorio). Si el campo
     format es "binary" se infiere por niveles:
       1. extensión en la URL          5. password/volumen → RAR
       2. tokens en el label           6. ese servidor solo da 1 formato
       3. misma edición + servidor     7. mismo juego, otro servidor
       4. contraseña/volumen           8. grupo único del juego / priors
     (el nivel 1-2 es exactamente lo que hace la app PS5:
      game/sources.js inferFormat; el resto es inferencia propia)
   - Los enlaces ZSTD de la comunidad (state/zstd-snapshot.js)
     se añaden como fuentes propias etiquetadas como ZSTD.
   ============================================================ */

/** Versión del formato de caché (invalida cachés antiguas: filtro de hosts + orden por velocidad). */
const RELS_CACHE_V = 6;

/** Versión del índice de fuentes externas (cache/fuentes/externos.json). */
const EXTS_CACHE_V = 1;

/** Máximo de fuentes visibles en el modal (antes era 5). */
const MAX_FUENTES = 7;

/* ------------------------------------------------------------
   Hosts de archivo: allowlist + prioridad de descarga
   - HOSTS_ARCHIVO: dominios que la web acepta como fuente.
     NO incluye mediafire (retirado a petición) ni puentes de
     descarga que exigen navegador.
   - HOST_RAPIDO: orden de preferencia por velocidad de bajada
     (0 = más rápido). Los desconocidos van al final.
   - Solo se usa en CLI y en el modal: ningún nombre de web
     ajena a Padrino se refleja en la salida pública.
   ------------------------------------------------------------ */
const HOSTS_ARCHIVO = [
    'vikingfile.com', 'vik1ngfile.site',
    'akirabox.com', 'akirabox.to',
    'datanodes.to', 'datanodes.co',
    'filekeeper.net', 'datavaults.co',
    'gofile.io', 'buzzheavier.com',
    'ranoz.gg', 'link-vault.org',
    'rootz.so', '1fichier.com',
    'rapidgator.net', 'filecrypt.cc',
    'archive.org', 'transfer.it',
    'mega.nz', 'fuckingfast.net',
    'fileditchfiles.st', 'theditch.st',
    '1024terabox.com',
];

/** Orden por velocidad: cuanto menor, antes se ofrece al usuario. */
const HOST_RAPIDO = [
    'vikingfile.com' => 0, 'vik1ngfile.site' => 0,
    'akirabox.com' => 1, 'akirabox.to' => 1,
    'datanodes.to' => 2, 'datanodes.co' => 2,
    'filekeeper.net' => 3, 'datavaults.co' => 4,
    'gofile.io' => 5, 'buzzheavier.com' => 6,
    'ranoz.gg' => 7, 'link-vault.org' => 8,
    'rootz.so' => 9, '1fichier.com' => 10,
    'rapidgator.net' => 11, 'filecrypt.cc' => 12,
    'archive.org' => 13, 'transfer.it' => 14,
    'mega.nz' => 15, 'fuckingfast.net' => 16,
    'fileditchfiles.st' => 17, 'theditch.st' => 18,
    '1024terabox.com' => 19,
];

/** Host de una URL en minúsculas ('' si no hay). */
function host_url(string $u): string
{
    return $u === '' ? '' : strtolower((string)(parse_url($u, PHP_URL_HOST) ?: ''));
}

/** ¿Esta URL apunta a un host de archivo permitido? (niega mediafire y puentes) */
function host_permitido(string $u): bool
{
    if (str_starts_with($u, 'magnet:?')) return true;
    if (str_contains($u, '.torrent') || str_contains($u, 'f=torrent')) return true;
    $h = host_url($u);
    if ($h === '') return false;
    foreach (HOSTS_ARCHIVO as $d) {
        if ($h === $d || str_ends_with($h, '.' . $d)) return true;
    }
    return false;
}

/** Prioridad de velocidad del host de una URL (50 = desconocido). */
function prioridad_host(string $u): int
{
    if (str_starts_with($u, 'magnet:?') || str_contains($u, '.torrent')) return 1;
    $h = host_url($u);
    foreach (HOST_RAPIDO as $d => $r) {
        if ($h === $d || str_ends_with($h, '.' . $d)) return $r;
    }
    return 50;
}

/** Nombre de proveedor canónico de un host (coincide con los releases propios). */
function provider_de_host(string $u): string
{
    if (str_starts_with($u, 'magnet:?')) return 'Magnet (P2P)';
    if (str_contains($u, '.torrent')) return 'Torrent (P2P)';
    $h = host_url($u);
    $map = [
        'vikingfile.com' => 'VikingFile', 'vik1ngfile.site' => 'VikingFile',
        'akirabox.com' => 'AkiraBox', 'akirabox.to' => 'AkiraBox',
        'datanodes.to' => 'DataNodes', 'datanodes.co' => 'DataNodes',
        'rootz.so' => 'Rootz', 'buzzheavier.com' => 'Buzzheavier',
    ];
    foreach ($map as $d => $p) if ($h === $d || str_ends_with($h, '.' . $d)) return $p;
    foreach (HOSTS_ARCHIVO as $d) {
        if ($h === $d || str_ends_with($h, '.' . $d)) return $d;
    }
    return $h !== '' ? preg_replace('/^www\./', '', $h) : 'web';
}

/* ------------------------------------------------------------
   Índice de formatos por juego (app/data/formats.js)
   juego → grupos declarados: pkg|exfat|ffpfs|archive
   ------------------------------------------------------------ */
function indice_formats(): array
{
    static $idx = null;
    if ($idx !== null) return $idx;
    $idx = [];
    $raw = @file_get_contents(STORE_FORMATS);
    if (!is_string($raw) || $raw === '') return $idx;
    $p = strpos($raw, 'var formats = {');
    if ($p === false) return $idx;
    $i = $p + strlen('var formats = ');
    $depth = 0; $inStr = false; $esc = false; $end = -1; $len = strlen($raw);
    for (; $i < $len; $i++) {
        $ch = $raw[$i];
        if ($inStr) {
            if ($esc)      { $esc = false; }
            elseif ($ch === '\\') { $esc = true; }
            elseif ($ch === '"')  { $inStr = false; }
            continue;
        }
        if ($ch === '"') { $inStr = true; }
        elseif ($ch === '{') { $depth++; }
        elseif ($ch === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    if ($end < 0) return $idx;
    $obj = substr($raw, $p + strlen('var formats = '), $end - ($p + strlen('var formats = ')) + 1);
    $j = json_decode($obj, true);
    if (is_array($j)) {
        foreach ($j as $id => $groups) {
            if (is_string($id) && is_array($groups)) {
                $idx[$id] = array_values(array_filter(array_map('strval', $groups)));
            }
        }
    }
    return $idx;
}

/* ------------------------------------------------------------
   Priors globales de formato por proveedor y por (proveedor|fuente)
   Se recalculan solo si cambian los releases (caché en cache/fmtpriors.json)
   ------------------------------------------------------------ */
function priors_fmt(bool $rebuild = false): array
{
    static $memo = null;
    $cache = CACHE_DIR . '/fmtpriors.json';

    if (!$rebuild) {
        if ($memo !== null) return $memo;
        // La web SOLO lee: el barrido de los ~4.000 releases es cosa de CLI
        // (scripts/priors.php y la auditoría diaria). Si no hay caché, sin priors.
        $j = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
        if (is_array($j) && (int)($j['v'] ?? 0) === 1 && is_array($j['psrc'] ?? null) && is_array($j['prov'] ?? null)) {
            return $memo = $j;
        }
        return $memo = ['v' => 1, 'psrc' => [], 'prov' => []];
    }

    $tab = ['v' => 1, 'psrc' => [], 'prov' => []];
    $files = @scandir(STORE_REL);
    if (is_array($files)) {
        foreach ($files as $f) {
            if (substr($f, -5) !== '.json') continue;
            $rel = json_decode((string)@file_get_contents(STORE_REL . '/' . $f), true);
            if (!is_array($rel)) continue;
            foreach ($rel as $r) {
                if (!is_array($r) || !empty($r['unavailableReason'])) continue;
                $fmt = strtolower((string)($r['format'] ?? ''));
                if ($fmt === '' || $fmt === 'binary' || $fmt === 'unknown') continue;
                $prov = (string)($r['provider'] ?? '');
                $k1 = $prov . '|' . (string)($r['source'] ?? '');
                foreach ([['psrc', $k1], ['prov', $prov]] as [$dim, $k]) {
                    $tab[$dim][$k][$fmt] = ($tab[$dim][$k][$fmt] ?? 0) + 1;
                }
            }
        }
    }
    foreach (['psrc', 'prov'] as $dim) {
        foreach ($tab[$dim] as $k => $d) {
            arsort($d);
            $best = (string)array_key_first($d);
            $tot  = array_sum($d);
            if ($tot < 20 || $d[$best] / $tot < 0.9) unset($tab[$dim][$k]);
            else $tab[$dim][$k] = $best;
        }
    }
    @mkdir(dirname($cache), 0775, true);
    @file_put_contents($cache, json_encode($tab, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $memo = $tab;
}

/* ------------------------------------------------------------
   Clave de "edición": el label sin el sufijo del servidor ni
   tokens de formato ni "part N" → sirve para heredar formato
   entre enlaces del mismo juego/edición.
   ------------------------------------------------------------ */
function ed_key(string $label): string
{
    $s = strtolower($label);
    $p = strpos($s, ' - ');
    if ($p !== false) $s = substr($s, 0, $p);
    $s = preg_replace('/\s+part\s*\d+$/', '', $s) ?? $s;
    $s = preg_replace('/(^|\s)(exfat|rar|7z|zip|pkg|ffpfs|ffpfsc|ffpkg|lz4|iso|bin)(?=\s|$)/i', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

/**
 * Contexto de inferencia de un juego: formatos declarados por edición
 * (por proveedor y en general), por proveedor y formato dominante del juego.
 * @return array{edProv:array<string,string>,gp:array<string,string>,edAny:array<string,string>,gameFmt:?string}
 */
function ctx_formato(array $rel, string $id): array
{
    $ctx = ['edProv' => [], 'gp' => [], 'edAny' => [], 'gameFmt' => null];
    $gfmt = [];
    foreach ($rel as $r) {
        if (!is_array($r) || !empty($r['unavailableReason'])) continue;
        $fmt = strtolower((string)($r['format'] ?? ''));
        if ($fmt === '' || $fmt === 'binary' || $fmt === 'unknown') continue;
        $gfmt[$fmt] = ($gfmt[$fmt] ?? 0) + 1;
        $prov = strtolower((string)($r['provider'] ?? ''));
        $k  = ed_key((string)($r['label'] ?? ''));
        $kp = $k . '|' . $prov;
        if (!isset($ctx['edProv'][$kp])) $ctx['edProv'][$kp] = [$fmt => 1];
        else $ctx['edProv'][$kp][$fmt] = 1;
        if (!isset($ctx['edAny'][$k])) $ctx['edAny'][$k] = [$fmt => 1];
        else $ctx['edAny'][$k][$fmt] = 1;
        if (!isset($ctx['gp'][$prov])) $ctx['gp'][$prov] = [$fmt => 1];
        else $ctx['gp'][$prov][$fmt] = 1;
    }
    // solo se hereda si TODOS los enlaces de esa edición/Proveedor coinciden en un formato
    foreach (['edProv', 'gp', 'edAny'] as $dim) {
        foreach ($ctx[$dim] as $k => $set) {
            if (count($set) === 1) $ctx[$dim][$k] = (string)array_key_first($set);
            else unset($ctx[$dim][$k]);
        }
    }
    // formato del juego: solo si todos sus formatos declarados caen en UN grupo
    $groups = indice_formats()[$id] ?? null;
    if (is_array($groups) && count($groups) === 1 && $gfmt) {
        arsort($gfmt);
        $ctx['gameFmt'] = (string)array_key_first($gfmt);
    }
    return $ctx;
}

/**
 * Formato visible de un enlace. Nunca vacío.
 * Orden: declarado → URL → label → edición+servidor → password/volumen →
 *        servidor del juego → edición otro servidor → grupo único → priors → binario.
 * @param array|null $ctx ctx_formato()
 * @return string minúsculas: pkg|exfat|ffpfs|ffpfsc|ffpkg|zip|rar|7z|tar|tgz|lz4|zstd|binario
 */
function formato_enlace(array $r, ?array $ctx = null): string
{
    $fmt = strtolower((string)($r['format'] ?? ''));
    if ($fmt !== '' && $fmt !== 'binary' && $fmt !== 'unknown') return $fmt;

    // 1) extensión del último segmento de la URL (browserUrl primero, como la app)
    foreach ([(string)($r['browserUrl'] ?? ''), (string)($r['url'] ?? '')] as $u) {
        if ($u === '') continue;
        $path = parse_url($u, PHP_URL_PATH);
        if (!$path) continue;
        $segs = array_reverse(array_values(array_filter(explode('/', $path), static fn($s) => $s !== '')));
        foreach ($segs as $seg) {
            $seg = rawurldecode($seg);
            if (preg_match('/\.([a-z0-9]{1,10})$/i', $seg, $m)
                && preg_match('/^(ffpfsc|ffpfs|ffpkg|exfat|pkg|fpkg|lz4|zip|rar|7z|tar|tgz)$/i', $m[1])) {
                return strtolower($m[1]);
            }
        }
    }

    // 2) el label que lo dice: tokens exóticos, extensión con punto, o token de formato
    $lab = (string)($r['label'] ?? '');
    if (preg_match('/\b(ffpfs|ffpfsc|ffpkg|fpkg|exfat|lz4)\b/i', $lab, $m)) {
        $t = strtolower($m[1]);
        return $t === 'fpkg' ? 'pkg' : (str_starts_with($t, 'ffpfs') ? 'ffpfs' : $t);
    }
    if (preg_match('/\.(zip|rar|7z|pkg|tar|tgz)\b/i', $lab, $m)) return strtolower($m[1]);
    if (preg_match('/\b(rar|7z|zip|pkg|tar|tgz|iso)\b/i', $lab, $m)) return strtolower($m[1]);

    if (is_array($ctx)) {
        $labK = ed_key($lab);
        // 3) la misma edición publicada por el MISMO servidor con formato declarado
        $kp = $labK . '|' . strtolower((string)($r['provider'] ?? ''));
        if (isset($ctx['edProv'][$kp]) && is_string($ctx['edProv'][$kp])) return $ctx['edProv'][$kp];

        // 4) contraseña o volumen → archivador (nunca se comprime un PKG/imagen)
        if (!empty($r['archivePassword']) || !empty($r['volume'])) return 'rar';

        // 5) el MISMO servidor solo ofrece un formato en este juego
        $gp = strtolower((string)($r['provider'] ?? ''));
        if (isset($ctx['gp'][$gp]) && is_string($ctx['gp'][$gp])) return $ctx['gp'][$gp];

        // 6) la misma edición declarada por otro servidor
        if (isset($ctx['edAny'][$labK]) && is_string($ctx['edAny'][$labK])) return $ctx['edAny'][$labK];

        // 7) el juego solo ofrece UN grupo de formatos
        if (!empty($ctx['gameFmt']) && is_string($ctx['gameFmt'])) return $ctx['gameFmt'];

        // 8) prior estadístico (≥90% de confianza, ≥20 muestras)
        $pr = priors_fmt();
        $pk = $gp . '|' . (string)($r['source'] ?? '');
        if (isset($pr['psrc'][$pk]) && is_string($pr['psrc'][$pk])) return $pr['psrc'][$pk];
        if (isset($pr['prov'][$gp]) && is_string($pr['prov'][$gp])) return $pr['prov'][$gp];
    }

    // 9) sin datos: la app lo trata como desconocido; aquí se declara binario
    return 'binario';
}

/**
 * Fuentes ZSTD del snapshot de la comunidad → entradas del modal.
 * @return array<int, array>
 */
function fuentes_zstd(?array $zstd): array
{
    if (!is_array($zstd) || empty($zstd['links']) || !is_array($zstd['links'])) return [];

    $ver   = trim((string)($zstd['version'] ?? ''));
    $size  = trim((string)($zstd['size'] ?? ''));
    $label = trim(implode(' · ', array_filter(['ZSTD', $ver, $size])));

    $nota = trim(implode(' · ', array_filter([
        !empty($zstd['firmware']) ? 'FW ' . $zstd['firmware'] : '',
        !empty($zstd['ampr'])     ? 'AMPR ' . $zstd['ampr'] : '',
        !empty($zstd['notes'])    ? (string)$zstd['notes'] : '',
    ])));

    $sizeGb = null;
    if (preg_match('/(\d+(?:\.\d+)?)\s*GB/i', $size, $m)) $sizeGb = round((float)$m[1], 2);

    $out = [];
    foreach (array_values(array_unique($zstd['links'])) as $url) {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !host_permitido($url)) continue;
        $host  = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $flow  = str_contains($host, 'vik1ng') || str_contains($host, 'viking') ? 'vikingfile'
               : (str_contains($host, 'gofile') ? 'gofile.io' : ($host !== '' ? $host : 'web'));
        $out[] = [
            'provider' => 'ZSTD',
            'label'    => texto_visible($label),
            'url'      => $url,
            'sizeGb'   => $sizeGb,
            'flow'     => $flow,
            'format'   => 'zstd',
            'health'   => null,
            'pass'     => null,
            'zstd'     => true,
            'note'     => texto_visible($nota),
        ];
    }
    return $out;
}

/** @return array<int, array{provider:string,label:string,url:string,sizeGb:?float,flow:string,format:string,health:?string,pass:?string,zstd?:bool,note?:string}> */
function cargar_descargas(string $id, ?array $zstd = null): array
{
    if (!id_valido($id)) return [];
    $file = STORE_REL . '/' . $id . '.json';
    $snapTs = @filemtime(STORE_ZSTD) ?: 0;
    if (!is_file($file) && $snapTs === 0) return [];

    $cache = CACHE_DIR . '/rels/' . $id . '.json';
    $srcTs = max(@filemtime($file) ?: 0, $snapTs);
    if (is_file($cache) && (@filemtime($cache) ?: 0) >= $srcTs) {
        $j = json_decode((string)@file_get_contents($cache), true);
        if (is_array($j) && (int)($j['v'] ?? 0) === RELS_CACHE_V && isset($j['src']) && is_array($j['src'])) {
            // la caché guarda SOLO los releases: ZSTD y externos se fusionan en cada petición
            return componer_fuentes($j['src'], $zstd, $id);
        }
    }

    $rel = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (!is_array($rel)) $rel = [];

    // contexto de inferencia: ediciones declaradas de ESTE juego + grupo único
    $ctx = ctx_formato($rel, $id);

    // solo fuentes vivas de hosts permitidos (sin mediafire ni acortadores)
    $ok = [];
    foreach ($rel as $r) {
        if (!is_array($r)) continue;
        if (!empty($r['unavailableReason'])) continue;
        $url = (string)($r['url'] ?? '');
        if ($url === '') continue;
        $esP2P = str_starts_with($url, 'magnet:?') || str_contains($url, '.torrent') || str_contains($url, 'f=torrent');
        if (!$esP2P) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($scheme !== 'https' && $scheme !== 'http') continue;
            if (!host_permitido($url)) continue;
        }
        $ok[] = $r;
    }
    // primero por velocidad de host (vikingfile y compañía, como pidió el
    // usuario); a igual prioridad, las sanas (ok) → sin datos → "flaky"
    $rango = static fn(array $r): int => (($r['health'] ?? '') === 'ok') ? 0 : (((string)($r['health'] ?? '') === 'flaky') ? 2 : 1);
    usort($ok, function ($a, $b) use ($rango) {
        $pa = prioridad_host((string)($a['url'] ?? ''));
        $pb = prioridad_host((string)($b['url'] ?? ''));
        if ($pa !== $pb) return $pa <=> $pb;
        return $rango($a) <=> $rango($b);
    });

    $seen = []; $out = [];
    foreach ($ok as $r) {
        $prov = strtolower((string)($r['provider'] ?? 'x'));
        if (isset($seen[$prov])) continue;
        $seen[$prov] = true;
        $size = isset($r['sizeBytes']) && is_numeric($r['sizeBytes']) && $r['sizeBytes'] > 0
            ? round((float)$r['sizeBytes'] / 1073741824, 2) : null;
        $out[] = [
            'provider' => (string)($r['provider'] ?? ''),
            'label'    => texto_visible((string)((string)($r['label'] ?? '') !== '' ? $r['label'] : ($r['provider'] ?? ''))),
            'url'      => (string)$r['url'],
            'sizeGb'   => $size,
            'flow'     => (string)($r['flow'] ?? ''),
            'format'   => formato_enlace($r, $ctx),   // siempre presente
            'health'   => isset($r['health']) ? (string)$r['health'] : null,
            'pass'     => !empty($r['archivePassword']) ? (string)$r['archivePassword'] : null,
        ];
        if (count($out) >= MAX_FUENTES) break;
    }

    @mkdir(dirname($cache), 0775, true);
    @file_put_contents($cache, json_encode(['v' => RELS_CACHE_V, 'src' => $out], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return componer_fuentes($out, $zstd, $id);
}

/**
 * Fuentes externas aportadas por `scripts/sincronizar_enlaces.php`
 * (cache/fuentes/externos.json — interna, bloqueada por .htaccess).
 * Se ordenan por velocidad de host; el formato ya viene precalculado.
 * @return array<int, array>
 */
function fuentes_externas(string $id): array
{
    static $idx = null;
    if ($idx === null) {
        $idx = [];
        $file = CACHE_DIR . '/fuentes/externos.json';
        if (is_file($file)) {
            $j = json_decode((string)@file_get_contents($file), true);
            if (is_array($j) && (int)($j['v'] ?? 0) === EXTS_CACHE_V && is_array($j['games'] ?? null)) {
                $idx = $j['games'];
            }
        }
    }
    $list = $idx[$id] ?? null;
    if (!is_array($list) || !$list) return [];

    $out = [];
    foreach ($list as $e) {
        if (!is_array($e)) continue;
        $url = (string)($e['url'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !host_permitido($url)) continue;
        $prov = (string)($e['provider'] ?? '') ?: provider_de_host($url);
        $out[] = [
            'provider' => $prov,
            'label'    => texto_visible((string)($e['label'] ?? '') !== '' ? (string)$e['label'] : $prov),
            'url'      => $url,
            'sizeGb'   => isset($e['sizeGb']) && is_numeric($e['sizeGb']) ? round((float)$e['sizeGb'], 2) : null,
            'flow'     => (string)($e['flow'] ?? '') !== '' ? (string)$e['flow'] : (explode('.', host_url($url))[0] ?? ''),
            'format'   => formato_enlace($e, null),   // precalculado por el sync; si falta, se infiere
            'health'   => null,
            'pass'     => !empty($e['pass']) ? (string)$e['pass'] : null,
            'ext'      => true,                        // interno: se elimina en api/detalle.php
        ];
    }
    usort($out, fn($a, $b) => prioridad_host($a['url']) <=> prioridad_host($b['url']));
    return $out;
}

/**
 * Orden final de fuentes: ZSTD → releases propios → externos (los propios
 * priman), deduplicando por URL y por proveedor, con tope MAX_FUENTES.
 * @param array<int, array> $propias fuentes ya construidas por cargar_descargas()
 */
function componer_fuentes(array $propias, ?array $zstd, string $id): array
{
    $res  = fuentes_zstd($zstd);
    $seenU = [];
    $seenP = [];
    foreach ($res as $r) {
        $seenU[(string)($r['url'] ?? '')] = true;
        $seenP[strtolower((string)($r['provider'] ?? ''))] = true;
    }

    foreach (array_merge($propias, fuentes_externas($id)) as $p) {
        if (count($res) >= MAX_FUENTES) break;
        $u  = (string)($p['url'] ?? '');
        $pr = strtolower((string)($p['provider'] ?? ''));
        if ($u === '' || isset($seenU[$u])) continue;
        if ($pr !== '' && isset($seenP[$pr])) continue;   // una por proveedor
        $seenU[$u] = true;
        if ($pr !== '') $seenP[$pr] = true;
        $res[] = $p;
    }
    return $res;
}
