import { Navigate } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { RiskPanel } from './RiskPanel'

/**
 * Pestaña «Riesgo» de la ficha (CUS-55, CUS-56; SDD §3.4: /app/pacientes/:id/riesgo). La
 * recepción no ve predicciones (SDD §3.5): va directo a sus variables sociodemográficas.
 */
export function RiskPage({ patient }) {
  const { user } = useAuth()

  if (user?.role === 'receptionist') return <Navigate to="variables" replace />

  return <RiskPanel patient={patient} />
}
