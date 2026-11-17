# Nexo — conciliación manual (primer paso)

Objetivo: enlazar un movimiento de un extracto con la cuota de un socio, confirmando el pago, y poder deshacerlo anulando sin borrar historial.
Branch: feat/28-reconcile | Issue: #28 | Runner: composer ci (PHPUnit + Pint + PHPStan)

## Specs

S1. User request: «vale ahora los siguientes pasos es continuar con las funcionaldiades».
S2. Issue #28, objetivo, verbatim: «que la tesorería pueda enlazar un movimiento de un extracto con la cuota de un socio, confirmando el pago, y que se pueda deshacer anulando, sin borrar historial».
S3. Reglas, verbatim: «Solo tesorería concilia (`edit-treasury`); junta solo consulta.» · «Solo se concilian abonos (importe positivo). Un cargo no genera un pago.» · «Un movimiento no se puede conciliar dos veces mientras el pago siga vigente.» · «Se reutilizan las guardas que ya existen para los pagos: no se paga una cuota exenta ni por encima de lo pendiente.» · «La operación es idempotente: dos envíos del mismo formulario no crean dos pagos.» · «Anular el pago libera el movimiento y vuelve a quedar conciliable. Nada se borra.» · «La confirmación es explícita: la aplicación propone, la persona confirma.»
S4. Fuera de alcance, verbatim: «Reparto de una transferencia entre varios socios, sobrantes por resolver, otros ingresos, caja y gastos. Quedan para issues posteriores.»

## Tasks

- T1 [S3] Esquema y relaciones: `payments.bank_movement_id` nulo con clave foránea; relación en `Payment` y `BankMovement::payments()/activePayment()`. commit: pending.
- T2 [S3] Regla de conciliación en `ReconcileMovement`, delegando las guardas de pago existentes en `RegisterPayment` (que ahora acepta el movimiento de origen). commit: pending.
- T3 [S3] Ruta y controlador `POST /bank/movements/{movement}/reconcile` con `can:edit-treasury`. commit: pending.
- T4 [S3] Pantalla: formulario por abono sin conciliar con las cuotas pendientes, y para los conciliados el pago enlazado con su anulación. commit: pending.

## Log

L1. User request: «vale ahora los siguientes pasos es continuar con las funcionaldiades». Se elige la conciliación manual porque el README la declara pendiente y es la pieza que la dirección visual elegida representa.
L2. Mapa del módulo (explorador, solo lectura): `bank_movements` guarda fecha, importe con signo, concepto y huella, sin estado ni referencia a cuotas; `payments` tiene `due_id`, importe, fecha, método, `submission_key` único y anulación con motivo; el estado de la cuota es calculado. No existía ninguna lógica de conciliación.
L3. Decisiones de diseño: el enlace vive en `payments.bank_movement_id`, de modo que un movimiento puede tener varios pagos en el futuro (reparto) pero solo uno vigente ahora; la idempotencia se resuelve devolviendo el pago vigente cuando el par movimiento-cuota se reenvía, y rechazando el salto a otra cuota; los cargos se rechazan antes de tocar pagos; las guardas de exención y exceso se reutilizan en lugar de duplicarse.
L4. Rojo observado primero en `tests/Feature/ReconcileTest.php`: 10 pruebas, 8 errores y 1 fallo antes de la ruta y la acción. Verde después: 10 pruebas, 39 aserciones. Las dos últimas aserciones se ajustaron a la clave real del error (`amount`) porque las guardas de exención y exceso vienen de `RegisterPayment`, no de la conciliación.
L5. Recorrido real en navegador: la conciliación funcionaba, pero al anular desde movimientos la aplicación redirigía a la pantalla de cuotas, así que no se veía el movimiento liberado. Corregido con una ruta propia del módulo bancario (`POST /bank/payments/{payment}/void`) que reutiliza `VoidPayment` y vuelve a `/bank`; la prueba del ciclo completo ahora usa esa ruta y comprueba el mensaje, la anulación y la nueva conciliación. Lección: el flujo se valida de punta a punta en el navegador, no solo por HTTP.
L6. Verificación independiente: las 7 reglas pasan, la suite queda en 108 pruebas / 879 aserciones y no hay doble conteo en los informes (los totales suman solo pagos vigentes y nunca leen `bank_movement_id`). Encontró un defecto real: la comprobación de «ya conciliado» ocurría antes de la transacción y cada envío generaba una clave nueva, así que dos envíos simultáneos podían crear dos pagos del mismo abono. Cerrado en la base de datos, no solo en PHP: índice único parcial `payments_active_bank_movement_unique` sobre `bank_movement_id` cuando `voided_at` es nulo, más la comprobación dentro de la transacción y resolución del ganador al chocar con el índice. También se atendieron dos avisos: la anulación bancaria reutiliza `ReasonRequest` (mensajes en español y recorte) y rechaza pagos que no vienen de un movimiento, para no reabrir una vía más ancha que su propósito.
L7. Prueba de regresión del caso reportado, en rojo primero: dos registros del mismo movimiento con claves distintas ahora devuelven el mismo pago y dejan un solo pago vigente. Evidencia final: 12 pruebas de conciliación / 49 aserciones y `composer ci` completo.
