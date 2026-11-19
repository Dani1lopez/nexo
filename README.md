# Nexo

Tesorería para asociaciones: cuotas claras, conciliación de movimientos y cuentas comprensibles para el siguiente equipo.

> Estado: base ejecutable. Hay una página inicial y una comprobación de arranque; hay acceso por roles, gestión de socios, cuota anual con exenciones y pagos manuales, importación de extractos bancarios en CSV, conciliación manual de un abono repartido entre varias cuotas, clasificación de otros ingresos y un resumen de saldos. La primera versión será una demo con datos ficticios, no un servicio preparado para gestionar dinero real.

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

La página pública y `/up` siguen funcionando sin clave ni base de datos. El acceso usa SQLite, sesiones y caché de base de datos; las migraciones normales no borran datos existentes. Crea cuentas individuales ficticias con `demo:user`: correo `.test`, rol y contraseña oculta confirmada de al menos 12 caracteres. El comando solo funciona en entorno local y rechaza duplicados. No utilices datos reales. La clave efímera cambia al reiniciar el servidor, por lo que se pierden las sesiones anteriores. No se crean archivos de credenciales. El panel muestra la identidad y enlaza a `/members`, la gestión de socios: tesorería crea, edita, da de baja y reactiva; junta solo consulta. Cada socio tiene nombre completo, correo ficticio `.test` único, fecha de alta y estado; la baja no borra datos y el listado muestra los activos por defecto (`?estado=activos|bajas|todos`). Tras actualizar, ejecuta `php artisan migrate` contra tu base de desarrollo y `npm run build` para regenerar los estilos. `/fees` gestiona la cuota anual: tesorería fija el importe de cada año (en euros, se guarda en céntimos) y se asigna a los socios activos con alta hasta el fin de ese año; las altas posteriores reciben las cuotas de los años ya fijados. Las exenciones son por socio y año con motivo obligatorio, y se retiran con una anulación con motivo que conserva el historial. Los pagos se registran a mano sobre una cuota concreta (importe, fecha hasta hoy, banco o efectivo y nota opcional sin datos personales reales); no pueden superar lo pendiente y solo se corrigen con una anulación con motivo que conserva el historial. Con pagos vigentes no se puede conceder una exención ni cambiar el importe de ese año. `/bank` importa extractos bancarios en CSV: tesorería sube un archivo con las columnas `fecha`, `importe` y `concepto` (delimitador `;` o `,` según la cabecera; fechas `dd/mm/aaaa` o `aaaa-mm-dd`; importes con signo, hasta dos decimales y sin separador de miles, negativos para cargos; texto UTF-8 o Windows-1252; el concepto no puede contener saltos de línea; máximo 1 MB). Una fila es duplicada si su fecha, importe y concepto (sin distinguir mayúsculas ni espacios de más) coinciden con un movimiento de una importación anterior; las filas repetidas dentro del mismo archivo se importan todas. Las filas inválidas no bloquean el archivo: se importan las válidas y se informa de las rechazadas con su línea y motivo. El archivo no se guarda; solo quedan los movimientos y un registro de cada importación (quién, cuándo y recuentos). Junta consulta pero no importa. Tras actualizar, ejecuta `php artisan migrate`. Junto a la importación, `/bank` permite conciliar: tesorería reparte un abono entre una o varias cuotas pendientes escribiendo el importe de cada una, y la suma debe dar exactamente lo que queda por conciliar de ese movimiento; si no cuadra no se crea ningún pago. Solo se concilian abonos (un cargo no genera un pago), se reutilizan las guardas de los pagos (ni cuota exenta ni importe por encima de lo pendiente) y un movimiento nunca reparte más que su propio importe, ni siquiera con dos envíos a la vez. Anular una parte desde `/bank` libera exactamente esa parte y conserva el historial; reenviar el mismo formulario no crea pagos nuevos, y ese reenvío se rechaza como formulario caducado si ya se anuló alguno de sus pagos (hay que recargar para obtener uno nuevo y conciliar lo que quede libre). Un abono mayor que las cuotas que se eligen queda sin resolver: el sobrante no se concilia ni genera ninguna operación, y resolverlo tendrá su propia issue. Junta consulta pero no concilia. Un abono completo que no es dinero para las cuotas se clasifica como otro ingreso: tesorería elige una categoría de la lista predefinida (Donaciones, Actividades, Subvenciones u Otros) y escribe una descripción obligatoria. Solo se clasifican abonos completos: ni cargos ni abonos con parte ya asignada a cuotas. El saldo del banco no cambia; el abono clasificado deja de figurar como pendiente de conciliación y `/saldos` lo cuenta como otro ingreso. La anulación con motivo conserva el historial y libera el abono. Un mismo abono nunca está a la vez en cuotas y en otros ingresos, tampoco con envíos repetidos o simultáneos: lo impiden las guardas en código y los disparadores de SQLite. Junta consulta pero no clasifica. Un cargo completo se clasifica como gasto bancario: tesorería elige una categoría (Material, Actividades, Alquiler y suministros u Otros) y escribe una descripción obligatoria. Clasificar no cambia el saldo del banco ni la caja, porque el extracto ya resta el cargo; `/saldos` muestra el total clasificado y `/bank` permite clasificar cada cargo pendiente. La anulación con motivo conserva el historial y libera el cargo. Un mismo movimiento nunca está a la vez en cuotas, en otros ingresos y en un gasto bancario, tampoco con envíos repetidos o simultáneos. Junta consulta pero no clasifica. Un movimiento completo también puede marcarse como traspaso entre el banco y la caja: un cargo es dinero que entró en la caja y un abono, efectivo que salió de la caja al banco. Un traspaso no es ingreso ni gasto, el saldo del banco no cambia y la caja sube o baja según la dirección; un abono marcado así deja de estar pendiente de conciliación, y la anulación con motivo conserva el historial y deshace el efecto en la caja. Un movimiento admite un único destino. Junta consulta pero no marca traspasos. `/saldos` resume, solo para consulta y para ambos roles, el saldo del extracto importado (los abonos suman y los cargos restan), el saldo de caja, los abonos sin conciliar (solo lo que falta de cada abono), los abonos clasificados como otros ingresos y la deuda por ejercicio con enlace a cada año. Banco y caja se muestran por separado. La caja suma los pagos de cuotas en efectivo vigentes más la base inicial y resta los gastos confirmados, sin esperar a que haya un extracto importado: un pago o un gasto anulado no cuenta y un pago en efectivo no cambia el saldo bancario. `/caja` es la página de la caja: tesorería fija ahí el saldo inicial una sola vez y junta solo consulta. La base es el dinero que ya había en la caja antes de usar Nexo y no incluye los pagos de cuotas en efectivo, que se suman solos; si los incluyera, contarían dos veces. Reenviar el mismo importe no cambia nada, un importe distinto no lo sobrescribe y la fila guarda quién lo fijó. Cero es una base fijada y no es lo mismo que no haberla fijado. En `/caja` se registran también los gastos pagados con el dinero de la caja: fecha, importe, categoría (Material, Actividades, Alquiler y suministros u Otros) y concepto; si no hay justificante, el motivo es obligatorio. Un gasto nace en borrador y se puede editar; confirmarlo descuenta una sola vez y reenviar la confirmación no descuenta dos veces. El mismo formulario de alta reenviado con los mismos datos es inofensivo, y reenviarlo con datos distintos se rechaza pidiendo recargar: nunca se descarta nada en silencio. Un gasto confirmado no se edita: se anula con motivo, el importe vuelve a la caja y la fila queda en el historial. Junta consulta pero no registra gastos. La deuda es la de todos los ejercicios fijados, una cuota exenta no se debe y un pago anulado no cuenta como cobrado. No hay operaciones económicas reales.

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

