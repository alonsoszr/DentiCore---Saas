export const ARCO_TYPES = {
  acceso: 'Acceso',
  rectificacion: 'Rectificación',
  cancelacion: 'Cancelación',
  oposicion: 'Oposición',
  portabilidad: 'Portabilidad',
}

export const ARCO_STATUSES = {
  recibida: 'Recibida',
  en_tramite: 'En trámite',
  atendida: 'Atendida',
  denegada: 'Denegada',
}

export const PURPOSE_CODES = ['atencion', 'notificaciones', 'ia', 'prediccion', 'encuestas']

export function patientRightsBase(patientId, portal = false) {
  return `${portal ? '/portal' : ''}/patients/${patientId}`
}

export function isDataOfficer(user) {
  return user?.role === 'clinic_admin' && user.is_data_officer === true
}
