import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { formatCurrency } from '../../ui/format'

const EMPTY = {
  code: '',
  name: '',
  category: '',
  price: '',
  requires_tooth: true,
  requires_surface: false,
  requires_informed_consent: false,
  resulting_finding_code: '',
  resulting_state_code: '',
}

function formFrom(procedure) {
  return {
    code: procedure.code,
    name: procedure.name,
    category: procedure.category ?? '',
    price: procedure.price,
    requires_tooth: procedure.requires_tooth,
    requires_surface: procedure.requires_surface,
    requires_informed_consent: procedure.requires_informed_consent,
    resulting_finding_code: procedure.resulting_finding?.code ?? '',
    resulting_state_code: procedure.resulting_state?.code ?? '',
  }
}

function requirementsText(procedure) {
  const parts = [
    procedure.requires_tooth && 'pieza',
    procedure.requires_surface && 'superficie',
    procedure.requires_informed_consent && 'consentimiento informado',
  ].filter(Boolean)
  return parts.length > 0 ? parts.join(', ') : '—'
}

/**
 * Catálogo de procedimientos de la clínica (/c/:slug/app/catalogo; CUS-32; RF-107, RF-109, RN-39):
 * precio, marcas de pieza, superficie y consentimiento informado, y hallazgo resultante NTS 188.
 * Un procedimiento usado en planes o presupuestos no se elimina: se desactiva.
 */
