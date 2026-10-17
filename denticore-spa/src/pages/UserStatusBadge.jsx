import { StatusBadge } from '../components/StatusBadge'
import { CheckIcon, ClockIcon, LockIcon, XIcon } from '../ui/icons'

const USER_STATUS_LABELS = {
  pendiente_activacion: 'Pendiente de activación',
  activo: 'Activo',
  bloqueado_temporal: 'Bloqueado temporalmente',
  inactivo: 'Inactivo',
}

const STYLES = {
  pendiente_activacion: { tone: 'neutral', icon: ClockIcon },
  activo: { tone: 'secondary', icon: CheckIcon },
  bloqueado_temporal: { tone: 'warning', icon: LockIcon },
  inactivo: { tone: 'neutral', icon: XIcon },
}

/** Estado de una cuenta del personal con texto e ícono (RNF-061; DI-03). */
export function UserStatusBadge({ status }) {
  const { tone, icon } = STYLES[status] ?? STYLES.inactivo
  return (
    <StatusBadge tone={tone} icon={icon}>
      {USER_STATUS_LABELS[status] ?? status}
    </StatusBadge>
  )
}
