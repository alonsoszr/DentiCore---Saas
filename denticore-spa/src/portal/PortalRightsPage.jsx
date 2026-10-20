import { Link } from 'react-router-dom'
import { useClinic } from '../auth/useClinic'
import { PageHeader } from '../components/PageHeader'
import { ArcoRequests } from '../pages/compliance/ArcoRequests'
import { ClinicalRecordCopy } from '../pages/compliance/ClinicalRecordCopy'
import { subjectName } from './portalQueries'
import { WithPortalSubject } from './WithPortalSubject'

export function PortalRightsPage({ documents = false }) {
  const { portalPath } = useClinic()
  return (
    <>
      <Link to={portalPath('/mi-historia')} className="portal-back">
        Volver a Mi salud
      </Link>
      <PageHeader title={documents ? 'Mis documentos' : 'Solicitudes ARCO'} />
      <WithPortalSubject>
        {(subject) => (
          <>
            <p>Datos de {subjectName(subject)}.</p>
            {documents ? (
              <ClinicalRecordCopy key={subject.id} patientId={subject.id} portal />
            ) : (
              <ArcoRequests key={subject.id} patientId={subject.id} portal />
            )}
          </>
        )}
      </WithPortalSubject>
    </>
  )
}
