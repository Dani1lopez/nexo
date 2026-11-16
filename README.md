# Nexo

Tesorería para asociaciones: cuotas claras, conciliación de movimientos y cuentas comprensibles para el siguiente equipo.

> Estado: base ejecutable. Hay una página inicial y una comprobación de arranque; los módulos de tesorería todavía no están implementados. La primera versión será una demo con datos ficticios, no un servicio preparado para gestionar dinero real.

## Ejecutar en local

Requisitos: PHP 8.3 o superior, Composer 2 y extensiones habituales de Laravel (incluidas DOM, mbstring, XML y PDO SQLite). La instalación se ha comprobado con PHP 8.5.12. Composer comprueba los requisitos exactos del archivo de bloqueo.

```sh
composer install
composer test
# Solo si database/database.sqlite aún no existe:
touch database/database.sqlite
APP_ENV=local php artisan migrate
APP_ENV=local php artisan demo:user
# Una clave efímera para este proceso, sin imprimirla ni guardarla:
APP_ENV=local APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan serve --host=127.0.0.1 --port=8000
```

Abre http://127.0.0.1:8000. La ruta `/up` devuelve HTTP 200 cuando Laravel arranca. Detén el servidor con Ctrl+C. Este servidor es solo para desarrollo local.

La página pública y `/up` siguen funcionando sin clave ni base de datos. El acceso usa SQLite, sesiones y caché de base de datos; las migraciones normales no borran datos existentes. Crea cuentas individuales ficticias con `demo:user`: correo `.test`, rol y contraseña oculta confirmada de al menos 12 caracteres. El comando solo funciona en entorno local y rechaza duplicados. No utilices datos reales. La clave efímera cambia al reiniciar el servidor, por lo que se pierden las sesiones anteriores. No se crean archivos de credenciales. El panel solo muestra la identidad; no hay operaciones económicas.

Para comprobar formato y dependencias:

```sh
vendor/bin/pint --test
composer validate --strict
composer audit
```

`composer test` ejecuta PHPUnit directamente. `php artisan test` puede mostrar una advertencia de phpdotenv por la ausencia deliberada del archivo de entorno. No uses datos ni credenciales reales.

## Primera versión

El contexto es una asociación cultural ficticia de adultos en España, con euros, una cuenta bancaria y una caja de efectivo.

- Acceso de tesorería con edición y de junta con consulta completa.
- Socios, cuota anual común, exenciones justificadas y pagos parciales.
- Importación de un formato CSV documentado con detección de reimportaciones.
- Conciliación manual, incluido el reparto de una transferencia entre varios socios.
- Otros ingresos por categoría y descripción; sobrantes pendientes de resolver.
- Registro básico de efectivo y gastos, con motivo cuando falte justificante.
- Resumen de saldos, cuotas pendientes y movimientos sin conciliar.

Los borradores se podrán editar. Las operaciones confirmadas se corregirán mediante anulación justificada y una nueva operación, conservando el historial. Los traspasos entre banco y caja no serán ingresos ni gastos nuevos.

## Límites

La primera entrega no incluye cobros reales, conexión bancaria, adjuntos de justificantes, conciliación automática, múltiples formatos bancarios, cierre y reapertura de ejercicios ni informes PDF. Estas capacidades quedan para versiones posteriores.

No se garantiza cumplimiento fiscal, contable o legal. No se publicarán datos personales, extractos reales ni credenciales.

## Dirección técnica

Base inicial: PHP 8.3+ y Laravel 13 con Blade y HTML. Las versiones exactas están fijadas en `composer.lock`. La página inicial no requiere CSS, JavaScript ni compilación de recursos; se incorporarán según los módulos. SQLite es la base local de la demo; el alojamiento gratuito sigue pendiente de verificación.

La estructura y la configuración inicial proceden de `laravel/laravel` v13.11.0 (MIT); se conserva su licencia en `LICENSE.framework.md`. La aplicación no tiene todavía una licencia de reutilización concedida.

## Desarrollo y versiones

El flujo de issues, ramas, pull requests hacia `main` y releases está en [CONTRIBUTING.md](CONTRIBUTING.md). Los cambios de cada versión se registran en [CHANGELOG.md](CHANGELOG.md). La base actual todavía no es una release `v0.1.0`.

## Objetivo de entrega

Una primera demo en tres semanas, priorizando conciliación, integridad de los datos, permisos y pruebas. El alcance se revisará según el avance observado.
