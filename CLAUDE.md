# DentiCore

SaaS multi-clínica para clínicas odontológicas del Perú. Proyecto del curso Pruebas y Calidad de Software.

## Estructura del monorepo

- `denticore-api/`: Laravel 13 (PHP 8.3), API REST `/api/v1`. Sus reglas están en `denticore-api/CLAUDE.md`, generado por Laravel Boost: no lo edites a mano.
- `denticore-spa/`: React 18 + Vite + Tailwind CSS v4. Sus reglas están en `denticore-spa/CLAUDE.md`.
- `denticore-ml/`: motor de riesgo (Python 3.12 + FastAPI), contrato SDD §4.6.
- `infra/` y `docker-compose.yml`: entorno local.
- `docs/`: fuentes de verdad del proyecto (no es código).

## Fuentes de verdad

Ante una contradicción, manda la primera de la lista:

1. `docs/SRS_DentiCore.md`: requisitos (RF, RNF, RN, CUS, CA).
2. `docs/SDD_DentiCore_v2.md`: diseño técnico (tablas, rutas, middleware, contratos).
3. `docs/Plan_Implementacion_DentiCore.md`: tareas `TASK-xxx`, dependencias y criterios de aceptación.
4. `docs/DESIGN.md` y las fichas `docs/design/screens/*.md`: interfaz.

- No crees tablas, columnas, rutas, estados ni reglas que no estén en el SDD. Si los necesitas, detente e informa (Plan §1.6).
- `docs/Articulos/` y `docs/borrador/` son material de consulta, no especificación.
- `docs/design/Prompts_Stitch_DentiCore.md` son prompts para Google Stitch, no especificación.

## Forma de trabajo

- Una tarea `TASK-xxx` por vez, con sus pruebas `T-xxx` escritas antes de la implementación.
- Mensajes de commit: `TASK-049: registra hallazgos del odontograma`.
- Solo datos sintéticos en semillas y pruebas; nunca datos reales (RES-08).
