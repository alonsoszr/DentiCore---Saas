import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocation, useNavigate } from 'react-router-dom'
import { apiClient, onUnauthorized } from '../api/client'
import { loginPathFor, slugFromPathname } from './paths'
import { clearToken, getToken, setToken } from './token'
import { AuthContext } from './useAuth'

const ME_QUERY_KEY = ['auth', 'me']

/**
 * Estado de la sesión. `twoFactor` refleja el paso pendiente del login de SDD §1.8
 * ('pending' = verificar TOTP, 'setup' = configurarlo) y lo consume RequireTwoFactor.
 */
export function AuthProvider({ children }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const pathnameRef = useRef(location.pathname)

  const meQuery = useQuery({
    queryKey: ME_QUERY_KEY,
    queryFn: fetchMe,
    enabled: Boolean(getToken()),
    retry: false,
  })

  const [twoFactor, setTwoFactor] = useState(null)
  // A3 muestra el correo del login: la API no devuelve el usuario antes del 2FA.
  const [pendingEmail, setPendingEmail] = useState(null)

  useEffect(() => {
    pathnameRef.current = location.pathname
  }, [location.pathname])

  useEffect(() => {
    onUnauthorized(() => {
      const slug = slugFromPathname(pathnameRef.current)
      queryClient.clear()
      navigate(loginPathFor(slug), { replace: true })
    })
    return () => onUnauthorized(null)
  }, [navigate, queryClient])

  const loginMutation = useMutation({
    mutationFn: async ({ tenantSlug, email, password }) => {
      const { data } = await apiClient.post('/auth/login', {
        tenant_slug: tenantSlug || undefined,
        email,
        password,
      })
      return data
    },
    onSuccess: async (data, { email }) => {
      setToken(data.token)
      setTwoFactor(twoFactorStep(data))
      setPendingEmail(data.requires_2fa ? email : null)
      if (data.user) {
        queryClient.setQueryData(ME_QUERY_KEY, data.user)
      } else if (data.requires_2fa_setup) {
        // Con `2fa:setup` la API permite GET /auth/me (A4 muestra la tarjeta del usuario).
        await queryClient.fetchQuery({ queryKey: ME_QUERY_KEY, queryFn: fetchMe })
      }
    },
  })

  const logoutMutation = useMutation({
    mutationFn: () => apiClient.post('/auth/logout'),
    onSettled: () => {
      clearToken()
      setTwoFactor(null)
      setPendingEmail(null)
      queryClient.clear()
    },
  })

  /** Respuesta de 2fa/verify o 2fa/confirm: token `full` y usuario (SDD §1.8; PEND-04). */
  const completeSession = (data) => {
    setToken(data.token)
    setTwoFactor(null)
    setPendingEmail(null)
    queryClient.setQueryData(ME_QUERY_KEY, data.user)
  }

  const value = {
    user: meQuery.isError ? null : (meQuery.data ?? null),
    isLoading: meQuery.isLoading,
    twoFactor,
    pendingEmail,
    completeSession,
    login: loginMutation.mutateAsync,
    logout: logoutMutation.mutateAsync,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

async function fetchMe() {
  const { data } = await apiClient.get('/auth/me')
  return data.data
}

function twoFactorStep(loginResponse) {
  if (loginResponse.requires_2fa) return 'pending'
  if (loginResponse.requires_2fa_setup) return 'setup'
  return null
}
