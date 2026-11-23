<?php
declare(strict_types=1);

/* ============================================================
   scripts/test_discrecion.php — test de discreción (solo CLI)
   Regla vigente: NINGÚN nombre de web/tienda/comunidad ajena puede
   aparecer como TEXTO VISIBLE en pantalla. Las URLs de imagen, la CSP
   y los campos técnicos sí pueden nombrar hosts (son datos, no texto).

   Comprueba:
     - el texto de las páginas HTML (sin atributos)
     - los campos de texto de las APIs (notes, label, about, summary,
       title, dev, pub…) excluyendo url/art/cover/shots/links
     - los estáticos que producen texto (app.js, style.css)
     - las notas ZSTD y las descripciones en caché
     - que api/detalle.php NO sirve el campo `pass` (contraseña de RAR)

   Uso:  php scripts/test_discrecion.php
   Sale con código 1 si hay fallas (sirve de check automatizado).
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

const BASE = 'http://localhost/padrinoweb';

$fallas = [];

/** Quita de un JSON todo lo que NO es texto visible (URLs, listas de imagen). */
function sin_urls(string $txt): string
{
    $patron = '/"(url|art|cover|coverUrl|thumb|shots|links|background|src|href|poster|icon)"\s*:\s*(?:"(?:[^"\\\\]|\\\\.)*"|\[[^\]]*\])/i';
    return (string)preg_replace($patron, '', $txt);
}

