import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
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
import { InlineError } from './InlineError'
import { ITEM_STATUS, SIGNERS, siteText } from './labels'
import { useFocusOnMount, usePlan, useProcedures } from './useTreatment'

const PENDING_ITEM = ['propuesto', 'aceptado']

/** Revocación con motivo de un consentimiento vigente (RF-074): el procedimiento ya no se puede registrar. */
function RevokeDialog({ consent, onRevoked, onCancel }) {
  const [reason, setReason] = useState('')
  const revoke = useMutation({
    mutationFn: async () =>
      (await apiClient.post(`/informed-consents/${consent.id}/revoke`, { reason: reason.trim() })).data.data,
    onSuccess: onRevoked,
  })

  return (
    <ConfirmDialog
      title="¿Revocar el consentimiento informado?"
      confirmLabel="Revocar consentimiento"
      destructive
      confirmDisabled={reason.trim() === ''}
      onConfirm={() => revoke.mutate()}
      onCancel={onCancel}
      busy={revoke.isPending}
      error={generalError(revoke.error)}
    >
      <p>El procedimiento no se podrá registrar sin un consentimiento nuevo. La revocación no se deshace.</p>
      <Field
        label="Motivo de la revocación"
        name="revoke-reason"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        error={fieldErrors(revoke.error).reason}
        maxLength={500}
      />
    </ConfirmDialog>
  )
}

/**
 * Firma presencial del consentimiento de un ítem (CUS-83; RF-073, RN-12): primero la vista previa
 * del texto que se firma; luego la confirmación con el documento del firmante en el dispositivo
 * o el formulario firmado escaneado.
 */
