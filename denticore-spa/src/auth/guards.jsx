import { Navigate, useLocation, useParams } from 'react-router-dom'
import { homePathFor, loginPathFor } from './paths'
import { useAuth } from './useAuth'

/**
 * Cadena de guardias de SDD §1.10: <RequireAuth> → <RequireTwoFactor> → <RequireRole>
 * → <RequireFeature>. Son solo de interfaz; el backend decide (RN-06).
 */

/** Exige sesión y que la clínica de la URL sea la del usuario. */
export function RequireAuth({ children }) {
  const { user, isLoading } = useAuth()
  const { slug } = useParams()
  const location = useLocation()

  if (isLoading) {
    return null
  }

  if (!user) {
    return <Navigate to={loginPathFor(slug)} replace state={{ from: location }} />
  }

  const userSlug = user.tenant?.slug ?? null
  if ((slug ?? null) !== userSlug) {
    return <Navigate to={homePathFor(user)} replace />
  }

  return children
}

/**
 * Mientras el login deja pendiente el segundo factor (SDD §1.8, §3.6), solo se permiten
 * las pantallas de verificación (/c/:slug/login/2fa) o de configuración (…/seguridad/2fa).
 */
export function RequireTwoFactor({ children }) {
  const { twoFactor } = useAuth()
  const { slug } = useParams()
  const location = useLocation()

  if (twoFactor === 'pending') {
    return <Navigate to={`${loginPathFor(slug)}/2fa`} replace state={{ from: location }} />
  }

  if (twoFactor === 'setup') {
    const securityPath = location.pathname.replace(/^(\/admin|\/c\/[^/]+\/(?:app|portal)).*$/, '$1/seguridad/2fa')
    if (location.pathname !== securityPath) {
      return <Navigate to={securityPath} replace />
    }
  }

  return children
}

/**
 * Oculta áreas de funciones que el plan de la clínica no incluye (ai, risk, analytics;
 * DD-16). Lee `user.tenant.features`, que la API expondrá con el plan (TASK-021/024);
 * mientras no exista, la función se considera no incluida.
 */
export function RequireFeature({ feature, fallbackPath, children }) {
  const { user } = useAuth()

  if (user?.tenant?.features?.[feature] !== true) {
    return <Navigate to={fallbackPath} replace />
  }

  return children
}
