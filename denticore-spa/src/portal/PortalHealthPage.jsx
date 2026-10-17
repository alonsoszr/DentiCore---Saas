import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiClient } from '../api/client'
import { generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'
import { Field } from '../components/Field'
import { PageHeader } from '../components/PageHeader'
import { ToothHistory } from '../components/odontogram/ToothHistory'
import { ALL_TEETH } from '../components/odontogram/teeth'
import { PatientConsentBadge } from '../pages/patients/ConsentStatusBadge'
import { WithPortalSubject } from './WithPortalSubject'

const SORTED = [...ALL_TEETH].sort((a, b) => a - b)
const GROUPS = [
  ['Dentición permanente', SORTED.filter((tooth) => tooth < 50)],
  ['Dentición temporal', SORTED.filter((tooth) => tooth > 50)],
]

/** Consentimiento vigente y enlace para otorgarlo o renovarlo (CUS-17). */
function ConsentSummary({ subject }) {
  const { slug, portalPath } = useClinic()
  const consents = useQuery({
    queryKey: ['portal-consents', slug, subject.id],
    queryFn: async () => (await apiClient.get(`/portal/patients/${subject.id}/consents`)).data.data,
  })
  const current = (consents.data ?? []).find((consent) => consent.status === 'vigente')

  return (
    <section className="card portal-item" aria-labelledby="consent-title">
      <h2 id="consent-title">Consentimiento de datos</h2>
      {consents.isError && (
        <div className="alert alert-error" role="alert">
          {generalError(consents.error)}
        </div>
      )}
      {consents.isSuccess && (
        <div>
          <PatientConsentBadge
            patient={{ has_current_consent: Boolean(current), consent_outdated: Boolean(current?.outdated) }}
          />
        </div>
      )}
      <Link to={portalPath('/consentimiento')} className="portal-card-link">
        Otorgar o renovar mi consentimiento
      </Link>
    </section>
  )
}

/** CUS-24: historial de una pieza elegida en una lista (el odontograma completo del portal es de E3). */
function ToothHistoryPicker({ subject }) {
  const { slug } = useClinic()
  const [tooth, setTooth] = useState('')
  const history = useQuery({
    queryKey: ['portal-tooth-history', slug, subject.id, tooth],
    queryFn: async () => (await apiClient.get(`/portal/patients/${subject.id}/teeth/${tooth}/history`)).data.data,
    enabled: tooth !== '',
  })

  return (
    <section className="portal-section" aria-labelledby="teeth-title">
      <h2 id="teeth-title">Historial de una pieza</h2>
      <Field label="Pieza" name="portal-tooth" size="lg" hint="Numeración FDI de dos dígitos.">
        <select id="portal-tooth" value={tooth} onChange={(event) => setTooth(event.target.value)}>
          <option value="">Elige una pieza</option>
          {GROUPS.map(([label, teeth]) => (
            <optgroup key={label} label={label}>
              {teeth.map((number) => (
                <option key={number} value={number}>
                  Pieza {number}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      </Field>
      {history.isLoading && (
        <div className="empty" role="status">
          Cargando…
        </div>
      )}
      {history.isError && (
        <div className="alert alert-error" role="alert">
          {generalError(history.error)}
        </div>
      )}
      {history.isSuccess && (
        <div className="card mt-4">
          <ToothHistory tooth={Number(tooth)} entries={history.data} size="lg" />
        </div>
      )}
    </section>
  )
}

/** /c/:slug/portal/mi-historia: «Mi salud» (CUS-17, CUS-24). */
export function PortalHealthPage() {
  return (
    <>
      <PageHeader title="Mi salud" />
      <WithPortalSubject>
        {(subject) => (
          <>
            <ConsentSummary subject={subject} />
            <ToothHistoryPicker subject={subject} />
          </>
        )}
      </WithPortalSubject>
    </>
  )
}
