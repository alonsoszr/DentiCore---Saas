import { useParams } from 'react-router-dom'
import { appPath, portalPath } from './paths'

/** Código de la clínica de la URL y constructores de rutas dentro de ella (DD-29). */
export function useClinic() {
  const { slug } = useParams()
  return {
    slug,
    appPath: (path = '') => appPath(slug, path),
    portalPath: (path = '') => portalPath(slug, path),
  }
}
