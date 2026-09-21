# Software Design Document: DentiCore

> **Documento de contexto base (knowledge base) para desarrollo asistido con Claude Code.**
> Plataforma SaaS multi-clínica (multi-tenant) de odontograma evolutivo, presupuestos estandarizados y predicción explicable de riesgo de caries dental.
> Documento estrictamente técnico y declarativo. Toda regla aquí definida es normativa para la generación de código.

**Stack mandatorio:**

| Capa | Tecnología |
| :-- | :-- |
| Frontend | React 18 (SPA), React Router, Axios, TanStack Query |
| Backend | PHP 8.3 + Laravel 11 (API RESTful, stateless) |
| Autenticación | Laravel Sanctum (SPA token-based) |
| Base de datos | PostgreSQL 16 |
| Microservicio ML | Python (FastAPI) — servicio externo desacoplado, consumido vía HTTP/REST |
| Cache / Colas | Redis |

---

## 1. Arquitectura del Sistema

### 1.1 Patrón general

- **Monolito modular Laravel** que expone una **API RESTful stateless** consumida por una **SPA React** independiente (repos/deploys separados; comunicación exclusiva vía JSON sobre HTTPS).
- **Microservicio ML desacoplado** en Python (FastAPI): Laravel actúa como cliente HTTP. El motor ML NO accede directamente a la base de datos de Laravel; recibe features en el payload y devuelve el resultado. Comunicación protegida con API key de servicio.
- **Multi-tenancy lógico de base de datos compartida** (single database, shared schema): todas las clínicas comparten las mismas tablas; el aislamiento se garantiza por una columna discriminadora `tenant_id` + **Global Scopes de Eloquent** aplicados automáticamente. NO se usa una base de datos por tenant.
- **Arquitectura por capas** (RNF-11): `Controllers → Form Requests (validación) → Services (lógica de negocio) → Models/Repositories (persistencia)`. El motor ML es una capa de servicio adicional aislada (`Services/Ml/`).

### 1.2 Stack tecnológico detallado

| Componente | Detalle técnico |
| :-- | :-- |
| API | Laravel 11, prefijo `/api/v1`, respuestas JSON con API Resources |
| Auth | Sanctum, tokens Bearer; RBAC vía Middleware + Policies |
| ORM | Eloquent con Global Scopes para multi-tenancy |
| DB | PostgreSQL 16; uso intensivo de `jsonb`, `uuid`, `enum` nativo, índices `GIN` sobre `jsonb` |
| Frontend | React SPA; guards de ruta por rol; estado de servidor con TanStack Query |
| ML | FastAPI (Python), scikit-learn/XGBoost, explicabilidad SHAP; contrato REST fijo |
| Resiliencia ML | Timeout + Circuit Breaker + fallback (el fallo del ML nunca bloquea la consulta clínica) |
| Cifrado | AES-256 en reposo (columnas sensibles), TLS 1.2+ en tránsito (RNF-04) |

### 1.3 Enfoque Multi-tenant (estrategia técnica)

**Regla de oro (RNF-05, tolerancia cero):** ninguna consulta puede retornar datos de un `tenant_id` distinto al de la sesión activa. Cero fugas entre tenants es un criterio de aceptación no negociable.

Implementación obligatoria:

1. **Columna `tenant_id`** (`foreignId` → `tenants.id`) en TODA tabla que contenga datos de clínica (todas excepto `tenants`, `users` de tipo `super_admin` y tablas de plataforma).
2. **`BelongsToTenant` trait** aplicado a todos los modelos multi-tenant. El trait:
   - Registra un **Global Scope** que añade `WHERE tenant_id = <current_tenant>` a toda query automáticamente.
   - Asigna `tenant_id` automáticamente en el evento `creating`.
3. **`ResolveTenant` middleware**: resuelve el tenant activo desde el usuario autenticado (`auth()->user()->tenant_id`) y lo fija en un singleton de contexto (`app()->instance('currentTenant', $tenant)`) al inicio de cada request.
4. **Claves de cifrado por clínica** (RF-03): cada tenant tiene su propia clave en `encryption_keys`, rotable. Los campos sensibles se cifran/descifran con la clave del tenant activo.
5. El `super_admin` es el ÚNICO rol que puede operar fuera del scope de un tenant (para registrar clínicas y ver reportes de plataforma), mediante un método explícito `withoutTenantScope()`.

