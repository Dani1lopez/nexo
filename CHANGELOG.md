# Registro de cambios

Los cambios se agrupan por versión. Las etiquetas siguen las reglas de [CONTRIBUTING.md](CONTRIBUTING.md).

## Sin publicar

### Añadido

- Acceso individual ficticio, cierre de sesión y permisos de tesorería y junta; panel de identidad de solo lectura.
- Aprovisionamiento interactivo local y protección de intentos de acceso.
- Gestión de socios ficticios (`app/Modules/Members`): alta, edición, listado con filtro por estado, baja sin borrado y reactivación. Tesorería edita; junta solo consulta. Requiere ejecutar las migraciones.
- Cuota anual común (`app/Modules/Fees`): tesorería fija el importe de cada año y se asigna a los socios activos con alta hasta el fin de ese año; los socios dados de alta después reciben las cuotas que les corresponden. Exenciones por socio y año con motivo obligatorio y anulación con motivo que conserva el historial. Importes en céntimos enteros. Requiere ejecutar las migraciones.
- Pagos manuales de las cuotas (`app/Modules/Fees`): tesorería registra importe, fecha (hasta hoy), forma de pago (banco o efectivo) y una nota opcional sobre una cuota concreta; los pagos parciales suman y nunca pueden superar lo pendiente. Los pagos se corrigen solo con una anulación con motivo que los conserva en el historial. Cada formulario lleva una clave única, de modo que un doble envío no duplica el pago. Con pagos vigentes no se puede conceder una exención ni cambiar el importe del año. Cada cuota muestra pagado, pendiente y estado. Requiere ejecutar las migraciones.

- Importación de extractos bancarios en CSV (`app/Modules/Bank`): tesorería sube un archivo con fecha, importe y concepto; los movimientos ya importados (misma fecha, importe y concepto, sin distinguir mayúsculas ni espacios de más) se omiten como duplicados, las filas inválidas se rechazan con línea y motivo sin bloquear el resto, y el archivo no se guarda: solo quedan los movimientos y un registro de cada importación. Junta consulta pero no importa. Requiere ejecutar las migraciones.
- Integración continua con GitHub Actions (`.github/workflows/ci.yml`): validación de Composer, formato, análisis estático con Larastan, PHPUnit estricto en PHP 8.4 y 8.5, comprobación de migraciones, `shellcheck`, `composer audit` y pruebas e2e con Playwright contra el servidor real. Scripts `composer ci`, `lint`, `analyse` y `check:migrations`. La política está en `CONTRIBUTING.md`.

- Base visual con Tailwind 4 y CSS propio, compilada con Vite: diseño compartido con barra de navegación, enlace «Saltar al contenido», apartado actual marcado, componentes comunes (botones, campos, avisos, etiquetas de estado) y todas las pantallas restiladas y adaptables desde 360 px. Menú lateral con un acento de color por sección, tema oscuro de tono medio y tema claro (siguen la preferencia del sistema y un botón permite cambiar y recordar la elección), resumen del año en las cuotas, filas compactas para movimientos y listados, estados vacíos con acción, confirmaciones tras crear, editar o anular, y hoja de estilos de impresión. Tipografía Inter autoalojada. El panel muestra un resumen de solo lectura (`app/Modules/Dashboard`): socios activos, lo pendiente y lo cobrado del último año con cuota fijada, y la fecha del último extracto importado; sin cuotas fijadas lo explica en lugar de mostrar ceros. Requiere ejecutar `npm ci` y `npm run build`. Los textos funcionales no cambian; se añade un mensaje de confirmación a las redirecciones existentes y un fragmento de JavaScript para el cambio de tema.

### Cambiado

- Las transacciones de SQLite pasan a modo `IMMEDIATE` para que la comprobación de lo pendiente y el registro de un pago sean atómicos frente a peticiones simultáneas.
- El acceso se organiza como el módulo `app/Modules/Access` (acciones, controladores, solicitudes, modelo y autorización), sin cambios de comportamiento.

- Las pruebas de PHPUnit no cargan el manifiesto de Vite; el e2e comprueba que los estilos compilados se cargan, el enlace de saltar al contenido, la navegación marcada y la ausencia de desbordamiento horizontal a 360 px.
- PHPUnit falla ahora también por avisos, deprecaciones, notices, pruebas arriesgadas y salida inesperada.

- Base Laravel con página inicial Blade que identifica la demo ficticia.
- Comprobación de arranque y pruebas HTTP.
- Instrucciones locales y flujo de issues, ramas, PR y releases.

No hay una release publicada. La conciliación todavía no está implementada.
