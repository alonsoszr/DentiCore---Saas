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
 * color. `role="alert"` la anuncia a los lectores de pantalla al aparecer; un aviso que ya está
 * al cargar la página pasa `role={null}`. `size="lg"` usa el texto de 16 px del portal.
 */
export function Alert({ tone = 'info', title, children, className = '', size, role = 'alert' }) {
  const { box, accent, Icon } = TONES[tone]
  const text = size === 'lg' ? 'text-body-lg' : 'text-body-md'
  return (
    <div role={role ?? undefined} className={`flex items-start gap-3 rounded p-3.5 ${text} ${box} ${className}`}>
      <Icon className={`mt-0.5 size-5 shrink-0 ${accent}`} />
      <div className="min-w-0 flex-1">
        {title && <p className={`m-0 text-title-md ${accent}`}>{title}</p>}
        {children && <div className={title ? 'mt-0.5' : undefined}>{children}</div>}
      </div>
    </div>
  )
}
