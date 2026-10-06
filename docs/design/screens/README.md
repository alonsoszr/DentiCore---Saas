# Pantallas de referencia — DentiCore

Fichas de implementación de las pantallas diseñadas en Google Stitch. Cada pantalla tiene:

| Archivo | Qué es | Autoridad |
| :-- | :-- | :-- |
| `<pantalla>.md` | Ficha: ruta, endpoints, estados, reglas y textos. | **Manda.** Si contradice al HTML, sigue la ficha. Si contradice al SDD o al SRS, siguen el SDD y el SRS. |
| `<pantalla>.html` | Prototipo exportado de Stitch. | Solo referencia de layout, espaciado y estilos. |
| `<pantalla>*.png` | Capturas de cada variante aprobada. | Referencia visual. |

## Reglas para Claude Code (copiar en `CLAUDE.md`)

```markdown
## Pantallas de referencia (docs/design/screens/)
- Toda la UI sigue docs/DESIGN.md. No uses colores, radios ni fuentes fuera de sus tokens.
- Los .html son prototipos de Stitch: úsalos SOLO como referencia de layout, espaciado y estilos.
- Ignora siempre del HTML: la barra superior de variantes o "vista de prueba" (RUTA ACTIVA, Variantes, Simulador),
  las funciones que cambian variantes (setVariant u otras), envíos simulados con setTimeout/alert,
  el Tailwind por CDN y su configuración inline, y los datos de ejemplo escritos a mano.
- Cada "variante" del HTML es un ESTADO del componente, definido en la ficha <pantalla>.md.
- La ficha .md manda sobre el HTML. El SDD y el SRS mandan sobre la ficha.
- No copies textos del HTML que no estén en la ficha o en el SRS.
- Reutiliza los componentes compartidos de la tabla de abajo; no dupliques estilos por pantalla.
- Si una ficha marca algo como [PENDIENTE], no lo inventes: detente y pregunta.
```

## Índice

| Ficha | Pantalla | Ruta | Entrega | Tarea del plan |
| :-- | :-- | :-- | :-: | :-- |
| [auth-login.md](auth-login.md) | Inicio de sesión de clínica (A1) | `/c/:slug/login` | E1 | TASK-040 |
| [auth-login-platform.md](auth-login-platform.md) | Inicio de sesión de plataforma (A2) | `/login` | E1 | TASK-040 |
| [auth-2fa-verify.md](auth-2fa-verify.md) | Verificación en dos pasos (A3) | `/c/:slug/login/2fa` | E1 | TASK-040 |
| [auth-2fa-setup.md](auth-2fa-setup.md) | Configuración del segundo factor (A4) | `/…/seguridad/2fa` | E1 | TASK-040 |
| [auth-password-forgot.md](auth-password-forgot.md) | ¿Olvidaste tu contraseña? (A5) | `/c/:slug/restablecer` | E1 | TASK-040 |
| [auth-password-reset.md](auth-password-reset.md) | Restablecer contraseña (A5) | `/c/:slug/restablecer/:token` | E1 | TASK-040 |
| [auth-activate.md](auth-activate.md) | Activar cuenta por invitación (A6) | `/c/:slug/activar/:token` | E1 | TASK-040 |

## Componentes compartidos del bloque de acceso

| Componente | Usado en | Notas |
| :-- | :-- | :-- |
| `AuthLayout` | Todas las de este bloque salvo A4 | Columna izquierda con formulario (máx. 420px) + panel de marca navy a la derecha (≥ 1024px). Prop `variant: 'clinic' \| 'platform'`. |
| `BrandPanel` | `AuthLayout` | `clinic`: logo y nombre de la clínica, "Historia clínica, odontograma y agenda en un solo lugar", "Gestión odontológica digital para clínicas del Perú." y 3 tarjetas. `platform`: logo DentiCore, "Administración de la plataforma", "Gestión de clínicas, planes y seguridad de DentiCore." y sus 3 tarjetas. |
| `ClinicHeader` | Pantallas con `:slug` | Logo y nombre de la clínica desde `GET /public/clinics/{slug}`. |
| `AuthCard` | A4 | Card centrada (máx. 560px) sin panel de marca. |
| `PasswordField` | A1, A2, A5, A6 | Input con botón mostrar/ocultar (`aria-label` "Mostrar contraseña" / "Ocultar contraseña"). |
| `PasswordRequirements` | A5 (restablecer), A6 | Lista con contador. Prop `includeHistoryRule` (true en A5, false en A6). Ver la regla de validación en cada ficha. |
| `OtpInput` | A3, A4 | 6 casillas numéricas, acepta pegar los 6 dígitos, `inputmode="numeric"`, `autocomplete="one-time-code"`. |
| `Alert` | Todas | Tonos `error`, `warning`, `info`, `success` del DESIGN.md, con ícono + título + texto. |

## Formato de errores de la API (SDD §4.1)

Todas las respuestas de error son `application/problem+json`. Los errores de validación llegan en `errors: {campo: [mensajes]}` y se muestran **junto al campo**, conservando los datos del formulario (RNF-064). Un 429 trae la cabecera `Retry-After` (segundos).

## Pendientes de definición del bloque de acceso

Registrar en el plan (§9.3) antes de cerrar TASK-040.

| ID | Pregunta | Afecta a |
| :-- | :-- | :-- |
| PEND-01 | Recuperación de contraseña del Súper Administrador: `POST /auth/password/forgot` exige `tenant.slug` y el SDD solo define rutas de SPA con `:slug`. ¿Rutas `/recuperar` y `/restablecer/:token` de plataforma? | A2, A5 |
| PEND-02 | Rutas de verificación y configuración del 2FA para el Súper Administrador (¿`/login/2fa`?). | A2, A3, A4 |
| PEND-03 | ¿Quién restablece el 2FA de un Súper Administrador que perdió el teléfono y sus códigos de recuperación? (DD-36 no lo cubre). | A3, A4 |
| PEND-04 | Tras `POST /auth/2fa/confirm` con token `2fa:setup`: ¿la API devuelve un token `full` o el usuario debe iniciar sesión de nuevo? | A4 |
| PEND-05 | Tras `POST /auth/invitations/{token}/accept`: ¿la API inicia sesión o redirige al login? | A6 |
| PEND-06 | ¿La API expone los intentos restantes del 2FA (p. ej. cabecera `RateLimit-Remaining`)? Si no, el texto "Te quedan N intentos" no se muestra. | A3 |
| PEND-07 | Prefijo exacto de la configuración forzada del 2FA (`/…/seguridad/2fa` en SDD §3.4). Debe renderizarse fuera del layout de la app. | A4 |
| PEND-08 | Estado visual de "clínica no encontrada" cuando `GET /public/clinics/{slug}` responde 404 (no hay diseño). | A1, A5, A6 |
