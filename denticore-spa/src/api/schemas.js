// Archivo generado por scripts/gen-api.mjs desde denticore-api/openapi.json. No editar a mano.
import { z } from 'zod'

export const attentionResourceSchema = z.object({
  id: z.string(),
  patient_id: z.string(),
  dentist: z.object({ id: z.string(), name: z.string(), cop: z.union([z.string(), z.null()]) }),
  status: z.enum(['abierta', 'cerrada', 'cerrada_incompleta']),
  is_first_attention: z.boolean(),
  opened_at: z.string().datetime({ offset: true }),
  clinical_started_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  closed_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  closed_by_system: z.boolean(),
  signer: z.union([z.object({ id: z.string(), name: z.string(), cop: z.union([z.string(), z.null()]) }), z.null()]),
  signed_at: z.union([z.string().datetime({ offset: true }), z.null()]),
})

export const businessRuleExceptionSchema = z.object({
  rule: z.string(),
  errors: z.record(z.string(), z.array(z.string())),
  status: z.number().int(),
  extensions: z.record(z.string(), z.union([z.string(), z.null()])),
})

export const clinicSettingsResourceSchema = z.object({
  id: z.string(),
  name: z.string(),
  address: z.string(),
  phone: z.union([z.string(), z.null()]),
  contact_email: z.union([z.string(), z.null()]),
  logo: z.union([z.object({ id: z.string(), status: z.string(), url: z.union([z.string(), z.null()]) }), z.null()]),
  prices_include_igv: z.boolean(),
  discount_cap_pct: z.string(),
  budget_validity_days: z.number().int(),
  portal_cancel_hours: z.number().int(),
  self_booking_enabled: z.boolean(),
  ai_enabled: z.boolean(),
  budget_terms: z.union([z.string(), z.null()]),
})

export const consentResourceSchema = z.object({
  id: z.string(),
  template_version: z.number().int(),
  purpose_care: z.boolean(),
  purpose_notifications: z.boolean(),
  purpose_ai: z.boolean(),
  purpose_risk: z.boolean(),
  purpose_surveys: z.boolean(),
  granted_by: z.enum(['titular', 'representante']),
  representative_id: z.union([z.string(), z.null()]),
  channel: z.enum(['presencial', 'portal', 'papel']),
  text_sha256: z.string(),
  granted_at: z.string().datetime({ offset: true }),
  status: z.enum(['vigente', 'revocado', 'sustituido']),
  superseded_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  revoked_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  outdated: z.boolean(),
})

export const legalRepresentativeResourceSchema = z.object({
  id: z.string(),
  document_type: z.string(),
  document_number: z.string(),
  first_name: z.string(),
  last_name: z.string(),
  relationship: z.string(),
  phone: z.string(),
  email: z.union([z.string(), z.null()]),
  valid_from: z.string(),
  valid_until: z.union([z.string(), z.null()]),
  ended_reason: z.union([z.string(), z.null()]),
  is_current: z.boolean(),
})

export const loginRequestSchema = z
  .object({
    tenant_slug: z.union([z.string().max(50), z.null()]).optional(),
    email: z.string().email().max(180),
    password: z.string().max(128),
  })
  .describe(
    'Inicio de sesión (CUS-06; SRS §11.2, Datos). La clínica la resuelve `tenant.slug:login` por el\ncódigo de acceso, con el mismo 401 si no existe (no se valida su existencia aquí para no\nrevelarla). La política de longitud mínima se aplica al definir la contraseña, no al ingresar.',
  )

export const patientResourceSchema = z.object({
  id: z.string(),
  document_type: z.union([z.string(), z.null()]),
  document_number: z.union([z.string(), z.null()]),
  clinical_record_number: z.union([z.string(), z.null()]),
  first_name: z.string(),
  last_name: z.string(),
  birth_date: z.string(),
  age_years: z.number().int(),
  is_minor: z.boolean(),
  sex: z.union([z.string(), z.null()]),
  phone: z.union([z.string(), z.null()]),
  email: z.union([z.string(), z.null()]),
  address: z.union([z.string(), z.null()]),
  archive_status: z.string(),
  has_current_consent: z.boolean(),
  consent_outdated: z.boolean(),
  medical_history: z.union([
    z.object({
      alergias: z.array(z.string()),
      enfermedades: z.array(z.string()),
      medicamentos: z.array(z.string()),
      observaciones: z.union([z.string(), z.null()]),
    }),
    z.null(),
  ]),
  allergies: z.array(z.string()),
  user_uuid: z.union([z.string(), z.null()]).optional(),
  created_at: z.union([z.string().datetime({ offset: true }), z.null()]),
})

export const problemDetailsSchema = z.object({
  type: z.string(),
  title: z.string(),
  status: z.number().int(),
  detail: z.string(),
  instance: z.string(),
  rule: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
})

