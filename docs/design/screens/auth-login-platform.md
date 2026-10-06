# Inicio de sesión de plataforma (A2)

| Campo | Valor |
| :-- | :-- |
| CUS | CUS-06 Iniciar sesión, flujo FA-1 Súper Administrador (SRS §11.2) |
| Requisitos | RF-032, RF-033, RF-035, RF-037, DD-15 |
| Ruta SPA | `/login` (público) |
| Componente | `LoginPage` reutilizado con `AuthLayout variant="platform"` |
| Referencia visual | `auth-login-platform.html`, `auth-login-platform*.png` |
| Tarea | TASK-040 |

## Diferencias con A1

Esta pantalla es **la misma que A1** con otra variante de layout. No crear un componente nuevo.

| Aspecto | A1 (clínica) | A2 (plataforma) |
| :-- | :-- | :-- |
| Encabezado del formulario | Logo y nombre de la clínica (API) | Candado + "DentiCore · Administración de la plataforma" + badge neutro "Acceso restringido" (texto `#0F1A34` sobre `#E5EEFF`) |
| Panel derecho | `BrandPanel variant="clinic"` | `BrandPanel variant="platform"` |
| `GET /public/clinics/{slug}` | Sí | No |
| Body de login | `{tenant_slug, email, password}` | `{email, password}` sin `tenant_slug` |
| Pie | "Contacta al administrador de tu clínica." | "¿Problemas para ingresar? Contacta al equipo de DentiCore." / "Acceso exclusivo para administradores de la plataforma." |
| "¿Olvidaste tu contraseña?" | `/c/:slug/restablecer` | **[PENDIENTE PEND-01]**: no hay ruta de plataforma. No mostrar el enlace hasta que se defina. |

## Panel de marca (plataforma)

- Título: "Administración de la plataforma"
- Texto: "Gestión de clínicas, planes y seguridad de DentiCore."
- Tarjetas:
  1. "Gestión de clínicas": "Alta, suspensión y reactivación de clínicas y cambio de plan."
  2. "Seguridad y auditoría": "Registro de accesos y eventos críticos de la plataforma."
  3. "Verificación en dos pasos obligatoria": "Este acceso requiere un código de 6 dígitos de tu aplicación de autenticación."

Nunca mencionar historia clínica, odontograma ni agenda: el Súper Administrador no accede a datos de pacientes (SRS ACT-01).

## Estados

Idénticos a A1 (401, 429, enviando). Sin estado de clínica suspendida.

## Después del login

El Súper Administrador siempre tiene 2FA obligatorio (DD-15):

| Respuesta | Destino |
| :-- | :-- |
| `requires_2fa: true` | Verificación en dos pasos de plataforma **[PENDIENTE PEND-02]** (propuesta: `/login/2fa`) |
| `requires_2fa_setup: true` | Configuración obligatoria del 2FA **[PENDIENTE PEND-02]** |
| Token `full` | `/admin` |

## Ignorar del HTML de Stitch

Igual que A1.
