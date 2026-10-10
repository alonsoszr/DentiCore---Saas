// Etiquetas y ayudantes del plan de tratamiento (SRS §5.5.2; CUS-32 a CUS-34, CUS-40).
import { formatCurrency } from '../../ui/format'

export const PLAN_STATUS = {
  borrador: 'Borrador',
  propuesto: 'Propuesto',
  aceptado: 'Aceptado',
  en_ejecucion: 'En ejecución',
  completado: 'Completado',
  cancelado: 'Cancelado',
}

export const ITEM_STATUS = {
  propuesto: 'Propuesto',
  aceptado: 'Aceptado',
  realizado: 'Realizado',
  descartado: 'Descartado',
}

/** Estados finales: el plan ya no admite cambios (SRS §5.5.2). */
export const FINAL_PLAN_STATUSES = ['completado', 'cancelado']

/** Clase del badge: los estados finales o detenidos se muestran atenuados (siempre con texto). */
export function statusBadge(status) {
  return ['cancelado', 'descartado', 'borrador'].includes(status) ? 'badge badge-muted' : 'badge'
}

/** Avance del plan (RF-130): ítems realizados sobre total y monto realizado sobre aceptado. */
export function progressText(progress) {
  if (!progress) return '—'
  const items = `${progress.items_performed} de ${progress.items_total} ítems realizados`
  if (progress.accepted_amount === null) return items

  return `${items} · ${formatCurrency(progress.performed_amount)} de ${formatCurrency(progress.accepted_amount)}`
}

/** Pieza y superficies de un ítem o hallazgo: «36 (O, M)»; un tramo, «38–48». */
export function siteText(tooth, surfaces = [], toothEnd = null) {
  if (tooth === null || tooth === undefined) return '—'
  const piece = toothEnd === null || toothEnd === undefined ? String(tooth) : `${tooth}–${toothEnd}`
  return surfaces.length > 0 ? `${piece} (${surfaces.join(', ')})` : piece
}

/** Errores de la API de un ítem: `items.0.tooth` → `{tooth}` (RF-110). */
export function itemErrors(errors, index, prefix = 'items') {
  const start = `${prefix}.${index}.`
  return Object.fromEntries(
    Object.entries(errors)
      .filter(([field]) => field.startsWith(start))
      .map(([field, message]) => [field.slice(start.length).split('.')[0], message]),
  )
}

export const EMPTY_ITEM = {
  procedure_id: '',
  tooth: '',
  surfaces: [],
  quantity: '1',
  session_number: '',
  observations: '',
  finding_ids: [],
}

/** Ítem del formulario → cuerpo de la API (los vacíos se omiten o van como null). */
export function itemPayload(item) {
  return {
    procedure_id: item.procedure_id,
    tooth: item.tooth === '' ? null : Number(item.tooth),
    surfaces: item.surfaces,
    quantity: Number(item.quantity || 1),
    session_number: item.session_number === '' ? null : Number(item.session_number),
    observations: item.observations.trim() === '' ? null : item.observations.trim(),
    ...(item.finding_ids.length > 0 ? { finding_ids: item.finding_ids } : {}),
  }
}

/** Hallazgo con su sigla: «Caries (CA)». */
export function findingText(entry) {
  const acronym = entry.state?.acronym ?? entry.finding?.acronym
  return `${entry.finding?.name ?? 'Hallazgo'}${acronym ? ` (${acronym})` : ''}`
}

/** Ítems de partida desde hallazgos rojos seleccionados (RF-111): uno por hallazgo, ya vinculado. */
export function itemsFromFindings(findings = []) {
  return findings.map((finding) => ({
    ...EMPTY_ITEM,
    tooth: finding.tooth === null || finding.tooth === undefined ? '' : String(finding.tooth),
    surfaces: finding.surfaces ?? [],
    finding_ids: [finding.id],
    findingLabel: finding.label,
  }))
}

export const BUDGET_STATUS = {
  borrador: 'Borrador',
  emitido: 'Emitido',
  aceptado: 'Aceptado',
  rechazado: 'Rechazado',
  vencido: 'Vencido',
  reemplazado: 'Reemplazado',
}

/** Motivos de rechazo del presupuesto (SRS §11.10 FA-1). */
export const REJECTION_REASONS = {
  precio: 'Precio',
  segunda_opinion: 'Segunda opinión',
  momento_no_oportuno: 'Momento no oportuno',
  otro: 'Otro',
}

export const SIGNERS = { titular: 'El titular', representante: 'Su representante legal' }

export const DECISION_CHANNELS = {
  presencial: 'Presencial',
  portal: 'Portal del paciente',
  enlace: 'Enlace compartido',
}

/** Roles que gestionan presupuestos (CUS-35) y que registran la decisión presencial (CUS-37). */
export const BUDGET_ROLES = ['clinic_admin', 'dentist', 'receptionist']
export const DECISION_ROLES = ['clinic_admin', 'receptionist']

export function budgetBadge(status) {
  return ['rechazado', 'vencido', 'reemplazado', 'borrador'].includes(status) ? 'badge badge-muted' : 'badge'
}
