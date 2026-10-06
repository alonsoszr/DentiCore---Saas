import { act, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../test/utils'
import { inactivityLimit } from '../auth/inactivity'
import { SessionTimeout } from './SessionTimeout'

const ROUTE = { route: '/c/clinica-demo/app/pacientes', path: '/c/:slug/app/pacientes' }

// Cada prueba empieza un día después de la hora real: la última actividad de la API es estado
// del módulo (se fija con la hora real al importarlo) y no debe quedar en el futuro. Una fecha
// fija deja de servir en cuanto la hora real la supera.
let day = 0
beforeEach(() => {
  const realNow = Date.now()
  vi.useFakeTimers({ shouldAdvanceTime: true })
  day += 1
  vi.setSystemTime(realNow + day * 24 * 60 * 60_000)
})
afterEach(() => vi.useRealTimers())

async function advance(minutes) {
  await act(async () => {
    vi.advanceTimersByTime(minutes * 60_000)
  })
}

describe('SessionTimeout', () => {
  it('warns 2 minutes before the inactivity timeout', async () => {
    const calls = mockApi(() => ({ status: 204 }))
    renderPage(<SessionTimeout />, { ...ROUTE, user: makeUser('receptionist') })

    await advance(27.5)
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()

    await advance(0.75)
    expect(screen.getByRole('alertdialog')).toHaveTextContent('se cerrará en 2 minutos')

    await act(async () => screen.getByRole('button', { name: 'Continuar sesión' }).click())
    expect(calls.some((call) => call.url === '/auth/keepalive')).toBe(true)
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('logs out and returns to the login when the session expires', async () => {
    mockApi(() => ({ status: 204 }))
    const logout = vi.fn(async () => {})
    renderPage(<SessionTimeout />, { ...ROUTE, user: makeUser('dentist'), auth: { logout } })

    await advance(30.5)

    expect(logout).toHaveBeenCalled()
    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/login')
  })

  it('uses 15 minutes for the patient portal', () => {
    expect(inactivityLimit('patient')).toBe(15 * 60_000)
    expect(inactivityLimit('clinic_admin')).toBe(30 * 60_000)
  })
})
