# Instalación del entorno — DentiCore

Guía rápida para dejar tu PC (Windows) lista para trabajar en DentiCore con OpenCode. Hazla una sola vez, en orden. Las reglas de trabajo en equipo están en `CONTRIBUTING.md`.

## 1. Qué necesitas

| Programa | Versión | Para qué |
| :-- | :-- | :-- |
| Git for Windows | Última | Clonar el repositorio, ramas y commits |
| Docker Desktop | Última | Base de datos, Redis, almacenamiento, correo y antivirus (todo en contenedores) |
| PHP + Composer (con Laravel Herd) | PHP **8.3** | API Laravel y pruebas con Pest |
| Node.js | **22 LTS** | Interfaz React, pruebas y OpenCode |
| OpenCode | Última | Asistente de IA en la terminal |
| Python | **3.12** (no 3.13) | Solo si vas a tocar el motor ML (`denticore-ml/`) |

**No instales** PostgreSQL, Redis ni MinIO: los levanta Docker.
**PC recomendada:** 16 GB de RAM (mínimo 8 GB) y 15 GB libres de disco.

## 2. Instalar los programas

Abre **PowerShell como administrador**:

```powershell
wsl --install                          # requisito de Docker; reinicia la PC si lo pide
winget install Git.Git
winget install Docker.DockerDesktop
winget install Python.Python.3.12      # solo si trabajarás en el motor ML
```

Luego, instala manualmente:

- **Node.js 22 LTS:** desde https://nodejs.org/en/download (elige la versión 22).
- **Laravel Herd:** desde https://herd.laravel.com/windows. Al abrirlo, en *Settings → PHP* elige **PHP 8.3**. Herd ya trae Composer.

Abre Docker Desktop una vez y espera a que diga *Engine running*. Si pide activar la virtualización, actívala en la BIOS de tu PC.

## 3. Instalar y configurar OpenCode

```powershell
npm install -g opencode-ai
opencode --version
```

Conecta un modelo (una sola vez). Entra con `opencode` y escribe `/connect` (o ejecuta `opencode auth login` en la terminal):

- **GitHub Copilot** (recomendado si tienes el GitHub Student Developer Pack con tu correo de la universidad).
- **OpenCode Zen**: modelos gratuitos, solo pide registrarte con tu correo.

Para elegir el modelo dentro de OpenCode usa `/models`.

> **No ejecutes `/init`** en este repositorio: el archivo de reglas `AGENTS.md` ya existe y no debe reemplazarse.

## 4. Comprobar que todo está instalado

Cierra y vuelve a abrir la terminal (usa **Git Bash** desde aquí):

```bash
git --version
docker --version
php -v              # debe decir 8.3.x
composer -V
node -v             # debe decir v22.x
opencode --version
php -m | grep -i pgsql   # debe mostrar pdo_pgsql y pgsql
```

Si falta `pdo_pgsql`, actívalo en Herd (*Settings → PHP → Extensions*).

## 5. Descargar el proyecto

Antes, acepta la invitación al repositorio que te llegó por correo de GitHub.

```bash
git clone https://github.com/alonsoszr/DentiCore---Saas.git
cd DentiCore---Saas
git config user.name  "Tu Nombre Apellido"
git config user.email "el-correo-de-tu-cuenta-de-github"
git config core.autocrlf false
git config pull.rebase true
```

El correo debe ser el de tu cuenta de GitHub; si no, tus commits no aparecerán a tu nombre.

## 6. Levantar el entorno

```bash
# 1. Servicios (primera vez tarda varios minutos)
docker compose up -d --wait

# 2. Backend
cd denticore-api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --database=pgsql_migrator
php artisan db:seed
vendor/bin/pest                # todas las pruebas deben pasar

# 3. Frontend
cd ../denticore-spa
npm ci
npm test
npx playwright install         # solo si harás pantallas o pruebas E2E
```

Para ver la aplicación funcionando, en dos terminales:

```bash
cd denticore-api && php artisan serve --port=8000
cd denticore-spa && npm run dev          # abre http://localhost:5173
```

Usuario de prueba: `recepcion@clinica-demo.test` en la clínica `clinica-demo` (datos sintéticos).

## 7. Primer uso de OpenCode en el proyecto

En la raíz del repositorio:

```bash
opencode
```

Pregúntale: *«¿Qué reglas de trabajo tiene este repositorio?»*. Debe resumir `AGENTS.md`. Si lo hace, ya estás listo: toma tu primera tarea siguiendo la sección 4 de `CONTRIBUTING.md`.

## 8. Problemas frecuentes

| Problema | Solución |
| :-- | :-- |
| `could not find driver` al migrar | Activa `pdo_pgsql` y `pgsql` en Herd. |
| `port is already allocated` | Otro programa usa el puerto 5433 o 6380; ciérralo o reinicia Docker. |
| Docker no arranca | Ejecuta `wsl --update` y verifica la virtualización en la BIOS. |
| La PC se pone lenta | Detén el antivirus del proyecto si no lo usas: `docker compose stop clamav`. |
| `opencode` no se reconoce | Cierra y abre la terminal; si persiste, reinstala con `npm install -g opencode-ai`. |
| Pruebas en rojo recién clonado | Confirma que Docker esté corriendo y repite `php artisan migrate --database=pgsql_migrator`. |
