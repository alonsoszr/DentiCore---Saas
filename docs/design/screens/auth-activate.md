# Activar cuenta por invitación (A6)

| Campo | Valor |
| :-- | :-- |
| CUS | CUS-01 (primer administrador), CUS-11 (personal), CUS-19 (portal, E3) |
| Requisitos | RF-016, RF-040, RF-042, DD-22 |
| Ruta SPA | `/c/:slug/activar/:token` (SDD §1.10) |
| Componente | `ActivateAccountPage` dentro de `AuthLayout variant="clinic"` |
| Referencia visual | `auth-activate.html`, `auth-activate-odontologo.png`, `-administrador.png`, `-invalida.png` |
| Tarea | TASK-040 (pantalla) · TASK-029 (API) |

## Datos

| Acción | Endpoint | Notas |
| :-- | :-- | :-- |
| Cargar invitación | `GET /api/v1/auth/invitations/{token}` | Devuelve los datos de la invitación (nombre, correo, rol, clínica; verificar el contrato en el OpenAPI). Aquí **sí** se muestran, a diferencia del restablecimiento. |
| Activar | `POST /api/v1/auth/invitations/{token}/accept` | `{password, password_confirmation, accepted_terms}` (verificar nombres en el OpenAPI). |

## Contenido

- Título "Te invitaron a {nombre de la clínica}". Subtítulo "Completa tus datos de acceso para ingresar a la clínica."
- Card de solo lectura: nombre, correo y **badge** de rol (Administrador de Clínica, Odontólogo, Recepcionista). Mismo estilo de badge para todos los roles.
- "Crea tu contraseña" y "Confirmar contraseña" (`PasswordField`, `autocomplete="new-password"`).
- `PasswordRequirements` con `includeHistoryRule = false` y contador `n/3`:

| Requisito | Dónde se valida |
| :-- | :-- |
| Entre 10 y 128 caracteres | Cliente, en vivo |
| No es una contraseña común | Servidor (neutro hasta la respuesta; x roja si la API lo rechaza) |
| No contiene tu correo ni tu nombre | **Cliente, en vivo**: el nombre y el correo vienen del `GET` de la invitación. La API también lo valida. |

  La regla "No es igual a tus últimas 5 contraseñas" **no se muestra**: una cuenta nueva no tiene historial.

- Casilla obligatoria "Acepto los términos de uso y la política de privacidad" (enlaces a los documentos legales, RNF-162).
- Botón "Activar cuenta".
- Solo para Administrador de Clínica: aviso `info` "En el siguiente paso configurarás la verificación en dos pasos." (DD-15).

## Estados

| Variante | Cuándo | Comportamiento |
| :-- | :-- | :-- |
| Cargando | `GET` pendiente | Skeleton del formulario. |
| Odontólogo / Recepcionista | `GET` 200, rol sin 2FA obligatorio | Formulario sin el aviso de 2FA. |
| Administrador | `GET` 200, rol `clinic_admin` | Formulario con el aviso de 2FA. |
| Error de política | **422** | Mensajes junto al campo, requisito marcado en rojo, datos conservados. |
| Invitación inválida | `GET` o `POST` **404** (vencida, usada o inexistente: misma respuesta, TASK-015) | Título "Esta invitación ya no es válida". Texto "Las invitaciones vencen a las 72 horas y se usan una sola vez. Pide al administrador de tu clínica que te envíe una nueva." Solo el enlace "Ir al inicio de sesión". No decir "venció": la interfaz no puede distinguir si venció o ya se usó. |
| Éxito | 2xx | **[PENDIENTE PEND-05]**: si la API inicia sesión, seguir el flujo de A1 (el administrador va a A4). Si no, ir a `/c/:slug/login` con el aviso "Tu cuenta está activa. Inicia sesión." |

## Ignorar del HTML de Stitch

Barra "Vista de prueba / Odontólogo · Administrador · Invitación vencida", funciones de cambio de variante, datos de ejemplo del invitado, contraseñas precargadas, pie "Gestión de acceso clínico", Tailwind por CDN.
