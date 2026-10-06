# Inicio de sesión de clínica (A1)

| Campo | Valor |
| :-- | :-- |
| CUS | CUS-06 Iniciar sesión (SRS §11.2) |
| Requisitos | RF-032, RF-033, RF-034, RF-035, RN-07, DD-15, DD-29 |
| Ruta SPA | `/c/:slug/login` (público) |
| Componente | `LoginPage` dentro de `AuthLayout variant="clinic"` |
| Referencia visual | `auth-login.html`, `auth-login*.png` |
| Tarea | TASK-040 (pantalla) · TASK-027 (API) |

## Datos

| Acción | Endpoint | Notas |
| :-- | :-- | :-- |
| Cargar clínica | `GET /api/v1/public/clinics/{slug}` | Nombre y logo para `ClinicHeader` y `BrandPanel`. 404 → ver PEND-08. |
| Iniciar sesión | `POST /api/v1/auth/login` | Body `{tenant_slug, email, password}`. `tenant_slug` sale de la URL, nunca de un campo. |

Respuesta 200 (SDD §4.5):

```json
{"token": "…", "abilities": ["2fa:pending"], "requires_2fa": true, "requires_2fa_setup": false, "expires_at": "…"}
```

Guardar el token en `sessionStorage` (`dc.token`), nunca en `localStorage` (DD-44).

## Formulario

| Campo | Tipo | Validación en cliente | Notas |
| :-- | :-- | :-- | :-- |
| Correo electrónico | `email`, `autocomplete="username"` | Obligatorio, formato de correo | Placeholder `nombre@correo.com`. |
| Contraseña | `PasswordField`, `autocomplete="current-password"` | Obligatorio, 1–128 caracteres | **No** aplicar aquí la política de 10 caracteres: esa regla es para crear contraseñas (SRS §11.2, Datos). |

Enlace "¿Olvidaste tu contraseña?" → `/c/:slug/restablecer`.

## Estados (variantes del HTML)

| Variante del HTML | Cuándo ocurre | Comportamiento |
| :-- | :-- | :-- |
| Normal | Estado inicial | Campos vacíos. |
| Error credenciales | Respuesta **401** | Alerta `error` con el título exacto **"Credenciales inválidas"**. Nunca indicar si falló el correo o la contraseña (RF-033). Vaciar la contraseña y mantener el correo. |
| Demasiados intentos | Respuesta **429** (5 intentos/min por IP, RF-035) | Alerta `warning`: "Demasiados intentos. Vuelve a intentarlo en {n} minuto(s)." con `n` calculado desde `Retry-After`. Deshabilitar "Ingresar" hasta que pase el tiempo. |
| Enviando | Petición pendiente | Botón con spinner y texto "Ingresando…"; campos deshabilitados. |
| Clínica suspendida | **No pertenece a esta pantalla** | El login funciona igual (CUS-06 FA-2). El banner de solo lectura va en el layout de la app (B1). |

**Importante:** el bloqueo de una cuenta por 5 fallos consecutivos (RF-034) **no tiene estado visual propio**. La API sigue respondiendo 401 "Credenciales inválidas" (CUS-06 FE-2) y el aviso llega al usuario por correo (`cuenta_bloqueada`). No inventar un mensaje de "cuenta bloqueada" en esta pantalla.

## Después del login (SDD §1.8 y §3.6)

| Respuesta | Destino |
| :-- | :-- |
| `requires_2fa: true` (token `2fa:pending`) | `/c/:slug/login/2fa` (A3) |
| `requires_2fa_setup: true` (token `2fa:setup`) | Configuración obligatoria del 2FA (A4, ver PEND-07) |
| Token `full` | `/c/:slug/app` (personal) o `/c/:slug/portal` (rol `patient`), según `GET /auth/me` |

Clínica cancelada dentro de los 90 días (CUS-06 FA-3): solo el Administrador de Clínica continúa, con acceso exclusivo a la exportación (CUS-05). Para los demás, la API responde 401.

## Textos

| Elemento | Texto |
| :-- | :-- |
| Título | Inicia sesión |
| Subtítulo | Accede con tu correo y contraseña. |
| Botón | Ingresar |
| Pie | ¿Problemas para ingresar? Contacta al administrador de tu clínica. |
| Pie (2.ª línea) | Las cuentas del personal se crean solo por invitación. |

"Contacta al administrador de tu clínica" es **texto**, no enlace: no existe una página de soporte.

## Ignorar del HTML de Stitch

Barra "RUTA ACTIVA / Variantes", `setVariant()`, `handleSubmit()` con `setTimeout` y `alert()`, Tailwind por CDN y su `tailwind.config` inline, SVG del logo de la clínica escrito a mano (viene de la API), `href="#recuperar"` y `href="#soporte"`.

## Criterios de aceptación de la pantalla

- CA-06.3: correo inexistente y contraseña incorrecta muestran exactamente el mismo mensaje.
- Un 429 deshabilita el envío durante el tiempo de `Retry-After`.
- Sin panel de marca por debajo de 1024px; formulario usable a 768px.
- Foco visible en todos los controles; errores anunciados con `role="alert"`.
