# Registro de migraciones expandir/contraer de E1

TASK-096. Cubre RNF-132 y RNF-136.

Esta es la lista de las migraciones que siguen el patrón expandir → poblar → cambiar → contraer (Plan §1.5) y que ya están aplicadas en `main` al cerrar E1. La reconciliación con el estado base sigue la tabla del Plan §2.3. Los commits son los de la integración en `main`.

## Reglas

- La expansión y la contracción van en PR distintos.
- La contracción se registra como irreversible.
- Desde MS-10, una contracción nunca va en la misma versión que su expansión (RNF-142).

En E1 no hubo despliegues: solo existen el entorno local y la CI, con datos sintéticos (RES-08). Por eso las contracciones se aplicaron en cuanto el código dejó de leer lo viejo.

## Reconciliación con el estado base (Plan §2.3)

Las cinco reconciliaciones se expandieron en tareas distintas y se contrajeron juntas en TASK-038, con la migración `2026_10_06_000100_contract_legacy_columns.php` (commit `242b9f5`, 2026-10-06).

| Objeto | Expansión (y población) | Qué se contrajo | Estado |
| :-- | :-- | :-- | :-- |
| `encryption_keys` | `2026_09_27_230452_expand_encryption_keys_with_version_and_status.php` (TASK-013, `c0c996c`, 2026-09-28): `version = 1` y `status` desde `is_active`. | Se eliminaron `is_active` y el `UNIQUE (tenant_id)` total. Queda el único parcial de la clave `activa`. | Contraída |
| Texto cifrado de `patients` | Comando idempotente `encryption:reencrypt-legacy` (TASK-013, `c0c996c`): pasa los textos a AES-256-GCM con el sobre `v{n}:`. | Se retiraron el descifrado del formato sin versión y el comando (`TenantEncryption`). | Contraída |
| `tenants.status`, `subscription_plan` y `settings` | `2026_10_05_000100_create_platform_tables_and_expand_tenants.php` (TASK-021, `12a4495`, 2026-10-05): `subscription_plans`, `clinic_settings` y `document_sequences`. `status` se convirtió en el mismo lugar a los valores de DI-03. | Se eliminaron `subscription_plan` y `settings`. `legal_name`, `ruc` y `address` pasaron a NOT NULL. | Contraída |
| `users.is_active` | `2026_10_05_000300_expand_identity_tables.php` (TASK-026, `caa43d3`, 2026-10-05): `status` desde el booleano y COP sintético para los odontólogos. | Se eliminó `is_active`. | Contraída |
| `patients.document_id` y `document_id_hash` | `2026_10_05_000400_expand_patients_table.php` (TASK-031, `48ba363`, 2026-10-05): `document_number`, `document_hash` con la clave `bidx`, número de HC y `search_name`. | Se eliminaron las columnas viejas. La identificación de SDD §2.5 pasó a NOT NULL. | Contraída |

**Irreversible.** `contract_legacy_columns` no tiene vuelta atrás: los datos de las columnas eliminadas no se pueden reconstruir. Una base local anterior a MS-01 se regenera con `php artisan migrate:fresh --seed --database=pgsql_migrator` (solo hay datos sintéticos).

### Contratos de la API reconciliados

| Contrato heredado | Reemplazo | Retiro |
| :-- | :-- | :-- |
| `POST /tenants` con contraseña | `POST /platform/tenants` con invitación (DD-22) | TASK-023 y TASK-039 (2026-10-06) |
| `POST /patients` con `user_uuid` | Las cuentas de portal sintéticas las crea la semilla (S-14) | TASK-038: `user_uuid` ya no se acepta en la entrada |
| `GET /patients/{patient}` para el rol `patient` | Portal `/api/v1/portal/*` (DI-13) | TASK-089 (`942b2b5`, 2026-10-16): responde 403 |

## FK agregadas por expansión (Plan §1.5)

Son columnas nulables que se agregan cuando existe la tabla referida. No tienen contracción.

| Columna | Migración | Tarea |
| :-- | :-- | :-- |
| `attentions.appointment_id` (UNIQUE) | `2026_10_10_000100_create_scheduling_tables.php` | TASK-064 (`35c1255`) |
| `appointments.risk_alert_id` y `treatment_plans.risk_alert_id` (FK compuesta `(tenant_id, risk_alert_id)`) | `2026_10_11_000100_create_risk_tables.php` | TASK-078 (`4f7fb07`) |

**Pendientes en E2 y E3:**
- `patient_identity_history.arco_request_id` y `consent_purpose_revocations.arco_request_id` (TASK-103);
- `appointments.periodic_control_id` (TASK-151).

## Cómo verificarlo

```bash
cd denticore-api
php artisan migrate:status --database=pgsql_migrator   # 30 migraciones, todas «Ran»
```

Las pruebas `tests/Feature/Support/SchemaIntegrityTest.php` y `RowLevelSecurityTest.php` fallan si falta una FK, un CHECK o la RLS de una tabla de clínica.
