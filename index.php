<?php
declare(strict_types=1);

/* ============================================================
   Padrino Store Web — Catálogo (índice)
   ============================================================ */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/seguridad.php';
require_once __DIR__ . '/inc/catalogo.php';

seg_headers();

/* rate-limit global por IP (páginas HTML) */
$rlGlobal = rate_limit('global', RL_MAX_GLOBAL);
if (!$rlGlobal['ok']) rechazar_429();

$cat = cargar_catalogo();

/* ---- filtros desde la URL (gen acepta ?gen=Action o ?gen[]=Action&gen[]=RPG) ---- */
$genRaw = $_GET['gen'] ?? [];
if (!is_array($genRaw)) $genRaw = [$genRaw];
$genSel = array_values(array_unique(array_filter(array_map(
    fn($x) => trim((string)$x),
    $genRaw
), fn($x) => $x !== '')));

$f = [
    'q'     => mb_substr(trim((string)($_GET['q'] ?? '')), 0, Q_MAX),
    'plat'  => in_array($_GET['plat'] ?? '', ['ps5', 'ps4'], true) ? $_GET['plat'] : '',
    'gen'   => $genSel,                           // array de géneros (OR)
    'zstd'  => !empty($_GET['zstd']),
    'dir'   => !empty($_GET['dir']),
    'size'  => in_array($_GET['size'] ?? '', ['s', 'm', 'l'], true) ? $_GET['size'] : '',
    'mcmin' => isset($_GET['mcmin']) && $_GET['mcmin'] !== '' ? max(0, min(100, (int)$_GET['mcmin'])) : 0,
    'orden' => in_array($_GET['orden'] ?? '', ['added', 'rating', 'title', 'size', 'year'], true) ? $_GET['orden'] : 'added',
    'pag'   => max(1, (int)($_GET['pag'] ?? 1)),
];

$res = filtrar_catalogo($cat, $f);
$zset = $cat['zstd'];

/* nº de filtros activos (para el badge del botón ☰ Filtros en móvil) */
$nFiltros = ($f['q'] !== '' ? 1 : 0)
          + ($f['plat'] !== '' ? 1 : 0)
          + count($f['gen'])
          + ($f['zstd'] ? 1 : 0)
          + ($f['dir'] ? 1 : 0)
          + ($f['size'] !== '' ? 1 : 0)
          + ($f['mcmin'] > 0 ? 1 : 0);

/* ---- links de filtro (mantener el resto de parámetros) ---- */
function link_filtro(array $f, array $cambios): string
{
    $n = array_merge($f, $cambios);
    $q = [];
    foreach (['q', 'plat', 'size', 'orden'] as $k) {
        if (($n[$k] ?? '') !== '' && $n[$k] !== null) $q[$k] = $n[$k];
    }
    if (!empty($n['gen']) && is_array($n['gen'])) $q['gen'] = $n['gen'];   // gen[]=A&gen[]=B
    foreach (['zstd', 'dir'] as $k) { if (!empty($n[$k])) $q[$k] = '1'; }
    if (!empty($n['mcmin'])) $q['mcmin'] = (string)$n['mcmin'];
    if (!empty($n['pag']) && $n['pag'] > 1) $q['pag'] = (string)$n['pag'];
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
}

/* ---- página de resultados ---- */
$items = $res['items'];
$total = $res['total'];
$pags  = $res['pags'];
$pag   = $res['pag'];

/* ---- chips activos ---- */
$chips = [];
if ($f['q'] !== '')    $chips[] = ['t' => 'Búsqueda: ' . $f['q'], 'u' => link_filtro($f, ['q' => '', 'pag' => 1])];
if ($f['plat'] !== '') $chips[] = ['t' => 'Plataforma: ' . strtoupper($f['plat']), 'u' => link_filtro($f, ['plat' => '', 'pag' => 1])];
foreach ($f['gen'] as $gn) {
    $resto = array_values(array_filter($f['gen'], fn($x) => $x !== $gn));
    $chips[] = ['t' => 'Género: ' . $gn, 'u' => link_filtro($f, ['gen' => $resto, 'pag' => 1])];
}
if ($f['zstd'])        $chips[] = ['t' => 'Solo ZSTD', 'u' => link_filtro($f, ['zstd' => null, 'pag' => 1])];
if ($f['dir'])         $chips[] = ['t' => 'Descarga directa', 'u' => link_filtro($f, ['dir' => null, 'pag' => 1])];
if ($f['size'] !== '') $chips[] = ['t' => 'Tamaño: ' . ['s' => '<10 GB', 'm' => '10–50 GB', 'l' => '>50 GB'][$f['size']], 'u' => link_filtro($f, ['size' => '', 'pag' => 1])];
if ($f['mcmin'])       $chips[] = ['t' => 'Metacritic ' . $f['mcmin'] . '+', 'u' => link_filtro($f, ['mcmin' => 0, 'pag' => 1])];

