# Padrino Store Web

Catálogo web de juegos **PS5 y PS4** de Padrino Store: búsqueda, filtros,
fichas con capturas y descargas con múltiples fuentes (ordenadas por
velocidad de host). Tema Durazno.

🌐 **Web**: <https://b4ckd0orl.github.io/padrinoweb>
💬 **Telegram**: [@padrinostores](https://t.me/padrinowers)

> Este repo publica **el código y el build estático**. Los datos del
> catálogo viven en [`B4ckd0orl/Padrino-Store`](https://github.com/B4ckd0orl/Padrino-Store)
> (solo lectura: este proyecto nunca lo modifica).

## Cómo funciona

| Modo | Dónde | Qué sirve |
|------|-------|-----------|
| **Web (PHP)** | XAMPP/Apache en local (`index.php`) | PHP + `api/*.php` en vivo |
| **Estático** | GitHub Pages (build automático) | `scripts/estatico.php` genera `dist/` |

El modo estático no ejecuta PHP: el build renderiza `index.html` y una
ficha por juego (`juego/<ID>/index.html`), y deja el filtrado, la
búsqueda y la paginación en el navegador sobre `datos/catalogo.json`
(`assets/app.js` con `data-estatico` en `<html>`).

## Build estático (GitHub Pages)

Workflow: `.github/workflows/pages.yml` — se ejecuta en cada push a
`main`, a diario (05:40 UTC) y bajo demanda (`workflow_dispatch`):

1. Clona `B4ckd0orl/Padrino-Store` en solo lectura.
2. `php scripts/estatico.php` → genera `dist/`.
3. Publica `dist/` con GitHub Actions en `b4ckd0orl.github.io/padrinoweb`.

Variables de entorno del build:

| Variable | Defecto | Descripción |
|----------|---------|-------------|
| `PADRINO_STORE` | ruta local | repo de datos (solo lectura) |
| `PADRINO_BASE_PATH` | `''` | prefijo del sitio (`/padrinoweb` en Pages) |
| `PADRINO_BASE_URL` | `https://b4ckd0orl.github.io/padrinoweb` | URL pública (canonical/OG) |
| `PADRINO_COVERS_CDN` | jsDelivr sobre `Padrino-Store` | CDN de portadas |

En local:

```bash
PADRINO_BASE_PATH=/padrinoweb php scripts/estatico.php
python -m http.server 8000        # sirviendo dist/ bajo /padrinoweb
```

## Estructura

```
index.php · juego.php · asset.php   páginas PHP (modo web)
inc/                                núcleo compartido (catálogo, fichas, descargas, seguridad)
api/                                JSON en vivo (juegos, buscar, detalle)
assets/                             app.js · style.css · guard.js · pre.js
scripts/                            estatico.php · auditoría · sincronizar_enlaces · tests
cache/                              caché local (solo van al repo covers/ y fuentes/externos.json)
.github/workflows/pages.yml         build + publicación
```

## Tests

```bash
php scripts/test_discrecion.php     # ningún nombre ajeno como texto visible
php scripts/auditoria.php           # informe general (incl. cobertura de enlaces)
php scripts/sincronizar_enlaces.php # refresca fuentes externas (CLI, con red)
```
