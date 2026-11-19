# Nexo — traspasos entre banco y caja
Objetivo: sacar efectivo o ingresarlo sin contarlo como ingreso ni gasto, moviendo cada cifra una sola vez.
Issue: [#67 — Traspasos entre banco y caja](https://github.com/Dani1lopez/nexo/issues/67) | Rama: `feat/67-transfers`.

## Specs

S1. «Un cargo del extracto pasa a ser dinero que entró en la caja y un abono, dinero que salió de la caja hacia el banco.»
S2. «Un traspaso no crea ningún gasto ni ingreso: el saldo del banco no cambia y la caja sube o baja según la dirección.»
S3. «Solo movimientos completos: ni pagos a cuotas, ni otros ingresos, ni gastos bancarios; un movimiento, un destino.»
S4. «Reenviar el mismo formulario no duplica; con datos distintos se rechaza. La anulación con motivo conserva el historial y deshace el efecto en la caja.»
S5. «Un abono marcado como traspaso deja de figurar como pendiente de conciliación.»
S6. «Tesorería marca y anula; junta consulta. Fuera de alcance: adjuntos, sobrantes y cierres.»

## Tasks

- T1 [S1–S6] Abrir la issue #67 y la rama; hecha.
- T2 [S1–S4] Migración con guardas, modelo, acciones de marcar y anular; hecha.
- T3 [S2,S5] Efecto en las dos cifras y en la lista de pendientes; hecha.
- T4 [S1–S5] Rutas, permisos, formulario y etiquetas en `/bank` y `/saldos`; hecha.
- T5 [S1–S5] Pruebas, e2e, documentación y entrega; hecha salvo el cierre de la PR.
- T6 [S3] Guardas recíprocas: un movimiento marcado como traspaso rechaza pago, otro ingreso y gasto también cuando se escriben después; hecha con migración 000018, guardas en código y formularios ocultos.

## Log

L1. «si dale» en respuesta a proponer los traspasos banco–caja como siguiente frente, el último del alcance original.
L2. Diseño: tabla propia enlazada al movimiento del extracto, con dirección (a caja o a banco), importe absoluto copiado y las mismas guardas que las otras clasificaciones. La caja deja de ser solo base + cuotas − gastos: suma los traspasos hacia la caja y resta los que salen hacia el banco. El saldo del banco no cambia de fórmula.

L3. Revisión nativa (riesgo, resiliabilidad, fiabilidad + refutador): tres hallazgos, todos el mismo defecto real: la regla de un solo destino solo se aplicaba al escribir el traspaso, así que un cargo marcado como traspaso seguía ofreciendo el formulario de gasto y su escritura no tenía guarda. Corregido con RED previo: disparadores recíprocos en `payments`, `other_incomes` y `bank_expenses` (migración 000018), guardas en `ClassifyBankExpense` y `ClassifyOtherIncome`, formularios ocultos con `$transfer === null` y prueba que marca el traspaso primero y espera el rechazo. Verificación: 281 pruebas / 2066 aserciones, Pint, PHPStan y migraciones reversibles.
L4. Tras corregir y hacer commit, el linaje pasó a `recover` con disposición `scope_changed`: el candidato cambió de alcance respecto al congelado. No se ejecuta la recuperación sin autorización humana explícita, aunque el proveedor la ofrezca, porque muta la autoridad de revisión. La PR #68 queda con el CI en verde y la corrección empujada, a la espera de decidir entre recuperar el linaje y volver a revisar, o integrar por la política ordinaria del repositorio.
