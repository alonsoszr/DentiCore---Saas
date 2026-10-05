// Archivo generado por scripts/gen-api.mjs desde denticore-api/openapi.json. No editar a mano.
import { z } from 'zod'

export const loginRequestSchema = z.object({
  tenant_slug: z.union([z.string(), z.null()]).optional(),
  email: z.string().email(),
  password: z.string(),
})

export const patientResourceSchema = z.object({
  id: z.string(),
  document_id: z.union([z.string(), z.null()]),
  first_name: z.string(),
  last_name: z.string(),
  birth_date: z.string(),
  phone: z.union([z.string(), z.null()]),
  email: z.union([z.string(), z.null()]),
  medical_history: z.union([
    z.object({
      alergias: z.array(z.string()),
      enfermedades: z.array(z.string()),
      medicamentos: z.array(z.string()),
      observaciones: z.union([z.string(), z.null()]),
    }),
    z.null(),
  ]),
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
    document_id: z.string().max(20),
    first_name: z.string().max(100),
    last_name: z.string().max(100),
    birth_date: z.string().date().describe('Fecha civil (SDD §2.1): AAAA-MM-DD.'),
    phone: z.union([z.string().max(20), z.null()]).optional(),
    email: z.union([z.string().email().max(180), z.null()]).optional(),
    medical_history: z
      .object({
        alergias: z.array(z.union([z.string().max(150), z.null()])),
        enfermedades: z.array(z.union([z.string().max(150), z.null()])),
        medicamentos: z.array(z.union([z.string().max(150), z.null()])),
        observaciones: z.union([z.string().max(2000), z.null()]),
      })
      .describe('Estructura de SDD §2.14.1.')
      .optional(),
    user_uuid: z.union([z.string().uuid(), z.null()]).optional(),
  })
  .describe(
    'Alta de paciente (CUS-14, contrato heredado). Los límites de longitud se aplican sobre el valor en\nclaro (las columnas cifradas son `text`). La unicidad del DNI y la validez de\nuser_uuid se comprueban en PatientService (requieren el índice ciego y el tenant).',
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

export const storeUserRequestSchema = z.object({
  name: z.string().max(150),
  email: z.string().email().max(180),
  password: z.string(),
  role: z.enum(['clinic_admin', 'dentist', 'receptionist', 'patient']),
  is_active: z.boolean().optional(),
  cop_number: z
    .union([
      z.string().max(10).describe('RN-75, RF-043: número de COP obligatorio para odontólogos y único en la clínica.'),
      z.null().describe('RN-75, RF-043: número de COP obligatorio para odontólogos y único en la clínica.'),
    ])
    .describe('RN-75, RF-043: número de COP obligatorio para odontólogos y único en la clínica.')
    .optional(),
})

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
  legal_name: z.union([z.string(), z.null()]),
  ruc: z.union([z.string(), z.null()]),
  slug: z.string(),
  address: z.union([z.string(), z.null()]),
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

export const updateUserRequestSchema = z.object({
  name: z.string().max(150).optional(),
  email: z.string().email().max(180).optional(),
  password: z.string().optional(),
  role: z.enum(['clinic_admin', 'dentist', 'receptionist', 'patient']).optional(),
  is_active: z.boolean().optional(),
  cop_number: z
    .union([
      z.string().max(10).describe('RN-75, RF-043: quien pasa a odontólogo sin COP registrado debe indicarlo.'),
      z.null().describe('RN-75, RF-043: quien pasa a odontólogo sin COP registrado debe indicarlo.'),
    ])
    .describe('RN-75, RF-043: quien pasa a odontólogo sin COP registrado debe indicarlo.')
    .optional(),
})

export const userResourceSchema = z.object({
  id: z.string(),
  name: z.string(),
  email: z.string(),
  role: z.string(),
  cop_number: z.union([z.string(), z.null()]),
  is_active: z.boolean(),
  tenant: z
    .union([
      z.object({
        id: z.string(),
        name: z.string(),
        legal_name: z.union([z.string(), z.null()]),
        ruc: z.union([z.string(), z.null()]),
        slug: z.string(),
        address: z.union([z.string(), z.null()]),
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
