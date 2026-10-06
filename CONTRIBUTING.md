# Guía de trabajo en paralelo — DentiCore

Cómo trabajamos los tres a la vez, cada uno con su asistente de IA en la terminal (Claude Code u OpenCode), sin pisarnos el código ni generar conflictos.

> **Las cinco reglas de oro**
>
> 1. **Una tarea `TASK-xxx` = una rama = un PR.** Nadie trabaja dos tareas en la misma rama.
> 2. **Nadie sube nada directo a `main`.** Todo entra por PR, con la CI en verde y la aprobación de otro integrante.
> 3. **Antes de empezar una tarea, se reserva** (issue asignado en el tablero). Si está asignada a otro, no se toca.
> 4. **El agente trabaja una tarea, no un hito.** Recibe solo la fila de la tarea y las secciones que cita; no inventa tablas, rutas ni reglas que no estén en el SDD.
> 5. **Rama corta, `rebase` diario.** Ninguna rama vive más de 2–3 días sin integrarse.

---

## 1. Preparación del repositorio (una sola vez — Alonso)

### 1.1 Dejar el repositorio limpio

Hoy hay 73 archivos que Git marca como modificados, pero el único cambio es el fin de línea (CRLF ↔ LF). Si cada uno trabaja en Windows sin normalizar, cada PR cambiaría archivos enteros y los conflictos serían constantes.

1. Terminar o guardar la tarea en curso (`task/044-cie10`).
2. Ampliar `.gitattributes` para que todo el repositorio use LF:

   ```gitattributes
   * text=auto eol=lf
   *.sh text eol=lf
   *.py text eol=lf
   docker-compose.yml text eol=lf
   *.png binary
   *.jpg binary
   *.docx binary
   ```

3. Normalizar en un PR propio:

   ```bash
   git switch main && git pull
   git switch -c task/000-normaliza-fin-de-linea
   git add --renormalize .
   git commit -m "TASK-000: normaliza finales de línea a LF"
   git push -u origin task/000-normaliza-fin-de-linea
   ```

### 1.2 Versionar la documentación del proyecto

Hoy `docs/` está en `.gitignore`, así que **quien clona el repositorio no recibe el SRS, el SDD ni el plan**, y su agente no tiene de dónde sacar la especificación.

1. En `.gitignore`, reemplazar la línea `docs/` por:

   ```gitignore
   docs/Articulos/
   docs/borrador/
   docs/*.docx
   ```

2. Revisar que `docs/Articulos/` no se suba (contiene un documento con datos personales de un tercero).
3. Subir en un PR: `SRS_DentiCore.md`, `SDD_DentiCore_v2.md`, `Plan_Implementacion_DentiCore.md`, `PlanDePruebas_DentiCore.md`, `DESIGN.md` y `docs/design/`.

### 1.3 Reglas para los agentes (`AGENTS.md`)

Claude Code lee `CLAUDE.md`; OpenCode lee `AGENTS.md`. Crear `AGENTS.md` en la raíz para que los dos agentes sigan las mismas reglas:

```markdown
# DentiCore — reglas para agentes de codificación

Lee y sigue también `CLAUDE.md` (mismas reglas). Si hay diferencia, manda `CLAUDE.md`.

## Antes de escribir código
- Trabajas UNA tarea `TASK-xxx`. Lee su fila en `docs/Plan_Implementacion_DentiCore.md` §5
  (descripción, criterios de aceptación, «Depende de» y «Origen»).
- Lee solo las secciones del SDD y los IDs del SRS citados en «Origen». No leas documentos enteros.
- Si una dependencia de la tarea no está en `main`, detente y avisa.

## Límites
- No crees tablas, columnas, rutas, estados ni reglas que no estén en el SDD. Si faltan, detente y avisa.
- No modifiques archivos de otros módulos salvo los compartidos que la tarea exige
  (rutas de su módulo, matriz de autorización, openapi.json, semillas).
- No cambies dependencias (composer.json, package.json, requirements) sin que la tarea lo pida.
- Solo datos sintéticos.

## Forma de trabajo
- Primero las pruebas `T-xxx` de la tarea; deben fallar por la razón esperada. Luego la implementación.
- Ejecuta la suite del área antes de terminar: `vendor/bin/pest`, `npm test` o `pytest`.
- Commits: `TASK-049: registra hallazgos del odontograma`.
- Nunca hagas commit en `main` ni `git push --force` a ramas ajenas.
```

