# Nexo — endurecer riesgos residuales del acceso

Objetivo: cerrar los tres riesgos residuales de seguridad del acceso registrados en la issue #9, antes de añadir operaciones económicas.
Branch: feat/9-access-hardening | Issue: #9 (OPEN) | Runner: composer test / PHPUnit.

## Specs

S1. User request: «pero no quiero que hagas eso que cojones quiero continuar con el proeyxto nexo».
S2. Issue #9 objective, verbatim: «Cerrar tres riesgos residuales de seguridad del acceso detectados durante la verificación de las PR #6 y #7. Ninguno es explotable hoy en la demo con datos ficticios, pero conviene resolverlos antes de añadir operaciones económicas.»
S3. Risk 1, verbatim: «El mensaje de bloqueo por exceso de intentos es distinto del de credenciales inválidas, por lo que revela que ese par correo/IP está bloqueado.»
S4. Risk 2, verbatim: «No hay configuración de proxies de confianza: detrás de un proxy, el límite de intentos usaría la IP del proxy.»
S5. Risk 3, verbatim: «La columna `role` es texto libre y asignable en masa. Ninguna ruta HTTP la escribe y los roles desconocidos se deniegan, pero no hay restricción a nivel de modelo o base de datos.»
S6. Acceptance, verbatim: «Decisión documentada y cubierta por pruebas para cada riesgo, o aceptación explícita del riesgo con su motivo.» y «Sin cambios en el comportamiento actual de login, logout y roles, que debe seguir cubierto por la suite.»
S7. Out of scope, verbatim: «Registro público, recuperación de contraseña, MFA y gestión de usuarios.»
S8. Delivery, verbatim: «Rama propia vinculada a esta issue, commits convencionales y PR hacia main con pruebas. Por tocar autenticación, requiere verificación técnica independiente.»

## Tasks

- T1 [S3,S6] route: parent inline, test-first; done; both login rejections now share one generic message, and a blocked attempt still never reaches `Auth::attempt`; tests updated to the generic text plus a new indistinguishability test. commit: pending.
- T2 [S4,S6] route: parent inline, test-first; done; added `config/trustedproxy.php` with `TRUSTED_PROXIES` (unset trusts no proxy, so `X-Forwarded-For` is ignored); tests pin both the untrusted default and the trusted case, where per-client buckets are isolated. commit: pending.
- T3 [S5,S6] route: parent inline, test-first; done; `role` removed from `$fillable`, writes only through `assignRole()`/`provision()`, unknown values throw before saving, `saving` guard backs it; DB-level `CHECK` accepted as a documented residual (SQLite table rebuild) with the fail-closed gate proof kept through a raw-row helper. Docs: README «Acceso y roles». commit: pending.

## Log

L1. User request: «pero no quiero que hagas eso que cojones quiero continuar con el proeyxto nexo». Reading: abandon the gentle-pi environment/repair line of work and continue the Nexo product. Chosen unit: issue #9, because the issue itself states these risks should be closed before economic operations; issue #12 (members polish) stays open and untouched.
L2. Decisions taken without new product questions, all recorded in README «Acceso y roles»: one generic rejection message for invalid and throttled attempts; no proxy trusted unless `TRUSTED_PROXIES` names it; roles limited to `treasury`/`board` and never mass assigned. DB-level `CHECK` on `role` accepted as a residual with its reason, because SQLite requires a table rebuild for it and the money workflows that motivated the hardening do not exist yet.
L3. Observed RED: focused `phpunit tests/Feature/AccessSecurityTest.php` reported 12 tests with 9 errors and 1 failure before the production change (lockout message still distinct, `X-Forwarded-For` not honoured when trusted, `assignRole`/`provision` absent). Observed GREEN after implementation: 12 tests, 121 assertions. Full `composer ci` then caught one static-analysis error (`new static()` unsafe in PHPStan) and one mangled line from the secret guard; both corrected without suppressing the analysis.
L4. Scope kept: no new product feature, no financial operation, no user-management UI, no registration or password reset. Issue #12 untouched.
L5. Full `composer ci` green: `composer validate --strict`, Pint, PHPStan (no errors), full suite 104 tests / 866 assertions, migration freshness check and `composer audit` (no advisories). The first full run surfaced a real consequence of T3: three older tests created accounts with an `admin` role through the model, which now throws. They now use `AuthTestCase::accountWithStoredRole()`, which inserts the row directly, so they keep proving that the gates deny unknown stored roles. Pint also caught one unused import left by that move; fixed rather than suppressed.

