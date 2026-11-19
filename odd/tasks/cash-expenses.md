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
- T3b [S1,S4] Registrar el saldo inicial con permisos y sumarlo al saldo de caja; verificada, commit que registra L6.
- T4 [S2–S4] Implementar gastos editables en borrador, confirmación y anulación con historial; cubrir permisos y saldos con pruebas; pendiente, sin commit.
- T5 [S1–S5] Integrar prueba de navegador, documentación y verificación de entrega; pendiente, sin commit.

## Log

L1. «de caja y gastoq ue vamos hacer?»
L2. «vale dale» en respuesta a la propuesta de S1–S5. Gastos bancarios y traspasos no entran en esta entrega.
L3. El explorador ubicó `Payment`, `VoidPayment`, `BuildBalances`, `AccessGates` y las pruebas de saldos. El saldo de caja debe consultar pagos de cuota activos de método efectivo, no copiarlos a la tabla de gastos. El saldo bancario sigue saliendo del extracto importado. Separar los cambios por unidad de comportamiento y añadir una prueba real de navegador para el flujo de dinero y permisos.
L4. «si dale» autorizó comprobar duplicados y abrir la issue pública en el repositorio indicado con la cuenta acordada. Búsqueda en abiertas y cerradas sin duplicado exacto; #62 abierta con `enhancement` y cuerpo comprobado por lectura posterior.
L5. T3a: RED 6 fallos de 15 por ausencia de caja; GREEN 15/65 en SummaryTest y 241/1801 en PHPUnit completo; Pint, PHPStan y `git diff --check` limpios. RDD: riesgo medio, revisión no exigida por presupuesto. No se ejecutó e2e en este corte; corresponde al flujo completo. Caja incluye solo cuotas vigentes en efectivo; aún no incluye saldo inicial ni gastos.
L6. T3b: RED 3 errores y 6 fallos; GREEN 9/67 en CashOpeningBalanceTest y 250/1868 en PHPUnit completo; Pint, PHPStan, migraciones (aplicación, reversión y reaplicación) y `git diff --check` limpios. RDD: riesgo medio, revisión no exigida. La base es una sola fila con índice único en SQLite; repetir el mismo importe es idempotente y un importe distinto se rechaza sin sobrescribir. Unidad de ~592 líneas, por encima del corte de 400: se informa sin recortes y la PR única de la issue mantiene la carga completa de revisión. La entrada de menú de /caja queda pendiente para T4, que incluye el diseño.
