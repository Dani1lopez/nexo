# Nexo

Tesorería para asociaciones: cuotas claras, conciliación de movimientos y cuentas comprensibles para el siguiente equipo.

> Estado: planificación. La aplicación todavía no está implementada. La primera versión será una demo con datos ficticios, no un servicio preparado para gestionar dinero real.

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

Base propuesta: PHP y Laravel, con Blade, HTML, CSS y JavaScript. La base de datos, las versiones y el alojamiento gratuito siguen pendientes de verificación. No hay instrucciones de instalación todavía.

## Objetivo de entrega

Una primera demo en tres semanas, priorizando conciliación, integridad de los datos, permisos y pruebas. El alcance se revisará según el avance observado.
