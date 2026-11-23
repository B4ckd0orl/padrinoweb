<?php
declare(strict_types=1);

/* ============================================================
   inc/portadas.php — diagnóstico de portadas (solo CLI)
   Compartido por scripts/check_portadas.php y la sección [7]
   de scripts/auditoria.php.

   SOLO LECTURA: no escribe en padrino-store ni toca catalog.js.
   Única escritura permitida: el informe cache/logs/portadas-sin-fuente-*.txt.
   ============================================================ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por consola\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/catalogo.php';

/** Recolecta todas las URLs http(s) de un valor (recursivo). */
function hosts_imagen(mixed $v, array &$out, int $prof = 0): void
{
    if ($prof > 5) return;
    if (is_string($v)) {
        if (preg_match('#^https?://#', $v)) $out[$v] = 1;
        return;
    }
    if (is_array($v)) foreach ($v as $x) hosts_imagen($x, $out, $prof + 1);
}

/**
 * Recorre el catálogo y las fichas y devuelve el diagnóstico.
 *
 * @return array{
 *   total:int, local:int, remota:int,
 *   sinPortada:array<string,string>, errorLocal:array<string,string>,
 *   errorCsp:array<string,string>, shotsMalos:array<string,array>,
 *   nShots:int, incidencias:int, informe:string
 * }
 */
function portadas_diagnostico(bool $escribirInforme = true): array
{
    $cat   = cargar_catalogo();          // regenera cache/catalog.json si está obsoleta
    $games = $cat['games'] ?? [];

    $local = 0; $remota = 0;
    $sinPortada = [];   // ni cover local ni imagen remota  → muestran el logo
    $errorLocal  = [];  // dice tener cover local pero el archivo no sirve
    $errorCsp    = [];  // imagen remota que el navegador bloquearía (host fuera de la CSP)

    foreach ($games as $g) {
        $id     = (string)$g['id'];
        $tieneC = !empty($g['cov']);
        $art    = (string)($g['art'] ?? '');

        if ($tieneC) {
            $local++;
            $ruta = cover_local_path($id);
            if (!imagen_valida($ruta)) $errorLocal[$id] = (string)$g['title'];
        } elseif ($art !== '') {
            $remota++;
            if (!imagen_permitida($art)) $errorCsp[$id] = $g['title'] . '  →  ' . $art;
        } else {
            $sinPortada[$id] = (string)$g['title'];
        }
    }

    /* capturas/banner de las fichas (fondo de ficha y lightbox) */
    $shotsMalos = [];   // id => urls que la CSP bloquearía
    $nShots = 0;
    foreach (glob(STORE_PAGES . '/*.json') ?: [] as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;
        $urls = [];
        hosts_imagen($j['shots'] ?? [], $urls);
        hosts_imagen($j['hero'] ?? null, $urls);
        hosts_imagen($j['cover'] ?? null, $urls);
        $nShots += count($urls);
        foreach ($urls as $u => $_) {
            if (!imagen_permitida($u)) $shotsMalos[basename($f, '.json')][] = $u;
        }
    }

    /* informe de los que no tienen ninguna fuente (Opción A) */
    $informe = '';
    if ($sinPortada && $escribirInforme) {
        $archivo = CACHE_DIR . '/logs/portadas-sin-fuente-' . date('Y-m-d') . '.txt';
        $l = [];
        $l[] = 'PORTADAS SIN FUENTE — ' . date('Y-m-d H:i:s');
        $l[] = 'juegos: ' . count($sinPortada) . ' de ' . count($games) . ' (muestran el logo como placeholder)';
        $l[] = '';
        foreach ($sinPortada as $id => $t) {
            $l[] = str_pad($id, 12) . $t . '  [sin imagen en catalog.js, pages ni shots]';
        }
        $l[] = '';
        $l[] = 'Para asignarles portada: añadir art.cover en catalog.js (app PS5),';
        $l[] = 'o dejarlos con el logo (decisión Opción A).';
        @mkdir(dirname($archivo), 0775, true);
        file_put_contents($archivo, implode("\n", $l) . "\n", LOCK_EX);
        $informe = $archivo;
    }

    return [
        'total'       => count($games),
        'local'       => $local,
        'remota'      => $remota,
        'sinPortada'  => $sinPortada,
        'errorLocal'  => $errorLocal,
        'errorCsp'    => $errorCsp,
        'shotsMalos'  => $shotsMalos,
        'nShots'      => $nShots,
        'incidencias' => count($errorLocal) + count($errorCsp) + count($shotsMalos),
        'informe'     => $informe,
    ];
}
