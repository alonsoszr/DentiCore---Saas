# Especificación Técnica: DentiCore

> Documento derivado de `SDD_DentiCore.md`. Describe CÓMO se construye el sistema: arquitectura, stack, modelo de datos, contratos de API, RBAC técnico, algoritmos críticos y estrategia de testing. Para el QUÉ y el PARA QUIÉN, ver `functional_specs.md`.

> **Desviaciones de versión respecto al SDD original (decididas explícitamente durante la Fase 0 de implementación, 2026-09-20/21, no asumidas):**
> - **Laravel 11 → Laravel 13.x**: el SDD mandataba Laravel 11, pero esa rama llegó a su fin de soporte de seguridad el 12 de marzo de 2026 y tiene un CVE de severidad alta sin parche (`CVE-2026-48019`, inyección CRLF en la regla de validación `email` por defecto), además de otros 5 advisories. Composer bloquea la instalación de cualquier versión 11.x por esta razón. Se optó por Laravel 13.x (actualmente `v13.32.0`), la versión con mayor soporte activo. La arquitectura por capas, Sanctum, Eloquent con Global Scopes y el resto de reglas de este documento aplican igual — Laravel 13 no introduce cambios estructurales frente a Laravel 11 en estos aspectos.
> - **React Router 6 → React Router 7 (`react-router-dom`)**: al instalar dependencias del frontend, `react-router-dom` 6.x resultó afectado por 2 CVEs moderados sin parche en esa rama (fix solo disponible desde 7.18.4). El SDD solo exige "React Router" sin fijar versión mayor, por lo que esto no contradice el stack mandatorio.
> - React 18, PHP 8.3, PostgreSQL 16 y Redis (vía Memurai, compatible con el protocolo Redis — no hay build oficial de Redis para Windows) se mantienen tal como los exige el SDD.
>
> **Extensiones y correcciones sobre el esquema/mecanismo, decididas durante la Fase 1 (2026-09-21), documentadas aquí porque el SDD no las contemplaba o contenía una laguna técnica real:**
> - **`tenants.slug`** (varchar(150) UNIQUE, no está en el SDD original): necesario porque `users.email` es único **por clínica**, no global (§3.3), así que `POST /auth/login` no puede identificar la clínica solo con email+password. El login ahora acepta `tenant_slug` (opcional; ausente = login de `super_admin`, que no tiene clínica).
> - **Índice único parcial `users_super_admin_email_unique` sobre `(email) WHERE tenant_id IS NULL`**: Postgres no considera dos `NULL` iguales, así que `UNIQUE(tenant_id, email)` por sí sola no impide dos `super_admin` con el mismo email. Este índice cierra ese hueco.
> - **`User` NO usa el trait `BelongsToTenant`/Global Scope**, a diferencia de los demás modelos de clínica: Sanctum resuelve el usuario dueño de un token *antes* de que exista un tenant activo en el contenedor (de hecho, el propio mecanismo de `ResolveTenant` en el punto 3 de abajo resuelve el tenant *a partir* del usuario ya autenticado, lo cual exige que la autenticación funcione sin tenant activo). Un Global Scope "deniega por defecto sin tenant" sobre `users` rompe la autenticación por completo. `tenant_id` se mantiene como columna, con asignación explícita en el servicio que crea el usuario (nunca mass-assignment, nunca desde el payload del cliente) — el aislamiento de `users` se hace con `WHERE tenant_id = ...` explícito en las queries de los controladores, no con un Global Scope automático.
> - **`TenantScope` (Global Scope de `BelongsToTenant`) deniega todo por defecto si no hay tenant activo resuelto**, en lugar de no filtrar: esto es necesario para que "tolerancia cero" (RNF-05) sea realmente el comportamiento por defecto — sin este diseño, cualquier request sin tenant resuelto (p. ej. un bug de middleware) devolvería datos de *todos* los tenants en vez de ninguno. El bypass sigue siendo exclusivamente `Model::withoutTenantScope()`.
> - **Longitud de columnas `enum`**: el `enum()` de Laravel sobre Postgres siempre genera `varchar(255)` + `CHECK` (no soporta fijar un largo menor manteniendo el enum). Por eso `subscription_plan`/`status`/`role` quedan en 255 en vez de los 50/20/30 literales de este documento — el `CHECK` restringe los valores igual, es una diferencia puramente de longitud de columna sin impacto funcional.

