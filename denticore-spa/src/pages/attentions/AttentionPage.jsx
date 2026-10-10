import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useLocation, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { formatDateTime } from '../../ui/format'
import { PatientHeader } from '../patients/PatientHeader'
import { usePatient } from '../patients/usePatient'
import { ProceduresPanel } from '../treatment/ProceduresPanel'
import { Cie10Picker } from './Cie10Picker'
import { DiagnosesList, DiagnosesPanel } from './DiagnosesPanel'
import { FindingsWorkspace } from './FindingsWorkspace'
import { ATTENTION_STATUS } from './labels'
import { NotePanel, NoteView } from './NotePanel'

/** Cierre con confirmación de sus consecuencias (CUS-26; RF-094, RN-77, RN-78, RNF-063). */
function CloseAttention({ attention, queryKey }) {
  const queryClient = useQueryClient()
  const [confirming, setConfirming] = useState(false)
  const close = useMutation({
    mutationFn: async () => (await apiClient.post(`/attentions/${attention.id}/close`)).data.data,
    onSuccess: () => {
      setConfirming(false)
      queryClient.invalidateQueries({ queryKey })
    },
    onError: () => setConfirming(false),
  })
  const missing = Object.values(fieldErrors(close.error))

  return (
    <section className="card flex flex-col gap-3" aria-labelledby="close-title">
      <h2 id="close-title">Cerrar atención</h2>
      <p className="m-0 muted">Para cerrar, la nota necesita el motivo de consulta y al menos un diagnóstico CIE-10.</p>
      {missing.length > 0 && (
        <Alert tone="error" title="No se puede cerrar la atención">
          <ul className="m-0 pl-4">
            {missing.map((message) => (
              <li key={message}>{message}</li>
            ))}
          </ul>
        </Alert>
      )}
      {close.isError && generalError(close.error) && (
        <div className="alert alert-error">{generalError(close.error)}</div>
      )}
      <div className="form-actions">
        <button type="button" className="btn" onClick={() => setConfirming(true)}>
          Cerrar atención
        </button>
      </div>
      {confirming && (
        <ConfirmDialog
          title="Cerrar la atención"
          confirmLabel="Confirmar cierre"
          busy={close.isPending}
          onCancel={() => setConfirming(false)}
          onConfirm={() => close.mutate()}
        >
          <ul className="mt-0 pl-4">
            <li>La nota y los diagnósticos quedan firmados con su número de COP y ya no se pueden modificar.</li>
            <li>La información posterior se registra como adenda.</li>
            {attention.is_first_attention && <li>El odontograma inicial del paciente se cierra.</li>}
          </ul>
        </ConfirmDialog>
      )}
    </section>
  )
}

/** Adenda a una atención cerrada (CUS-81; RF-097, RN-77): texto, motivo y diagnósticos opcionales. */
function AddendumForm({ attention, queryKey }) {
  const queryClient = useQueryClient()
  const [text, setText] = useState('')
  const [chiefComplaint, setChiefComplaint] = useState('')
  const [diagnoses, setDiagnoses] = useState([])
  const add = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post(`/attentions/${attention.id}/addenda`, {
          text,
          chief_complaint: chiefComplaint.trim() === '' ? null : chiefComplaint,
          diagnoses: diagnoses.map(({ cie10_code, type }) => ({ cie10_code, type })),
        })
      ).data.data,
    onSuccess: () => {
      setText('')
      setChiefComplaint('')
      setDiagnoses([])
      queryClient.invalidateQueries({ queryKey })
    },
  })
  const errors = fieldErrors(add.error)

  return (
    <form
      className="card flex flex-col gap-3"
      aria-labelledby="addendum-title"
      onSubmit={(event) => {
        event.preventDefault()
        add.mutate()
      }}
    >
      <h2 id="addendum-title">Nueva adenda</h2>
      <Field label="Texto de la adenda" name="addendum-text" error={errors.text}>
        <textarea
          id="addendum-text"
          rows={3}
          maxLength={2000}
          value={text}
          onChange={(event) => setText(event.target.value)}
        />
      </Field>
      {attention.status === 'cerrada_incompleta' && (
        <p className="m-0 muted">
          Esta atención se cerró sin motivo o sin diagnóstico: se completa con una adenda que los registre.
        </p>
      )}
      <Field
        label="Motivo de consulta (si faltó)"
        name="addendum-chief-complaint"
        value={chiefComplaint}
        error={errors.chief_complaint}
        onChange={(event) => setChiefComplaint(event.target.value)}
      />
      <DiagnosesList
        diagnoses={diagnoses}
        onRemove={(removed) => setDiagnoses(diagnoses.filter((d) => d.cie10_code !== removed.cie10_code))}
      />
      <Cie10Picker
        onAdd={(picked) => setDiagnoses([...diagnoses.filter((d) => d.cie10_code !== picked.cie10_code), picked])}
      />
      {add.isError && generalError(add.error) && <div className="alert alert-error">{generalError(add.error)}</div>}
      {add.isSuccess && <div className="alert alert-success">Adenda registrada.</div>}
      <div className="form-actions">
        <button type="submit" className="btn" disabled={add.isPending || text.trim() === ''}>
          Registrar adenda
        </button>
      </div>
    </form>
  )
}

