# Nexo — bank CSRF tests
Branch: test/42-bank-csrf | Issue: #42 | Delivery: one PR

## Specs
S1. «Las tres operaciones rechazan peticiones sin token o con token incorrecto con respuesta 419.»
S2. «Un token válido no es rechazado por CSRF.»
S3. «Las peticiones rechazadas no crean ni modifican movimientos, pagos o importaciones.»
S4. «Las pruebas usan datos ficticios y una base aislada.»

## Tasks
- T1 [S1-S4] worker: add public HTTP CSRF tests; done; commit: 2e8ead2.
- T2 [S1-S4] independent verification passed and native assess reviewDue false; done; commit: 2e8ead2.
- T3 [S1-S4] PR, CI, squash merge, update local main; done; commit: 47b4e8e.

## Log
L1. «vale vamos a ir de fase en fase y por cada punto una pr y una rama y hacemos el ciclo de trabajs en  una rama lueg pr y push se reiva y luego mergue al mian y luego te traes los cambios la localy sai se repite el cicloe venga comienza».
L2. Issue #42 confirmed; branch test/42-bank-csrf from main cb59fdb. Preserve and exclude prior uncommitted task logs. Risk: item 2 (CSRF guards on financial operations). Tests only, no authorization or production behavior changes unless a proven defect requires separate scope.
L3. Worker reports tests-only characterization, mutation RED demonstrating unprotected 302 responses and state changes; focused 6 tests / 34 assertions, full 155 tests / 1150 assertions, Pint passed. Worker reported an external scratch file despite no-external-scratch instruction; preserve and report.
L4. Independent verifier confirmed S1-S4, focused 6/34, full 155/1150 and Pint. Verifier also reported external scratch outside scope; preserve. Header token variants not covered. Commit 2e8ead2; native committed-only assess reviewDue false.
L5. PR #43 passed PHP 8.4 and PHP 8.5 CI; E2E skipped by tests-only policy. Squash-merged as 47b4e8e973834389fa6c5baf9d0f96e8ccfa1681; main local updated; this task log remains uncommitted.
