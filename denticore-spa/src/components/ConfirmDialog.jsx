import { useEffect, useId, useRef } from 'react'

/**
 * Diálogo de confirmación de acciones irreversibles (RNF-063; DESIGN.md › Confirmation dialogs):
 * título, consecuencias y el contenido del formulario que la acción necesite. Escape o
 * «Cancelar» lo cierran sin efectos; el foco entra al diálogo al abrirse. Con `destructive` el botón
 * de confirmar usa el estilo destructivo; `confirmDisabled` lo bloquea mientras falten datos.
 */
export function ConfirmDialog({
  title,
  children,
  confirmLabel,
  onConfirm,
  onCancel,
  busy = false,
  error = null,
  destructive = false,
  confirmDisabled = false,
}) {
  const titleId = useId()
  const dialogRef = useRef(null)

  useEffect(() => {
    dialogRef.current?.querySelector('input, textarea, select, button')?.focus()
  }, [])

  return (
    <div className="dialog-backdrop">
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="card dialog"
        onKeyDown={(event) => event.key === 'Escape' && onCancel()}
      >
        <h2 id={titleId}>{title}</h2>
        {children}
        {error && (
          <div className="alert alert-error" role="alert">
            {error}
          </div>
        )}
        <div className="form-actions">
          <button
            type="button"
            className={destructive ? 'btn btn-danger' : 'btn'}
            onClick={onConfirm}
            disabled={busy || confirmDisabled}
          >
            {confirmLabel}
          </button>
          <button type="button" className="btn btn-secondary" onClick={onCancel} disabled={busy}>
            Cancelar
          </button>
        </div>
      </div>
    </div>
  )
}
