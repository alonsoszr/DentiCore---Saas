import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { apiClient, lastApiActivity } from '../api/client'
import { loginPathFor } from '../auth/paths'
import { useAuth } from '../auth/useAuth'
import { Alert } from '../components/Alert'
import { inactivityLimit } from '../auth/inactivity'

const WARNING_BEFORE = 2 * 60_000
const CHECK_EVERY = 15_000

/**
 * Aviso de vencimiento por inactividad (RNF-065; T-168): 2 minutos antes (a los 28 o 13
 * minutos) ofrece continuar, que renueva la sesión con POST /auth/keepalive. Al vencer
 * cierra la sesión y vuelve al login.
 */
export function SessionTimeout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [state, setState] = useState('active')
  // La sesión empieza, como mínimo, al montarse el área autenticada.
  const [startedAt] = useState(() => Date.now())

  const limit = inactivityLimit(user?.role)

  useEffect(() => {
    if (!user) return undefined
    const check = () => {
      const idle = Date.now() - Math.max(lastApiActivity(), startedAt)
      if (idle >= limit) {
        setState('expired')
      } else if (idle >= limit - WARNING_BEFORE) {
        setState('warning')
      }
    }
    const timer = setInterval(check, CHECK_EVERY)
    return () => clearInterval(timer)
  }, [user, limit, startedAt])

  useEffect(() => {
    if (state !== 'expired') return
    const loginPath = loginPathFor(user?.tenant?.slug)
    logout()
      .catch(() => {})
      .finally(() =>
        navigate(loginPath, { replace: true, state: { notice: 'Tu sesión expiró. Inicia sesión de nuevo.' } }),
      )
  }, [state, user, logout, navigate])

  if (state !== 'warning') return null

  const continueSession = async () => {
    await apiClient.post('/auth/keepalive').catch(() => {})
    setState('active')
  }

  return (
    <div role="alertdialog" aria-labelledby="session-timeout-title" className="card session-timeout">
      <Alert tone="warning" title="Tu sesión está por cerrarse">
        <span id="session-timeout-title">Por inactividad, tu sesión se cerrará en 2 minutos.</span>
      </Alert>
      <div className="form-actions">
        <button type="button" className="btn" onClick={continueSession}>
          Continuar sesión
        </button>
        <button type="button" className="btn btn-secondary" onClick={() => setState('expired')}>
          Cerrar sesión
        </button>
      </div>
    </div>
  )
}
