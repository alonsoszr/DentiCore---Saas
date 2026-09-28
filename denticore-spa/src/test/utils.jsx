import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { apiClient } from '../api/client'
import { AuthContext } from '../auth/useAuth'

export const CLINIC = { id: 't-1', name: 'Clínica Demo', slug: 'clinica-demo' }

export function makeUser(role, overrides = {}) {
  return {
    id: `u-${role}`,
    name: `Usuario ${role}`,
    email: `${role}@clinica-demo.test`,
    role,
    is_active: true,
    tenant: role === 'super_admin' ? null : CLINIC,
    ...overrides,
  }
}

/**
 * Sustituye el adaptador HTTP de Axios: `handler(config)` devuelve
 * { status, data } y se registran todas las solicitudes.
 */
export function mockApi(handler) {
  const calls = []
  apiClient.defaults.adapter = async (config) => {
    calls.push(config)
    const { status = 200, data = null } = (await handler(config)) ?? {}
    const response = { data, status, statusText: String(status), headers: {}, config }
    if (status >= 400) {
      const error = new Error(`HTTP ${status}`)
      error.config = config
      error.response = response
      error.isAxiosError = true
      throw error
    }
    return response
  }
  return calls
}

export function renderPage(ui, { user = null, route = '/', path = '*', auth = {} } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const value = {
    user,
    isLoading: false,
    twoFactor: null,
    login: async () => ({ user }),
    logout: async () => {},
    ...auth,
  }
  return render(
    <QueryClientProvider client={queryClient}>
      <AuthContext.Provider value={value}>
        <MemoryRouter initialEntries={[route]}>
          <Routes>
            <Route path={path} element={ui} />
            <Route path="*" element={<LocationProbe />} />
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

/** Muestra la ruta actual para comprobar redirecciones. */
export function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location">{location.pathname}</div>
}
