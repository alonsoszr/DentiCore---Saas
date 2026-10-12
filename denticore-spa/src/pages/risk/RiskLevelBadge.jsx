import { AlertCircleIcon, AlertOctagonIcon, ShieldCheckIcon } from '../../ui/icons'
import { RISK_LEVELS } from './labels'

const ICONS = { bajo: ShieldCheckIcon, medio: AlertCircleIcon, alto: AlertOctagonIcon }

/** Nivel de riesgo con texto e ícono sobre su color (DESIGN.md › Status palette): nunca solo color. */
export function RiskLevelBadge({ level }) {
  const Icon = ICONS[level] ?? AlertCircleIcon
  return (
    <span className={`badge badge-risk-${level}`}>
      <Icon className="size-4" />
      {RISK_LEVELS[level] ?? level}
    </span>
  )
}
