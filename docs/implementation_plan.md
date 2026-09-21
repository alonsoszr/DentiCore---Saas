# Plan de Implementación: DentiCore

> Documento derivado exclusivamente de `functional_specs.md` y `technical_specs.md` (a su vez derivados de `SDD_DentiCore.md`). No introduce tecnologías, endpoints, tablas, reglas de negocio ni decisiones de producto que no estén ya definidas en esos documentos. Donde el SDD no especifica algo necesario para implementar, se deja explícitamente marcado en la §9 ("Vacíos del SDD") en lugar de asumirlo.

## 0. Principio de ordenamiento

Las fases están ordenadas por **dependencia de datos** (claves foráneas del esquema, `technical_specs.md §3.20`) y por **dependencia arquitectónica** (capas `Controllers → Form Requests → Services → Models/Repositories`, `technical_specs.md §2.1`). No se puede construir un módulo antes que las tablas/servicios de los que depende vía FK.

Cadena de dependencias de datos (de raíz a hojas):

```
tenants
 ├─ encryption_keys
 └─ users
     └─ patients
         ├─ patient_risk_variables
         ├─ odontogram_teeth
         ├─ treatment_catalog ─ treatments
         ├─ budgets ─ budget_items
         ├─ appointments
         ├─ ai_diagnosis_suggestions
         ├─ ai_treatment_suggestions
         ├─ risk_predictions ─ risk_alerts
         ├─ clinical_followups
         └─ satisfaction_surveys
audit_logs, performance_alerts (dependen de tenant_id/user_id nullable, sin bloquear otras fases)
```

Cada fase indica: objetivo, prerrequisitos, entregables técnicos (con referencia a la sección exacta del spec de donde provienen), endpoints incluidos, pruebas a implementar (nombres tomados literalmente de `technical_specs.md §7`, no se inventan pruebas nuevas) y criterio de aceptación.

---

## Fase 0 — Configuración base del proyecto

**Objetivo:** dejar el esqueleto de ambos repos (backend y frontend) y la infraestructura mínima operativa, según el stack de `technical_specs.md §1`.

**Prerrequisitos:** ninguno.

**Entregables técnicos:**
- Backend: proyecto Laravel 13.x (PHP 8.3) nuevo, repositorio independiente del frontend (`technical_specs.md §2.1`). *(Desviación deliberada de versión respecto al SDD original — Laravel 11 está fuera de soporte de seguridad; ver nota al inicio de `technical_specs.md`.)*
- Frontend: proyecto React 18 (SPA) con React Router 7, Axios y TanStack Query, repositorio independiente (`technical_specs.md §1`). *(react-router-dom actualizado a v7 por CVEs sin parche en la rama 6.x.)*
- Base de datos PostgreSQL 16 provisionada.
- Redis provisionado (cache/colas, `technical_specs.md §1`; en desarrollo local sobre Windows se usa Memurai, compatible con el protocolo Redis, ya que no existe build oficial de Redis para Windows).
- Instalación y configuración de Laravel Sanctum (Bearer tokens, `technical_specs.md §2.2`).
- Configuración de prefijo global de rutas API `/api/v1` (`technical_specs.md §5`).
- Estructura de carpetas por capas: `Controllers/`, `Http/Requests/` (Form Requests), `Services/` (incluye subcarpeta `Services/Ml/` reservada para el cliente ML), `Models/` (`technical_specs.md §2.1`).
- Configuración de conexión PostgreSQL con soporte `jsonb`, `uuid`, `enum` nativo (`technical_specs.md §2.2`).

**Estado: completado (2026-09-21).** Verificado end-to-end: `php artisan migrate` corrió contra PostgreSQL (`denticore`), cache Redis confirmado (`Cache::put`/`get` vía `predis`, verificado también con `memurai-cli`), `GET /up` → 200, `GET /api/v1/user` sin token → 401 JSON (confirma prefijo `/api/v1` y middleware `auth:sanctum` operativos), build y dev server del frontend verificados.

**Pruebas:** ninguna prevista en el spec para esta fase (es configuración de infraestructura).

**Criterio de aceptación:** el backend responde en `/api/v1` y el frontend puede levantar una SPA vacía apuntando a él; conexión a PostgreSQL y Redis verificada. ✅

---

## Fase 1 — Fundamento multi-tenant (crítico, bloqueante para todo lo demás)

**Objetivo:** implementar el mecanismo de aislamiento entre clínicas, que según `technical_specs.md §2.3` es de "tolerancia cero" y condiciona toda tabla y query posterior.

**Prerrequisitos:** Fase 0.

