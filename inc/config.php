<?php
declare(strict_types=1);

/* ============================================================
   Padrino Store Web — configuración
   Rutas a los datos de la app PS5 (SOLO LECTURA, nunca se tocan)
   ============================================================ */

/* Rutas a los datos de la app PS5 (SOLO LECTURA, nunca se tocan).
   Portabilidad: se puede sobreescribir con la variable de entorno
   PADRINO_STORE apuntando a la raíz de padrino-store; se aceptan
   separadores / y \ en cualquier SO. */
define('STORE_ROOT', rtrim((string)(getenv('PADRINO_STORE') ?: 'C:/Users/Padrino/Desktop/PS5/storemia/padrino-store'), '/\\'));
define('STORE_DATA',   STORE_ROOT . '/app/data');
define('STORE_PAGES',  STORE_DATA . '/pages');
define('STORE_REL',    STORE_DATA . '/releases');
define('STORE_COVERS', STORE_ROOT . '/covers');
define('STORE_ASSETS', STORE_ROOT . '/app/assets');
define('STORE_SCE',    STORE_ROOT . '/app/sce_sys');
define('STORE_CATALOG',STORE_DATA . '/catalog.js');
define('STORE_ZSTD',   STORE_ROOT . '/app/state/zstd-snapshot.js');
define('STORE_FORMATS',STORE_DATA . '/formats.js');

define('WEB_ROOT',  dirname(__DIR__));
define('CACHE_DIR', WEB_ROOT . '/cache');

/* ---- Build estático (GitHub Pages no ejecuta PHP) ---------------
   scripts/estatico.php define ESTATICO antes de incluir nada; estos
   valores apuntan al sitio publicado. En el modo web no se usan. */
define('BASE_PATH_ESTATICO', rtrim((string)(getenv('PADRINO_BASE_PATH') ?: ''), '/'));
define('URL_BASE_ESTATICO',  rtrim((string)(getenv('PADRINO_BASE_URL') ?: 'https://b4ckd0orl.github.io/padrinoweb'), '/'));
define('COVERS_CDN',         rtrim((string)(getenv('PADRINO_COVERS_CDN') ?: 'https://cdn.jsdelivr.net/gh/B4ckd0orl/Padrino-Store@main/covers'), '/'));

/* Ajustes */
define('POR_PAGINA', 48);            // juegos por página del catálogo
define('MAX_PAG_LINKS', 7);          // números de página visibles
define('Q_MAX', 64);                 // longitud máxima del texto de búsqueda

/* Rate-limit / anti-abuso (por IP y ventana) */
define('RL_VENTANA', 60);            // ventana en segundos
define('RL_MAX_DETALLE', 30);        // detalle/descarga
define('RL_MAX_API', 120);           // APIs de catálogo/búsqueda
define('RL_MAX_GLOBAL', 300);        // páginas HTML
define('RL_MAX_ASSET', 1200);        // covers y assets
define('RL_BAN_MAX', 5);             // incumplimientos (por bucket) antes del baneo
define('RL_BAN_TTL', 900);           // duración del baneo (15 min)
define('RL_STRIKE_TTL', 3600);       // ventana de los strikes (1 h)
define('BAN_DIR', CACHE_DIR . '/ban');
define('RL_DIR', CACHE_DIR . '/rl');

/* Firma HMAC de las URLs de contenido (covers).
   El secreto NO vive en el código: se toma de la variable de entorno
   PW_SECRET o de cache/secret.key (no servible por web). Si no existe,
   se genera aleatoriamente la primera vez. */
function cargar_secreto_asset(): string
{
    $env = getenv('PW_SECRET');
    if (is_string($env) && strlen($env) >= 16) return $env;

    $file = CACHE_DIR . '/secret.key';
    $k = @file_get_contents($file);
    if (is_string($k) && strlen(trim($k)) >= 16) return trim($k);

    $k = bin2hex(random_bytes(32));
    @file_put_contents($file, $k, LOCK_EX);
    return $k;
}
define('SECRETO_ASSET', cargar_secreto_asset());

/* Redes sociales */
define('SOCIAL', [
    ['k' => 'telegram', 'label' => 'Telegram', 'handle' => '@padrinostores', 'url' => 'https://t.me/padrinostores'],
    ['k' => 'github',   'label' => 'GitHub',   'handle' => 'B4ckd0orl',      'url' => 'https://github.com/B4ckd0orl'],
]);

