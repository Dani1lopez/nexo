# Nexo — accurate product status
Branch: docs/40-product-status | Issue: #40 | Delivery: one PR

## Specs
S1. «La portada refleja las funciones disponibles y mantiene claros los límites de la demo.»
S2. «Las pruebas impiden recuperar el mensaje desactualizado.»
S3. «El changelog recoge conciliación, saldos y reparto sin reescribir releases anteriores.»

## Tasks
- T1 [S1-S3] worker: update home, tests and changelog; done; commit: 83e1d8b.
- T2 [S1-S3] verify and review; done, native assess reviewDue false; commit: 83e1d8b.
- T3 [S1-S3] PR, CI, squash merge and update local main; done; commit: cb59fdb.

## Log
L1. «vale vamos a ir de fase en fase y por cada punto una pr y una rama y hacemos el ciclo de trabajs en  una rama lueg pr y push se reiva y luego mergue al mian y luego te traes los cambios la localy sai se repite el cicloe venga comienza».
L2. Issue #40 confirmed; branch from main 991edeb. Preserve and exclude preexisting uncommitted odd/tasks/safe-e2e-cleanup.md and odd/tasks/stale-reconciliation.md. Scope only product status; no new features or visual redesign.
L3. Worker observed RED for stale copy and GREEN after changes. Parent found wording implied all movements rather than credits; observed RED on corrected public text, then GREEN: 3 tests / 22 assertions, full 149 tests / 1116 assertions, Pint and PHPStan passed. Existing stop-next style still suggests pending work visually; leave as separately scoped visual follow-up.
L4. Native assess returned reviewDue false. PR #41 passed PHP 8.4, PHP 8.5 and Playwright CI, and was squash-merged as cb59fdbc7bb42cc1d64a565bb9a2cd1156a16937. Local main updated; task log remains uncommitted.
