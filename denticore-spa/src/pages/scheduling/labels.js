// Etiquetas de la agenda (SRS §5.5.4; SDD §2.10; CUS-44 a CUS-53, CUS-77).

export const APPOINTMENT_STATUS = {
  programada: 'Programada',
  confirmada: 'Confirmada',
  en_atencion: 'En atención',
  atendida: 'Atendida',
  cancelada: 'Cancelada',
  inasistencia: 'Inasistencia',
}

/** Estados que ocupan la franja y admiten cambios (SRS §5.5.4). */
export const ACTIVE_STATUSES = ['programada', 'confirmada', 'en_atencion']

export const WEEKDAYS = {
  1: 'Lunes',
  2: 'Martes',
  3: 'Miércoles',
  4: 'Jueves',
  5: 'Viernes',
  6: 'Sábado',
  7: 'Domingo',
}

export const BLOCK_KINDS = {
  vacaciones: 'Vacaciones',
  capacitacion: 'Capacitación',
  feriado: 'Feriado',
  cierre: 'Cierre',
  otro: 'Otro',
}

export const CANCEL_REASONS = {
  paciente: 'A pedido del paciente',
  clinica: 'Por la clínica',
  otro: 'Otro motivo',
}

export const CONFIRMATION_CHANNELS = {
  enlace: 'por enlace',
  portal: 'por el portal',
  telefono: 'por teléfono',
}

/** Roles que reservan, reprograman, cancelan, confirman y registran la llegada (CUS-47 a CUS-50). */
export const DESK_ROLES = ['clinic_admin', 'receptionist']

/**
 * Citas afectadas de un 422 RN-82: las claves `decisions.{uuid}` de `errors` (SDD §5.7). El SPA
 * pide cada cita para mostrarla y decidir.
 */
export function affectedIds(error) {
  const errors = error?.response?.data?.errors ?? {}
  return Object.keys(errors)
    .filter((key) => key.startsWith('decisions.') && key.split('.').length === 2)
    .map((key) => key.slice('decisions.'.length))
}
