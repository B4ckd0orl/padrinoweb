/* pre.js — solo el build estático (GitHub Pages).
   Se carga en <head>, antes del CSS y del cuerpo. Si la URL trae
   parámetros (filtros, búsqueda, orden…), añade la clase "pre" al
   <html> para que style.css oculte la zona dinámica del catálogo
   (grid, banner, novedades, chips…) mientras assets/app.js aplica
   esos filtros sobre datos/catalogo.json. La home sin filtros no se
   oculta: su HTML ya es el correcto. Sin JS no se ejecuta y nada se
   oculta; si app.js falla, el failsafe de 6 s retira la clase. */
(function () {
  if (!location.search) return;                   // sin parámetros: nada que aplicar
  var p = location.pathname;
  if (p.indexOf('/juego/') > -1) return;          // las fichas no se filtran
  var h = document.documentElement;
  h.classList.add('pre');
  setTimeout(function () { h.classList.remove('pre'); }, 6000);
})();