/* ---- números de página ---- */
function paginas(int $pag, int $pags, int $max): array
{
    if ($pags <= $max) return range(1, $pags);
    $half = intdiv($max, 2);
    $start = max(1, $pag - $half);
    $end = min($pags, $start + $max - 1);
    $start = max(1, $end - $max + 1);
    $out = [];
    if ($start > 1) { $out[] = 1; if ($start > 2) $out[] = '…'; }
    for ($i = $start; $i <= $end; $i++) $out[] = $i;
    if ($end < $pags) { if ($end < $pags - 1) $out[] = '…'; $out[] = $pags; }
    return $out;
}
$paginas = paginas($pag, $pags, MAX_PAG_LINKS);

/* ---- primera visita (cookie) ---- */
$primera = !isset($_COOKIE['pw_seen']);
if ($primera && !defined('ESTATICO')) {   // el build estático no emite cookies
    setcookie('pw_seen', '1', [
        'expires'  => time() + 86400 * 365,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$orden_label = ['added' => 'Recientes', 'rating' => 'Mejor valorados', 'title' => 'Título A-Z', 'size' => 'Mayor tamaño', 'year' => 'Año'][$f['orden']];
$generos_top = array_slice($cat['genres'], 0, 10, true);
$mc_total = count(array_filter($cat['games'], fn($g) => $g['mc'] !== null));

/* ---- juegos "NUEVO" (añadidos en los últimos 7 días) ---- */
$ahora = time();
$nuevos_ids = [];
foreach ($cat['games'] as $g) {
    if (!empty($g['added'])) {
        $ts = strtotime($g['added']) ?: 0;
        if ($ts > 0 && ($ahora - $ts) < 7 * 86400) {
            $nuevos_ids[$g['id']] = true;
        }
    }
}

/* ---- últimos 10 juegos para el carrusel de novedades ---- */
$recientes_top = array_slice(
    array_filter($cat['games'], fn($g) => !empty($g['cov']) || !empty($g['art'])),
    0, 10
);
// Reordenar por added desc para coger los 10 más recientes
usort($cat['games'], fn($a, $b) => strcmp($b['added'], $a['added']));
$recientes_top = array_slice(
    array_filter($cat['games'], fn($g) => !empty($g['cov']) || !empty($g['art'])),
    0, 10
);
// Restaurar el orden del catálogo para el filtrado posterior
// (cargar_catalogo ya devuelve el slice en added desc por defecto)

$titulo_pagina = 'Padrino Store — Catálogo PS5 y PS4';
$urlBase  = url_base();
$urlSelf = $urlBase . (defined('ESTATICO') ? '/' : '/index.php') . ($f['q'] !== '' || $f['plat'] !== '' || $f['gen']
    ? '?' . http_build_query(array_filter(['q' => $f['q'], 'plat' => $f['plat'], 'gen' => $f['gen']]))
    : '');
$ogImg = $urlBase . '/asset.php?f=logo';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Descarga juegos PS5 y PS4 sin necesidad de la tienda. Catálogo Padrino Store.">
<link rel="canonical" href="<?= e($urlSelf) ?>">
<!-- Open Graph / Telegram preview -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($titulo_pagina) ?>">
<meta property="og:description" content="Descarga juegos PS5 y PS4 sin necesidad de la tienda. Catálogo Padrino Store.">
<meta property="og:image" content="<?= e($ogImg) ?>">
<meta property="og:url" content="<?= e($urlSelf) ?>">
<meta property="og:site_name" content="Padrino Store">
<title><?= e($titulo_pagina) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 0 ?>">
<link rel="icon" href="asset.php?f=logo">
</head>
<body>

<!-- ================= NAVBAR ================= -->
<header class="top">
  <a class="brand" href="index.php">
    <img src="asset.php?f=logo" alt="Padrino Store" width="30" height="30">
    <span class="brand-t">Padrino<i>&nbsp;Store</i></span>
  </a>
  <nav class="nav-links">
    <a class="lnk on" href="index.php">Catálogo</a>
    <a class="lnk" href="index.php?orden=rating">Top</a>
    <a class="lnk" href="index.php?zstd=1">ZSTD</a>
    <a class="lnk" href="index.php?orden=added">Novedades</a>
    <a class="lnk" href="#redes">Redes</a>
  </nav>
  <button class="filtros-btn" id="filtrosBtn" aria-label="Filtros" aria-controls="side" aria-expanded="false">
    ☰ <span class="flabel">Filtros</span> <span class="fbadge" id="filtrosBadge" <?= $nFiltros > 0 ? '' : 'hidden' ?>><?= $nFiltros ?></span>
  </button>
  <form class="search" role="search" action="index.php" method="get" id="searchForm">
    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input type="search" name="q" id="q" placeholder="Buscar en <?= number_format(count($cat['games']), 0, ',', '.') ?> juegos…" value="<?= e($f['q']) ?>" autocomplete="off"
           role="combobox" aria-expanded="false" aria-controls="ac" aria-autocomplete="list" aria-label="Buscar juegos">
    <div class="ac" id="ac" role="listbox" aria-label="Sugerencias de búsqueda" hidden></div>
  </form>
  <a class="tg-btn" href="<?= e(TELEGRAM_URL) ?>" target="_blank" rel="noopener noreferrer" aria-label="Únete al Telegram">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.479.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
    <span><?= e(TELEGRAM_HANDLE) ?></span>
  </a>
  <a class="fav-nav" href="#redes" id="favNav" aria-label="Mis favoritos" title="Mis favoritos">
    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21.2l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
    <span class="fav-badge" id="favBadge" hidden>0</span>
  </a>
</header>

<div class="wrap">
  <!-- ================= FILTROS ================= -->
  <div class="side-overlay" id="sideOverlay"></div>
  <aside class="side" id="side" role="group" aria-label="Filtros del catálogo">
    <div class="side-head">
      <h4>Filtros</h4>
      <button class="side-close" id="sideClose" aria-label="Cerrar">✕</button>
    </div>

    <a class="clear-all" id="clearAll" href="index.php">✕ Limpiar todos los filtros</a>

    <h4>Favoritos</h4>
    <a class="opt" id="filtroFavs" href="#">
      <span class="box"></span>Mis favoritos <span class="cnt" id="favCnt">0</span>
    </a>

    <h4>Historial</h4>
    <div class="hist-list" id="histList">
      <div class="hist-empty">Aún no has visitado ningún juego.</div>
    </div>

    <h4>Plataforma</h4>
    <a class="opt <?= $f['plat'] === '' ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['plat' => '', 'pag' => 1])) ?>" data-fk="plat" data-fv="">
      <span class="box"></span>Todos <span class="cnt"><?= number_format(count($cat['games']), 0, ',', '.') ?></span>
    </a>
    <a class="opt <?= $f['plat'] === 'ps5' ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['plat' => $f['plat'] === 'ps5' ? '' : 'ps5', 'pag' => 1])) ?>" data-fk="plat" data-fv="ps5">
      <span class="box"></span>PS5 <span class="cnt"><?= number_format($cat['plat']['ps5'] ?? 0, 0, ',', '.') ?></span>
    </a>
    <a class="opt <?= $f['plat'] === 'ps4' ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['plat' => $f['plat'] === 'ps4' ? '' : 'ps4', 'pag' => 1])) ?>" data-fk="plat" data-fv="ps4">
      <span class="box"></span>PS4 <span class="cnt"><?= number_format($cat['plat']['ps4'] ?? 0, 0, ',', '.') ?></span>
    </a>

    <h4>Catálogo</h4>
    <div class="zstd-note">Los juegos <b>ZSTD</b> son de la comunidad: comprimidos, más livianos y con descarga directa. Se marcan con la etiqueta dorada.</div>
    <a class="opt <?= $f['zstd'] ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['zstd' => $f['zstd'] ? null : '1', 'pag' => 1])) ?>" style="margin-top:8px" data-fk="zstd" data-fv="1">
      <span class="box"></span>Solo ZSTD <span class="cnt"><?= number_format(count($zset), 0, ',', '.') ?></span>
    </a>
    <a class="opt <?= $f['dir'] ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['dir' => $f['dir'] ? null : '1', 'pag' => 1])) ?>" data-fk="dir" data-fv="1">
      <span class="box"></span>Descarga directa <span class="cnt"><?= number_format($cat['direct'] ?? 0, 0, ',', '.') ?></span>
    </a>

    <h4>Tamaño</h4>
    <a class="opt <?= $f['size'] === 's' ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['size' => $f['size'] === 's' ? '' : 's', 'pag' => 1])) ?>" data-fk="size" data-fv="s">
      <span class="box"></span>&lt; 10 GB
    </a>
    <a class="opt <?= $f['size'] === 'm' ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['size' => $f['size'] === 'm' ? '' : 'm', 'pag' => 1])) ?>" data-fk="size" data-fv="m">
      <span class="box"></span>10 – 50 GB
    </a>
    <a class="opt <?= $f['size'] === 'l' ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['size' => $f['size'] === 'l' ? '' : 'l', 'pag' => 1])) ?>" data-fk="size" data-fv="l">
      <span class="box"></span>&gt; 50 GB
    </a>

    <h4>Metacritic <span class="cnt-inline"><?= number_format($mc_total, 0, ',', '.') ?> con nota</span></h4>
    <a class="opt <?= $f['mcmin'] === 70 ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['mcmin' => $f['mcmin'] === 70 ? 0 : 70, 'pag' => 1])) ?>" data-fk="mcmin" data-fv="70">
      <span class="box"></span>70+
    </a>
    <a class="opt <?= $f['mcmin'] === 80 ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['mcmin' => $f['mcmin'] === 80 ? 0 : 80, 'pag' => 1])) ?>" data-fk="mcmin" data-fv="80">
      <span class="box"></span>80+
    </a>
    <a class="opt <?= $f['mcmin'] === 90 ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['mcmin' => $f['mcmin'] === 90 ? 0 : 90, 'pag' => 1])) ?>" data-fk="mcmin" data-fv="90">
      <span class="box"></span>90+
    </a>

    <h4>Género</h4>
    <?php foreach ($generos_top as $gn => $gc): $activo = in_array($gn, $f['gen'], true); ?>
    <a class="opt <?= $activo ? 'on' : '' ?>" href="<?= e(link_filtro($f, ['gen' => $activo
            ? array_values(array_filter($f['gen'], fn($x) => $x !== $gn))
            : array_merge($f['gen'], [$gn]), 'pag' => 1])) ?>" data-fk="gen" data-fv="<?= e($gn) ?>">
      <span class="box"></span><?= e($gn) ?> <span class="cnt"><?= number_format($gc, 0, ',', '.') ?></span>
    </a>
    <?php endforeach; ?>
  </aside>

  <!-- ================= MAIN ================= -->
  <main class="main">
    <div class="bar">
      <span class="res"><b><?= number_format($total, 0, ',', '.') ?></b> resultados</span>
      <?php foreach ($chips as $c): ?>
        <span class="fchip"><?= e($c['t']) ?> <a href="<?= e($c['u']) ?>">✕</a></span>
      <?php endforeach; ?>
      <div class="sort-wrap">
        <select class="sort" id="orden" aria-label="Ordenar">
          <?php foreach (['added' => 'Recientes', 'rating' => 'Mejor valorados', 'title' => 'Título A-Z', 'size' => 'Mayor tamaño', 'year' => 'Año'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $f['orden'] === $k ? 'selected' : '' ?>>Ordenar: <?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if ($total === 0): ?>
      <div class="nores">
        <img src="asset.php?f=logo" alt="" width="64" height="64">
        <h2>Sin resultados</h2>
        <p>No encontramos juegos con esos filtros. Prueba a quitar alguno.</p>
        <a class="gbtn p" href="index.php">Ver todo el catálogo</a>
      </div>
    <?php else: ?>

      <?php if (!$f['q'] && !$f['plat'] && !$f['gen'] && !$f['zstd'] && !$f['dir'] && !$f['size'] && !$f['mcmin'] && $f['pag'] === 1): ?>
      <!-- ================= BANNER TELEGRAM ================= -->
      <div class="tg-banner">
        <div class="tg-banner-inner">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.479.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
          <div class="tg-banner-txt">
            <span>Únete al canal de Telegram</span>
            <small>Novedades, juegos nuevos y más en <?= e(TELEGRAM_HANDLE) ?></small>
          </div>
          <a class="tg-banner-btn" href="<?= e(TELEGRAM_URL) ?>" target="_blank" rel="noopener noreferrer">Unirse →</a>
        </div>
      </div>

      <!-- ================= NOVEDADES ================= -->
      <?php if ($recientes_top): ?>
      <div class="nov-wrap">
        <div class="nov-head">
          <span class="nov-label">✦ Novedades</span>
          <a href="index.php?orden=added" class="nov-ver">Ver todas →</a>
        </div>
        <div class="nov-scroll">
          <?php foreach ($recientes_top as $rg): $rz = isset($cat['zstd'][$rg['id']]); ?>
          <a class="nov-card" href="<?= e(url_ficha($rg['id'])) ?>">
            <div class="nov-tile" style="background:<?= e($rg['tint'] ?: '#1e1e1e') ?>">
              <?php if (!empty($rg['cov'])): ?>
              <img src="<?= e(url_cover($rg['id'])) ?>" data-art="<?= e((string)$rg['art']) ?>" alt="<?= e($rg['title']) ?>" loading="lazy" width="192" height="256">
              <?php elseif (!empty($rg['art'])): ?>
              <img src="<?= e($rg['art']) ?>" alt="<?= e($rg['title']) ?>" loading="lazy" width="192" height="256">
              <?php else: ?>
              <img class="ph" src="asset.php?f=logo" alt="" loading="lazy" width="192" height="256" data-nofb="1">
              <?php endif; ?>
              <?php if ($rz): ?><span class="badge">ZSTD</span><?php endif; ?>
              <span class="badge-new">NUEVO</span>
              <span class="plat"><?= strtoupper(e($rg['plat'])) ?></span>
            </div>
            <div class="nov-title"><?= e($rg['title']) ?></div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <div class="grid" id="grid">
        <?php foreach ($items as $g): $z = isset($zset[$g['id']]); $esNuevo = isset($nuevos_ids[$g['id']]); ?>
        <a class="card" href="<?= e(url_ficha($g['id'])) ?>">
          <div class="tile" style="background:<?= e($g['tint'] ?: '#1e1e1e') ?>">
            <?php if (!empty($g['cov'])): ?>
            <img src="<?= e(url_cover($g['id'])) ?>" data-art="<?= e((string)$g['art']) ?>" alt="<?= e($g['title']) ?>" loading="lazy" width="384" height="512">
            <?php elseif (!empty($g['art'])): ?>
            <img src="<?= e($g['art']) ?>" alt="<?= e($g['title']) ?>" loading="lazy" width="384" height="512">
            <?php else: ?>
            <img class="ph" src="asset.php?f=logo" alt="" loading="lazy" width="384" height="512" data-nofb="1">
            <?php endif; ?>
            <?php if ($z): ?><span class="badge">ZSTD</span><?php endif; ?>
            <?php if ($esNuevo): ?><span class="badge-new">NUEVO</span><?php endif; ?>
            <span class="plat"><?= strtoupper(e($g['plat'])) ?></span>
            <span class="fav-dot" data-fav-id="<?= e($g['id']) ?>" title="Favorito" aria-label="Favorito">♥</span>
            <div class="dl"><span data-id="<?= e($g['id']) ?>">⬇ Descargar</span></div>
          </div>
          <div class="ct"><?= e($g['title']) ?></div>
          <div class="cm">
            <?php if ($g['mc'] !== null): ?><b><?= (int)$g['mc'] ?></b><?php endif; ?>
            <?php if ($g['size'] !== null): ?><span><?= e(rtrim(rtrim(number_format($g['size'], 3, '.', ''), '0'), '.')) ?> GB</span><?php endif; ?>
            <?php if ($g['year'] !== null): ?><span><?= (int)$g['year'] ?></span><?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if ($pags > 1): ?>
      <!-- "Cargar más" (JS) — la paginación clásica de abajo convive con ella
           (en estático, app.js repinta sus enlaces con los filtros activos) -->
      <div class="more-wrap">
        <button type="button" class="more-btn" id="moreBtn"
                data-pag="<?= $pag ?>" data-pags="<?= $pags ?>"
                data-qs="<?= e(http_build_query(array_filter([
                    'q' => $f['q'], 'plat' => $f['plat'], 'gen' => $f['gen'],
                    'size' => $f['size'], 'orden' => $f['orden'],
                    'zstd' => $f['zstd'] ? '1' : '', 'dir' => $f['dir'] ? '1' : '',
                    'mcmin' => $f['mcmin'] ?: '',
                ], fn($v) => $v !== '' && $v !== null))) ?>">
          Cargar más <span id="moreCount"></span>
        </button>
      </div>

      <nav class="pages" id="pagesNav" aria-label="Paginación">
        <?php if ($pag > 1): ?><a class="pg" href="<?= e(link_filtro($f, ['pag' => $pag - 1])) ?>">‹</a><?php endif; ?>
        <?php foreach ($paginas as $p): ?>
          <?php if ($p === '…'): ?><span class="pg gap">…</span>
          <?php elseif ($p === $pag): ?><span class="pg on" aria-current="page"><?= $p ?></span>
          <?php else: ?><a class="pg" href="<?= e(link_filtro($f, ['pag' => (int)$p])) ?>"><?= $p ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($pag < $pags): ?><a class="pg" href="<?= e(link_filtro($f, ['pag' => $pag + 1])) ?>">›</a><?php endif; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>

    <!-- ================= REDES ================= -->
    <section class="social" id="redes">
      <h4>Síguenos</h4>
      <div class="row">
        <?php foreach (SOCIAL as $s): ?>
        <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer">
          <?= icono_red($s['k']) ?>
          <span class="h"><?= e($s['label']) ?><small><?= e($s['handle']) ?></small></span>
        </a>
        <?php endforeach; ?>
      </div>
      <div class="cr">Padrino Store Web — descarga juegos PS5 y PS4 sin necesidad de la tienda · tema Durazno</div>
    </section>
  </main>
