# Nexo — caja y gastos en efectivo
Objetivo: incorporar el efectivo de la asociación sin modificar el saldo bancario ni duplicar pagos de cuotas.
Issue: [#62 — Caja y gastos en efectivo](https://github.com/Dani1lopez/nexo/issues/62) | Rama local: `feat/62-cash-expenses`.

## Specs

Alcance de la propuesta aceptada por el usuario en L2:

S1. «Caja: fijar un saldo inicial y aprovechar los pagos de cuotas en efectivo que Nexo ya registra, sin tener que introducirlos dos veces.»
S2. «Gastos en efectivo: registrar fecha, importe, categoría y concepto. Si no hay justificante, el motivo es obligatorio. Sin subir archivos en esta versión.»
S3. «Historial y permisos: tesorería registra y corrige; junta solo consulta. Los borradores se pueden editar. Un gasto confirmado se corrige anulándolo con motivo y registrando uno nuevo, sin borrar el original.»
S4. «Saldos: mostrar banco y caja por separado. Por ejemplo, si hay 80 € en caja y se pagan 25 € de material, la caja baja a 55 € y el banco no cambia.»
S5. «Después: clasificar los gastos bancarios a partir de los cargos ya importados, sin descontarlos dos veces, y registrar traspasos banco–caja sin contarlos como ingresos ni gastos.»

## Tasks

- T1 [S1–S5] Mapear pagos, saldos y permisos; exploración completada, sin commit de código.
- T2 [S1–S5] Buscar duplicados y abrir la issue en GitHub; completada: #62 abierta y verificada; sin commit.
- T3a [S1,S4] Derivar las cuotas en efectivo y mostrarlas en un saldo de caja separado; verificada, commit `6e6b7a1`.
- T3b [S1,S4] Registrar el saldo inicial con permisos y sumarlo al saldo de caja; verificada, commit `239366b`.
- T4 [S2–S4] Implementar gastos editables en borrador, confirmación y anulación con historial; cubrir permisos y saldos con pruebas; implementada en línea, commit que registra L8.
- T5 [S1–S5] Integrar prueba de navegador, documentación y verificación de entrega; e2e 32/32 en verde; revisión nativa terminada en alto del proveedor (validador rechazó la primera corrección); la regresión que detectó quedó corregida como trabajo normal; sin commit aún.

## Log

L1. «de caja y gastoq ue vamos hacer?»
L2. «vale dale» en respuesta a la propuesta de S1–S5. Gastos bancarios y traspasos no entran en esta entrega.
L3. El explorador ubicó `Payment`, `VoidPayment`, `BuildBalances`, `AccessGates` y las pruebas de saldos. El saldo de caja debe consultar pagos de cuota activos de método efectivo, no copiarlos a la tabla de gastos. El saldo bancario sigue saliendo del extracto importado. Separar los cambios por unidad de comportamiento y añadir una prueba real de navegador para el flujo de dinero y permisos.
L4. «si dale» autorizó comprobar duplicados y abrir la issue pública en el repositorio indicado con la cuenta acordada. Búsqueda en abiertas y cerradas sin duplicado exacto; #62 abierta con `enhancement` y cuerpo comprobado por lectura posterior.
L5. T3a: RED 6 fallos de 15 por ausencia de caja; GREEN 15/65 en SummaryTest y 241/1801 en PHPUnit completo; Pint, PHPStan y `git diff --check` limpios. RDD: riesgo medio, revisión no exigida por presupuesto. No se ejecutó e2e en este corte; corresponde al flujo completo. Caja incluye solo cuotas vigentes en efectivo; aún no incluye saldo inicial ni gastos.
L6. T3b: RED 3 errores y 6 fallos; GREEN 9/67 en CashOpeningBalanceTest y 250/1868 en PHPUnit completo; Pint, PHPStan, migraciones (aplicación, reversión y reaplicación) y `git diff --check` limpios. RDD: riesgo medio, revisión no exigida. La base es una sola fila con índice único en SQLite; repetir el mismo importe es idempotente y un importe distinto se rechaza sin sobrescribir. Unidad de ~592 líneas, por encima del corte de 400: se informa sin recortes y la PR única de la issue mantiene la carga completa de revisión. La entrada de menú de /caja queda pendiente para T4, que incluye el diseño.
L7. Estrategia de entrega: `CONTRIBUTING.md` fija una única PR por issue hacia `main`, sin cadenas ni etiquetas de excepción, así que la entrega es una PR con commits por unidad de comportamiento y la carga de revisión se informa completa. Corte actual: `6e6b7a1` (T3a, 181 líneas) y `239366b` (T3b, 595 líneas); T4 abre un corte nuevo.
L8. T4 implementada en línea con desarrollo test-first. RED: 9 pruebas, 9 fallos (rutas inexistentes); dos correcciones de implementación (regla `required_unless` en lugar de `Rule::requiredUnless` y aserciones sin formulario hechas sobre textos de botones, porque el cierre de sesión del diseño es un formulario visible para junta). GREEN: CashExpenseTest 9/75 y suite completa 259/1943; Pint corrigió dos importaciones sin uso y quedó limpio; PHPStan limpio; `check-migrations.sh` aplica, revierte y reaplica la 000014. Añadida la prueba e2e `cash-expenses.spec.ts` (flujo tesorería y consulta de junta) y la entrada de menú Caja con acento lima propio; su ejecución completa corresponde a T5.
L9. Revisión nativa (riesgo, resiliabilidad, fiabilidad + refutador) sobre el diff de los tres commits: estado `correction_required` con siete hallazgos consolidables en tres defectos. Corrección acotada (RED observado primero en las dos reglas nuevas): 1) `UpdateCashExpense` relee dentro de transacción y su escritura es condicional a que la fila siga en borrador, así una confirmación solapada ya no se puede sobrescribir (R1-001/R3-001/R4-001); 2) `RegisterCashExpense` solo trata como reintento el reenvío idéntico y rechaza una clave reutilizada con datos distintos (R1-002/R3-002/R4-002), igual que el patrón corregido en #45; 3) `BuildCashBalance` lee el saldo inicial una vez y entrega la fila, y el controlador deja de releerla (R3-003). Verificación: 261/1960 en PHPUnit, Pint y PHPStan limpios, 167 líneas de diff dentro del presupuesto de 200. E2E completo 32/32 con el peso dentro de presupuesto. Commit de la corrección: `0072078`.
L10. El validador dirigido rechazó la corrección y la autoridad terminó en alto del proveedor (`native_stop_required`), sin continuación ofrecida: no se reabre esa revisión. Sus dos observaciones eran reales y se corrigieron como trabajo normal de la rama: 1) REG-001: la comparación del reenvío se hacía contra la fila editable, así el reenvío exacto del formulario original fallaba tras una edición legítima del borrador; ahora la fila recuerda la huella del envío original (migración 000015, `submission_fingerprint`) y el reenvío idéntico sigue siendo un reintento aunque el borrador se haya editado; 2) la prueba de edición solapada ahora comprueba también que el saldo de caja queda intacto (−25,00 € y no −30,00 €). RED observado antes de arreglar: el reenvío exacto redirigía a la raíz con error de validación.
