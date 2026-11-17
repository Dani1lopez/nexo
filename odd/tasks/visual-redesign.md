# Nexo — subir la interfaz de nivel

Objetivo: llevar la aplicación a una presencia de producto grande —carácter visual fuerte, movimiento con intención y CSS cuidado— manteniendo la calma, la claridad de uso y un peso mínimo.
Rama: pendiente de abrir cuando se implemente | Issue: pendiente de crear antes del primer cambio de código | Direcciones visuales: `design/nexo-visual-v2/` | Sistema: `design/system.md`

## Specs

S1. User request: «lo quieor es que investigues y crees una ruta y rodamdp de mejroar la pagian a otro nievel para que sea una pagina de una empresa grande con animaciones y css mejorado diseño brutla pero eso sencilla de usar para el susuario y que no pese».
S2. Lectura del brief: diseño con carácter (no plantilla genérica), animaciones reales pero no decorativas, CSS mejorado sobre lo que ya existe, y facilidad de uso por encima del efecto.
S3. Restricción de peso: el JavaScript propio sigue siendo mínimo y sin frameworks; nada de librerías de UI ni de animación; fuentes ya servidas en local. El presupuesto se fija en la Fase 6 y se mide, no se estima.
S4. Reutilizar, no reemplazar: los tokens de `resources/css/app.css` (color con `light-dark()`, acento por sección, `--ease-out`, sombras de anillo) siguen siendo la base; toda dirección nueva añade tokens, no valores sueltos.
S5. Accesibilidad no negociable: contraste WCAG AA, foco visible, navegación completa por teclado, `prefers-reduced-motion` respetado en todo movimiento, y estado para cada acción (vacío, carga, error, éxito).
S6. Fuera de alcance: cambios de flujo de negocio, nuevas operaciones económicas, rediseño de la conciliación más allá de su presentación y cualquier dependencia npm de UI o animación.

## Direcciones visuales

`design/nexo-visual-v2/` contiene tres estructuras distintas, no tres paletas:

| Archivo | Dirección | Compromiso |
| --- | --- | --- |
| `01-panel-de-mando.html` | Panel denso con raíl, barra pegajosa y banda de indicadores | Máximo contexto; riesgo de parecer un cuadro de mandos genérico |
| `02-hoja-de-cuentas.html` | Hoja editorial de una columna con cifras enormes | Fuerza y lectura clarísima; menos datos a la vez |
| `03-espacio-partido.html` | Espacio partido: cola a la izquierda, trabajo a la derecha, detalle deslizante | Encaja con conciliar y con teclado; lo más exigente en móvil |
| `04-sintesis-conciliacion.html` | Síntesis: conciliación con cuadre a cero, resumen de importación, pestañas con contador, chips y confianza por sugerencia | Concentra mucha información en una pantalla; el detalle va en la fila, no en un panel |
| `05-socias.html` | Lista de socias con pestañas, chips, selección por lotes y los dos vacíos distintos | Densa y potente; exige mantener contadores y filtro coherentes con la URL |
| `06-movil-y-estados.html` | Reflujo en 390 px más los estados de carga, error, datos antiguos y cero frente a sin datos | Prueba lo que suele olvidarse; se revisa junto a la 04 |

El análisis que justifica cada patrón está en `design/nexo-visual-v2/benchmark.md`: ocho productos comparables, seis patrones adoptables con su coste real sin framework, y lo que se descarta de forma explícita.

La recomendación inicial es **tomar la estructura de 03 para el trabajo de conciliación y la de 02 para el panel de inicio**, porque separan «consultar» de «operar» sin inventar una tercera gramática. La dirección `04` muestra el resultado de mezclar esa estructura con los patrones del benchmark y es la que conviene revisar primero: demuestra el cuadre a cero, el resumen por resultado, las pestañas con contador y la confianza por sugerencia sin añadir peso.

## Tasks

## Tasks