**Entregables técnicos** (`technical_specs.md §2.3`, `§3.1`–`§3.3`):
- Migración tabla `tenants` (§3.1: `id`, `uuid` UNIQUE default `gen_random_uuid()`, `name`, `subscription_plan` enum('basic','pro','enterprise'), `status` enum('active','suspended','cancelled') default 'active', `settings` jsonb).
- Migración tabla `encryption_keys` (§3.2: `tenant_id` FK UNIQUE NOT NULL, `key_ciphertext` text, `rotated_at`, `is_active` boolean default true).
- Migración tabla `users` (§3.3: `uuid` UNIQUE, `tenant_id` FK nullable solo si `role='super_admin'`, `name`, `email`, `password` bcrypt, `role` enum('super_admin','clinic_admin','dentist','receptionist','patient'), `is_active`; índice UNIQUE(tenant_id, email) — email único por clínica, no global; índice sobre `role`).
- Trait `BelongsToTenant` aplicado a todos los modelos multi-tenant: Global Scope `WHERE tenant_id = <current_tenant>` + asignación automática de `tenant_id` en el evento `creating` (§2.3 punto 2).
- Middleware `ResolveTenant`: resuelve tenant desde `auth()->user()->tenant_id` y lo fija en `app()->instance('currentTenant', $tenant)` al inicio de cada request (§2.3 punto 3).
- Mecanismo de claves de cifrado por clínica: lectura de la clave activa en `encryption_keys` del tenant actual (§2.3 punto 4; el uso concreto sobre campos sensibles se implementa en la Fase 3 cuando existan esos campos).
- Método explícito `withoutTenantScope()` disponible solo para flujos de `super_admin` (§2.3 punto 5).
- `AuthController`: `POST /auth/login`, `POST /auth/logout` (`auth:sanctum`), `GET /auth/me` (`auth:sanctum`) — `technical_specs.md §5.1`.
- `TenantController`: `POST /tenants` y `GET /tenants`, ambos con `EnsureRole:super_admin` (§5.1).
- Middleware `EnsureRole:role1,role2` (autorización gruesa a nivel de ruta, §4.2).

**Endpoints incluidos:** `/auth/login`, `/auth/logout`, `/auth/me`, `/tenants` (POST/GET) — `technical_specs.md §5.1`.

**Pruebas** (`technical_specs.md §7.1`):
- `test_user_cannot_access_patient_from_another_tenant()` *(placeholder hasta que exista el modelo `Patient` en Fase 3; puede diferirse su ejecución completa a esa fase, pero el Global Scope debe quedar listo aquí)*.
- `test_global_scope_filters_all_queries_by_tenant()`.
- `test_tenant_id_is_never_accepted_from_request_payload()`.
- `test_super_admin_can_bypass_tenant_scope_only_explicitly()`.

**Criterio de aceptación:** `functional_specs.md §4.1` ("Aislamiento total entre clínicas") — ninguna query retorna datos de un tenant distinto al de la sesión activa; el `tenant_id` nunca se acepta desde el payload del cliente (`technical_specs.md §6.4`).

**Estado: completado (2026-09-21).** Desviaciones y hallazgos técnicos respecto al diseño original de esta fase (todos documentados en `technical_specs.md`, nota al inicio del documento):
- **`users` NO lleva el trait `BelongsToTenant`/Global Scope.** Se descubrió que Sanctum resuelve el usuario dueño de un token *antes* de que exista un tenant activo en el contenedor; un Global Scope "deniega por defecto sin tenant" sobre `users` rompe la autenticación por completo (confirmado con un test que reproducía 401 en todo request con Bearer token válido). El mecanismo se demuestra y prueba sobre `EncryptionKey` en su lugar, modelo de datos de clínica real que si lleva el trait.
- **`tenants.slug`** agregado (no estaba en el SDD): necesario para que `/auth/login` identifique la clínica, dado que `email` es único por clínica y no global.
- **Índice único parcial** en `users(email) WHERE tenant_id IS NULL`: cierra un hueco real de `UNIQUE(tenant_id, email)` en Postgres (NULL nunca es igual a NULL).
- **`TenantScope` deniega todo por defecto** cuando no hay tenant resuelto, en vez de no filtrar — refuerza la tolerancia cero de forma segura ante fallos.
- **Bug real corregido:** los defaults a nivel de columna (`gen_random_uuid()`, `status` default `'active'`, `is_active` default `true`) no se reflejan en el modelo Eloquent en memoria inmediatamente tras `create()` (solo en la fila real), lo que causaba `uuid`/`status` en `null` en la respuesta HTTP justo después de crear un registro. Se corrigió generando esos valores en PHP antes del insert (trait `HasUuid`, `protected $attributes` en los modelos).
- **Pruebas ejecutadas:** 13 tests / 24 aserciones, todas en verde, contra una base de datos de pruebas Postgres dedicada (`denticore_testing`, configurada en `phpunit.xml` — el `sqlite :memory:` por defecto de Laravel es incompatible con `gen_random_uuid()`/`jsonb`). Incluye las 3 pruebas de `§7.1`, más pruebas de `AuthController` y `TenantController` (esta última cubre `test_only_super_admin_can_register_tenant` de `§7.6`, adelantada desde Fase 2 porque el controlador ya existe aquí).
- Verificado también manualmente por HTTP real: login de `super_admin` y de usuario de clínica (con `tenant_slug`), creación y listado de tenants, rechazo 403 a un `dentist` intentando crear un tenant.

