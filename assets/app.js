/* ============================================================
   Padrino Store Web — app.js
   Bienvenida · drawer filtros · autocompletado · orden ·
   modal de descargas (api/detalle.php) · copiar · lightbox ·
   favoritos (localStorage) · toast · historial · badge NUEVO
   ============================================================ */
(function () {
  'use strict';

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  /* ============================================================
     UTILIDADES
     ============================================================ */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmt(n) {
    var x = Number(n);
    if (!isFinite(x)) return '?';
    return x.toFixed(x >= 100 ? 0 : x >= 10 ? 1 : 2).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
  }
  function dom(u) { try { return new URL(u, location.href).host; } catch (e) { return u; } }
  /* Ruta base de la app ('' en raíz, '/padrinoweb' en subdirectorio) */
  var BASE = (function () {
    var p = location.pathname;
    if (/\/juego\/[A-Za-z0-9]+\/?$/.test(p)) p = p.replace(/\/juego\/[A-Za-z0-9]+\/?$/, '');  // URL amigable
    else p = p.replace(/\/(?:index|juego)\.(?:php|html)$/, '');                               // index.php/html · juego.php/html
    return p.replace(/\/$/, '');                                                              // sin barra final
  })();
  /* URL de la ficha de un juego (URL amigable) */
  function urlJuego(id) { return BASE + '/juego/' + id; }

  /* ============================================================
     MODO ESTÁTICO (GitHub Pages): el build genera HTML + JSON y el
     filtrado/búsqueda/paginación se hacen aquí, sobre
     datos/catalogo.json y datos/fuentes/<id>.json.
     ============================================================ */
  var ESTATICO = document.documentElement.hasAttribute('data-estatico');
  var LOGO = ESTATICO ? 'assets/logo.png' : 'asset.php?f=logo';
  function urlDetalle(id) {
    return ESTATICO ? 'datos/fuentes/' + encodeURIComponent(id) + '.json'
                    : 'api/detalle.php?id=' + encodeURIComponent(id);
  }
  function copiar(txt) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).catch(function () { fallbackCopiar(txt); });
    } else fallbackCopiar(txt);
  }
  function fallbackCopiar(txt) {
    var ta = document.createElement('textarea');
    ta.value = txt;
    ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }

  /* ============================================================
     TOAST — notificaciones no intrusivas
     ============================================================ */
  var toastWrap = null;
  function getToastWrap() {
    if (!toastWrap) {
      toastWrap = document.createElement('div');
      toastWrap.className = 'toast-wrap';
      document.body.appendChild(toastWrap);
    }
    return toastWrap;
  }
  /**
   * @param {string} msg   Texto del toast
   * @param {string} icon  Emoji/texto del icono
   * @param {string} tipo  Clase extra: 'ok' | 'warn' | 'tg' | ''
   * @param {number} ms    Duración en ms (default 2500)
   */
  function toast(msg, icon, tipo, ms) {
    var wrap = getToastWrap();
    var t = document.createElement('div');
    t.className = 'toast' + (tipo ? ' ' + tipo : '');
    t.innerHTML = '<span class="ti">' + esc(icon || '✓') + '</span><span class="tm">' + esc(msg) + '</span>';
    wrap.appendChild(t);
    var dur = ms || 2500;
    setTimeout(function () {
      t.classList.add('out');
      setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 250);
    }, dur);
  }

  /* ============================================================
     FAVORITOS — localStorage: pw_favs = {id: title, ...}
     ============================================================ */
  var FAV_KEY = 'pw_favs';

  function getFavs() {
    try { var d = JSON.parse(localStorage.getItem(FAV_KEY) || '{}'); return (d && typeof d === 'object') ? d : {}; }
    catch (e) { return {}; }
  }
  function saveFavs(favs) {
    try { localStorage.setItem(FAV_KEY, JSON.stringify(favs)); } catch (e) {}
  }
  function isFav(id) { return !!getFavs()[id]; }
  function toggleFav(id, title) {
    var favs = getFavs();
    if (favs[id]) {
      delete favs[id];
      saveFavs(favs);
      toast('Eliminado de favoritos', '♡', 'warn');
      return false;
    } else {
      favs[id] = title || id;
      saveFavs(favs);
      toast('Añadido a favoritos', '♥', 'ok');
      return true;
    }
  }

  /** Actualiza todos los indicadores visuales de favoritos en el catálogo */
  function refrescarFavDots() {
    var favs = getFavs();
    $$('.fav-dot[data-fav-id]').forEach(function (dot) {
      var id = dot.getAttribute('data-fav-id');
      dot.classList.toggle('on', !!favs[id]);
    });
    // badge en botón favorito de la ficha
    var btnFav = $('[data-fav][data-fav-id]');
    if (btnFav) {
      var id2 = btnFav.getAttribute('data-fav-id');
      var esFav = !!favs[id2];
      btnFav.textContent = esFav ? '♥ Favorito' : '♡ Favorito';
      btnFav.classList.toggle('on', esFav);
    }
    // contador en el sidebar
    var cnt = $('#favCnt');
    if (cnt) cnt.textContent = Object.keys(favs).length;
    // badge en la navbar
    var nb = $('#favBadge');
    if (nb) {
      var n = Object.keys(favs).length;
      nb.textContent = n > 99 ? '99+' : String(n);
      nb.hidden = n === 0;
    }
  }

  /** Clics en puntos favorito de tarjetas (catálogo) */
  document.addEventListener('click', function (e) {
    var dot = e.target.closest('.fav-dot[data-fav-id]');
    if (dot) {
      e.preventDefault();
      e.stopPropagation();
      var id = dot.getAttribute('data-fav-id');
      var card = dot.closest('.card');
      var title = card ? (($('.ct', card) || {}).textContent || '') : id;
      toggleFav(id, title);
      refrescarFavDots();
    }
  });

  /** Botón favorito en la ficha del juego */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-fav][data-fav-id]');
    if (btn) {
      var id = btn.getAttribute('data-fav-id');
      var title = (document.querySelector('h1') || {}).textContent || id;
      toggleFav(id, title);
      refrescarFavDots();
    }
  });

  /** Filtro "Mis favoritos" en el sidebar */
  var filtroFavs = $('#filtroFavs');
  var esModoFavs = false;
  var gridOrig = null; // guarda las tarjetas originales

  /* Badge "☰ Filtros" con nº de filtros activos (los de URL los pinta PHP;
     aquí se suman los que solo existen en JS, como el modo favoritos) */
  function refrescarBadgeFiltros() {
    var b = $('#filtrosBadge');
    if (!b) return;
    var base = parseInt(b.textContent, 10) || 0;
    var n = Math.max(0, (b.dataset.base != null ? +b.dataset.base : base) + (esModoFavs ? 1 : 0));
    b.textContent = n;
    b.hidden = n === 0;
  }
  (function () {
    var b = $('#filtrosBadge');
    if (b) b.dataset.base = b.hidden ? '0' : (parseInt(b.textContent, 10) || 0);
  })();

  if (filtroFavs) {
    filtroFavs.addEventListener('click', function (e) {
      e.preventDefault();
      var grid = $('#grid');
      if (!grid) return;

      esModoFavs = !esModoFavs;
      filtroFavs.classList.toggle('on', esModoFavs);
      refrescarBadgeFiltros();

      if (esModoFavs) {
        // guardar estado original y filtrar por favoritos
        gridOrig = grid.innerHTML;
        var favs = getFavs();
        var ids = Object.keys(favs);
        var cards = $$('.card', grid);
        cards.forEach(function (c) {
          var dot = c.querySelector('.fav-dot[data-fav-id]');
          var id = dot ? dot.getAttribute('data-fav-id') : null;
          c.style.display = (id && favs[id]) ? '' : 'none';
        });
        if (ids.length === 0) toast('Aún no tienes favoritos guardados', '♡', '');
      } else {
        // restaurar
        if (gridOrig !== null) { grid.innerHTML = gridOrig; gridOrig = null; }
        refrescarFavDots();
      }
    });
  }

  /* Inicializar favoritos al cargar */
  refrescarFavDots();

  /* Clic en el icono de favoritos de la navbar → activa el filtro "Mis favoritos" */
  var favNav = $('#favNav');
  if (favNav) {
    favNav.addEventListener('click', function (e) {
      if (!filtroFavs) return;              // no estamos en el catálogo
      e.preventDefault();
      /* en móvil, abre antes el drawer para que el filtro sea visible */
      if (side && sideBtn && !side.classList.contains('open') && window.innerWidth <= 900) {
        abrirFiltros(true);
      }
      if (!esModoFavs) filtroFavs.click();
      else filtroFavs.scrollIntoView({ block: 'center' });
    });
  }

  /* ============================================================
     HISTORIAL — localStorage: pw_hist = [{id,title,ts}, ...]
     ============================================================ */
  var HIST_KEY = 'pw_hist';
  var HIST_MAX = 30;

  function getHist() {
    try { var d = JSON.parse(localStorage.getItem(HIST_KEY) || '[]'); return Array.isArray(d) ? d : []; }
    catch (e) { return []; }
  }
  function addHist(id, title) {
    var h = getHist().filter(function (x) { return x.id !== id; });
    h.unshift({ id: id, title: title || id, ts: Date.now() });
    if (h.length > HIST_MAX) h = h.slice(0, HIST_MAX);
    try { localStorage.setItem(HIST_KEY, JSON.stringify(h)); } catch (e) {}
  }

  /* Registrar visita si estamos en la ficha */
  var juegoId = (document.body && document.body.dataset && document.body.dataset.juegoId) || '';
  if (juegoId) {
    var h1 = document.querySelector('h1');
    addHist(juegoId, h1 ? h1.textContent.trim() : juegoId);
  }

  /* Marcar tarjetas ya visitadas en el catálogo (reutilizable tras "Cargar más") */
  function marcarVisitadas() {
    var hist = getHist();
    if (!hist.length) return;
    var ids = {};
    hist.forEach(function (x) { ids[x.id] = true; });
    $$('.card').forEach(function (card) {
      var dot = card.querySelector('.fav-dot[data-fav-id]');
      if (dot && ids[dot.getAttribute('data-fav-id')]) {
        card.setAttribute('data-visited', '1');
      }
    });
  }
  marcarVisitadas();

  /* Sección "Historial" en el panel de filtros (últimos 20 visitados) */
  (function () {
    var box = $('#histList');
    if (!box) return;
    var hist = getHist().slice(0, 20);
    if (!hist.length) return;                     // mensaje vacío por defecto
    box.innerHTML = hist.map(function (x) {
      return '<a href="' + esc(urlJuego(x.id)) + '" title="' + esc(x.title) + '">' +
        '<span class="hid">' + esc(x.id) + '</span>' +
        '<span class="ht">' + esc(x.title) + '</span></a>';
    }).join('');
  })();

  /* ============================================================
     TARJETA (compartida: "cargar más" y el filtrado estático)
     ============================================================ */
  var msDesde = Date.now() - 7 * 86400000;
  function esNuevo(added) {
    var t = added ? Date.parse(added) : NaN;
    return !isNaN(t) && t > msDesde;
  }
  function escAttr(s) { return esc(s); }

  function tarjeta(g) {
    var src = g.coverUrl || LOGO;
    var extra = '';
    if (g.cov && g.art) extra = ' data-art="' + escAttr(g.art) + '"';
    if (!g.cov && !g.art) extra = ' data-nofb="1"';
    var mc = g.mc != null ? '<b>' + g.mc + '</b>' : '';
    var sz = g.size != null ? '<span>' + fmt(g.size) + ' GB</span>' : '';
    var yr = g.year != null ? '<span>' + g.year + '</span>' : '';
    return '<a class="card" href="' + esc(urlJuego(g.id)) + '">' +
      '<div class="tile" style="background:' + escAttr(g.tint || '#1e1e1e') + '">' +
        '<img src="' + escAttr(src) + '" alt="' + escAttr(g.title) + '" loading="lazy" width="384" height="512"' + extra + '>' +
        (g.zstd ? '<span class="badge">ZSTD</span>' : '') +
        (esNuevo(g.added) ? '<span class="badge-new">NUEVO</span>' : '') +
        '<span class="plat">' + esc(String(g.plat).toUpperCase()) + '</span>' +
        '<span class="fav-dot" data-fav-id="' + escAttr(g.id) + '" title="Favorito" aria-label="Favorito">♥</span>' +
        '<div class="dl"><span data-id="' + escAttr(g.id) + '">⬇ Descargar</span></div>' +
      '</div>' +
      '<div class="ct">' + esc(g.title) + '</div>' +
      '<div class="cm">' + mc + sz + yr + '</div>' +
    '</a>';
  }

  /* ============================================================
     CARGAR MÁS — paginación infinita (api/juegos.php; en modo
     estático, sobre datos/catalogo.json)
     (la paginación clásica sigue visible si no hay JS)
     ============================================================ */
  (function () {
    var btn = $('#moreBtn');
    if (!btn) return;
    var pagesNav = $('#pagesNav');
    var grid = $('#grid');
    if (!grid) return;

    var cargando = false;

    /* sin JS ya no hace falta la paginación clásica: se oculta aquí */
    if (pagesNav) pagesNav.hidden = true;
    var pag0 = parseInt(btn.getAttribute('data-pag'), 10) || 1;
    var pags0 = parseInt(btn.getAttribute('data-pags'), 10) || 1;
    if (pag0 >= pags0) btn.hidden = true;

    function append(items) {
      if (!items.length) { btn.hidden = true; return; }
      var frag = document.createElement('div');
      frag.innerHTML = items.map(tarjeta).join('');
      var nuevos = Array.prototype.slice.call(frag.children);
      nuevos.forEach(function (c) {
        c.style.opacity = '0';
        c.style.transform = 'translateY(14px)';
        c.style.transition = 'opacity .35s ease, transform .35s ease';
        grid.appendChild(c);
      });
      /* el siguiente frame lanza la animación */
      requestAnimationFrame(function () {
        nuevos.forEach(function (c) { c.style.opacity = '1'; c.style.transform = 'none'; });
      });
      refrescarFavDots();
      marcarVisitadas();
    }

    btn.addEventListener('click', function () {
      /* los atributos se releen en cada clic: el filtrado estático los cambia */
      var pag = parseInt(btn.getAttribute('data-pag'), 10) || 1;
      var pags = parseInt(btn.getAttribute('data-pags'), 10) || 1;
      var qs = btn.getAttribute('data-qs') || '';
      if (cargando || pag >= pags) return;
      cargando = true;
      var old = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = 'Cargando…';

      function fin(ok) {
        cargando = false; btn.disabled = false;
        if (btn.innerHTML === 'Cargando…') btn.innerHTML = old;
        if (!ok) { btn.innerHTML = 'Error — vuelve a intentarlo'; setTimeout(function () { btn.innerHTML = old; }, 1800); }
      }

      if (ESTATICO) {
        cargarCatalogoLocal()
          .then(function (cat) {
            var f = leerFiltrosURL();
            var r = filtrarLocal(cat.items, f, cat.por || 48);
            var sig = r.all.slice(pag * (cat.por || 48), (pag + 1) * (cat.por || 48));
            append(sig);
            btn.setAttribute('data-pag', String(pag + 1));
            btn.setAttribute('data-pags', String(r.pags));
            if (pag + 1 >= r.pags) btn.hidden = true;
            fin(true);
          })
          .catch(function () { fin(false); });
        return;
      }

      fetch('api/juegos.php' + (qs ? '?' + qs + '&' : '?') + 'pag=' + (pag + 1))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.items || !d.items.length) { btn.hidden = true; return; }
          append(d.items);
          var np = d.pag || pag + 1;
          btn.setAttribute('data-pag', String(np));
          if (np >= (d.pags || pags)) btn.hidden = true;
          fin(true);
        })
        .catch(function () { fin(false); });
    });
  })();

  /* ============================================================
     COMPARAR — hasta 2 juegos lado a lado (localStorage pw_cmp)
     ============================================================ */
  var CMP_KEY = 'pw_cmp';
  function getCmp() {
    try { var v = JSON.parse(localStorage.getItem(CMP_KEY)); return Array.isArray(v) ? v : []; }
    catch (e) { return []; }
  }
  function setCmp(list) {
    try { localStorage.setItem(CMP_KEY, JSON.stringify(list.slice(0, 2))); } catch (e) {}
    pintarBarraCmp();
  }
  function fmtCmp(n) {
    var x = Number(n);
    if (!isFinite(x)) return '—';
    return x.toFixed(x >= 100 ? 0 : x >= 10 ? 1 : 2).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1') + ' GB';
  }

  /* --- barra flotante: se inyecta en cualquier página --- */
  var cmpBar = null;
  function pintarBarraCmp() {
    if (!cmpBar) {
      cmpBar = document.createElement('div');
      cmpBar.id = 'cmpBar';
      cmpBar.className = 'cmp-bar';
      cmpBar.hidden = true;
      document.body.appendChild(cmpBar);
      cmpBar.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-cmp-act]') : null;
        if (!t) return;
        var act = t.getAttribute('data-cmp-act');
        if (act === 'quitar') {
          var id = t.getAttribute('data-cmp-id');
          setCmp(getCmp().filter(function (x) { return x.id !== id; }));
          sincronizarBtnCmp();
        } else if (act === 'limpiar') {
          setCmp([]); sincronizarBtnCmp();
        } else if (act === 'abrir') {
          abrirComparacion();
        }
      });
    }
    var list = getCmp();
    if (!list.length) { cmpBar.hidden = true; document.body.classList.remove('cmp-on'); return; }
    cmpBar.hidden = false;
    document.body.classList.add('cmp-on');
    cmpBar.innerHTML =
      '<span class="cmp-t">Comparar:</span>' +
      list.map(function (x) {
        return '<span class="cmp-chip">' + esc(x.title) +
          ' <button type="button" data-cmp-act="quitar" data-cmp-id="' + esc(x.id) + '" aria-label="Quitar ' + esc(x.title) + '">✕</button></span>';
      }).join('') +
      (list.length < 2 ? '<span class="cmp-hint">Elige 1 juego más</span>' : '') +
      '<button type="button" class="cmp-go" data-cmp-act="abrir"' + (list.length < 2 ? ' disabled' : '') + '>Ver comparación</button>' +
      '<button type="button" class="cmp-lim" data-cmp-act="limpiar">Limpiar</button>';
  }

  /* --- estado del botón "⇄ Comparar" en la ficha --- */
  function sincronizarBtnCmp() {
    var btn = document.querySelector('[data-cmp]');
    if (!btn) return;
    var id = btn.getAttribute('data-cmp-id');
    var dentro = getCmp().some(function (x) { return x.id === id; });
    btn.classList.toggle('on', dentro);
    btn.textContent = dentro ? '✓ En comparación' : '⇄ Comparar';
    btn.setAttribute('aria-pressed', dentro ? 'true' : 'false');
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-cmp]') : null;
    if (!btn) return;
    var item = {
      id: btn.getAttribute('data-cmp-id'),
      title: btn.getAttribute('data-title') || btn.getAttribute('data-cmp-id'),
      plat: btn.getAttribute('data-plat') || '',
      size: btn.getAttribute('data-size') || '',
      mc: btn.getAttribute('data-mc') || '',
      year: btn.getAttribute('data-year') || '',
      gen: btn.getAttribute('data-gen') || '',
      cover: btn.getAttribute('data-cover') || ''
    };
    var list = getCmp();
    var i = list.findIndex(function (x) { return x.id === item.id; });
    if (i >= 0) { list.splice(i, 1); toast('Quitado de la comparación', '⇄', ''); }
    else {
      if (list.length >= 2) { toast('Solo puedes comparar 2 juegos a la vez', '⇄', ''); return; }
      list.push(item); toast(list.length === 2 ? 'Listo: pulsa "Ver comparación"' : 'Añadido: elige 1 juego más', '⇄', '');
    }
    setCmp(list);
    sincronizarBtnCmp();
  });

  /* --- panel lateral a lado --- */
  function abrirComparacion() {
    var list = getCmp();
    if (list.length < 2) return;
    var ovl = document.getElementById('cmpOvl');
    if (!ovl) {
      ovl = document.createElement('div');
      ovl.id = 'cmpOvl';
      ovl.className = 'ovl';
      ovl.setAttribute('data-close-cmp', '');
      ovl.innerHTML = '<div class="cmpbox" role="dialog" aria-modal="true" aria-labelledby="cmpT">' +
        '<div class="dlhd"><div class="t" id="cmpT">Comparación</div>' +
        '<button type="button" class="x" data-close-cmp aria-label="Cerrar">✕</button></div>' +
        '<div id="cmpBody"></div></div>';
      document.body.appendChild(ovl);
      ovl.addEventListener('click', function (e) {
        if (e.target === ovl || (e.target.closest && e.target.closest('[data-close-cmp]'))) ovl.classList.remove('show');
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && ovl.classList.contains('show')) ovl.classList.remove('show');
      });
    }
    var filas = [
      ['Plataforma', function (x) { return esc(x.plat || '—'); }],
      ['Tamaño', function (x) { return x.size !== '' ? fmtCmp(x.size) : '—'; }],
      ['Metacritic', function (x) { return x.mc !== '' ? '<b>' + esc(x.mc) + '</b>' : '—'; }],
      ['Año', function (x) { return esc(x.year || '—'); }],
      ['Género', function (x) { return esc(x.gen || '—'); }]
    ];
    document.getElementById('cmpBody').innerHTML =
      '<table class="cmp-table"><thead><tr><th></th>' +
      list.map(function (x) {
        return '<th><img src="' + esc(x.cover) + '" alt="" width="70" height="93">' +
          '<a href="' + esc(urlJuego(x.id)) + '">' + esc(x.title) + '</a></th>';
      }).join('') + '</tr></thead><tbody>' +
      filas.map(function (f) {
        return '<tr><th>' + f[0] + '</th>' + list.map(function (x) { return '<td>' + f[1](x) + '</td>'; }).join('') + '</tr>';
      }).join('') + '</tbody></table>';
    ovl.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  pintarBarraCmp();
  sincronizarBtnCmp();

  /* ============================================================
     FALLBACK DE IMÁGENES
     ============================================================ */
  function fallbackImg(t) {
    if (t.dataset.nofb === '1') return;
    var st = t.dataset.fb || '0';
    if (st === '0') {
      var art = t.getAttribute('data-art');
      if (art) { t.dataset.fb = '1'; t.src = art; return; }
    }
    if (st === '0' || st === '1') {
      t.dataset.fb = '2';
      t.src = LOGO;
      var tile = t.closest ? t.closest('.tile') : null;
      if (tile) t.classList.add('ph');
      var cov = t.closest ? t.closest('.cover') : null;
      if (cov) cov.classList.add('nocov');
      if (t.id === 'dlcov') { t.style.objectFit = 'contain'; t.style.padding = '8px'; t.style.background = 'var(--s2)'; }
      return;
    }
    if (t.remove) t.remove();
  }
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG') fallbackImg(t);
  }, true);

  /* ============================================================
     BIENVENIDA
     ============================================================ */
  $$('[data-close-welcome]').forEach(function (b) {
    b.addEventListener('click', function () {
      var w = $('#welcome');
      if (w) w.classList.remove('show');
      recordarBienvenida();
    });
  });
  var wel = $('#welcome');
  if (wel) {
    wel.addEventListener('click', function (ev) {
      if (ev.target === wel) { wel.classList.remove('show'); recordarBienvenida(); }
    });
  }
  /* Sin cookies en GitHub Pages: la bienvenida se recuerda en localStorage */
  function recordarBienvenida() {
    if (!ESTATICO) return;
    try { localStorage.setItem('pw_seen', '1'); } catch (e) {}
  }
  if (wel && ESTATICO) {
    var yaVista = false;
    try { yaVista = !!localStorage.getItem('pw_seen'); } catch (e) {}
    if (yaVista) wel.classList.remove('show');
  }

  /* ============================================================
     DRAWER DE FILTROS (móvil)
     ============================================================ */
  var side = $('#side'), sideBtn = $('#filtrosBtn'), sideOv = $('#sideOverlay'), sideClose = $('#sideClose');
  function abrirFiltros(open) {
    if (!side) return;
    side.classList.toggle('open', open);
    if (sideOv) sideOv.classList.toggle('show', open);
    if (sideBtn) sideBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  }
  if (sideBtn) sideBtn.addEventListener('click', function () { abrirFiltros(!side.classList.contains('open')); });
  if (sideClose) sideClose.addEventListener('click', function () { abrirFiltros(false); });
  if (sideOv) sideOv.addEventListener('click', function () { abrirFiltros(false); });

  /* ============================================================
     ORDEN DEL CATÁLOGO
     ============================================================ */
  var orden = $('#orden');
  if (orden) {
    orden.addEventListener('change', function () {
      var u = new URL(window.location.href);
      u.searchParams.set('orden', orden.value);
      u.searchParams.delete('pag');
      window.location.href = u.toString();
    });
  }

  /* ============================================================
     AUTOCOMPLETADO
     ============================================================ */
  var q = $('#q'), ac = $('#ac'), acTimer = null, acLast = '';
  if (q && ac) {
    /* estado a11y del combobox */
    function acAbrir(open) {
      ac.hidden = !open;
      q.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (!open) q.removeAttribute('aria-activedescendant');
    }
    function acOpciones() { return $$('#ac [role="option"]'); }
    function acMover(pasos) {
      var ops = acOpciones();
      if (!ops.length) return;
      var i = ops.findIndex(function (o) { return o.getAttribute('aria-selected') === 'true'; });
      if (i >= 0) { ops[i].removeAttribute('aria-selected'); ops[i].classList.remove('ac-on'); }
      i = (i + pasos + ops.length) % (i < 0 ? ops.length : ops.length);
      if (i < 0) i += ops.length;
      ops[i].setAttribute('aria-selected', 'true');
      ops[i].classList.add('ac-on');
      q.setAttribute('aria-activedescendant', ops[i].id);
      ops[i].scrollIntoView({ block: 'nearest' });
    }
    function acLimpiarSel() {
      acOpciones().forEach(function (o) { o.removeAttribute('aria-selected'); o.classList.remove('ac-on'); });
      q.removeAttribute('aria-activedescendant');
    }

    q.addEventListener('input', function () {
      clearTimeout(acTimer);
      var v = q.value.trim();
      if (v.length < 2) { acAbrir(false); ac.innerHTML = ''; return; }
      acTimer = setTimeout(function () {
        if (v === acLast) return;
        acLast = v;
        var prom = ESTATICO
          ? cargarCatalogoLocal().then(function (cat) { return { items: buscarLocal(v, cat) }; })
          : fetch('api/buscar.php?q=' + encodeURIComponent(v)).then(function (r) { return r.json(); });
        prom
          .then(function (d) {
            if (q.value.trim() !== v) return;
            var items = (d && d.items) || [];
            if (!items.length) {
              ac.innerHTML = '<div class="none" role="status">Sin resultados para «' + esc(v) + '»</div>';
              acAbrir(true); return;
            }
            ac.innerHTML = items.map(function (g, i) {
              var src = g.coverUrl || (g.cov ? ('asset.php?f=cover&id=' + g.id) : (g.art || LOGO));
              var extra = '';
              if (g.cov && g.art) extra += ' data-art="' + esc(g.art) + '"';
              if (!g.cov && !g.art) extra += ' data-nofb="1"';
              return '<a role="option" id="ac-opt-' + i + '" aria-selected="false" href="' + esc(urlJuego(g.id)) + '">' +
                '<img src="' + esc(src) + '"' + extra + ' alt="" loading="lazy">' +
                '<span style="min-width:0"><span class="t">' + esc(g.title) + '</span>' +
                '<span class="m"><b>' + g.plat.toUpperCase() + '</b>' +
                (g.size != null ? '<span>' + fmt(g.size) + ' GB</span>' : '') +
                (g.zstd ? '<span style="color:var(--acc)">ZSTD</span>' : '') +
                '</span></span></a>';
            }).join('');
            acAbrir(true);
          })
          .catch(function () { acAbrir(false); });
      }, 180);
    });
    q.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { acAbrir(false); q.blur(); return; }
      if (ac.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); acMover(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); acMover(-1); }
      else if (e.key === 'Enter') {
        var sel = ac.querySelector('[role="option"][aria-selected="true"]');
        if (sel) { e.preventDefault(); location.href = sel.getAttribute('href'); }
      }
    });
    document.addEventListener('click', function (e) {
      if (ac && !ac.contains(e.target) && e.target !== q) acAbrir(false);
    });
  }

  /* ============================================================
     MODAL DE DESCARGAS
     ============================================================ */
  var ovl = $('#ovl');
  var cacheRel = {};

  function abrirModal(id, meta) {
    if (!ovl) return;
    ovl.classList.add('show');
    document.body.style.overflow = 'hidden';

    var list = $('#dllist'), n = $('#dln'), ft = $('#dlf');
    if (n) n.textContent = '';
    if (ft) ft.innerHTML = '';

    if (meta) pintarCabecera(meta);

    if (cacheRel[id]) { pintarFuentes(cacheRel[id]); return; }

    if (list) list.innerHTML = '<div class="empty">Cargando fuentes de descarga…</div>';

    fetch(urlDetalle(id))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) throw new Error(d.error);
        cacheRel[id] = d;
        pintarCabecera(d);
        pintarFuentes(d);
      })
      .catch(function (err) {
        if (list) list.innerHTML = '<div class="empty err">No se pudieron cargar las fuentes' +
          (err.message ? ' (' + esc(err.message) + ')' : '') + '. Inténtalo de nuevo en unos segundos.</div>';
        if (n) n.textContent = '';
      });
  }

  function pintarCabecera(d) {
    var cov = $('#dlcov');
    if (cov && d.cover) {
      cov.src = d.cover;
      cov.style.objectFit = d.hasCover === false ? 'contain' : 'cover';
      cov.style.padding = d.hasCover === false ? '8px' : '';
      cov.style.background = d.hasCover === false ? 'var(--s2)' : '';
    }
    var t = $('#dlt'); if (t && d.title) t.textContent = d.title;
    var m = $('#dlm');
    if (m) {
      var bits = [];
      if (d.id) bits.push('<b>' + esc(d.id) + '</b>');
      if (d.plat) bits.push('<span>' + esc(String(d.plat).toUpperCase()) + '</span>');
      if (d.size != null) bits.push('<span>' + fmt(d.size) + ' GB</span>');
      if (d.zstd && d.zstd.version) bits.push('<span style="color:var(--acc)">ZSTD ' + esc(d.zstd.version) + '</span>');
      m.innerHTML = bits.join('');
    }
  }

  function pintarFuentes(d) {
    var list = $('#dllist'), n = $('#dln'), ft = $('#dlf');
    var srcs = d.sources || [];
    if (n) n.textContent = srcs.length ? srcs.length + ' disponibles' : '';

    if (!srcs.length) {
      if (list) list.innerHTML = '<div class="empty">Sin fuentes disponibles ahora mismo. Pide ayuda en el canal de <a href="https://t.me/padrinostores" target="_blank" rel="noopener noreferrer">Padrino Store</a>.</div>';
      return;
    }

    if (list) {
      list.innerHTML = srcs.map(function (s, i) {
        var esZ = !!s.zstd;
        var tags = [];
        var fmtTxt = (s.format || 'binario').toUpperCase();
        var esBin = (s.format || 'binario') === 'binario';
        tags.push('<span class="tg f' + (esZ ? ' zf' : '') + (esBin ? ' unk' : '') + '"' +
          (esBin ? ' title="El servidor no declara el formato del archivo"' : '') + '>FORMATO: ' + esc(fmtTxt) + '</span>');
        if (s.flow) tags.push('<span class="tg">' + esc(s.flow) + '</span>');
        if (s.sizeGb != null) tags.push('<span class="tg">' + fmt(s.sizeGb) + ' GB</span>');
        if (s.health) tags.push('<span class="tg' + (s.health === 'ok' ? ' z' : '') + '">' + esc(s.health) + '</span>');
        if (esZ) tags.push('<span class="tg zs">ZSTD</span>');
        var label = s.label || s.provider || ('Fuente ' + (i + 1));
        return '<div class="src' + (esZ ? ' rowz' : '') + '">' +
          '<span class="dot' + (s.health && s.health !== 'ok' ? ' off' : '') + (esZ ? ' dz' : '') + '"></span>' +
          '<span class="info"><span class="p">' + esc(label) + '</span>' +
          (s.url ? '<span class="l">' + esc(dom(s.url)) + '</span>' : '') +
          (s.note ? '<span class="nt">' + esc(s.note) + '</span>' : '') +
          (tags.length ? '<span class="tags">' + tags.join('') + '</span>' : '') +
          '</span>' +
          '<a class="go" href="' + esc(s.url) + '" target="_blank" rel="noopener noreferrer">Abrir</a>' +
          '<button class="cp" data-copy-url="' + esc(s.url) + '" title="Copiar enlace">⧉</button>' +
          '</div>';
      }).join('');
    }

    if (ft) {
      var f = [];
      if (d.size != null) f.push('Tamaño total <b>' + fmt(d.size) + ' GB</b>');
      if (d.pub) f.push('Editorial <b>' + esc(d.pub) + '</b>');
      if (d.zstd && d.zstd.size) f.push('ZSTD <b>' + esc(d.zstd.size) + '</b>');
      f.push('Descarga directa desde tu navegador, sin pasar por la tienda de PS5.');
      if (f.length) ft.innerHTML = f.join(' · ');
    }

    $$('.cp', list).forEach(function (b) {
      b.addEventListener('click', function () {
        copiar(b.getAttribute('data-copy-url'));
        toast('Enlace copiado', '⧉', 'ok', 1800);
        b.textContent = '✓';
        setTimeout(function () { b.textContent = '⧉'; }, 1400);
      });
    });
  }

  function cerrarModal() {
    if (!ovl) return;
    ovl.classList.remove('show');
    document.body.style.overflow = '';
  }
  if (ovl) {
    ovl.addEventListener('click', function (e) { if (e.target === ovl || e.target.closest('[data-close-ovl]')) cerrarModal(); });
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { cerrarModal(); cerrarLightbox(); } });

  /* ============================================================
     BOTONES DE DESCARGA (tarjeta y ficha)
     ============================================================ */
  document.addEventListener('click', function (e) {
    var dl = e.target.closest('[data-dl]');
    if (dl) {
      e.preventDefault();
      var id = dl.getAttribute('data-dl');
      var card = dl.closest('.card');
      var meta = null;
      if (card) {
        var imgT = card.querySelector('.tile img');
        meta = {
          id: id,
          title: ($('.ct', card) || {}).textContent || '',
          cover: imgT ? imgT.getAttribute('src') : (ESTATICO ? LOGO : ('asset.php?f=cover&id=' + id)),
          hasCover: !!(imgT && imgT.getAttribute('src').indexOf('f=cover') > -1)
        };
      }
      abrirModal(id, meta);
      return;
    }
    var dls = e.target.closest('.dl span[data-id]');
    if (dls) {
      e.preventDefault();
      e.stopPropagation();
      var id2 = dls.getAttribute('data-id');
      var card2 = dls.closest('.card');
      var imgT2 = card2 && card2.querySelector('.tile img');
      abrirModal(id2, card2 ? {
        id: id2,
        title: ($('.ct', card2) || {}).textContent || '',
        cover: imgT2 ? imgT2.getAttribute('src') : (ESTATICO ? LOGO : ('asset.php?f=cover&id=' + id2)),
        hasCover: !!(imgT2 && imgT2.getAttribute('src').indexOf('f=cover') > -1)
      } : null);
    }
  });

  /* ============================================================
     COPIAR ENLACE (ficha)
     ============================================================ */
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var u = location.origin + BASE + '/' + b.getAttribute('data-copy');
      copiar(u);
      toast('Enlace copiado al portapapeles', '🔗', 'ok', 1800);
      var old = b.textContent;
      b.textContent = '✓ Copiado';
      setTimeout(function () { b.textContent = old; }, 1500);
    });
  });

  /* ============================================================
     LIGHTBOX DE CAPTURAS
     ============================================================ */
  var lb = null;
  function cerrarLightbox() { if (lb) lb.classList.remove('show'); }
  $$('.shots img').forEach(function (img) {
    img.addEventListener('click', function () {
      if (!lb) {
        lb = document.createElement('div');
        lb.className = 'lightbox';
        lb.innerHTML = '<img alt="Captura ampliada">';
        lb.addEventListener('click', cerrarLightbox);
        document.body.appendChild(lb);
      }
      $('img', lb).src = img.getAttribute('data-full') || img.src;
      lb.classList.add('show');
    });
  });

  /* ============================================================
     NOTA DE DESCARGAS EN FICHA
     ============================================================ */
  var dlnote = $('#dlnote');
  if (dlnote && juegoId) {
    fetch(urlDetalle(juegoId))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        cacheRel[juegoId] = d;
        var n = (d.sources || []).length;
        dlnote.textContent = n
          ? n + ' fuentes de descarga disponibles · ' + (d.size != null ? fmt(d.size) + ' GB' : 'sin tamaño')
          : 'Sin fuentes disponibles ahora mismo — pide ayuda en el canal de Padrino Store.';
      })
      .catch(function () { dlnote.textContent = 'No se pudo comprobar la disponibilidad.'; });
  }

  /* ============================================================
     ESTÁTICO — filtrado, búsqueda y paginación en el navegador
     (espejo exacto de filtrar_catalogo() y api/buscar.php en PHP;
     se usan solo con data-estatico en <html>)
     ============================================================ */
  var catLocal = null;
  function cargarCatalogoLocal() {
    if (!catLocal) {
      catLocal = fetch('datos/catalogo.json')
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (d) { if (!d || !d.items) throw new Error('catálogo no válido'); return d; })
        .catch(function (e) { catLocal = null; throw e; });
    }
    return catLocal;
  }

  /** norm_txt() en JS: minúsculas sin acentos/diacríticos */
  function normLocal(s) {
    return String(s == null ? '' : s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }
  function cmpStr(a, b) { return a < b ? -1 : a > b ? 1 : 0; }
  function cmpNum(a, b) { return a < b ? -1 : a > b ? 1 : 0; }

  function makeCmp(orden) {
    return function (a, b) {
      switch (orden) {
        case 'rating': return cmpNum(b.mc == null ? -1 : b.mc, a.mc == null ? -1 : a.mc) || cmpStr(a.title, b.title);
        case 'title':  return cmpStr(String(a.title).toLowerCase(), String(b.title).toLowerCase());
        case 'size':   return cmpNum(b.size == null ? -1 : b.size, a.size == null ? -1 : a.size);
        case 'year':   return cmpNum(b.year == null ? 0 : b.year, a.year == null ? 0 : a.year) || cmpStr(a.title, b.title);
        default:       return cmpStr(String(b.added || ''), String(a.added || '')) || cmpStr(a.title, b.title);
      }
    };
  }

  function skuScore(g) {
    return (g.cov ? 8 : 0) + (g.art ? 4 : 0) + (g.size != null ? 2 : 0) + (g.mc != null ? 1 : 0);
  }

  /** Un juego = un resultado (agrupa SKUs repetidos), como dedup_titulos() */
  function dedupLocal(items) {
    var ord = [], best = {};
    items.forEach(function (g) {
      var k = normLocal(g.title);
      if (!Object.prototype.hasOwnProperty.call(best, k)) { best[k] = g; ord.push(k); return; }
      var a = skuScore(g), b = skuScore(best[k]);
      var ga = String(g.added || ''), gb = String(best[k].added || '');
      if (a > b || (a === b && ga > gb) || (a === b && ga === gb && g.id < best[k].id)) best[k] = g;
    });
    return ord.map(function (k) { return best[k]; });
  }

  function leerFiltrosURL() {
    var p = new URLSearchParams(location.search);
    var gen = [];
    p.forEach(function (v, k) {
      if ((k === 'gen' || k.indexOf('gen[') === 0) && v) gen.push(v);
    });
    var plat = p.get('plat');
    var size = p.get('size');
    var ord = p.get('orden');
    var mc = parseInt(p.get('mcmin'), 10);
    return {
      q: String(p.get('q') || '').slice(0, 64),
      plat: (plat === 'ps5' || plat === 'ps4') ? plat : '',
      gen: gen,
      zstd: !!p.get('zstd'),
      dir: !!p.get('dir'),
      size: (size === 's' || size === 'm' || size === 'l') ? size : '',
      mcmin: isFinite(mc) ? Math.max(0, Math.min(100, mc)) : 0,
      orden: ['added', 'rating', 'title', 'size', 'year'].indexOf(ord) > -1 ? ord : 'added',
      pag: Math.max(1, parseInt(p.get('pag'), 10) || 1)
    };
  }

  /* espejo de la condición del banner en index.php (el orden no la altera) */
  function hayRestriccion(f) {
    return !!(f.q || f.plat || f.gen.length || f.zstd || f.dir || f.size || f.mcmin || f.pag > 1);
  }
  function hayCambios(f) { return hayRestriccion(f) || f.orden !== 'added'; }

  function nFiltrosLocal(f) {
    return (f.q ? 1 : 0) + (f.plat ? 1 : 0) + f.gen.length + (f.zstd ? 1 : 0) +
           (f.dir ? 1 : 0) + (f.size ? 1 : 0) + (f.mcmin > 0 ? 1 : 0);
  }

  function clonFiltro(f) {
    return { q: f.q, plat: f.plat, gen: f.gen.slice(), zstd: f.zstd, dir: f.dir,
             size: f.size, mcmin: f.mcmin, orden: f.orden, pag: f.pag };
  }

  /** Cadena de consulta, espejo de link_filtro() de index.php */
  function qsDe(f) {
    var p = [];
    if (f.q) p.push('q=' + encodeURIComponent(f.q));
    if (f.plat) p.push('plat=' + encodeURIComponent(f.plat));
    f.gen.forEach(function (g, i) { p.push('gen%5B' + i + '%5D=' + encodeURIComponent(g)); });
    if (f.zstd) p.push('zstd=1');
    if (f.dir) p.push('dir=1');
    if (f.size) p.push('size=' + encodeURIComponent(f.size));
    if (f.mcmin) p.push('mcmin=' + f.mcmin);
    if (f.orden && f.orden !== 'added') p.push('orden=' + encodeURIComponent(f.orden));
    if (f.pag > 1) p.push('pag=' + f.pag);
    return p.join('&');
  }

  /** Espejo de filtrar_catalogo() */
  function filtrarLocal(items, f, por) {
    por = por || 48;
    var qn = f.q ? normLocal(f.q) : '';
    var idq = f.q ? f.q.replace(/[^A-Za-z0-9]/g, '').toUpperCase() : '';
    var out = [], outId = [];
    for (var i = 0; i < items.length; i++) {
      var g = items[i];
      if (f.plat && g.plat !== f.plat) continue;
      if (f.gen.length && !f.gen.some(function (x) { return (g.gen || []).indexOf(x) > -1; })) continue;
      if (f.zstd && !g.zstd) continue;
      if (f.dir && !g.dir) continue;
      if (f.mcmin > 0 && (g.mc || 0) < f.mcmin) continue;
      if (f.size) {
        var s = g.size;
        if (s == null) continue;
        if (f.size === 's' && !(s < 10)) continue;
        if (f.size === 'm' && !(s >= 10 && s <= 50)) continue;
        if (f.size === 'l' && !(s > 50)) continue;
      }
      if (qn) {
        var raw = g.title + ' ' + g.id + ' ' + (g.dev || '') + ' ' + (g.pub || '') + ' ' + (g.gen || []).join(' ');
        if (idq && String(g.id).indexOf(idq) === 0) { outId.push(g); continue; }
        if (normLocal(raw).indexOf(qn) === -1 && raw.toLowerCase().indexOf(f.q.toLowerCase()) === -1) continue;
      }
      out.push(g);
    }
    var cmp = makeCmp(f.orden);
    outId.sort(cmp);
    out.sort(cmp);
    var res = outId.length ? outId.concat(out) : out;
    if (f.q) res = dedupLocal(res);
    var total = res.length;
    var pags = Math.max(1, Math.ceil(total / por));
    var pag = Math.min(Math.max(1, f.pag || 1), pags);
    return { items: res.slice((pag - 1) * por, pag * por), all: res, total: total, pag: pag, pags: pags };
  }

  /** Espejo de api/buscar.php (autocompletado) */
  function buscarLocal(v, cat) {
    var ql = v.toLowerCase(), qn = normLocal(v);
    var idq = v.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    var exact = [], idp = [], pref = [], soft = [];
    var items = cat.items;
    for (var i = 0; i < items.length; i++) {
      var g = items[i];
      var t = String(g.title).toLowerCase(), tn = normLocal(g.title);
      var id = String(g.id).toLowerCase();
      var dev = String(g.dev || '').toLowerCase(), pub = String(g.pub || '').toLowerCase();
      if (id === ql || t === ql || tn === qn) exact.push(g);
      else if (idq && String(g.id).indexOf(idq) === 0) idp.push(g);
      else if (t.indexOf(ql) === 0 || tn.indexOf(qn) === 0) pref.push(g);
      else if (t.indexOf(ql) > -1 || id.indexOf(ql) > -1 || tn.indexOf(qn) > -1 ||
               dev.indexOf(ql) > -1 || pub.indexOf(ql) > -1) soft.push(g);
      if (exact.length + idp.length + pref.length + soft.length >= 150) break;
    }
    return dedupLocal(exact.concat(idp, pref, soft)).slice(0, 10);
  }

  function fmtEntero(n) { return Number(n).toLocaleString('es-ES'); }

  /** Chips de filtro activos (espejo de index.php) */
  function pintarChipsLocal(f) {
    var bar = $('.bar');
    if (!bar) return;
    $$('.fchip', bar).forEach(function (c) { if (c.parentNode) c.parentNode.removeChild(c); });
    var chips = [];
    if (f.q) chips.push(['Búsqueda: ' + f.q, function (n) { n.q = ''; }]);
    if (f.plat) chips.push(['Plataforma: ' + f.plat.toUpperCase(), function (n) { n.plat = ''; }]);
    f.gen.forEach(function (gn) {
      chips.push(['Género: ' + gn, function (n) { n.gen = n.gen.filter(function (x) { return x !== gn; }); }]);
    });
    if (f.zstd) chips.push(['Solo ZSTD', function (n) { n.zstd = false; }]);
    if (f.dir) chips.push(['Descarga directa', function (n) { n.dir = false; }]);
    if (f.size) chips.push(['Tamaño: ' + ({ s: '<10 GB', m: '10–50 GB', l: '>50 GB' }[f.size] || ''), function (n) { n.size = ''; }]);
    if (f.mcmin) chips.push(['Metacritic ' + f.mcmin + '+', function (n) { n.mcmin = 0; }]);
    var ref = bar.querySelector('.sort-wrap');
    chips.forEach(function (c) {
      var n = clonFiltro(f);
      c[1](n);
      n.pag = 1;
      var sp = document.createElement('span');
      sp.className = 'fchip';
      sp.appendChild(document.createTextNode(c[0] + ' '));
      var a = document.createElement('a');
      a.href = 'index.html' + (qsDe(n) ? '?' + qsDe(n) : '');
      a.textContent = '✕';
      sp.appendChild(a);
      if (ref) bar.insertBefore(sp, ref); else bar.appendChild(sp);
    });
  }

  /** Enlaces y estado activo del sidebar (espejo de link_filtro()) */
  function pintarSidebarLocal(f) {
    $$('#side .opt[data-fk]').forEach(function (a) {
      var k = a.getAttribute('data-fk'), v = a.getAttribute('data-fv');
      var n = clonFiltro(f);
      if (k === 'gen') {
        var i = n.gen.indexOf(v);
        if (i > -1) n.gen.splice(i, 1); else n.gen.push(v);
      } else if (k === 'zstd' || k === 'dir') {
        n[k] = !n[k];
      } else if (String(n[k]) === String(v)) {
        n[k] = (k === 'mcmin') ? 0 : '';
      } else {
        n[k] = (k === 'mcmin') ? (parseInt(v, 10) || 0) : v;
      }
      n.pag = 1;
      a.setAttribute('href', 'index.html' + (qsDe(n) ? '?' + qsDe(n) : ''));
      var on = (k === 'gen') ? (f.gen.indexOf(v) > -1)
             : (k === 'zstd' || k === 'dir') ? !!f[k]
             : String(f[k]) === String(v);
      a.classList.toggle('on', on);
    });
  }

  /** Bloque "Sin resultados" (espejo del de index.php) */
  function pintarNoResLocal(total) {
    var grid = $('#grid');
    if (!grid) return;
    var nr = $('#noresEstatico');
    if (total > 0) {
      grid.style.display = '';
      if (nr && nr.parentNode) nr.parentNode.removeChild(nr);
      return;
    }
    grid.style.display = 'none';
    if (!nr) {
      nr = document.createElement('div');
      nr.id = 'noresEstatico';
      nr.className = 'nores';
      nr.innerHTML = '<img src="' + esc(LOGO) + '" alt="" width="64" height="64">' +
        '<h2>Sin resultados</h2>' +
        '<p>No encontramos juegos con esos filtros. Prueba a quitar alguno.</p>' +
        '<a class="gbtn p" href="index.html">Ver todo el catálogo</a>';
      grid.parentNode.insertBefore(nr, grid.nextSibling);
    }
  }

  /** Aplica los filtros de la URL al catálogo renderizado por el build */
  function pintarEstatico(f) {
    return cargarCatalogoLocal().then(function (cat) {
      var r = filtrarLocal(cat.items, f, cat.por || 48);
      var grid = $('#grid');
      if (grid) {
        grid.style.display = '';
        grid.innerHTML = r.items.map(tarjeta).join('');
      }
      var res = $('.bar .res b');
      if (res) res.textContent = fmtEntero(r.total);
      pintarChipsLocal(f);
      pintarSidebarLocal(f);
      var qi = $('#q');
      if (qi && qi.value !== f.q) qi.value = f.q;
      var sel = $('#orden');
      if (sel) sel.value = f.orden;
      var b = $('#filtrosBadge');
      if (b) {
        var n = nFiltrosLocal(f);
        b.textContent = String(n);
        b.hidden = n === 0;
        b.dataset.base = String(n);
      }
      var rest = hayRestriccion(f);
      var tb = $('.tg-banner'); if (tb) tb.style.display = rest ? 'none' : '';
      var nv = $('.nov-wrap');   if (nv) nv.style.display = rest ? 'none' : '';
      var btn = $('#moreBtn');
      if (btn) {
        btn.setAttribute('data-pag', String(r.pag));
        btn.setAttribute('data-pags', String(r.pags));
        btn.setAttribute('data-qs', qsDe(f));
        btn.hidden = r.pag >= r.pags;
      }
      pintarNoResLocal(r.total);
      refrescarFavDots();
      marcarVisitadas();
      document.documentElement.classList.remove('pre');
      return r;
    }).catch(function (err) {
      document.documentElement.classList.remove('pre');
      toast('No se pudo cargar el catálogo (' + (err && err.message ? err.message : 'error') + ')', '⚠', 'err', 3200);
    });
  }

  /* Al cargar: si la URL trae filtros, se aplican sobre el HTML del build */
  if (ESTATICO) {
    if ($('#grid')) {
      var f0 = leerFiltrosURL();
      if (hayCambios(f0) || location.search) pintarEstatico(f0);
      else document.documentElement.classList.remove('pre');
    } else {
      document.documentElement.classList.remove('pre');
    }
  }

})();
