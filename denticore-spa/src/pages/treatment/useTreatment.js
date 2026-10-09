import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { useClinic } from '../../auth/useClinic'

/** Catálogo de procedimientos de la clínica (CUS-32); los ítems nuevos solo usan los activos (RN-26). */
export function useProcedures() {
  const { slug } = useClinic()

  return useQuery({
    queryKey: ['procedures', slug],
    queryFn: async () => (await apiClient.get('/procedures')).data.data,
  })
}

/** Planes del paciente con su avance (RF-130). */
export function usePatientPlans(patientId) {
  const { slug } = useClinic()

  return useQuery({
    queryKey: ['treatment-plans', slug, patientId],
    queryFn: async () => (await apiClient.get(`/patients/${patientId}/treatment-plans`)).data.data,
  })
}

/** Un plan con sus ítems y su avance. */
export function usePlan(planId) {
  const { slug } = useClinic()

  return useQuery({
    queryKey: ['treatment-plan', slug, planId],
    queryFn: async () => (await apiClient.get(`/treatment-plans/${planId}`)).data.data,
  })
}