**Eloquent — relaciones clave:**

```
Tenant hasMany User
Tenant hasMany Patient
Tenant hasOne EncryptionKey
Patient belongsTo Tenant
Patient hasMany OdontogramTooth        (máx. 32 por paciente)
Patient hasMany Treatment
Patient hasMany Budget
Patient hasMany RiskPrediction
Patient hasMany ClinicalFollowup
OdontogramTooth belongsTo Patient       (historial en columna jsonb)
Treatment belongsTo Patient
Treatment belongsTo TreatmentCatalog
Budget hasMany BudgetItem
Budget belongsTo Patient
RiskPrediction hasOne RiskAlert          (condicional: solo si risk_level = 'alto')
RiskPrediction belongsTo Patient
User belongsTo Tenant                    (nullable solo para super_admin)
```

---

## 2. Esquema de Base de Datos (Data Model)

> PostgreSQL 16. Convención: `id` = `bigserial`/`foreignId`; identificadores públicos expuestos al frontend = `uuid`. Timestamps `created_at`/`updated_at` en todas las tablas (omitidos abajo por brevedad salvo relevancia). Todas las FK con `ON DELETE RESTRICT` salvo indicación.

### 2.1 `tenants` — Clínicas suscritas (RF-01)

| Columna | Tipo PostgreSQL | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| uuid | uuid | UNIQUE, default gen_random_uuid() |
| name | varchar(150) | NOT NULL |
| subscription_plan | varchar(50) enum('basic','pro','enterprise') | NOT NULL |
| status | varchar(20) enum('active','suspended','cancelled') | NOT NULL, default 'active' |
| settings | jsonb | NULL (config por clínica) |

*Índices:* UNIQUE(uuid). *Sin* `tenant_id` (es la raíz).

### 2.2 `encryption_keys` — Claves de cifrado por clínica (RF-03)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, UNIQUE, NOT NULL |
| key_ciphertext | text | NOT NULL (clave cifrada con master key del sistema) |
| rotated_at | timestamptz | NULL |
| is_active | boolean | NOT NULL, default true |

### 2.3 `users` — Usuarios de plataforma y clínica (RF-04, RF-05)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| uuid | uuid | UNIQUE |
| tenant_id | bigint | FK → tenants.id, **NULL solo si role='super_admin'** |
| name | varchar(150) | NOT NULL |
| email | varchar(180) | NOT NULL |
| password | varchar(255) | NOT NULL (bcrypt) |
| role | varchar(30) enum('super_admin','clinic_admin','dentist','receptionist','patient') | NOT NULL |
| is_active | boolean | NOT NULL, default true |

*Índices:* UNIQUE(tenant_id, email) — el email es único **por clínica**, no global. Index(role).

### 2.4 `patients` — Pacientes (RF-09)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| uuid | uuid | UNIQUE |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| user_id | bigint | FK → users.id, NULL (portal opcional del paciente) |
| document_id | varchar(20) | NOT NULL (DNI, cifrado AES-256) |
| first_name | varchar(100) | NOT NULL |
| last_name | varchar(100) | NOT NULL |
| birth_date | date | NOT NULL |
| phone | varchar(20) | NULL (cifrado) |
| email | varchar(180) | NULL |
| medical_history | jsonb | NULL (antecedentes médicos generales estructurados) |

*Índices:* UNIQUE(tenant_id, document_id). Index(tenant_id, last_name).

### 2.5 `patient_risk_variables` — Variables del modelo de riesgo (RF-21, RF-22, RF-23)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| sociodemographic | jsonb | NOT NULL (edad, ingreso_familiar, educacion_padres, situacion_laboral, estructura_familiar) |
| clinical | jsonb | NOT NULL (porcentaje_placa, severidad_caries) |
| behavioral | jsonb | NOT NULL (consumo_azucar, uso_pasta_fluorada, frecuencia_visitas) |
| captured_at | timestamptz | NOT NULL |

