# Nexo — gastos bancarios clasificados
Objetivo: clasificar los cargos del extracto para explicar en qué se gastó el dinero, sin descontarlo dos veces del saldo del banco.
Issue: [#65 — Clasificar un cargo del extracto como gasto bancario](https://github.com/Dani1lopez/nexo/issues/65) | Rama: `feat/65-bank-expenses`.

## Specs

S1. «Solo se clasifican cargos completos: ni abonos ni cargos con parte ya asignada a cuotas.»
S2. «Categorías predefinidas: Material, Actividades, Alquiler y suministros y Otros; descripción obligatoria.»
S3. «Tesorería clasifica y anula con motivo; junta consulta. La anulación conserva el historial y libera el movimiento.»
S4. «Clasificar no cambia el saldo del banco ni la caja; `/saldos` muestra el total clasificado.»
S5. «Un mismo movimiento nunca está a la vez en cuotas y en un gasto bancario, tampoco con envíos repetidos o simultáneos.»
S6. «Traspasos banco–caja, adjuntos y resolución de sobrantes quedan fuera de esta entrega.»
S7. «Pruebas de reglas, permisos, idempotencia, integridad y un flujo de navegador con datos ficticios.»

## Tasks

- T1 [S1–S7] Abrir la issue #65 y crear la rama; hecho.
- T2 [S1,S2,S5] Migración, modelo, clasificación y anulación con guardas de código y de SQLite; hecha.
- T3 [S2–S4] Rutas, permisos, formulario de clasificación en `/bank` y total en `/saldos`; hecha.
- T4 [S1–S5] Pruebas de reglas, permisos, idempotencia e integridad; hecha, 7 pruebas.
- T5 [S7] Flujo e2e, README, CHANGELOG y verificación de entrega; en curso.

## Log

L1. «si» en respuesta a la propuesta de abrir la issue de cargos bancarios como siguiente frente, con la recomendación de hacerlo antes que los traspasos banco–caja.
L2. Exploración previa: el patrón de otros ingresos (`other_incomes`) se reutiliza entero salvo tres cosas — el guard de importe negativo, el importe sin signo y la prueba que fija que un cargo no se clasifica como otro ingreso. Un gasto bancario no cabe en `cash_expenses`: no tiene borrador ni confirmación y entraría en el saldo de caja. El saldo del banco sigue siendo la suma firmada del extracto, así que clasificar no debe insertar ni modificar movimientos.
L3. Las guardas de «movimiento ocupado por un pago o por otro ingreso» son inalcanzables para un cargo: los disparadores existentes ya impiden que un pago o una clasificación de ingreso apunten a un cargo (importe y capacidad se comparan con el movimiento, que es negativo). Se mantienen como defensa en profundidad, pero no se prueban estados imposibles; en su lugar se fija que un abono no se clasifica como gasto y que la clasificación de ingreso sigue rechazando cargos.
