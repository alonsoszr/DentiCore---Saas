import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'

/**
 * Sujeto del portal (SDD §3.3): la ficha propia de la cuenta o, si no la tiene, su primer
 * representado vigente. El selector «Estás viendo» (RF-177) llega en MS-13.
 */
export function usePortalSubject() {
  const { slug } = useClinic()
  const subjects = useQuery({
    queryKey: ['portal-subjects', slug],
    queryFn: async () => (await apiClient.get('/portal/subjects')).data.data,
  })
  const list = subjects.data ?? []

  return { ...subjects, subject: list.find((item) => item.relationship === 'titular') ?? list[0] ?? null }
}

/** Nombre del sujeto para los textos del portal. */
export function subjectName(subject) {
  return `${subject.first_name} ${subject.last_name}`
}

/** Presupuestos del sujeto del portal (CUS-36; RF-121). */
export function usePortalBudgets(subject) {
  const { slug } = useClinic()
  return useQuery({
    queryKey: ['portal-budgets', slug, subject.id],
    queryFn: async () => (await apiClient.get(`/portal/patients/${subject.id}/budgets`)).data.data,
  })
}

/** Citas del sujeto del portal, de la más reciente a la más antigua (CUS-77). */
export function usePortalAppointments(subject) {
  const { slug } = useClinic()
  return useQuery({
    queryKey: ['portal-appointments', slug, subject.id],
    queryFn: async () => (await apiClient.get(`/portal/patients/${subject.id}/appointments`)).data.data,
  })
}

/** Cita pendiente: programada o confirmada y aún por empezar (`now` en milisegundos). */
export function isUpcoming(appointment, now) {
  return ['programada', 'confirmada'].includes(appointment.status) && new Date(appointment.starts_at).getTime() > now
}

/** Tipos de cita activos y visibles en el portal (CUS-46; vacío resuelto de MS-06). */
export function usePortalAppointmentTypes() {
  const { slug } = useClinic()
  return useQuery({
    queryKey: ['portal-appointment-types', slug],
    queryFn: async () => (await apiClient.get('/portal/availability')).data.types,
    // Un 403 indica que la clínica no habilitó el autoagendamiento: no se reintenta.
    retry: false,
  })
}

/**
 * RF-026: sin autoagendamiento, la disponibilidad del portal responde 403 (`portal.self_booking`)
 * y el paciente no reserva ni reprograma desde el portal; puede confirmar y cancelar.
 */
export function selfBookingDisabled(typesQuery) {
  return typesQuery.error?.response?.status === 403
}

/** Mensaje de un error de escritura: el general o, en un 422, los de cada campo. */
export function errorText(error) {
  return generalError(error) ?? (Object.values(fieldErrors(error)).join(' ') || null)
}
