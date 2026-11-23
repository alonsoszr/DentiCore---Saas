import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { ProfilePage } from './ProfilePage'

it('actualiza el nombre de la cuenta propia', async () => {
  const user = makeUser('receptionist')
  const calls = mockApi(() => ({ data: { data: { ...user, name: 'Rosa Pérez' } } }))
  renderPage(<ProfilePage />, { user })
  await userEvent.clear(screen.getByLabelText('Nombre'))
  await userEvent.type(screen.getByLabelText('Nombre'), 'Rosa Pérez')
  await userEvent.click(screen.getByRole('button', { name: 'Guardar nombre' }))
  await waitFor(() => expect(calls.at(-1)).toMatchObject({ url: '/me/profile', method: 'patch' }))
  expect(await screen.findByText('Nombre actualizado.')).toBeInTheDocument()
})

it('exige confirmación y código para regenerar y no ofrece desactivar a un administrador', async () => {
  const calls = mockApi(() => ({ data: { recovery_codes: ['codigo-sintetico-1'] } }))
  renderPage(<ProfilePage />, { user: makeUser('clinic_admin') })
  expect(screen.queryByRole('button', { name: 'Desactivar segundo factor' })).not.toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Regenerar códigos de recuperación' }))
  const dialog = screen.getByRole('dialog')
  const confirm = within(dialog).getByRole('button', { name: 'Confirmar' })
  expect(confirm).toBeDisabled()
  await userEvent.type(within(dialog).getByLabelText('Código de la aplicación'), '123456')
  await userEvent.click(confirm)
  expect(await screen.findByText('codigo-sintetico-1')).toBeInTheDocument()
  expect(calls.at(-1)).toMatchObject({
    url: '/auth/2fa/recovery-codes',
    data: JSON.stringify({ code: '123456' }),
  })
})