## 1. Stack tecnológico

| Capa | Tecnología |
| :-- | :-- |
| Frontend | React 18 (SPA), React Router 7, Axios, TanStack Query |
| Backend | PHP 8.3 + Laravel 13.x (API RESTful, stateless) |
| Autenticación | Laravel Sanctum (SPA token-based) |
| Base de datos | PostgreSQL 16 |
| Microservicio ML | Python (FastAPI) — servicio externo desacoplado, consumido vía HTTP/REST |
| Cache / Colas | Redis (Memurai en desarrollo local sobre Windows) |

## 2. Arquitectura del sistema

### 2.1 Patrón general
- **Monolito modular Laravel** exponiendo una **API RESTful stateless**, consumida por una **SPA React** independiente (repos/deploys separados, comunicación exclusiva vía JSON sobre HTTPS).
- **Microservicio ML desacoplado** (FastAPI): Laravel actúa como cliente HTTP. El motor ML **no** accede directamente a la base de datos de Laravel; recibe features en el payload y devuelve el resultado. Comunicación protegida con API key de servicio (`X-ML-Service-Key`).
- **Multi-tenancy lógico de base de datos compartida** (single database, shared schema): todas las clínicas comparten las mismas tablas; el aislamiento se garantiza mediante columna discriminadora `tenant_id` + Global Scopes de Eloquent aplicados automáticamente. No se usa base de datos por tenant.
- **Arquitectura por capas**: `Controllers → Form Requests (validación) → Services (lógica de negocio) → Models/Repositories (persistencia)`. El motor ML es una capa de servicio adicional aislada (`Services/Ml/`).

### 2.2 Stack detallado

| Componente | Detalle técnico |
| :-- | :-- |
| API | Laravel 13.x, prefijo `/api/v1`, respuestas JSON con API Resources |
| Auth | Sanctum, tokens Bearer; RBAC vía Middleware + Policies |
| ORM | Eloquent con Global Scopes para multi-tenancy |
| DB | PostgreSQL 16; uso intensivo de `jsonb`, `uuid`, `enum` nativo, índices `GIN` sobre `jsonb` |
| Frontend | React SPA; guards de ruta por rol; estado de servidor con TanStack Query |
| ML | FastAPI (Python), scikit-learn/XGBoost, explicabilidad SHAP; contrato REST fijo |
| Resiliencia ML | Timeout + Circuit Breaker + fallback (el fallo del ML nunca bloquea la consulta clínica) |
| Cifrado | AES-256 en reposo (columnas sensibles), TLS 1.2+ en tránsito |

### 2.3 Estrategia multi-tenant

**Regla de oro (tolerancia cero):** ninguna consulta puede retornar datos de un `tenant_id` distinto al de la sesión activa.

Implementación obligatoria:

1. **Columna `tenant_id`** (`foreignId` → `tenants.id`) en toda tabla con datos de clínica (todas excepto `tenants`, `users` de tipo `super_admin` y tablas de plataforma).
2. **Trait `BelongsToTenant`** aplicado a los modelos de datos de clínica (excepto `users`, ver nota de Fase 1 al inicio del documento):
   - Registra un Global Scope que añade `WHERE tenant_id = <current_tenant>` a toda query automáticamente; si no hay tenant activo resuelto, la query no devuelve nada por defecto (deny-by-default, no "sin filtro").
   - Asigna `tenant_id` automáticamente en el evento `creating`.
3. **Middleware `ResolveTenant`**: resuelve el tenant activo desde el usuario autenticado (`auth()->user()->tenant_id`) y lo fija en un singleton de contexto (`app()->instance('currentTenant', $tenant)`) al inicio de cada request.
4. **Claves de cifrado por clínica**: cada tenant tiene su propia clave en `encryption_keys`, rotable. Los campos sensibles se cifran/descifran con la clave del tenant activo.
5. El `super_admin` es el único rol que puede operar fuera del scope de un tenant, mediante método explícito `withoutTenantScope()` (nunca implícito).

### 2.4 Relaciones Eloquent clave

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

## 3. Esquema de base de datos

> PostgreSQL 16. Convención: `id` = `bigserial`/`foreignId`; identificadores públicos expuestos al frontend = `uuid`. Timestamps `created_at`/`updated_at` en todas las tablas salvo indicación. Todas las FK con `ON DELETE RESTRICT` salvo indicación contraria.

