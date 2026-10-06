import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Field } from '../../components/Field'
import { ConsentForm } from '../../portal/ConsentForm'
import { PURPOSES } from '../../portal/consentPurposes'
import { formatDateTime } from '../../ui/format'

const STATUS_LABELS = { vigente: 'Vigente', sustituido: 'Sustituido', revocado: 'Revocado' }
const CHANNEL_LABELS = { presencial: 'Presencial', portal: 'Portal', papel: 'Papel' }

/**
 * Consentimiento de datos del paciente (CUS-17; RF-065 a RF-067): historial, constancia PDF y
 * registro de uno nuevo con `ConsentForm`. Canal presencial: confirma con el documento del
 * titular o del representante; papel: se adjunta el formulario firmado.
 */
export function ConsentPage({ patient }) {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const [channel, setChannel] = useState('presencial')
  const [document, setDocument] = useState('')
  const [scan, setScan] = useState(null)
  const [registered, setRegistered] = useState(false)
  const [certificate, setCertificate] = useState({})

  const consentsQuery = useQuery({
    queryKey: ['consents', slug, patient.id],
    queryFn: async () => (await apiClient.get(`/patients/${patient.id}/consents`)).data.data,
  })
  const previewQuery = useQuery({
    queryKey: ['consents', slug, patient.id, 'preview'],
    queryFn: async () => (await apiClient.get(`/patients/${patient.id}/consents/preview`)).data.data,
    retry: false,
  })

  const grant = useMutation({
    mutationFn: async (purposes) => {
      if (channel === 'papel') {
        const data = new FormData()
        data.append('channel', 'papel')
        for (const [field, value] of Object.entries(purposes)) data.append(field, value ? '1' : '0')
        if (scan) data.append('scanned_file', scan)
        return (await apiClient.post(`/patients/${patient.id}/consents`, data)).data.data
      }
      return (
        await apiClient.post(`/patients/${patient.id}/consents`, {
          channel,
          ...purposes,
          confirmation_document_number: document,
        })
      ).data.data
    },
    onSuccess: () => {
      setRegistered(true)
      setDocument('')
      queryClient.invalidateQueries({ queryKey: ['consents', slug, patient.id] })
      queryClient.invalidateQueries({ queryKey: ['patients', slug, patient.id] })
    },
  })

  const downloadCertificate = async (consentId) => {
    const { data } = await apiClient.get(`/consents/${consentId}/certificate`)
    setCertificate({ ...certificate, [consentId]: data.data })
    if (data.data.url) window.open(data.data.url, '_blank', 'noopener')
  }

  const errors = fieldErrors(grant.error)
  const preview = previewQuery.data
  const consents = consentsQuery.data ?? []

  return (
    <>
      <div className="card">
        <h2>Consentimientos registrados</h2>
        {consentsQuery.isLoading && <div className="empty">Cargando…</div>}
        {consentsQuery.isSuccess && consents.length === 0 && (
          <div className="empty">El paciente aún no tiene consentimientos.</div>
        )}
        {consents.length > 0 && (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Estado</th>
                  <th>Otorgado por</th>
                  <th>Canal</th>
                  <th>Finalidades</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {consents.map((consent) => (
                  <tr key={consent.id}>
                    <td>{formatDateTime(consent.granted_at)}</td>
                    <td>
                      <span className={consent.status === 'vigente' ? 'badge' : 'badge badge-muted'}>
                        {STATUS_LABELS[consent.status]}
                      </span>
                      {consent.outdated && <span className="muted"> · versión anterior</span>}
                    </td>
                    <td>{consent.granted_by === 'representante' ? 'Representante' : 'Titular'}</td>
                    <td>{CHANNEL_LABELS[consent.channel]}</td>
                    <td>
                      {PURPOSES.filter((purpose) => consent[purpose.field])
                        .map((purpose) => purpose.label.slice(0, 3))
                        .join(' ')}
                    </td>
                    <td>
                      <button type="button" className="btn-link" onClick={() => downloadCertificate(consent.id)}>
                        Constancia
                      </button>
                      {certificate[consent.id] && !certificate[consent.id].url && (
                        <span className="muted"> En preparación; inténtalo en unos segundos.</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="card">
        <h2>Registrar consentimiento</h2>
        {registered && <div className="alert alert-success">Consentimiento registrado.</div>}
        {previewQuery.isLoading && <div className="empty">Cargando…</div>}
        {previewQuery.isError && <div className="alert alert-error">{generalError(previewQuery.error)}</div>}
        {generalError(grant.error) && <div className="alert alert-error">{generalError(grant.error)}</div>}
        {preview && (
          <>
            {preview.granted_by === 'representante' && preview.representative && (
              <p>
                Otorga el representante legal: {preview.representative.first_name} {preview.representative.last_name}.
              </p>
            )}
            <ConsentForm
              key={registered ? 'nuevo' : 'actual'}
              preview={preview}
              errors={errors}
              submitting={grant.isPending}
              onSubmit={(purposes) => {
                setRegistered(false)
                grant.mutate(purposes)
              }}
            >
              <div className="form-grid">
                <Field label="Canal" name="channel" error={errors.channel}>
                  <select id="channel" value={channel} onChange={(event) => setChannel(event.target.value)}>
                    <option value="presencial">Presencial</option>
                    <option value="papel">Formulario en papel</option>
                  </select>
                </Field>
                {channel === 'presencial' ? (
                  <Field
                    label={
                      preview.granted_by === 'representante'
                        ? 'Documento del representante (confirmación)'
                        : 'Documento del titular (confirmación)'
                    }
                    name="confirmation_document_number"
                    value={document}
                    onChange={(event) => setDocument(event.target.value)}
                    error={errors.confirmation_document_number}
                    hint="Lo escribe la persona que otorga el consentimiento."
                    autoComplete="off"
                  />
                ) : (
                  <Field
                    label="Formulario firmado (PDF o imagen, máximo 10 MB)"
                    name="scanned_file"
                    type="file"
                    accept="application/pdf,image/png,image/jpeg"
                    onChange={(event) => setScan(event.target.files?.[0] ?? null)}
                    error={errors.scanned_file}
                  />
                )}
              </div>
            </ConsentForm>
          </>
        )}
      </div>
    </>
  )
}
