import { CheckIcon, XIcon } from '../ui/icons'
import { StatusBadge } from './StatusBadge'

/** Estado activo o inactivo de un registro de catálogo, con texto e ícono (DESIGN.md). */
export function ActiveBadge({ active }) {
  return active ? (
    <StatusBadge icon={CheckIcon}>Activo</StatusBadge>
  ) : (
    <StatusBadge tone="neutral" icon={XIcon}>
      Inactivo
    </StatusBadge>
  )
}