### 3.1 `tenants`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| uuid | uuid | UNIQUE, default gen_random_uuid() (generado en PHP, ver nota) |
| name | varchar(150) | NOT NULL |
| slug | varchar(150) | UNIQUE, NOT NULL — **extensión Fase 1**, no está en el SDD original (ver nota al inicio del documento) |
| subscription_plan | varchar(50) enum('basic','pro','enterprise') | NOT NULL |
| status | varchar(20) enum('active','suspended','cancelled') | NOT NULL, default 'active' |
| settings | jsonb | NULL |

> **Nota de implementación:** aunque la columna `uuid` tiene default `gen_random_uuid()` a nivel de BD, Eloquent no refresca el modelo en memoria tras un `INSERT` (solo el id autoincremental), así que `uuid` (y cualquier otra columna con default de BD, p. ej. `status`) saldría `null` en la respuesta inmediatamente después de crear el registro. Se generan en PHP antes del insert (trait `HasUuid`, y `protected $attributes` para defaults simples como `status`/`is_active`), dejando el default de BD solo como respaldo ante inserts que no pasen por Eloquent.

*Índices:* UNIQUE(uuid). Sin `tenant_id` (es la raíz).

### 3.2 `encryption_keys`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, UNIQUE, NOT NULL |
| key_ciphertext | text | NOT NULL |
| rotated_at | timestamptz | NULL |
| is_active | boolean | NOT NULL, default true |

### 3.3 `users`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| uuid | uuid | UNIQUE |
| tenant_id | bigint | FK → tenants.id, NULL solo si role='super_admin' |
| name | varchar(150) | NOT NULL |
| email | varchar(180) | NOT NULL |
| password | varchar(255) | NOT NULL (bcrypt) |
| role | varchar(30) enum('super_admin','clinic_admin','dentist','receptionist','patient') | NOT NULL |
| is_active | boolean | NOT NULL, default true |

*Índices:* UNIQUE(tenant_id, email). Index(role). **Índice único parcial adicional (Fase 1):** UNIQUE(email) WHERE tenant_id IS NULL — ver nota al inicio del documento (Postgres no trata dos NULL como iguales).

> **`users` no usa el Global Scope de `BelongsToTenant`** (ver nota al inicio del documento): mantiene la columna `tenant_id` y su asignación explícita, pero el aislamiento se aplica con `WHERE tenant_id = ...` explícito en cada query de controlador, no automáticamente.

### 3.4 `patients`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| uuid | uuid | UNIQUE |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| user_id | bigint | FK → users.id, NULL |
| document_id | varchar(20) | NOT NULL (cifrado AES-256) |
| first_name | varchar(100) | NOT NULL |
| last_name | varchar(100) | NOT NULL |
| birth_date | date | NOT NULL |
| phone | varchar(20) | NULL (cifrado) |
| email | varchar(180) | NULL |
| medical_history | jsonb | NULL |

*Índices:* UNIQUE(tenant_id, document_id). Index(tenant_id, last_name).

### 3.5 `patient_risk_variables`
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

### 3.6 `odontogram_teeth` — núcleo append-only
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| tooth_number | smallint | NOT NULL (notación FDI: 11–48, 1 fila por pieza) |
| current_status | varchar(40) | NOT NULL (denormalizado) |
| history | jsonb | NOT NULL, default '[]' (append-only) |

*Índices:* UNIQUE(patient_id, tooth_number). Index GIN(history). CHECK(tooth_number entre rangos FDI válidos).

Estructura de cada entrada de `history`:
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

### 3.7 `treatment_catalog`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| code | varchar(30) | NOT NULL |
| name | varchar(150) | NOT NULL |
| base_price | numeric(10,2) | NOT NULL |
| is_active | boolean | NOT NULL, default true |

*Índices:* UNIQUE(tenant_id, code). El precio se versiona por snapshot en `budget_items`.

### 3.8 `treatments`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| treatment_catalog_id | bigint | FK → treatment_catalog.id, NOT NULL |
| tooth_number | smallint | NOT NULL |
| status | varchar(30) enum('proposed','accepted','in_progress','completed','cancelled') | NOT NULL, default 'proposed' |