*Índices:* Index(tenant_id, patient_id).

### 2.6 `odontogram_teeth` — Odontograma evolutivo (RF-12) — **núcleo append-only**

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| tooth_number | smallint | NOT NULL (notación FDI: 11–48, 1 fila por pieza) |
| current_status | varchar(40) | NOT NULL (estado vigente denormalizado para consulta rápida) |
| history | jsonb | NOT NULL, default '[]' (**append-only**, ver §5.2) |

*Índices:* UNIQUE(patient_id, tooth_number). Index GIN(history). CHECK(tooth_number entre rangos FDI válidos).

**Estructura de cada entrada del array `history` (jsonb):**

```json
[
  {
    "date": "2026-09-20T14:30:00Z",
    "status": "caries_c2",
    "diagnosis_source": "manual | ai_suggested",
    "dentist_id": 42,
    "notes": "Cara oclusal",
    "related_treatment_id": 128
  }
]
```

> Regla: nunca se sobrescribe ni se borra una entrada de `history`. Cada cambio de estado hace **append** de un nuevo objeto y actualiza `current_status`. Esto materializa el "historial evolutivo por pieza" y garantiza trazabilidad (resuelve el problema F04-3).

### 2.7 `treatment_catalog` — Catálogo de tratamientos y precios (RF-17, entrada E-05)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| code | varchar(30) | NOT NULL |
| name | varchar(150) | NOT NULL |
| base_price | numeric(10,2) | NOT NULL |
| is_active | boolean | NOT NULL, default true |

*Índices:* UNIQUE(tenant_id, code). El precio se versiona por snapshot en `budget_items` (ver §5.1).

### 2.8 `treatments` — Tratamientos aplicados a piezas

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| treatment_catalog_id | bigint | FK → treatment_catalog.id, NOT NULL |
| tooth_number | smallint | NOT NULL |
| status | varchar(30) enum('proposed','accepted','in_progress','completed','cancelled') | NOT NULL, default 'proposed' |

### 2.9 `ai_diagnosis_suggestions` — Diagnóstico asistido por IA (RF-10, RF-11)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| raw_note | text | NOT NULL (nota clínica en texto libre, entrada E-02) |
| structured_output | jsonb | NOT NULL (pieza, estadio, grado, extensión) |
| dentist_decision | varchar(20) enum('accepted','adjusted','rejected') | NULL (RF-11/RF-15) |
| decided_by | bigint | FK → users.id, NULL |

### 2.10 `ai_treatment_suggestions` — Sugerencia de tratamiento IA (RF-14, RF-15, RF-16)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| suggestion | jsonb | NOT NULL (diagnóstico + plan sugerido) |
| dentist_decision | varchar(20) enum('accepted','adjusted','rejected') | NULL |
| decided_by | bigint | FK → users.id, NULL |
| decided_at | timestamptz | NULL |

### 2.11 `budgets` — Presupuestos (RF-17) — **inmutable tras emisión**

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| uuid | uuid | UNIQUE |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| status | varchar(20) enum('issued','accepted','rejected') | NOT NULL, default 'issued' |
| total_amount | numeric(10,2) | NOT NULL |
| pdf_path | varchar(255) | NULL (S-01) |
| issued_at | timestamptz | NOT NULL |
| cycle_time_seconds | integer | NULL (RF-18) |

> Un budget emitido es **inmutable**: no se editan `total_amount` ni sus items. Un cambio genera un nuevo budget (ver §5.1).

### 2.12 `budget_items` — Líneas del presupuesto (snapshot de precio)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| budget_id | bigint | FK → budgets.id, NOT NULL, ON DELETE CASCADE |
| treatment_catalog_id | bigint | FK → treatment_catalog.id, NOT NULL |
| tooth_number | smallint | NOT NULL |
| description | varchar(150) | NOT NULL |
| unit_price_snapshot | numeric(10,2) | NOT NULL (**precio congelado al momento de emisión**) |
| quantity | smallint | NOT NULL, default 1 |
| subtotal | numeric(10,2) | NOT NULL |

