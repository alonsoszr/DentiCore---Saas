import { useState } from 'react'
import { PURPOSES } from './consentPurposes'

/**
 * Consentimiento de datos (CUS-17; RF-065, CA-17.1, CA-17.5). Muestra el texto presentado y
 * las finalidades que la clínica ofrece (`available_purposes` de la vista previa), todas sin
 * marcar. La confirmación propia de cada canal (documento, formulario en papel o "He leído y
 * acepto" en el portal) llega en `children`. Lo usan la ficha del personal y el portal.
 */
export function ConsentForm({
  preview,
  errors = {},
  submitting,
  submitLabel = 'Registrar consentimiento',
  onSubmit,
  children,
}) {
  const offered = PURPOSES.filter((purpose) => preview.available_purposes.includes(purpose.field))
  const [checked, setChecked] = useState(() => Object.fromEntries(offered.map((purpose) => [purpose.field, false])))

  const handleSubmit = (event) => {
    event.preventDefault()
    onSubmit(checked)
  }

  return (
    <form onSubmit={handleSubmit} noValidate>
      <p className="muted">Versión {preview.template_version} del texto de consentimiento.</p>
      <div className="consent-text" tabIndex={0} aria-label="Texto del consentimiento">
        {preview.text}
      </div>

      <fieldset className="consent-purposes">
        <legend className="form-section">Finalidades que otorga</legend>
        {offered.map((purpose) => (
          <div key={purpose.field}>
            <label className="checkbox">
              <input
                type="checkbox"
                name={purpose.field}
                checked={checked[purpose.field]}
                onChange={(event) => setChecked({ ...checked, [purpose.field]: event.target.checked })}
                aria-invalid={errors[purpose.field] ? true : undefined}
              />
              {purpose.label}
              {purpose.required && ' (obligatoria)'}
            </label>
            {errors[purpose.field] && (
              <span className="error" role="alert">
                {errors[purpose.field]}
              </span>
            )}
          </div>
        ))}
      </fieldset>

      {children}

      <div className="form-actions">
        <button type="submit" className="btn" disabled={submitting}>
          {submitting ? 'Guardando…' : submitLabel}
        </button>
      </div>
    </form>
  )
}