/**
 * Atención odontológica (/c/:slug/app/atenciones/:uuid; CUS-22, CUS-23, CUS-26, CUS-80, CUS-81).
 * La cabecera del paciente con sus alergias encabeza la pantalla, antes de cualquier registro
 * clínico (RNF-146, RNF-149). Con la atención abierta, el odontólogo a cargo registra la nota,
 * los diagnósticos, los hallazgos y los procedimientos (CUS-39) y la cierra; cerrada, queda en
 * solo lectura con adendas.
 */
export function AttentionPage() {
  const { uuid } = useParams()
  const location = useLocation()
  const { user } = useAuth()
  const { slug, appPath } = useClinic()
  const queryKey = ['attentions', slug, uuid]

  const attentionQuery = useQuery({
    queryKey,
    queryFn: async () => (await apiClient.get(`/attentions/${uuid}`)).data.data,
    retry: false,
  })
  const attention = attentionQuery.data
  const patientQuery = usePatient(attention?.patient_id)
  const patient = patientQuery.data
  const toProcedures = location.pathname.endsWith('/procedimientos')

  // /atenciones/:uuid/procedimientos (CUS-39) abre la misma atención en su sección de procedimientos.
  useEffect(() => {
    if (toProcedures && patient) document.getElementById('procedimientos')?.scrollIntoView()
  }, [toProcedures, patient])

  if (attentionQuery.isLoading || (attention && patientQuery.isLoading))
    return <div className="card empty">Cargando…</div>
  if (attentionQuery.isError) return <div className="alert alert-error">{generalError(attentionQuery.error)}</div>
  if (patientQuery.isError) return <div className="alert alert-error">{generalError(patientQuery.error)}</div>

  const open = attention.status === 'abierta'
  const inCharge = user?.role === 'dentist' && attention.dentist.id === user.id
  const consentWarning =
    location.state?.consentWarning ??
    (patient.has_current_consent
      ? null
      : { detail: 'El paciente no tiene un consentimiento de datos vigente: no podrá registrar datos clínicos.' })

  return (
    <>
      <div className="page-header">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="m-0 text-headline-md">Atención del {formatDateTime(attention.opened_at)}</h1>
          <span className={open ? 'badge' : 'badge badge-muted'}>{ATTENTION_STATUS[attention.status]}</span>
        </div>
        <Link to={appPath(`/pacientes/${patient.id}/hc`)} className="btn btn-secondary">
          Volver a la historia clínica
        </Link>
      </div>

      <PatientHeader patient={patient} />

      <p className="muted">
        Odontólogo a cargo: {attention.dentist.name} · COP {attention.dentist.cop}
        {attention.signer &&
          ` · Firmada por ${attention.signer.name} (COP ${attention.signer.cop}) el ${formatDateTime(attention.signed_at)}`}
      </p>

      {open && consentWarning && (
        <Alert tone="warning" title="Registro clínico bloqueado">
          {consentWarning.detail}{' '}
          <Link to={appPath(`/pacientes/${patient.id}/consentimiento`)}>Registrar el consentimiento</Link>
        </Alert>
      )}

      {open && inCharge ? (
        <>
          <NotePanel key={attention.id} attention={attention} queryKey={queryKey} />
          <DiagnosesPanel attention={attention} queryKey={queryKey} />
          <FindingsWorkspace attention={attention} patient={patient} />
          <ProceduresPanel attention={attention} patient={patient} />
          <CloseAttention attention={attention} queryKey={queryKey} />
        </>
      ) : (
        <>
          <NoteView note={attention.note} />
          <section className="card" aria-labelledby="diagnoses-title">
            <h2 id="diagnoses-title">Diagnósticos CIE-10</h2>
            <DiagnosesList diagnoses={attention.diagnoses ?? []} />
          </section>
          {(attention.addenda ?? []).length > 0 && (
            <section className="card" aria-labelledby="addenda-title">
              <h2 id="addenda-title">Adendas</h2>
              <ol className="m-0 flex list-none flex-col gap-3 p-0">
                {attention.addenda.map((addendum) => (
                  <li key={addendum.id}>
                    <p className="m-0 whitespace-pre-line">{addendum.text}</p>
                    {addendum.chief_complaint && <p className="m-0">Motivo de consulta: {addendum.chief_complaint}</p>}
                    <DiagnosesList diagnoses={addendum.diagnoses ?? []} />
                    <p className="m-0 muted">
                      {addendum.author.name} · COP {addendum.author.cop} · {formatDateTime(addendum.created_at)}
                    </p>
                  </li>
                ))}
              </ol>
            </section>
          )}
          {!open && user?.role === 'dentist' && <AddendumForm attention={attention} queryKey={queryKey} />}
        </>
      )}
    </>
  )
}
