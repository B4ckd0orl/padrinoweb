<?php
declare(strict_types=1);

/* ============================================================
   Padrino Store Web — Ficha del juego (estilo PS5)
   ============================================================ */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/seguridad.php';
require_once __DIR__ . '/inc/catalogo.php';
require_once __DIR__ . '/inc/fichas.php';

seg_headers();

/* rate-limit global por IP (páginas HTML) */
$rlGlobal = rate_limit('global', RL_MAX_GLOBAL);
if (!$rlGlobal['ok']) rechazar_429();

$id = strtoupper((string)($_GET['id'] ?? ''));
if (!id_valido($id)) { http_response_code(400); exit('ID no válido'); }

/* ---- 301 a la URL amigable /juego/CUSA12345 (enlaces antiguos en Telegram) ----
   Solo si la vino como juego.php?id=…; tras la rewrites interna de mod_rewrite
   REQUEST_URI sigue siendo /juego/ID, así que no hay bucle. */
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (preg_match('#juego\.php\?id=[A-Z]{4}[0-9]{5}#i', $uri)) {
    header('Location: ' . url_ficha($id), true, 301);
    exit;
}

$cat = cargar_catalogo();
$g = juego_por_id($cat, $id);
if ($g === null) { http_response_code(404); exit('Juego no encontrado'); }

$ficha = cargar_ficha($id);
$zstd  = $cat['zstd'][$id] ?? null;
$sizeTxt = $g['size'] !== null ? rtrim(rtrim(number_format($g['size'], 3, '.', ''), '0'), '.') . ' GB' : null;

$titulo = $g['title'] . ' — Padrino Store';
$desc = $ficha['summary'] !== '' ? $ficha['summary']
      : ($g['title'] . ' para ' . strtoupper($g['plat']) . '. Descarga directa en Padrino Store.');

/* ---- Open Graph: portada para preview en Telegram/WhatsApp ---- */
$tieneCov = !empty($g['cov']);
$artCover  = !empty($g['art']) ? (string)$g['art'] : '';
$covSrc = $tieneCov ? url_cover($id) : ($artCover !== '' ? $artCover : logo_src());
$ogImage = $tieneCov
    ? (str_starts_with($covSrc, 'http') ? $covSrc : url_base() . '/' . $covSrc)   // build estático: URL absoluta ya
    : ($artCover !== '' ? $artCover : '');
$ogUrl   = url_base() . '/juego/' . rawurlencode($id);
$canonico = url_base() . '/juego/' . rawurlencode($id);
$covCls  = ($tieneCov || $artCover !== '') ? '' : ' nocov';
$bg = $ficha['shots'][0]['url'] ?? null;

/* ---- Otras versiones del mismo juego (SKUs PS4/PS5 repetidos) ----
   La búsqueda deduplica por título; aquí se recuperan los demás SKUs. */
