# Informe de pruebas y aceptación de E1 (TCR-E1)

| Campo | Valor |
| :-- | :-- |
| Código | TCR-E1 (plantilla del anexo D de `docs/PlanDePruebas_DentiCore.md`) |
| Tarea | TASK-097 (MS-08) |
| Entrega | E1, curso, con datos sintéticos (RES-08) |
| Etiqueta | `e1.0` (API 1.0.0, motor de riesgo 1.0.0, contrato ML 1.0) |
| Fecha | 2026-10-17 |
| Regla aplicada | SRS §17.1 (Plan §6.5): una entrega se acepta solo si todos sus RF tienen sus pruebas en verde y todos sus RNF cumplen su criterio. Lo diferido se registra con su nueva entrega y la aprobación del dueño del producto. |
| Decisión | **ACEPTAR CON CONDICIONES** (§7) |

## 1. Alcance ejecutado y versiones probadas

**Alcance (SRS §17.2 y §17.4):**

| Tipo | Cantidad |
| :-- | --: |
| CUS | 50 |
| RF | 115 |
| RNF | 50 |
| RN de esos CUS (§14.5) | 64 |
| CA de §11 de esos CUS | 59 |

De los 78 CA del anexo C del plan de pruebas, 19 pertenecen a CUS de E2 y E3 (CUS-28, CUS-30, CUS-41 y CUS-64).

**Versión probada:** la rama `main` en la etiqueta `e1.0`. Las corridas de referencia de la CI están en GitHub Actions, con el identificador de cada corrida:

| Corrida (`RUN-id`) | Workflow | Commit | Resultado |
| :-- | :-- | :-- | :-- |
| ⟨pendiente: corridas de `main` en la etiqueta e1.0⟩ | api, spa, ml, e2e, traceability | | |
| 39016862496 | api (Pest, cobertura, SAST, secretos, OpenAPI) | `1cf970b` (cierre de MS-07) | ✅ |
| 39016862446 | e2e (matriz 4 × 4, k6 T-176, motor detenido) | `1cf970b` | ✅ |
| 39016862441 | spa (ESLint, Prettier, Vitest, build, auditoría) | `1cf970b` | ✅ |
| 39016862472 | traceability | `1cf970b` | ✅ |
| 39051823643 | api-mutation (corregido en TASK-097) | rama `task/097-informe-e1` | ⟨pendiente⟩ |

**Entorno:**
- runners de GitHub (`ubuntu-latest`) con PostgreSQL 16, Redis 7, MinIO, Mailpit y el motor ML del `docker-compose.yml`;
- sin staging ni entorno de referencia: se crean en MS-10 (PQ-04, supuesto S-04).

## 2. KPI del plan de pruebas (§2.4)

| KPI | Valor en E1 | Meta | Estado |
| :-- | :-- | :-- | :-- |
| Tasa de éxito de la suite | 100 % de las ejecutadas: Pest 1 723 de 1 723 (1 omitida), Vitest 247 de 247, pytest ⟨pendiente⟩, E2E 204 de 204 | 100 % | ✅ |
| Cobertura de RF | 115 de 115 | 100 % | ✅ |
| Cobertura de CA | 59 de 59 de los CUS de E1 | 100 % | ✅ |
| Trazabilidad de RN | 64 de 64 | 100 % | ✅ |
| Densidad de defectos | 9 defectos en la verificación de E1 (§4) sobre 115 RF: 0,08 por RF | Tendencia decreciente | Línea base |
| Reapertura de defectos | 0 de 9 | ≤ 10 % | ✅ |
| Retención de mutación (MSI) | ⟨pendiente⟩ | ≥ 70 % (bloquea desde E3) | Informativo |

**La prueba omitida** es la del antivirus con EICAR (`FileStorageTest`). Se omite sola cuando ClamAV no responde, y la CI no lo levanta para no sumar 5 minutos de arranque. En local pasa. Su RNF (RNF-103) es de E2.

