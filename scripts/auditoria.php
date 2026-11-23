<?php
declare(strict_types=1);

/* ============================================================
   scripts/auditoria.php — informe de seguridad diario (solo CLI)
   Uso:  C:\xampp\php\php.exe C:\xampp\htdocs\padrinoweb\scripts\auditoria.php
   Salida: consola + cache/logs/auditoria-YYYY-MM-DD.txt
   ============================================================ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por consola\n");
}

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/descargas.php';   // priors_fmt() para la sección 6b

const APACHE_LOGS = 'C:/xampp/apache/logs';
const HISTORICO_H = 24;          // horas de historial analizadas
const TOP_N = 10;
const PATRONES = ['../', '..%2f', '/etc/passwd', 'wp-login', '.env', 'phpmyadmin',
                  'eval(', 'base64_', '<script', 'union%20select', '.git/', 'cgi-bin'];

$out = [];
$linea = function (string $t = '') use (&$out) { $out[] = $t; };

$linea('============================================================');
$linea(' AUDITORIA DE SEGURIDAD — Padrino Store Web');
$linea(' ' . date('Y-m-d H:i:s') . '  ·  PHP ' . PHP_VERSION);
$linea('============================================================');

/* ---------------- 1. Log de errores PHP ---------------- */
$phpLog = CACHE_DIR . '/logs/error.log';
$linea('');
$linea('[1] LOG PHP  (' . $phpLog . ')');
if (is_file($phpLog)) {
    $linea('    tamano: ' . number_format(filesize($phpLog)) . ' bytes · modificado: ' . date('Y-m-d H:i:s', filemtime($phpLog)));
    $ult = array_slice(array_values(array_filter(explode("\n", (string)file_get_contents($phpLog)))), -8);
    $recientes = array_filter($ult, fn($l) => $l !== '' && (time() - (@strtotime(substr($l, 1, 20)) ?: 0)) < HISTORICO_H * 3600);
    $linea('    lineas en las ultimas ' . HISTORICO_H . 'h: ' . count($recientes));
    foreach (array_slice(array_values($recientes), -5) as $l) $linea('      > ' . substr($l, 0, 220));
} else {
    $linea('    (sin errores PHP: no existe el archivo) ✓');
}

