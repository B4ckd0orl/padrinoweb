<?php
declare(strict_types=1);

/* ============================================================
   scripts/check_portadas.php — auditoría de portadas (solo CLI)
   Uso:  C:\xampp\php\php.exe C:\xampp\htdocs\padrinoweb\scripts\check_portadas.php
   Salida: consola + informe cache/logs/portadas-sin-fuente-YYYY-MM-DD.txt
   SOLO LECTURA: no escribe en padrino-store ni toca catalog.js.
   ============================================================ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por consola\n");
}

require __DIR__ . '/../inc/portadas.php';   // carga config + seguridad + catálogo

$d = portadas_diagnostico(true);

echo "============================================================\n";
echo " AUDITORIA DE PORTADAS — Padrino Store Web\n";
echo ' ' . date('Y-m-d H:i:s') . '  ·  PHP ' . PHP_VERSION . "\n";
echo "============================================================\n\n";
echo "juegos: {$d['total']}\n";
echo "  con cover local ............ {$d['local']}\n";
echo "  con imagen remota (art) .... {$d['remota']}\n";
echo '  SIN portada ................ ' . count($d['sinPortada']) . "\n\n";

echo '[1] COVER LOCAL AUSENTE/ROTA ............ ' . count($d['errorLocal'])
   . ($d['errorLocal'] ? "\n" : " ✓\n");
foreach ($d['errorLocal'] as $id => $t) echo "      $id  $t\n";

echo '[2] ART FUERA DE LA CSP (imagen bloqueada) ' . count($d['errorCsp'])
   . ($d['errorCsp'] ? "\n" : " ✓\n");
foreach ($d['errorCsp'] as $id => $t) echo "      $id  $t\n";

echo '[3] SIN PORTADA ......................... ' . count($d['sinPortada'])
   . ($d['sinPortada'] ? "\n" : " ✓\n");

echo '[4] CAPTURAS FUERA DE LA CSP ............. ' . count($d['shotsMalos'])
   . ($d['shotsMalos'] ? '  (de ' . number_format($d['nShots']) . " URLs revisadas)\n"
                       : ' ✓ (' . number_format($d['nShots']) . " URLs)\n");
$hostMalo = [];
foreach ($d['shotsMalos'] as $id => $lista) foreach ($lista as $u) $hostMalo[(string)parse_url($u, PHP_URL_HOST)] = true;
foreach (array_keys($hostMalo) as $h) echo "      host bloqueado: $h\n";
foreach (array_slice($d['shotsMalos'], 0, 10, true) as $id => $lista) echo "      $id  " . $lista[0] . "\n";

if ($d['informe']) echo '      informe: ' . $d['informe'] . "\n";

echo "\n";
if ($d['incidencias']) {
    echo "\nRESULTADO: {$d['incidencias']} incidencia(s) — revisar arriba\n";
    exit(1);
}
echo "\nRESULTADO: sin incidencias"
   . ($d['sinPortada'] ? ' (' . count($d['sinPortada']) . ' sin portada por diseño)' : '')
   . " ✓\n";
exit(0);
