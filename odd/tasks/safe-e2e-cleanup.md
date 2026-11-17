# Nexo — safe E2E cleanup
Branch: fix/36-safe-e2e-cleanup | Issue: #36 | Delivery: one PR

## Specs
S1. «proteger la limpieza de las pruebas E2E».
S2. «por cada punto una pr y una rama y hacemos el ciclo de trabajs en  una rama lueg pr y push se reiva y luego mergue al mian y luego te traes los cambios la localy sai se repite el cicloe».

## Tasks
- T1 [S1] parent: test and implement safe E2E temporary ownership; implemented; commit: pending.
- T2 [S1] independent verification and native review as applicable; commit: pending.
- T3 [S2] PR, CI, squash merge, update local main; commit: pending.

## Log
L1. «vale vamos a ir de fase en fase y por cada punto una pr y una rama y hacemos el ciclo de trabajs en  una rama lueg pr y push se reiva y luego mergue al mian y luego te traes los cambios la localy sai se repite el cicloe venga comienza».
L2. Issue #36 confirmed; branch fix/36-safe-e2e-cleanup created from synchronized main. Risk: item 1 (recursive cleanup may delete unrelated data).
L3. Local checks: 144 PHPUnit tests / 1063 assertions, Pint and PHPStan passed; 18 Chromium E2E passed; initial 3 cleanup tests passed. Independent verifier confirmed safety on isolated adversarial probes and 19 E2E tests, with startup-failure cleanup unverified. Verifier reported scratch writes outside its specified scope and an npm update notice; scratch remains untouched pending ownership and cleanup authorization.
