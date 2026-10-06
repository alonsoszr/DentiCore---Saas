# Configuración del segundo factor (A4)

| Campo | Valor |
| :-- | :-- |
| CUS | CUS-08 Configurar segundo factor |
| Requisitos | RF-038, DD-15, DD-36, CA-06.4, SDD §3.6 |
| Ruta SPA | `/…/seguridad/2fa` (SDD §3.4) **[PENDIENTE PEND-07]**: prefijo exacto. En la configuración forzada se renderiza **fuera** del layout de la app (sin sidebar). |
| Guardia | Token `2fa:setup` (configuración forzada) o `full` (configuración voluntaria desde el perfil, E2). |
| Componente | `TwoFactorSetupPage` dentro de `AuthCard` (sin panel de marca) |
| Referencia visual | `auth-2fa-setup.html`, `auth-2fa-setup-paso1.png`, `-paso2.png`, `-paso2-error.png`, `-paso3.png` |
| Tarea | TASK-040 (pantalla) · TASK-028 (API) |

## Cuándo aparece

| Situación | Token |
| :-- | :-- |
| Súper Administrador o Administrador de Clínica sin 2FA confirmado (primer ingreso) | `2fa:setup` |
| Usuario con `two_factor_reset_required = true` tras un restablecimiento (CUS-79) | `2fa:setup` |

Con token `2fa:setup` el usuario **solo** puede: `POST /auth/2fa/setup`, `POST /auth/2fa/confirm`, `POST /auth/logout`, `GET /auth/me`. No existe opción de omitir.

## Datos

| Paso | Endpoint | Resultado |
| :-- | :-- | :-- |
| Al entrar | `GET /auth/me` | Correo, rol y clínica para la tarjeta del usuario. |
| Paso 1 | `POST /api/v1/auth/2fa/setup` | Secreto y URI `otpauth://` para el QR (verificar nombres de campos en el OpenAPI). |
| Paso 2 | `POST /api/v1/auth/2fa/confirm` `{code}` | Activa el 2FA y devuelve los **10 códigos de recuperación** (se muestran una sola vez). |
| Paso 3 → Finalizar | — | **[PENDIENTE PEND-04]**: si la respuesta de `confirm` trae un token `full`, reemplazarlo e ir a la app; si no, cerrar sesión y volver al login con el aviso "Verificación activada. Inicia sesión de nuevo." |
| Cerrar sesión | `POST /api/v1/auth/logout` | Disponible en todos los pasos. |

## Estructura

- Encabezado: logo DentiCore a la izquierda, enlace "Cerrar sesión" a la derecha.
- Tarjeta del usuario: correo, badge de rol ("Administrador de Clínica" / "Súper Administrador") y nombre de la clínica (vacío para el Súper Administrador).
- Stepper de 3 pasos **no clicable**: 1. Escanea · 2. Confirma · 3. Códigos.

### Paso 1 — Escanea

- Título "Configuración del segundo factor".
- Aviso `info`: "Tu rol requiere verificación en dos pasos para proteger los datos de los pacientes. No podrás continuar hasta completarla."
- QR generado en el cliente a partir de la URI `otpauth://` (no pedir una imagen al servidor).
- "Usa Google Authenticator, Microsoft Authenticator u otra app compatible."
- "¿No puedes escanear el código? Clave de configuración manual": clave en `mono-md` agrupada de 4 en 4, con botón "Copiar".
- Botón "Continuar a confirmación".

### Paso 2 — Confirma

- Título "Confirma la configuración". Texto: "Ingresa el código de 6 dígitos que muestra tu aplicación."
- `OtpInput` (bordes `#7C8AA0`).
- Ayuda: "¿Problemas de sincronización? Verifica que la hora de tu teléfono esté en modo automático."
- Botones "Atrás" (vuelve al paso 1 con el mismo secreto) y "Confirmar".
- Error **422**: alerta `error` "Código incorrecto. Revisa que la hora de tu teléfono sea correcta e inténtalo de nuevo."; vaciar las casillas.
- Error **429** (`throttle:codes`): alerta con cuenta regresiva desde `Retry-After`.

### Paso 3 — Códigos

- Título "Guarda tus códigos de recuperación". Texto: "Si pierdes acceso a tu aplicación de autenticación, estos códigos te permitirán iniciar sesión."
- Aviso `warning` (ámbar): "Guárdalos antes de continuar. Cada código se usa una sola vez. No volverás a verlos."
- Cuadrícula de 10 códigos en `mono-md`, numerados 01–10.
- Botones "Descargar" (archivo `.txt` con los códigos, el correo y la clínica) y "Copiar".
- Casilla obligatoria "Guardé mis códigos de recuperación".
- Botón "Finalizar" a todo el ancho, deshabilitado hasta marcar la casilla.
- **Sin botón "Atrás"**: el 2FA ya está activo.
- Al salir de la página sin finalizar: los códigos no se vuelven a mostrar. Avisar con `beforeunload`.

## Prohibido (apareció en versiones de Stitch)

- Pestañas de pasos clicables.
- "Directiva de Seguridad MINSA", "MINSA-NTS", "TOTP SHA-1", sedes o IDs de clínica, pie con año o "Acceso Clínico Verificado".

## Ignorar del HTML de Stitch

Barra "Vista: Paso 1 · Paso 2 · Paso 2 (Error) · Paso 3", funciones de cambio de variante, QR y códigos de ejemplo escritos a mano, Tailwind por CDN.
