import { useId, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { PURPOSES } from '../../portal/consentPurposes'
import { useFocusFirstError } from '../../ui/useFocusFirstError'
import { patientRightsBase, PURPOSE_CODES } from './rights'

function RevocationForm({ consent, patientId, portal, onRevoked }) {
  const { slug } = useClinic()
  const client = useQueryClient()
  const [purposes, setPurposes] = useState([])
  const [reason, setReason] = useState('')
  const [confirming, setConfirming] = useState(false)
  const revoke = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post(`${patientRightsBase(patientId, portal)}/consents/${consent.id}/revocations`, {
          purposes,
          reason: reason.trim() || null,
        })
      ).data.data,
    onSuccess: () => {
      onRevoked()
      setConfirming(false)
      setPurposes([])
      setReason('')
      for (const key of ['consents', 'portal-consents', 'patients', 'clinical-record', 'portal-subjects']) {
        client.invalidateQueries({ queryKey: [key, slug] })
      }
    },
    onError: (error) => {
      if (error.response?.status === 422) setConfirming(false)
    },
  })
  const errors = fieldErrors(revoke.error)
  const purposeErrorId = useId()
  const purposeError = Object.entries(errors)
    .filter(([key]) => key === 'purposes' || key.startsWith('purposes.'))
    .map(([, message]) => message)
    .join(' ')
  useFocusFirstError(revoke.error)
  const available = PURPOSES.map((purpose, index) => ({ ...purpose, code: PURPOSE_CODES[index] })).filter(
    (purpose) => consent[purpose.field] && !(consent.revoked_purposes ?? []).includes(purpose.code),
  )

  return (
    <>
      {revoke.isError && !confirming && <Alert tone="error">{generalError(revoke.error)}</Alert>}
      <form
        onSubmit={(event) => {
          event.preventDefault()
          revoke.reset()
          setConfirming(true)
        }}
      >
        <fieldset disabled={revoke.isPending}>
          <legend>Finalidades que deseas revocar</legend>
          {available.map((purpose) => (
            <label className="checkbox" key={purpose.code}>
              <input
                type="checkbox"
                aria-invalid={purposeError ? true : undefined}
                aria-describedby={purposeError ? purposeErrorId : undefined}
                checked={purposes.includes(purpose.code)}
                onChange={(event) => {
                  setPurposes(
                    event.target.checked
                      ? [...purposes, purpose.code]
                      : purposes.filter((value) => value !== purpose.code),
                  )
                }}
              />
              Revocar {purpose.label}
            </label>
          ))}
          {purposeError && (
            <p id={purposeErrorId} className="error" role="alert">
              {purposeError}
            </p>
          )}
        </fieldset>
        <Field
          label="Motivo (opcional)"
          name="reason"
          value={reason}
          maxLength={500}
          onChange={(event) => setReason(event.target.value)}
          error={errors.reason}
          size={portal ? 'lg' : undefined}
        />
        <div className="form-actions">
          <button className="btn btn-danger" disabled={purposes.length === 0 || revoke.isPending}>
            Revocar finalidades
          </button>
        </div>
      </form>
      {confirming && (
        <ConfirmDialog
          title="Revocar finalidades"
          confirmLabel="Confirmar revocación"
          destructive
          busy={revoke.isPending}
          error={generalError(revoke.error)}
          onCancel={() => setConfirming(false)}
          onConfirm={() => revoke.mutate()}
        >
          <p>La revocación tiene efecto inmediato para las finalidades seleccionadas.</p>
          {purposes.includes('atencion') && (
            <p>
              El consentimiento quedará revocado y no se podrán registrar nuevos datos clínicos hasta otorgar uno nuevo.
            </p>
          )}
        </ConfirmDialog>
      )}
    </>
  )
}

export function ConsentRevocation({ patientId, portal = false }) {
  const { slug } = useClinic()
  const [revoked, setRevoked] = useState(false)
  const query = useQuery({
    queryKey: [portal ? 'portal-consents' : 'consents', slug, patientId],
    queryFn: async () => (await apiClient.get(`${patientRightsBase(patientId, portal)}/consents`)).data.data,
  })
  const current = query.data?.find((consent) => consent.status === 'vigente')
  return (
    <section className="card">
      <h2>Revocar finalidades</h2>
      {revoked && (
        <Alert tone="success" role="status">
          Finalidades revocadas.
        </Alert>
      )}
      {query.isLoading && <p role="status">Cargando…</p>}
      {query.isError && (
        <>
          <Alert tone="error">{generalError(query.error)}</Alert>
          <button type="button" className="btn btn-secondary" onClick={() => query.refetch()}>
            Reintentar
          </button>
        </>
      )}
      {query.isSuccess && !current && <p>No hay un consentimiento vigente que se pueda revocar.</p>}
      {current && (
        <RevocationForm
          key={current.id}
          consent={current}
          patientId={patientId}
          portal={portal}
          onRevoked={() => setRevoked(true)}
        />
      )}
    </section>
  )
}