---

## Fase 2 — RBAC y gestión de usuarios de clínica

**Objetivo:** habilitar la administración de personal por clínica y la autorización fina por registro.

**Prerrequisitos:** Fase 1.

**Entregables técnicos** (`technical_specs.md §4`, `§5.2`):
- `UserController`: `GET /users`, `POST /users`, `PATCH /users/{user}`, todos con `EnsureRole:clinic_admin` (§5.2).
- Policies por modelo, comenzando con las que apliquen a lo ya existente; el patrón general (`PatientPolicy`, `OdontogramPolicy`, `BudgetPolicy`, `RiskPredictionPolicy`) se completa a medida que existan esos modelos en fases posteriores (§4.2). Regla fina: `patient` solo accede a registros con `patient.user_id = auth()->id()`.
- Frontend: componente `<RequireRole allow={[...]}>` para guards de ruta, leyendo el rol del token (§4.2). Explícitamente documentado como UX — no sustituye autorización backend.

**Endpoints incluidos:** `/users` (GET/POST), `/users/{user}` (PATCH) — §5.2.

**Pruebas** (`technical_specs.md §7.6`, aplicable a lo ya construido):
- `test_only_super_admin_can_register_tenant()` ya cubierta en Fase 1 (`TenantController` se construyó ahí).
- El resto de `§7.6` (`test_receptionist_cannot_update_odontogram`, `test_patient_can_only_view_own_records`, `test_ml_callback_requires_valid_service_key`) se implementa cuando existan los módulos correspondientes (Fases 4, 3, 9).

**Criterio de aceptación:** `functional_specs.md §2` — cada rol solo accede a las acciones descritas en la matriz de roles para los módulos ya construidos.

---

## Fase 3 — Pacientes

**Objetivo:** ficha de paciente, base de la que dependen odontograma, presupuestos, riesgo, citas, IA y encuestas.

**Prerrequisitos:** Fase 1 (multi-tenancy), Fase 2 (roles).

**Entregables técnicos** (`technical_specs.md §3.4`, `§5.3`):
- Migración tabla `patients` (§3.4: `uuid` UNIQUE, `tenant_id` FK NOT NULL, `user_id` FK nullable, `document_id` varchar(20) NOT NULL cifrado AES-256, `first_name`, `last_name`, `birth_date`, `phone` cifrado nullable, `email` nullable, `medical_history` jsonb nullable; índice UNIQUE(tenant_id, document_id), índice (tenant_id, last_name)).
- Implementación de cifrado AES-256 en reposo para `document_id` y `phone`, usando la clave activa del tenant desde `encryption_keys` (`technical_specs.md §2.2`, §2.3 punto 4).
- `PatientController`: `GET /patients`, `POST /patients` (`EnsureRole:clinic_admin,dentist,receptionist`), `GET /patients/{patient}` (`can:view,patient`) — §5.3.
- `PatientPolicy` (§4.2).

**Endpoints incluidos:** `/patients` (GET/POST), `/patients/{patient}` (GET) — §5.3.

**Pruebas:**
- `test_user_cannot_access_patient_from_another_tenant()` — ejecución completa ahora que `Patient` existe (§7.1).
- `test_patient_document_id_is_encrypted_at_rest()` (§7.8) — el valor crudo en BD no es texto plano.

**Criterio de aceptación:** `functional_specs.md §5` — datos sensibles del paciente protegidos en todo momento (almacenados y en tránsito, TLS ya cubierto por infraestructura).

---

## Fase 4 — Odontograma evolutivo (append-only)

**Objetivo:** núcleo clínico del sistema; historial de piezas dentales que nunca se sobrescribe (`functional_specs.md §3.4`, `§4.2`).

**Prerrequisitos:** Fase 3 (patients).

**Entregables técnicos** (`technical_specs.md §3.6`, `§5.3`, `§6.2`):
- Migración tabla `odontogram_teeth` (§3.6: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `tooth_number` smallint NOT NULL notación FDI 11–48, `current_status` varchar(40) denormalizado, `history` jsonb default `'[]'`; índice UNIQUE(patient_id, tooth_number), índice GIN(history), CHECK de rango FDI válido).
- Estructura de cada entrada de `history` exactamente como está definida en §3.6 (campos `date`, `status`, `diagnosis_source`, `dentist_id`, `notes`, `related_treatment_id`).
- `OdontogramService::appendState` implementando el algoritmo de §6.2 paso a paso:
  1. Localizar/crear fila `(patient_id, tooth_number)` con `history = []`.
  2. Construir objeto de estado.
  3. Append al array `history` — nunca reemplazar ni eliminar entradas previas.
  4. Actualizar `current_status`.
  5. Todo dentro de una transacción.
- `OdontogramController`: `GET /patients/{patient}/odontogram` (`can:view,patient`), `POST /patients/{patient}/odontogram/{tooth}` (`EnsureRole:dentist`), `GET /patients/{patient}/odontogram/{tooth}/history` (`can:view,patient`) — §5.3.
- `OdontogramPolicy` (§4.2).