### 3.9 `ai_diagnosis_suggestions`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| raw_note | text | NOT NULL |
| structured_output | jsonb | NOT NULL (pieza, estadio, grado, extensión) |
| dentist_decision | varchar(20) enum('accepted','adjusted','rejected') | NULL |
| decided_by | bigint | FK → users.id, NULL |

### 3.10 `ai_treatment_suggestions`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| suggestion | jsonb | NOT NULL (diagnóstico + plan sugerido) |
| dentist_decision | varchar(20) enum('accepted','adjusted','rejected') | NULL |
| decided_by | bigint | FK → users.id, NULL |
| decided_at | timestamptz | NULL |

### 3.11 `budgets` — inmutable tras emisión
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| uuid | uuid | UNIQUE |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| status | varchar(20) enum('issued','accepted','rejected') | NOT NULL, default 'issued' |
| total_amount | numeric(10,2) | NOT NULL |
| pdf_path | varchar(255) | NULL |
| issued_at | timestamptz | NOT NULL |
| cycle_time_seconds | integer | NULL |

### 3.12 `budget_items`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| budget_id | bigint | FK → budgets.id, NOT NULL, ON DELETE CASCADE |
| treatment_catalog_id | bigint | FK → treatment_catalog.id, NOT NULL |
| tooth_number | smallint | NOT NULL |
| description | varchar(150) | NOT NULL |
| unit_price_snapshot | numeric(10,2) | NOT NULL (precio congelado al momento de emisión) |
| quantity | smallint | NOT NULL, default 1 |
| subtotal | numeric(10,2) | NOT NULL |

### 3.13 `appointments`
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

*Índices:* UNIQUE(tenant_id, dentist_id, scheduled_at) — previene doble reserva a nivel de BD. Index(tenant_id, scheduled_at).

### 3.14 `risk_predictions`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| risk_level | varchar(10) enum('bajo','medio','alto') | NOT NULL |
| risk_score | numeric(5,4) | NOT NULL (0.0000–1.0000) |
| confidence | numeric(5,4) | NOT NULL |
| explanation | jsonb | NOT NULL (SHAP: importancia global + individual) |
| model_version | varchar(30) | NOT NULL |
| predicted_at | timestamptz | NOT NULL |

*Índices:* Index(tenant_id, patient_id, predicted_at).

### 3.15 `risk_alerts` — condicional
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| risk_prediction_id | bigint | FK → risk_predictions.id, UNIQUE, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| dentist_id | bigint | FK → users.id, NOT NULL |
| acknowledged | boolean | NOT NULL, default false |

> Solo se crea si `risk_predictions.risk_level = 'alto'`.

### 3.16 `clinical_followups`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| risk_prediction_id | bigint | FK → risk_predictions.id, NULL |
| actual_outcome | jsonb | NOT NULL |
| recorded_by | bigint | FK → users.id, NOT NULL |

### 3.17 `satisfaction_surveys`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NOT NULL |
| patient_id | bigint | FK → patients.id, NOT NULL |
| type | varchar(20) enum('history_perception','budget_satisfaction') | NOT NULL |
| answers | jsonb | NOT NULL |
| score | smallint | NULL |

### 3.18 `audit_logs`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NULL (eventos de plataforma sin tenant) |
| user_id | bigint | FK → users.id, NULL |
| action | varchar(80) | NOT NULL |
| context | jsonb | NULL (ip, user_agent, recurso afectado) |
| created_at | timestamptz | NOT NULL |

*Índices:* Index(tenant_id, created_at), Index(user_id, action).

### 3.19 `performance_alerts`
| Columna | Tipo | Restricciones |
| :-- | :-- | :-- |
| id | bigserial | PK |
| tenant_id | bigint | FK → tenants.id, NULL |
| metric | varchar(50) | NOT NULL |
| value | numeric(10,2) | NOT NULL |
| threshold | numeric(10,2) | NOT NULL |
| triggered_at | timestamptz | NOT NULL |

### 3.20 Resumen relacional

```
Tenant 1:N User | 1:N Patient | 1:1 EncryptionKey | 1:N TreatmentCatalog
Patient 1:N OdontogramTooth (máx 32) | 1:N Treatment | 1:N Budget
Patient 1:1 PatientRiskVariables (última vigente) | 1:N RiskPrediction | 1:N ClinicalFollowup
Budget 1:N BudgetItem
RiskPrediction 1:1 RiskAlert (condicional, solo si risk_level='alto')
Appointment N:1 Patient | N:1 User(dentist)
```

