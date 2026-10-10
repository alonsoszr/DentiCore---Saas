import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { InlineError } from './InlineError'
import { useProcedures } from './useTreatment'

const EMPTY = { title: '', body: '', procedures: [] }

/**
 * Plantillas de consentimiento informado (/c/:slug/app/configuracion/consentimientos; CUS-82;
 * RF-072): título, texto con sus campos, versión vigente y procedimientos asociados. Cambiar el
 * texto crea una versión nueva; los consentimientos ya firmados conservan la suya.
 */
export function ConsentTemplatesPage() {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const queryKey = ['informed-consent-templates', slug]
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY)
  const [toDeactivate, setToDeactivate] = useState(null)
  const formRef = useRef(null)

  useEffect(() => {
    if (editing !== null) formRef.current?.querySelector('input')?.focus()
  }, [editing])

  const templates = useQuery({
    queryKey,
    queryFn: async () => (await apiClient.get('/informed-consent-templates')).data.data,
  })
  const procedures = useProcedures()

  const save = useMutation({
    mutationFn: async (payload) =>
      editing === 'new'
        ? apiClient.post('/informed-consent-templates', payload)
        : apiClient.put(`/informed-consent-templates/${editing}`, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey })
      setEditing(null)
    },
  })
  const deactivate = useMutation({
    mutationFn: async (id) => apiClient.post(`/informed-consent-templates/${id}/deactivate`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey })
      setToDeactivate(null)
    },
  })

  const open = (template) => {
    save.reset()
    setEditing(template ? template.id : 'new')
    setForm(template ? { title: template.title, body: template.body ?? '', procedures: template.procedures } : EMPTY)
  }
  const toggleProcedure = (id) =>
    setForm((current) => ({
      ...current,
      procedures: current.procedures.includes(id)
        ? current.procedures.filter((value) => value !== id)
        : [...current.procedures, id],
    }))

  const errors = fieldErrors(save.error)
  // Los errores por procedimiento llegan como `procedures.N`, en el orden enviado.
  const procedureErrors = Object.entries(errors).filter(([field]) => field.startsWith('procedures.'))
  const catalog = procedures.data ?? []
  const nameOf = (id) => catalog.find((procedure) => procedure.id === id)?.name ?? 'Procedimiento'
  const choosable = catalog.filter(
    (procedure) => procedure.requires_informed_consent || form.procedures.includes(procedure.id),
  )
  const list = templates.data ?? []

  return (
    <div className="card">
      <div className="page-header">
        <h1 className="m-0">Plantillas de consentimiento informado</h1>
        {editing === null && (
          <button type="button" className="btn" onClick={() => open(null)}>
            Nueva plantilla
          </button>
        )}
      </div>
      <p className="muted">
        Cada procedimiento que exige consentimiento informado necesita una plantilla activa. El texto admite los campos{' '}
        {'{{paciente}}'}, {'{{procedimiento}}'}, {'{{pieza}}'}, {'{{riesgos}}'}, {'{{alternativas}}'} y{' '}
        {'{{odontologo}}'}.
      </p>

      {editing !== null && (
        <form
          ref={formRef}
          className="form-section"
          noValidate
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate({ title: form.title.trim(), body: form.body, procedures: form.procedures })
          }}
        >
          <h2>{editing === 'new' ? 'Nueva plantilla' : 'Editar plantilla'}</h2>
          {generalError(save.error) && (
            <div className="alert alert-error" role="alert">
              {generalError(save.error)}
            </div>
          )}
          <Field
            label="Título"
            name="template-title"
            value={form.title}
            onChange={(event) => setForm({ ...form, title: event.target.value })}
            error={errors.title}
            maxLength={150}
          />
          <Field
            label="Texto del consentimiento"
            name="template-body"
            error={errors.body}
            hint={editing === 'new' ? undefined : 'Si cambias el texto se crea una versión nueva.'}
          >
            <textarea
              id="template-body"
              rows={8}
              value={form.body}
              onChange={(event) => setForm({ ...form, body: event.target.value })}
              aria-invalid={errors.body ? true : undefined}
              aria-describedby={errors.body ? 'template-body-error' : undefined}
            />
          </Field>
          <fieldset className="m-0 border-0 p-0">
            <legend className="text-label-md">Procedimientos asociados</legend>
            {choosable.length === 0 ? (
              <p className="muted">No hay procedimientos del catálogo que exijan consentimiento informado.</p>
            ) : (
              <div className="flex flex-wrap gap-4">
                {choosable.map((procedure) => (
                  <label key={procedure.id} className="checkbox">
                    <input
                      type="checkbox"
                      checked={form.procedures.includes(procedure.id)}
                      onChange={() => toggleProcedure(procedure.id)}
                    />
                    {procedure.code} · {procedure.name}
                  </label>
                ))}
              </div>
            )}
            <InlineError>{errors.procedures}</InlineError>
            {procedureErrors.map(([field, message]) => (
              <InlineError key={field}>{message}</InlineError>
            ))}
          </fieldset>
          <div className="form-actions">
            <button type="submit" className="btn" disabled={save.isPending}>
              {editing === 'new' ? 'Crear plantilla' : 'Guardar cambios'}
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setEditing(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {templates.isLoading && <div className="empty">Cargando…</div>}
      {templates.isError && (
        <div className="alert alert-error" role="alert">
          {generalError(templates.error)}
        </div>
      )}
      {templates.isSuccess && list.length === 0 && (
        <div className="empty">La clínica aún no tiene plantillas de consentimiento informado.</div>
      )}
      {list.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Plantilla</th>
                <th>Versión</th>
                <th>Estado</th>
                <th>Procedimientos</th>
                <th>
                  <span className="sr-only">Acciones</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {list.map((template) => (
                <tr key={template.id}>
                  <td>{template.title}</td>
                  <td>{template.current_version}</td>
                  <td>
                    <span className={template.is_active ? 'badge' : 'badge badge-muted'}>
                      {template.is_active ? 'Activa' : 'Inactiva'}
                    </span>
                  </td>
                  <td>{template.procedures.map(nameOf).join(', ') || '—'}</td>
                  <td>
                    {template.is_active && (
                      <div className="flex flex-wrap gap-3">
                        <button
                          type="button"
                          className="btn-link"
                          aria-label={`Editar ${template.title}`}
                          onClick={() => open(template)}
                        >
                          Editar
                        </button>
                        <button
                          type="button"
                          className="btn-link"
                          aria-label={`Desactivar ${template.title}`}
                          onClick={() => setToDeactivate(template)}
                        >
                          Desactivar
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {toDeactivate && (
        <ConfirmDialog
          title={`¿Desactivar «${toDeactivate.title}»?`}
          confirmLabel="Desactivar plantilla"
          destructive
          onConfirm={() => deactivate.mutate(toDeactivate.id)}
          onCancel={() => setToDeactivate(null)}
          busy={deactivate.isPending}
          error={generalError(deactivate.error)}
        >
          <p>
            Sus procedimientos quedarán sin plantilla activa hasta que crees otra. Los consentimientos ya firmados no
            cambian.
          </p>
        </ConfirmDialog>
      )}
    </div>
  )
}
