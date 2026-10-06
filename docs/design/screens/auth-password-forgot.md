# ¿Olvidaste tu contraseña? (A5)

| Campo | Valor |
| :-- | :-- |
| CUS | CUS-09 Recuperar contraseña |
| Requisitos | RF-039, DD-15 |
| Ruta SPA | `/c/:slug/restablecer` (SDD §3.4). Plataforma: **[PENDIENTE PEND-01]** |
| Componente | `ForgotPasswordPage` dentro de `AuthLayout variant="clinic"` |
| Referencia visual | `auth-password-forgot.html`, `auth-password-forgot*.png` |
| Tarea | TASK-040 (pantalla) · TASK-029 (API) |

## Datos

| Acción | Endpoint | Body |
| :-- | :-- | :-- |
| Cargar clínica | `GET /api/v1/public/clinics/{slug}` | Para `ClinicHeader` y `BrandPanel`. |
| Solicitar enlace | `POST /api/v1/auth/password/forgot` | `{tenant_slug, email}` |

La API responde **igual** exista o no el correo (RF-039). La SPA muestra siempre el mismo mensaje de éxito ante cualquier 2xx.

## Formulario

| Campo | Validación en cliente |
| :-- | :-- |
| Correo electrónico (`email`, placeholder `nombre@correo.com`) | Obligatorio, formato de correo |

## Estados

| Variante | Cuándo | Comportamiento |
| :-- | :-- | :-- |
| Formulario | Inicial | Campo vacío. |
| Error de formato | Validación en cliente | Mensaje junto al campo: "Ingresa un correo válido." |
| Enviando | Petición pendiente | Botón con spinner. |
| Enlace enviado | Cualquier respuesta 2xx | Card `success`: "Si el correo está registrado, recibirás un enlace para restablecer tu contraseña. El enlace vence en 60 minutos." + "Volver al inicio de sesión". **Nunca** "Te enviamos un enlace a tu correo": eso confirma que la cuenta existe. |
| Demasiados intentos | **429** (`throttle:login`) | Alerta `warning` con el tiempo de `Retry-After`. |

## Textos

| Elemento | Texto |
| :-- | :-- |
| Título | ¿Olvidaste tu contraseña? |
| Subtítulo | Ingresa tu correo y te enviaremos un enlace para restablecer tu contraseña. |
| Botón | Enviar enlace |
| Enlace | Volver al inicio de sesión → `/c/:slug/login` |

## Ignorar del HTML de Stitch

Barra de variantes, funciones de cambio de variante, envíos simulados, Tailwind por CDN.