**Sobre las E2E:**
- La matriz completa tiene 60 casos por navegador (15 por ancho), en 4 navegadores.
- En cada navegador se omiten 9 casos a propósito:
  - 8 son pantallas del personal a 360 px, que pide desde 768 px (RNF-052);
  - 1 es el caso del motor detenido a 360 px.

## 3. Resultados por nivel y por tipo de prueba

| Nivel o tipo | Herramienta | Resultado | Evidencia |
| :-- | :-- | :-- | :-- |
| Estático | Pint, Larastan nivel 6, ESLint, Prettier, ruff, mypy estricto, SonarCloud | 0 errores; puerta de SonarCloud en verde en cada PR | api, spa y ml en cada PR |
| Unitario e integración (API) | Pest con PostgreSQL 16 y RLS | 1 723 pruebas en verde en 182 s | RUN 39016862496 |
| Arquitectura | Pest `arch()` (T-158 a T-161) | Sin violaciones | Incluida en Pest |
| Aislamiento entre clínicas | T-014 a T-018, T-160 sobre todas las rutas y *jobs* de E1 | 0 fugas | Incluida en Pest (TASK-093) |
| Autorización | `AUTH_MATRIX` (T-019) | Toda ruta registrada en la matriz; ningún rol denegado recibe 2xx | Incluida en Pest |
| Bitácora | T-149 y T-150 | Cada evento emitido en E1 se registra sin valores clínicos | Incluida en Pest |
| Contrato OpenAPI | T-162 y el documento exportado comparado en la CI | Sin diferencias (OpenAPI 3.1, 119 rutas) | api |
| Contrato ML | T-163 y pytest del motor | En verde | ml |
| Frontend | Vitest y React Testing Library | 247 pruebas en 42 archivos | spa |
| E2E y accesibilidad | Playwright con axe-core (WCAG 2.1 AA) | Chrome, Edge, Firefox y WebKit a 360, 768, 1280 y 1920 px; Chrome para Android emulado (Pixel 7) ⟨pendiente⟩ | RUN 39016862446 |
| Concurrencia (PC-E, T-176) | k6 0.54 | 50 reservas simultáneas del mismo horario: 1 creada, 49 conflictos (409), 0 errores; p95 2,08 s | Artefacto `load-results/pc-e-summary.json` |
| Tolerancia a fallos | E2E `@motor-detenido` | Con el motor detenido, la atención sigue editable y la pantalla dice «Predicción no disponible» | RUN 39016862446 |
| Seguridad | Semgrep (reglas propias), gitleaks, `composer audit`, `npm audit` y `pip-audit` | 0 hallazgos SAST, 0 secretos, 0 vulnerabilidades conocidas | api, spa y ml |
| Mutación (nocturna) | Pest `--mutate` sobre los 5 servicios críticos | ⟨pendiente⟩ | RUN 39051823643 |
| Carga de desempeño (PC-N, PC-P) | k6 sobre el entorno de referencia | No ejecutada: diferida por R-03 (§6) | — |

**Cobertura de código (RNF-126):**

| Componente | Cobertura | Umbral |
| :-- | --: | --: |
| Backend (líneas) | 98,3 % | 80 % |
| Aislamiento (`app/Support/Tenancy`) | 98,1 % | 95 % |
| Odontograma | 99,3 % | 95 % |
| Presupuestos | 99,1 % | 95 % |
| Citas | 98,0 % | 95 % |
| Riesgo | 98,7 % | 95 % |
| SPA (líneas) | 90,6 % | 70 % |
| Motor ML | ⟨pendiente⟩ | 80 % |

**Tiempo de las suites** (OQ-10; RNF-129 es de E3):
- La suite de la API (Pest con cobertura) tarda 3 min 46 s, con un máximo de 15 min.
- Cada job E2E por navegador tarda de 4 min 46 s a 6 min 23 s, con un máximo de 20 min.

## 4. Defectos

Registro de los defectos encontrados por las pruebas de la verificación de E1 (MS-07 y MS-08). La severidad sigue el §2.3 del plan de pruebas. No quedan defectos críticos ni altos abiertos (RNF-073).