### 1.4 Acceso y protección de `main`

1. **Settings → Collaborators → Add people**: invitar a los dos compañeros con su usuario de GitHub.
2. **Settings → Rules → Rulesets → New branch ruleset** sobre `main`:
   - *Require a pull request before merging*, con **1 aprobación**.
   - *Require status checks to pass*: los checks de los workflows `api`, `spa`, `e2e` y `ml`.
   - *Block force pushes*.
   - *Allow merge method*: solo **Squash**.
3. **Settings → General → Pull Requests**: activar *Automatically delete head branches*.

### 1.5 Plantilla de PR

Crear `.github/pull_request_template.md`:

```markdown
## TASK-xxx — <título>

**Hito:** MS-xx · **Carril:** A/B/C · **Issue:** #

### Qué hace
-

### Secciones del SDD e IDs del SRS
-

### Pruebas T-xxx cerradas
-

### Definición de Terminado (Plan §1.3)
- [ ] DoD-01 Código en su módulo, sin atajos entre capas
- [ ] DoD-02/03 Aislamiento por clínica (BelongsToTenant, RLS, 404 entre clínicas)
- [ ] DoD-04/05 Transacción en el Service y auditoría
- [ ] DoD-06/07 Pruebas agrupadas por ID y reloj simulado
- [ ] DoD-08/09 Matriz de autorización y OpenAPI actualizados
- [ ] DoD-10 Pantalla mínima en español (si aplica)
- [ ] DoD-12 Sin secretos ni datos reales

### Evidencia
<salida de la suite / capturas>
```

### 1.6 Tablero de tareas (el «candado»)

Crear un **GitHub Project** (tablero) con las columnas *Por hacer*, *En curso*, *En revisión* y *Hecho*, y un issue por cada tarea pendiente de E1 (TASK-044 a TASK-098), con el título `TASK-049 — Hallazgos y correcciones del odontograma` y la etiqueta de su hito (`MS-02`…).

**Asignarse el issue es reservar la tarea.** Así nadie trabaja dos veces lo mismo.

---

## 2. Preparación de cada integrante (una sola vez)

### 2.1 Herramientas

- Git, Docker Desktop, PHP 8.3 con Composer, Node.js 22 y Python 3.12.
- Un asistente en terminal: **Claude Code** u **OpenCode** (`npm i -g opencode-ai`) con el modelo que tengan disponible.

### 2.2 Clonar y configurar la identidad

```bash
git clone https://github.com/alonsoszr/DentiCore---Saas.git
cd DentiCore---Saas
git config user.name  "Nombre Apellido"
git config user.email "correo-registrado-en-tu-cuenta-de-github@..."
git config core.autocrlf false
git config pull.rebase true
```

El correo **debe** estar registrado en la cuenta de GitHub de quien hace el commit; si no, los commits no aparecen a su nombre.

### 2.3 Levantar el entorno

```bash
docker compose up -d --wait                       # PostgreSQL, Redis, MinIO, Mailpit, ClamAV, ML
cd denticore-api
cp .env.example .env && composer install && php artisan key:generate
php artisan migrate --database=pgsql_migrator
php artisan db:seed                               # clínica de demostración (datos sintéticos)
vendor/bin/pest                                   # todo debe estar en verde antes de empezar
cd ../denticore-spa
npm ci && npm test
```

