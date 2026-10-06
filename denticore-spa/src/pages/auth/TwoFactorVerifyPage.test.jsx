import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { maskEmail } from './authHelpers'
import { TwoFactorVerifyPage } from './TwoFactorVerifyPage'

const ROUTE = { route: '/c/clinica-demo/login/2fa', path: '/c/:slug/login/2fa' }

function render(auth = {}) {
  const completeSession = vi.fn()
  renderPage(<TwoFactorVerifyPage />, {
    ...ROUTE,
    auth: { twoFactor: 'pending', pendingEmail: 'odontologo@clinica-demo.test', completeSession, ...auth },
  })
  return { completeSession }
}

describe('TwoFactorVerifyPage', () => {
  it('sends the 6 digits as soon as they are complete and enters the app', async () => {
    const user = makeUser('dentist')
    const calls = mockApi((config) =>
      config.url === '/auth/2fa/verify' ? { data: { token: 'full-token', user } } : { data: {} },
    )
    const { completeSession } = render()

    expect(screen.getByText('Ingresaste como odon•••@clinica-demo.test')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Dígito 1 de 6'), '123456')

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/pacientes')
    expect(JSON.parse(calls.find((call) => call.url === '/auth/2fa/verify').data)).toEqual({ code: '123456' })
    expect(completeSession).toHaveBeenCalledWith({ token: 'full-token', user })
  })

  it('accepts pasting the whole code into the first box', async () => {
    const calls = mockApi(() => ({ data: { token: 't', user: makeUser('dentist') } }))
    render()

    await userEvent.click(screen.getByLabelText('Dígito 1 de 6'))
    await userEvent.paste('654321')

    await screen.findByTestId('location')
    expect(JSON.parse(calls.find((call) => call.url === '/auth/2fa/verify').data)).toEqual({ code: '654321' })
  })

  it('clears the boxes after an incorrect code', async () => {
    mockApi(() => ({ status: 422, data: { errors: { code: ['Código incorrecto.'] } } }))
    render()

    await userEvent.type(screen.getByLabelText('Dígito 1 de 6'), '111111')

    expect(await screen.findByText('Código incorrecto.')).toBeInTheDocument()
    expect(screen.getByLabelText('Dígito 1 de 6')).toHaveValue('')
  })

  it('locks the form with a countdown after too many attempts', async () => {
    mockApi(() => ({ status: 429, data: {}, headers: {} }))
    render()

    await userEvent.type(screen.getByLabelText('Dígito 1 de 6'), '111111')

    expect(await screen.findByText(/Demasiados intentos\. Espera 01:00/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Verificar e ingresar' })).toBeDisabled()
  })

  it('verifies with a recovery code', async () => {
    const calls = mockApi(() => ({ data: { token: 't', user: makeUser('clinic_admin') } }))
    render()

    await userEvent.click(screen.getByRole('button', { name: /Usar código de recuperación/ }))
    await userEvent.type(screen.getByLabelText('Código de recuperación'), 'ABCD-EFGH')
    await userEvent.click(screen.getByRole('button', { name: 'Verificar código de recuperación' }))

    await screen.findByTestId('location')
    expect(JSON.parse(calls.find((call) => call.url === '/auth/2fa/verify').data)).toEqual({
      recovery_code: 'ABCD-EFGH',
    })
  })

  it('returns to the login when the pending token expired', async () => {
    mockApi(() => ({ status: 401, data: {} }))
    render()

    await userEvent.type(screen.getByLabelText('Dígito 1 de 6'), '123456')

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/login')
  })

  it('is only reachable with a pending second factor', async () => {
    render({ twoFactor: null })

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/login')
  })

  it('masks the email of the login', () => {
    expect(maskEmail('d.alvarez@sonrisaandina.pe')).toBe('d.al•••@sonrisaandina.pe')
    expect(maskEmail('')).toBe('')
  })
})
