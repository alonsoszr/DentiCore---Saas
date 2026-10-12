import { useAuth } from '../auth/useAuth'
import { DEFAULT_TIME_ZONE } from './format'

/** Zona horaria de la clínica del usuario (SDD §1.10): las fechas y horas se muestran en ella. */
export function useTimeZone() {
  const { user } = useAuth()
  return user?.tenant?.timezone ?? DEFAULT_TIME_ZONE
}
