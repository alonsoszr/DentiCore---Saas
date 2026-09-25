export const ROLE_LABELS = {
  super_admin: 'Administrador de plataforma',
  clinic_admin: 'Administrador de clínica',
  dentist: 'Odontólogo',
  receptionist: 'Recepcionista',
  patient: 'Paciente',
}

/** Roles que un clinic_admin puede asignar (backend: User::TENANT_ROLES). */
export const TENANT_ROLES = ['clinic_admin', 'dentist', 'receptionist', 'patient']

export const STAFF_ROLES = ['clinic_admin', 'dentist', 'receptionist']

/** Pantalla inicial de cada rol tras iniciar sesión. */
export function homePathFor(role) {
  if (role === 'super_admin') return '/clinicas'
  if (STAFF_ROLES.includes(role)) return '/pacientes'
  return '/inicio'
}