Cada ejecución crea su propia base temporal. `E2E_TMP` no permite elegir la ruta de limpieza: se genera internamente y solo se eliminan los archivos conocidos de esa ejecución. Si quedan archivos inesperados, la limpieza falla y conserva el directorio para inspección.

La política del CI está en [CONTRIBUTING.md](CONTRIBUTING.md#integración-continua).

El peso de los recursos propios se comprueba después de compilar, con dos presupuestos separados: uno para el resto de la aplicación y otro, medido en la compilación real, para la portada (el único recurso que carga Three.js):

```sh
npm run build
npm run check:weight
```

Las variables `WEIGHT_CSS_GZIP_KB`, `WEIGHT_CSS_RAW_KB`, `WEIGHT_JS_GZIP_KB` y `WEIGHT_JS_RAW_KB` bajan los límites del resto de la aplicación; `WEIGHT_LANDING_CSS_GZIP_KB`, `WEIGHT_LANDING_CSS_RAW_KB`, `WEIGHT_LANDING_JS_GZIP_KB` y `WEIGHT_LANDING_JS_RAW_KB` hacen lo mismo con la portada. `npm run e2e` compila, pasa ese presupuesto y ejecuta sus propias pruebas de Node antes de Playwright.

`composer test` ejecuta PHPUnit directamente. `php artisan test` puede mostrar una advertencia de phpdotenv por la ausencia deliberada del archivo de entorno. No uses datos ni credenciales reales.

## Primera versión

El contexto es una asociación cultural ficticia de adultos en España, con euros, una cuenta bancaria y una caja de efectivo.

- Acceso de tesorería con edición y de junta con consulta completa.
- Socios, cuota anual común, exenciones justificadas y pagos parciales.
- Importación de un formato CSV documentado con detección de reimportaciones.
- Conciliación manual, incluido el reparto de una transferencia entre varios socios.
- Otros ingresos por categoría y descripción, con anulación justificada; los sobrantes de un abono siguen pendientes de resolver.
- Registro básico de efectivo y gastos, con motivo cuando falte justificante.
- Resumen de saldos, cuotas pendientes y movimientos sin conciliar.

Los borradores se podrán editar. Las operaciones confirmadas se corregirán mediante anulación justificada y una nueva operación, conservando el historial. Los traspasos entre banco y caja no serán ingresos ni gastos nuevos.

## Límites

La primera entrega no incluye cobros reales, conexión bancaria, adjuntos de justificantes, conciliación automática, múltiples formatos bancarios, cierre y reapertura de ejercicios ni informes PDF. Estas capacidades quedan para versiones posteriores.

No se garantiza cumplimiento fiscal, contable o legal. No se publicarán datos personales, extractos reales ni credenciales.

## Dirección técnica

Base inicial: PHP 8.4+ y Laravel 13 con Blade y HTML. Las versiones exactas están fijadas en `composer.lock`. Los estilos (tema oscuro por defecto y tema claro, con un acento de color por sección) se escriben con Tailwind 4 y CSS propio en `resources/css/app.css` (los tokens de diseño viven en su bloque `@theme`) y se compilan con Vite: `npm run build` genera `public/build`, que no se versiona, y `npm run dev` recompila mientras se edita. Las vistas Blade comparten el diseño `resources/views/layouts/app.blade.php`. El JavaScript propio son dos entradas de Vite: `resources/js/theme.js` (unas pocas líneas en el diseño base que cambian entre el tema claro y el oscuro y recuerdan la elección; sin él, el tema sigue la preferencia del sistema) y `resources/js/landing.js`, que solo incluye la portada. La portada es una escena 3D con Three.js cargada en su propio recurso y en ningún otro: sin JavaScript, con movimiento reducido o sin WebGL se ve el fotograma estático y el texto completo. Su presupuesto de peso es propio (`npm run check:weight`, medido en `scripts/check-weight.mjs`), y el resto de la aplicación conserva el suyo. SQLite es la base local de la demo; el alojamiento gratuito sigue pendiente de verificación.

El código se organiza como un monolito modular: cada área de negocio vive en `app/Modules/<Área>`, separada en `Actions` (casos de uso), `Http/Controllers`, `Http/Requests` (validación), `Models` y `Authorization`. Los módulos son `Access` (acceso y roles), `Members` (socios), `Fees` (cuota anual, exenciones y pagos), `Bank` (importación de extractos, conciliación, otros ingresos y gastos bancarios), `Cash` (caja, saldo inicial, gastos en efectivo y traspasos con el banco) y `Dashboard` (panel y saldos, ambos de solo lectura). Rutas, vistas, migraciones, pruebas y proveedores mantienen las ubicaciones convencionales de Laravel. Solo se crea un módulo o una carpeta cuando contiene código real.

La estructura y la configuración inicial proceden de `laravel/laravel` v13.11.0 (MIT); se conserva su licencia en `LICENSE.framework.md`. La aplicación no tiene todavía una licencia de reutilización concedida.

## Acceso y roles

El login responde siempre con el mismo mensaje, `Las credenciales no son válidas.`, tanto si los datos son incorrectos como si el par correo/IP está bloqueado por exceso de intentos: el bloqueo no se revela al cliente. El límite es de 5 fallos por correo normalizado e IP, con una ventana de 60 segundos.

La IP del cliente es `REMOTE_ADDR` por defecto: el encabezado `X-Forwarded-For` no se tiene en cuenta, de modo que no se puede mover el límite de intentos falsificando una cabecera. Si la aplicación se despliega detrás de un proxy propio, define `TRUSTED_PROXIES` con las IPs de esos proxies, separadas por comas, para que el límite use la IP real. Sin esa variable no se confía en ningún proxy: la lista vacía es intencionada, porque un valor nulo haría que el framework confiara en todos los proxies cuando el `Host` termina en `.on-forge.com` o `.on-vapor.com`, y esa cabecera la controla el cliente.

Los roles son solo `treasury` y `board`. No son asignables en masa: la única forma de escribirlos es `User::assignRole()` o `User::provision()`, y cualquier cambio de rol con otro valor lanza una excepción antes de guardar. La columna `role` de SQLite sigue siendo texto libre porque añadir una restricción `CHECK` exige reconstruir la tabla, y una actualización con el constructor de consultas no pasa por el modelo; esos dos riesgos se aceptan de forma explícita y las puertas de autorización deniegan cualquier valor desconocido, con pruebas que lo fijan.

## Desarrollo y versiones

El flujo de issues, ramas, pull requests hacia `main` y releases está en [CONTRIBUTING.md](CONTRIBUTING.md). Los cambios de cada versión se registran en [CHANGELOG.md](CHANGELOG.md). La base actual todavía no es una release `v0.1.0`.

## Objetivo de entrega

Una primera demo en tres semanas, priorizando conciliación, integridad de los datos, permisos y pruebas. El alcance se revisará según el avance observado.
