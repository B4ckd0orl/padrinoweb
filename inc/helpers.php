<?php
declare(strict_types=1);

/* ============================================================
   Helpers compartidos por todas las páginas
   (iconos SVG de redes · normalización de texto para búsqueda)
   ============================================================ */

/** Normaliza una cadena: minúsculas + elimina acentos/diacríticos (ö→o, é→e, ñ→n…) */
function norm_txt(string $s): string
{
    static $memo = [];                       // memoización: el build estático la llama ~17M veces
    if (isset($memo[$s])) return $memo[$s];

    // transliterar (intl o iconv; fallback manual para los más comunes)
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if ($t !== false) return $memo[$s] = $t;
    }
    // fallback: tabla de los diacríticos más habituales en títulos de PS4/PS5
    static $map = [
        'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ý'=>'y','ÿ'=>'y','ñ'=>'n','ç'=>'c','ß'=>'ss',
        'Á'=>'a','À'=>'a','Â'=>'a','Ä'=>'a','Ã'=>'a','Å'=>'a',
        'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'Ó'=>'o','Ò'=>'o','Ô'=>'o','Ö'=>'o','Õ'=>'o','Ø'=>'o',
        'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'Ý'=>'y','Ñ'=>'n','Ç'=>'c',
    ];
    return $memo[$s] = mb_strtolower(strtr($s, $map));
}

/* ---- Iconos SVG de redes (inline, sin dependencias) ---- */
function icono_red(string $k): string
{
    $p = [
        'telegram' => 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.479.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z',
        'canal'    => 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.479.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z',
        'github'   => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12',
        'x'        => 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z',
    ];
    $d = $p[$k] ?? $p['github'];
    return '<svg viewBox="0 0 24 24" width="19" height="19" fill="currentColor" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

/** Ruta base de la app ('' en raíz, '/padrinoweb' en subdirectorio) */
function base_path(): string
{
    if (defined('ESTATICO')) return BASE_PATH_ESTATICO;   // build: '' o '/padrinoweb'
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($script));
    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

/** URL absoluta de la web (para OG/canonical) */
function url_base(): string
{
    if (defined('ESTATICO')) return URL_BASE_ESTATICO;    // build: URL pública del sitio
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $fwd  = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $fwd === 'https'
          || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    return ($https ? 'https://' : 'http://') . $host . base_path();
}

/** URL del logo (asset.php en modo web; archivo suelto en el build estático) */
function logo_src(): string
{
    return defined('ESTATICO') ? 'assets/logo.png' : 'asset.php?f=logo';
}

/** URL relativa de la ficha de un juego: 'juego/CUSA12345' (amigable) */
function url_ficha(string $id): string
{
    return base_path() . '/juego/' . rawurlencode($id);
}
