import { ActivityIcon, AlertTriangleIcon, CheckCheckIcon, CheckIcon, ClockIcon, XIcon } from '../../ui/icons'
import { APPOINTMENT_STATUS } from './labels'

const ICONS = {
  programada: ClockIcon,
  confirmada: CheckIcon,
  en_atencion: ActivityIcon,
  atendida: CheckCheckIcon,
  cancelada: XIcon,
  inasistencia: AlertTriangleIcon,
}

/**
 * Estado de la cita con texto e ícono sobre su color (DESIGN.md › Status palette): nunca solo
 * color. Una cita confirmada que se reprogramó indica que falta reconfirmarla.
 */
export function StatusBadge({ appointment }) {
  const Icon = ICONS[appointment.status] ?? ClockIcon
  return (
    <span className={`badge badge-status-${appointment.status}`}>
      <Icon className="size-4" />
      {APPOINTMENT_STATUS[appointment.status] ?? appointment.status}
      {appointment.reconfirmation_pending && ' · por reconfirmar'}
    </span>
  )
}
