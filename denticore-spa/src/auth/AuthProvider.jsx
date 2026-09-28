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
    queryFn: async () => {
      const { data } = await apiClient.get('/auth/me')
      return data.data
    },
    enabled: Boolean(getToken()),
    retry: false,
  })

  const [twoFactor, setTwoFactor] = useState(null)

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
    onSuccess: (data) => {
      setToken(data.token)
      setTwoFactor(twoFactorStep(data))
      if (data.user) {
        queryClient.setQueryData(ME_QUERY_KEY, data.user)
      }
    },
  })

  const logoutMutation = useMutation({
    mutationFn: () => apiClient.post('/auth/logout'),
    onSettled: () => {
      clearToken()
      setTwoFactor(null)
      queryClient.clear()
    },
  })

  const value = {
    user: meQuery.isError ? null : (meQuery.data ?? null),
    isLoading: meQuery.isLoading,
    twoFactor,
    login: loginMutation.mutateAsync,
    logout: logoutMutation.mutateAsync,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

function twoFactorStep(loginResponse) {
  if (loginResponse.requires_2fa) return 'pending'
  if (loginResponse.requires_2fa_setup) return 'setup'
  return null
}
