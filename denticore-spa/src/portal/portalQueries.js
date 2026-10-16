import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../api/client'
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
