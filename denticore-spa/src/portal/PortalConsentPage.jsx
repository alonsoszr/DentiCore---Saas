import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'
import { Alert } from '../components/Alert'
import { AlertCircleIcon } from '../ui/icons'
import { ConsentForm } from './ConsentForm'
import { subjectName } from './portalQueries'
import { WithPortalSubject } from './WithPortalSubject'

/**
 * CUS-17 FA-1: la confirmación es la propia sesión más la casilla «He leído y acepto»; el canal
 * es `portal`. Las finalidades opcionales salen sin marcar (CA-17.1). La revocación (CUS-18) es
 * de E2.
 */
function ConsentGrant({ subject }) {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const [accepted, setAccepted] = useState(false)
  const [missing, setMissing] = useState(false)
  const [granted, setGranted] = useState(false)
  const grantedRef = useRef(null)
  const acceptRef = useRef(null)
  useEffect(() => {
    if (granted) grantedRef.current?.focus()
  }, [granted])

  const preview = useQuery({
    queryKey: ['portal-consent-preview', slug, subject.id],
    queryFn: async () => (await apiClient.get(`/portal/patients/${subject.id}/consents/preview`)).data.data,
  })
  const grant = useMutation({
    mutationFn: async (purposes) =>
      (await apiClient.post(`/portal/patients/${subject.id}/consents`, purposes)).data.data,
    onSuccess: () => {
      setGranted(true)
      queryClient.invalidateQueries({ queryKey: ['portal-consents', slug, subject.id] })
    },
  })

  if (subject.relationship === 'titular' && subject.is_minor) {
    return (
      <Alert tone="info" size="lg" role={null}>
        La decisión corresponde al representante legal.
      </Alert>
    )
  }
  if (granted) {
    return (
      <div ref={grantedRef} tabIndex={-1}>
        <Alert tone="success" size="lg" title="Consentimiento registrado" />
      </div>
    )
  }
  if (preview.isLoading) {
    return (
      <div className="empty" role="status">
        Cargando…
      </div>
    )
  }
  if (preview.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {generalError(preview.error)}
      </div>
    )
  }

  return (
    <div className="card">
      {subject.relationship === 'representado' && <p className="mt-0">Consentimiento de {subjectName(subject)}.</p>}
      {generalError(grant.error) && (
        <div className="alert alert-error mb-4" role="alert">
          {generalError(grant.error)}
        </div>
      )}
      <ConsentForm
        preview={preview.data}
        errors={fieldErrors(grant.error)}
        submitting={grant.isPending}
        submitLabel="Otorgar consentimiento"
        onSubmit={(purposes) => {
          if (!accepted) {
            setMissing(true)
            acceptRef.current?.focus()
            return
          }
          grant.mutate(purposes)
        }}
      >
        <div className="field field-lg">
          <label className="checkbox">
            <input
              ref={acceptRef}
              type="checkbox"
              checked={accepted}
              aria-invalid={missing && !accepted ? true : undefined}
              aria-describedby={missing && !accepted ? 'consent-accept-error' : undefined}
              onChange={(event) => {
                setAccepted(event.target.checked)
                setMissing(false)
              }}
            />
            He leído y acepto
          </label>
          {missing && !accepted && (
            <span id="consent-accept-error" className="error" role="alert">
              <AlertCircleIcon className="size-4 shrink-0" />
              Marca «He leído y acepto» para otorgar el consentimiento.
            </span>
          )}
        </div>
      </ConsentForm>
    </div>
  )
}

/** /c/:slug/portal/consentimiento (CUS-17). */
export function PortalConsentPage() {
  const { portalPath } = useClinic()

  return (
    <>
      <Link to={portalPath('/mi-historia')} className="portal-back">
        Volver a Mi salud
      </Link>
      <h1>Consentimiento de datos</h1>
      <WithPortalSubject>{(subject) => <ConsentGrant subject={subject} />}</WithPortalSubject>
    </>
  )
}
