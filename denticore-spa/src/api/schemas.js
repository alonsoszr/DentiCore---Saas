// Archivo generado por scripts/gen-api.mjs desde denticore-api/openapi.json. No editar a mano.
import { z } from 'zod'

export const attentionAddendumResourceSchema = z.object({
  id: z.string(),
  text: z.string(),
  chief_complaint: z.union([z.string(), z.null()]),
  author: z.object({ id: z.string(), name: z.string(), cop: z.string() }),
  created_at: z.string().datetime({ offset: true }),
  diagnoses: z
    .array(
      z.object({
        id: z.string(),
        code: z.string(),
        description: z.string(),
        type: z.enum(['presuntivo', 'definitivo']),
        origin: z.enum(['nota', 'adenda']),
        created_at: z.string().datetime({ offset: true }),
      }),
    )
    .optional(),
  attention_status: z.enum(['cerrada', 'cerrada_incompleta']).optional(),
})

export const attentionDiagnosisResourceSchema = z.object({
  id: z.string(),
  code: z.string(),
  description: z.string(),
  type: z.enum(['presuntivo', 'definitivo']),
  origin: z.enum(['nota', 'adenda']),
  created_at: z.string().datetime({ offset: true }),
})

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
  note: z
    .union([
      z.object({
        id: z.string(),
        chief_complaint: z.union([z.string(), z.null()]),
        current_illness: z.union([z.string(), z.null()]),
        extraoral_exam: z.union([z.string(), z.null()]),
        intraoral_exam: z.union([z.string(), z.null()]),
        indications: z.union([z.string(), z.null()]),
        status: z.enum(['borrador', 'firmada']),
        signed_at: z.union([z.string().datetime({ offset: true }), z.null()]),
      }),
      z.null(),
    ])
    .optional(),
  diagnoses: z
    .array(
      z.object({
        id: z.string(),
        code: z.string(),
        description: z.string(),
        type: z.enum(['presuntivo', 'definitivo']),
        origin: z.enum(['nota', 'adenda']),
        created_at: z.string().datetime({ offset: true }),
      }),
    )
    .optional(),
  addenda: z
    .array(
      z.object({
        id: z.string(),
        text: z.string(),
        chief_complaint: z.union([z.string(), z.null()]),
        author: z.object({ id: z.string(), name: z.string(), cop: z.string() }),
        created_at: z.string().datetime({ offset: true }),
        diagnoses: z
          .array(
            z.object({
              id: z.string(),
              code: z.string(),
              description: z.string(),
              type: z.enum(['presuntivo', 'definitivo']),
              origin: z.enum(['nota', 'adenda']),
              created_at: z.string().datetime({ offset: true }),
            }),
          )
          .optional(),
        attention_status: z.enum(['cerrada', 'cerrada_incompleta']).optional(),
      }),
    )
    .optional(),
})

export const budgetLineRequestSchema = z
  .object({
    discount_pct: z.number().gte(0).lte(100),
    discount_reason: z.union([z.string().max(200), z.null()]).optional(),
  })
  .describe(
    'Descuento de una línea del borrador (CUS-35 paso 3; RN-31): 0,00 a 100,00 %. El motivo (5 a 200\ncaracteres si el descuento es mayor que 0) y el tope de la clínica los aplica BudgetService.',
  )