## 4. RBAC — implementación técnica

### 4.1 Roles (enum `users.role`)
`super_admin`, `clinic_admin`, `dentist`, `receptionist`, `patient`. Adicionalmente `ml_service` como credencial de servicio (no es un valor de `users.role`).

### 4.2 Mecanismos
- **Middleware `ResolveTenant`**: fija el tenant activo; se ejecuta antes de cualquier controlador de datos de clínica.
- **Middleware `EnsureRole:role1,role2`**: bloquea rutas por rol (autorización gruesa a nivel de ruta).
- **Policies por modelo** (`PatientPolicy`, `OdontogramPolicy`, `BudgetPolicy`, `RiskPredictionPolicy`): autorización fina; `patient` solo accede a registros con `patient.user_id = auth()->id()`.
- **Middleware `VerifyMlServiceKey`**: valida el header `X-ML-Service-Key` en los endpoints callback del microservicio ML.
- **React Route Guards**: componente `<RequireRole allow={[...]}>` que lee el rol del token y redirige si no autorizado. Los guards de React son UX; **nunca** sustituyen la autorización del backend.

## 5. Rutas y controladores

> Prefijo global `/api/v1`. Todas las rutas (salvo login) bajo middleware `auth:sanctum` + `ResolveTenant`. Respuestas vía API Resources.

### 5.1 Autenticación y plataforma
| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /auth/login | AuthController@login | — |
| POST | /auth/logout | AuthController@logout | auth:sanctum, ResolveTenant |
| GET | /auth/me | AuthController@me | auth:sanctum, ResolveTenant |
| POST | /tenants | TenantController@store | auth:sanctum, ResolveTenant, EnsureRole:super_admin |
| GET | /tenants | TenantController@index | auth:sanctum, ResolveTenant, EnsureRole:super_admin |
| POST | /tenants/{tenant}/encryption-keys/rotate | EncryptionKeyController@rotate | EnsureRole:super_admin |
| GET | /compliance/report | ComplianceController@report | EnsureRole:super_admin |
| GET | /audit-logs | AuditLogController@index | EnsureRole:super_admin,clinic_admin |
| GET | /performance/alerts | PerformanceAlertController@index | EnsureRole:super_admin,clinic_admin |

**Contrato `POST /auth/login`** (extensión Fase 1, ver nota al inicio del documento):
```json
{
  "tenant_slug": "clinica-sonrisa",
  "email": "dentista@clinica-sonrisa.test",
  "password": "..."
}
```
`tenant_slug` es opcional (`nullable`, valida `exists:tenants,slug`); su ausencia se interpreta como login de `super_admin` (`tenant_id IS NULL`). Respuesta: `{ "token": "...", "user": { ...UserResource } }`.

### 5.2 Usuarios y roles (clínica)
| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| GET | /users | UserController@index | EnsureRole:clinic_admin |
| POST | /users | UserController@store | EnsureRole:clinic_admin |
| PATCH | /users/{user} | UserController@update | EnsureRole:clinic_admin |

### 5.3 Pacientes, ficha e historial
| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| GET | /patients | PatientController@index | EnsureRole:clinic_admin,dentist,receptionist |
| POST | /patients | PatientController@store | EnsureRole:clinic_admin,dentist,receptionist |
| GET | /patients/{patient} | PatientController@show | can:view,patient |
| GET | /patients/{patient}/odontogram | OdontogramController@show | can:view,patient |
| POST | /patients/{patient}/odontogram/{tooth} | OdontogramController@appendState | EnsureRole:dentist |
| GET | /patients/{patient}/odontogram/{tooth}/history | OdontogramController@history | can:view,patient |
| POST | /patients/{patient}/risk-variables | RiskVariableController@store | EnsureRole:dentist,receptionist |

### 5.4 Asistencia IA
| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /patients/{patient}/ai/diagnosis | AiDiagnosisController@suggest | EnsureRole:dentist |
| PATCH | /ai/diagnosis/{suggestion}/decision | AiDiagnosisController@decide | EnsureRole:dentist |
| POST | /patients/{patient}/ai/treatment | AiTreatmentController@suggest | EnsureRole:dentist |
| PATCH | /ai/treatment/{suggestion}/decision | AiTreatmentController@decide | EnsureRole:dentist |