**Endpoints incluidos:** los tres de §5.3 relacionados a odontograma.

**Pruebas** (`technical_specs.md §7.2`):
- `test_odontogram_update_appends_to_history_without_overwriting()`.
- `test_current_status_reflects_last_appended_state()`.
- `test_history_entries_are_never_deleted_or_mutated()`.
- Adicional de §7.6: `test_receptionist_cannot_update_odontogram()`.

**Criterio de aceptación:** `functional_specs.md §4.2` — trazabilidad total del odontograma; ningún `UPDATE` sobrescribe o borra una entrada existente (§6.2, regla "Prohibido").

---

## Fase 5 — Catálogo de tratamientos y tratamientos aplicados

**Objetivo:** base de precios de la clínica, requerida por presupuestos (Fase 6).

**Prerrequisitos:** Fase 3 (patients), Fase 1 (multi-tenancy).

**Entregables técnicos** (`technical_specs.md §3.7`, `§3.8`):
- Migración tabla `treatment_catalog` (§3.7: `tenant_id` FK NOT NULL, `code`, `name`, `base_price` numeric(10,2), `is_active` default true; índice UNIQUE(tenant_id, code)).
- Migración tabla `treatments` (§3.8: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `treatment_catalog_id` FK NOT NULL, `tooth_number`, `status` enum('proposed','accepted','in_progress','completed','cancelled') default 'proposed').
- CRUD de catálogo (el SDD no detalla un controlador específico de catálogo con rutas propias; el consumo de `treatment_catalog` está descrito como parte de `BudgetService::generate`, §6.1 — ver nota en §9 sobre gestión administrativa del catálogo).

**Endpoints incluidos:** el SDD no define endpoints propios para `treatment_catalog`/`treatments` fuera del uso interno en presupuestos (§4.5 solo expone `/patients/{patient}/budgets`). Ver §9.

**Pruebas:** no hay pruebas específicas de catálogo/tratamientos listadas en §7 del spec técnico; las pruebas relacionadas (`test_budget_rejects_treatment_not_in_active_catalog`, `test_budget_item_stores_price_snapshot_not_live_price`) se ejecutan en la Fase 6.

**Criterio de aceptación:** existencia de `treatment_catalog` con `is_active` operable, consumible por `BudgetService`.

---

## Fase 6 — Presupuestos (inmutables)

**Objetivo:** generación de presupuestos estandarizados con precios congelados (`functional_specs.md §3.6`, `§4.3`).

**Prerrequisitos:** Fase 5 (treatment_catalog), Fase 3 (patients).

