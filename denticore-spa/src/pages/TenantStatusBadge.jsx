import { StatusBadge } from '../components/StatusBadge'
import { AlertTriangleIcon, CheckIcon, XIcon } from '../ui/icons'

const TENANT_STATUS = {
  activa: { label: 'Activa', tone: 'success', icon: CheckIcon },
  suspendida: { label: 'Suspendida', tone: 'warning', icon: AlertTriangleIcon },
  cancelada: { label: 'Cancelada', tone: 'neutral', icon: XIcon },
  eliminada: { label: 'Eliminada', tone: 'neutral', icon: XIcon },
}

/** Estado de la clínica con texto e ícono (RN-07): suspendida es solo lectura. */
export function TenantStatusBadge({ status }) {
  const state = TENANT_STATUS[status] ?? { label: status, tone: 'neutral' }
  return (
    <StatusBadge tone={state.tone} icon={state.icon}>
      {state.label}
    </StatusBadge>
  )
}
