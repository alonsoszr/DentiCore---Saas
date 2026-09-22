import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from './useAuth'

/**
 * Guard de ruta por rol (technical_specs.md §4.2). Es solo UX: la autorización real
 * la aplica siempre el backend (EnsureRole + Policies).
 */
export function RequireRole({ allow, children, loginPath = '/login', forbiddenPath = '/' }) {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return null
  }

  if (!user) {
    return <Navigate to={loginPath} replace state={{ from: location }} />
  }

  if (!allow.includes(user.role)) {
    return <Navigate to={forbiddenPath} replace />
  }

  return children
}