function SignForm({ item, plan, onSigned, onCancel }) {
  const { user } = useAuth()
  const [fills, setFills] = useState({ riesgos: '', alternativas: '' })
  const [channel, setChannel] = useState('dispositivo')
  const [documentNumber, setDocumentNumber] = useState('')
  const [file, setFile] = useState(null)
  // El personal no odontólogo indica quién informó; se propone al odontólogo que elaboró el plan.
  const informedBy = user?.role === 'dentist' ? null : plan.created_by?.id

  const preview = useMutation({
    mutationFn: async () =>
      (
        await apiClient.get(`/plan-items/${item.id}/informed-consents/preview`, {
          params: { ...fills, ...(informedBy ? { informed_by: informedBy } : {}) },
        })
      ).data.data,
  })
  const sign = useMutation({
    mutationFn: async () => {
      const data = new FormData()
      data.append('channel', channel)
      if (channel === 'dispositivo') data.append('confirmation_document_number', documentNumber.trim())
      if (channel === 'papel' && file) data.append('scanned_file', file)
      if (informedBy) data.append('informed_by', informedBy)
      if (fills.riesgos.trim()) data.append('riesgos', fills.riesgos.trim())
      if (fills.alternativas.trim()) data.append('alternativas', fills.alternativas.trim())
      return (await apiClient.post(`/plan-items/${item.id}/informed-consents`, data)).data.data
    },
    onSuccess: onSigned,
  })
  const errors = { ...fieldErrors(preview.error), ...fieldErrors(sign.error) }
  const prefix = `consent-${item.id}`
  const ready = channel === 'dispositivo' ? documentNumber.trim() !== '' : file !== null
  const formRef = useFocusOnMount()

  return (
    <div className="form-section" ref={formRef}>
      <h3>Consentimiento de {item.procedure.name}</h3>
      {generalError(preview.error) && (
        <div className="alert alert-error" role="alert">
          {generalError(preview.error)}
        </div>
      )}
      <div className="form-grid">
        <Field label="Riesgos (opcional)" name={`${prefix}-riesgos`} error={errors.riesgos}>
          <textarea
            id={`${prefix}-riesgos`}
            rows={2}
            maxLength={500}
            aria-invalid={errors.riesgos ? true : undefined}
            aria-describedby={errors.riesgos ? `${prefix}-riesgos-error` : undefined}
            value={fills.riesgos}
            onChange={(event) => {
              preview.reset()
              setFills({ ...fills, riesgos: event.target.value })
            }}
          />
        </Field>
        <Field label="Alternativas (opcional)" name={`${prefix}-alternativas`} error={errors.alternativas}>
          <textarea
            id={`${prefix}-alternativas`}
            rows={2}
            maxLength={1000}
            aria-invalid={errors.alternativas ? true : undefined}
            aria-describedby={errors.alternativas ? `${prefix}-alternativas-error` : undefined}
            value={fills.alternativas}
            onChange={(event) => {
              preview.reset()
              setFills({ ...fills, alternativas: event.target.value })
            }}
          />
        </Field>
      </div>
      {informedBy && <p className="muted">Odontólogo que informa: {plan.created_by.name}.</p>}
      <InlineError>{errors.informed_by}</InlineError>
      <div className="form-actions">
        <button
          type="button"
          className="btn btn-secondary"
          onClick={() => preview.mutate()}
          disabled={preview.isPending}
        >
          Ver texto a firmar
        </button>
      </div>

      {preview.isSuccess && (
        <>
          <section aria-label="Texto del consentimiento" className="card">
            <p className="muted m-0">
              Versión {preview.data.template_version} · Firma{' '}
              {preview.data.representative
                ? `${SIGNERS.representante.toLowerCase()}: ${preview.data.representative.first_name} ${preview.data.representative.last_name}`
                : SIGNERS.titular.toLowerCase()}
            </p>
            <p className="whitespace-pre-line">{preview.data.text}</p>
          </section>

          <form
            noValidate
            onSubmit={(event) => {
              event.preventDefault()
              sign.mutate()
            }}
          >
            {generalError(sign.error) && (
              <div className="alert alert-error" role="alert">
                {generalError(sign.error)}
              </div>
            )}
            <fieldset className="m-0 border-0 p-0">
              <legend className="text-label-md">Forma de firma</legend>
              <div className="flex flex-wrap gap-4">
                <label className="checkbox">
                  <input
                    type="radio"
                    name={`${prefix}-channel`}
                    value="dispositivo"
                    checked={channel === 'dispositivo'}
                    onChange={() => setChannel('dispositivo')}
                  />
                  En este dispositivo
                </label>
                <label className="checkbox">
                  <input
                    type="radio"
                    name={`${prefix}-channel`}
                    value="papel"
                    checked={channel === 'papel'}
                    onChange={() => setChannel('papel')}
                  />
                  Formulario en papel escaneado
                </label>
              </div>
            </fieldset>
            {channel === 'dispositivo' ? (
              <Field
                label="Documento de quien firma"
                name={`${prefix}-document`}
                value={documentNumber}
                onChange={(event) => setDocumentNumber(event.target.value)}
                error={errors.confirmation_document_number}
                hint="Quien firma lo digita para confirmar que leyó el texto."
                autoComplete="off"
                maxLength={12}
              />
            ) : (
              <Field label="Formulario firmado (PDF o imagen)" name={`${prefix}-file`} error={errors.scanned_file}>
                <input
                  id={`${prefix}-file`}
                  type="file"
                  accept="application/pdf,image/jpeg,image/png"
                  aria-invalid={errors.scanned_file ? true : undefined}
                  aria-describedby={errors.scanned_file ? `${prefix}-file-error` : undefined}
                  onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                />
              </Field>
            )}
            <div className="form-actions">
              <button type="submit" className="btn" disabled={!ready || sign.isPending}>
                Registrar firma
              </button>
              <button type="button" className="btn btn-secondary" onClick={onCancel}>
                Cancelar
              </button>
            </div>
          </form>
        </>
      )}
    </div>
  )
}

/**
 * Consentimientos informados del plan (/c/:slug/app/planes/:uuid/consentimientos; CUS-83; RF-073,
 * RF-074, RN-76): los ítems pendientes cuyo procedimiento lo exige, con su firma presencial. La
 * API no lista los consentimientos de un ítem: se muestran los firmados en esta sesión, que se
 * pueden revocar.
 */