### 5.5 Presupuestos y citas
| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /patients/{patient}/budgets | BudgetController@store | EnsureRole:dentist,receptionist,clinic_admin |
| GET | /budgets/{budget} | BudgetController@show | can:view,budget |
| PATCH | /budgets/{budget}/decision | BudgetController@decide | can:view,budget |
| GET | /budgets/{budget}/pdf | BudgetController@pdf | can:view,budget |
| POST | /appointments | AppointmentController@store | EnsureRole:receptionist,clinic_admin |
| PATCH | /appointments/{appointment}/check-in | AppointmentController@checkIn | EnsureRole:receptionist |
| GET | /appointments/availability | AppointmentController@availability | auth:sanctum |

### 5.6 Predicción de riesgo
| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /patients/{patient}/risk/predict | RiskPredictionController@predict | EnsureRole:dentist |
| GET | /patients/{patient}/risk/latest | RiskPredictionController@latest | EnsureRole:dentist,clinic_admin |
| GET | /risk-predictions/{prediction}/explanation | RiskPredictionController@explanation | EnsureRole:dentist |
| GET | /risk-alerts | RiskAlertController@index | EnsureRole:dentist |
| PATCH | /risk-alerts/{alert}/acknowledge | RiskAlertController@acknowledge | EnsureRole:dentist |
| POST | /patients/{patient}/followups | ClinicalFollowupController@store | EnsureRole:dentist |
| GET | /reports/risk-distribution | RiskReportController@distribution | EnsureRole:clinic_admin |

### 5.7 Encuestas
| Método | Endpoint | Controlador@método | Middleware adicional |
| :-- | :-- | :-- | :-- |
| POST | /patients/{patient}/surveys | SurveyController@store | EnsureRole:receptionist,patient |

### 5.8 Microservicio ML externo (contrato REST — Python/FastAPI)
> Consumido por Laravel (`Services/Ml/RiskEngineClient`). No expuesto al frontend.

| Método | Endpoint (base URL del microservicio) | Propósito |
| :-- | :-- | :-- |
| POST | /predict | Recibe features → devuelve risk_level, score, confidence, explanation (SHAP) |
| GET | /health | Health check para Circuit Breaker |
| GET | /model/version | Versión del modelo activo |

## 6. Reglas de negocio críticas — implementación técnica

### 6.1 Generación de presupuesto (`BudgetService::generate`)
1. Recibir el conjunto de tratamientos requeridos (pieza + `treatment_catalog_id`).
2. Validar que cada tratamiento exista en `treatment_catalog` con `is_active = true` del tenant activo. Si alguno no existe → abortar sin crear presupuesto parcial.
3. Por cada línea: crear `budget_item` copiando `base_price` del catálogo a `unit_price_snapshot`. `subtotal = unit_price_snapshot * quantity`.
4. `total_amount = SUM(subtotales)`.
5. Persistir `budget` (status `issued`) + `budget_items` dentro de una transacción DB.
6. Registrar `cycle_time_seconds`.
7. Generar PDF de forma asíncrona (job en cola); no bloquea la respuesta.

**Inmutabilidad:** un `budget` con status `issued`/`accepted`/`rejected` nunca se modifica ni sus items. El precio persistido es `unit_price_snapshot`, no el precio actual del catálogo. Objetivo de rendimiento: cálculo ≤ 2 s en el 95% de solicitudes.

### 6.2 Persistencia del odontograma (`OdontogramService::appendState`)
1. Localizar la fila `odontogram_teeth` de `(patient_id, tooth_number)`; si no existe, crearla con `history = []`.
2. Construir el objeto de estado (date, status, diagnosis_source, dentist_id, notes, related_treatment_id).
3. Append del objeto al array `history` (jsonb) — nunca reemplazar ni eliminar entradas previas.
4. Actualizar `current_status` con el nuevo estado.
5. Todo en una transacción.

**Prohibido:** cualquier `UPDATE` que sobrescriba una entrada existente de `history`, o que reduzca la longitud del array.

