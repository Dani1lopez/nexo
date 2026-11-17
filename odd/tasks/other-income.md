# Nexo — classify other income
Branch: feat/44-other-income | Issue: #44 | Delivery: feature-branch chain

## Specs
S1. «Solo abonos importados, completos y todavía no asignados a cuotas; no clasificar cargos ni sobrantes parciales.»
S2. «Categorías predefinidas: Donaciones, Actividades, Subvenciones y Otros; descripción obligatoria.»
S3. «Tesorería clasifica y anula con motivo; junta consulta. La anulación conserva el historial y libera el abono.»
S4. «El saldo bancario no cambia al clasificar; el abono clasificado deja de figurar como pendiente de conciliación.»
S5. «Las operaciones impiden asignar simultáneamente el mismo dinero a cuotas y otros ingresos, también ante envíos repetidos o concurrentes.»
S6. «Caja, ingresos manuales, gastos y resolución de sobrantes quedan fuera de esta issue.»
S7. «Pruebas funcionales, permisos, idempotencia, integridad, concurrencia y flujo real de navegador con datos ficticios.»

## Tasks
- T1 [S1,S5,S7] integrity foundation and tests; commit: pending.
- T2 [S1,S2,S5,S7] classification and tests; commit: pending.
- T3 [S3,S5,S7] void, access and idempotency with tests; commit: pending.
- T4 [S3,S4,S7] bank and balance presentation with tests; commit: pending.
- T5 [S1-S7] browser flow, documentation and integration proof; commit: pending.
- T6 [S1-S7] independent verification, native review, tracker PR and delivery; commit: pending.

## Log
L1. «vale vamos a ir de fase en fase y por cada punto una pr y una rama y hacemos el ciclo de trabajs en  una rama lueg pr y push se reiva y luego mergue al mian y luego te traes los cambios la localy sai se repite el cicloe venga comienza».
L2. User accepted «clasificando un abono ya importado como otro ingreso, con categoría y descripción. La caja y los ingresos en efectivo quedarían para su propia PR» with «ese enfonque», and selected «Lista predefinida». Initial list Donaciones, Actividades, Subvenciones, Otros; description required. Issue #44 confirmed; branch from main 47b4e8e. Prior four modified task logs belong to previous units; preserve and exclude.
L3. Risk: items 1, 3 and 4 (financial integrity, shared allocation and concurrency). Candidate forecast above 400 authored lines; retain tests/docs alongside behavior and split only if each slice preserves deployable integrity.
L4. Worker reports implementation of S1-S7 with RED 20 tests / 8 errors / 12 failures, GREEN focused 20/158, suite 178/1327, Pint, analysis, migrations, Composer validation/audit and 19 E2E passed. Reported approximately 1446 authored lines, substantially above review budget. Worker reported external scratch despite instruction prohibiting it; preserve and report. No commit, push or PR.
L5. Independent verifier confirmed S1-S6, focused 20/158, full 178/1327, Pint and in-memory migration round trip; S7 browser flow not independently run because verifier was constrained against external temp. Worker-only browser run remains 19 passed. Verifier advisory: same-key concurrent submissions may return an error for the second despite the first succeeding; no double allocation observed. PHPStan and check:migrations not independently run due temp constraints, though writer reports green. Do not assert completion.
L6. User decided «si haz una division», accepting the recommendation to study a division into reviewable units without separating integrity safeguards. One read-only slicing pass suggested five slices, one estimated ~455 lines and counts not independently exact. Its proposal to merge an API write slice before the screen and reporting is unsafe: classified credits could still appear unallocated.
L7. User confirmed «si dale» to a feature-branch chain: integrate slices on a feature tracker and leave main unchanged until the complete feature is verified. Exact per-slice counts and safe test boundaries still need confirmation; one estimated slice exceeds 400 lines. Independent browser proof remains missing. Do not discard existing implementation or unrelated task logs.
