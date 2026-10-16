import { generalError } from '../api/errors'
import { usePortalSubject } from './portalQueries'

/**
 * Pinta `children(subject)` cuando el sujeto está listo; mientras tanto, la carga, el error o el
 * aviso de una cuenta sin ficha vinculada.
 */
export function WithPortalSubject({ children }) {
  const subjects = usePortalSubject()

  if (subjects.isLoading) {
    return (
      <div className="empty" role="status">
        Cargando…
      </div>
    )
  }
  if (subjects.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {generalError(subjects.error)}
      </div>
    )
  }
  if (!subjects.subject) {
    return (
      <div className="card">
        <p className="m-0">
          Tu cuenta aún no está vinculada a una ficha de paciente. Consulta con la recepción de tu clínica.
        </p>
      </div>
    )
  }

  return children(subjects.subject)
}
