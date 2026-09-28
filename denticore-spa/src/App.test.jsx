import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import App from './App'
import { AuthProvider } from './auth/AuthProvider'
import { getToken, setToken } from './auth/token'
import { CLINIC, makeUser, mockApi } from './test/utils'

function renderApp(route) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const patientsResponse = { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }

describe('App', () => {
  it('logs a receptionist in through the clinic login and opens the staff area', async () => {
    mockApi((config) => {
      if (config.url === '/auth/login') return { data: { token: '1|abc', user: makeUser('receptionist') } }
      if (config.url === '/auth/me') return { data: { data: makeUser('receptionist') } }
      return patientsResponse
    })
    renderApp('/c/clinica-demo/login')

    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'recepcion@clinica-demo.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'password')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(await screen.findByRole('heading', { name: 'Pacientes' }, { timeout: 5000 })).toBeInTheDocument()
    expect(getToken()).toBe('1|abc')
    expect(screen.queryByRole('link', { name: 'Usuarios' })).not.toBeInTheDocument()
    expect(screen.getByText('Clínica Demo')).toBeInTheDocument()
  })

  it('restores the session and redirects a receptionist away from admin only screens', async () => {
    setToken('1|abc')
    mockApi((config) => (config.url === '/auth/me' ? { data: { data: makeUser('receptionist') } } : patientsResponse))
    renderApp('/c/clinica-demo/app/usuarios')

    expect(await screen.findByRole('heading', { name: 'Acceso no permitido' }, { timeout: 5000 })).toBeInTheDocument()
  })

  it('keeps each role inside its own area', async () => {
    setToken('1|abc')
    mockApi((config) =>
      config.url === '/auth/me'
        ? { data: { data: makeUser('patient', { patient_uuid: null }) } }
        : { data: { data: null } },
    )
    renderApp('/admin/clinicas')

    expect(await screen.findByText(/Tu cuenta aún no está vinculada/, {}, { timeout: 5000 })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Mi ficha' })).toHaveAttribute('href', `/c/${CLINIC.slug}/portal`)
  })

  it('sends the visitor to the login and back to the clinic login after the session expires', async () => {
    setToken('1|vencido')
    mockApi(() => ({ status: 401, data: {} }))
    renderApp('/c/clinica-demo/app/pacientes')

    expect(await screen.findByRole('button', { name: 'Ingresar' })).toBeInTheDocument()
    expect(screen.getByText('clinica-demo')).toBeInTheDocument()
    expect(getToken()).toBeNull()
  })

  it('logs the super admin out to the platform login', async () => {
    setToken('1|abc')
    mockApi((config) => {
      if (config.url === '/auth/me') return { data: { data: makeUser('super_admin') } }
      if (config.url === '/auth/logout') return { status: 204 }
      return { data: { data: [] } }
    })
    renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Clínicas' }, { timeout: 5000 })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }))

    expect(await screen.findByText('Acceso de administración de la plataforma.')).toBeInTheDocument()
    expect(getToken()).toBeNull()
  })

  it('shows not found for unknown routes', () => {
    renderApp('/no-existe')

    expect(screen.getByRole('heading', { name: 'Página no encontrada' })).toBeInTheDocument()
  })
})
