# Derechos del titular — versión mínima (TASK-109-a)

Primera versión básica autorizada por el responsable: reutiliza la presentación de las
pantallas existentes. No es el diseño visual final y no requiere un prototipo Stitch nuevo.
Fuentes: SDD §3.4, §4.3.3, §4.3.10, §4.3.11, §5.10 y §5.11; CUS-18 y CUS-62 a CUS-64;
RF-068, RF-069, RF-180, RF-182 a RF-185, RN-14, RN-68 a RN-70 y RNF-063.

## Alcance y navegación

- `/c/:slug/app/pacientes/:uuid/consentimiento`: conserva todo lo existente y agrega
  revocación solo para administrador/recepción.
- `/c/:slug/portal/consentimiento`: conserva el otorgamiento y agrega revocación al titular
  adulto o representante, nunca al titular menor.
- `/c/:slug/app/pacientes/:uuid/arco`: historial y registro presencial para administrador/recepción.
- `/c/:slug/portal/arco`: historial, respuesta y registro sobre el sujeto de la sesión.
- `/c/:slug/app/cumplimiento/arco`: bandeja y detalle dentro de la misma pantalla,
  únicamente para `clinic_admin` con `is_data_officer`.
- `/c/:slug/app/pacientes/:uuid/copia-hc` y `/c/:slug/portal/documentos`: solicitud,
  estado y enlace firmado de copia de HC. Sin portabilidad JSON, prevista en otro hito.

## Diseño básico

Reutilizar `Layout`, `PortalLayout`, `PatientHeader`, `PageHeader`, `Field`, `Alert`,
`StatusBadge`, `TableWrap` y `ConfirmDialog`, y las clases existentes `card`, `form-grid`,
`form-actions`, `btn` y `btn-secondary`. Sin colores, fuentes, tokens ni dependencias nuevos.
Formularios en una columna en portal y grilla existente en personal. Fechas civiles con
`formatCivilDate`, instantes con `formatDateTime`; etiquetas de estado siempre en español.

## Estados y operaciones

| Estado                        | Origen                              | Presentación/acción                                                                                                  |
| :---------------------------- | :---------------------------------- | :------------------------------------------------------------------------------------------------------------------- |
| Cargando                      | GET pendiente                       | «Cargando…», región de estado                                                                                        |
| Sin datos                     | GET 200 vacío                       | Mensaje explícito; formulario disponible si corresponde                                                              |
| Error                         | Red, 403, 404 o 409                 | Mensaje del API y posibilidad de reintentar la lectura                                                               |
| Validación                    | 422                                 | Errores junto al campo, datos conservados y foco en el primero                                                       |
| Revocación                    | POST `.../consents/:id/revocations` | Seleccionar finalidades vigentes; motivo opcional; confirmar consecuencias antes de enviar                           |
| Solicitud                     | POST `.../arco-requests`            | Tipo y descripción; presencial agrega solicitante, documento verificado, representante vigente y adjuntos opcionales |
| Recibida                      | GET `arco-requests`                 | Tipo, titular, fecha límite, días hábiles restantes y «Iniciar atención»                                             |
| En trámite                    | POST `.../:id/start`                | Responder, denegar con fundamento, asignar rectificación o aplicar cancelación según tipo                            |
| Cancelación                   | POST `.../:id/cancellation`         | Confirmar bloqueo, eliminación de datos opcionales y desactivación del portal; la HC se conserva                     |
| Resuelta                      | POST `.../:id/resolve`              | Mostrar respuesta, fundamento si corresponde e indicador de plazo; no volver a resolver                              |
| Documento pendiente/generando | POST copia; GET documento           | «En preparación» y «Actualizar estado»; nunca simular un archivo listo                                               |
| Documento listo               | GET documento                       | Enlace firmado «Descargar copia» y opción de actualizar el enlace                                                    |
| Documento fallido             | GET documento                       | Error de generación y posibilidad de solicitar otra copia                                                            |

## Textos de confirmación

- «Revocar finalidades»: el efecto es inmediato. Si incluye atención: «El consentimiento
  quedará revocado y no se podrán registrar nuevos datos clínicos hasta otorgar uno nuevo».
- «Aplicar cancelación ARCO»: «Se bloqueará al paciente, se eliminarán los datos de finalidades
  opcionales y se desactivará su acceso al portal. La historia clínica se conserva por obligación legal».
- «Resolver solicitud»: «Se registrará la respuesta y se notificará al solicitante. La resolución no se puede modificar».

## Verificación

Vitest: permisos, estados vacíos/error/422, cuerpos de solicitudes, confirmaciones sin efectos
al cancelar, formularios de personal/portal, copia pendiente/lista y flujo del oficial.
Playwright: registrar cancelación, iniciar, confirmar bloqueo, resolver y verificar rechazo de
una nueva escritura clínica; accesibilidad AA y uso en tablet/portal móvil.
La variante visual final se diseñará después. TASK-109-b conserva bitácora, perfil/2FA,
restablecimiento de acceso y cancelación de clínica.
