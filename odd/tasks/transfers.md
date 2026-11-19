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
- T2 [S1–S4] Migración con guardas, modelo, acciones de marcar y anular; en curso.
- T3 [S2,S5] Efecto en las dos cifras y en la lista de pendientes; pendiente.
- T4 [S1–S5] Rutas, permisos, formulario y etiquetas en `/bank` y `/saldos`; pendiente.
- T5 [S1–S5] Pruebas, e2e, documentación y entrega; pendiente.

## Log

L1. «si dale» en respuesta a proponer los traspasos banco–caja como siguiente frente, el último del alcance original.
L2. Diseño: tabla propia enlazada al movimiento del extracto, con dirección (a caja o a banco), importe absoluto copiado y las mismas guardas que las otras clasificaciones. La caja deja de ser solo base + cuotas − gastos: suma los traspasos hacia la caja y resta los que salen hacia el banco. El saldo del banco no cambia de fórmula.
