# Flujo de trabajo de Nexo

Después de la versión inicial, todo cambio empieza con una issue y llega a `main` mediante una pull request. No se trabaja directamente en `main`.

## De la issue a main

1. Crear una issue antes de modificar código: error reproducible, mejora o nueva funcionalidad. Indicar objetivo, alcance y criterios de aceptación. Buscar duplicados antes.
2. Crear una rama desde `main` actualizado: `feat/123-member-fees`, `fix/124-csv-import` o `docs/125-setup`. El número corresponde a la issue.
3. Implementar un cambio coherente, con pruebas y documentación. Usar commits convencionales: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `build:` o `ci:`.
4. Abrir una PR hacia `main`, enlazando la issue con `Closes #123` cuando la resuelva completamente, o `Refs #123` si solo aporta una parte. Explicar el resultado y las comprobaciones ejecutadas.
5. Revisar el diff y pasar las comprobaciones aplicables. Una prueba fallida o una verificación pendiente impide integrar. Si está activada la revisión nativa, respetar su consentimiento y resultado.
6. Integrar mediante squash merge con un mensaje convencional, y eliminar la rama cuando corresponda. `main` debe seguir siendo ejecutable.

Las correcciones detectadas durante una PR permanecen en esa misma rama; no necesitan una PR adicional si no amplían el alcance. Un trabajo distinto lleva otra issue y otra rama.

La base actual está en `feat/initial-foundation`: es una entrega de arranque, no la demo funcional terminada. Las publicaciones, integraciones y releases son acciones explícitas; este documento no habilita automatizaciones ni modifica la protección de ramas en GitHub.

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
