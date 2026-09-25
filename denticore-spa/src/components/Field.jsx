/**
 * Campo de formulario con etiqueta, ayuda opcional y error de validación.
 */
export function Field({ label, name, error, hint, children, ...inputProps }) {
  return (
    <div className="field">
      <label htmlFor={name}>{label}</label>
      {children ?? <input id={name} name={name} {...inputProps} />}
      {hint && !error && <span className="hint">{hint}</span>}
      {error && <span className="error">{error}</span>}
    </div>
  )
}