| ID | Defecto | Requisito | Severidad | Corrección | Estado |
| :-- | :-- | :-- | :-- | :-- | :-- |
| DEF-001 | Un *job* del outbox buscaba su mensaje solo por uuid; un *job* de una clínica podía procesar el mensaje (un correo) de otra. | RNF-101, RN-01 | Crítica | TASK-093 (#91): `OutboxJob` exige que el mensaje sea de la clínica del *job*; T-016 lo cubre. | Cerrado |
| DEF-002 | Los registros técnicos guardaban correos, celulares y DNI dentro del texto de los mensajes y de las excepciones, y el canal `monthly` no redactaba. | RNF-110 | Alta | TASK-092 (#92): `RedactPersonalData` enmascara el texto libre y las excepciones en todos los canales; T-164 recorre una corrida sintética completa. | Cerrado |
| DEF-003 | A 768 px se desplazaba en horizontal la página entera, no solo la tabla. | RNF-052 | Media | TASK-094 (#94): `.table-wrap` con `position: relative` y `fieldset { min-width: 0 }`. | Cerrado |
| DEF-004 | En Firefox a 360 px, el inicio de sesión se desplazaba 1 px en horizontal. | RNF-051, RNF-052 | Media | TASK-094 (#94): `min-w-0` en el contenedor. | Cerrado |
| DEF-005 | Las tablas con desplazamiento horizontal no se podían recorrer con el teclado (axe `scrollable-region-focusable`). | WCAG 2.1.1 | Media | TASK-094 (#94): `TableWrap` (grupo con nombre y foco). | Cerrado |
| DEF-006 | Dos reglas de validación (`multiple_of`, `prohibited_if`) devolvían su mensaje en inglés. | RNF-188 | Baja | TASK-094 (#94): traducción y una prueba que recorre todas las reglas usadas por la API. | Cerrado |
| DEF-007 | La mutación nocturna nunca midió el MSI: `--parallel` fallaba al crear las bases (el rol de la API no tiene CREATEDB) y `continue-on-error` lo ocultaba. Antes tampoco tenía Redis ni Mailpit. | RNF-127 | Media | TASK-095 (#95) agregó los servicios y TASK-097 corre cada servicio crítico en serie, sin ocultar el fallo. | Cerrado |
| DEF-008 | Faltaba el aviso de RNF-146 cuando el usuario tiene abierto otro paciente en otra pestaña. | RNF-146 | Media | TASK-098: aviso en la franja del paciente (BroadcastChannel). | ⟨pendiente⟩ |
| DEF-009 | Ocho badges de estado mostraban texto sin ícono. Cumplían RNF-061 por el texto, no la regla de diseño «texto e ícono». | RNF-061, DESIGN.md | Baja | TASK-098: `StatusBadge` con ícono. | ⟨pendiente⟩ |

**Causa raíz de DEF-001:**
- `OutboxJob` confiaba en que el *job* solo llegara con el uuid de su propia clínica, y no lo comprobaba.
- No era explotable desde la API: hacía falta un *job* encolado con un uuid ajeno.
- **Acción:** la verificación quedó en la clase base, y T-016 prueba cada *job* de E1 con un mensaje de otra clínica.

## 5. RNF de E1: cumplimiento y evidencia

**Estados:**
- ✅ cumplido;
- 🟨 cumplido en E1 con una parte condicionada (§6);
- ⏸️ diferido por R-03 con aprobación (§6);
- ⚠️ desviación aceptada (§6).

El detalle de las pruebas de cada ID está en el anexo `trazabilidad-e1.md`.

| RNF | Criterio (SRS §13) | Estado | Evidencia |
| :-- | :-- | :-- | :-- |
| RNF-001 | Importes con aritmética decimal exacta | ✅ | Propiedades con 10 000 casos (`MoneyTest`, `BudgetCalculatorTest`) |
| RNF-002 | Fechas y plazos de negocio sin error | ✅ | Casos límite con reloj simulado (`LegalRepresentativeTest` y otras) |
| RNF-003 | 364 combinaciones de pieza y superficie | ✅ | `ClinicalValidatorTest`, `ClinicalValidatorDatabaseTest`, `SqlHelperObjectsTest` |
| RNF-004 | Sigla, color y posición del anexo NTS 188 | 🟨 | El catálogo sembrado se verifica entero (`NtsCatalogSeedTest`). La firma del cirujano dentista es de E2 (DE-04, TASK-117). |
| RNF-005 | 100 % de los RF y CA con prueba | ✅ | Workflow `traceability`: 115 RF y 59 CA |
| RNF-006 | Lecturas p95 ≤ 300 ms bajo PC-N | ⏸️ | R-03: T-174 en MS-10 (TASK-116) |
| RNF-007 | Listados y búsquedas p95 ≤ 500 ms bajo PC-N | 🟨 | Con 20 000 pacientes, la búsqueda usa el índice trigram sin barrido secuencial (`PatientSearchTest`, EXPLAIN). El p95 bajo PC-N se difiere con R-03 (TASK-116). |
| RNF-008 | Escrituras simples p95 ≤ 600 ms | ⏸️ | R-03: T-174 en MS-10 |
| RNF-011 | Presupuestos: recálculo ≤ 500 ms y emisión ≤ 2 s | ⏸️ | R-03: T-178 en MS-10 |
| RNF-013 | Predicción ≤ 3 s y «no disponible» ≤ 3,3 s | ⏸️ | R-03: T-179 en MS-10. El corte de 3 s se prueba en T-127 (CA-55.3) y en el E2E `@motor-detenido`. |
| RNF-016 | Reserva p95 ≤ 800 ms | ⏸️ | R-03: T-174 en MS-10. La exclusión se prueba en T-112 y T-176. |
| RNF-035 | Pico de una clínica (PC-P) ≤ +20 % | ⏸️ | R-03: T-175 en MS-10 |
| RNF-038 | Integridad bajo concurrencia (PC-E) | ✅ | T-176 (k6): 1 creada, 49 conflictos, 0 errores. Los abonos simultáneos son de E3 (pagos). |
| RNF-046 | ISO 8601, importes como cadena decimal, UTF-8 | ✅ | `OpenApiTest`, `MoneyTest` |
| RNF-051 | Chrome, Edge y Firefox; el portal además en Safari y Chrome para Android | 🟨 | Matriz E2E en Chromium, Edge, Firefox y WebKit; Android emulado (Pixel 7) ⟨pendiente⟩. Ni un dispositivo Android real ni la versión estable anterior de cada navegador entran en la CI. |
| RNF-052 | Personal 768–1920 px y portal desde 360 px, sin desplazamiento horizontal | ✅ | `auditScreen` en cada pantalla E2E de los 4 anchos, más `TableWrap` |
| RNF-061 | Estados y hallazgos no solo por color | ✅ | Texto o sigla en todos (`attention.spec.ts`, `expectStatesNotOnlyByColor`). DEF-009 completa el ícono. |
| RNF-063 | Confirmación de las acciones irreversibles | ✅ | Emitir presupuesto, cerrar atención, corregir un hallazgo y cancelar una cita (`Budget.test.jsx` y E2E). Anular abono, revocar, fusionar y eliminar la HC no existen en E1. |
| RNF-064 | Errores de validación en español junto al campo | ✅ | `schemas.test.js`, esquemas generados del OpenAPI |
| RNF-074 | Atención continua con el motor ML caído | ✅ | E2E `@motor-detenido` (`risk.spec.ts`) |
| RNF-079 | Idempotencia de las operaciones críticas | ✅ | `IdempotencyTest` |
| RNF-091 | Cifrado en reposo | 🟨 | Campos sensibles con AES-256-GCM y clave por clínica (`VersionedEncryptionTest`, `SecurityTest`). El cifrado de volúmenes, respaldos y objetos depende del proveedor (MS-10: TASK-111 y TASK-113). |
| RNF-093 | bcrypt con costo ≥ 12 | ✅ | `PasswordPolicyTest`, `SecurityTest` |
| RNF-094 | Tokens de ≥ 128 bits, solo el hash en el servidor y `sessionStorage` | ✅ | `SecurityTest`, `tokenStorage.test.js` |
| RNF-096 | CORS sin comodín | ✅ | `HttpContractTest`, `SecurityTest` |
| RNF-100 | 0 secretos en el repositorio; rotación cada 90 días | 🟨 | gitleaks en cada cambio, sin hallazgos. En E1 no hay credenciales de servicio que rotar: solo existen las locales y las de la CI, con datos sintéticos. El gestor de secretos con rotación llega con los entornos (TASK-111). |
| RNF-101 | Aislamiento entre clínicas | ✅ | T-014 a T-018 y T-160 (12 archivos de prueba) |
| RNF-104 | Sin inyección de código | ✅ | Regla Semgrep `raw-sql-with-interpolation`; `security.test.js` (la SPA no inserta HTML) |
| RNF-110 | Registros sin datos personales | ✅ | `LogPrivacyTest` sobre una corrida sintética completa (DEF-002) |
| RNF-119 | Arquitectura por capas | ✅ | `LayersTest` |
| RNF-121 | Validación clínica centralizada | ✅ | `ClinicalValidationTest` |
| RNF-122 | Análisis estático sin errores | ✅ | Larastan 6, ESLint, ruff y mypy estricto bloquean el PR |
| RNF-123 | Estilo uniforme | ✅ | Pint, Prettier y ruff format bloquean el PR |
| RNF-126 | Cobertura | ✅ | §3 |
| RNF-128 | Cada RN y CA con prueba | ✅ | Workflow `traceability`: 64 RN y 59 CA |
| RNF-130 | Reloj simulado y 0 esperas reales | ✅ | `TimeTest` (`arch()`), `EvidenceTest` y otras |
| RNF-135 | Cambios por PR con ≥ 1 revisor y CI en verde | ⚠️ | Todos los cambios entraron por PR con la CI y SonarCloud en verde, pero se integraron con `--admin`, sin un segundo revisor. Además, `main` solo exige el check `e2e`. |
| RNF-146 | Identidad del paciente visible y aviso de otra pestaña | ⟨pendiente⟩ | La franja `PatientHeader` está en toda pantalla clínica. El aviso de otra pestaña llega con DEF-008. |
| RNF-147 | La IA o el modelo no escriben datos clínicos | ✅ | `PredictTest`: tras una predicción, las tablas clínicas no cambian |
| RNF-149 | Aviso de alergias antes de actuar | ✅ | `MedicalHistoryTest` y la franja del paciente |
| RNF-151 | Solo simbología oficial | ✅ | `FindingCatalogSchemaTest`, `NtsCatalogSeedTest` |
| RNF-153 | Origen y autor de cada dato clínico | ✅ | `ClinicalRecordTest`. Las entradas de IA son de E3. |
| RNF-165 | AUC-ROC ≥ 0,75 | ✅ | AUC de validación 0,776 (`test_training.py`; ficha `model_card_1.0.0.md`) |
| RNF-168 | SHAP + base = salida con error ≤ 1e-6 | ✅ | `test_shap.py`, `MlContractTest` |
| RNF-169 | Determinismo en 1 000 repeticiones | ✅ | `test_determinism.py` |
| RNF-174 | Variables fuera de dominio → 422 | ✅ | `RiskVariableTest`, `RiskEngineClientTest` y otras |
| RNF-188 | Todo en español del Perú | ✅ | `ValidationMessagesTest`; revisión E2E de pantallas y correos (`expectSpanishText`) |
| RNF-189 | `dd/mm/aaaa`, 24 h y `S/ 1,234.56` | ✅ | `format.test.js` |
| RNF-191 | Nombres peruanos completos, también en los PDF | ✅ | `RegisterPatientTest`, `PatientSearchTest`. Los PDF usan DejaVu Sans incrustada (Latin Extended). |
| RNF-192 | Integridad en la base de datos | ✅ | `SchemaIntegrityTest`: FK salvo 12 excepciones justificadas, más de 50 CHECK e inmutabilidad por disparador |

**Resumen:**

| Estado | RNF |
| :-- | --: |
| ✅ Cumplidos | ⟨pendiente⟩ |
| 🟨 Cumplidos con condición | ⟨pendiente⟩ |
| ⏸️ Diferidos por R-03 | 6 |
| ⚠️ Desviación aceptada | 1 |

## 6. Desviaciones respecto del plan y condiciones

| # | Desviación | Justificación | Nueva entrega | Aprobación |
| :-: | :-- | :-- | :-- | :-- |
| C-01 | Desempeño bajo PC-N y PC-P sin medir: RNF-006, 008, 011, 013, 016 y 035, y el p95 de RNF-007 | Recorte R-03 (MS-04, regla de decisión del Plan §4.6). Sin el entorno de referencia ni el CDR (PQ-04, S-04), la medición no sería comparable con el criterio. | MS-10: TASK-072 (CDR) y TASK-116 | Dueño del producto, 2026-10-17 |
| C-02 | Cifrado de volúmenes, respaldos y objetos (RNF-091) y rotación de credenciales (RNF-100) | Dependen del proveedor de nube y del gestor de secretos, que aún no existen; en E1 no hay credenciales de servicio reales. | MS-10: TASK-111 y TASK-113 | Dueño del producto, 2026-10-17 |
| C-03 | Firma del cirujano dentista sobre el catálogo NTS 188 (RNF-004) | La firma se exige en E2 (DE-04, PQ-05); E1 cierra con el catálogo verificado por prueba. | MS-10: TASK-117 | Plan §7.3 (DE-04) |
| C-04 | RNF-135: integración con `--admin`, sin un segundo revisor | Decisión del equipo para los hitos MS-03 a MS-08, con la CI y SonarCloud en verde como condición de cada integración. | Se vuelve a decidir al iniciar E2 | Dueño del producto, 2026-10-17 |
| C-05 | Chrome para Android emulado, no en un dispositivo real; sin la versión estable anterior de cada navegador (RNF-051) | La CI usa la versión actual de cada motor de Playwright. | Prueba en dispositivo en E3 (portal completo, MS-13) | Dueño del producto, 2026-10-17 |
| C-06 | Sin staging: la demostración de TASK-098 corre en el entorno local | Staging se aprovisiona en MS-10 (TASK-111). | MS-10 | Dueño del producto, 2026-10-17 |
| C-07 | La guía de instalación debe verificarla otro integrante (RNF-141, *Should*, de E3) | La corrida automatizada en un clon limpio funcionó (`docs/e1/verificacion-instalacion.md`); falta la del compañero. | MS-08 | ⟨pendiente⟩ |

**Fuera de E1:** RNF-127 (MSI ≥ 70 %) es *Should*. Su valor se informa (§3) y bloquea la liberación desde E3 (Plan §6.1).

## 7. Decisión

**ACEPTAR CON CONDICIONES.**
- Los 115 RF de E1 tienen sus pruebas en verde.
- ⟨pendiente⟩ de los 50 RNF cumplen su criterio, y los demás tienen su condición registrada en §6, con la nueva entrega y la aprobación del dueño del producto (Plan §6.5).
- No quedan defectos críticos ni altos abiertos.
- Ningún dato real entra a la plataforma antes de aceptar E2 (SRS §17.1).

| Rol | Nombre | Firma | Fecha |
| :-- | :-- | :-- | :-- |
| Líder de QA y del proyecto | Blas Puente Juancito Alexis | | |
| Integrante | Carvo Mendez Orlando Alexander | | |
| Integrante | Sánchez Ramos Carlos Alonso | | |
| Docente | Mg. Maglioni Arana Caparachin | | |

## Anexos

- `docs/e1/trazabilidad-e1.md`: cada RF, RN, CA y RNF de E1 con todas sus pruebas. Lo genera `node scripts/traceability/e1.mjs --anexo`.
- `docs/e1/verificacion-instalacion.md`: verificación de la guía de instalación.
- `docs/e1/migraciones.md`: registro de migraciones expandir/contraer.
- `denticore-ml/docs/model_card_1.0.0.md`: ficha técnica provisional del modelo.
- GitHub Release `e1.0`: el OpenAPI generado por la CI, este informe y la ficha del modelo.