export const budgetResourceSchema = z.object({
  id: z.string(),
  number: z.union([z.string(), z.null()]),
  status: z.enum(['borrador', 'emitido', 'aceptado', 'rechazado', 'vencido', 'reemplazado']),
  plan_id: z.string(),
  patient_id: z.string(),
  corrects_budget_id: z.union([z.string(), z.null()]),
  prices_include_igv: z.boolean(),
  igv_rate: z.string(),
  subtotal: z.string(),
  discount_total: z.string(),
  base_amount: z.string(),
  igv_amount: z.string(),
  total: z.string(),
  validity_days: z.union([z.number().int(), z.null()]),
  issued_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  expires_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  terms: z.union([z.string(), z.null()]),
  dentist: z.union([z.object({ id: z.string(), name: z.string(), cop: z.union([z.string(), z.null()]) }), z.null()]),
  pdf: z.union([z.object({ status: z.enum(['pendiente', 'generando', 'listo', 'fallido']) }), z.null()]),
  decision: z.union([
    z.object({
      channel: z.enum(['portal', 'presencial', 'enlace']),
      by: z.union([z.object({ id: z.string(), name: z.string() }), z.null()]),
      signer: z.enum(['titular', 'representante']),
      decided_at: z.union([z.string().datetime({ offset: true }), z.null()]),
      ip: z.union([z.string(), z.null()]),
      rejection_reason: z.union([
        z.literal('precio'),
        z.literal('segunda_opinion'),
        z.literal('momento_no_oportuno'),
        z.literal('otro'),
        z.literal(null),
      ]),
      rejection_detail: z.union([z.string(), z.null()]),
      signed_file: z.boolean(),
    }),
    z.null(),
  ]),
  replaced_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  expired_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  lines: z.array(
    z.object({
      id: z.string(),
      plan_item_id: z.string(),
      procedure: z.object({ id: z.string(), code: z.string(), name: z.string() }),
      description: z.string(),
      tooth: z.union([z.number().int(), z.null()]),
      surfaces: z.array(z.enum(['M', 'D', 'O', 'I', 'V', 'L', 'P'])),
      unit_price: z.string(),
      quantity: z.number().int(),
      discount_pct: z.string(),
      discount_reason: z.union([z.string(), z.null()]),
      discount_approved: z.boolean(),
      subtotal: z.string(),
    }),
  ),
  created_at: z.string().datetime({ offset: true }),
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

export const clinicalNoteResourceSchema = z.object({
  id: z.string(),
  chief_complaint: z.union([z.string(), z.null()]),
  current_illness: z.union([z.string(), z.null()]),
  extraoral_exam: z.union([z.string(), z.null()]),
  intraoral_exam: z.union([z.string(), z.null()]),
  indications: z.union([z.string(), z.null()]),
  status: z.enum(['borrador', 'firmada']),
  signed_at: z.union([z.string().datetime({ offset: true }), z.null()]),
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

export const findingNoTreatDecisionResourceSchema = z.object({
  id: z.string(),
  finding_id: z.string(),
  reason: z.string(),
  decided_by: z.object({ id: z.string(), name: z.string() }),
  created_at: z.string().datetime({ offset: true }),
})

export const informedConsentResourceSchema = z.object({
  id: z.string(),
  template_version: z.number().int(),
  signer: z.string(),
  representative_id: z.union([z.string(), z.null()]),
  channel: z.string(),
  text_sha256: z.string(),
  signed_at: z.string().datetime({ offset: true }),
  status: z.string(),
  used_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  revoked_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  revocation_reason: z.union([z.string(), z.null()]),
  informed_by: z.union([z.object({ id: z.string(), name: z.string() }), z.null()]),
})

export const informedConsentTemplateResourceSchema = z.object({
  id: z.string(),
  title: z.string(),
  is_active: z.boolean(),
  current_version: z.union([z.number().int(), z.null()]),
  body: z.union([z.string(), z.null()]),
  procedures: z.array(z.string()),
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

export const odontogramEntryResourceSchema = z.object({
  id: z.string(),
  entry_type: z.enum(['inicial', 'evolucion', 'correccion']),
  tooth: z.number().int(),
  tooth_end: z.union([z.number().int(), z.null()]),
  surfaces: z.array(z.enum(['M', 'D', 'O', 'I', 'V', 'L', 'P'])),
  finding: z.union([
    z.object({ code: z.string(), name: z.string(), acronym: z.union([z.string(), z.null()]) }),
    z.null(),
  ]),
  state: z.union([
    z.object({ code: z.string(), name: z.string(), acronym: z.union([z.string(), z.null()]) }),
    z.null(),
  ]),
  color: z.union([z.literal('azul'), z.literal('rojo'), z.literal(null)]),
  origin: z.enum(['manual', 'ia', 'procedimiento']),
  note: z
    .union([
      z.string().describe('SDD §3.4 CUS-21: recepción ve el odontograma sin notas clínicas.'),
      z.null().describe('SDD §3.4 CUS-21: recepción ve el odontograma sin notas clínicas.'),
    ])
    .describe('SDD §3.4 CUS-21: recepción ve el odontograma sin notas clínicas.')
    .optional(),
  corrects_entry_id: z.union([z.string(), z.null()]),
  correction_kind: z.union([z.literal('anulacion'), z.literal('reemplazo'), z.literal(null)]),
  correction_reason: z.union([z.string(), z.null()]),
  corrected_by_id: z
    .union([
      z.string().describe('CA-23.1: la entrada corregida se presenta «corregida», con enlace a su corrección.'),
      z.null().describe('CA-23.1: la entrada corregida se presenta «corregida», con enlace a su corrección.'),
    ])
    .describe('CA-23.1: la entrada corregida se presenta «corregida», con enlace a su corrección.')
    .optional(),
  author: z.object({ id: z.string(), name: z.string(), cop: z.string() }),
  recorded_at: z.string().datetime({ offset: true }),
})

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

export const performedProcedureResourceSchema = z.object({
  id: z.string(),
  quantity: z.number().int(),
  performed_at: z.string().datetime({ offset: true }),
  observations: z.union([z.string(), z.null()]),
  dentist: z.object({ id: z.string(), name: z.string(), cop: z.union([z.string(), z.null()]) }),
  attention_id: z.string(),
  odontogram_entry_id: z.union([z.string(), z.null()]),
  informed_consent_id: z.union([z.string(), z.null()]),
  plan_item: z.object({
    id: z.string(),
    status: z.enum(['propuesto', 'aceptado', 'realizado', 'descartado']),
    quantity: z.number().int(),
    performed_quantity: z.number().int(),
  }),
  plan: z.object({
    id: z.string(),
    status: z.enum(['borrador', 'propuesto', 'aceptado', 'en_ejecucion', 'completado', 'cancelado']),
  }),
  budget_id: z.union([z.string(), z.null()]),
})

export const planItemRequestSchema = z
  .object({
    procedure_id: z.string().optional(),
    tooth: z.union([z.number().int(), z.null()]).optional(),
    surfaces: z.union([z.array(z.string().min(1).max(1)).max(7), z.null()]).optional(),
    quantity: z.number().int().gte(1).lte(32).optional(),
    session_number: z.union([z.number().int().gte(1).lte(32767), z.null()]).optional(),
    observations: z.union([z.string().max(500), z.null()]).optional(),
  })
  .describe(
    'Edición de un ítem `propuesto` del plan en borrador (CUS-33; RF-110, RN-26). Los campos son\nopcionales; la pieza y las superficies se validan con el valor nuevo o el guardado.',
  )

export const planItemResourceSchema = z.object({
  id: z.string(),
  position: z.number().int(),
  procedure: z.object({ id: z.string(), code: z.string(), name: z.string() }),
  tooth: z.union([z.number().int(), z.null()]),
  surfaces: z.array(z.enum(['M', 'D', 'O', 'I', 'V', 'L', 'P'])),
  quantity: z.number().int(),
  performed_quantity: z.number().int(),
  session_number: z.union([z.number().int(), z.null()]),
  observations: z.union([z.string(), z.null()]),
  status: z.enum(['propuesto', 'aceptado', 'realizado', 'descartado']),
  discard_reason: z.union([z.string(), z.null()]),
  origin: z.enum(['manual', 'ia']),
  finding_ids: z.array(z.string()),
})

export const planItemsRequestSchema = z
  .object({
    items: z
      .array(
        z.object({
          procedure_id: z.string(),
          tooth: z.union([z.number().int(), z.null()]).optional(),
          surfaces: z.union([z.array(z.string().min(1).max(1)).max(7), z.null()]).optional(),
          quantity: z.number().int().gte(1).lte(32).optional(),
          session_number: z.union([z.number().int().gte(1).lte(32767), z.null()]).optional(),
          observations: z.union([z.string().max(500), z.null()]).optional(),
          finding_ids: z
            .array(z.string().uuid())
            .refine((arr) => arr.every((item, i) => arr.indexOf(item) == i), 'All items must be unique!')
            .optional(),
        }),
      )
      .min(1),
  })
  .describe(
    'Ítems nuevos de un plan (CUS-33; RF-110, RF-111, RN-26): procedimiento activo del catálogo,\npieza, superficies, cantidad (1–32), sesión opcional, observaciones y hallazgos que atiende.\nLa pieza y las superficies según el procedimiento las valida ClinicalValidator en el servicio.',
  )

export const problemDetailsSchema = z.object({
  type: z.string(),
  title: z.string(),
  status: z.number().int(),
  detail: z.string(),
  instance: z.string(),
  rule: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
})

export const procedureRequestSchema = z
  .object({
    code: z.string().max(30),
    name: z.string().max(150),
    category: z.union([z.string().max(60), z.null()]).optional(),
    price: z.number().gte(0).lte(99999.99),
    requires_tooth: z.boolean(),
    requires_surface: z.boolean(),
    requires_informed_consent: z.boolean().optional(),
    resulting_finding_code: z.union([z.string().max(20), z.null()]).optional(),
    resulting_state_code: z.union([z.string().max(20), z.null()]).optional(),
    is_active: z.boolean().optional(),
  })
  .describe(
    'Alta y edición de un procedimiento del catálogo (CUS-32; RF-107, RN-26, RN-39, RN-76). En la\nedición los campos son opcionales; las reglas que combinan campos usan el valor nuevo o, si no\nllega, el guardado.',
  )

export const procedureResourceSchema = z.object({
  id: z.string(),
  code: z.string(),
  name: z.string(),
  category: z.union([z.string(), z.null()]),
  price: z.string(),
  requires_tooth: z.boolean(),
  requires_surface: z.boolean(),
  requires_informed_consent: z.boolean(),
  resulting_finding: z.union([
    z.object({ code: z.string(), name: z.string(), acronym: z.union([z.string(), z.null()]) }),
    z.null(),
  ]),
  resulting_state: z.union([
    z.object({
      code: z.string(),
      name: z.string(),
      color: z.enum(['azul', 'rojo']),
      acronym: z.union([z.string(), z.null()]),
    }),
    z.null(),
  ]),
  is_active: z.boolean(),
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

export const treatmentPlanRequestSchema = z
  .object({
    title: z.string().max(150),
    items: z
      .array(
        z.object({
          procedure_id: z.string(),
          tooth: z.union([z.number().int(), z.null()]).optional(),
          surfaces: z.union([z.array(z.string().min(1).max(1)).max(7), z.null()]).optional(),
          quantity: z.number().int().gte(1).lte(32).optional(),
          session_number: z.union([z.number().int().gte(1).lte(32767), z.null()]).optional(),
          observations: z.union([z.string().max(500), z.null()]).optional(),
          finding_ids: z
            .array(z.string().uuid())
            .refine((arr) => arr.every((item, i) => arr.indexOf(item) == i), 'All items must be unique!')
            .optional(),
        }),
      )
      .optional(),
  })
  .describe(
    'Alta y edición del plan de tratamiento (CUS-33; RF-110). El alta admite sus primeros ítems;\nla edición, solo el título (los ítems tienen sus propias rutas).',
  )

export const treatmentPlanResourceSchema = z.object({
  id: z.string(),
  patient_id: z.string(),
  title: z.string(),
  status: z.enum(['borrador', 'propuesto', 'aceptado', 'en_ejecucion', 'completado', 'cancelado']),
  origin: z.enum(['manual', 'ia', 'urgencia', 'alerta']),
  created_by: z.object({ id: z.string(), name: z.string() }),
  items: z.array(
    z.object({
      id: z.string(),
      position: z.number().int(),
      procedure: z.object({ id: z.string(), code: z.string(), name: z.string() }),
      tooth: z.union([z.number().int(), z.null()]),
      surfaces: z.array(z.enum(['M', 'D', 'O', 'I', 'V', 'L', 'P'])),
      quantity: z.number().int(),
      performed_quantity: z.number().int(),
      session_number: z.union([z.number().int(), z.null()]),
      observations: z.union([z.string(), z.null()]),
      status: z.enum(['propuesto', 'aceptado', 'realizado', 'descartado']),
      discard_reason: z.union([z.string(), z.null()]),
      origin: z.enum(['manual', 'ia']),
      finding_ids: z.array(z.string()),
    }),
  ),
  progress: z.object({
    items_total: z.number().int(),
    items_performed: z.number().int(),
    performed_amount: z.string(),
    accepted_amount: z.union([z.string(), z.null()]),
  }),
  cancel_reason: z.union([z.string(), z.null()]),
  cancelled_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  completed_at: z.union([z.string().datetime({ offset: true }), z.null()]),
  created_at: z.string().datetime({ offset: true }),
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