- P0 [S2,S5] Panel (issue #27). Composición nueva de `dashboard.blade.php` y componentes en `app.css` que consumen los tokens de #26. Criterio: cifras tabulares, estado con texto, foco visible, movimiento reducido respetado, pruebas HTTP y e2e ajustadas al contrato nuevo. Evidencia: capturas en navegador real, `composer ci` y `npm run e2e`.
- P1 [S2,S4] Tokens que faltan. **Hecho (issue #26):** radio (`--radius-sm/md/lg/pill`), movimiento (`--dur-fast/base/slow`, `--ease-standard`) y estado (`--color-skeleton`, `--color-overlay`) añadidos al bloque `@theme`, sin cambiar ningún valor previo. La incorporación es aditiva: cada componente adoptará los tokens cuando se toque en las fases 2 a 5, y ninguna fase puede introducir un valor suelto de radio o duración. Evidencia: `design/system.md` actualizado y `composer ci` en verde.
- P2 [S2,S5] Tipografía y jerarquía. Escala tipográfica fluida con `clamp()`, cifras tabulares en todo importe, versalitas coherentes para etiquetas. Criterio: la jerarquía se lee sin cajas; contraste AA verificado en ambos temas.
- P3 [S2,S5] Shell y navegación. Raíl con estado activo por sección, barra pegajosa, paleta de comandos con `⌘K` como atajo, migas y estados de foco completos. Criterio: todo alcanzable con teclado; sin trampas de foco.
- P4 [S2,S5] Datos densos. Tablas y listas con densidad ajustable, filtros persistentes en la URL, estados vacío/carga/error y acciones por fila accesibles. Criterio: 500 filas sin bloqueo perceptible; el filtro sobrevive a recargar.
- P5 [S2,S3,S5] Movimiento con intención. Entrada de página con `@starting-style`, transiciones entre vistas con la API View Transitions y reserva para navegadores sin ella, realce del elemento seleccionado, apertura del panel de detalle. Nada se mueve sin motivo; todo respeta `prefers-reduced-motion`. Criterio: sin saltos de diseño, sin animar propiedades que provoquen repintados caros (`width`, `top`), y comportamiento correcto con movimiento reducido.
- P6 [S3] Presupuesto de rendimiento. **Medido (issue #26):** `scripts/check-weight.mjs` informa el peso real y falla por encima del límite. Línea base: CSS **7,94 KB gzip** y **38,07 KB sin comprimir**; JavaScript propio **0 KB**. Presupuesto: **CSS ≤ 12 KB gzip y ≤ 48 KB sin comprimir**, **JS ≤ 12 KB gzip**. Fuentes: 213 KB en 7 subconjuntos, informativo, porque `unicode-range` hace que solo se descargue el que se usa. Pendiente: cablearlo en CI, que es riesgo de entrega y va como cambio aparte. Criterio: la comprobación falla el build cuando se supera; verificado forzando el límite a 1 KB (salida 1).
- P7 [S5] Revisión de accesibilidad y estados. Recorrido completo con teclado, lector de pantalla en las tres pantallas principales, contraste en ambos temas y estados de error de formularios. Criterio: ningún control inalcanzable ni sin nombre accesible.
- P8 [S2,S3] Patrones del benchmark. Cuadre a diferencia cero visible durante toda la conciliación; resumen por resultado tras importar; pestañas de estado con contador; filtros en la URL con chips y «limpiar» condicional; confianza por sugerencia; detalle en la fila con `details`. Criterio: cada patrón funciona sin JavaScript, salvo la hoja de atajos, y está cubierto por pruebas de HTTP. Evidencia: `design/nexo-visual-v2/04-sintesis-conciliacion.html` y `benchmark.md`.

## Orden recomendado

P6 primero como línea base medida, luego P1 y P2 (baratos, alto impacto), después P3 y P4, y P5 al final, cuando la estructura ya no cambie. P7 cierra cada fase, no solo el final.

## Entrega

Rama `feat/26-visual-foundation`, issue #26. Límites de rebanada, para que el revisor no reciba una sorpresa:

| Rebanada | Commit | Contenido |
| --- | --- | --- |
| 1 | `687f372` | Documentación de diseño: sincronización del sistema, cuatro direcciones, benchmark y este documento (1.693 líneas, casi todas HTML de tableros) |
| 2 | `be23658` | Fundamento real: tokens aditivos en `app.css`, `scripts/check-weight.mjs` y script npm (207 líneas) |

El código de producción de esta entrega son unas 130 líneas; el volumen de la rebanada 1 son tableros de diseño, no código que se ejecute. Si el revisor prefiere separarlo, la rebanada 2 se puede llevar a su propia PR sin tocar la otra, porque los tableros no dependen de los tokens nuevos.

## Log

L1. User request: «si dale y despues de eso lo quieor es que investigues y crees una ruta y rodamdp de mejroar la pagian a otro nievel para que sea una pagina de una empresa grande con animaciones y css mejorado diseño brutla pero eso sencilla de usar para el susuario y que no pese». «si dale» se refiere a publicar la rama de la issue #9 y abrir su PR, hecho como PR #25.
L2. Sincronización del sistema de diseño desde `resources/css/app.css`, `resources/views/layouts/app.blade.php` y `package.json`: `design/system.md` y `design/system.html`. No se inventó ningún valor; lo que el sistema no fija (radio, duración, espacio propio) queda declarado como pendiente en P1.
L3. Direcciones escritas en `design/nexo-visual-v2/`: `01-panel-de-mando.html`, `02-hoja-de-cuentas.html` y `03-espacio-partido.html`, más `design.json`. Son alternativas entre sí; ninguna está elegida todavía.
L4. Investigación de restricciones: la aplicación usa Tailwind 4 con `@theme` y CSS propio, Inter servida localmente con `@fontsource-variable/inter`, un único fragmento de JavaScript para el tema y sin framework de interfaz. Por eso el movimiento se plantea con CSS y la API nativa de transiciones, y el presupuesto de P6 se mide con herramientas que ya están en el proyecto.
L5. Benchmark escrito en `design/nexo-visual-v2/benchmark.md` con dos investigaciones: ocho productos comparables de gestión de socios y tesorería (CiviBanking, PKarnet, Sokare, Aplos, CLUNIQ, easyVerein, Clubworx, AssoConnect) y la artesanía documentada de dashboards financieros (Stripe, Moneybird, Wave, Xero, Linear, Notion Calendar). Se separa lo documentado de lo que solo vimos en páginas comerciales; no se inventan duraciones, escalas ni motivos de animación ajenos.
L6. Soportes de navegador comprobados para el movimiento: transiciones entre documentos disponibles en el 87,7 % de uso global y Baseline desde 2025-10-14; `@starting-style` en el 93,4 % y Baseline desde 2024. Ambas degradan sin romper, así que el movimiento no exige JavaScript.
L7. Cuarta dirección añadida: `design/nexo-visual-v2/04-sintesis-conciliacion.html`, que mezcla la estructura de 03 con los patrones con fuente del benchmark. Es una alternativa más, no una dirección elegida. Se descarta el panel deslizante como patrón copiado porque no apareció en ninguna fuente pública de esos productos: si se usa, es decisión propia y con foco atrapado.
L8. Segunda ronda de mejora, a partir de la guía de composición y de interfaces adaptables: `05-socias.html` cubre la pantalla que faltaba (pestañas con contador, chips, selección por lotes con total pendiente y los dos vacíos que no son lo mismo: colección vacía frente a búsqueda sin resultados). `06-movil-y-estados.html` demuestra el reflujo en 390 px, el botón de cerrar mes desactivado con su motivo visible, la carga con esqueleto, el error que dice si se guardó algo, los datos con antigüedad declarada y la diferencia entre un cero real y un dato que no existe. En `04` se añadió el conmutador de tema para revisar ambos, cabecera de tabla fija, entrada con `@starting-style` y la marca de actualización.
L9. Criterios de la guía aplicados de forma explícita: ninguna cifra se compara mal (cifras tabulares y alineación a la derecha), el color nunca va solo (toda etiqueta de estado lleva texto), el foco es visible y distinto de la selección, los objetivos táctiles son de 40-48 px en móvil, y cada movimiento respeta `prefers-reduced-motion` sin perder información.
L10. User decision: «valae eligo eso», eligiendo la dirección `04-sintesis-conciliacion.html` y su versión móvil `06-movil-y-estados.html` como línea a implementar. A partir de aquí, las demás direcciones quedan como alternativas documentadas, no como candidatas.
L11. Issue #26 creada (convención del repositorio: issue antes del primer cambio de código) y rama `feat/26-visual-foundation` desde `main` en `51dbfae`. Implementado lo de P1 y la medición de P6: tokens aditivos en `resources/css/app.css`; `scripts/check-weight.mjs` con script `check:weight` en `package.json`; `design/system.md` actualizado. Línea base real: CSS 7,94 KB gzip / 38,07 KB sin comprimir, JS propio 0 KB. El presupuesto se fija sobre gzip porque es lo que se descarga; el tamaño sin comprimir queda como segundo tope. La comprobación se probó en rojo forzando el límite a 1 KB (salida 1) y en verde con el presupuesto real (salida 0).
L12. Issue #27 y rama `feat/27-visual-panel`: primera aplicación visible de la dirección elegida. `dashboard.blade.php` pasa a cabecera de página, banda de indicadores con cuatro cifras (pendiente como cifra principal, cobrado con barra de progreso derivada, socios activos, movimientos), «Siguientes pasos» como filas accionables y el acceso mostrado con su capacidad («Tesorería, con edición» / «Junta, solo consulta»). En `app.css` se añadieron componentes que consumen los tokens de #26 (radio y duración), con entrada vía `@starting-style` desactivada por `prefers-reduced-motion`. Verificación real, no supuesta: capturas en navegador con sesión iniciada en tema claro, oscuro y 390 px; `composer ci` en verde tras ajustar tres pruebas HTTP que afirmaban el texto anterior; `npm run e2e` 15/15 tras ajustar dos aserciones de rol exacto que el panel ya no imprime así; peso dentro del presupuesto (8,64 KB gzip de CSS).
