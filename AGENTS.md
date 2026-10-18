# DentiCore — instrucciones para agentes de codificación (Codex, OpenCode u otros)

SaaS multi-clínica para clínicas odontológicas del Perú (curso Pruebas y Calidad de Software).
Este archivo es el equivalente de `CLAUDE.md` para agentes que no son Claude Code. Si algo
difiere, manda `CLAUDE.md`.

## 1. Estructura del monorepo

| Carpeta | Contenido | Reglas propias |
| :-- | :-- | :-- |
| `denticore-api/` | Laravel 13 (PHP 8.3), API REST `/api/v1`, módulos en `app/Modules/<Módulo>` y núcleo en `app/Support` | `denticore-api/AGENTS.md` (Laravel Boost) |
| `denticore-spa/` | React 18 + Vite + Tailwind CSS 4; personal en `src/areas`, portal en `src/portal` | `denticore-spa/CLAUDE.md` |
| `denticore-ml/` | Motor de riesgo de caries (Python 3.12 + FastAPI) | — |
| `infra/`, `docker-compose.yml` | Entorno local (PostgreSQL 16, Redis 7, MinIO, Mailpit, ClamAV, ML) | — |
| `docs/` | Especificación del proyecto (no es código) | — |

Módulos del backend: `Platform`, `Identity`, `Patients`, `Odontogram`, `Treatment`, `Scheduling`,
`Risk`, `Portal`, `Compliance`. Cada uno tiene su archivo de rutas en `routes/api/<módulo>.php`.

## 2. Fuentes de verdad (en este orden)

1. `docs/SRS_DentiCore.md`: requisitos (RF, RNF, RN, CUS, CA).
2. `docs/SDD_DentiCore_v2.md`: diseño técnico (tablas, rutas, middleware, contratos, catálogo de pruebas §6.3).
3. `docs/Plan_Implementacion_DentiCore.md`: tareas `TASK-xxx` (§5), Definición de Terminado (§1.3), ramas y PR (§1.4).
4. `docs/DESIGN.md` y `docs/design/screens/*.md`: interfaz.

`docs/Articulos/` y `docs/borrador/` son material de consulta, no especificación.

**No leas los documentos completos** (son muy grandes). Para cada tarea lee solo:
- su fila en el Plan §5 (descripción, criterios de aceptación, «Depende de», «Origen»);
- las secciones del SDD y los IDs del SRS citados en «Origen»;
- las filas de sus pruebas `T-xxx` en el SDD §6.3.

## 3. Reglas obligatorias

- Trabajas **una sola tarea `TASK-xxx`** por vez, en su propia rama `task/<número>-<slug>`.
- **No crees tablas, columnas, rutas, estados ni reglas que no estén en el SDD.** Si los necesitas, detente y avisa.
- **Pruebas primero:** escribe las `T-xxx` de la tarea, confirma que fallan por la razón esperada y luego implementa.
- Cada prueba se agrupa por lo que verifica: `->group('T-142', 'RF-182', 'CUS-63')`.
- Aislamiento por clínica: `tenant_id` nunca llega desde la solicitud; los recursos de otra clínica responden 404;
  todo modelo con `tenant_id NOT NULL` usa `BelongsToTenant`.
- Cada escritura corre en una transacción del Service; los efectos asíncronos van al outbox en esa transacción.
- Reglas que dependen del tiempo: siempre con reloj simulado, nunca `sleep`.
- Errores en `application/problem+json`, textos en español del Perú, formatos `es-PE` (`S/ 1,234.56`, `dd/mm/aaaa`).
- Solo datos sintéticos. Sin secretos en el código.
- No cambies `composer.json`, `composer.lock`, `package.json` ni `package-lock.json` salvo que la tarea lo exija.
- `openapi.json` nunca se edita a mano: se regenera con `php artisan scramble:export`.
- Antes de terminar, revisa la Definición de Terminado (Plan §1.3) y marca las filas que aplican.

## 4. Trabajo en paralelo con Claude Code

Otro agente (Claude Code) trabaja al mismo tiempo en el mismo repositorio, en otra carpeta (`git worktree`).
- Trabaja **solo** en las tareas, módulos y carpetas que te asignaron en la instrucción de la tarea.
- **No crees migraciones** salvo que la tarea lo exija explícitamente; si hace falta, detente y avisa.
- Archivos compartidos (archivo de rutas de otro módulo, matriz `tests/Authorization/matrix.php`,
  programación de tareas, semillas): solo **agrega** al final lo tuyo; nunca reordenes ni borres lo ajeno.
- Usa tu propia base de pruebas (`DB_DATABASE=denticore_testing_codex`, `REDIS_DB=14`), ya configurada
  en el entorno de la terminal. Nunca uses `denticore_testing`.
- No ejecutes `php artisan migrate:fresh` ni comandos que borren datos sobre la base `denticore`.

## 5. Comandos

Backend (`denticore-api/`):
```bash
vendor/bin/pest --parallel                 # suite completa
vendor/bin/pest --filter=NombreDelTest     # una prueba
vendor/bin/pest --group=T-142              # pruebas de un ID
vendor/bin/pint --test                     # estilo
vendor/bin/phpstan analyse --memory-limit=1G
php artisan scramble:export                # regenera openapi.json (después de correr las pruebas)
```

Frontend (`denticore-spa/`):
```bash
npm test
npm run lint
npm run build
npm run e2e                                # requiere API y Docker levantados
```

En **PowerShell**, un argumento con `|` va entre comillas simples: `vendor/bin/pest '--filter=A|B'`.

## 6. Git

- Rama: `task/<número>-<slug>`, creada desde `main` actualizado.
- Commits: `TASK-104: atiende solicitudes ARCO` (ID y verbo en presente).
- No hagas `push` ni abras el PR sin que la persona responsable revise el cambio.
- Nunca hagas commit en `main` ni `push --force` a ramas ajenas.

## 7. Al terminar una tarea, muestra

1. Resultado de `vendor/bin/pest --parallel` (y `npm test` si tocaste la SPA).
2. Resultado de Pint y PHPStan.
3. `git diff --stat`.
4. Las filas de la Definición de Terminado que aplican, marcadas.
5. Cualquier punto donde te detuviste por falta de especificación.
