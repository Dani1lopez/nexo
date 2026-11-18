# Sistema de diseño de Nexo

Sincronizado desde el código del proyecto. Solo se recogen valores que existen en las fuentes; no se inventa ninguno.

Fuentes leídas: `resources/css/app.css` (bloques `@theme static` líneas 19-49, `@layer base` desde la 76 y `@layer components`), `resources/views/layouts/app.blade.php` (shell, barra superior, menú), `resources/views/home.blade.php` (portada), `package.json` (fuentes y herramientas).

Resincronizado tras los cambios de `resources/css/app.css` posteriores a la primera versión de este documento: `--color-line-saldos` y su parada en el menú (issue #32), la composición del panel `.page-head`/`.kpis`/`.next` (issue #27) y el formulario de conciliación `.reconcile` (issue #28).

## Color

Todos los colores se declaran una vez con `light-dark(valor claro, valor oscuro)`; el valor usado sigue `color-scheme`. Fuente: `resources/css/app.css:22-48` y `:65-66`.

### Base

| Token | Claro | Oscuro | Uso |
| --- | --- | --- | --- |
| `--color-canvas` | `#dde3ec` | `#202634` | Fondo de la aplicación |
| `--color-surface` | `#f2f5f9` | `#2a3142` | Superficie de bloques y tablas |
| `--color-raised` | `#fbfcfe` | `#333b50` | Superficie elevada: tarjetas, menús, controles |
| `--color-ink` | `#172033` | `#eceff6` | Texto principal |
| `--color-muted` | `#4b566e` | `#aab3c7` | Texto secundario |
| `--color-rule` | `ink` al 8 % | `ink` al 8 % | Separadores translúcidos |
| `--color-on-accent` | `#ffffff` | `#141925` | Texto sobre acento |

### Acentos de sección

El `body` define `--line` con el acento de la sección actual (`line-panel`, `line-saldos`, `line-socios`, `line-cuotas`, `line-movimientos`). `--line-soft` es el acento al 12 % mezclado con `--color-surface`, y `--glow` es el resplandor superior de cada página.

| Token | Claro | Oscuro | Sección |
| --- | --- | --- | --- |
| `--color-line-panel` | `#3a50c8` | `#8b9cff` | Panel |
| `--color-line-saldos` | `#9d174d` | `#f9a8d4` | Saldos |
| `--color-line-socios` | `#0f766e` | `#2dd4bf` | Socios |
| `--color-line-cuotas` | `#b45309` | `#fbbf24` | Cuotas |
| `--color-line-movimientos` | `#6d28d9` | `#b79cff` | Movimientos |

El menú lateral dibuja una vía con las cinco paradas, en este orden: panel, saldos, socios, cuotas, movimientos (`app.css`, gradiente de `.side::before`). La portada repite ese orden en `.map`, pero su cuarta parada —conciliación— no tiene acento propio: usa el gris de `.stop-next`.

### Semánticos

| Token | Claro | Oscuro | Uso |
| --- | --- | --- | --- |
| `--color-danger` | `#b42318` | `#f97066` | Salida de dinero, error |
| `--color-danger-strong` | `#912018` | `#fda29b` | Texto de error sobre `danger-soft` |
| `--color-danger-soft` | `rgb(240 68 56 / .10)` | `rgb(240 68 56 / .14)` | Fondo de alerta de error |
| `--color-warn` | `#93370d` | `#fec84b` | Pendiente |
| `--color-warn-soft` | `rgb(247 144 9 / .18)` | `rgb(247 144 9 / .15)` | Fondo de pendiente |
| `--color-ok` | `#067647` | `#47cd89` | Entrada de dinero, cobrado |
| `--color-ok-strong` | `#054f31` | `#75e0a7` | Texto de éxito sobre `ok-soft` |
| `--color-ok-soft` | `rgb(18 183 106 / .14)` | `rgb(18 183 106 / .15)` | Fondo de éxito |
| `--color-info` | `#3538cd` | `#a4bcfd` | Sin conciliar, aviso informativo |
| `--color-info-soft` | `rgb(99 102 241 / .14)` | `rgb(99 102 241 / .20)` | Fondo informativo |
| `--color-neutral-text` | `#475467` | `#cbd2dd` | Texto de exención y estados neutros |
| `--color-neutral-soft` | `ink` al 8 % | `ink` al 8 % | Fondo de estado neutro |

### Estado

| Token | Claro | Oscuro | Uso |
| --- | --- | --- | --- |
| `--color-skeleton` | `ink` al 12 % | `ink` al 12 % | Bloques de carga |
| `--color-overlay` | `rgb(15 23 42 / .45)` | `rgb(0 0 0 / .6)` | Velo de diálogo |
| `--topbar-bg` | `rgb(242 245 249 / .82)` | `rgb(32 38 52 / .78)` | Barra superior translúcida |

Mezclas derivadas que el propio `app.css` usa y que este sistema admite en lugar de nuevos colores: `ink` al 3 %, 6 %, 7 %, 8 %, 9 %, 10 %, 12 % y 18 % sobre `transparent` o sobre una superficie, y el acento `--line` al 9 %, 12 %, 16 %, 28 %, 30 %, 35 %, 38 % y 55 %.

## Tipografía

- Familia única: `--font-sans: 'Inter Variable', system-ui, -apple-system, 'Segoe UI', sans-serif` (`app.css:20`). Se sirve localmente con `@fontsource-variable/inter`; sin CDN.
- La jerarquía se construye con tamaño, peso y espacio, no con cajas (`app.css:6-18`).
- Cifras siempre tabulares: `font-variant-numeric: tabular-nums` en `body` y en cada cifra monetaria.
- Papeles fijados en `@layer base` y en los componentes: `h1` 1.75 rem / 1.2 / 650 / −0.025 em; `h2` 1.25 rem / 1.3 / 600 / −0.015 em; `h3` 1 rem / 1.4 / 600; cuerpo 0.9375 rem / 1.6; `label` 0.875 rem / 550; portada `.hero h1` `clamp(2.25rem, 6.5vw, 3.75rem)` / 1.04 / 600 / −0.04 em con 16 ch de medida; `.hero .lead` 1.125 rem / 1.55 en `muted`; `.kpis .n` 1.75 rem / 680 y su variante `.hero` 2.5 rem / 1.05; `.kpis .k` y `.section-label` 0.6875 rem con versalitas y 0.12–0.14 em de tracking.
- Números con ancho fijo, no fuente distinta: no hay token propio de cifras.

## Espacio, radio, sombra y movimiento

- Espacio: escala de Tailwind 4 (base 4 px). El proyecto no define una escala propia y el sistema no añade una paralela.
- Radio (`app.css:52-56`): `--radius-sm: 8px`, `--radius-md: 12px`, `--radius-lg: 16px`, `--radius-pill: 999px`. Nombres semánticos, no por píxel.
  - Deuda conocida: varios componentes antiguos siguen con radios literales (`0.375rem`, `0.4375rem`, `0.5rem`, `0.625rem`, `0.75rem`, `1rem`) en lugar de los tokens. Se conservan como están; un componente nuevo usa los tokens.
- Movimiento (`app.css:58-62`): `--dur-fast: 120ms`, `--dur-base: 200ms`, `--dur-slow: 320ms`, `--ease-standard: cubic-bezier(0.2, 0, 0, 1)` y `--ease-out: cubic-bezier(0.23, 1, 0.32, 1)`.
- Sombras (`app.css:66-73`): `--shadow-ring` (anillo de 1 px más sombra mínima) para superficies apoyadas; `--shadow-lift` (anillo más elevación) para elementos flotantes, como `.window` o `.guest-narrow`.
- Reducción de movimiento: `@layer base` fuerza `transition-duration` y `animation-duration` a `0.01ms` bajo `prefers-reduced-motion: reduce`. La entrada de `.kpis` y `.next` solo existe dentro de `@media (prefers-reduced-motion: no-preference)`.

## Reglas de componente

### Shell

- Barra superior pegajosa, translúcida, con desenfoque y una línea de 1 px debajo (`.topbar`); marca `Nexo` con dos círculos unidos por una línea.
- Enlace «Saltar al contenido» que aparece al enfocar (`.skip-link`), foco visible global `:focus-visible` con `2px solid var(--line)` y 2 px de separación.
- Menú lateral desde 48 rem, fila con desplazamiento horizontal por debajo; cada parada lleva un punto del color de su sección y `aria-current="page"` en la activa.
- Tema: `color-scheme` global `light dark`; `data-theme` en `<html>` lo fija. Oscuro por defecto cuando el sistema no expresa preferencia.

### Portada (`.field`, `.hero`, `.window`, `.map`)

- `.field` crea el campo: rejilla de puntos de `ink` al 18 % cada 22 px, con máscara radial, detrás del contenido (`z-index: -1`).
- `.hero h1` a `clamp(2.25rem, 6.5vw, 3.75rem)`, medida de 16 ch; `.hero .lead` en `muted`; botón `.btn-pill` de 2.75 rem de alto y radio de píldora.
- `.window` es la aplicación como objeto: superficie `surface`, radio 1 rem, `--shadow-lift` más un halo del acento hacia abajo. Dentro, `.window-bar` con título y `badge-partial`, y filas `.sample` de tres columnas (fecha 5.75 rem, concepto elástico, importe a la derecha).
- `.map` es la ruta del dinero: cuatro paradas separadas por una línea de 2 px del acento de su sección; la última (`.stop-next`) en gris discontinuo y con el texto en `muted`, porque todavía no ocurre.

### Panel (`.page-head`, `.kpis`, `.next`)

- `.page-head` alinea título y metadatos en una fila que envuelve; el meta se va a la derecha y en pantallas estrechas vuelve al flujo.
- `.kpis` es una banda, no una rejilla de tarjetas: celdas de `surface` separadas por 1 px de `ink` al 8 %, una sola máscara redondeada y `--shadow-ring`. Columnas: 1 desde 40 rem, 2 desde 56 rem con proporciones `1.35fr 1fr 1fr 0.85fr`.
- `.next` es una lista de filas enlazadas con flecha que se desplaza 2 px al pasar el ratón; nunca varias acciones primarias compitiendo.
- Entrada: una sola transición de `opacity` y `translate` de 0.375 rem con `@starting-style`, solo con `no-preference`.

### Conciliación (`.reconcile`)

- El formulario ocupa la fila completa de la lista de movimientos (`grid-column: 1 / -1`), separado por una línea de `ink` al 7 % y con los campos a 30 rem de ancho máximo. La etiqueta del movimiento vinculado queda en `muted` a 0.8125 rem.

### Registros y estados

- Superficies separadas por anillos translúcidos, no por bordes sólidos (`.entry`, `.board`, `.tally`, `.link-grid`).
- Color reservado al significado: el acento de sección solo en enlace, botón primario y punto del menú; `ok`/`danger` solo en importes y estados.
- Estado siempre con palabra además del color (`.badge` con punto y texto: pendiente, parcial, pagado, exento).
- Importes alineados a la derecha y con `tabular-nums` (`.movement dd.amount`, `.sample b`).
- Un resplandor suave del acento ilumina la parte superior de cada página (`body` con `background-image` radial).

## Contraste medido (AA)

Ratios calculados sobre los valores declarados en `app.css`. Texto normal necesita 4.5:1; texto grande (≥24 px, o ≥18.66 px en negrita) y elementos no textuales de control, 3:1.

| Par | Claro | Oscuro |
| --- | --- | --- |
| `ink` sobre `canvas` | 12.6:1 | 13.1:1 |
| `muted` sobre `canvas` | 5.7:1 | 7.2:1 |
| `muted` sobre `surface` | 6.7:1 | 6.2:1 |
| `line-panel` sobre `canvas` | 5.2:1 | 6.0:1 |
| `line-saldos` sobre `canvas` | 6.1:1 | 8.3:1 |
| `line-socios` sobre `canvas` | **4.2:1** | 8.1:1 |
| `line-cuotas` sobre `canvas` | **3.9:1** | 9.1:1 |
| `line-movimientos` sobre `canvas` | 5.5:1 | 6.6:1 |
| `line-socios` sobre `surface` | 5.0:1 | 7.0:1 |
| `line-cuotas` sobre `surface` | 4.6:1 | 7.8:1 |
| `line-movimientos` sobre `surface` | 6.5:1 | 5.7:1 |
| `ok` sobre `canvas` | **4.4:1** | 7.5:1 |
| `warn` sobre `canvas` | 5.8:1 | 9.8:1 |
| `danger` sobre `canvas` | 5.1:1 | 5.4:1 |
| `info` sobre `canvas` | 6.3:1 | 8.1:1 |
| `on-accent` sobre `line-panel` | 6.6:1 | 6.9:1 |

En negrita los tres pares que no llegan a 4.5:1 en tema claro. Regla que se deriva: en claro, `line-cuotas`, `line-socios` y `ok` no se usan como texto pequeño sobre `canvas`; se usan como texto grande, sobre `surface` (`cuotas` y `socios` sí cumplen ahí), o con un punto y una etiqueta en `ink`/`muted` al lado.

## Lo que el sistema todavía no fija

- Duración de esqueletos y de transiciones entre páginas: se apoyan en los tokens de movimiento ya añadidos, sin valores nuevos por componente.
- Densidad de tabla (alta o cómoda) como token: se decide con utilidades de espacio hasta que una pantalla real lo exija.
- Un acento para la conciliación: la portada la dibuja en gris discontinuo y `info` solo para su estado; si el flujo gana una pantalla propia, habrá que decidir su acento allí.
- Escena 3D de la portada: no hay todavía token de escena (profundidad, cámara, duración de bucle). Cualquier valor nuevo se añade a este documento al implementarse, no antes.
