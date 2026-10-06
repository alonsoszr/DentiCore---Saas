import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { mockApi, renderPage } from '../../test/utils'
import { ActivateAccountPage } from './ActivateAccountPage'
import { containsIdentity } from './authHelpers'
import { ForgotPasswordPage } from './ForgotPasswordPage'
import { ResetPasswordPage } from './ResetPasswordPage'

const GOOD_PASSWORD = 'Molar-Sano-2026'

describe('ForgotPasswordPage', () => {
  const route = { route: '/c/clinica-demo/restablecer', path: '/c/:slug/restablecer' }

  it('always shows the same message after a 2xx response (RF-039)', async () => {
    const calls = mockApi(() => ({ status: 204 }))
    renderPage(<ForgotPasswordPage />, route)

    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'nadie@correo.test')
    await userEvent.click(screen.getByRole('button', { name: 'Enviar enlace' }))

    expect(await screen.findByText(/Si el correo está registrado, recibirás un enlace/)).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toEqual({ tenant_slug: 'clinica-demo', email: 'nadie@correo.test' })
  })

  it('checks the email format before sending', async () => {
    const calls = mockApi(() => ({ status: 204 }))
    renderPage(<ForgotPasswordPage />, route)

    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'no-es-correo')
    await userEvent.click(screen.getByRole('button', { name: 'Enviar enlace' }))

    expect(await screen.findByText('Ingresa un correo válido.')).toBeInTheDocument()
    expect(calls.some((call) => call.method === 'post')).toBe(false)
  })
})

describe('ResetPasswordPage', () => {
  const route = { route: '/c/clinica-demo/restablecer/tok123', path: '/c/:slug/restablecer/:token' }

  it('saves the new password with the token and confirms the closed sessions', async () => {
    const calls = mockApi(() => ({ status: 204 }))
    renderPage(<ResetPasswordPage />, route)

    const save = screen.getByRole('button', { name: 'Guardar contraseña' })
    expect(screen.getByText('Requisitos de la contraseña (0/4)')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Nueva contraseña'), GOOD_PASSWORD)
    await userEvent.type(screen.getByLabelText('Confirmar contraseña'), 'otra')
    expect(screen.getByText('Las contraseñas no coinciden')).toBeInTheDocument()
    expect(save).toBeDisabled()

    await userEvent.clear(screen.getByLabelText('Confirmar contraseña'))
    await userEvent.type(screen.getByLabelText('Confirmar contraseña'), GOOD_PASSWORD)
    await userEvent.click(save)

    expect(await screen.findByText(/Tu contraseña se actualizó/)).toBeInTheDocument()
    expect(JSON.parse(calls.find((call) => call.method === 'post').data)).toEqual({
      token: 'tok123',
      password: GOOD_PASSWORD,
      password_confirmation: GOOD_PASSWORD,
    })
  })

  it('marks the rule rejected by the API next to the field and keeps the values', async () => {
    mockApi(() => ({
      status: 422,
      data: { errors: { password: ['La contraseña es demasiado común. Elige otra.'] } },
    }))
    renderPage(<ResetPasswordPage />, route)

    await userEvent.type(screen.getByLabelText('Nueva contraseña'), 'password123')
    await userEvent.type(screen.getByLabelText('Confirmar contraseña'), 'password123')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar contraseña' }))

    expect(await screen.findByText('La contraseña es demasiado común. Elige otra.')).toBeInTheDocument()
    expect(screen.getByText(/No es una contraseña común \(no se cumple\)/)).toBeInTheDocument()
    expect(screen.getByLabelText('Nueva contraseña')).toHaveValue('password123')
  })

  it('explains that an expired or used link is no longer valid', async () => {
    mockApi(() => ({ status: 404, data: {} }))
    renderPage(<ResetPasswordPage />, route)

    await userEvent.type(screen.getByLabelText('Nueva contraseña'), GOOD_PASSWORD)
    await userEvent.type(screen.getByLabelText('Confirmar contraseña'), GOOD_PASSWORD)
    await userEvent.click(screen.getByRole('button', { name: 'Guardar contraseña' }))

    expect(await screen.findByText('Este enlace ya no es válido')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Solicitar un enlace nuevo' })).toHaveAttribute(
      'href',
      '/c/clinica-demo/restablecer',
    )
  })
})

describe('ActivateAccountPage', () => {
  const route = { route: '/c/clinica-demo/activar/inv456', path: '/c/:slug/activar/:token' }
  const invitation = {
    name: 'Rosa Quispe',
    email: 'rosa@clinica-demo.test',
    role: 'clinic_admin',
    clinic: { name: 'Clínica Demo', slug: 'clinica-demo' },
  }

  it('activates the account and goes to the login with the notice (PEND-05)', async () => {
    const calls = mockApi((config) =>
      config.method === 'get' && config.url.startsWith('/auth/invitations')
        ? { data: { data: invitation } }
        : { status: config.method === 'post' ? 204 : 200, data: {} },
    )
    renderPage(<ActivateAccountPage />, route)

    expect(await screen.findByText('Te invitaron a Clínica Demo')).toBeInTheDocument()
    expect(screen.getByText('Administrador de Clínica')).toBeInTheDocument()
    expect(screen.getByText('En el siguiente paso configurarás la verificación en dos pasos.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Crea tu contraseña'), GOOD_PASSWORD)
    await userEvent.type(screen.getByLabelText('Confirmar contraseña'), GOOD_PASSWORD)
    const activate = screen.getByRole('button', { name: 'Activar cuenta' })
    expect(activate).toBeDisabled()
    await userEvent.click(screen.getByLabelText('Acepto los términos de uso y la política de privacidad'))
    await userEvent.click(activate)

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/login')
    expect(JSON.parse(calls.find((call) => call.method === 'post').data)).toEqual({
      password: GOOD_PASSWORD,
      password_confirmation: GOOD_PASSWORD,
    })
  })

  it('checks in the browser that the password does not contain the email or the name', async () => {
    mockApi(() => ({ data: { data: { ...invitation, role: 'dentist' } } }))
    renderPage(<ActivateAccountPage />, route)

    await userEvent.type(await screen.findByLabelText('Crea tu contraseña'), 'QuispeSegura99')

    expect(screen.getByText(/No contiene tu correo ni tu nombre \(no se cumple\)/)).toBeInTheDocument()
    expect(screen.queryByText(/siguiente paso configurarás/)).not.toBeInTheDocument()
    expect(containsIdentity('rosa-2026-segura', invitation)).toBe(true)
    expect(containsIdentity(GOOD_PASSWORD, invitation)).toBe(false)
  })

  it('shows an invalid invitation without telling whether it expired or was used', async () => {
    mockApi(() => ({ status: 404, data: {} }))
    renderPage(<ActivateAccountPage />, route)

    expect(await screen.findByText('Esta invitación ya no es válida')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ir al inicio de sesión' })).toBeInTheDocument()
  })
})