export function CatalogPage() {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY)
  const [toDelete, setToDelete] = useState(null)
  const formRef = useRef(null)

  useEffect(() => {
    if (editing !== null) formRef.current?.querySelector('input')?.focus()
  }, [editing])
  const queryKey = ['procedures', slug]

  const list = useQuery({ queryKey, queryFn: async () => (await apiClient.get('/procedures')).data.data })
  const catalog = useQuery({
    queryKey: ['finding-catalog'],
    queryFn: async () => (await apiClient.get('/finding-catalog')).data.data,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey })
  const save = useMutation({
    mutationFn: async (payload) =>
      editing === 'new'
        ? (await apiClient.post('/procedures', payload)).data.data
        : (await apiClient.patch(`/procedures/${editing}`, payload)).data.data,
    onSuccess: () => {
      setEditing(null)
      refresh()
    },
  })
  const toggle = useMutation({
    mutationFn: async (procedure) =>
      apiClient.patch(`/procedures/${procedure.id}`, { is_active: !procedure.is_active }),
    onSuccess: refresh,
  })
  const remove = useMutation({
    mutationFn: async (procedure) => apiClient.delete(`/procedures/${procedure.id}`),
    onSuccess: () => {
      setToDelete(null)
      refresh()
    },
  })

  const open = (procedure) => {
    save.reset()
    setForm(procedure ? formFrom(procedure) : EMPTY)
    setEditing(procedure ? procedure.id : 'new')
  }
  const set = (field) => (event) =>
    setForm({ ...form, [field]: event.target.type === 'checkbox' ? event.target.checked : event.target.value })
  const handleSubmit = (event) => {
    event.preventDefault()
    save.mutate({
      ...form,
      category: form.category.trim() === '' ? null : form.category.trim(),
      requires_surface: form.requires_tooth && form.requires_surface,
      resulting_finding_code: form.resulting_finding_code || null,
      resulting_state_code: form.resulting_finding_code ? form.resulting_state_code || null : null,
    })
  }

  const errors = fieldErrors(save.error)
  const findings = catalog.data ?? []
  const states = findings.find((finding) => finding.code === form.resulting_finding_code)?.states ?? []
  const procedures = list.data ?? []

  return (
    <div className="card">
      <div className="page-header">
        <h1 className="m-0">Catálogo de procedimientos</h1>
        {editing === null && (
          <button type="button" className="btn" onClick={() => open(null)}>
            Nuevo procedimiento
          </button>
        )}
      </div>

      {editing !== null && (
        <form ref={formRef} onSubmit={handleSubmit} noValidate className="form-section">
          <h2>{editing === 'new' ? 'Nuevo procedimiento' : 'Editar procedimiento'}</h2>
          {generalError(save.error) && <div className="alert alert-error">{generalError(save.error)}</div>}
          <div className="form-grid">
            <Field
              label="Código"
              name="code"
              value={form.code}
              onChange={set('code')}
              error={errors.code}
              maxLength={30}
            />
            <Field
              label="Nombre"
              name="name"
              value={form.name}
              onChange={set('name')}
              error={errors.name}
              maxLength={150}
            />
            <Field
              label="Categoría (opcional)"
              name="category"
              value={form.category}
              onChange={set('category')}
              error={errors.category}
              maxLength={60}
            />
            <Field
              label="Precio (S/)"
              name="price"
              type="number"
              inputMode="decimal"
              min="0"
              max="99999.99"
              step="0.01"
              value={form.price}
              onChange={set('price')}
              error={errors.price}
            />
            <Field
              label="Hallazgo resultante (opcional)"
              name="resulting_finding_code"
              error={errors.resulting_finding_code}
            >
              <select
                id="resulting_finding_code"
                value={form.resulting_finding_code}
                onChange={(event) =>
                  setForm({ ...form, resulting_finding_code: event.target.value, resulting_state_code: '' })
                }
              >
                <option value="">Sin hallazgo resultante</option>
                {findings.map((finding) => (
                  <option key={finding.code} value={finding.code}>
                    {finding.name}
                  </option>
                ))}
              </select>
            </Field>
            {form.resulting_finding_code && (
              <Field label="Estado resultante" name="resulting_state_code" error={errors.resulting_state_code}>
                <select
                  id="resulting_state_code"
                  value={form.resulting_state_code}
                  onChange={set('resulting_state_code')}
                >
                  <option value="">Selecciona un estado</option>
                  {states.map((state) => (
                    <option key={state.code} value={state.code}>
                      {state.name}
                      {state.acronym ? ` (${state.acronym})` : ''} · {state.color === 'rojo' ? 'rojo' : 'azul'}
                    </option>
                  ))}
                </select>
              </Field>
            )}
          </div>
          <fieldset className="m-0 border-0 p-0">
            <legend className="text-label-md">Requiere</legend>
            <div className="flex flex-wrap gap-4">
              <label className="checkbox">
                <input type="checkbox" checked={form.requires_tooth} onChange={set('requires_tooth')} />
                Pieza
              </label>
              <label className="checkbox">
                <input
                  type="checkbox"
                  checked={form.requires_tooth && form.requires_surface}
                  disabled={!form.requires_tooth}
                  onChange={set('requires_surface')}
                />
                Superficie
              </label>
              <label className="checkbox">
                <input
                  type="checkbox"
                  checked={form.requires_informed_consent}
                  onChange={set('requires_informed_consent')}
                />
                Consentimiento informado
              </label>
            </div>
            {errors.requires_surface && (
              <span className="error" role="alert">
                {errors.requires_surface}
              </span>
            )}
          </fieldset>
          <div className="form-actions">
            <button type="submit" className="btn" disabled={save.isPending}>
              {save.isPending ? 'Guardando…' : 'Guardar procedimiento'}
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setEditing(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {generalError(toggle.error) && <div className="alert alert-error">{generalError(toggle.error)}</div>}
      {list.isLoading && <div className="empty">Cargando…</div>}
      {list.isError && <div className="alert alert-error">{generalError(list.error)}</div>}
      {list.isSuccess && procedures.length === 0 && (
        <div className="empty">Aún no hay procedimientos en el catálogo.</div>
      )}
      {procedures.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Código</th>
                <th>Procedimiento</th>
                <th>Categoría</th>
                <th>Precio</th>
                <th>Requiere</th>
                <th>Hallazgo resultante</th>
                <th>Estado</th>
                <th>
                  <span className="sr-only">Acciones</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {procedures.map((procedure) => (
                <tr key={procedure.id}>
                  <td className="mono">{procedure.code}</td>
                  <td>{procedure.name}</td>
                  <td>{procedure.category ?? '—'}</td>
                  <td>{formatCurrency(procedure.price)}</td>
                  <td>{requirementsText(procedure)}</td>
                  <td>
                    {procedure.resulting_finding
                      ? `${procedure.resulting_finding.name} · ${procedure.resulting_state?.name ?? ''}`
                      : '—'}
                  </td>
                  <td>
                    <span className={procedure.is_active ? 'badge' : 'badge badge-muted'}>
                      {procedure.is_active ? 'Activo' : 'Inactivo'}
                    </span>
                  </td>
                  <td>
                    <div className="flex flex-wrap gap-3">
                      <button type="button" className="btn-link" onClick={() => open(procedure)}>
                        Editar
                      </button>
                      <button
                        type="button"
                        className="btn-link"
                        onClick={() => toggle.mutate(procedure)}
                        disabled={toggle.isPending}
                      >
                        {procedure.is_active ? 'Desactivar' : 'Activar'}
                      </button>
                      <button
                        type="button"
                        className="btn-link"
                        onClick={() => {
                          remove.reset()
                          setToDelete(procedure)
                        }}
                      >
                        Eliminar
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {toDelete && (
        <ConfirmDialog
          title={`¿Eliminar «${toDelete.name}»?`}
          confirmLabel="Eliminar procedimiento"
          destructive
          onConfirm={() => remove.mutate(toDelete)}
          onCancel={() => setToDelete(null)}
          busy={remove.isPending}
          error={generalError(remove.error)}
        >
          <p>
            Se eliminará del catálogo. Si ya se usó en planes o presupuestos no podrá eliminarse; en ese caso,
            desactívalo para que no se use en ítems nuevos.
          </p>
        </ConfirmDialog>
      )}
    </div>
  )
}