### 6.3 Contrato de comunicación con el microservicio ML (`RiskPredictionService::predict`)
1. Recopilar features del paciente desde `patient_risk_variables`. Si falta alguna variable requerida → abortar y notificar dato faltante (no se llama al ML con payload incompleto).
2. `RiskEngineClient` hace `POST /predict` con **timeout ≤ 3 s**.
3. **Circuit Breaker + fallback:** si el ML no responde, da timeout o devuelve error → no se bloquea la consulta clínica; se devuelve estado `unavailable` al frontend y se registra el fallo.
4. Persistir `risk_prediction` (risk_level, risk_score, confidence, explanation, model_version).
5. `<<extend>>`: solo si `risk_level = 'alto'` → crear `risk_alert` dirigida al `dentist`.
6. `<<include>>`: la explicación (SHAP) siempre acompaña a una predicción calculada; `GET /explanation` lee `risk_predictions.explanation`. No existe explicación sin predicción previa.

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

### 6.4 Aislamiento multi-tenant
- Toda query de modelos multi-tenant pasa por el Global Scope de `BelongsToTenant`. Prohibido construir queries crudas que omitan `tenant_id`.
- El `tenant_id` se asigna en el servidor desde la sesión; nunca se acepta `tenant_id` desde el payload del cliente.
- El único bypass permitido es `super_admin` mediante método explícito `withoutTenantScope()`.

### 6.5 Prevención de doble reserva
- Restricción **UNIQUE(tenant_id, dentist_id, scheduled_at)** a nivel de BD como última línea de defensa.
- `AppointmentService::schedule` valida disponibilidad (solapamiento con `duration_minutes`) antes de insertar; ante violación de la restricción única, devuelve conflicto 409.

## 7. Casos de prueba principales (testing)

> Backend: Pest/PHPUnit. Puntos críticos de fallo derivados de los riesgos identificados y las reglas de negocio. Nombres de test = contrato de comportamiento.

### 7.1 Aislamiento multi-tenant (crítico)
- `test_user_cannot_access_patient_from_another_tenant()` — 403/404 al pedir un `patient` de otro tenant.
- `test_global_scope_filters_all_queries_by_tenant()` — un `index` solo retorna registros del tenant activo.
- `test_tenant_id_is_never_accepted_from_request_payload()` — se ignora un `tenant_id` inyectado en el body.
- `test_super_admin_can_bypass_tenant_scope_only_explicitly()`.

### 7.2 Odontograma append-only
- `test_odontogram_update_appends_to_history_without_overwriting()` — longitud del array crece en 1, entradas previas intactas.
- `test_current_status_reflects_last_appended_state()`.
- `test_history_entries_are_never_deleted_or_mutated()`.

### 7.3 Presupuesto inmutable
- `test_budget_rejects_treatment_not_in_active_catalog()` — aborta sin crear budget parcial.
- `test_budget_item_stores_price_snapshot_not_live_price()` — cambiar catálogo luego no altera el budget.
- `test_issued_budget_cannot_be_modified()`.
- `test_budget_total_equals_sum_of_item_subtotals()`.
- `test_budget_generation_runs_in_single_transaction()` — rollback total ante fallo de una línea.

### 7.4 Integración ML y resiliencia
- `test_predict_endpoint_rejects_incomplete_payload()` — falta variable → no se llama al ML.
- `test_risk_prediction_falls_back_when_ml_service_unavailable()` — timeout → estado `unavailable`, la consulta clínica no se bloquea.
- `test_high_risk_prediction_triggers_risk_alert()` — `risk_level='alto'` crea exactamente 1 alerta.
- `test_non_high_risk_prediction_does_not_create_alert()` — `bajo`/`medio` no crean alerta.
- `test_explanation_is_available_only_after_prediction_exists()` — `<<include>>`.

### 7.5 Doble reserva de citas
- `test_cannot_double_book_same_dentist_same_slot()` — segunda cita en el mismo slot → 409.
- `test_overlapping_appointment_is_rejected()`.

### 7.6 RBAC / autorización
- `test_receptionist_cannot_update_odontogram()` — solo `dentist`.
- `test_patient_can_only_view_own_records()`.
- `test_only_super_admin_can_register_tenant()`.
- `test_ml_callback_requires_valid_service_key()`.

### 7.7 Validación de diagnóstico IA
- `test_dentist_can_accept_adjust_or_reject_ai_suggestion()` — persiste `dentist_decision`.
- `test_ai_suggestion_starts_without_decision()`.

### 7.8 Cifrado
- `test_patient_document_id_is_encrypted_at_rest()` — el valor crudo en BD no es texto plano.

### 7.9 Auditoría
- `test_access_events_are_written_to_audit_log()`.
