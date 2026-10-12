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
 * ¿El consentimiento vigente incluye la finalidad (d) «predicción»? (RN-10, RN-58). Sin ella la API
 * rechaza la captura y el cálculo con 422; así se avisa antes. `allowed` es `null` mientras carga o
 * si no se pudo leer. La revocación parcial de una finalidad (CUS-18) no llega en el recurso: ese
 * caso lo sigue decidiendo la API con su 422. Comparte la caché de la pestaña «Consentimiento».
 */
export function useRiskPurpose(patientId, enabled = true) {
  const { slug } = useClinic()
  const consents = useQuery({
    queryKey: ['consents', slug, patientId],
    queryFn: async () => (await apiClient.get(`/patients/${patientId}/consents`)).data.data,
    enabled,
    retry: false,
  })
  return {
    isLoading: consents.isLoading,
    allowed: consents.isSuccess
      ? consents.data.some((consent) => consent.status === 'vigente' && consent.purpose_risk)
      : null,
  }
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
