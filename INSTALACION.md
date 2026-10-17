# Instalación del entorno local de DentiCore

Esta guía levanta el entorno completo desde cero (TASK-096; RNF-141: en 60 minutos o menos): base de datos, servicios, motor de riesgo, API, cola de trabajos y SPA, con los datos de demostración. Todos los datos son sintéticos (RES-08).

Está escrita para Windows con **Git Bash**. En Linux y macOS los comandos de las secciones 3 a 7 son los mismos; solo cambia la instalación de los programas (sección 1). Las reglas de trabajo en equipo están en `CONTRIBUTING.md`.

> **Toma el tiempo.** Anota la hora al empezar la sección 1 y al llegar a la sección 7. Si pasas de 60 minutos, anota en qué paso se fue el tiempo: es la evidencia de RNF-141.

## 1. Instalar los programas

| Programa | Versión | Para qué |
| :-- | :-- | :-- |
| Git | Última (en Windows, Git for Windows trae Git Bash) | Clonar el repositorio |
| Docker Desktop (o Docker Engine con Compose v2) | Última | PostgreSQL, Redis, MinIO, Mailpit, ClamAV y el motor de riesgo |
| PHP + Composer | PHP **8.3** con `bcmath`, `intl`, `pdo_pgsql`, `pgsql`, `zip` | API Laravel y sus pruebas |
| Node.js | **22 LTS** | SPA y pruebas de interfaz |
| Python | **3.12** | Solo si vas a modificar el motor (`denticore-ml/`); para usarlo basta Docker |

**Requisitos de la PC:**
- 16 GB de RAM (mínimo 8 GB) y 15 GB libres de disco.
- No instales PostgreSQL ni Redis: los levanta Docker.
- Clona el repositorio en un **SSD**. En un disco duro mecánico, `npm ci` y `composer install` escriben decenas de miles de archivos y pueden pasar de una hora (ver `docs/e1/verificacion-instalacion.md`).

**Windows.** Abre PowerShell como administrador:

```powershell
wsl --install                 # requisito de Docker Desktop; reinicia si lo pide
winget install Git.Git
winget install Docker.DockerDesktop
winget install OpenJS.NodeJS.LTS
```

