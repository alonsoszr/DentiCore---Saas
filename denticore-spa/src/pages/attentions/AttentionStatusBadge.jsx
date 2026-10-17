import { StatusBadge } from '../../components/StatusBadge'
import { ActivityIcon, AlertTriangleIcon, LockIcon } from '../../ui/icons'
import { ATTENTION_STATUS } from './labels'

const STYLES = {
  abierta: { tone: 'secondary', icon: ActivityIcon },
  cerrada: { tone: 'neutral', icon: LockIcon },
  cerrada_incompleta: { tone: 'warning', icon: AlertTriangleIcon },
}

/**
 * Estado de la atención con texto e ícono (RNF-061). «Cerrada incompleta» la deja el cierre
 * automático sin nota completa (RF-096), por eso va en el tono de advertencia.
 */
export function AttentionStatusBadge({ status }) {
  const { tone, icon } = STYLES[status] ?? STYLES.cerrada
  return (
    <StatusBadge tone={tone} icon={icon}>
      {ATTENTION_STATUS[status] ?? status}
    </StatusBadge>
  )
}
