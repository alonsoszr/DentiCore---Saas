import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../api/client'
import { clearToken, getToken, setToken } from './token'
import { AuthContext } from './useAuth'

const ME_QUERY_KEY = ['auth', 'me']

export function AuthProvider({ children }) {
  const queryClient = useQueryClient()

  const meQuery = useQuery({
    queryKey: ME_QUERY_KEY,
    queryFn: async () => {
      const { data } = await apiClient.get('/auth/me')
      return data.data
    },
    enabled: Boolean(getToken()),
    retry: false,
  })

  const loginMutation = useMutation({
    mutationFn: async ({ tenantSlug, email, password }) => {
      const { data } = await apiClient.post('/auth/login', {
        tenant_slug: tenantSlug || undefined,
        email,
        password,
      })
      return data
    },
    onSuccess: ({ token, user }) => {
      setToken(token)
      queryClient.setQueryData(ME_QUERY_KEY, user)
    },
  })

  const logoutMutation = useMutation({
    mutationFn: () => apiClient.post('/auth/logout'),
    onSettled: () => {
      clearToken()
      queryClient.clear()
    },
  })

  const value = {
    user: meQuery.isError ? null : (meQuery.data ?? null),
    isLoading: meQuery.isLoading,
    login: loginMutation.mutateAsync,
    logout: logoutMutation.mutateAsync,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