/* ---------------- 2. Log de errores Apache ---------------- */
$apacheErr = APACHE_LOGS . '/error.log';
$linea('');
$linea('[2] LOG APACHE (' . $apacheErr . ')');
if (is_file($apacheErr)) {
    $lineas = @file($apacheErr, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $corte = time() - HISTORICO_H * 3600;
    $recientes = [];
    foreach ($lineas as $l) {
        if (!preg_match('/^\[(\w{3}) (\w{3}) (\d{2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d+)? (\d{4})\]/', $l, $m)) {
            // líneas sin timestamp (p. ej. "PHP Warning: PHP Startup...")
            if (stripos($l, 'php ') === 0 || stripos($l, 'php:') !== false) $recientes[] = $l;
            continue;
        }
        $ts = strtotime("{$m[3]} {$m[2]} {$m[7]} {$m[4]}:{$m[5]}:{$m[6]}");
        if ($ts !== false && $ts >= $corte) $recientes[] = $l;
    }
    $linea('    lineas en las ultimas ' . HISTORICO_H . 'h: ' . count($recientes));
    $fatals = array_filter($recientes, fn($l) => stripos($l, 'fatal error') !== false
        || stripos($l, 'php:error') !== false || stripos($l, 'php warning') !== false
        || stripos($l, 'php parse error') !== false || stripos($l, 'php notice') !== false);
    $linea('    errores/warnings PHP: ' . count($fatals) . ($fatals ? '' : ' ✓'));
    foreach (array_slice(array_values($fatals), -5) as $l) $linea('      > ' . substr(trim($l), 0, 220));
} else {
    $linea('    (no encontrado)');
}

/* ---------------- 3. Access log: actividad y abusos ---------------- */
$access = APACHE_LOGS . '/access.log';
$linea('');
$linea('[3] ACCESS LOG (' . $access . ')');
if (is_file($access)) {
    $ips = []; $codes = []; $sospechosos = []; $ids = []; $total = 0;
    $corteTxt = date('d/M/Y:H:i:s');   // rápido: solo analizamos el trozo reciente por fecha en la propia línea
    $lineas = @file($access, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lineas as $l) {
        if (!preg_match('#^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) [^"]*" (\d{3})#', $l, $m)) continue;
        // formato Apache: dd/Mon/YYYY:HH:MM:SS +ZZZZ → dd Mon YYYY HH:MM:SS +ZZZZ
        $ts = strtotime(preg_replace('#^(\d{2}/\w{3}/\d{4}):(\d{2}:\d{2}:\d{2})$#', '$1 $2', $m[2]));
        if ($ts === false || $ts < time() - HISTORICO_H * 3600) continue;
        $total++;
        $ip = $m[1];
        $code = $m[5];
        $ips[$ip] = ($ips[$ip] ?? 0) + 1;
        $codes[$code] = ($codes[$code] ?? 0) + 1;
        // IDs de juego consultados (URL amigable o ?id=)
        if (preg_match('#(?:/juego/|juego/.php/?id=|detalle/.php/?id=)([A-Z]{4}[0-9]{5})#i', urldecode($m[4]), $im)) {
            $gid = strtoupper($im[1]);
            $ids[$gid] = ($ids[$gid] ?? 0) + 1;
        }
        if (in_array($code, ['403', '429'], true)) $sospechosos[$ip] = ($sospechosos[$ip] ?? 0) + 1;
        $bajo = strtolower($m[4]);
        foreach (PATRONES as $p) {
            if (stripos($l, $p) !== false) {
                $sospechosos[$ip] = ($sospechosos[$ip] ?? 0) + 1;
                $key = $ip . '|' . $p;
                if (!isset($sospechosos[$key])) $sospechosos[$key] = 0;
                $sospechosos[$key]++;
                break;
            }
        }
    }
    $linea('    peticiones en las ultimas ' . HISTORICO_H . 'h: ' . number_format($total));
    ksort($codes);
    foreach ($codes as $c => $n) $linea('      ' . $c . ': ' . number_format($n));
    arsort($ips);
    $linea('    IPs activas: ' . count($ips));
    foreach (array_slice($ips, 0, TOP_N, true) as $ip => $n) $linea('      ' . str_pad($ip, 40) . ' ' . number_format($n));

    arsort($ids);
    $topIds = array_slice($ids, 0, TOP_N, true);
    $linea('    IDs de juego consultados: ' . number_format(count($ids)) . ' distintos');
    foreach ($topIds as $gid => $n) $linea('      ' . str_pad($gid, 14) . ' ' . number_format($n));

    $ataques = array_filter($sospechosos, fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);
    $linea('    IPs con 403/429/patrones peligrosos: ' . count($ataques));
    arsort($ataques);
    foreach (array_slice($ataques, 0, TOP_N, true) as $ip => $n) {
        $nivel = $n >= 50 ? ' ← ALERTA' : ($n >= 10 ? ' ← vigilar' : '');
        $linea('      ' . str_pad($ip, 40) . ' ' . number_format($n) . $nivel);
    }
    foreach ($sospechosos as $k => $n) {
        if (is_string($k) && str_contains($k, '|') && $n >= 3) $linea('      patron "' . substr($k, strpos($k, '|') + 1, 40) . '" x' . $n . ' desde ' . strstr($k, '|', true));
    }
} else {
    $linea('    (no encontrado)');
}

/* ---------------- 4. Rate-limit y baneos activos ---------------- */
$linea('');
$linea('[4] RATE-LIMIT / BAN');
$rlFiles = glob(RL_DIR . '/*.json') ?: [];
$banFiles = glob(BAN_DIR . '/*.json') ?: [];
$linea('    archivos de limite: ' . count($rlFiles));
$activos = 0;
$baneosActivos = [];                       // para el export JSON
foreach ($banFiles as $b) {
    $j = json_decode((string)@file_get_contents($b), true);
    if (is_array($j) && (int)($j['until'] ?? 0) > time()) {
        $activos++;
        $restante = (int)$j['until'] - time();
        $baneosActivos[] = [
            'ip'       => (string)($j['ip'] ?? basename($b, '.json')),
            'hasta'    => date(DATE_ATOM, (int)$j['until']),
            'restante' => sprintf('%d min %02d s', intdiv($restante, 60), $restante % 60),
            'restante_s' => $restante,
        ];
    }
}
$linea('    baneos activos: ' . $activos . ' / registros: ' . count($banFiles));
foreach ($baneosActivos as $bn) {
    $linea('      ' . str_pad($bn['ip'], 40) . ' → hasta ' . substr($bn['hasta'], 11, 8) . ' · quedan ' . $bn['restante'] . ' ← ACTIVO');
}
foreach (array_slice($banFiles, 0, 5) as $b) {
    $j = json_decode((string)@file_get_contents($b), true);
    if (is_array($j) && (int)($j['until'] ?? 0) <= time()) {
        $linea('      ' . basename($b, '.json') . ' → caducado ' . date('H:i:s', (int)$j['until']));
    }
}

/* ---------------- 5. Archivos fuera de allowlist en la raíz ---------------- */
$linea('');
$linea('[5] ARCHIVOS EN RAIZ (allowlist)');
$allow = ['index.php', 'juego.php', 'asset.php', '.htaccess', 'api', 'inc', 'cache', 'assets', 'scripts'];
$inesperados = [];
foreach (scandir(WEB_ROOT) ?: [] as $f) {
    if ($f === '.' || $f === '..') continue;
    if (!in_array($f, $allow, true)) $inesperados[] = $f;
}
if ($inesperados) {
    $linea('    INESPERADOS: ' . implode(', ', $inesperados));
} else {
    $linea('    ninguno fuera de la allowlist ✓');
}
// .php sueltos en assets/
foreach (glob(WEB_ROOT . '/assets/*.php') ?: [] as $f) $linea('    PHP dentro de assets: ' . basename($f));

/* ---------------- 6. Integridad de la app PS5 (solo lectura) ---------------- */
$linea('');
$linea('[6] APP PS5 (padrino-store, debe estar intacta)');
$linea('    catalog.js: ' . (is_file(STORE_CATALOG) ? number_format(filesize(STORE_CATALOG)) . ' bytes' : 'FALTA'));
$linea('    covers: ' . count(glob(STORE_COVERS . '/*.jpg') ?: []) . ' · pages: ' . count(glob(STORE_PAGES . '/*.json') ?: []) . ' · releases: ' . count(glob(STORE_REL . '/*.json') ?: []));

/* ---------------- 6b. Priors de formato de descarga (inferencia) ---------------- */
$linea('');
$linea('[6b] PRIORS DE FORMATO (cache/fmtpriors.json)');
$t0 = microtime(true);
priors_fmt(true);
$dt = round((microtime(true) - $t0) * 1000);
$pf = CACHE_DIR . '/fmtpriors.json';
$pj = is_file($pf) ? json_decode((string)@file_get_contents($pf), true) : null;
$np = is_array($pj) ? (count($pj['psrc'] ?? []) + count($pj['prov'] ?? [])) : 0;
$linea('    regenerado en ' . $dt . ' ms · ' . $np . ' priors con confianza >= 90%'
    . (is_file($pf) ? ' · ' . number_format(filesize($pf)) . ' bytes' : ' · FALTA'));

/* ---------------- 7. Portadas del catálogo ---------------- */
$linea('');
$linea('[7] PORTADAS (covers, art y capturas)');
require __DIR__ . '/../inc/portadas.php';    // módulo compartido con check_portadas.php
$pd = portadas_diagnostico(true);
$linea('    juegos: ' . number_format($pd['total'])
     . ' · cover local: ' . number_format($pd['local'])
     . ' · imagen remota: ' . number_format($pd['remota'])
     . ' · SIN portada: ' . count($pd['sinPortada']));
$linea('    covers propias de la web (cache/covers): ' . count(glob(COVERS_WEB . '/*') ?: [])
     . '  · storemia covers: ' . count(glob(STORE_COVERS . '/*.jpg') ?: []));
$linea('    cover local ausente/rota ..... ' . count($pd['errorLocal']) . (count($pd['errorLocal']) ? ' ← ALERTA' : ' ✓'));
foreach ($pd['errorLocal'] as $id => $t) $linea('      ' . $id . '  ' . $t);
$linea('    art fuera de la CSP .......... ' . count($pd['errorCsp']) . (count($pd['errorCsp']) ? ' ← ALERTA' : ' ✓'));
foreach ($pd['errorCsp'] as $id => $t) $linea('      ' . $id . '  ' . $t);
$linea('    capturas fuera de la CSP ..... ' . count($pd['shotsMalos'])
     . (count($pd['shotsMalos']) ? '  (de ' . number_format($pd['nShots']) . ' URLs) ← ALERTA' : ' ✓ (' . number_format($pd['nShots']) . ' URLs)'));
foreach (array_slice($pd['shotsMalos'], 0, 10, true) as $id => $lista) $linea('      ' . $id . '  ' . $lista[0]);
$linea('    sin ninguna fuente ........... ' . count($pd['sinPortada'])
     . (count($pd['sinPortada']) ? '  (Opción A: quedan con el logo)' : ' ✓'));
if ($pd['informe']) $linea('    informe: ' . $pd['informe']);
$incidenciasPortadas = $pd['incidencias'];

/* ---------------- 8. Cobertura de enlaces de descarga ---------------- */
$linea('');
$linea('[8] ENLACES DE DESCARGA (cobertura)');
$extFile = CACHE_DIR . '/fuentes/externos.json';
$extRaw  = json_decode((string)@file_get_contents($extFile), true) ?: [];
$extGames = (array)($extRaw['games'] ?? []);
$extLinks = 0;
foreach ($extGames as $ents) $extLinks += count((array)$ents);
$tsSync = @filemtime($extFile) ?: 0;

$catAud = cargar_catalogo();
$conRel = 0; $conExt = 0; $conZstd = 0; $conAlgo = 0;
foreach ($catAud['games'] as $g) {
    $id   = (string)$g['id'];
    $hay  = false;

    $f = STORE_REL . '/' . $id . '.json';
    if (is_file($f)) {
        foreach ((array)json_decode((string)@file_get_contents($f), true) as $r) {
            if (!is_array($r) || !empty($r['unavailableReason'])) continue;
            if (host_permitido((string)($r['url'] ?? ''))) { $hay = true; $conRel++; break; }
        }
    }
    if (!$hay && isset($extGames[$id])) { $hay = true; $conExt++; }
    if (!$hay) {
        foreach ((array)($catAud['zstd'][$id]['links'] ?? []) as $u) {
            if (host_permitido((string)$u)) { $hay = true; $conZstd++; break; }
        }
    }
    if ($hay) $conAlgo++;
}

$totAud = count($catAud['games']);
$linea('    SKUs en catálogo ............. ' . number_format($totAud));
$linea('    con releases propios ........ ' . number_format($conRel));
$linea('    solo con enlaces externos ... ' . number_format($conExt)
     . ($conExt ? '  (sincronización CLI)' : ''));
$linea('    solo con fuentes ZSTD ....... ' . number_format($conZstd));
$linea('    SIN ninguna fuente ........... ' . number_format($totAud - $conAlgo)
     . ((($totAud - $conAlgo) / max(1, $totAud)) < 0.02 ? ' ✓' : '  ALERTA'));
$linea('    cobertura ................... ' . round(100 * $conAlgo / max(1, $totAud), 1) . '%');
$linea('    externos.json ............... ' . number_format(count($extGames)) . ' juegos · '
     . number_format($extLinks) . ' enlaces'
     . ($tsSync ? ' · sincronizado ' . date('Y-m-d H:i', $tsSync) : ' · SIN SINCRONIZAR'));

/* ---------------- 9. Resumen ---------------- */
$linea('');
$linea('============================================================');
$alertas = 0;
if (isset($fatals) && count($fatals) > 0) $alertas++;
if (!empty($ataques) && max($ataques) >= 50) $alertas++;
if ($activos > 0) $alertas++;
if ($inesperados) $alertas++;
if ($incidenciasPortadas > 0) $alertas++;
if ($tsSync === 0) $alertas++;   // enlaces externos nunca sincronizados
if ((($totAud - $conAlgo) / max(1, $totAud)) >= 0.02) $alertas++;  // cobertura < 98%
$linea($alertas ? " RESULTADO: $alertas ALERTA(S) — revisar arriba" : ' RESULTADO: sin incidencias ✓');
$linea('============================================================');

$texto = implode(PHP_EOL, $out) . PHP_EOL;
echo $texto;

@mkdir(CACHE_DIR . '/logs', 0775, true);
$Informe = CACHE_DIR . '/logs/auditoria-' . date('Y-m-d') . '.txt';
file_put_contents($Informe, $texto, FILE_APPEND | LOCK_EX);

/* ---------------- 9. Export JSON (además del TXT) ---------------- */
$informeJson = [
    'generado'      => date(DATE_ATOM),
    'php'           => PHP_VERSION,
    'alertas'       => $alertas,
    'baneos_activos'=> $baneosActivos,
    'top_ids'       => array_map(
        fn($gid, $n) => ['id' => $gid, 'visitas' => $n],
        array_keys($topIds ?? []), array_values($topIds ?? [])
    ),
    'rate_limit'    => ['archivos' => count($rlFiles), 'registros_ban' => count($banFiles)],
    'portadas'      => [
        'juegos'       => $pd['total'],
        'cover_local'  => $pd['local'],
        'sin_portada'  => count($pd['sinPortada']),
        'error_local'  => $pd['errorLocal'],
        'error_csp'    => $pd['errorCsp'],
    ],
    'inesperados'   => $inesperados,
];
$InformeJson = CACHE_DIR . '/logs/auditoria-' . date('Y-m-d') . '.json';
file_put_contents($InformeJson, json_encode($informeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
echo 'JSON: ' . $InformeJson . PHP_EOL;
