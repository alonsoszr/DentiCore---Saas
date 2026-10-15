export const ROLE_LABELS = {
  super_admin: 'Súper Administrador',
  clinic_admin: 'Administrador de Clínica',
  dentist: 'Odontólogo',
  receptionist: 'Recepcionista',
  patient: 'Paciente',
}

/** Roles que un clinic_admin puede asignar (backend: User::TENANT_ROLES). */
export const TENANT_ROLES = ['clinic_admin', 'dentist', 'receptionist', 'patient']

export const STAFF_ROLES = ['clinic_admin', 'dentist', 'receptionist']
