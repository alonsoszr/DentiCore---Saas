import { Link } from 'react-router-dom'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'

/**
 * Aviso de que el consentimiento vigente no incluye la finalidad (d) «predicción» (RN-10, RN-58):
 * sin ella no se registran variables ni se calcula el riesgo. Lleva a la pestaña «Consentimiento».
 */
export function RiskPurposeAlert({ patient, className = '' }) {
  const { appPath } = useClinic()
  return (
    <Alert tone="warning" title="Falta la finalidad (d) del consentimiento" className={className}>
      El consentimiento vigente no incluye la finalidad de predicción de riesgo: no se pueden registrar variables ni
      calcular el riesgo.{' '}
      <Link to={appPath(`/pacientes/${patient.id}/consentimiento`)}>Registrar el consentimiento</Link>
    </Alert>
  )
}
