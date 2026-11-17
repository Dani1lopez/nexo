# Nexo — CI for feature chain
Branch: ci/47-feature-chain | Issue: #47 | Delivery: one PR to main

## Specs
S1. «Las PR hacia las ramas de la cadena reciben las comprobaciones de PHP y, cuando corresponda, E2E.»
S2. «Las PR y los push a main conservan el comportamiento existente.»
S3. «La política y las comprobaciones del cambio quedan documentadas y verificadas.»

## Tasks
- T1 [S1-S3] implement and test narrowly scoped CI trigger; done; commit: pending.
- T2 [S1-S3] independent verification and native review; in progress; commit: pending.
- T3 [S1-S3] PR, CI, merge and update tracker base; commit: pending.

## Log
L1. User authorized «si lo autorizo» in response to a separate CI issue and PR to enable checks on the #44 feature-branch chain without weakening existing main checks.
L2. Issue #47 confirmed. Branch ci/47-feature-chain based on origin/main 47b4e8e; worktree .worktrees/47-feature-chain. Preserve the other-income candidate and its worktree. Risk: item 5 (CI and delivery policy).
L3. Worker reports RED 2/7 before trigger and self-check, GREEN 7/7, YAML parsed. Changed CI, CONTRIBUTING and a Node test that reads the actual workflow.
L4. Independent verifier confirmed 7/7 tests, actionlint success, YAML parse and 12 in-memory negative mutations caught. Yamllint reported 13 line-length warnings. GitHub Actions runtime and Node 24 remain unverified locally; real feature-base CI requires updated tracker base. No CI PR or merge.
