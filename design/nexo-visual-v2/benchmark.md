# Benchmark · qué copiar y qué no

Análisis de productos parecidos a Nexo y de la artesanía visual de dashboards financieros modernos. Todo lo que aparece aquí tiene una URL de origen; cuando la evidencia era solo la página comercial, se dice.

Regla de trabajo: **se copian patrones y decisiones, no interfaces ni recursos ajenos.** Un patrón es «importar → sugerir → confirmar». Una interfaz es el HTML, los iconos y las ilustraciones de otro producto.

---

## Parte 1 · Gestión de socios y tesorería

Se cribaron unas 25 herramientas y se conservaron 8 realmente comparables: cuotas, remesas y conciliación de extractos.

### CiviBanking (extensión de CiviCRM) · EN · global, código abierto

[https://docs.civicrm.org/banking/en/latest/user-guide/](https://docs.civicrm.org/banking/en/latest/user-guide/)

- Lista de extractos con contador por estado: nuevos, analizados, procesados, ignorados.
- Revisión por movimiento con datos básicos arriba y detalles técnicos plegados.
- Flujo importar → analizar → emparejar → confirmar, con **porcentaje de confianza por sugerencia**; solo lo inequívoco se procesa solo.
- **Copiar:** el porcentaje de confianza y el plegado de detalles técnicos.
- **No copiar:** la ceremonia de plugins analizadores y emparejadores.

### PKarnet · ES · España

[https://pkarnet.com/es/funcionalidades/cuotas-y-remesas-sepa](https://pkarnet.com/es/funcionalidades/cuotas-y-remesas-sepa)

- Cuatro pasos: definir cuotas → generar recibos → exportar remesa SEPA → importar extracto.
- Tras importar, el resultado se agrupa en **cobros, devoluciones y pendientes**.
- Importación de Norma 43, el formato que de verdad llega de los bancos españoles.
- **Copiar:** el resumen por resultado inmediatamente después de importar.
- **No copiar:** asientos contables automáticos sin paso visible de revisión; una devolución debe verse y poder deshacerse.

### Sokare · ES · asociaciones

[https://sokare.es/gestion-socios-asociaciones/](https://sokare.es/gestion-socios-asociaciones/)

- Una fila por socia con fecha de renovación, importe y estado de cobro.
- Ficha con tipo de cuota, fecha de alta, forma de pago e historial de renovaciones.
- Orden de configuración: primero los tipos de cuota, después asignar socias.
- **Copiar:** la fila por socia con renovación y estado juntos.
- **No copiar:** el discurso de venta «la realidad: Excel…» y la ausencia de conciliación.

### Aplos · EN · entidades sin ánimo de lucro (EE. UU.)

[https://help.aplos.com/hc/en-us/articles/30678413692813-Bank-Reconciliation-Overview](https://help.aplos.com/hc/en-us/articles/30678413692813-Bank-Reconciliation-Overview)

- Se elige el libro, se introduce la fecha de cierre y el **saldo del extracto**, se marcan partidas y solo se cierra cuando la diferencia es cero.
- **Copiar:** saldo del extracto y diferencia viva visibles arriba durante toda la conciliación; cierre bloqueado hasta llegar a cero.
- **No copiar:** la profundidad de contabilidad por fondos; es un programa de contabilidad, no de tesorería.

### CLUNIQ Manager · DE · clubes alemanes

[https://cluniq.de/manager/](https://cluniq.de/manager/)

- Resumen de socios, «Beitragslauf» (proceso de cuotas) que genera un fichero SEPA para subir tú al banco, e importación bancaria con sugerencias que confirma la persona.
- **No se guardan credenciales bancarias**: exportar y subir.
- **Copiar:** exportar-para-subir y sugerir-para-confirmar; además el texto de confianza («sin datos de pago, sin suscripción automática»).
- **No copiar:** tarifas por número de socias y funciones de nóminas y fichaje bloqueadas en el plan caro.

### easyVerein · DE · clubes

[https://easyverein.com/vereinssoftware/funktionen/](https://easyverein.com/vereinssoftware/funktionen/)

- Panel de unas 30 tarjetas configurables, con previsión de ingresos por cuotas a 12 meses y saldo bancario.
- Importación por FinTS, CAMT y CSV; facturas con enlace de pago y código QR.
- **Copiar:** la previsión de cuotas a 12 meses; el QR de pago en el recibo.
- **No copiar:** 30 tarjetas y 15 módulos (chat, inventario, salas, votaciones). Una portada de tesorería necesita cuatro tarjetas.

### Clubworx · EN · clubes (Reino Unido y EE. UU.)

[https://intercom.help/clubworx/en/articles/1380959-managing-payments-manually-in-clubworx](https://intercom.help/clubworx/en/articles/1380959-managing-payments-manually-in-clubworx)

- Lista de pagos filtrada por fecha, selección de cuotas y acción masiva «pagado (otro)».
- Sin importación de extractos.
- **Copiar:** el camino de respaldo cuando no hay remesa SEPA: filtrar por fecha y marcar en lote.
- **No copiar:** un estado comodín «pagado (otro)» que borra la forma de pago real.

### AssoConnect · FR · asociaciones

[https://assoconnect.com/fonctionnalites/adhesions/](https://assoconnect.com/fonctionnalites/adhesions/) *(solo página comercial; capturas de marketing)*

- Altas de socias con validar, editar o rechazar en un clic; historial por socia.
- Plan de pago a plazos configurable: número de pagos e intervalo.
- El estado de la factura se deriva del estado del pago.
- **Copiar:** estado derivado y plan de plazos definido en la campaña de cuota.
- **No copiar:** el reembolso en un clic que **borra el asiento**; en tesorería se registra una anulación, no se borra historia.

### Cribados y por qué

| Producto | Motivo para descartarlo |
| --- | --- |
| HelloAsso (FR) | Solo recaudación; el donativo opcional al pagador debe seguir siendo opcional |
| Wild Apricot (EN) | Los usuarios informan que los extractos no cuadran con las facturas; lección: guardar la fecha valor del banco |
| ClubExpress, Neon CRM, Bloomerang | CRM de socios o de donantes, no tesorería con importación |
| Memberful | Suscripciones de creadores con comisión del 4,9 % |
| TeamSnap, SportsEngine | Inscripción deportiva |
| Holded, Quipu, Declarando | Contabilidad o fiscalidad de autónomos; interfaz no verificada |
| easyVerein / Clubworx | Demasiado anchos para nuestro alcance, salvo los patrones citados |

### Cinco patrones que sí se transfieren

1. **Importar → sugerir → confirmar**, con confianza por sugerencia y solo lo inequívoco automático. [CiviBanking](https://docs.civicrm.org/banking/en/latest/user-guide/)
2. **Resumen por resultado tras importar**: cobros, devoluciones, pendientes. [PKarnet](https://pkarnet.com/es/funcionalidades/cuotas-y-remesas-sepa)
3. **Exportar el fichero SEPA y que lo suba la persona**, sin guardar credenciales bancarias. [CLUNIQ](https://cluniq.de/manager/)
4. **Conciliación que solo cierra a diferencia cero**, con el saldo del extracto visible arriba. [Aplos](https://help.aplos.com/hc/en-us/articles/30678413692813-Bank-Reconciliation-Overview)
5. **Una fila por socia con renovación y estado de cobro**; los tipos de cuota se configuran antes que las socias. [Sokare](https://sokare.es/gestion-socios-asociaciones/)

### Consecuencias para Nexo

- La conciliación debe mostrar el **saldo del extracto y la diferencia** en todo momento, y no cerrar el mes hasta que la diferencia sea cero.
- El resultado de importar necesita una pantalla de resumen por estado, no un mensaje fugaz.
- Hay que soportar **los dos caminos**: remesa SEPA exportada e importación de Norma 43, más el respaldo de marcado en lote por fecha.
- La anulación sustituye al borrado en todo lo que toque dinero e historial.
- Cuatro tarjetas en la portada, no treinta.

---

## Parte 2 · Artesanía visual de dashboards financieros

Se revisaron Linear, Stripe, Mercury, Ramp, Vercel, Height, Notion Calendar, Pennylane, Qonto, Moneybird, Xero y Wave. Aviso de honestidad: **casi ninguno publica su CSS, sus duraciones ni su escala tipográfica**. Lo que sigue separa lo documentado de lo que solo vimos en notas de producto o fragmentos de búsqueda, y no se inventa ninguna cifra.

### Lo que sí está documentado

| Tema | Hallazgo | Fuente |
| --- | --- | --- |
| Filtros | Chips sobre la tabla; «limpiar filtros» aparece solo cuando hay uno activo | [Stripe · filter controls](https://docs.stripe.com/stripe-apps/patterns/filter-controls) |
| Estado en la URL | Los filtros de Moneybird viven en la URL y se pueden guardar como marcador, pero no como vistas guardadas | [Moneybird](https://helpcenter.moneybird.nl/nl/articles/207115-filter-opslaan) |
| Pestañas de estado | Pestañas encima de la lista (borrador, impagada, todas) | [Wave](https://support.waveapps.com/hc/en-us/articles/4416930325396-Find-an-invoice) |
| Tablas | Tres modos de paginación, selección por lotes, acciones por fila y «x–y de z resultados» | [Stripe · DataTable](https://docs.stripe.com/stripe-apps/components/datatable) |
| Filtrar antes de paginar | La documentación de Stripe pide filtrar los datos antes de pasarlos, para que los totales cuadren | misma fuente |
| Cifras | Números tabulares y decimales alineados para datos financieros | [Xero · Xero Design Language](https://blog.xero.com/product-updates/xero-design-language/) |
| Atajos | `⌘K` paleta, `?` hoja de atajos, `/` para buscar | [Notion Calendar](https://www.notion.com/help/notion-calendar-keyboard-shortcuts) |
| Movimiento reducido | La recomendación es reducir o sustituir el movimiento, no apagarlo todo | [MDN · prefers-reduced-motion](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@media/prefers-reduced-motion) |
| Presupuesto | Ejemplo público: recursos de ruta crítica por debajo de 170 KB | [web.dev · performance budget](https://web.dev/articles/your-first-performance-budget) |

### Lo que no está documentado, y no vamos a fingir que sí

- **Duraciones y curvas concretas de estos productos.** Ninguna es pública. Nuestro `--ease-out` ya existe en el proyecto y las duraciones las propondremos como tokens en la Fase 1.
- **Escala tipográfica y contraste etiqueta/valor.** No hay especificación pública. Es una decisión nuestra, no una copia.
- **Lista con detalle deslizante.** No apareció en ninguna fuente pública de estos productos. Es una decisión propia, no un patrón copiado; si se adopta, se prueba con teclado y foco atrapado.
- **Esqueletos de carga frente a spinner.** Sin evidencia pública. Decisión propia.

### Seis patrones adoptables, con su coste real

| Patrón | Origen | Coste en Blade + Tailwind, sin framework |
| --- | --- | --- |
| Filtros como estado en la URL | Moneybird | Formulario GET, leer `request()->query()`, paginación que conserve la cadena. Cero JavaScript |
| Pestañas de estado con contador | Wave | Una consulta agrupada de conteos; cada pestaña es un `<a href="?status=…">` |
| Chips con estado sugerido/activo y «limpiar» condicional | Stripe | Un chip activo es un enlace que quita su parámetro. Cero JavaScript |
| Paginación en servidor con «x–y de z» | Stripe | Paginador estándar; cursor solo si alguna tabla crece mucho |
| Cifras tabulares en todo importe | Xero | Una clase en el componente Blade de importe |
| `?` hoja de atajos y `/` para buscar | Notion Calendar | Un `keydown` y un `<dialog>` estático. Unos cientos de bytes |

---

## Parte 3 · Plan de copia y modificación, pantalla por pantalla

No se copia ninguna interfaz: se copian las decisiones y se rehacen con los tokens del proyecto.

### Portada del panel

- **De easyVerein:** la previsión de cuotas a 12 meses. **Adaptación:** una sola tarjeta con barras mensuales y cifras tabulares.
- **De Argenta:** los totales encima de la lista, no debajo.
- **De easyVerein (lo que se evita):** treinta tarjetas. Nos quedamos con **cuatro**: saldo, cobrado, pendiente, sin conciliar.
- **Propio:** el saldo se separa en banco y caja, porque el proyecto ya distingue ambos.

### Socios

- **De Sokare:** una fila por socia con renovación y estado de cobro juntos.
- **De AssoConnect:** el estado del recibo derivado del estado del pago, nunca escrito a mano.
- **De Stripe:** «x–y de z» y acciones por fila.
- **Propio:** filtros de estado en la URL para que un enlace a «morosas» funcione al compartirlo.

### Cuotas

- **De AssoConnect:** plan de plazos definido en la campaña de cuota, no por socia.
- **De PKarnet:** exportación de remesa SEPA como paso explícito, con su resultado.
- **Propio:** la anulación conserva historial; nunca se borra un pago.

### Movimientos y conciliación

- **De CiviBanking:** porcentaje de confianza por sugerencia; solo lo inequívoco entra solo.
- **De PKarnet:** resumen por resultado tras importar (cobros, devoluciones, pendientes).
- **De Aplos:** saldo del extracto y diferencia vivos arriba; el cierre se bloquea hasta cero.
- **De CLUNIQ:** jamás credenciales bancarias; exportar y subir.
- **De Clubworx:** marcado en lote por fecha como camino de respaldo.

### Acceso y formularios

- **Propio:** un solo mensaje de error de acceso, ya implementado en la PR #25.
- **De Stripe:** el error junto al campo y el resumen arriba solo cuando hay varios.
- **De Wave:** pestañas para separar estados del mismo recurso.

### Móvil

- **De Linear:** el raíl desaparece en pantallas estrechas, no se convierte en cajón permanente.
- **Propio:** las tablas densas pasan a lista de dos columnas por fila, no a tabla con desplazamiento horizontal.

---

## Qué se descarta explícitamente

- Comisión sobre el dinero recaudado, como en Memberful o HelloAsso: Nexo no cobra al socio, la tesorería registra.
- Credenciales bancarias guardadas y conciliación automática total: se queda en sugerencia confirmada.
- Asientos contables automáticos sin revisión, como en PKarnet.
- Reembolso que borra el asiento, como en AssoConnect.
- Paneles de 30 tarjetas y 15 módulos, como en easyVerein.
- Cualquier librería npm de interfaz, iconos o animación: el peso manda.

