import { useId, useState } from 'react'
import { Field } from '../../components/Field'
import { fieldErrors } from '../../api/errors'
import { PURPOSES } from '../../portal/consentPurposes'
import { useFocusFirstError } from '../../ui/useFocusFirstError'
import { PURPOSE_CODES } from './rights'

export function ArcoResolutionForm({ request, pending, error, onSubmit }) {
  const [status, setStatus] = useState('atendida')
  const [response, setResponse] = useState('')
  const [denial, setDenial] = useState('')
  const [purposes, setPurposes] = useState([])
  const [correction, setCorrection] = useState('')
  const errors = fieldErrors(error)
  const purposeErrorId = useId()
  const purposeError = Object.entries(errors)
    .filter(([key]) => key === 'purposes' || key.startsWith('purposes.'))
    .map(([, message]) => message)
    .join(' ')
  useFocusFirstError(error)

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault()
        const data = { status, response, ...(status === 'denegada' ? { denial_reason: denial } : {}) }
        if (request.type === 'oposicion' && status === 'atendida') data.purposes = purposes
        if (request.type === 'rectificacion' && correction.trim()) data.correction_entry_id = correction.trim()
        onSubmit(data)
      }}
    >
      <h3>Resolución</h3>
      <Field label="Resultado" name="status" error={errors.status}>
        <select id="status" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="atendida">Atendida</option>
          <option value="denegada">Denegada</option>
        </select>
      </Field>
      <Field
        label="Respuesta al solicitante"
        name="response"
        error={errors.response}
        hint="Entre 20 y 5000 caracteres."
      >
        <textarea
          id="response"
          value={response}
          onChange={(event) => setResponse(event.target.value)}
          required
          minLength={20}
          maxLength={5000}
          rows={4}
        />
      </Field>
      {status === 'denegada' && (
        <Field
          label="Fundamento de la denegación"
          name="denial_reason"
          error={errors.denial_reason}
          hint="Entre 20 y 2000 caracteres."
        >
          <textarea
            id="denial_reason"
            value={denial}
            onChange={(event) => setDenial(event.target.value)}
            required
            minLength={20}
            maxLength={2000}
            rows={3}
          />
        </Field>
      )}
      {request.type === 'oposicion' && status === 'atendida' && (
        <fieldset>
          <legend>Finalidades opcionales que se revocarán</legend>
          {PURPOSES.slice(1).map((purpose, index) => {
            const code = PURPOSE_CODES[index + 1]
            return (
              <label className="checkbox" key={code}>
                <input
                  type="checkbox"
                  aria-invalid={purposeError ? true : undefined}
                  aria-describedby={purposeError ? purposeErrorId : undefined}
                  checked={purposes.includes(code)}
                  onChange={(event) =>
                    setPurposes(event.target.checked ? [...purposes, code] : purposes.filter((value) => value !== code))
                  }
                />
                {purpose.label}
              </label>
            )
          })}
          {purposeError && (
            <p id={purposeErrorId} className="error" role="alert">
              {purposeError}
            </p>
          )}
        </fieldset>
      )}
      {request.type === 'rectificacion' && (
        <Field
          label="UUID de la corrección clínica (opcional)"
          name="correction_entry_id"
          value={correction}
          onChange={(event) => setCorrection(event.target.value)}
          error={errors.correction_entry_id}
          hint="Si corresponde a datos clínicos, indica la corrección que registró el odontólogo para este paciente."
        />
      )}
      <div className="form-actions">
        <button className="btn" disabled={pending}>
          Resolver solicitud
        </button>
      </div>
    </form>
  )
}
