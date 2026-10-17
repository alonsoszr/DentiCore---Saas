import { ROLE_LABELS } from '../auth/roles'
import { UserIcon } from '../ui/icons'
import { StatusBadge } from './StatusBadge'

/** Rol de una cuenta con texto e ícono (DESIGN.md: todo badge lleva texto e ícono). */
export function RoleBadge({ role, className }) {
  return (
    <StatusBadge icon={UserIcon} className={className}>
      {ROLE_LABELS[role] ?? role}
    </StatusBadge>
  )
}