export function PlanConsentsPage() {
  const { uuid } = useParams()
  const { appPath } = useClinic()
  const plan = usePlan(uuid)
  const procedures = useProcedures()
  const patient = usePatient(plan.data?.patient_id)
  const [signing, setSigning] = useState(null)
  const [signed, setSigned] = useState({})
  const [revoking, setRevoking] = useState(null)

  if (plan.isLoading || procedures.isLoading) return <div className="card empty">Cargando…</div>
  if (plan.isError)
    return (
      <div className="alert alert-error" role="alert">
        {generalError(plan.error)}
      </div>
    )

  const requiresConsent = (item) =>
    procedures.data?.find((procedure) => procedure.id === item.procedure.id)?.requires_informed_consent
  const items = plan.data.items.filter((item) => PENDING_ITEM.includes(item.status) && requiresConsent(item))
  const record = (itemId, consent) => setSigned((current) => ({ ...current, [itemId]: consent }))

  return (
    <>
      <div className="page-header">
        <Link to={appPath(`/planes/${uuid}`)} className="btn btn-secondary">
          Volver al plan
        </Link>
      </div>

      {patient.data && <PatientHeader patient={patient.data} />}

      <div className="card">
        <h2>Consentimientos informados</h2>
        <p className="muted">
          Plan «{plan.data.title}». El paciente, o su representante si es menor de edad, firma en forma presencial antes
          de realizar cada procedimiento que lo exige.
        </p>
        {items.length === 0 ? (
          <div className="empty">Ningún ítem pendiente del plan exige consentimiento informado.</div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Procedimiento</th>
                  <th>Pieza</th>
                  <th>Ítem</th>
                  <th>Consentimiento</th>
                  <th>
                    <span className="sr-only">Acciones</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => {
                  const consent = signed[item.id]
                  return (
                    <tr key={item.id}>
                      <td>{item.procedure.name}</td>
                      <td>{siteText(item.tooth, item.surfaces)}</td>
                      <td>{ITEM_STATUS[item.status]}</td>
                      <td>
                        {consent ? (
                          <>
                            <span className={consent.status === 'revocado' ? 'badge badge-muted' : 'badge'}>
                              {consent.status === 'revocado' ? 'Revocado' : 'Firmado'}
                            </span>
                            <div className="muted">
                              Versión {consent.template_version} · {formatDateTime(consent.signed_at)}
                            </div>
                          </>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td>
                        {(!consent || consent.status === 'revocado') && signing !== item.id && (
                          <button
                            type="button"
                            className="btn-link"
                            aria-label={`${consent ? 'Firmar uno nuevo' : 'Firmar'}: consentimiento de ${item.procedure.name}`}
                            onClick={() => setSigning(item.id)}
                          >
                            {consent ? 'Firmar uno nuevo' : 'Firmar'}
                          </button>
                        )}
                        {consent?.status === 'vigente' && (
                          <button
                            type="button"
                            className="btn-link"
                            aria-label={`Revocar el consentimiento de ${item.procedure.name}`}
                            onClick={() => setRevoking(consent)}
                          >
                            Revocar
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        {signing && (
          <SignForm
            key={signing}
            item={items.find((item) => item.id === signing)}
            plan={plan.data}
            onSigned={(consent) => {
              record(signing, consent)
              setSigning(null)
            }}
            onCancel={() => setSigning(null)}
          />
        )}
        {Object.keys(signed).length > 0 && (
          <Alert tone="info" title="Consentimientos de esta sesión" className="mt-4">
            Los consentimientos firmados quedan registrados en el plan; esta lista solo muestra los de esta sesión.
          </Alert>
        )}
      </div>

      {revoking && (
        <RevokeDialog
          consent={revoking}
          onRevoked={(consent) => {
            const itemId = Object.keys(signed).find((key) => signed[key].id === consent.id)
            record(itemId, consent)
            setRevoking(null)
          }}
          onCancel={() => setRevoking(null)}
        />
      )}
    </>
  )
}
