# Nexo

Tesorería para asociaciones: cuotas claras, conciliación de movimientos y cuentas comprensibles para el siguiente equipo.

> Estado: base ejecutable. Hay una página inicial y una comprobación de arranque; hay acceso por roles, gestión de socios, cuota anual con exenciones y pagos manuales e importación de extractos bancarios en CSV; la conciliación todavía no está implementada. La primera versión será una demo con datos ficticios, no un servicio preparado para gestionar dinero real.

## Ejecutar en local

Requisitos: PHP 8.4 o superior, Composer 2, Node 24 o superior (solo para compilar los estilos) y extensiones habituales de Laravel (incluidas DOM, mbstring, XML y PDO SQLite). La instalación se ha comprobado con PHP 8.5.12. Composer comprueba los requisitos exactos del archivo de bloqueo.

```sh
composer install
npm ci
npm run build
composer test
# Solo si database/database.sqlite aún no existe:
touch database/database.sqlite
APP_ENV=local php artisan migrate
APP_ENV=local php artisan demo:user
# Una clave efímera para este proceso, sin imprimirla ni guardarla:
APP_ENV=local APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan serve --host=127.0.0.1 --port=8000
```

Abre http://127.0.0.1:8000. La ruta `/up` devuelve HTTP 200 cuando Laravel arranca. Detén el servidor con Ctrl+C. Este servidor es solo para desarrollo local.

La página pública y `/up` siguen funcionando sin clave ni base de datos. El acceso usa SQLite, sesiones y caché de base de datos; las migraciones normales no borran datos existentes. Crea cuentas individuales ficticias con `demo:user`: correo `.test`, rol y contraseña oculta confirmada de al menos 12 caracteres. El comando solo funciona en entorno local y rechaza duplicados. No utilices datos reales. La clave efímera cambia al reiniciar el servidor, por lo que se pierden las sesiones anteriores. No se crean archivos de credenciales. El panel muestra la identidad y enlaza a `/members`, la gestión de socios: tesorería crea, edita, da de baja y reactiva; junta solo consulta. Cada socio tiene nombre completo, correo ficticio `.test` único, fecha de alta y estado; la baja no borra datos y el listado muestra los activos por defecto (`?estado=activos|bajas|todos`). Tras actualizar, ejecuta `php artisan migrate` contra tu base de desarrollo y `npm run build` para regenerar los estilos. `/fees` gestiona la cuota anual: tesorería fija el importe de cada año (en euros, se guarda en céntimos) y se asigna a los socios activos con alta hasta el fin de ese año; las altas posteriores reciben las cuotas de los años ya fijados. Las exenciones son por socio y año con motivo obligatorio, y se retiran con una anulación con motivo que conserva el historial. Los pagos se registran a mano sobre una cuota concreta (importe, fecha hasta hoy, banco o efectivo y nota opcional sin datos personales reales); no pueden superar lo pendiente y solo se corrigen con una anulación con motivo que conserva el historial. Con pagos vigentes no se puede conceder una exención ni cambiar el importe de ese año. `/bank` importa extractos bancarios en CSV: tesorería sube un archivo con las columnas `fecha`, `importe` y `concepto` (delimitador `;` o `,` según la cabecera; fechas `dd/mm/aaaa` o `aaaa-mm-dd`; importes con signo, hasta dos decimales y sin separador de miles, negativos para cargos; texto UTF-8 o Windows-1252; el concepto no puede contener saltos de línea; máximo 1 MB). Una fila es duplicada si su fecha, importe y concepto (sin distinguir mayúsculas ni espacios de más) coinciden con un movimiento de una importación anterior; las filas repetidas dentro del mismo archivo se importan todas. Las filas inválidas no bloquean el archivo: se importan las válidas y se informa de las rechazadas con su línea y motivo. El archivo no se guarda; solo quedan los movimientos y un registro de cada importación (quién, cuándo y recuentos). Junta consulta pero no importa. Tras actualizar, ejecuta `php artisan migrate`. Los movimientos importados todavía no crean ni concilian pagos. No hay operaciones económicas reales.

