import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { useClinic } from '../../auth/useClinic'

/** Registros de variables del paciente, del más reciente al más antiguo (RF-164). */
export function useVariableSets(patientId, enabled = true) {
  const { slug } = useClinic()
  return useQuery({
    queryKey: ['risk-variable-sets', slug, patientId],
    queryFn: async () => (await apiClient.get(`/patients/${patientId}/risk-variable-sets`)).data.data,
    enabled,
  })
}

/**
 * Predicción vigente del paciente: su id llega en la historia clínica (`current_risk_prediction`)
 * y el detalle por rol en `GET /risk-predictions/{id}` (SDD §3.5). Comparte la caché de la HC.
 */
export function useCurrentPrediction(patientId, enabled = true) {
  const { slug } = useClinic()
  const record = useQuery({
    queryKey: ['clinical-record', slug, patientId],
    queryFn: async () => (await apiClient.get(`/patients/${patientId}/clinical-record`)).data.data,
    enabled,
  })
  const currentId = record.data?.current_risk_prediction?.id ?? null
  const prediction = useQuery({
    queryKey: ['risk-prediction', slug, currentId],
    queryFn: async () => (await apiClient.get(`/risk-predictions/${currentId}`)).data.data,
    enabled: enabled && currentId !== null,
  })

  return {
    isLoading: record.isLoading || (currentId !== null && prediction.isLoading),
    error: record.error ?? prediction.error,
    prediction: currentId === null ? null : (prediction.data ?? null),
  }
}
