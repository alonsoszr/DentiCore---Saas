import { AlertCircleIcon } from '../ui/icons'

/**
 * Campo de formulario con etiqueta, ayuda opcional y error de validación. El error queda
 * enlazado al control con aria-describedby (DESIGN.md › Input fields).
 * - `endAdornment`: ícono o botón dentro del campo, a la derecha.
 * - `size="lg"`: controles de 48px (autenticación y portal, DESIGN.md › Density).
 */
export function Field({ label, name, error, hint, children, endAdornment, size, ...inputProps }) {
  const hintId = hint && !error ? `${name}-hint` : undefined
  const errorId = error ? `${name}-error` : undefined

  return (
    <div className={size === 'lg' ? 'field field-lg' : 'field'}>
      <label htmlFor={name}>{label}</label>
      {children ?? (
        <div className={endAdornment ? 'field-control has-adornment' : 'field-control'}>
          <input
            id={name}
            name={name}
            aria-invalid={error ? true : undefined}
            aria-describedby={errorId ?? hintId}
            {...inputProps}
          />
          {endAdornment && <span className="field-adornment">{endAdornment}</span>}
        </div>
      )}
      {hintId && (
        <span id={hintId} className="hint">
          {hint}
        </span>
      )}
      {error && (
        <span id={errorId} className="error" role="alert">
          <AlertCircleIcon className="size-4 shrink-0" />
          {error}
        </span>
      )}
    </div>
  )
}
