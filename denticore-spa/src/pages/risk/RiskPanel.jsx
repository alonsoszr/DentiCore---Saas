import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { Spinner } from '../../ui/icons'
import { missingLabel } from './labels'
import { RiskCard, RiskUnavailableCard } from './RiskCard'
import { RiskPurposeAlert } from './RiskPurposeAlert'
import { useCurrentPrediction, useRiskPurpose, useVariableSets } from './useRisk'

/** Lista legible de lo que falta para calcular (CA-55.4). */
function MissingList({ missing }) {
  return (
    <ul className="mt-1 mb-0 pl-5">
      {missing.map((key) => (
        <li key={key}>{missingLabel(key)}</li>
      ))}
    </ul>
  )
}

/**
 * Riesgo de caries del paciente (CUS-55, CUS-56): la predicción vigente y, para el odontólogo,
 * el cálculo con el registro de variables más reciente. Si el motor no responde se muestra
 * «Predicción no disponible» y nada más cambia: la atención sigue editable (RN-64, CA-55.3).
 */
export function RiskPanel({ patient }) {
  const { user } = useAuth()
  const { slug, appPath } = useClinic()
  const queryClient = useQueryClient()
  const isDentist = user?.role === 'dentist'
  const [unavailable, setUnavailable] = useState(false)
  const current = useCurrentPrediction(patient.id)
  const sets = useVariableSets(patient.id, isDentist)
  const purpose = useRiskPurpose(patient.id, isDentist)
  const blocked = purpose.isLoading || purpose.allowed === false
  const latest = sets.data?.[0] ?? null

  const predict = useMutation({
    mutationFn: async () =>
      (await apiClient.post(`/patients/${patient.id}/risk-predictions`, { risk_variable_set_id: latest.id })).data.data,
    onSuccess: (result) => {
      if (result.status === 'no_disponible') {
        setUnavailable(true)
        return
      }
      setUnavailable(false)
      queryClient.setQueryData(['risk-prediction', slug, result.id], result)
      queryClient.invalidateQueries({ queryKey: ['clinical-record', slug, patient.id] })
      queryClient.invalidateQueries({ queryKey: ['risk-variable-sets', slug, patient.id] })
    },
  })

  const missing = predict.error?.response?.data?.missing ?? latest?.missing ?? []
  const variablesLink = appPath(`/pacientes/${patient.id}/riesgo/variables`)

  return (
    <>
      {isDentist && (
        <section className="card" aria-labelledby="risk-calc-title">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 id="risk-calc-title" className="m-0">
              Calcular riesgo de caries
            </h2>
            <div className="flex flex-wrap gap-2">
              <Link to={variablesLink} className="btn btn-secondary">
                Variables de riesgo
              </Link>
              <button
                type="button"
                className="btn"
                disabled={predict.isPending || !latest?.is_complete || blocked}
                onClick={() => predict.mutate()}
              >
                {predict.isPending && <Spinner />}
                {predict.isPending ? 'Calculando…' : 'Calcular riesgo'}
              </button>
            </div>
          </div>
          {sets.isError && (
            <Alert tone="error" className="mt-3">
              {generalError(sets.error)}
            </Alert>
          )}
          {purpose.allowed === false && <RiskPurposeAlert patient={patient} className="mt-3" />}
          {purpose.allowed !== false && sets.isSuccess && latest === null && (
            <p className="muted mb-0">Registre las variables de riesgo del paciente para poder calcular.</p>
          )}
          {purpose.allowed !== false && missing.length > 0 && (
            <Alert tone="warning" title="Faltan variables para calcular" className="mt-3">
              <MissingList missing={missing} />
            </Alert>
          )}
          {predict.error && !predict.error.response?.data?.missing && (
            <Alert tone="error" className="mt-3">
              {generalError(predict.error)}
            </Alert>
          )}
        </section>
      )}

      {unavailable && <RiskUnavailableCard />}
      {current.isLoading ? (
        <div className="card empty" role="status">
          Cargando la predicción…
        </div>
      ) : current.error ? (
        <div className="alert alert-error" role="alert">
          {generalError(current.error)}
        </div>
      ) : current.prediction ? (
        <RiskCard prediction={current.prediction} title={unavailable ? 'Predicción vigente' : 'Riesgo de caries'} />
      ) : (
        !unavailable && <div className="card empty">El paciente no tiene una predicción de riesgo vigente.</div>
      )}
    </>
  )
}
