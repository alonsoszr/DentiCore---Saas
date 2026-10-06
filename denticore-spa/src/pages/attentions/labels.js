// Etiquetas de la atención compartidas por la historia clínica y la pantalla de la atención.

export const ATTENTION_STATUS = { abierta: 'Abierta', cerrada: 'Cerrada', cerrada_incompleta: 'Cerrada incompleta' }

export const DENTITIONS = { permanente: 'Permanente', temporal: 'Temporal', mixta: 'Mixta' }

/** Secciones de la nota (RF-084) con su límite de caracteres. */
export const NOTE_SECTIONS = [
  ['chief_complaint', 'Motivo de consulta', 2000],
  ['current_illness', 'Enfermedad actual', 5000],
  ['extraoral_exam', 'Examen extraoral', 5000],
  ['intraoral_exam', 'Examen intraoral', 5000],
  ['indications', 'Indicaciones', 5000],
]
