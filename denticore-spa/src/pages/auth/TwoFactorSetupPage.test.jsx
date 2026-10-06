import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { groupKey } from './authHelpers'
import { TwoFactorSetupPage } from './TwoFactorSetupPage'

vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(async () => 'data:image/png;base64,QR') } }))

const ROUTE = { route: '/c/clinica-demo/app/seguridad/2fa', path: '/c/:slug/app/seguridad/2fa' }
const ADMIN = makeUser('clinic_admin')
const CODES = Array.from({ length: 10 }, (_, index) => `C0D${index}-XXXX`)

function api(confirmResponse) {
  return mockApi((config) => {
    if (config.url === '/auth/2fa/setup') {
      return { data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_url: 'otpauth://totp/DentiCore:a?secret=JBSWY3DPEHPK3PXP' } }
    }
    if (config.url === '/auth/2fa/confirm') return confirmResponse()
    return { data: {} }
  })
}

describe('TwoFactorSetupPage', () => {
  it('guides through scan, confirmation and recovery codes and then enters the app', async () => {
    const completeSession = vi.fn()
    const calls = api(() => ({ data: { token: 'full', user: ADMIN, recovery_codes: CODES } }))
    renderPage(<TwoFactorSetupPage />, { ...ROUTE, user: ADMIN, auth: { twoFactor: 'setup', completeSession } })

    expect(screen.getByText('Administrador de Clínica')).toBeInTheDocument()
    expect(await screen.findByAltText('Código QR para tu aplicación de autenticación')).toBeInTheDocument()
    expect(screen.getByTestId('manual-key')).toHaveTextContent('JBSW Y3DP EHPK 3PXP')

    await userEvent.click(screen.getByRole('button', { name: 'Continuar a confirmación' }))
    await userEvent.type(screen.getByLabelText('Dígito 1 de 6'), '123456')
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    expect(await screen.findByText('Guarda tus códigos de recuperación')).toBeInTheDocument()
    expect(JSON.parse(calls.find((call) => call.url === '/auth/2fa/confirm').data)).toEqual({ code: '123456' })
    expect(completeSession).toHaveBeenCalled()
    expect(screen.getByText('01. C0D0-XXXX')).toBeInTheDocument()
    expect(screen.getByText('10. C0D9-XXXX')).toBeInTheDocument()

    const finish = screen.getByRole('button', { name: 'Finalizar' })
    expect(finish).toBeDisabled()
    await userEvent.click(screen.getByLabelText('Guardé mis códigos de recuperación'))
    await userEvent.click(finish)

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/pacientes')
  })

  it('shows the incorrect code and empties the boxes', async () => {
    api(() => ({ status: 422, data: { errors: { code: ['El código no es válido.'] } } }))
    renderPage(<TwoFactorSetupPage />, { ...ROUTE, user: ADMIN, auth: { twoFactor: 'setup' } })

    await userEvent.click(await screen.findByRole('button', { name: 'Continuar a confirmación' }))
    await userEvent.type(screen.getByLabelText('Dígito 1 de 6'), '000000')
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    expect(await screen.findByText(/Código incorrecto/)).toBeInTheDocument()
    expect(screen.getByLabelText('Dígito 1 de 6')).toHaveValue('')
    await userEvent.click(screen.getByRole('button', { name: 'Atrás' }))
    expect(screen.getByRole('heading', { name: 'Configuración del segundo factor' })).toBeInTheDocument()
  })

  it('groups the manual key by 4 characters', () => {
    expect(groupKey('ABCDEFGHIJ')).toBe('ABCD EFGH IJ')
  })
})
