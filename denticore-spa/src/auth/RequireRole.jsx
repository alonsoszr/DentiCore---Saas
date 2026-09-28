import { Navigate } from 'react-router-dom'
import { useAuth } from './useAuth'

/**
 * Guardia por rol (SDD §1.10, §3.2 capa 5). Solo oculta navegación: la autorización
 * real la decide siempre el backend (RN-06). Se usa dentro de <RequireAuth>.
 */
export function RequireRole({ allow, forbiddenPath, children }) {
  const { user } = useAuth()

  if (!user || !allow.includes(user.role)) {
    return <Navigate to={forbiddenPath} replace />
  }

  return children
}
