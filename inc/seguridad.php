<?php
declare(strict_types=1);

/* ============================================================
   Seguridad: headers, validación, rate-limit/ban, firma, salida
   ============================================================ */

/* Orígenes de imagen permitidos por la CSP img-src (única fuente de verdad:
   la usa seg_headers() para la cabecera y scripts/check_portadas.php para
   auditar que ninguna portada apunte a un host que el navegador bloqueará).
   Son CDNs de imagen: la web solo exige que NINGUNO de estos nombres
   aparezca como texto visible para el usuario (plan §0). */
const CSP_IMG_ORIGENES = [
    'https://*.playstation.com', 'https://*.playstation.net',
    'https://*.githubusercontent.com', 'https://*.github.io', 'https://raw.githubusercontent.com',
    'https://*.bp.blogspot.com', 'https://*.blogspot.com',
    'https://archive.org', 'https://*.archive.org',
    'https://*.dlpsgame.com', 'https://dlpsgame.com',            // el * NO cubre el host exacto
    'https://*.pegasus-dl.dev', 'https://pegasus-dl.dev',        // (verificado en navegador 2026-11-17)
    'https://*.steamstatic.com', 'https://*.igdb.com',
    'https://cdn.prosperopatches.com', 'https://cover.spectrumlibrary.online',
    'https://cdn.jsdelivr.net',                                        // covers en el build estático (GitHub Pages)
];

/** ¿Esta URL remota la dejaría cargar el navegador (https + host en la CSP)? */
function imagen_permitida(?string $url): bool
{
    if (!is_string($url) || $url === '') return false;
    $p = parse_url($url);
    if ($p === false || empty($p['scheme']) || empty($p['host'])) return false;
    if (strtolower((string)$p['scheme']) !== 'https') return false;
    $host = strtolower((string)$p['host']);
    foreach (CSP_IMG_ORIGENES as $origen) {
        $patron = strtolower(substr($origen, 8));            // sin 'https://'
        if ($patron !== '' && $patron[0] === '*') {
            $sufijo = substr($patron, 1);                    // *.dominio.com
            if (str_ends_with($host, $sufijo)) return true;
        } elseif ($host === $patron) {
            return true;
        }
    }
    return false;
}

/** Headers de seguridad en TODA respuesta */
function seg_headers(): void
{
    if (defined('ESTATICO')) return;          // el build estático no emite headers: va en <meta>
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Security-Policy: ' . csp_string());
}

/** CSP estricta: sin 'unsafe-inline' en scripts (no hay scripts inline ni
    handlers inline). img-src: lista explícita de los CDNs de portadas
    (art.cover) y capturas (shots) — son URLs de imagen, no texto visible.
    La usan seg_headers() (cabecera) y el build estático (<meta>). */
function csp_string(): string
{
    return "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:"
        . ' ' . implode(' ', CSP_IMG_ORIGENES)
        . "; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
}

/** Etiqueta <meta> con la CSP (solo build estático; en modo web ''). */
function csp_meta(): string
{
    if (!defined('ESTATICO')) return '';
    /* frame-ancestors solo se aplica en cabeceras: el navegador lo
       descarta (y avisa en consola) si va en un <meta>. */
    $csp = str_replace("; frame-ancestors 'self'", '', csp_string());
    return '<meta http-equiv="Content-Security-Policy" content="' . e($csp) . '">';
}

/** Escapado de salida HTML (acepta string|int|float|null) */
function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** ID de juego válido: PPSA12345 / CUSA12345 (whitelist estricta) */
function id_valido(?string $id): bool
{
    return is_string($id) && preg_match('/^[A-Z]{4}\d{5}$/', $id) === 1;
}

/** IP del cliente (respetando proxy local solo si viene de 127.0.0.1) */
function cliente_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ($ip === '127.0.0.1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $cand = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($cand, FILTER_VALIDATE_IP)) $ip = $cand;
    }
    return $ip;
}

/** Hash estable (nombre de archivo) para una IP */
function ip_hash(string $ip): string
{
    return substr(hash('sha256', 'ip|' . $ip), 0, 32);
}

/* ------------------------- BANEO ------------------------- */

