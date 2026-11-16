# Flujo de trabajo de Nexo

Después de la versión inicial, todo cambio empieza con una issue y llega a `main` mediante una pull request. No se trabaja directamente en `main`.

## De la issue a main

1. Crear una issue antes de modificar código: error reproducible, mejora o nueva funcionalidad. Indicar objetivo, alcance y criterios de aceptación. Buscar duplicados antes.
2. Crear una rama desde `main` actualizado: `feat/123-member-fees`, `fix/124-csv-import` o `docs/125-setup`. El número corresponde a la issue.
3. Implementar un cambio coherente, con pruebas y documentación. Usar commits convencionales: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `build:` o `ci:`.
4. Abrir una PR hacia `main`, enlazando la issue con `Closes #123` cuando la resuelva completamente, o `Refs #123` si solo aporta una parte. Explicar el resultado y las comprobaciones ejecutadas.
5. Revisar el diff y pasar las comprobaciones aplicables, incluida la integración continua (ver más abajo). Una prueba fallida o una verificación pendiente impide integrar. Si está activada la revisión nativa, respetar su consentimiento y resultado.
6. Integrar mediante squash merge con un mensaje convencional, y eliminar la rama cuando corresponda. `main` debe seguir siendo ejecutable.

Las correcciones detectadas durante una PR permanecen en esa misma rama; no necesitan una PR adicional si no amplían el alcance. Un trabajo distinto lleva otra issue y otra rama.

La base actual está en `feat/initial-foundation`: es una entrega de arranque, no la demo funcional terminada. Las publicaciones, integraciones y releases son acciones explícitas; este documento no habilita automatizaciones ni modifica la protección de ramas en GitHub.

## Integración continua

El workflow `.github/workflows/ci.yml` se ejecuta en cada PR hacia `main` y en cada push a `main`. Una PR con alguna comprobación en rojo no se integra, y una comprobación fallida se corrige en la misma rama.

| Trabajo | Cuándo | Qué comprueba |
| --- | --- | --- |
| `Comprobaciones (PHP 8.4)` y `(PHP 8.5)` | Siempre | `composer validate --strict`, `git diff --check`, formato con Pint, análisis estático con Larastan, PHPUnit que falla también por avisos, deprecaciones, notices y pruebas arriesgadas, migraciones desde cero con reversión completa y reaplicación, y `shellcheck`. En PHP 8.5 también `composer audit` |
| `E2E (Playwright)` | En `main`, y en una PR solo si cambia `app`, `bootstrap`, `config`, `database`, `e2e`, `public`, `resources`, `routes`, `scripts`, las dependencias o los workflows. Solo se ejecuta si las comprobaciones anteriores pasan | Flujos reales en Chromium contra `php artisan serve` con un SQLite temporal: acceso y permisos con sesión y cookies reales, flujo de tesorería (socio, cuota y pago parcial), importación CSV (subida real, filas rechazadas, reimportación detectada y junta sin acceso a importar) y, cuando existan, los flujos de cada funcionalidad crítica |

Reglas:

- **Local primero.** `composer ci` ejecuta las mismas comprobaciones de PHP. El e2e se ejecuta con `npm ci`, `npx playwright install chromium` y `npm run e2e`. Las pruebas e2e usan una base temporal fuera del repositorio y cuentas ficticias propias; no dependen de datos previos ni dejan archivos.
- **Sin reintentos.** Una prueba que falla a veces es un defecto que se corrige, no se repite. El CI no reintenta ni marca pruebas como tolerables.
- **Sin atajos en el análisis estático.** Larastan se ejecuta en el nivel más alto que el código ya cumple (hoy, el 1) sin `@phpstan-ignore` ni línea base. El nivel solo sube, y se sube corrigiendo los tipos.
- **Un flujo e2e por funcionalidad crítica.** Cuando una funcionalidad añade un flujo con dinero, permisos o importación de datos, su PR añade el flujo e2e correspondiente.
- **Acciones fijadas.** Las acciones de terceros se fijan por el SHA de un commit, con la versión en un comentario, y los trabajos solo tienen permiso de lectura del contenido. Se actualizan a mano, revisando el cambio.
- **Consumo de minutos.** El repositorio es público, así que los runners estándar de Linux no gastan la cuota mensual; si pasara a ser privado, sí la gastarían. Por eso el CI cancela las ejecuciones obsoletas de una PR, solo usa Linux, cachea Composer, npm y el navegador, limita el tiempo de cada trabajo, ejecuta el e2e solo cuando hace falta y conserva el rastro de un fallo únicamente siete días.
- **Protección de ramas.** Este documento no la configura. Si se activa, las comprobaciones exigibles son `Comprobaciones (PHP 8.4)`, `Comprobaciones (PHP 8.5)` y `E2E (Playwright)`; el e2e puede aparecer como omitido cuando una PR no toca nada relevante, y eso cuenta como correcto.

## Versiones

Usar etiquetas `vMAJOR.MINOR.PATCH` y mantener `CHANGELOG.md`.

| Situación | Versión |
| --- | --- |
| Primera demo funcional del alcance acordado | `v0.1.0` |
| Corrección compatible durante 0.x | `v0.1.1` |
| Nueva entrega funcional durante 0.x | `v0.2.0` |
| Candidato previo a una entrega | `v0.2.0-rc.1` |
| Primera versión con contratos estables | `v1.0.0` |
| Desde 1.0: corrección compatible | PATCH |
| Desde 1.0: funcionalidad compatible | MINOR |
| Desde 1.0: ruptura de un contrato público | MAJOR |

Durante 0.x no se promete estabilidad: cualquier ruptura se anuncia expresamente y aumenta MINOR, no PATCH. Las versiones publicadas son inmutables; una corrección produce otra versión. Los commits de documentación o mantenimiento no obligan por sí solos a publicar.

## Publicar una release

1. Elegir un commit de `main` ya revisado e integrado; comprobarlo en una instalación limpia con `composer install`, `composer test`, `vendor/bin/pint --test`, `composer validate --strict` y `composer audit`. Añadir las pruebas funcionales que requiera esa entrega.
2. Preparar por PR una entrada de changelog con versión, fecha y cambios respecto a la release anterior. Indicar incompatibilidades, pasos de actualización y limitaciones conocidas cuando existan.
3. Tras integrar esa PR y comprobar su commit, crear la etiqueta exacta sobre ese commit y publicar la release en GitHub con las mismas notas. Marcar las versiones `-rc.N` como prerelease.
4. No publicar si faltan comprobaciones requeridas o se han usado datos reales. Publicar una release no equivale a desplegar.

Todavía no hay releases ni automatización de publicación. La página inicial por sí sola no justifica `v0.1.0`.
