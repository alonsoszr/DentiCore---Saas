# Verificación en dos pasos (A3)

| Campo | Valor |
| :-- | :-- |
| CUS | CUS-07 Verificar segundo factor (TOTP) |
| Requisitos | RF-037, RF-038, DD-15, DD-36, SDD §1.8 y §3.6 |
| Ruta SPA | `/c/:slug/login/2fa` (SDD §3.4). Plataforma: **[PENDIENTE PEND-02]** |
| Guardia | Solo accesible con un token de habilidad `2fa:pending` en `sessionStorage`; sin él → volver al login. |
| Componente | `TwoFactorVerifyPage` dentro de `AuthLayout` (`clinic` o `platform`) |
| Referencia visual | `auth-2fa-verify.html` (clínica, 4 variantes), `auth-2fa-verify-platform.png` |
| Tarea | TASK-040 (pantalla) · TASK-028 (API) |

## Datos

| Acción | Endpoint | Body |
| :-- | :-- | :-- |
| Verificar código | `POST /api/v1/auth/2fa/verify` | `{code: "123456"}` o `{recovery_code: "XXXX-XXXX"}` |
| Volver al inicio de sesión | `POST /api/v1/auth/logout` | Revoca el token `2fa:pending` antes de salir. Luego borrar `dc.token` e ir al login. |

Respuesta 200: `{token (full), user}`. Reemplazar el token en `sessionStorage` y redirigir a `/c/:slug/app`, `/c/:slug/portal` o `/admin` según el rol.

## Contenido

- Indicador "Paso 2 de 2 · Verificación de identidad".
- Título "Verificación en dos pasos". Texto: "Ingresa el código de 6 dígitos de tu aplicación de autenticación."
- Línea "Ingresaste como {correo parcialmente oculto}", p. ej. `d.al•••@sonrisaandina.pe`. El correo lo recuerda la SPA desde el formulario de login: la API **no** devuelve datos del usuario antes de verificar el 2FA. No mostrar nombre ni COP.
- `OtpInput` de 6 dígitos. Enviar automáticamente al completar los 6 dígitos, o con "Verificar e ingresar".
- Enlace "¿No tienes acceso a tu app? Usar código de recuperación".
- Enlace "Volver al inicio de sesión".

## Estados (variantes del HTML)

| Variante | Cuándo ocurre | Comportamiento |
| :-- | :-- | :-- |
| Normal | Estado inicial | Foco en la primera casilla. |
| Código incorrecto | Respuesta **422** | Alerta `error`: "Código incorrecto." + "Revisa que la hora de tu teléfono sea correcta o espera el siguiente código." Vaciar las casillas y enfocar la primera. "Te quedan {n} intentos" solo si la API informa los intentos restantes **[PENDIENTE PEND-06]**. |
| Bloqueo | Respuesta **429** (`throttle:codes`: 5 fallos en 15 min) | Alerta `error`: "Demasiados intentos. Espera {mm:ss} o contacta al administrador de tu clínica." con cuenta regresiva desde `Retry-After`. Casillas y botón deshabilitados. |
| Código de recuperación | El usuario pulsa "Usar código de recuperación" | Reemplaza `OtpInput` por un campo de texto "Código de recuperación" (formato `XXXX-XXXX`, fuente monoespaciada). Ayuda: "Ingresa uno de los códigos de recuperación que guardaste al configurar la verificación. Cada código se usa una sola vez." Botón "Verificar código de recuperación". Enlace "Volver a ingresar el código de 6 dígitos". |
| Token expirado | Respuesta **401** | Borrar el token y volver al login con el aviso "Tu sesión expiró. Inicia sesión de nuevo." |

Un código fallido cuenta como intento de inicio de sesión (CUS-06 FE-5).

## Prohibido (apareció en versiones de Stitch y NO existe en el sistema)

- "Recordar este equipo por 30 días": el 2FA se exige en cada inicio de sesión (RF-037).
- Contador "Expira en Ns": la página no conoce el ciclo del código de la app y el servidor acepta ±1 periodo.
- Panel de marca distinto al del login: usar el mismo `BrandPanel`.

## Ignorar del HTML de Stitch

Barra de variantes, `setVariant()`, envíos simulados, Tailwind por CDN, datos de ejemplo escritos a mano.
