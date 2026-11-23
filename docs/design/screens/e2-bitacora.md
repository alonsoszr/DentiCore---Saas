# Bitácora de auditoría de E2

TASK-109-b · CUS-66 · RF-187 · SDD §3.4 y §4.3.11.

El Súper Administrador entra por `/admin/auditoria`. El administrador de clínica entra por `/app/cumplimiento/auditoria`. Se reutilizan PageHeader, Field y TableWrap, con los tokens existentes.

La vista muestra fecha, acción, usuario o rol, y tipo de recurso. Los filtros «Desde», «Hasta», «ID de usuario», «Acción», «Tipo de recurso» e «ID de recurso» se aplican tanto a la consulta como a la exportación. Solo se exporta con ambas fechas aplicadas. La consulta mantiene los filtros al paginar y vuelve a la primera página al cambiarlos.

La API determina el alcance de los datos. La carga muestra «Cargando bitácora…», una lista vacía muestra «No hay eventos para estos filtros» y un fallo permite reintentar. Los errores de validación se muestran junto al campo correspondiente. El CSV se descarga desde la respuesta autenticada y conserva el ámbito de la consulta.