### 2.13 `appointments` — Citas (RF-04, validación de disponibilidad)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| dentist_id | bigint | FK → users.id, NOT NULL |
| scheduled_at | timestamptz | NOT NULL |
| duration_minutes | smallint | NOT NULL, default 30 |
| status | varchar(20) enum('scheduled','checked_in','completed','cancelled','no_show') | NOT NULL, default 'scheduled' |
| payment_confirmed | boolean | NOT NULL, default false |

*Índices:* **UNIQUE(tenant_id, dentist_id, scheduled_at)** — previene doble reserva a nivel de BD (ver §5.5). Index(tenant_id, scheduled_at).

### 2.14 `risk_predictions` — Predicción de riesgo (RF-24, RF-25, RF-26)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| risk_level | varchar(10) enum('bajo','medio','alto') | NOT NULL |
| risk_score | numeric(5,4) | NOT NULL (0.0000–1.0000) |
| confidence | numeric(5,4) | NOT NULL (RF-26, indicador de confiabilidad) |
| explanation | jsonb | NOT NULL (SHAP: importancia global + individual, RF-25) |
| model_version | varchar(30) | NOT NULL |
| predicted_at | timestamptz | NOT NULL |

*Índices:* Index(tenant_id, patient_id, predicted_at).

### 2.15 `risk_alerts` — Alertas de riesgo alto (RF-27) — **condicional**

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| risk_prediction_id | bigint | FK → risk_predictions.id, UNIQUE, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| dentist_id | bigint | FK → users.id, NOT NULL |
| acknowledged | boolean | NOT NULL, default false |

> Solo se crea si `risk_predictions.risk_level = 'alto'` (relación `<<extend>>`, ver §5.3).

### 2.16 `clinical_followups` — Seguimiento clínico posterior (RF-28)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| risk_prediction_id | bigint | FK → risk_predictions.id, NULL |
| actual_outcome | jsonb | NOT NULL (nuevas lesiones/tratamientos reales) |
| recorded_by | bigint | FK → users.id, NOT NULL |

### 2.17 `satisfaction_surveys` — Encuestas (RF-13, RF-20)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| type | varchar(20) enum('history_perception','budget_satisfaction') | NOT NULL |
| answers | jsonb | NOT NULL |
| score | smallint | NULL |

### 2.18 `audit_logs` — Auditoría de accesos (RF-06)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NULL (eventos de plataforma sin tenant) |
| user_id | bigint | FK → users.id, NULL |
| action | varchar(80) | NOT NULL |
| context | jsonb | NULL (ip, user_agent, recurso afectado) |
| created_at | timestamptz | NOT NULL |

*Índices:* Index(tenant_id, created_at), Index(user_id, action).

### 2.19 `performance_alerts` — Degradación del servicio (RF-08)

| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NULL |
| metric | varchar(50) | NOT NULL |
| value | numeric(10,2) | NOT NULL |
| threshold | numeric(10,2) | NOT NULL |
| triggered_at | timestamptz | NOT NULL |

### 2.20 Resumen relacional

```
Tenant 1:N User | 1:N Patient | 1:1 EncryptionKey | 1:N TreatmentCatalog
Patient 1:N OdontogramTooth (máx 32) | 1:N Treatment | 1:N Budget
Patient 1:1 PatientRiskVariables (última vigente) | 1:N RiskPrediction | 1:N ClinicalFollowup
Budget 1:N BudgetItem
RiskPrediction 1:1 RiskAlert (condicional, solo si risk_level='alto')
Appointment N:1 Patient | N:1 User(dentist)
```

---

## 3. Matriz de Roles y Permisos (RBAC)

### 3.1 Roles (enum `users.role`)

| Rol | Ámbito | Descripción |
| :-- | :-- | :-- |
| `super_admin` | Plataforma (sin tenant) | Registra clínicas, gestiona claves, reportes globales |
| `clinic_admin` | Un tenant | Administra personal y config de su clínica; reportes de riesgo |
| `dentist` | Un tenant | Diagnóstico, odontograma, valida IA, decide tratamientos |
| `receptionist` | Un tenant | Ficha del paciente, encuestas, variables de riesgo, check-in |
| `patient` | Un tenant (self) | Consulta su propio historial/presupuesto; encuestas |
| `ml_service` | No humano | Actor de servicio (API key), no es un `users.role` sino credencial de servicio |

