# Restablecer contraseña (A5)

| Campo | Valor |
| :-- | :-- |
| CUS | CUS-09 Recuperar contraseña |
| Requisitos | RF-039, RF-040, DD-15 |
| Ruta SPA | `/c/:slug/restablecer/:token` (SDD §1.10). Plataforma: **[PENDIENTE PEND-01]** |
| Componente | `ResetPasswordPage` dentro de `AuthLayout variant="clinic"` |
| Referencia visual | `auth-password-reset.html`, `auth-password-reset*.png` |
| Tarea | TASK-040 (pantalla) · TASK-029 (API) |

## Datos

| Acción | Endpoint | Body |
| :-- | :-- | :-- |
| Guardar contraseña | `POST /api/v1/auth/password/reset` | `{token, password, password_confirmation}` (verificar nombres en el OpenAPI) |

- No existe un endpoint para consultar el token antes de enviarlo. La pantalla **no** muestra nombre, correo ni COP del usuario.
- El token es de un solo uso y vence a los 60 minutos. Al guardar se revocan todas las sesiones del usuario.

## Formulario

| Campo | Tipo |
| :-- | :-- |
| Nueva contraseña | `PasswordField`, `autocomplete="new-password"` |
| Confirmar contraseña | `PasswordField`, `autocomplete="new-password"` |

## Requisitos de contraseña (RF-040)

`PasswordRequirements` con `includeHistoryRule = true` y contador `n/4`:

| Requisito | Dónde se valida | Estado en la lista |
| :-- | :-- | :-- |
| Entre 10 y 128 caracteres | Cliente, en vivo | x gris → check `#0F766E` al cumplirse |
| No es una contraseña común | **Servidor** (lista de ≥ 10 000) | Neutro ("se verifica al guardar") hasta la respuesta; x roja si la API lo rechaza |
| No contiene tu correo ni tu nombre | **Servidor** (esta pantalla no conoce al usuario) | Neutro hasta la respuesta; x roja si la API lo rechaza |
| No es igual a tus últimas 5 contraseñas | **Servidor** (historial) | Neutro hasta la respuesta; x roja si la API lo rechaza |

Además, "Las contraseñas no coinciden" junto a "Confirmar contraseña" (validación en cliente).

"Guardar contraseña" se habilita cuando se cumplen las reglas verificables en el cliente y ambas contraseñas coinciden. El resto lo decide el 422 de la API, cuyos mensajes se muestran junto al campo y marcan el requisito correspondiente.

No agregar un medidor de fuerza ni reglas que el RF-040 no pide (mayúsculas, símbolos, números).

## Estados

| Variante | Cuándo | Comportamiento |
| :-- | :-- | :-- |
| Formulario | Inicial | Campos vacíos, contador 0/4. |
| Enviando | Petición pendiente | Botón con spinner. |
| Error de política | **422** con `errors.password` | Mensajes junto al campo y requisito marcado en rojo; conservar lo escrito. |
| Enlace inválido | **404** (token vencido, usado o inexistente: misma respuesta, TASK-015) | Título "Este enlace ya no es válido", texto "Los enlaces vencen a los 60 minutos y se usan una sola vez." y botón "Solicitar un enlace nuevo" → `/c/:slug/restablecer`. |
| Éxito | 2xx | "Tu contraseña se actualizó. Por seguridad, cerramos tus sesiones abiertas." + botón "Ir al inicio de sesión". |

## Textos

| Elemento | Texto |
| :-- | :-- |
| Título | Restablecer contraseña |
| Subtítulo | Crea una nueva contraseña para acceder a tu cuenta. |
| Nota | Al guardar se cerrarán todas tus sesiones abiertas. |
| Botón | Guardar contraseña |
| Enlace | Volver al inicio de sesión (justo debajo del formulario) |

## Ignorar del HTML de Stitch

Barra "Estado de vista / Formulario · Enlace inválido · Éxito", funciones de cambio de variante, contraseñas de ejemplo precargadas, Tailwind por CDN.
