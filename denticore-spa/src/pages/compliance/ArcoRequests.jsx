import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { Field } from '../../components/Field'
import { StatusBadge } from '../../components/StatusBadge'
import { FileTextIcon } from '../../ui/icons'
import { formatCivilDate, formatDateTime } from '../../ui/format'
import { useFocusFirstError } from '../../ui/useFocusFirstError'
import { ARCO_STATUSES, ARCO_TYPES, patientRightsBase } from './rights'

const EMPTY = {
  type: 'acceso',
  description: '',
  requested_by: 'titular',
  confirmation_document_number: '',
  legal_representative_id: '',
}

export function ArcoRequests({ patientId, portal = false }) {
  const { slug } = useClinic()
  const client = useQueryClient()
  const [form, setForm] = useState(EMPTY)
  const [files, setFiles] = useState([])
  const [receipt, setReceipt] = useState(null)
  const [uploadKey, setUploadKey] = useState(0)
  const base = patientRightsBase(patientId, portal)
  const queryKey = ['patient-arco', slug, patientId, portal]
  const requests = useQuery({ queryKey, queryFn: async () => (await apiClient.get(`${base}/arco-requests`)).data.data })
  const representatives = useQuery({
    queryKey: ['representatives', slug, patientId],
    queryFn: async () => (await apiClient.get(`/patients/${patientId}/representatives`)).data.data,
    enabled: !portal && form.requested_by === 'representante',
  })
  const register = useMutation({
    mutationFn: async () => {
      const payload = portal
        ? { type: form.type, description: form.description }
        : {
            ...form,
            ...(form.requested_by === 'titular' ? { legal_representative_id: undefined } : {}),
          }
      let body = payload
      if (!portal && files.length > 0) {
        body = new FormData()
        for (const [name, value] of Object.entries(payload)) if (value !== undefined) body.append(name, value)
        for (const file of files) body.append('files[]', file)
      }
      return (await apiClient.post(`${base}/arco-requests`, body)).data.data
    },
    onSuccess: (data) => {
      setReceipt(data)
      setForm(EMPTY)
      setFiles([])
      setUploadKey((value) => value + 1)
      client.invalidateQueries({ queryKey })
      client.invalidateQueries({ queryKey: ['arco-requests', slug] })
    },
  })
  const errors = fieldErrors(register.error)
  useFocusFirstError(register.error)
  const change = (event) => setForm({ ...form, [event.target.name]: event.target.value })
  const size = portal ? 'lg' : undefined
  const fileError =
    errors.files ??
    Object.entries(errors)
      .filter(([key]) => key.startsWith('files.'))
      .map(([, value]) => value)
      .join(' ')

  return (
    <>
      <section className="card">
        <h2>Registrar solicitud ARCO</h2>
        <p>Solicita acceso, rectificación, cancelación, oposición o portabilidad de tus datos.</p>
        {receipt && (
          <Alert tone="success" role="status">
            Solicitud registrada. Fecha límite: {formatCivilDate(receipt.due_date)}.
          </Alert>
        )}
        {register.isError && <Alert tone="error">{generalError(register.error)}</Alert>}
        <form
          onSubmit={(event) => {
            event.preventDefault()
            setReceipt(null)
            register.mutate()
          }}
        >
          <div className="form-grid">
            <Field label="Tipo de solicitud" name="type" error={errors.type} size={size}>
              <select id="type" name="type" value={form.type} onChange={change}>
                {Object.entries(ARCO_TYPES).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </Field>
            {!portal && (
              <Field label="Solicitante" name="requested_by" error={errors.requested_by}>
                <select id="requested_by" name="requested_by" value={form.requested_by} onChange={change}>
                  <option value="titular">Titular</option>
                  <option value="representante">Representante legal</option>
                </select>
              </Field>
            )}
            {!portal && form.requested_by === 'representante' && (
              <Field label="Representante legal" name="legal_representative_id" error={errors.legal_representative_id}>
                <select
                  id="legal_representative_id"
                  name="legal_representative_id"
                  value={form.legal_representative_id}
                  onChange={change}
                  required
                >
                  <option value="">Selecciona un representante vigente</option>
                  {(representatives.data ?? [])
                    .filter((item) => item.is_current)
                    .map((item) => (
                      <option key={item.id} value={item.id}>
                        {item.first_name} {item.last_name}
                      </option>
                    ))}
                </select>
              </Field>
            )}
            {!portal && (
              <Field
                label="Documento de confirmación"
                name="confirmation_document_number"
                value={form.confirmation_document_number}
                onChange={change}
                maxLength={12}
                required
                error={errors.confirmation_document_number}
                hint="Verifica el documento del titular o representante que presenta la solicitud."
              />
            )}
          </div>
          <Field
            label="Descripción"
            name="description"
            error={errors.description}
            size={size}
            hint="Entre 10 y 2000 caracteres."
          >
            <textarea
              id="description"
              name="description"
              value={form.description}
              onChange={change}
              minLength={10}
              maxLength={2000}
              required
              rows={4}
            />
          </Field>
          {!portal && (
            <Field
              key={uploadKey}
              label="Adjuntos (opcional, hasta 5 archivos de 5 MB)"
              name="files"
              type="file"
              multiple
              accept="application/pdf,image/jpeg,image/png"
              onChange={(event) => setFiles([...event.target.files])}
              error={fileError}
            />
          )}
          {representatives.isError && (
            <>
              <Alert tone="error">{generalError(representatives.error)}</Alert>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => representatives.refetch()}
                disabled={representatives.isFetching}
              >
                Reintentar representantes
              </button>
            </>
          )}
          <div className="form-actions">
            <button className="btn" disabled={register.isPending}>
              {register.isPending ? 'Registrando…' : 'Registrar solicitud'}
            </button>
          </div>
        </form>
      </section>
      <section className="card">
        <h2>Solicitudes registradas</h2>
        {requests.isLoading && <p role="status">Cargando…</p>}
        {requests.isError && (
          <>
            <Alert tone="error">{generalError(requests.error)}</Alert>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => requests.refetch()}
              disabled={requests.isFetching}
            >
              Reintentar historial
            </button>
          </>
        )}
        {requests.isSuccess && requests.data.length === 0 && <p>No hay solicitudes registradas.</p>}
        {(requests.data ?? []).map((request) => (
          <article key={request.id} className="portal-item">
            <h3>{ARCO_TYPES[request.type]}</h3>
            <StatusBadge icon={FileTextIcon}>{ARCO_STATUSES[request.status]}</StatusBadge>
            <p>{request.description}</p>
            <p>
              Recibida: {formatDateTime(request.received_at)} · Fecha límite: {formatCivilDate(request.due_date)}
            </p>
            {request.response && (
              <>
                <h4>Respuesta</h4>
                <p>{request.response}</p>
              </>
            )}
            {request.denial_reason && <p>Fundamento: {request.denial_reason}</p>}
          </article>
        ))}
      </section>
    </>
  )
}
