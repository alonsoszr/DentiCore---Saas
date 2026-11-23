import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { AccessRecoveryActions, ClinicCancellation } from './AdminActions'

it('restablece el segundo factor del usuario de la clínica tras confirmar', async () => {
  const calls = mockApi(() => ({ data: { data: {} } }))
  renderPage(<AccessRecoveryActions user={{ id: 'u-2', name: 'Rosa' }} />, { user: makeUser('clinic_admin') })
  await userEvent.click(screen.getByRole('button', { name: 'Restablecer segundo factor' }))
  expect(calls).toHaveLength(0)
  await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirmar' }))
  expect(await screen.findByText('Segundo factor restablecido.')).toBeInTheDocument()
  expect(calls[0]).toMatchObject({ url: '/users/u-2/2fa/reset', method: 'post' })
})

it('omite la solicitud de cancelación hasta ingresar el motivo y confirmar', async () => {
  const calls = mockApi(() => ({ data: { data: { status: 'cancelada' } } }))
  const updated = vi.fn()
  renderPage(<ClinicCancellation tenant={{ id: 'clinica-1', name: 'Prueba' }} onUpdated={updated} />, {
    user: makeUser('super_admin'),
  })
  await userEvent.click(screen.getByRole('button', { name: 'Cancelar clínica' }))
  const dialog = within(screen.getByRole('dialog'))
  const confirm = dialog.getByRole('button', { name: 'Confirmar cancelación' })
  expect(confirm).toBeDisabled()
  await userEvent.type(dialog.getByLabelText('Motivo de la cancelación'), 'Cierre de operación')
  expect(calls).toHaveLength(0)
  await userEvent.click(confirm)
  expect(await screen.findByText('Clínica cancelada.')).toBeInTheDocument()
  expect(calls[0]).toMatchObject({
    url: '/platform/tenants/clinica-1/cancel',
    data: JSON.stringify({ reason: 'Cierre de operación' }),
  })
  expect(updated).toHaveBeenCalledWith({ status: 'cancelada' })
})