export const storePatientRequestSchema = z
  .object({
    document_type: z.enum(['dni', 'ce', 'pasaporte', 'cpp']),
    document_number: z.string(),
    first_name: z.string().min(1).max(100),
    last_name: z.string().min(1).max(100),
    birth_date: z.string().date().describe('Fecha civil (SDD §2.1): AAAA-MM-DD.'),
    sex: z.enum(['femenino', 'masculino']),
    phone: z.string().regex(new RegExp('^(9\\d{8}|\\+[1-9]\\d{7,14})$')),
    email: z.union([z.string().email().max(180), z.null()]).optional(),
    address: z.union([z.string().min(5).max(200), z.null()]).optional(),
    representative: z.union([z.array(z.string()), z.null()]).optional(),
  })
  .describe(
    'Alta de paciente (CUS-14; contrato de SDD §4.5 y datos de SRS §11.3). La fecha de nacimiento no\npuede ser posterior a hoy ni dar más de 120 años (fecha de la clínica). Un menor de 18 años\ndebe traer su representante legal (RN-12, CA-14.4). La unicidad del documento la comprueba\nPatientService con el índice ciego.',
  )

export const storeTenantRequestSchema = z
  .object({
    name: z.string().min(3).max(150),
    legal_name: z.string().min(3).max(200),
    ruc: z.string(),
    slug: z
      .string()
      .regex(new RegExp('^[a-z0-9]([a-z0-9-]{1,48})[a-z0-9]$'))
      .describe('SRS §11.1: 3–50 caracteres [a-z0-9-], sin guion al inicio ni al final.'),
    address: z.string().min(5).max(200),
    subscription_plan: z.string(),
    admin: z.object({ name: z.string().min(3).max(150), email: z.string().email().max(180) }),
  })
  .describe(
    'Alta de clínica con invitación (CUS-01; validaciones de SRS §11.1, RF-013, RF-014, DD-22).\nEl primer administrador no recibe contraseña: la define al activar su cuenta.',
  )

export const storeUserRequestSchema = z
  .object({
    name: z.string().min(3).max(150),
    email: z.string().email().max(180),
    role: z.enum(['clinic_admin', 'dentist', 'receptionist', 'patient']),
    cop_number: z
      .union([
        z.string().max(10).describe('RN-75, RF-043: número de COP obligatorio para odontólogos y único en la clínica.'),
        z.null().describe('RN-75, RF-043: número de COP obligatorio para odontólogos y único en la clínica.'),
      ])
      .describe('RN-75, RF-043: número de COP obligatorio para odontólogos y único en la clínica.')
      .optional(),
    specialty: z.union([z.string().max(100), z.null()]).optional(),
    rne_number: z.union([z.string().max(10), z.null()]).optional(),
    is_data_officer: z
      .boolean()
      .describe('RF-047: solo un Administrador de Clínica puede ser Oficial de Datos Personales.')
      .optional(),
  })
  .describe(
    'Alta de usuario de la clínica con invitación (CUS-11; RF-042, RF-043, RF-047, DD-22): sin\ncontraseña, que el usuario define al activar su cuenta. `super_admin` no es asignable (§3.7).',
  )

export const subscriptionPlanResourceSchema = z.object({
  id: z.string(),
  code: z.string(),
  name: z.string(),
  max_dentists: z.union([z.number().int(), z.null()]),
  includes_ai: z.boolean(),
  includes_risk: z.boolean(),
  includes_analytics: z.boolean(),
})

export const tenantResourceSchema = z.object({
  id: z.string(),
  name: z.string(),
  legal_name: z.string(),
  ruc: z.string(),
  slug: z.string(),
  address: z.string(),
  phone: z.union([z.string(), z.null()]),
  contact_email: z.union([z.string(), z.null()]),
  plan: z
    .object({
      id: z.string(),
      code: z.string(),
      name: z.string(),
      max_dentists: z.union([z.number().int(), z.null()]),
      includes_ai: z.boolean(),
      includes_risk: z.boolean(),
      includes_analytics: z.boolean(),
    })
    .optional(),
  status: z.string(),
  status_reason: z.union([z.string(), z.null()]),
  timezone: z.string(),
  active_dentists: z.union([z.number().int(), z.null()]).optional(),
  admin: z.object({ id: z.string(), name: z.string(), email: z.string(), status: z.string() }).optional(),
  created_at: z.union([z.string().datetime({ offset: true }), z.null()]),
})