Opcional, para que el agente conozca las convenciones de Laravel: `php artisan boost:install` y elegir el agente que se usa.

### 2.4 Comprobar que el agente lee las reglas

En la raíz del repositorio, abrir el agente y preguntar: *«¿Qué reglas de trabajo tiene este repositorio?»*. Debe resumir `AGENTS.md`/`CLAUDE.md`. Si no, revisar que el archivo esté en `main` y que el agente se abrió en la raíz.

---

## 3. Reparto del trabajo

### 3.1 Carriles

El plan asigna cada tarea a un carril (columna *Carril* del Plan §5):

| Carril | Contenido | Responsable | Herramienta |
| :-- | :-- | :-- | :-- |
| **A** | Núcleo, clínico y comercial (camino crítico del backend) | Alonso | Claude Code |
| **B** | Pacientes, agenda, riesgo en Laravel y portal | *[Integrante B]* | OpenCode |
| **C** | SPA transversal, odontograma, verificación y pruebas | *[Integrante C]* | OpenCode |

El carril es una preferencia, no un muro: **regla de *pull*** (Plan §4.5) — quien termina toma, entre las tareas cuyas dependencias ya están en `main`, la del hito más antiguo; prefiere su carril salvo que eso lo deje esperando más de media jornada.

### 3.2 Estado al 06/10/2026 y primeras tareas

Integrados en `main`: MS-00, MS-01 (TASK-021 a 042), TASK-043 y el motor ML base (TASK-074 a 077). En curso: TASK-044 (Alonso).

| Integrante | Primeras tareas (dependencias ya cumplidas) | Después |
| :-- | :-- | :-- |
| Alonso (A) | TASK-044 → 045 (esquema de atención) → 047 → 049 | 054, 056, 058, 059, 061 |
| Integrante B | TASK-046 (validador clínico) → 057 (cálculo del presupuesto) → 085 (base del portal) | Al integrarse 045: 064 → 065 → 066 → 067 (agenda) |
| Integrante C | TASK-051 (componente de odontograma, SPA) | 048, 050, 052, 053; luego 071 (pantallas de agenda) |

Ninguna de las primeras tareas de B y C depende de lo que Alonso tiene en curso, así que los tres pueden empezar el mismo día.

### 3.3 Una sola persona toca el esquema a la vez

Las migraciones (`database/migrations/`) son la mayor fuente de conflictos: dependen del orden. Regla:

- Las tareas de esquema (045, 054, 064, 078) **no** se hacen en paralelo entre sí.
- Antes de crear una migración, `git pull` de `main`: la fecha de la migración nueva debe ser posterior a la última integrada.
- Expandir y contraer van en PR distintos (Plan §1.5).

---

## 4. Ciclo de una tarea

### 4.1 Empezar

```bash
git switch main && git pull
git switch -c task/046-validador-clinico
```

Mover el issue a *En curso* y asignárselo.

### 4.2 Pedirle la tarea al agente

Usar siempre esta plantilla (rellenarla desde la fila de la tarea en el Plan §5):

```text
Tarea: TASK-046 — Validador clínico central (MS-02, carril B, service)
Lee: AGENTS.md; la fila TASK-046 de docs/Plan_Implementacion_DentiCore.md §5;
     solo las secciones del SDD y los IDs del SRS de su columna «Origen».
Pruebas: escribe primero las T-xxx asignadas (SDD §6.3) y confirma que fallan.
Hacer: <descripción accionable copiada del plan>
Criterios de aceptación: <copiados del plan>
Restricciones: solo lo descrito; sin tablas, rutas ni reglas nuevas; tenant_id nunca desde la
               solicitud; errores problem+json; reloj simulado; solo datos sintéticos.
Al terminar: ejecuta la suite completa del área y muéstrame el resultado. No hagas push.
```

Revisar lo que hizo el agente **antes** de cada commit (`git diff`). El agente propone; la persona responde por el código.

### 4.3 Mantener la rama al día (cada mañana)

