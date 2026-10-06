const MINUTE = 60_000

/** RF-036: 30 minutos de inactividad para el personal y 15 para el portal del paciente. */
export function inactivityLimit(role) {
  return (role === 'patient' ? 15 : 30) * MINUTE
}