Para comprobar formato y dependencias:

```sh
vendor/bin/pint --test
composer validate --strict
composer audit
```

`composer ci` ejecuta de una vez las mismas comprobaciones de PHP que el CI (validación, formato, Larastan, PHPUnit estricto, migraciones y auditoría). Las pruebas e2e en un navegador real usan Playwright y Node 24 o superior, solo para desarrollo:

```sh
npm ci
npx playwright install chromium
npm run e2e
```

La política del CI está en [CONTRIBUTING.md](CONTRIBUTING.md#integración-continua).

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

Base inicial: PHP 8.4+ y Laravel 13 con Blade y HTML. Las versiones exactas están fijadas en `composer.lock`. Los estilos (tema oscuro por defecto y tema claro, con un acento de color por sección) se escriben con Tailwind 4 y CSS propio en `resources/css/app.css` (los tokens de diseño viven en su bloque `@theme`) y se compilan con Vite: `npm run build` genera `public/build`, que no se versiona, y `npm run dev` recompila mientras se edita. Las vistas Blade comparten el diseño `resources/views/layouts/app.blade.php`. El único JavaScript propio es un fragmento de unas pocas líneas en el diseño base que cambia entre el tema claro y el oscuro y recuerda la elección en el navegador; sin él, el tema sigue la preferencia del sistema. SQLite es la base local de la demo; el alojamiento gratuito sigue pendiente de verificación.

El código se organiza como un monolito modular: cada área de negocio vive en `app/Modules/<Área>`, separada en `Actions` (casos de uso), `Http/Controllers`, `Http/Requests` (validación), `Models` y `Authorization`. Los módulos son `Access` (acceso y roles), `Members` (socios), `Fees` (cuota anual, exenciones y pagos) y `Bank` (importación de extractos bancarios). Rutas, vistas, migraciones, pruebas y proveedores mantienen las ubicaciones convencionales de Laravel. Solo se crea un módulo o una carpeta cuando contiene código real.

La estructura y la configuración inicial proceden de `laravel/laravel` v13.11.0 (MIT); se conserva su licencia en `LICENSE.framework.md`. La aplicación no tiene todavía una licencia de reutilización concedida.

## Acceso y roles

El login responde siempre con el mismo mensaje, `Las credenciales no son válidas.`, tanto si los datos son incorrectos como si el par correo/IP está bloqueado por exceso de intentos: el bloqueo no se revela al cliente. El límite es de 5 fallos por correo normalizado e IP, con una ventana de 60 segundos.

La IP del cliente es `REMOTE_ADDR` por defecto: el encabezado `X-Forwarded-For` no se tiene en cuenta, de modo que no se puede mover el límite de intentos falsificando una cabecera. Si la aplicación se despliega detrás de un proxy propio, define `TRUSTED_PROXIES` con las IPs de esos proxies, separadas por comas, para que el límite use la IP real. Sin esa variable no se confía en ningún proxy.

Los roles son solo `treasury` y `board`. No son asignables en masa: la única forma de escribirlos es `User::assignRole()` o `User::provision()`, y cualquier otro valor lanza una excepción antes de guardar. La columna `role` de SQLite sigue siendo texto libre porque añadir una restricción `CHECK` exige reconstruir la tabla; ese riesgo se acepta de forma explícita y las puertas de autorización deniegan cualquier valor desconocido, con una prueba que lo fija.

## Desarrollo y versiones

El flujo de issues, ramas, pull requests hacia `main` y releases está en [CONTRIBUTING.md](CONTRIBUTING.md). Los cambios de cada versión se registran en [CHANGELOG.md](CHANGELOG.md). La base actual todavía no es una release `v0.1.0`.

## Objetivo de entrega

Una primera demo en tres semanas, priorizando conciliación, integridad de los datos, permisos y pruebas. El alcance se revisará según el avance observado.
