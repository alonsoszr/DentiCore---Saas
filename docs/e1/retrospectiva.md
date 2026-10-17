# Retrospectiva de E1 y velocidad medida

TASK-098, criterio 2: la velocidad real (dp por semana) queda registrada para volver a planificar E2 con la regla de decisión del Plan §4.6.

## 1. Velocidad medida

**Fuente:** los commits de `main` (`git log`) y los dp de cada TASK de las tablas del Plan §5.

**Fecha de cierre de una tarea:** la de su último commit integrado. No cuentan los que solo corrigen defectos de una revisión posterior.

**Tareas sin ID:** las de MS-00 (TASK-003 a TASK-020) entraron en commits llamados «MS-00» entre el 2026-09-28 y el 2026-10-05; se cuentan cerradas el 2026-10-05.

| Hito | Tareas | dp del Plan | Cerrado entre | Estado |
| :-- | --: | --: | :-- | :-- |
| MS-00 Andamiaje | 20 | 21,25 | 2026-09-28 y 2026-10-09 | Cerrado |
| MS-01 Fundamentos | 22 | 26,5 | 2026-10-05 y 2026-10-06 | Cerrado |
| MS-02 Atención y odontograma | 11 | 15,25 | 2026-10-06 | Cerrado |
| MS-03 Plan y presupuesto | 10 | 14,25 | 2026-10-06 y 2026-10-10 | Cerrado |
| MS-04 Agenda | 9 de 10 | 11,5 de 13 | 2026-10-10 y 2026-10-11 | Cerrado con R-03: TASK-072 (CDR, 1,5 dp) pasa a MS-10 |
| MS-05 Riesgo de caries | 11 | 13 | 2026-10-05 y 2026-10-12 | Cerrado |
| MS-06 Portal mínimo | 6 | 6,25 | 2026-10-16 | Cerrado |
| MS-07 Verificación | 5 | 7 | 2026-10-16 y 2026-10-17 | Cerrado |
| MS-08 Cierre | 3 | 3 | 2026-10-17 | Cerrado con este PR |
| **E1** | **97 de 98** | **118 de 119,5** | **2026-09-28 a 2026-10-17** | |

**Resultado:** **118 dp en 20 días (2,9 semanas), unos 41 dp por semana.**

**Contra el plan:**
- El Plan supuso 4 dp por persona y semana con 3 personas, es decir 12 dp por semana (supuesto S-01).
- Con eso, E1 ocupaba S3 a S12, unas 10 semanas.
- La velocidad medida es unas 3,4 veces la planificada.

**Cómo se trabajó:**
- Casi todo E1 se construyó con un agente de codificación (Claude Code) que trabajó una tarea por vez, con las pruebas primero, la CI y SonarCloud en verde como condición de integración, y una persona que dirigió, decidió los vacíos y aprobó cada hito.
- En el periodo hay 164 commits en `main` (por fecha de autor): 94 de la cuenta `A7000`, 69 de `Alonso` y 1 de `Juancito2004A`.

**La velocidad no fue pareja:**

| Semana (lunes) | Commits | Contenido |
| :-- | --: | :-- |
| 2026-09-28 | 4 | MS-00: andamiaje y reconciliación |
| 2026-10-05 | 131 | MS-00 a MS-05: casi todo el alcance funcional |
| 2026-10-12 | 29 | MS-05 a MS-08: portal, verificación y cierre |

**Para volver a planificar E2 (Plan §4.6):**
- No conviene usar 41 dp por semana como capacidad estable.
- La semana del 2026-10-05 concentró la construcción y la del 2026-10-12, la verificación. En esta última, cada hallazgo exigió corregir y volver a correr la matriz E2E completa de 4 × 4 (más de 20 min por corrida).
- E2 trae trabajo que el agente no acelera: staging y producción (PQ-04), el simulacro de respaldos, la firma del validador clínico (PQ-05) y los contratos legales.
- **Propuesta:** planificar E2 con la velocidad de la semana de verificación (24,5 dp cerrados en la semana del 2026-10-12) para las tareas de código, y con el calendario de los terceros para las dependencias externas (DE-01, DE-02 y DE-04).

## 2. Lo que salió bien, lo que salió mal y qué se cambia

⟨pendiente: los puntos del equipo⟩

| Tema | Salió bien | Salió mal | Qué se cambia en E2 |
| :-- | :-- | :-- | :-- |
| | | | |

## 3. Datos que deja la verificación de E1

Son hechos para la discusión, con su evidencia en el TCR-E1:
- **Defectos:** la verificación (MS-07 y MS-08) encontró 9 (DEF-001 a DEF-009). Uno era crítico: un *job* del outbox podía procesar el mensaje de otra clínica. Lo encontraron las pruebas de aislamiento ampliadas, no una persona.
- **Controles que no funcionaban:**
  - la mutación nocturna informó «success» durante todo E1 sin medir nada (DEF-007);
  - la protección de `main` solo exige el check `e2e` (C-04).
- **RNF-146:** el SDD no diseñó el aviso de otra pestaña. La trazabilidad lo daba por cubierto porque una prueba citaba el ID por otra parte del criterio (DEF-008). Que una prueba cite el ID no prueba que cubra todo el criterio.
- **Entorno:**
  - el límite de descargas anónimas de Docker Hub hizo fallar la CI (429) y obligó a relanzarla;
  - la guía de instalación tarda 2 h en un disco duro mecánico y unos 15 min en un SSD.
- **Lo diferido a MS-10 por R-03 y por falta de entorno** (TCR-E1, condiciones C-01 a C-03 y C-06):
  - el desempeño bajo carga;
  - el cifrado de la infraestructura;
  - la rotación de credenciales;
  - la firma clínica;
  - staging.