**Entregables técnicos** (`technical_specs.md §3.11`, `§3.12`, `§5.5`, `§6.1`):
- Migración tabla `budgets` (§3.11: `uuid` UNIQUE, `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `status` enum('issued','accepted','rejected') default 'issued', `total_amount` numeric(10,2), `pdf_path` nullable, `issued_at`, `cycle_time_seconds` nullable).
- Migración tabla `budget_items` (§3.12: `tenant_id` FK NOT NULL, `budget_id` FK NOT NULL ON DELETE CASCADE, `treatment_catalog_id` FK NOT NULL, `tooth_number`, `description`, `unit_price_snapshot` numeric(10,2) — precio congelado, `quantity` default 1, `subtotal`).
- `BudgetService::generate` implementando el algoritmo de §6.1 paso a paso:
  1. Recibir tratamientos requeridos (pieza + `treatment_catalog_id`).
  2. Validar existencia y `is_active=true` de cada tratamiento en el catálogo del tenant activo; abortar sin presupuesto parcial si falla alguno.
  3. Crear `budget_item` por línea, copiando `base_price` → `unit_price_snapshot`; `subtotal = unit_price_snapshot * quantity`.
  4. `total_amount = SUM(subtotales)`.
  5. Persistir `budget` (status `issued`) + `budget_items` en una transacción.
  6. Registrar `cycle_time_seconds`.
  7. Generar PDF de forma asíncrona (job en cola, Redis) sin bloquear la respuesta.
- Regla de inmutabilidad: ningún `budget` con status `issued`/`accepted`/`rejected` se modifica ni sus items; corrección = nuevo presupuesto (§6.1).
- `BudgetController`: `POST /patients/{patient}/budgets` (`EnsureRole:dentist,receptionist,clinic_admin`), `GET /budgets/{budget}` (`can:view,budget`), `PATCH /budgets/{budget}/decision` (`can:view,budget`), `GET /budgets/{budget}/pdf` (`can:view,budget`) — §5.5.
- `BudgetPolicy` (§4.2).

**Endpoints incluidos:** los cuatro de §5.5 relacionados a budgets.

**Pruebas** (`technical_specs.md §7.3`):
- `test_budget_rejects_treatment_not_in_active_catalog()`.
- `test_budget_item_stores_price_snapshot_not_live_price()`.
- `test_issued_budget_cannot_be_modified()`.
- `test_budget_total_equals_sum_of_item_subtotals()`.
- `test_budget_generation_runs_in_single_transaction()`.

**Criterio de aceptación:** `functional_specs.md §4.3` — presupuesto refleja el precio del momento de emisión para siempre; objetivo de rendimiento ≤ 2 s en el 95% de solicitudes (§6.1).

---

## Fase 7 — Citas (sin doble reserva)

**Objetivo:** agenda de citas con prevención de solapamiento a nivel de aplicación y de base de datos.

**Prerrequisitos:** Fase 3 (patients), Fase 2 (users/dentist).

**Entregables técnicos** (`technical_specs.md §3.13`, `§5.5`, `§6.5`):
- Migración tabla `appointments` (§3.13: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `dentist_id` FK NOT NULL, `scheduled_at`, `duration_minutes` default 30, `status` enum('scheduled','checked_in','completed','cancelled','no_show') default 'scheduled', `payment_confirmed` default false; **índice UNIQUE(tenant_id, dentist_id, scheduled_at)** como última línea de defensa contra doble reserva; índice (tenant_id, scheduled_at)).
- `AppointmentService::schedule`: valida disponibilidad (solapamiento con `duration_minutes`) antes de insertar; ante violación de la restricción única, devuelve conflicto 409 (§6.5).
- `AppointmentController`: `POST /appointments` (`EnsureRole:receptionist,clinic_admin`), `PATCH /appointments/{appointment}/check-in` (`EnsureRole:receptionist`), `GET /appointments/availability` (`auth:sanctum`) — §5.5.

**Endpoints incluidos:** los tres de §5.5 relacionados a appointments.

**Pruebas** (`technical_specs.md §7.5`):
- `test_cannot_double_book_same_dentist_same_slot()` — segunda cita en el mismo slot → 409.
- `test_overlapping_appointment_is_rejected()`.

**Criterio de aceptación:** `functional_specs.md §4.7` — la agenda de un odontólogo no admite solapamientos, sin excepción.

---

## Fase 8 — Diagnóstico y tratamiento asistidos por IA (validación humana)

**Objetivo:** sugerencias de IA sobre diagnóstico y tratamiento, siempre sujetas a decisión explícita del odontólogo (`functional_specs.md §3.5`).

**Prerrequisitos:** Fase 3 (patients), Fase 4 (odontogram, para vincular `related_treatment_id` si aplica).

**Entregables técnicos** (`technical_specs.md §3.9`, `§3.10`, `§5.4`):
- Migración tabla `ai_diagnosis_suggestions` (§3.9: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `raw_note` text, `structured_output` jsonb —pieza, estadio, grado, extensión—, `dentist_decision` enum('accepted','adjusted','rejected') nullable, `decided_by` FK nullable).
- Migración tabla `ai_treatment_suggestions` (§3.10: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `suggestion` jsonb —diagnóstico + plan—, `dentist_decision` enum nullable, `decided_by` FK nullable, `decided_at` nullable).
- `AiDiagnosisController`: `POST /patients/{patient}/ai/diagnosis` (`EnsureRole:dentist`), `PATCH /ai/diagnosis/{suggestion}/decision` (`EnsureRole:dentist`) — §5.4.
- `AiTreatmentController`: `POST /patients/{patient}/ai/treatment` (`EnsureRole:dentist`), `PATCH /ai/treatment/{suggestion}/decision` (`EnsureRole:dentist`) — §5.4.

**Endpoints incluidos:** los cuatro de §5.4.

**Pruebas** (`technical_specs.md §7.7`):
- `test_dentist_can_accept_adjust_or_reject_ai_suggestion()`.
- `test_ai_suggestion_starts_without_decision()`.

**Criterio de aceptación:** `functional_specs.md §4.4` — toda sugerencia de IA requiere validación explícita del odontólogo antes de tener efecto clínico.

> **Nota importante:** el SDD no especifica el mecanismo interno que genera `structured_output` (diagnóstico) ni `suggestion` (tratamiento) — no define un modelo, proveedor ni contrato REST para esta funcionalidad, a diferencia de la predicción de riesgo (Fase 9, que sí tiene contrato completo en §6.3). Ver §9.

---

## Fase 9 — Predicción de riesgo de caries e integración con microservicio ML

**Objetivo:** predicción de riesgo con explicación (SHAP) y resiliencia ante fallos del ML (`functional_specs.md §3.7`).

**Prerrequisitos:** Fase 3 (patients).

**Entregables técnicos** (`technical_specs.md §3.5`, `§3.14`, `§3.15`, `§3.16`, `§5.6`, `§5.8`, `§6.3`):
- Migración tabla `patient_risk_variables` (§3.5: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `sociodemographic` jsonb, `clinical` jsonb, `behavioral` jsonb, `captured_at`; índice (tenant_id, patient_id)).
- Migración tabla `risk_predictions` (§3.14: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `risk_level` enum('bajo','medio','alto'), `risk_score` numeric(5,4), `confidence` numeric(5,4), `explanation` jsonb, `model_version`, `predicted_at`; índice (tenant_id, patient_id, predicted_at)).
- Migración tabla `risk_alerts`, condicional (§3.15: `tenant_id` FK NOT NULL, `risk_prediction_id` FK UNIQUE NOT NULL, `patient_id` FK NOT NULL, `dentist_id` FK NOT NULL, `acknowledged` default false; solo se crea si `risk_level='alto'`).
- Migración tabla `clinical_followups` (§3.16: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `risk_prediction_id` FK nullable, `actual_outcome` jsonb, `recorded_by` FK NOT NULL).
- `RiskVariableController`: `POST /patients/{patient}/risk-variables` (`EnsureRole:dentist,receptionist`) — §5.3.
- Cliente `Services/Ml/RiskEngineClient` con `POST /predict` al microservicio, **timeout ≤ 3 s**, Circuit Breaker + fallback (§6.3, §2.2).
- Middleware `VerifyMlServiceKey`: valida header `X-ML-Service-Key` en endpoints callback del microservicio (§4.2).
- `RiskPredictionService::predict` implementando el algoritmo de §6.3 paso a paso:
  1. Recopilar features de `patient_risk_variables`; si falta alguna variable requerida → abortar, no llamar al ML con payload incompleto.
  2. `RiskEngineClient` hace `POST /predict` (timeout ≤ 3 s).
  3. Circuit Breaker + fallback: si el ML falla/timeout/error → no bloquear la consulta clínica, devolver estado `unavailable`, registrar el fallo.
  4. Persistir `risk_prediction`.
  5. `<<extend>>`: solo si `risk_level='alto'` → crear `risk_alert` dirigida al `dentist`.
  6. `<<include>>`: la explicación siempre acompaña a la predicción; sin predicción no hay explicación.
- Payload y respuesta del contrato REST exactamente como en §6.3 (`patient_ref`, `features.sociodemographic/clinical/behavioral`; respuesta `risk_level`, `risk_score`, `confidence`, `model_version`, `explanation.global/individual`).
- `RiskPredictionController`: `POST /patients/{patient}/risk/predict` (`EnsureRole:dentist`), `GET /patients/{patient}/risk/latest` (`EnsureRole:dentist,clinic_admin`), `GET /risk-predictions/{prediction}/explanation` (`EnsureRole:dentist`) — §5.6.
- `RiskAlertController`: `GET /risk-alerts` (`EnsureRole:dentist`), `PATCH /risk-alerts/{alert}/acknowledge` (`EnsureRole:dentist`) — §5.6.
- `ClinicalFollowupController`: `POST /patients/{patient}/followups` (`EnsureRole:dentist`) — §5.6.
- `RiskReportController`: `GET /reports/risk-distribution` (`EnsureRole:clinic_admin`) — §5.6.
- `RiskPredictionPolicy` (§4.2).
- **Microservicio ML (repositorio separado, Python/FastAPI):** endpoints `POST /predict`, `GET /health` (para el Circuit Breaker), `GET /model/version` (§5.8). Modelo scikit-learn/XGBoost con explicabilidad SHAP (§2.2). No accede a la base de datos de Laravel (§2.1).

**Endpoints incluidos:** los de §5.6 completos + `/patients/{patient}/risk-variables` (§5.3) + los tres del microservicio (§5.8).

**Pruebas** (`technical_specs.md §7.4`):
- `test_predict_endpoint_rejects_incomplete_payload()`.
- `test_risk_prediction_falls_back_when_ml_service_unavailable()`.
- `test_high_risk_prediction_triggers_risk_alert()`.
- `test_non_high_risk_prediction_does_not_create_alert()`.
- `test_explanation_is_available_only_after_prediction_exists()`.
- Adicional de §7.6: `test_ml_callback_requires_valid_service_key()`.

**Criterio de aceptación:** `functional_specs.md §4.5` y `§4.6` — la predicción nunca bloquea la atención clínica; siempre se muestra `confidence` junto al resultado; explicación siempre presente cuando hay predicción.

---

## Fase 10 — Encuestas de satisfacción

**Objetivo:** registrar percepción de historial y satisfacción con presupuesto.

**Prerrequisitos:** Fase 3 (patients).

**Entregables técnicos** (`technical_specs.md §3.17`, `§5.7`):
- Migración tabla `satisfaction_surveys` (§3.17: `tenant_id` FK NOT NULL, `patient_id` FK NOT NULL, `type` enum('history_perception','budget_satisfaction'), `answers` jsonb, `score` smallint nullable).
- `SurveyController`: `POST /patients/{patient}/surveys` (`EnsureRole:receptionist,patient`) — §5.7.

**Endpoints incluidos:** el de §5.7.

**Pruebas:** no hay pruebas específicas de encuestas listadas en §7 del spec técnico.

**Criterio de aceptación:** `functional_specs.md §3.9` — encuestas registrables por recepción o por el propio paciente.

---

## Fase 11 — Auditoría y cumplimiento

**Objetivo:** trazabilidad de accesos y acciones sensibles a nivel de plataforma.

**Prerrequisitos:** Fase 1 (auth/tenants), transversal a todas las fases anteriores (cada acción sensible ya construida debe empezar a auditarse cuando esta fase se complete).

**Entregables técnicos** (`technical_specs.md §3.18`, `§5.1`):
- Migración tabla `audit_logs` (§3.18: `tenant_id` FK nullable —eventos de plataforma sin tenant—, `user_id` FK nullable, `action` varchar(80), `context` jsonb —ip, user_agent, recurso afectado—, `created_at`; índices (tenant_id, created_at) y (user_id, action)).
- `AuditLogController`: `GET /audit-logs` (`EnsureRole:super_admin,clinic_admin`) — §5.1.
- `ComplianceController`: `GET /compliance/report` (`EnsureRole:super_admin`) — §5.1.

**Endpoints incluidos:** `/audit-logs`, `/compliance/report` — §5.1.

**Pruebas** (`technical_specs.md §7.9`):
- `test_access_events_are_written_to_audit_log()`.

**Criterio de aceptación:** `functional_specs.md §3.10` — cada acción sensible queda auditada; `clinic_admin` solo ve auditoría de su propia clínica.

> **Nota importante:** el SDD no especifica el mecanismo técnico de captura (observer de modelo, middleware global, listener de eventos) ni el contenido exacto del reporte de `/compliance/report`. Ver §9.

---

## Fase 12 — Monitoreo de desempeño

**Objetivo:** alertas cuando un indicador de desempeño se degrada.

**Prerrequisitos:** Fase 1 (tenants), transversal.

**Entregables técnicos** (`technical_specs.md §3.19`, `§5.1`):
- Migración tabla `performance_alerts` (§3.19: `tenant_id` FK nullable, `metric` varchar(50), `value` numeric(10,2), `threshold` numeric(10,2), `triggered_at`).
- `PerformanceAlertController`: `GET /performance/alerts` (`EnsureRole:super_admin,clinic_admin`) — §5.1.

**Endpoints incluidos:** `/performance/alerts` — §5.1.

**Pruebas:** no hay pruebas específicas listadas en §7 del spec técnico para este módulo.

**Criterio de aceptación:** `functional_specs.md §3.11` — visibilidad de degradación de servicio para `super_admin` y, limitada a su clínica, para `clinic_admin`.

> **Nota importante:** el SDD no especifica qué proceso calcula `metric`/`value`/`threshold` ni con qué frecuencia se evalúan. Ver §9.

---

## Fase 13 — Rotación de claves de cifrado

**Objetivo:** completar el ciclo de vida de `encryption_keys` iniciado en Fase 1.

**Prerrequisitos:** Fase 1 (encryption_keys), Fase 3 (primer consumo real de cifrado sobre `patients`).

**Entregables técnicos** (`technical_specs.md §5.1`):
- `EncryptionKeyController`: `POST /tenants/{tenant}/encryption-keys/rotate` (`EnsureRole:super_admin`) — §5.1.

**Endpoints incluidos:** el de §5.1.

**Pruebas:** no hay pruebas específicas listadas en §7 del spec técnico para rotación de claves.

**Criterio de aceptación:** `functional_specs.md §3.1` — clave de cifrado de una clínica rotable por `super_admin`.

---

## Fase 14 — Frontend SPA (transversal, en paralelo a partir de Fase 1)

**Objetivo:** consumo de la API por la SPA React, con guards de rol y estado de servidor.

**Prerrequisitos:** cada pantalla depende de que su(s) endpoint(s) correspondiente(s) ya existan en el backend (no puede adelantarse a la fase backend que la sustenta).

**Entregables técnicos** (`technical_specs.md §1`, `§2.2`, `§4.2`):
- Cliente Axios configurado para Sanctum (tokens Bearer).
- Integración TanStack Query para estado de servidor.
- React Router con rutas protegidas.
- Componente `<RequireRole allow={[...]}>` (§4.2) aplicado a cada ruta según la matriz de roles de `functional_specs.md §2`.
- Una vista/flujo por endpoint ya construido en el backend, siguiendo el orden de fases 1–13.

**Pruebas:** el SDD no define una suite de pruebas frontend (§7 solo cubre backend con Pest/PHPUnit). Ver §9.

**Criterio de aceptación:** `functional_specs.md §3` — cada rol accede únicamente a las pantallas/funciones que le corresponden; los guards de React son UX, la autorización real ocurre en el backend (§4.2).

---

## 15. Matriz de trazabilidad Fase → Pruebas del spec técnico (§7)

| Fase | Pruebas cubiertas (nombre literal de `technical_specs.md §7`) |
| :-- | :-- |
| 1 | `test_global_scope_filters_all_queries_by_tenant`, `test_tenant_id_is_never_accepted_from_request_payload`, `test_super_admin_can_bypass_tenant_scope_only_explicitly`, `test_only_super_admin_can_register_tenant` (adelantada desde Fase 2: `TenantController` ya existe en Fase 1) |
| 3 | `test_user_cannot_access_patient_from_another_tenant`, `test_patient_document_id_is_encrypted_at_rest` |
| 4 | `test_odontogram_update_appends_to_history_without_overwriting`, `test_current_status_reflects_last_appended_state`, `test_history_entries_are_never_deleted_or_mutated`, `test_receptionist_cannot_update_odontogram` |
| 6 | `test_budget_rejects_treatment_not_in_active_catalog`, `test_budget_item_stores_price_snapshot_not_live_price`, `test_issued_budget_cannot_be_modified`, `test_budget_total_equals_sum_of_item_subtotals`, `test_budget_generation_runs_in_single_transaction` |
| 7 | `test_cannot_double_book_same_dentist_same_slot`, `test_overlapping_appointment_is_rejected` |
| 8 | `test_dentist_can_accept_adjust_or_reject_ai_suggestion`, `test_ai_suggestion_starts_without_decision` |
| 9 | `test_predict_endpoint_rejects_incomplete_payload`, `test_risk_prediction_falls_back_when_ml_service_unavailable`, `test_high_risk_prediction_triggers_risk_alert`, `test_non_high_risk_prediction_does_not_create_alert`, `test_explanation_is_available_only_after_prediction_exists`, `test_ml_callback_requires_valid_service_key` |
| 11 | `test_access_events_are_written_to_audit_log` |
| — | `test_patient_can_only_view_own_records` (§7.6) — transversal, se valida contra cada endpoint accesible por `patient` a medida que se construyen: `/patients/{patient}` (Fase 3), `/patients/{patient}/odontogram*` (Fase 4), `/budgets/{budget}` (Fase 6) |

Todas las pruebas listadas en `technical_specs.md §7` quedan cubiertas por alguna fase. No se agregó ninguna prueba fuera de esa lista.

---

## 16. Orden recomendado de ejecución (resumen)

```
Fase 0 → Fase 1 → Fase 2 → Fase 3 → Fase 4
                                   → Fase 5 → Fase 6
                                   → Fase 7
                                   → Fase 8
                                   → Fase 9
                                   → Fase 10
Fase 11, 12, 13 → transversales, pueden iniciarse tras Fase 1 pero requieren que existan
                   los módulos que auditan/monitorean/cifran para tener valor completo.
Fase 14 → en paralelo a cada fase backend, nunca por delante de ella.
```

---

## 9. Vacíos del SDD (no se inventa nada; se deja pendiente de definición)

Estos puntos son necesarios para completar la implementación pero **no están especificados** en `SDD_DentiCore.md` ni, por lo tanto, en `functional_specs.md`/`technical_specs.md`. Deben resolverse con el responsable de producto/arquitectura antes de la fase correspondiente, no asumirse:

1. **Mecanismo de IA para diagnóstico/tratamiento (Fase 8):** el SDD no define qué modelo, proveedor o servicio genera `structured_output` (diagnóstico) ni `suggestion` (tratamiento), a diferencia de la predicción de riesgo que sí tiene contrato REST completo (§6.3). No se sabe si reutiliza el mismo microservicio FastAPI, otro servicio, o un proveedor externo (ej. LLM).
2. **Gestión administrativa del catálogo de tratamientos (Fase 5):** no hay endpoints definidos en §4/§5 del spec técnico para crear/editar `treatment_catalog` fuera de su consumo interno en `BudgetService`. Falta definir quién y cómo lo mantiene.
3. **Mecanismo de captura de auditoría (Fase 11):** no se especifica si es un Observer de Eloquent, un middleware global, un listener de eventos, o registro manual por controlador.
4. **Contenido del reporte de cumplimiento** (`GET /compliance/report`, Fase 11): el endpoint existe pero no hay definición de qué datos contiene.
5. **Mecanismo de cálculo de `performance_alerts`** (Fase 12): no se especifica qué proceso mide `metric`/`value` ni compara contra `threshold`, ni la frecuencia (job programado, middleware de request, APM externo).
6. **Pruebas de frontend:** el spec técnico (§7) solo define pruebas backend con Pest/PHPUnit; no hay estrategia de testing definida para la SPA React.
7. **Estrategia de despliegue/CI-CD:** el SDD no menciona contenedores, pipelines, ambientes (staging/producción) ni proceso de release.
8. **Notificaciones:** el SDD no menciona canal de notificación (email, push, in-app) para `risk_alerts`, decisiones de presupuesto, o recordatorios de citas — solo describe la persistencia de las alertas.
9. **Recuperación de contraseña / gestión de sesión avanzada:** Sanctum y login/logout están definidos (§5.1), pero no hay endpoint ni flujo de "olvidé mi contraseña".
10. **Librería concreta de generación de PDF** para presupuestos (§6.1 solo indica "Generar PDF... de forma asíncrona").
11. **Rate limiting / throttling de API:** no mencionado en el SDD.

No se debe implementar nada de lo anterior por iniciativa propia sin antes confirmarlo, para no introducir comportamiento no especificado.