/* URL del canal Telegram principal (para el botón de la navbar y el banner) */
define('TELEGRAM_URL',    'https://t.me/padrinostores');
define('TELEGRAM_HANDLE', '@padrinostores');

/* Portadas propias de la web: covers descargadas para juegos que la app PS5
   no tiene en covers/. Vive DENTRO de padrinoweb (storemia sigue en solo lectura). */
define('COVERS_WEB', CACHE_DIR . '/covers');

if (!is_dir(CACHE_DIR)) { @mkdir(CACHE_DIR, 0775, true); }

/**
 * Ruta del archivo de portada local de un id.
 * Primero covers/ de la app PS5; si no está, covers/ propios de la web.
 * Devuelve '' cuando no hay ningún archivo.
 */
function cover_local_path(string $id): string
{
    /* Modo estático: no se descargan los 476 MB de covers del repo de datos.
       Se lista el índice (git ls-tree, solo nombres) y se devuelve la ruta
       que tocaba; cargar_catalogo() solo comprueba que no sea ''. */
    if (defined('ESTATICO')) {
        $idx = covers_index();
        $id  = strtoupper($id);
        if (!isset($idx[$id])) return '';
        return $idx[$id]['tipo'] === 'datos'
            ? STORE_COVERS . '/' . $idx[$id]['archivo']
            : COVERS_WEB   . '/' . $idx[$id]['archivo'];
    }

    $p = STORE_COVERS . '/' . $id . '.jpg';
    if (is_file($p)) return $p;
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $p = COVERS_WEB . '/' . $id . '.' . $ext;
        if (is_file($p)) return $p;
    }
    return '';
}

/**
 * Índice de portadas disponibles: ID → ['tipo' => 'datos'|'web', 'archivo' => nombre].
 *   - 'datos': covers/ del repo de datos (listados con `git ls-tree`, sin
 *     descargar blobs → sirve de CDN en el build de GitHub Actions).
 *   - 'web':   covers propios de la web (cache/covers, se copian al build).
 */
function covers_index(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];

    $nombres = [];
    if (is_dir(STORE_ROOT . '/.git')) {
        $out = @shell_exec('git -C "' . str_replace('"', '', STORE_ROOT) . '" ls-tree -r --name-only HEAD covers');
        if (is_string($out) && trim($out) !== '') {
            foreach (preg_split('/\r?\n/', $out) as $linea) {
                if (preg_match('#^covers/([^/]+\.(?:jpg|jpeg|png|webp))$#i', trim($linea), $m)) {
                    $nombres[strtoupper(pathinfo($m[1], PATHINFO_FILENAME))] = $m[1];
                }
            }
        }
    }
    if (!$nombres) {                       // sin git: barrido directo (solo en local)
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            foreach (glob(STORE_COVERS . '/*.' . $ext) ?: [] as $f) {
                $nombres[strtoupper(pathinfo($f, PATHINFO_FILENAME))] = basename($f);
            }
        }
    }
    foreach ($nombres as $id => $archivo) $memo[$id] = ['tipo' => 'datos', 'archivo' => $archivo];

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {     // covers propios de la web
        foreach (glob(COVERS_WEB . '/*.' . $ext) ?: [] as $f) {
            $id = strtoupper(pathinfo($f, PATHINFO_FILENAME));
            if (!isset($memo[$id])) $memo[$id] = ['tipo' => 'web', 'archivo' => basename($f)];
        }
    }
    return $memo;
}

/** ¿La portada local es servible? (≥1 KB y cabecera de imagen reconocida) */
function imagen_valida(string $ruta): bool
{
    if ($ruta === '' || !is_file($ruta) || filesize($ruta) < 1024) return false;
    $cab = (string)@file_get_contents($ruta, false, null, 0, 12);
    return strncmp($cab, "\xFF\xD8", 2) === 0                                   // JPEG
        || strncmp($cab, "\x89PNG", 4) === 0                                    // PNG
        || (strncmp($cab, 'RIFF', 4) === 0 && substr($cab, 8, 4) === 'WEBP')    // WebP
        || strncmp($cab, 'GIF8', 4) === 0;                                      // GIF
}
