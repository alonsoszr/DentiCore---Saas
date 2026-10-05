import { AlertCircleIcon, AlertTriangleIcon, CheckCircleIcon, InfoIcon } from '../ui/icons'

const TONES = {
  error: {
    box: 'bg-error-container text-on-error-container',
    accent: 'text-error',
    Icon: AlertCircleIcon,
  },
  warning: {
    box: 'bg-warning-container text-on-warning-container',
    accent: 'text-warning',
    Icon: AlertTriangleIcon,
  },
  info: {
    box: 'bg-secondary-container text-on-secondary-container',
    accent: 'text-secondary',
    Icon: InfoIcon,
  },
  success: {
    box: 'bg-tertiary-container text-on-tertiary-container',
    accent: 'text-tertiary',
    Icon: CheckCircleIcon,
  },
}

/**
 * Alerta en línea (DESIGN.md › Alerts & banners): ícono + título + mensaje, nunca solo
 * color. `role="alert"` la anuncia a los lectores de pantalla al aparecer.
 */
export function Alert({ tone = 'info', title, children, className = '' }) {
  const { box, accent, Icon } = TONES[tone]
  return (
    <div role="alert" className={`flex items-start gap-3 rounded p-3.5 text-body-md ${box} ${className}`}>
      <Icon className={`mt-0.5 size-5 shrink-0 ${accent}`} />
      <div className="min-w-0 flex-1">
        {title && <p className={`m-0 text-title-md ${accent}`}>{title}</p>}
        {children && <p className={title ? 'mt-0.5 mb-0' : 'm-0'}>{children}</p>}
      </div>
    </div>
  )
}
