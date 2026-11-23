/* ============================================================
   Padrino Store Web — guard.js
   Anti-inspección: F12 · clic derecho · Ctrl+U/S/P ·
   Ctrl+Shift+I/J/C · detector de DevTools · bloqueo de selección
   ============================================================ */
(function () {
  'use strict';

  /* bloquea el menú contextual */
  document.addEventListener('contextmenu', function (e) {
    e.preventDefault();
    return false;
  }, { capture: true });

  /* bloquea arrastrar imágenes/texto y soltar archivos */
  document.addEventListener('dragstart', function (e) { e.preventDefault(); }, { capture: true });
  document.addEventListener('drop', function (e) { e.preventDefault(); }, { capture: true });

  /* atajos de inspección */
  var BLOQUEADOS = {
    'F12': true,
    'U': { ctrl: true, shift: false },        // Ctrl+U (código fuente)
    'S': { ctrl: true, shift: false },        // Ctrl+S (guardar)
    'P': { ctrl: true, shift: false },        // Ctrl+P (imprimir)
    'I': { ctrl: true, shift: true },         // Ctrl+Shift+I
    'J': { ctrl: true, shift: true },         // Ctrl+Shift+J
    'C': { ctrl: true, shift: true },         // Ctrl+Shift+C
    'K': { ctrl: true, shift: true },         // Ctrl+Shift+K (consola en algunos navegadores)
  };

  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toUpperCase();
    var regla = BLOQUEADOS[k];
    if (!regla) return;

    var ctrl = e.ctrlKey || e.metaKey;
    if (regla === true) { e.preventDefault(); e.stopPropagation(); return; }   // F12
    if (regla.ctrl && ctrl && !!e.shiftKey === !!regla.shift) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, { capture: true, passive: false });

  /* atajo antiguo: Ctrl+Shift+I en Firefox/Safari sin key unificada */
  window.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && ['I', 'J', 'C', 'K'].indexOf((e.key || '').toUpperCase()) > -1) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, { capture: true, passive: false });

  /* bloquea inspeccionar elementos desde el menú del navegador (view-source por URL) */
  if (location.protocol === 'view-source:') { location.replace('about:blank'); }

  /* ---------- detector de DevTools ---------- */
  var abierto = false;
  function marcarAbierto() {
    if (abierto) return;
    abierto = true;
    intentarProteger();
  }

  function medirDevTools() {
    /* sin `debugger`: pausaba el hilo principal cada 3 s si había DevTools
       abierto (coste de rendimiento). Solo heurística de tamaño. */
    var w = window.outerWidth - window.innerWidth > 160;
    var h = window.outerHeight - window.innerHeight > 160;
    if ((w && h) && window.innerWidth < 1200) marcarAbierto();
  }

  setInterval(medirDevTools, 3000);

  /* ---------- ofuscación / endurecimiento ---------- */
  function intentarProteger() {
    /* desactiva la selección de texto (estilo de toda la web) */
    var s = document.createElement('style');
    s.id = '__guard__';
    s.textContent =
      /* selección bloqueada solo en imágenes y enlaces (no en todo el DOM:
         el usuario debe poder seleccionar texto y usar Ctrl+C) */
      'img{-webkit-user-select:none;-moz-user-select:none;user-select:none}' +
      'html input,html textarea,html [contenteditable]{-webkit-user-select:text!important;-moz-user-select:text!important;user-select:text!important}' +
      'img{-webkit-user-drag:none;user-drag:none}';
    if (!document.getElementById('__guard__')) {
      (document.head || document.documentElement).appendChild(s);
    }

    /* vacía la consola en navegadores que lo permiten */
    try {
      var v = function () {};
      console.log = console.info = console.warn = console.debug = v;
      console.clear = v;
    } catch (e) {}
  }

  /* aplica desde el inicio (no solo al detectar DevTools) */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', intentarProteger);
  } else intentarProteger();

  /* NOTA: se eliminó Object.defineProperty(document, 'location', ...):
         rompía librerías externas y el propio JS de la web (document.location
         dejaba de ser un objeto Location). */
})();