### 3.2 Matriz de permisos

> ✅ permitido · ⚠️ permitido solo sobre registros propios/asignados · ❌ denegado

| Recurso / Acción | super_admin | clinic_admin | dentist | receptionist | patient |
| :-- | :-: | :-: | :-: | :-: | :-: |
| Registrar clínica (tenant) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Gestionar claves de cifrado | ✅ | ❌ | ❌ | ❌ | ❌ |
| Reporte de cumplimiento normativo | ✅ | ❌ | ❌ | ❌ | ❌ |
| Monitoreo de degradación | ✅ | ⚠️ (su clínica) | ❌ | ❌ | ❌ |
| Gestionar usuarios de la clínica | ❌ | ✅ | ❌ | ❌ | ❌ |
| Asignar permisos por rol | ❌ | ✅ | ❌ | ❌ | ❌ |
| Reporte distribución de riesgo | ❌ | ✅ | ❌ | ❌ | ❌ |
| Registrar ficha del paciente | ❌ | ✅ | ✅ | ✅ | ❌ |
| Consultar odontograma | ❌ | ✅ | ✅ | ⚠️ (lectura) | ⚠️ (propio) |
| Actualizar odontograma | ❌ | ❌ | ✅ | ❌ | ❌ |
| Diagnóstico asistido IA | ❌ | ❌ | ✅ | ❌ | ❌ |
| Validar sugerencia IA | ❌ | ❌ | ✅ | ❌ | ❌ |
| Registrar variables de riesgo | ❌ | ❌ | ✅ (clínicas/conductuales) | ✅ (sociodemográficas) | ❌ |
| Generar presupuesto | ❌ | ✅ | ✅ | ⚠️ (emite) | ❌ |
| Ver/aceptar presupuesto | ❌ | ✅ | ✅ | ✅ | ⚠️ (propio) |
| Consultar predicción de riesgo | ❌ | ✅ | ✅ | ❌ | ❌ |
| Ver explicación de riesgo | ❌ | ⚠️ | ✅ | ❌ | ❌ |
| Registrar seguimiento clínico | ❌ | ❌ | ✅ | ❌ | ❌ |
| Responder encuestas | ❌ | ❌ | ❌ | ✅ (registra) | ✅ (propio) |
| Ver auditoría de accesos | ✅ | ⚠️ (su clínica) | ❌ | ❌ | ❌ |

### 3.3 Implementación en Laravel + React

- **Middleware `ResolveTenant`**: fija el tenant activo; se ejecuta antes de cualquier controlador de datos de clínica.
- **Middleware `EnsureRole:role1,role2`**: bloquea rutas por rol (autorización gruesa a nivel de ruta).
- **Policies por modelo** (`PatientPolicy`, `OdontogramPolicy`, `BudgetPolicy`, `RiskPredictionPolicy`): autorización fina; `patient` solo accede a registros con `patient.user_id = auth()->id()`.
- **Middleware `VerifyMlServiceKey`**: valida el header `X-ML-Service-Key` en los endpoints callback del microservicio ML (actor no humano).
- **React Route Guards**: componente `<RequireRole allow={[...]}>` que lee el rol del token y redirige si no autorizado. Los guards de React son UX, **nunca** sustituyen la autorización del backend.

---

## 4. Estructura de Rutas y Controladores

> Prefijo global `/api/v1`. Todas las rutas (salvo login) bajo middleware `auth:sanctum` + `ResolveTenant`. Respuestas vía API Resources.

### 4.1 Autenticación y plataforma

| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /auth/login | AuthController@login | — |
| POST | /auth/logout | AuthController@logout | auth:sanctum |
| GET | /auth/me | AuthController@me | auth:sanctum |
| POST | /tenants | TenantController@store | EnsureRole:super_admin |
| GET | /tenants | TenantController@index | EnsureRole:super_admin |
| POST | /tenants/{tenant}/encryption-keys/rotate | EncryptionKeyController@rotate | EnsureRole:super_admin |
| GET | /compliance/report | ComplianceController@report | EnsureRole:super_admin |
| GET | /audit-logs | AuditLogController@index | EnsureRole:super_admin,clinic_admin |
| GET | /performance/alerts | PerformanceAlertController@index | EnsureRole:super_admin,clinic_admin |