</div>

<!-- ================= BIENVENIDA ================= -->
<?php if ($primera): ?>
<div class="welcome show" id="welcome">
  <div class="wcard">
    <img class="wlogo" src="asset.php?f=logo" alt="Padrino Store" width="76" height="76">
    <h2>Bienvenido a <i>Padrino Store</i></h2>
    <p>De aquí puedes <b>descargar los juegos sin necesidad de la tienda</b>.<br>
       Explora el catálogo PS5 y PS4, elige tu juego y descarga directo.</p>
    <div class="wsoc">
      <?php foreach (SOCIAL as $s): ?>
        <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($s['label']) ?> <?= e($s['handle']) ?>">
          <?= icono_red($s['k']) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <button class="wbtn" data-close-welcome>Explorar el catálogo →</button>
    <button class="wskip" data-close-welcome>ya los conozco, cerrar</button>
  </div>
</div>
<?php endif; ?>

<!-- ================= MODAL DESCARGA ================= -->
<div class="ovl" id="ovl" data-close-ovl>
  <div class="dlbox" role="dialog" aria-modal="true" aria-labelledby="dlt">
    <div class="dlhd">
      <img id="dlcov" src="<?= e(logo_src()) ?>" alt="" width="64" height="85">
      <div>
        <div class="t" id="dlt"></div>
        <div class="m" id="dlm"></div>
      </div>
      <button type="button" class="x" data-close-ovl aria-label="Cerrar">✕</button>
    </div>
    <div class="dlsec"><span>Fuentes de descarga</span><span id="dln"></span></div>
    <div id="dllist"></div>
    <div class="dlft" id="dlf"></div>
  </div>
</div>

<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: 0 ?>"></script>
<script src="assets/guard.js?v=<?= @filemtime(__DIR__ . '/assets/guard.js') ?: 0 ?>"></script>
</body>
</html>
<?php

/* ---- Los iconos SVG de redes viven en inc/helpers.php (compartido) ---- */

