import { AlertCircleIcon, CheckCircleIcon, InfoIcon } from '../../ui/icons'

/**
 * Requisitos de contraseña de RF-040 (docs/design/screens/README.md › PasswordRequirements).
 * Cada requisito es `{ label, state }` con `state`: 'ok', 'pending' (se verifica al guardar)
 * o 'failed'. El estado se comunica con ícono y texto, no solo con color.
 */
export function PasswordRequirements({ items }) {
  const met = items.filter((item) => item.state === 'ok').length

  return (
    <div className="requirements" aria-live="polite">
      <p className="m-0 text-label-md text-on-surface-variant">
        Requisitos de la contraseña ({met}/{items.length})
      </p>
      <ul className="m-0 mt-1.5 flex list-none flex-col gap-1 p-0">
        {items.map((item) => (
          <li key={item.label} className={`requirement requirement-${item.state}`}>
            {item.state === 'ok' && <CheckCircleIcon className="size-4 shrink-0" />}
            {item.state === 'failed' && <AlertCircleIcon className="size-4 shrink-0" />}
            {item.state === 'pending' && <InfoIcon className="size-4 shrink-0" />}
            <span>
              {item.label}
              {item.state === 'pending' && ' (se verifica al guardar)'}
              {item.state === 'failed' && ' (no se cumple)'}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
