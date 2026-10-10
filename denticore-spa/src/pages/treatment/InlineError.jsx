import { AlertCircleIcon } from '../../ui/icons'

/**
 * Error de la API que no pertenece a un único control (una fila, un grupo de casillas): mismo
 * estilo que el error de `Field`, con ícono y `role="alert"` (DESIGN.md › Input fields).
 */
export function InlineError({ children }) {
  if (!children) return null

  return (
    <div className="field">
      <span className="error" role="alert">
        <AlertCircleIcon className="size-4 shrink-0" />
        {children}
      </span>
    </div>
  )
}