export const updateClinicSettingsRequestSchema = z
  .object({
    name: z.string().min(3).max(150).optional(),
    address: z.string().min(5).max(200).optional(),
    phone: z.union([z.string().max(20), z.null()]).optional(),
    contact_email: z.union([z.string().email().max(180), z.null()]).optional(),
    prices_include_igv: z.boolean().optional(),
    discount_cap_pct: z.number().gte(0).lte(100).optional(),
    budget_validity_days: z.number().int().gte(1).lte(180).optional(),
    portal_cancel_hours: z.number().int().gte(0).lte(72).optional(),
    self_booking_enabled: z.boolean().optional(),
    budget_terms: z.union([z.string().max(2000), z.null()]).optional(),
    ai_enabled: z.string().optional(),
    complaints_book_url: z.string().optional(),
  })
  .describe(
    'Parámetros de la clínica (CUS-04): los límites son los CHECK de `clinic_settings` (SDD §2.3;\nRN-31, RN-35, RN-49) y de RF-024. `ai_enabled` (RF-027) se acepta desde MS-12 y\n`complaints_book_url` (RNF-164) desde MS-13.',
  )

export const updatePatientRequestSchema = z
  .object({
    document_type: z.enum(['dni', 'ce', 'pasaporte', 'cpp']).optional(),
    document_number: z.string().optional(),
    first_name: z.string().min(1).max(100).optional(),
    last_name: z.string().min(1).max(100).optional(),
    birth_date: z.string().date().optional(),
    sex: z.enum(['femenino', 'masculino']).optional(),
    phone: z.string().regex(new RegExp('^(9\\d{8}|\\+[1-9]\\d{7,14})$')).optional(),
    email: z.union([z.string().email().max(180), z.null()]).optional(),
    address: z.union([z.string().min(5).max(200), z.null()]).optional(),
  })
  .describe(
    'Actualización de la identificación y el contacto del paciente (CUS-15; RF-062, SRS §11.3). El\nnúmero de historia clínica no cambia (RN-79). El tipo y el número de documento van juntos.',
  )

export const updateTenantRequestSchema = z
  .object({
    name: z.string().min(3).max(150).optional(),
    legal_name: z.string().min(3).max(200).optional(),
    ruc: z.string().optional(),
    address: z.string().min(5).max(200).optional(),
  })
  .describe(
    'Edición de los datos de una clínica (CUS-01; RF-013, RF-014). El código de acceso no se\nacepta: es inmutable (DD-29) y el plan se cambia con PUT …/plan (CUS-03).',
  )

export const updateUserRequestSchema = z
  .object({
    name: z.string().min(3).max(150).optional(),
    email: z.string().email().max(180).optional(),
    role: z.enum(['clinic_admin', 'dentist', 'receptionist', 'patient']).optional(),
    cop_number: z
      .union([
        z.string().max(10).describe('RN-75, RF-043: quien es (o pasa a ser) odontólogo debe tener COP.'),
        z.null().describe('RN-75, RF-043: quien es (o pasa a ser) odontólogo debe tener COP.'),
      ])
      .describe('RN-75, RF-043: quien es (o pasa a ser) odontólogo debe tener COP.')
      .optional(),
    specialty: z.union([z.string().max(100), z.null()]).optional(),
    rne_number: z.union([z.string().max(10), z.null()]).optional(),
    is_data_officer: z.boolean().optional(),
  })
  .describe(
    'Edición de un usuario de la clínica (CUS-11; RF-042, RF-043, RF-045, RF-047). La desactivación\ny la reactivación tienen sus propias rutas; la contraseña la define solo su dueño.',
  )

export const userResourceSchema = z.object({
  id: z.string(),
  name: z.string(),
  email: z.string(),
  role: z.string(),
  status: z.string(),
  is_data_officer: z.boolean(),
  cop_number: z.union([z.string(), z.null()]),
  specialty: z.union([z.string(), z.null()]),
  rne_number: z.union([z.string(), z.null()]),
  last_login_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  tenant: z
    .union([
      z.object({
        id: z.string(),
        name: z.string(),
        legal_name: z.string(),
        ruc: z.string(),
        slug: z.string(),
        address: z.string(),
        phone: z.union([z.string(), z.null()]),
        contact_email: z.union([z.string(), z.null()]),
        plan: z
          .object({
            id: z.string(),
            code: z.string(),
            name: z.string(),
            max_dentists: z.union([z.number().int(), z.null()]),
            includes_ai: z.boolean(),
            includes_risk: z.boolean(),
            includes_analytics: z.boolean(),
          })
          .optional(),
        status: z.string(),
        status_reason: z.union([z.string(), z.null()]),
        timezone: z.string(),
        active_dentists: z.union([z.number().int(), z.null()]).optional(),
        admin: z.object({ id: z.string(), name: z.string(), email: z.string(), status: z.string() }).optional(),
        created_at: z.union([z.string().datetime({ offset: true }), z.null()]),
      }),
      z.null(),
    ])
    .optional(),
  patient_uuid: z.union([z.string(), z.null()]).optional(),
})
