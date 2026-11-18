# Nexo — stale reconciliation
Branch: fix/38-stale-reconciliation | Issue: #38 | Delivery: one PR

## Specs
S1. «Un reenvío idéntico antes de anular sigue siendo idempotente.»
S2. «Un reenvío después de anular un pago completo o parte de un reparto no muestra éxito ni altera los importes o el historial.»
S3. «Una nueva conciliación con un formulario nuevo sigue funcionando.»
S4. «Las pruebas comprueban el resultado visible y el estado de pagos y movimientos.»

## Tasks
- T1 [S1-S4] worker: reproduce, test and fix stale reconciliation; done; commit: 631a25f.
- T2 [S1-S4] independent verification passed; native assess reviewDue false; done; commit: 631a25f.
- T3 [S1-S4] PR, CI, squash merge and update main; done; commit: 991edeb.

## Log
L1. «vale vamos a ir de fase en fase y por cada punto una pr y una rama y hacemos el ciclo de trabajs en  una rama lueg pr y push se reiva y luego mergue al mian y luego te traes los cambios la localy sai se repite el cicloe venga comienza».
L2. Issue #38 created and confirmed; branch fix/38-stale-reconciliation from main 2c0a337. Existing uncommitted odd/tasks/safe-e2e-cleanup.md belongs to preceding unit: preserve and never include it in this PR. Risk: item 3 (shared reconciliation and payment contracts). The chosen safe behavior is rejection of an expired submission; new submissions remain allowed.
L3. Worker reported RED 3 failures reproducing false success, then focused 31 tests / 188 assertions and full 147 tests / 1104 assertions passed, Pint and PHPStan passed. Worker reported scratch files outside the repository; do not remove without established ownership and scope.
L4. Independent verifier confirmed S1-S4, focused 31/188 and full 147/1104, Pint and PHPStan passed; initial Xdebug noise and a PHPStan 128M limit failure were corrected by environment configuration. Advisory: stale split submission with cleared row can still show success, and a concurrent void may race replay; neither was reproduced against base and both require separate investigation. Native assess on commit 631a25f returned reviewDue false; independent verification was performed.
L5. PR #39 passed PHP 8.4, PHP 8.5 and E2E CI and was squash-merged as 991edeb3a1c6c8bf1d7feaa38c417ce77b7e151e. Local main updated; this task log remains uncommitted.
