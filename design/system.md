# Sistema de diseño de Nexo

Sincronizado desde el código del proyecto. Solo se recogen valores que existen en las fuentes; no se inventa ninguno.

Fuentes leídas: `resources/css/app.css` (bloque `@theme static` y `@layer base`), `resources/views/layouts/app.blade.php` (shell), `package.json` (fuentes y herramientas). No se leyeron vistas de módulos concretos.

## Color

Todos los colores se declaran una vez con `light-dark(valor claro, valor oscuro)`; el valor usado sigue `color-scheme`. Fuente: `resources/css/app.css:19-46`.

| Token | Claro | Oscuro | Uso |
| --- | --- | --- | --- |
| `--color-canvas` | `#dde3ec` | `#202634` | Fondo de la aplicación |
| `--color-surface` | `#f2f5f9` | `#2a3142` | Superficie de bloques |
| `--color-raised` | `#fbfcfe` | `#333b50` | Superficie elevada: tarjetas, menús |
| `--color-ink` | `#172033` | `#eceff6` | Texto principal |
| `--color-muted` | `#4b566e` | `#aab3c7` | Texto secundario |
| `--color-rule` | `ink` al 8 % | `ink` al 8 % | Separadores translúcidos |
| `--color-on-accent` | `#ffffff` | `#141925` | Texto sobre acento |

Acentos por sección (el `body` define `--line`): `--color-line-panel` `#3a50c8` / `#8b9cff`, `--color-line-socios` `#0f766e` / `#2dd4bf`, `--color-line-cuotas` `#b45309` / `#fbbf24`, `--color-line-movimientos` `#6d28d9` / `#b79cff`.

Semánticos: `--color-danger` `#b42318` / `#f97066`, `--color-warn` `#93370d` / `#fec84b`, `--color-ok` `#067647` / `#47cd89`, `--color-info` `#3538cd` / `#a4bcfd`, con sus variantes `-soft` al 10-20 % de opacidad.

## Tipografía

- Familia única: `--font-sans: 'Inter Variable', system-ui, ...` (`app.css:18`). Se sirve localmente con `@fontsource-variable/inter`; sin CDN.
- La jerarquía se construye con tamaño, peso y espacio, no con cajas (`app.css:6-16`).
- Números tabulares para cifras monetarias: la app usa la variante de Inter; no hay token propio.

## Espacio, radio y sombras

- Escala de espacio de Tailwind 4 (4 px de base); el proyecto no define una escala propia y no se añade una paralela para no duplicar decisiones.
- Radio, añadido en la issue #26 (`app.css`): `--radius-sm: 8px`, `--radius-md: 12px`, `--radius-lg: 16px`, `--radius-pill: 999px`. Nombres semánticos, no por píxel.
- Movimiento, añadido en la issue #26: `--dur-fast: 120ms`, `--dur-base: 200ms`, `--dur-slow: 320ms`, `--ease-standard: cubic-bezier(0.2, 0, 0, 1)`, más el `--ease-out` que ya existía.
- Estado, añadido en la issue #26: `--color-skeleton` (tinta al 12 %) y `--color-overlay` (velo de diálogo, claro y oscuro).
- `--shadow-ring`: anillo de 1 px más una sombra mínima (`app.css`).
- `--shadow-lift`: anillo más elevación para elementos flotantes (`app.css`).

## Reglas de componente

- Superficies separadas por anillos translúcidos, no por bordes sólidos.
- Color reservado al significado; el acento de sección solo en enlace, botón primario y punto del menú.
- Un resplandor suave del acento ilumina la parte superior de cada página.
- Tema oscuro por defecto cuando el sistema no expresa preferencia; el botón del menú fija `data-theme` en `<html>`.
- Shell: barra superior translúcida (`--topbar-bg`) y contenido con ancho máximo; el JavaScript propio es un fragmento mínimo para alternar tema.

## Lo que el sistema todavía no fija

- Duración de esqueletos y de transiciones entre páginas: se apoyan en los tokens de movimiento ya añadidos, sin valores nuevos por componente.
- Densidad de tabla (alta o cómoda) como token: se decide con utilidades de espacio hasta que una pantalla real lo exija.
