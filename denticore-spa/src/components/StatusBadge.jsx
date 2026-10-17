const TONES = {
  neutral: 'badge badge-muted',
  secondary: 'badge',
  primary: 'badge badge-primary',
  success: 'badge badge-success',
  warning: 'badge badge-warning',
  error: 'badge badge-error',
}

/**
 * Badge de estado con texto e ícono sobre el tono de la Status palette (DESIGN.md): ningún estado
 * se comunica solo con color.
 */
export function StatusBadge({ tone = 'secondary', icon: Icon, className, children }) {
  return (
    <span className={className ? `${TONES[tone]} ${className}` : TONES[tone]}>
      {Icon && <Icon className="size-4" />}
      {children}
    </span>
  )
}