### 4.2 Usuarios y roles (clínica)

| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| GET | /users | UserController@index | EnsureRole:clinic_admin |
| POST | /users | UserController@store | EnsureRole:clinic_admin |
| PATCH | /users/{user} | UserController@update | EnsureRole:clinic_admin |

### 4.3 Pacientes, ficha e historial

| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| GET | /patients | PatientController@index | EnsureRole:clinic_admin,dentist,receptionist |
| POST | /patients | PatientController@store | EnsureRole:clinic_admin,dentist,receptionist |
| GET | /patients/{patient} | PatientController@show | can:view,patient |
| GET | /patients/{patient}/odontogram | OdontogramController@show | can:view,patient |
| POST | /patients/{patient}/odontogram/{tooth} | OdontogramController@appendState | EnsureRole:dentist |
| GET | /patients/{patient}/odontogram/{tooth}/history | OdontogramController@history | can:view,patient |
| POST | /patients/{patient}/risk-variables | RiskVariableController@store | EnsureRole:dentist,receptionist |

### 4.4 Asistencia IA

| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /patients/{patient}/ai/diagnosis | AiDiagnosisController@suggest | EnsureRole:dentist |
| PATCH | /ai/diagnosis/{suggestion}/decision | AiDiagnosisController@decide | EnsureRole:dentist |
| POST | /patients/{patient}/ai/treatment | AiTreatmentController@suggest | EnsureRole:dentist |
| PATCH | /ai/treatment/{suggestion}/decision | AiTreatmentController@decide | EnsureRole:dentist |

### 4.5 Presupuestos y citas

| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /patients/{patient}/budgets | BudgetController@store | EnsureRole:dentist,receptionist,clinic_admin |
| GET | /budgets/{budget} | BudgetController@show | can:view,budget |
| PATCH | /budgets/{budget}/decision | BudgetController@decide | can:view,budget |
| GET | /budgets/{budget}/pdf | BudgetController@pdf | can:view,budget |
| POST | /appointments | AppointmentController@store | EnsureRole:receptionist,clinic_admin |
| PATCH | /appointments/{appointment}/check-in | AppointmentController@checkIn | EnsureRole:receptionist |
| GET | /appointments/availability | AppointmentController@availability | auth:sanctum |

### 4.6 Predicción de riesgo

| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /patients/{patient}/risk/predict | RiskPredictionController@predict | EnsureRole:dentist |
| GET | /patients/{patient}/risk/latest | RiskPredictionController@latest | EnsureRole:dentist,clinic_admin |
| GET | /risk-predictions/{prediction}/explanation | RiskPredictionController@explanation | EnsureRole:dentist |
| GET | /risk-alerts | RiskAlertController@index | EnsureRole:dentist |
| PATCH | /risk-alerts/{alert}/acknowledge | RiskAlertController@acknowledge | EnsureRole:dentist |
| POST | /patients/{patient}/followups | ClinicalFollowupController@store | EnsureRole:dentist |
| GET | /reports/risk-distribution | RiskReportController@distribution | EnsureRole:clinic_admin |

### 4.7 Encuestas

| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /patients/{patient}/surveys | SurveyController@store | EnsureRole:receptionist,patient |

### 4.8 Microservicio ML externo (contrato REST — Python/FastAPI)

> Consumido por Laravel (`Services/Ml/RiskEngineClient`). NO expuesto al frontend.

| Método | Endpoint (base URL del microservicio) | Propósito |
| :-- | :-- | :-- |
| POST | /predict | Recibe features → devuelve risk_level, score, confidence, explanation (SHAP) |
| GET | /health | Health check para Circuit Breaker |
| GET | /model/version | Versión del modelo activo |

---

## 5. Reglas de Negocio Críticas y Flujos

### 5.1 Generación de presupuesto — cálculo inmutable (RF-17, RNF-01)

**Algoritmo (`BudgetService::generate`):**