function aplicar_ban(string $ip): void
{
    if (!is_dir(BAN_DIR)) @mkdir(BAN_DIR, 0775, true);
    @file_put_contents(
        BAN_DIR . '/' . ip_hash($ip) . '.json',
        json_encode(['until' => time() + RL_BAN_TTL, 'ip' => $ip]),
        LOCK_EX
    );
}

/** Sale con 403 si la IP está baneada. */
function verificar_ban(): void
{
    $f = BAN_DIR . '/' . ip_hash(cliente_ip()) . '.json';
    if (!is_file($f)) return;
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || !isset($j['until'])) { @unlink($f); return; }
    if ((int)$j['until'] > time()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: ' . ((int)$j['until'] - time()));
        seg_headers();
        echo 'Demasiadas peticiones. Vuelve a intentarlo en unos minutos.';
        exit;
    }
    @unlink($f); // caducado
}

/** Limpieza de strikes/limite caducados (probabilística, sin coste notable) */
function gc_rate_limit(): void
{
    if (random_int(1, 25) !== 1) return;
    foreach ([RL_DIR, BAN_DIR] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            $mt = @filemtime($f) ?: 0;
            if (time() - $mt > 7200) @unlink($f);
        }
    }
}

/**
 * Rate-limit por IP y bucket, con `flock` (sin carreras), strikes y baneo.
 * @return array{ok:bool, restantes:int}
 */
function rate_limit(string $bucket, int $max): array
{
    if (defined('ESTATICO')) return ['ok' => true, 'restantes' => $max];   // build CLI: sin límite
    verificar_ban();

    if (!is_dir(RL_DIR)) @mkdir(RL_DIR, 0775, true);
    $file = RL_DIR . '/' . substr(hash('sha256', $bucket . '|' . cliente_ip()), 0, 24) . '.json';

    $fp = @fopen($file, 'c+');
    if ($fp === false) return ['ok' => true, 'restantes' => $max]; // falla abierta: no romper la web local

    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($data)) $data = [];

    $now = time();
    $hits = array_values(array_filter((array)($data['h'] ?? []), fn($t) => is_int($t) && ($now - $t) < RL_VENTANA));
    $strikes = array_values(array_filter((array)($data['s'] ?? []), fn($t) => is_int($t) && ($now - $t) < RL_STRIKE_TTL));

    $ok = true;
    if (count($hits) >= $max) {
        $ok = false;
        $strikes[] = $now;
        if (count($strikes) >= RL_BAN_MAX) aplicar_ban(cliente_ip());
    } else {
        $hits[] = $now;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['h' => $hits, 's' => $strikes]));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    gc_rate_limit();

    return ['ok' => $ok, 'restantes' => $ok ? max(0, $max - count($hits)) : 0];
}

/** Responde JSON y termina (sin caché intermedia) */
function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    seg_headers();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Error 429 amigable y termina */
function rechazar_429(): never
{
    http_response_code(429);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: ' . RL_VENTANA);
    header('Cache-Control: no-store');
    seg_headers();
    echo '<!DOCTYPE html><html lang="es"><meta charset="utf-8"><title>Demasiadas peticiones</title>'
       . '<body style="background:#0a0a0a;color:#eee;font-family:system-ui;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center">'
       . '<div><h1 style="color:#fab283">Demasiadas peticiones</h1>'
       . '<p>Has hecho muchas peticiones seguidas. Espera un minuto y vuelve a intentarlo.</p>'
       . '<p><a style="color:#fab283" href="index.php">← Volver al catálogo</a></p></div></body></html>';
    exit;
}

/** Error 404 amigable */
function abortar_404(string $msg = 'No encontrado'): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    seg_headers();
    echo $msg;
    exit;
}

/* ------------------------- FIRMA DE URL ------------------------- */

/** Token HMAC corto para un asset y sus parámetros */
function token_firma(string $f, array $p): string
{
    ksort($p);
    $msg = $f;
    foreach ($p as $k => $v) $msg .= '|' . $k . '=' . $v;
    return substr(hash_hmac('sha256', $msg, SECRETO_ASSET), 0, 32);
}

