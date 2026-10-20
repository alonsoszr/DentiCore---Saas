import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { patientRightsBase } from './rights'

/** CUS-62: no simula el PDF listo; su estado y URL firmada siempre vienen del servidor. */
export function ClinicalRecordCopy({ patientId, portal = false, documentId = null }) {
  const { slug } = useClinic()
  const [requested, setRequested] = useState(null)
  const id = documentId ?? requested?.id
  const request = useMutation({
    mutationFn: async () =>
      (await apiClient.post(`${patientRightsBase(patientId, portal)}/clinical-record-copies`)).data.data,
    onSuccess: (data) => setRequested(data),
  })
  const status = useQuery({
    queryKey: ['rights-document', slug, portal, patientId, id],
    queryFn: async () => (await apiClient.get(`${portal ? '/portal' : ''}/documents/${id}`)).data.data,
    enabled: Boolean(id),
    retry: false,
  })
  const document = status.data ?? requested
  const preparing = document && ['pendiente', 'generando'].includes(document.status)

  return (
    <section className="card">
      <h2>{documentId ? 'Documento de respuesta' : 'Copia de la historia clínica'}</h2>
      {!documentId && <p>Solicita una copia de la historia clínica. El documento se prepara en segundo plano.</p>}
      {(request.isError || status.isError) && <Alert tone="error">{generalError(request.error ?? status.error)}</Alert>}
      {preparing && <p role="status">En preparación.</p>}
      {document?.status === 'fallido' && (
        <Alert tone="error">No se pudo generar el documento. Puedes solicitar otra copia.</Alert>
      )}
      {document?.status === 'listo' && document.url && (
        <a className="btn" href={document.url} target="_blank" rel="noopener noreferrer">
          {documentId ? 'Descargar documento' : 'Descargar copia'}
        </a>
      )}
      <div className="form-actions">
        {!documentId && (
          <button
            className="btn btn-secondary"
            type="button"
            onClick={() => request.mutate()}
            disabled={request.isPending || preparing || status.isFetching}
          >
            {request.isPending ? 'Solicitando…' : 'Solicitar copia de HC'}
          </button>
        )}
        {id && (
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => status.refetch()}
            disabled={status.isFetching}
          >
            {document?.status === 'listo' ? 'Actualizar enlace' : 'Actualizar estado'}
          </button>
        )}
      </div>
      {document?.status === 'listo' && <p className="muted">El enlace de descarga tiene una validez de 10 minutos.</p>}
    </section>
  )
}