/** Quita etiquetas y atributos: queda SOLO el texto que se lee en pantalla. */
function solo_texto(string $html): string
{
    $t = (string)preg_replace('/<[^>]*>/', ' ', $html);
    return html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function escanear_visible(string $origen, string $texto): void
{
    global $fallas;
    if (trim($texto) === '') return;
    foreach (MENCIONES_AJENAS as $p) {
        if (preg_match($p, $texto, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int)$m[0][1];
            $ctx = trim(preg_replace('/\s+/', ' ', substr($texto, max(0, $pos - 70), 170)));
            $fallas[] = sprintf('%s :: texto visible «%s» :: …%s…', $origen, $m[0][0], $ctx);
        }
    }
}

function http_txt(string $url, ?string &$headers = null): ?string
{
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: DiscretionTest/1.0\r\n", 'timeout' => 30]]);
    $txt = @file_get_contents($url, false, $ctx);
    if ($txt === false) return null;
    $headers = implode("\n", $http_response_header ?? []);
    return $txt;
}

$cat = cargar_catalogo();

/* ids de prueba: uno por tipo de fuente + el del plan */
$extId = null;
$ext = json_decode((string)@file_get_contents(CACHE_DIR . '/fuentes/externos.json'), true);
foreach (array_keys((array)($ext['games'] ?? [])) as $k) { $extId = (string)$k; break; }
$zstdId = null;
foreach ($cat['games'] as $g) {
    $z = $cat['zstd'][$g['id']] ?? null;
    if ($z && !empty($z['links'])) { $zstdId = $g['id']; break; }
}
$ids = array_values(array_filter(['CUSA30277', $extId, $zstdId, 'PPSA03753', 'CUSA01519']));

$lineas = [];
$lineas[] = '============================================================';
$lineas[] = ' TEST DE DISCRECION (texto visible) - Padrino Store Web';
$lineas[] = ' ' . date('Y-m-d H:i:s') . '  ·  PHP ' . PHP_VERSION;
$lineas[] = '============================================================';

/* [1] HTML servido: solo el texto, sin atributos */
$lineas[] = '';
$lineas[] = '[1] TEXTO DE LAS PAGINAS';
$rutas = ['/' => 'index', '/juego.php?id=CUSA30277' => 'ficha CUSA30277'];
if ($extId) $rutas['/juego.php?id=' . $extId] = "ficha externa $extId";
foreach ($rutas as $ruta => $nombre) {
    $txt = http_txt(BASE . $ruta);
    if ($txt === null) { $lineas[] = "    ! $nombre: sin respuesta"; $fallas[] = "$nombre :: HTTP sin respuesta"; continue; }
    $nAntes = count($fallas);
    escanear_visible($nombre, solo_texto($txt));
    $lineas[] = '    ' . str_pad($nombre, 26) . ' ' . number_format(strlen($txt)) . ' bytes · fallas: ' . (count($fallas) - $nAntes);
}

/* [2] JSON APIs: todo el texto salvo las URLs */
$lineas[] = '';
$lineas[] = '[2] CAMPOS DE TEXTO DE LAS APIs';
$apis = ['/api/juegos.php' => 'juegos (página 1)'];
foreach ($ids as $id) $apis['/api/detalle.php?id=' . $id] = "detalle $id";
foreach ($apis as $ruta => $nombre) {
    $txt = http_txt(BASE . $ruta);
    if ($txt === null) { $lineas[] = "    ! $nombre: sin respuesta"; $fallas[] = "$nombre :: HTTP sin respuesta"; continue; }
    $nAntes = count($fallas);
    escanear_visible($nombre, sin_urls($txt));
    // el campo pass (contraseña de los RAR) no se sirve jamás
    if (str_contains($ruta, 'detalle') && preg_match('/"pass"\s*:/', $txt)) {
        $fallas[] = "$nombre :: sirve el campo \"pass\" (contraseña de RAR)";
    }
    $lineas[] = '    ' . str_pad($nombre, 26) . ' ' . number_format(strlen($txt)) . ' bytes · fallas: ' . (count($fallas) - $nAntes);
}

/* [3] estáticos que producen texto en pantalla */
$lineas[] = '';
$lineas[] = '[3] ESTATICOS';
foreach (['assets/app.js', 'assets/style.css', 'index.php', 'juego.php'] as $f) {
    $ruta = __DIR__ . '/../' . $f;
    $txt = is_file($ruta) ? (string)file_get_contents($ruta) : null;
    if ($txt === null) { $fallas[] = "$f :: no encontrado"; continue; }
    $nAntes = count($fallas);
    // los .php: solo texto (sin atributos); los .js/.css: tal cual
    escanear_visible($f, str_ends_with($f, '.php') ? solo_texto($txt) : $txt);
    $lineas[] = '    ' . str_pad($f, 26) . ' ' . number_format(strlen($txt)) . ' bytes · fallas: ' . (count($fallas) - $nAntes);
}

/* [4] descripciones en caché (about/summary se sirven tal cual) */
$lineas[] = '';
$lineas[] = '[4] DESCRIPCIONES EN CACHED (about/summary)';
$nFich = 0;
foreach (glob(CACHE_DIR . '/fichas/*.json') ?: [] as $f) {
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) continue;
    $nFich++;
    escanear_visible('fichas/' . basename($f), implode("\n", array_merge(
        (array)($j['about'] ?? []),
        [(string)($j['summary'] ?? '')]
    )));
}
$lineas[] = '    fichas escaneadas: ' . $nFich;

/* [5] notas ZSTD (se muestran en el modal) */
$lineas[] = '';
$lineas[] = '[5] NOTAS ZSTD';
$nZ = 0;
foreach ($cat['zstd'] as $id => $z) {
    if (!is_array($z)) continue;
    $nZ++;
    escanear_visible("zstd/$id", (string)($z['notes'] ?? ''));
}
$lineas[] = '    snapshots escaneados: ' . $nZ;

/* informe */
$lineas[] = '';
$lineas[] = '============================================================';
if ($fallas) {
    $lineas[] = ' FALLAS: ' . count($fallas);
    foreach ($fallas as $f) $lineas[] = '  - ' . $f;
} else {
    $lineas[] = ' OK: ningun nombre ajeno aparece como texto visible';
}
$lineas[] = '============================================================';

$texto = implode("\n", $lineas) . "\n";
@mkdir(CACHE_DIR . '/logs', 0775, true);
file_put_contents(CACHE_DIR . '/logs/test-discrecion-' . date('Y-m-d') . '.txt', $texto, FILE_APPEND | LOCK_EX);
echo $texto;
exit($fallas ? 1 : 0);