/** URL firmada de la cover de un juego (firma obligatoria en asset.php) */
function url_cover(string $id): string
{
    $id = strtoupper($id);

    /* Build estático: no hay asset.php. Los covers del repo de datos se
       sirven desde el CDN (jsDelivr) y los propios, de assets/covers/. */
    if (defined('ESTATICO')) {
        $idx = covers_index();
        if (!isset($idx[$id])) return 'assets/logo.png';
        return $idx[$id]['tipo'] === 'datos'
            ? COVERS_CDN . '/' . rawurlencode($idx[$id]['archivo'])
            : 'assets/covers/' . rawurlencode($idx[$id]['archivo']);
    }

    return 'asset.php?f=cover&id=' . rawurlencode($id) . '&h=' . token_firma('cover', ['id' => $id]);
}

/** Verifica la firma de una cover. */
function firma_cover_ok(string $id, ?string $h): bool
{
    if (!is_string($h) || strlen($h) !== 32) return false;
    return hash_equals(token_firma('cover', ['id' => $id]), $h);
}

/**
 * Nombres de webs/tiendas/comunidades ajenas. SOLO se usan para DETECTAR
 * texto visible: los datos originales se conservan tal cual (los nombres de
 * las otras stores siguen donde estaban), lo único que se exige es que
 * ninguno de estos nombres llegue como texto en pantalla (plan §0).
 */
const MENCIONES_AJENAS = [
    '/\b[a-z0-9.-]*(?:dlpsgame|downloadgameps3|prosperopatches|pegasus-dl|spectrumlibrary|superpsx|mediafire)[a-z0-9.-]*\.[a-z]{2,}\b/i',
    '/\bdlps\b/i',
    '/\bpippo\b/i',
    '/\bpegasus\b/i',
    '/\bspectrum\b/i',
    '/\bsuperpsx\b/i',
    '/\bpsx[\s._-]?place\b/i',
    '/\bdownloadgameps3\b/i',
    '/\bprosperopatches\b/i',
    '/\bmediafire\b/i',
    '/\bzer0[\s._-]?dayz?\b/i',
];

/**
 * Nombres propios legítimos (títulos y estudios del catálogo) que contienen
 * una palabra de MENCIONES_AJENAS. NO son menciones a las comunidades ajenas:
 * se enmascaran antes de escanear para no dar falsos positivos. Si aparece
 * un juego nuevo con una de estas palabras, el test lo señalará y se añade
 * aquí tras revisarlo.
 */
const NOMBRES_PROPIOS_OK = [
    'heavy spectrum entertainment labs', // estudio de Shadow of the Beast (CUSA03762)
    'the spectrum retreat',               // juego de Dan Smith Studios (CUSA12242)
];

/** Enmascara los nombres propios legítimos (conserva la longitud: los offsets
 *  del escáner siguen valiendo para mostrar el contexto). */
function sin_nombres_propios(string $t): string
{
    foreach (NOMBRES_PROPIOS_OK as $n) {
        $t = str_ireplace($n, str_repeat(' ', strlen($n)), $t);
    }
    return $t;
}

/** ¿Este texto contiene el nombre de una web ajena? */
function hay_mencion_ajena(string $t): bool
{
    if ($t === '') return false;
    $t = sin_nombres_propios($t);
    foreach (MENCIONES_AJENAS as $p) {
        if (preg_match($p, $t)) return true;
    }
    return false;
}

/**
 * Texto SEGURO para pantalla. No reescribe ni renombra nada: si el texto
 * está limpio se devuelve idéntico; si lleva el nombre de una web ajena se
 * omite el campo corto o se descartan solo las frases contaminadas.
 */
function texto_visible(string $t): string
{
    $t = trim($t);
    if ($t === '' || !hay_mencion_ajena($t)) return $t;
    if (mb_strlen($t) <= 140) return '';                 // campo corto: se omite
    $partes = preg_split('/(?<=[.!?…])\s+|\R+/', $t) ?: [];
    $limpio = [];
    foreach ($partes as $p) {
        $p = trim($p);
        if ($p !== '' && !hay_mencion_ajena($p)) $limpio[] = $p;
    }
    return trim(implode(' ', $limpio));
}

/** Anti-hotlink suave para assets servidos por PHP: solo nuestro origen o vacío */
function permitir_referer(): bool
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref === '') return true; // apertura directa / local
    $host = parse_url($ref, PHP_URL_HOST) ?? '';
    $selfHost = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    return $host === '' || $host === $selfHost || $host === 'localhost' || $host === '127.0.0.1';
}
