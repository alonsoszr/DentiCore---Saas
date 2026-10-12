// Etiquetas del riesgo de caries (SRS RF-161; SDD §2.14.2, §3.5; DESIGN.md › Status palette).

export const RISK_LEVELS = {
  bajo: 'Riesgo bajo',
  medio: 'Riesgo medio',
  alto: 'Riesgo alto',
}

/** Roles que ven la predicción (SDD §3.5): el administrador con campos reducidos y el odontólogo completo. */
export const PREDICTION_ROLES = ['clinic_admin', 'dentist']

/** Roles que registran variables, cada uno sus secciones (RF-162). */
export const VARIABLE_ROLES = ['dentist', 'receptionist']

/** Secciones que escribe cada rol (RF-162). */
export const SECTIONS_BY_ROLE = {
  dentist: ['clinical', 'behavioral'],
  receptionist: ['sociodemographic'],
}

export const SECTION_TITLES = {
  clinical: 'Variables clínicas',
  behavioral: 'Variables conductuales',
  sociodemographic: 'Datos sociodemográficos',
}

/** Campos de cada sección con sus dominios (SDD §2.14.2). `kind`: number | select | boolean. */
export const FIELDS = {
  clinical: [
    {
      key: 'cpod',
      label: 'CPOD',
      kind: 'number',
      min: 0,
      max: 32,
      step: 1,
      hint: 'Dentición permanente o mixta (0 a 32).',
      dentitions: ['mixta', 'permanente'],
    },
    {
      key: 'ceod',
      label: 'ceod',
      kind: 'number',
      min: 0,
      max: 20,
      step: 1,
      hint: 'Dentición temporal o mixta (0 a 20).',
      dentitions: ['temporal', 'mixta'],
    },
    {
      key: 'plaque_index_pct',
      label: 'Índice de placa (%)',
      kind: 'number',
      min: 0,
      max: 100,
      step: 0.1,
      hint: '0 a 100, con un decimal.',
    },
    { key: 'active_lesions', label: 'Lesiones activas', kind: 'number', min: 0, max: 32, step: 1, hint: '0 a 32.' },
  ],
  behavioral: [
    {
      key: 'sugar_between_meals',
      label: 'Azúcar entre comidas (veces al día)',
      kind: 'select',
      options: [
        ['0', '0'],
        ['1', '1'],
        ['2', '2'],
        ['3', '3'],
        ['4+', '4 o más'],
      ],
    },
    { key: 'fluoride_toothpaste', label: 'Usa pasta dental fluorada', kind: 'boolean' },
    {
      key: 'brushing_per_day',
      label: 'Cepillado diario (veces)',
      kind: 'select',
      options: [
        ['0', '0'],
        ['1', '1'],
        ['2', '2'],
        ['3+', '3 o más'],
      ],
    },
    {
      key: 'dental_visits_per_year',
      label: 'Visitas al odontólogo por año',
      kind: 'select',
      options: [
        ['<1', 'Menos de 1'],
        ['1', '1'],
        ['2+', '2 o más'],
      ],
    },
  ],
  sociodemographic: [
    {
      key: 'education_level',
      label: 'Nivel educativo',
      kind: 'select',
      options: [
        ['sin_estudios', 'Sin estudios'],
        ['primaria', 'Primaria'],
        ['secundaria', 'Secundaria'],
        ['tecnica', 'Técnica'],
        ['universitaria', 'Universitaria'],
      ],
    },
    {
      key: 'employment_status',
      label: 'Situación laboral',
      kind: 'select',
      options: [
        ['formal', 'Formal'],
        ['informal', 'Informal'],
        ['desempleado', 'Desempleado'],
        ['otra', 'Otra'],
      ],
    },
    {
      key: 'family_structure',
      label: 'Estructura familiar',
      kind: 'select',
      minorOnly: true,
      options: [
        ['biparental', 'Biparental'],
        ['monoparental', 'Monoparental'],
        ['extendida', 'Extendida'],
        ['otra', 'Otra'],
      ],
    },
  ],
}

/** Dentición según la edad, como `DentitionResolver::default` de la API (DI-20): define si se pide CPOD, ceod o ambos. */
export function dentitionFor(ageYears) {
  if (ageYears < 6) return 'temporal'
  return ageYears <= 12 ? 'mixta' : 'permanente'
}

/** Nombre en español de una variable que falta (`missing` de la API). */
export function missingLabel(key) {
  for (const fields of Object.values(FIELDS)) {
    const field = fields.find((candidate) => candidate.key === key)
    if (field) return field.label
  }
  if (key === 'respondent') return 'Informante'
  return key
}