Instala PHP con [Laravel Herd](https://herd.laravel.com/windows), que ya trae Composer. Luego, en *Settings → PHP*, elige **PHP 8.3** y activa `pdo_pgsql` y `pgsql` en *Extensions*.

Abre Docker Desktop una vez y espera a que diga *Engine running*. Si pide activar la virtualización, actívala en la BIOS.

**Linux (Debian o Ubuntu):**
- Docker Engine con el plugin de Compose.
- `php8.3-cli` con `php8.3-{bcmath,intl,pgsql,zip,mbstring,xml,curl}` y Composer.
- Node.js 22, desde NodeSource o `nvm`.

**macOS:**
- Docker Desktop;
- `brew install php@8.3 composer node@22`.

**Comprueba las versiones.** Cierra y abre la terminal; en Windows, usa **Git Bash** desde aquí:

```bash
git --version
docker compose version
php -v                        # 8.3.x
php -m | grep -i pgsql        # pdo_pgsql y pgsql
composer -V
node -v                       # v22.x
```

## 2. Descargar el proyecto

Primero acepta la invitación al repositorio que te llegó por correo de GitHub.

```bash
git clone https://github.com/alonsoszr/DentiCore---Saas.git
cd DentiCore---Saas
git config core.autocrlf false
git config pull.rebase true
git config user.name  "Tu Nombre Apellido"
git config user.email "el-correo-de-tu-cuenta-de-github"
```

## 3. Levantar los servicios

```bash
docker compose up -d                                        # la primera vez descarga ~3 GB
docker compose up -d --wait postgres redis minio mailpit ml # espera a que estén sanos
```

**Servicios y puertos:**

| Servicio | Puerto en tu PC | Para qué |
| :-- | :-- | :-- |
| PostgreSQL 16 | 5433 | Base `denticore` (y `denticore_testing` para las pruebas) |
| Redis 7 | 6380 | Colas, caché y Circuit Breaker del motor |
| MinIO | 9000 (consola en 9001) | Archivos y artefactos del modelo |
| Mailpit | 1025 (bandeja en http://localhost:8025) | Todos los correos que envía la aplicación |
| Motor de riesgo | 8001 | Predicción de riesgo de caries (FastAPI) |
| ClamAV | 3310 | Antivirus de los archivos subidos |

**Sobre ClamAV:**
- Tarda hasta 5 minutos en estar sano. No hace falta esperarlo para seguir.
- Mientras no responde, los archivos subidos quedan «pendientes».

## 4. Preparar la API

```bash
cd denticore-api
cp .env.example .env
composer install
php artisan key:generate
# Clave de sellado de evidencias (DD-46): una por PC, nunca la subas al repositorio.
echo "EVIDENCE_HMAC_KEY=$(php -r 'echo bin2hex(random_bytes(32));')" >> .env
php artisan migrate --database=pgsql_migrator   # las migraciones las ejecuta el dueño del esquema
php artisan db:seed                             # clínica de demostración con datos sintéticos
```

**Modelo de riesgo.** El motor arranca sin modelo. Se entrena la versión 1.0.0 con la semilla fija, se sube a MinIO y Laravel la registra y activa:

```bash
docker compose -f ../docker-compose.yml exec ml python -m denticore_ml.training --version 1.0.0 --out models --upload
php artisan risk:register-model 1.0.0 --activate
```

Su ficha técnica está en `denticore-ml/docs/model_card_1.0.0.md`.

## 5. Preparar la SPA

```bash
cd ../denticore-spa
cp .env.example .env
npm ci
```

## 6. Arrancar la aplicación

Cada proceso va en su propia terminal de Git Bash, abierta en la carpeta del repositorio:

| # | Proceso | Comando |
| :-: | :-- | :-- |
| 1 | API (http://localhost:8000) | `cd denticore-api && php artisan serve --port=8000` |
| 2 | Cola de trabajos (correos, PDF) | `cd denticore-api && php artisan queue:work redis --queue=critical,notifications,documents,heavy,default` |
| 3 | Despachador del outbox | `cd denticore-api && php artisan outbox:dispatch` |
| 4 | Tareas programadas (cierre de atenciones, inasistencias, recordatorios) | `cd denticore-api && php artisan schedule:work` |
| 5 | SPA (http://localhost:5173) | `cd denticore-spa && npm run dev` |

**Qué necesita cada proceso:**
- Sin las terminales 2 y 3 no salen los correos de invitación, confirmación y recordatorio.
- La 4 solo hace falta para ver las tareas automáticas.
- La primera petición a la API tarda unos segundos.

## 7. Comprobar que funciona

1. Abre http://localhost:8000/up: debe responder 200.
2. Abre http://localhost:5173/c/clinica-demo/login y entra como recepción (cuentas más abajo).
3. Registra un paciente y su consentimiento.
4. Abre http://localhost:8025: debe estar el correo de la clínica en español.

**Anota la hora: aquí termina la medición de RNF-141.**

### Cuentas de demostración

La contraseña de todas es `password`.

| Rol | Correo | Entrada |
| :-- | :-- | :-- |
| Súper Administrador | `admin@denticore.test` | http://localhost:5173/login. Pide un código 2FA: agrega a tu app de autenticación el secreto `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP` (sintético y público, solo local). |
| Administrador de la clínica | `admin@clinica-demo.test` | `/c/clinica-demo/login`. En el primer ingreso configura su 2FA con tu app; después la cuenta queda ligada a ese teléfono. |
| Odontólogo | `dentista@clinica-demo.test` | `/c/clinica-demo/login` |
| Recepción | `recepcion@clinica-demo.test` | `/c/clinica-demo/login` |
| Paciente (portal) | `paciente@clinica-demo.test` | `/c/clinica-demo/login`; al entrar va a su portal (`/c/clinica-demo/portal`) |

### Más herramientas

- **Documentación de la API:** http://localhost:8000/docs/api. El contrato versionado es `denticore-api/openapi.json`.
- **Consola de MinIO:** http://localhost:9001, con el usuario `denticore` y la contraseña `denticore_local`.

## 8. Ejecutar las pruebas

Las pruebas no cuentan en los 60 minutos. Usan la base `denticore_testing` y no tocan los datos de demostración.

```bash
cd denticore-api && vendor/bin/pest            # unas 1 700 pruebas; 10 a 20 min
cd denticore-spa && npm test                   # Vitest
cd denticore-spa && npx playwright install && npm run e2e -- --project=chromium-1280   # E2E (con la sección 6 corriendo)
```

**Antes de las E2E en local:**
- Arranca la API con `LOGIN_ATTEMPTS_PER_MINUTE=1000 API_REQUESTS_PER_MINUTE=1000 php artisan serve --port=8000`. Así todas las sesiones de Playwright pueden entrar desde la misma IP.
- No inscribas el 2FA del administrador de la clínica desde una prueba.

## 9. Problemas frecuentes

| Problema | Solución |
| :-- | :-- |
| `could not find driver` al migrar | Activa `pdo_pgsql` y `pgsql` (Herd: *Settings → PHP → Extensions*). |
| `php -v` muestra otra versión | Otro PHP va primero en el `PATH` (por ejemplo, XAMPP). Deja primero el de Herd o el 8.3. |
| `port is already allocated` | Otro programa usa 5433, 6380, 9000, 1025, 8025, 8001 o 3310. Ciérralo, o cambia el puerto con `FORWARD_DB_PORT` y similares (ver `docker-compose.yml`), y ajusta `.env`. |
| `permission denied` al migrar | Migra con `--database=pgsql_migrator`: la API usa un rol sin permisos sobre el esquema (SDD §2.13). |
| La migración `contract_legacy_columns` falla | Tu base viene de antes de MS-01. Solo hay datos sintéticos: `php artisan migrate:fresh --seed --database=pgsql_migrator`. |
| La predicción dice «Predicción no disponible» | Falta el paso del modelo (sección 4) o el contenedor `ml` está detenido (`docker compose ps`). Es el comportamiento esperado con el motor caído (RN-64). |
| No llegan los correos | Faltan las terminales 2 y 3 de la sección 6. |
| El login responde «demasiados intentos» (429) | Hubo más de 5 intentos en un minuto desde tu IP (RF-035): espera un minuto. |
| Docker no arranca (Windows) | Ejecuta `wsl --update` y revisa la virtualización en la BIOS. |
| La PC se pone lenta | Detén el antivirus si no subes archivos: `docker compose stop clamav`. |
| `npm ci` o `composer install` tardan muchísimo | El repositorio está en un disco duro mecánico o el antivirus de Windows revisa cada archivo. Clona en un SSD, o excluye la carpeta del repositorio en *Seguridad de Windows → Exclusiones*. |

## 10. Asistente de IA (opcional)

El equipo trabaja con un agente de codificación en la terminal (OpenCode o Claude Code). Las reglas que lee están en `AGENTS.md` y `CLAUDE.md`; no ejecutes `/init`, porque los reemplazaría.

Para OpenCode:

```bash
npm install -g opencode-ai
opencode auth login           # GitHub Copilot (Student Pack) u OpenCode Zen
```
