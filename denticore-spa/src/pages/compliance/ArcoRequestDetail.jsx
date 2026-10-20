import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { StatusBadge } from '../../components/StatusBadge'
import { FileTextIcon } from '../../ui/icons'
import { formatCivilDate } from '../../ui/format'
import { ArcoResolutionForm } from './ArcoResolutionForm'
import { ClinicalRecordCopy } from './ClinicalRecordCopy'
import { ARCO_STATUSES, ARCO_TYPES } from './rights'

function CorrectionAssignment({ request, mutation }) {
  const { slug, appPath } = useClinic()
  const [dentist, setDentist] = useState(request.assigned_dentist_id ?? '')
  const users = useQuery({
    queryKey: ['arco-dentists', slug],
    queryFn: async () =>
      (await apiClient.get('/users', { params: { role: 'dentist', status: 'activo', per_page: 100 } })).data.data,
  })
  const errors = fieldErrors(mutation.error)
  return (
    <>
      <p>
        <Link to={appPath(`/pacientes/${request.patient.id}/editar`)} state={{ arcoRequestId: request.id }}>
          Corregir identificación del paciente
        </Link>
      </p>
      <form
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate({ action: 'assign-correction', data: { dentist_id: dentist } })
        }}
      >
        <Field label="Odontólogo para rectificación clínica" name="dentist_id" error={errors.dentist_id}>
          <select id="dentist_id" value={dentist} onChange={(event) => setDentist(event.target.value)} required>
            <option value="">Selecciona un odontólogo</option>
            {(users.data ?? [])
              .filter((user) => user.role === 'dentist' && user.status === 'activo')
              .map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name}
                </option>
              ))}
          </select>
        </Field>
        {users.isError && (
          <>
            <Alert tone="error">{generalError(users.error)}</Alert>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => users.refetch()}
              disabled={users.isFetching}
            >
              Reintentar odontólogos
            </button>
          </>
        )}
        <div className="form-actions">
          <button className="btn btn-secondary" disabled={mutation.isPending || !dentist}>
            Asignar rectificación
          </button>
        </div>
      </form>
    </>
  )
}

export function ArcoRequestDetail({ id }) {
  const { slug } = useClinic()
  const client = useQueryClient()
  const heading = useRef(null)
  const [confirmation, setConfirmation] = useState(null)
  useEffect(() => {
    heading.current?.focus()
  }, [id])
  const queryKey = ['arco-request', slug, id]
  const detail = useQuery({ queryKey, queryFn: async () => (await apiClient.get(`/arco-requests/${id}`)).data.data })
  const request = detail.data
  const patient = useQuery({
    queryKey: ['patients', slug, request?.patient?.id],
    queryFn: async () => (await apiClient.get(`/patients/${request.patient.id}`)).data.data,
    enabled: request?.type === 'cancelacion' && Boolean(request?.patient?.id),
  })
  const action = useMutation({
    mutationFn: async ({ action: name, data = {} }) =>
      (await apiClient.post(`/arco-requests/${id}/${name}`, data)).data.data,
    onSuccess: (data) => {
      setConfirmation(null)
      client.setQueryData(queryKey, data)
      client.invalidateQueries({ queryKey })
      for (const key of [
        'arco-requests',
        'patient-arco',
        'patients',
        'clinical-record',
        'consents',
        'portal-subjects',
      ]) {
        client.invalidateQueries({ queryKey: [key, slug] })
      }
    },
    onError: (error) => {
      if ([409, 422].includes(error.response?.status)) setConfirmation(null)
      if (error.response?.status === 409) {
        client.invalidateQueries({ queryKey })
        client.invalidateQueries({ queryKey: ['patients', slug, request?.patient?.id] })
      }
    },
  })
  const blocked = patient.data?.archive_status === 'bloqueado'
  return (
    <section className="card">
      <h2 ref={heading} tabIndex={-1}>
        Detalle de solicitud
      </h2>
      {detail.isLoading && <p role="status">Cargando…</p>}
      {detail.isError && (
        <>
          <Alert tone="error">{generalError(detail.error)}</Alert>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => detail.refetch()}
            disabled={detail.isFetching}
          >
            Reintentar solicitud
          </button>
        </>
      )}
      {request && (
        <>
          <h3>
            {ARCO_TYPES[request.type]} · {request.patient?.name}
          </h3>
          <StatusBadge icon={FileTextIcon}>{ARCO_STATUSES[request.status]}</StatusBadge>
          <p>{request.description}</p>
          <p>Fecha límite: {formatCivilDate(request.due_date)}</p>
          {request.remaining_business_days !== null && <p>Días hábiles restantes: {request.remaining_business_days}</p>}
          {action.isError && !confirmation && <Alert tone="error">{generalError(action.error)}</Alert>}
          {request.status === 'recibida' && (
            <button className="btn" onClick={() => action.mutate({ action: 'start' })} disabled={action.isPending}>
              Iniciar atención
            </button>
          )}
          {request.status === 'en_tramite' && (
            <>
              {request.type === 'cancelacion' && (
                <>
                  {patient.isError && (
                    <>
                      <Alert tone="error">{generalError(patient.error)}</Alert>
                      <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => patient.refetch()}
                        disabled={patient.isFetching}
                      >
                        Reintentar paciente
                      </button>
                    </>
                  )}
                  {blocked ? (
                    <Alert tone="success" role="status">
                      Paciente bloqueado. La historia clínica se conserva.
                    </Alert>
                  ) : (
                    <button
                      className="btn btn-danger"
                      disabled={action.isPending || !patient.isSuccess}
                      onClick={() => {
                        action.reset()
                        setConfirmation({ action: 'cancellation' })
                      }}
                    >
                      Aplicar cancelación ARCO
                    </button>
                  )}
                </>
              )}
              {request.type === 'rectificacion' && <CorrectionAssignment request={request} mutation={action} />}
              <ArcoResolutionForm
                request={request}
                pending={action.isPending}
                error={action.error}
                onSubmit={(data) => {
                  action.reset()
                  setConfirmation({ action: 'resolve', data })
                }}
              />
            </>
          )}
          {request.response && (
            <>
              <h3>Respuesta</h3>
              <p>{request.response}</p>
            </>
          )}
          {request.denial_reason && <p>Fundamento: {request.denial_reason}</p>}
          {request.within_deadline !== null && request.within_deadline !== undefined && (
            <p>{request.within_deadline ? 'Resuelta dentro del plazo.' : 'Resuelta fuera del plazo.'}</p>
          )}
          {request.response_document_id && (
            <ClinicalRecordCopy patientId={request.patient.id} documentId={request.response_document_id} />
          )}
        </>
      )}
      {confirmation && (
        <ConfirmDialog
          title={confirmation.action === 'cancellation' ? 'Aplicar cancelación ARCO' : 'Resolver solicitud'}
          confirmLabel={confirmation.action === 'cancellation' ? 'Bloquear paciente' : 'Confirmar resolución'}
          destructive={confirmation.action === 'cancellation'}
          onConfirm={() => action.mutate(confirmation)}
          onCancel={() => setConfirmation(null)}
          busy={action.isPending}
          error={generalError(action.error)}
        >
          {confirmation.action === 'cancellation' ? (
            <p>
              Se bloqueará al paciente, se eliminarán los datos de finalidades opcionales y se desactivará su acceso al
              portal. La historia clínica se conserva por obligación legal.
            </p>
          ) : (
            <p>Se registrará la respuesta y se notificará al solicitante. La resolución no se puede modificar.</p>
          )}
        </ConfirmDialog>
      )}
    </section>
  )
}