```bash
git fetch origin
git rebase origin/main
```

### 4.4 Entregar

```bash
vendor/bin/pest            # o npm test / pytest, según el área
git add -A
git commit -m "TASK-046: valida piezas y superficies según la NTS 188"
git push -u origin task/046-validador-clinico
```

En GitHub: **Compare & pull request**, completar la plantilla, pedir revisión a otro integrante y mover el issue a *En revisión*.

### 4.5 Revisar el PR de otro

- Quién revisa: A revisa a B, B revisa a C, C revisa a A (o el que esté libre, pero nunca el autor).
- Leer el código, marcar las filas DoD y comprobar que la CI está en verde.
- Plazo: el mismo día. Un PR esperando revisión bloquea a todo el equipo.
- Si se piden cambios, el autor los hace en la misma rama y vuelve a pedir revisión.
- Integrar con **Squash and merge**. El issue pasa a *Hecho*.

---

## 5. Archivos compartidos: cómo no pisarse

| Archivo | Riesgo | Regla |
| :-- | :-- | :-- |
| `database/migrations/*` | Orden de ejecución | Ver §3.3. |
| `routes/api/<módulo>.php` | Bajo | Cada módulo tiene su archivo; no editar el de otro. |
| Matriz de autorización (`AUTH_MATRIX`) | Medio | Solo agregar filas al final del bloque del módulo; en conflicto, conservar ambas. |
| `openapi.json` | Alto (se regenera) | Nunca resolver a mano: tras el `rebase`, volver a generarlo y subirlo. |
| `composer.lock`, `package-lock.json`, `requirements*.txt` | Alto | Solo cambiarlos si la tarea lo pide; en conflicto, tomar el de `main` y reinstalar. |
| `denticore-spa/src/App.jsx` (rutas) | Medio | Agregar rutas, no reordenar las existentes. |
| `DatabaseSeeder` y semillas | Medio | Cada módulo agrega su propia clase de semilla y solo una línea al seeder principal. |
| `CLAUDE.md`, `AGENTS.md`, workflows de CI | Alto | Solo por PR dedicado, avisando al equipo. |

---

## 6. Si aparece un conflicto

```bash
git fetch origin
git rebase origin/main
# Git se detiene en el archivo en conflicto
git status                 # ver qué archivos chocan
# editar y dejar ambos cambios cuando corresponda (o regenerar openapi.json / lockfiles)
git add <archivo>
git rebase --continue
vendor/bin/pest            # comprobar que todo sigue en verde
git push --force-with-lease
```

- `--force-with-lease` solo en **tu** rama, nunca en `main` ni en la de otro.
- Si el conflicto no es obvio, no dejar que el agente «lo arregle» a ciegas: hablarlo con el autor del otro cambio.

---

## 7. Cierre de hito y evidencia para el informe

Cuando todas las tareas de un hito están en `main` y la CI está en verde:

```bash
git switch main && git pull
git tag ms-02
git push origin ms-02
```

Para el informe final (capítulos 8, 9 y 13), guardar al cerrar cada hito:

- Captura de **Insights → Network** (ramas) y de **Insights → Contributors** (commits por integrante).
- Enlaces a 2–3 PR representativos con su revisión aprobada.
- Captura de un run completo de GitHub Actions en verde.
- Defectos encontrados (issues con la etiqueta `defecto`).

---

## 8. Qué no hacer

- Commits a `main` o `push --force` a ramas ajenas.
- Hacer commits en nombre de otro integrante o cambiar el autor de commits. Si dos trabajan juntos, se agrega al final del mensaje `Co-authored-by: Nombre <correo-de-github>`.
- Pedirle al agente un hito completo o «implementa todo lo que falta».
- Dejar que el agente cree tablas, rutas o reglas que no están en el SDD.
- Subir datos reales, secretos o archivos `.env`.
- Ramas abiertas más de 3 días o PR sin revisar más de 1 día.