$otras = [];
if (norm_txt($g['title']) !== '') {
    foreach ($cat['games'] as $o) {
        if ($o['id'] !== $id && norm_txt($o['title']) === norm_txt($g['title'])) $otras[] = $o;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<!-- base para que las rutas relativas (assets/) funcionen
     también bajo la URL amigable /juego/CUSA12345 -->
<base href="<?= e(base_path() === '' ? '/' : base_path() . '/') ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($canonico) ?>">
<!-- Open Graph / Telegram preview -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($titulo) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta property="og:url" content="<?= e($ogUrl) ?>">
<meta property="og:site_name" content="Padrino Store">
<title><?= e($titulo) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 0 ?>">
<link rel="icon" href="asset.php?f=logo">
</head>
<body class="pagina-juego" data-juego-id="<?= e($id) ?>">

<a class="gback" href="index.php">← Volver al catálogo</a>

<article class="game show" id="game">
  <div class="hero">
    <div class="bg" <?= $bg ? 'style="background-image:url(\'' . e($bg) . '\')"' : ($covSrc ? 'style="background-image:url(\'' . e($covSrc) . '\')"' : '') ?>></div>
    <div class="veil"></div>
    <div class="inner">
      <div class="cover<?= $covCls ?>" style="background:<?= e($g['tint'] ?: '#1e1e1e') ?>">
        <?php if ($tieneCov && $artCover !== ''): ?>
        <img src="<?= e($covSrc) ?>" data-art="<?= e($artCover) ?>" alt="<?= e($g['title']) ?>" width="384" height="512">
        <?php else: ?>
        <img src="<?= e($covSrc) ?>" alt="<?= e($g['title']) ?>" width="384" height="512" data-nofb="<?= $covSrc === logo_src() ? '1' : '0' ?>">
        <?php endif; ?>
      </div>
      <div class="htxt">
        <div class="kick">
          <?php if ($zstd): ?><span class="z">ZSTD</span><?php endif; ?>
          <span><?= strtoupper(e($g['plat'])) ?></span>
          <?php if ($g['mc'] !== null): ?><span class="mc">MC <?= (int)$g['mc'] ?></span><?php endif; ?>
          <?php if ($g['year'] !== null): ?><span><?= (int)$g['year'] ?></span><?php endif; ?>
        </div>
        <h1><?= e($g['title']) ?></h1>
        <div class="gmeta">
          <?php if ($sizeTxt): ?><b><?= e($sizeTxt) ?></b><?php endif; ?>
          <?php if ($g['gen']): ?><span><?= e(implode(' · ', $g['gen'])) ?></span><?php endif; ?>
          <?php if ($g['dev']): ?><span><?= e($g['dev']) ?></span><?php endif; ?>
        </div>
        <div class="gbtns">
          <button type="button" class="gbtn p" data-dl="<?= e($id) ?>">⬇ Descargar</button>
          <button type="button" class="gbtn g" data-fav data-fav-id="<?= e($id) ?>">♡ Favorito</button>
          <button type="button" class="gbtn g" data-cmp
                  data-cmp-id="<?= e($id) ?>"
                  data-title="<?= e($g['title']) ?>"
                  data-plat="<?= e(strtoupper($g['plat'])) ?>"
                  data-size="<?= $g['size'] !== null ? (string)$g['size'] : '' ?>"
                  data-mc="<?= $g['mc'] !== null ? (string)$g['mc'] : '' ?>"
                  data-year="<?= $g['year'] !== null ? (string)$g['year'] : '' ?>"
                  data-gen="<?= e(implode(' · ', $g['gen'])) ?>"
                  data-cover="<?= e($covSrc) ?>">⇄ Comparar</button>
          <button type="button" class="gbtn g" data-copy="juego/<?= e($id) ?>">🔗 Copiar enlace</button>
        </div>
      </div>
    </div>
  </div>

  <div class="gbody">
    <section class="gsec">
      <h3>Información</h3>
      <div class="gtags">
        <span>ID <b><?= e($g['id']) ?></b></span>
        <span>Plataforma <b><?= strtoupper(e($g['plat'])) ?></b></span>
        <?php if ($sizeTxt): ?><span>Tamaño <b><?= e($sizeTxt) ?></b></span><?php endif; ?>
        <?php if ($g['mc'] !== null): ?><span>Metacritic <b><?= (int)$g['mc'] ?></b></span><?php endif; ?>
        <?php if ($g['year'] !== null): ?><span>Año <b><?= (int)$g['year'] ?></b></span><?php endif; ?>
        <?php if ($g['dev']): ?><span>Desarrollador <b><?= e($g['dev']) ?></b></span><?php endif; ?>
        <?php if ($g['pub'] && $g['pub'] !== $g['dev']): ?><span>Editorial <b><?= e($g['pub']) ?></b></span><?php endif; ?>
        <?php if ($g['dir']): ?><span><b>Descarga directa</b></span><?php endif; ?>
        <?php foreach ($g['gen'] as $gn): ?><span>Género <b><?= e($gn) ?></b></span><?php endforeach; ?>
        <?php if ($zstd): ?><span>ZSTD <b><?= e($zstd['version'] ?: 'comunidad') ?></b></span><?php endif; ?>
      </div>
    </section>

    <?php if ($otras): ?>
    <section class="gsec">
      <h3>Otras versiones</h3>
      <div class="gtags">
        <?php foreach ($otras as $o): ?>
        <a class="ver" href="<?= e(url_ficha($o['id'])) ?>">
          <?= strtoupper(e($o['plat'])) ?> <b><?= e($o['id']) ?></b>
          <?php if ($o['size'] !== null): ?>· <?= e(number_format((float)$o['size'], 1, ',', '.')) ?> GB<?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($ficha['about']): ?>
    <section class="gsec">
      <h3>Descripción</h3>
      <div class="about">
        <?php foreach ($ficha['about'] as $p): ?><p><?= e($p) ?></p><?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($ficha['shots']): ?>
    <section class="gsec">
      <h3>Capturas</h3>
      <div class="shots">
        <?php foreach ($ficha['shots'] as $s): ?>
          <img src="<?= e($s['url']) ?>" alt="Captura de <?= e($g['title']) ?>" loading="lazy" data-full>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="gdl">
      <div>
        <div class="t">Descargar este juego</div>
        <div class="s" id="dlnote">Cargando fuentes…</div>
      </div>
      <button type="button" class="go" data-dl="<?= e($id) ?>">Ver fuentes de descarga →</button>
    </section>

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
  </div>
</article>

<!-- ================= MODAL DESCARGA ================= -->
<div class="ovl" id="ovl" data-close-ovl>
  <div class="dlbox" role="dialog" aria-modal="true" aria-labelledby="dlt">
    <div class="dlhd">
      <img id="dlcov" src="<?= e($covSrc) ?>" alt="" width="64" height="85">
      <div>
        <div class="t" id="dlt"><?= e($g['title']) ?></div>
        <div class="m" id="dlm"><b><?= e($g['id']) ?></b><span><?= strtoupper(e($g['plat'])) ?></span><?php if ($sizeTxt): ?><span><?= e($sizeTxt) ?></span><?php endif; ?></div>
      </div>
      <button type="button" class="x" data-close-ovl aria-label="Cerrar">✕</button>
    </div>
    <div class="dlsec"><span>Fuentes de descarga</span><span id="dln"></span></div>
    <div id="dllist"><div class="empty">Cargando…</div></div>
    <div class="dlft" id="dlf"></div>
  </div>
</div>

<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: 0 ?>"></script>
<script src="assets/guard.js?v=<?= @filemtime(__DIR__ . '/assets/guard.js') ?: 0 ?>"></script>
</body>
</html>