1. Recibir el conjunto de tratamientos requeridos (pieza + `treatment_catalog_id`).
2. Validar que **cada** tratamiento exista en `treatment_catalog` con `is_active = true` del tenant activo. Si alguno no existe → **abortar** y notificar inconsistencia (flujo alternativo RF-17.a). No se genera presupuesto parcial.
3. Por cada línea: crear `budget_item` copiando `base_price` del catálogo a `unit_price_snapshot` (**snapshot congelado**). `subtotal = unit_price_snapshot * quantity`.
4. `total_amount = SUM(subtotales)`.
5. Persistir `budget` (status `issued`) + `budget_items` dentro de **una transacción DB**.
6. Registrar `cycle_time_seconds` (RF-18).
7. Generar PDF (S-01) de forma asíncrona (job en cola); no bloquea la respuesta.

**Reglas de inmutabilidad:**
- Un `budget` con status `issued`/`accepted`/`rejected` **nunca** se modifica ni sus items. Corrección = nuevo budget.
- El precio del presupuesto es el `unit_price_snapshot`, **no** el precio actual del catálogo. Cambiar el catálogo después NO altera presupuestos ya emitidos.
- Objetivo de rendimiento: cálculo ≤ 2 s en el 95% de solicitudes (RNF-01).

### 5.2 Persistencia del odontograma — append-only (RF-12)

**Regla (`OdontogramService::appendState`):**

1. Localizar la fila `odontogram_teeth` de `(patient_id, tooth_number)`; si no existe, crearla con `history = []`.
2. Construir el objeto de estado (date, status, diagnosis_source, dentist_id, notes, related_treatment_id).
3. **Append** del objeto al array `history` (jsonb) — nunca reemplazar ni eliminar entradas previas.
4. Actualizar `current_status` con el nuevo estado (denormalización para consulta rápida).
5. Todo en una transacción.

**Prohibido:** cualquier `UPDATE` que sobrescriba una entrada existente de `history`, o que reduzca la longitud del array. Consultar historial = leer el array completo ordenado por `date`.

### 5.3 Contrato de comunicación con el microservicio ML (RF-24 → RF-27)

**Flujo (`RiskPredictionService::predict`):**

1. Recopilar features del paciente desde `patient_risk_variables` (sociodemográficas + clínicas + conductuales). Si falta alguna variable requerida → abortar y notificar dato faltante (flujo alternativo RF-24.a). No se llama al ML con payload incompleto.
2. `RiskEngineClient` hace `POST /predict` al microservicio con **timeout ≤ 3 s** (RNF-02).
3. **Circuit Breaker + fallback:** si el ML no responde, da timeout o devuelve error → NO se bloquea la consulta clínica. Se devuelve un estado `unavailable` al frontend y se registra el fallo. La ausencia de predicción nunca impide diagnóstico/presupuesto (resiliencia RNF-09, observación F01).
4. Persistir `risk_prediction` (risk_level, risk_score, confidence, explanation, model_version).
5. **`<<extend>>` (RF-27):** SOLO si `risk_level = 'alto'` → crear `risk_alert` dirigida al `dentist`. Para `bajo`/`medio` no se crea alerta.
6. **`<<include>>` (RF-25):** la explicación (SHAP) siempre acompaña a una predicción calculada; `GET /explanation` lee `risk_predictions.explanation`. No existe explicación sin predicción previa.

**Payload `POST /predict` (Laravel → ML):**

```json
{
  "patient_ref": "uuid",
  "features": {
    "sociodemographic": { "age": 34, "family_income": "...", "parents_education": "...", "employment": "...", "family_structure": "..." },
    "clinical": { "plaque_percentage": 42.5, "caries_severity": "moderate" },
    "behavioral": { "sugar_intake": "high", "fluoride_toothpaste": true, "visit_frequency": "annual" }
  }
}
```

**Respuesta (ML → Laravel):**

```json
{
  "risk_level": "alto",
  "risk_score": 0.8123,
  "confidence": 0.91,
  "model_version": "xgb-v1.3",
  "explanation": {
    "global": [{ "feature": "sugar_intake", "importance": 0.31 }],
    "individual": [{ "feature": "plaque_percentage", "shap_value": 0.22 }]
  }
}
```

