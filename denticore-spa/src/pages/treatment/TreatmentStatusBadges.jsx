import { StatusBadge } from '../../components/StatusBadge'
import {
  ActivityIcon,
  CheckCheckIcon,
  CheckIcon,
  ClockIcon,
  FileCheckIcon,
  FileClockIcon,
  FileTextIcon,
  FileXIcon,
  InfoIcon,
  XIcon,
} from '../../ui/icons'
import { BUDGET_STATUS, ITEM_STATUS, PLAN_STATUS } from './labels'

// Tonos de la Status palette (DESIGN.md): plan y presupuesto; el ítem sigue al plan.
const PLAN = {
  borrador: ['neutral', ClockIcon],
  propuesto: ['secondary', InfoIcon],
  aceptado: ['secondary', CheckIcon],
  en_ejecucion: ['primary', ActivityIcon],
  completado: ['success', CheckCheckIcon],
  cancelado: ['neutral', XIcon],
}
const ITEM = {
  propuesto: ['secondary', InfoIcon],
  aceptado: ['secondary', CheckIcon],
  realizado: ['success', CheckCheckIcon],
  descartado: ['neutral', XIcon],
}
const BUDGET = {
  borrador: ['neutral', FileTextIcon],
  emitido: ['secondary', FileTextIcon],
  aceptado: ['success', FileCheckIcon],
  rechazado: ['error', FileXIcon],
  vencido: ['warning', FileClockIcon],
  reemplazado: ['neutral', FileTextIcon],
}

function badge(map, labels, status) {
  const [tone, icon] = map[status] ?? ['neutral', InfoIcon]
  return (
    <StatusBadge tone={tone} icon={icon}>
      {labels[status] ?? status}
    </StatusBadge>
  )
}

export const PlanStatusBadge = ({ status }) => badge(PLAN, PLAN_STATUS, status)
export const ItemStatusBadge = ({ status }) => badge(ITEM, ITEM_STATUS, status)
export const BudgetStatusBadge = ({ status }) => badge(BUDGET, BUDGET_STATUS, status)
