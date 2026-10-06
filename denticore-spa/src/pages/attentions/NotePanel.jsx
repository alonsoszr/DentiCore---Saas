import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { Field } from '../../components/Field'
import { NOTE_SECTIONS } from './labels'

/**
 * Nota de atención (CUS-80; RF-084): se guarda con un PUT idempotente mientras la atención está
 * abierta. El autoguardado cada 30 s (RF-086) llega en MS-15.
 */
export function NotePanel({ attention, queryKey }) {
  const queryClient = useQueryClient()
  const [note, setNote] = useState(() =>
    Object.fromEntries(NOTE_SECTIONS.map(([field]) => [field, attention.note?.[field] ?? ''])),
  )

  const save = useMutation({
    mutationFn: async () => (await apiClient.put(`/attentions/${attention.id}/note`, note)).data.data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })
  const errors = fieldErrors(save.error)

  return (
    <section className="card" aria-labelledby="note-title">
      <h2 id="note-title">Nota de atención</h2>
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {NOTE_SECTIONS.map(([field, label, max]) => (
          <Field key={field} label={label} name={field} error={errors[field]}>
            <textarea
              id={field}
              name={field}
              rows={field === 'chief_complaint' ? 2 : 3}
              maxLength={max}
              value={note[field]}
              aria-invalid={errors[field] ? true : undefined}
              onChange={(event) => setNote({ ...note, [field]: event.target.value })}
            />
          </Field>
        ))}
        {save.isError && generalError(save.error) && (
          <div className="alert alert-error">{generalError(save.error)}</div>
        )}
        {save.isSuccess && <div className="alert alert-success">Nota guardada.</div>}
        <div className="form-actions">
          <button type="submit" className="btn" disabled={save.isPending}>
            Guardar nota
          </button>
        </div>
      </form>
    </section>
  )
}

/** Nota en solo lectura (atención cerrada; RN-78). */
export function NoteView({ note }) {
  return (
    <section className="card" aria-labelledby="note-title">
      <h2 id="note-title">Nota de atención</h2>
      {NOTE_SECTIONS.map(([field, label]) => (
        <div key={field} className="mb-2">
          <h3 className="m-0 text-label-md">{label}</h3>
          <p className="m-0 whitespace-pre-line">{note?.[field] || <span className="muted">Sin registrar</span>}</p>
        </div>
      ))}
    </section>
  )
}