> El indicador `confidence` (RF-26) debe mostrarse siempre; la predicción NO es un diagnóstico definitivo.

### 5.4 Aislamiento multi-tenant (RNF-05)

- Toda query de modelos multi-tenant pasa por el Global Scope de `BelongsToTenant`. Prohibido construir queries crudas que omitan `tenant_id`.
- El `tenant_id` se asigna en el servidor desde la sesión; **nunca** se acepta `tenant_id` desde el payload del cliente.
- El único bypass permitido es `super_admin` mediante método explícito `withoutTenantScope()`, jamás implícito.

### 5.5 Prevención de doble reserva (RF-04, resuelve F04-1)

- Restricción **UNIQUE(tenant_id, dentist_id, scheduled_at)** a nivel de BD como última línea de defensa.
- `AppointmentService::schedule` valida disponibilidad (solapamiento con `duration_minutes`) antes de insertar; ante violación de la restricción única, devuelve conflicto 409.

---

## 6. Casos de Prueba Principales (Testing)

> Backend: Pest/PHPUnit. Puntos críticos de fallo derivados de los riesgos identificados (F01, F04) y las reglas §5. Nombres de test = contrato de comportamiento.

### 6.1 Aislamiento multi-tenant (crítico — RNF-05)

- `test_user_cannot_access_patient_from_another_tenant()` — 403/404 al pedir un `patient` de otro tenant.
- `test_global_scope_filters_all_queries_by_tenant()` — un `index` solo retorna registros del tenant activo.
- `test_tenant_id_is_never_accepted_from_request_payload()` — se ignora un `tenant_id` inyectado en el body.
- `test_super_admin_can_bypass_tenant_scope_only_explicitly()`.

### 6.2 Odontograma append-only (RF-12)

- `test_odontogram_update_appends_to_history_without_overwriting()` — longitud del array crece en 1, entradas previas intactas.
- `test_current_status_reflects_last_appended_state()`.
- `test_history_entries_are_never_deleted_or_mutated()`.

### 6.3 Presupuesto inmutable (RF-17, RNF-01)

- `test_budget_rejects_treatment_not_in_active_catalog()` — aborta sin crear budget parcial.
- `test_budget_item_stores_price_snapshot_not_live_price()` — cambiar catálogo luego no altera el budget.
- `test_issued_budget_cannot_be_modified()`.
- `test_budget_total_equals_sum_of_item_subtotals()`.
- `test_budget_generation_runs_in_single_transaction()` — rollback total ante fallo de una línea.

### 6.4 Integración ML y resiliencia (RF-24, RNF-02, RNF-09)

- `test_predict_endpoint_rejects_incomplete_payload()` — falta variable → no se llama al ML.
- `test_risk_prediction_falls_back_when_ml_service_unavailable()` — timeout → estado `unavailable`, la consulta clínica no se bloquea.
- `test_high_risk_prediction_triggers_risk_alert()` — `risk_level='alto'` crea exactamente 1 alerta.
- `test_non_high_risk_prediction_does_not_create_alert()` — `bajo`/`medio` no crean alerta.
- `test_explanation_is_available_only_after_prediction_exists()` — `<<include>>`.

### 6.5 Doble reserva de citas (RF-04)

- `test_cannot_double_book_same_dentist_same_slot()` — segunda cita en el mismo slot → 409.
- `test_overlapping_appointment_is_rejected()`.

### 6.6 RBAC / autorización

- `test_receptionist_cannot_update_odontogram()` — solo `dentist`.
- `test_patient_can_only_view_own_records()`.
- `test_only_super_admin_can_register_tenant()`.
- `test_ml_callback_requires_valid_service_key()`.

### 6.7 Validación de diagnóstico IA (RF-11, RF-15)

- `test_dentist_can_accept_adjust_or_reject_ai_suggestion()` — persiste `dentist_decision`.
- `test_ai_suggestion_starts_without_decision()`.

### 6.8 Cifrado (RNF-04)

- `test_patient_document_id_is_encrypted_at_rest()` — el valor crudo en BD no es texto plano.

### 6.9 Auditoría (RF-06)

- `test_access_events_are_written_to_audit_log()`.
