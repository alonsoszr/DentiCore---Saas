import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../test/utils'
import { ClinicSettingsPage } from './ClinicSettingsPage'

const ROUTE = {
  user: makeUser('clinic_admin'),
  route: '/c/clinica-demo/app/configuracion',
  path: '/c/:slug/app/configuracion',
}

const SETTINGS = {
  id: 's-1',
  name: 'Clínica Demo',
  address: 'Av. Arequipa 1234, Lima',
  phone: null,
  contact_email: 'contacto@clinica-demo.test',
  logo: null,
  prices_include_igv: true,
  discount_cap_pct: '10.00',
  budget_validity_days: 30,
  portal_cancel_hours: 24,
  self_booking_enabled: false,
  ai_enabled: false,
  budget_terms: null,
}

describe('ClinicSettingsPage', () => {
  it('saves the clinic parameters (RF-024 to RF-026)', async () => {
    const calls = mockApi((config) =>
      config.method === 'patch'
        ? { data: { data: { ...SETTINGS, budget_validity_days: 45 } } }
        : { data: { data: SETTINGS } },
    )
    renderPage(<ClinicSettingsPage />, ROUTE)

    const validity = await screen.findByLabelText('Vigencia del presupuesto (días)')
    expect(validity).toHaveValue(30)
    await userEvent.clear(validity)
    await userEvent.type(validity, '45')
    await userEvent.click(screen.getByLabelText('Permitir que el paciente reserve citas desde el portal'))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }))

    expect(await screen.findByText('Parámetros guardados.')).toBeInTheDocument()
    const patch = calls.find((call) => call.method === 'patch')
    expect(JSON.parse(patch.data)).toMatchObject({
      budget_validity_days: 45,
      self_booking_enabled: true,
      phone: null,
      budget_terms: null,
    })
  })

  it('shows the validation errors next to each field and keeps the values', async () => {
    mockApi((config) =>
      config.method === 'patch'
        ? { status: 422, data: { errors: { discount_cap_pct: ['El tope de descuento no debe ser mayor que 100.'] } } }
        : { data: { data: SETTINGS } },
    )
    renderPage(<ClinicSettingsPage />, ROUTE)

    const cap = await screen.findByLabelText('Tope de descuento (%)')
    await userEvent.clear(cap)
    await userEvent.type(cap, '150')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }))

    expect(await screen.findByText('El tope de descuento no debe ser mayor que 100.')).toBeInTheDocument()
    expect(cap).toHaveValue(150)
  })

  it('uploads the logo and shows that it is under review', async () => {
    const calls = mockApi((config) =>
      config.method === 'post'
        ? { data: { data: { ...SETTINGS, logo: { id: 'f-1', status: 'pendiente', url: null } } } }
        : { data: { data: SETTINGS } },
    )
    renderPage(<ClinicSettingsPage />, ROUTE)

    const file = new File(['png'], 'logo.png', { type: 'image/png' })
    await userEvent.upload(await screen.findByLabelText('Logotipo (PNG o JPG, máximo 1 MB)'), file)
    await userEvent.click(screen.getByRole('button', { name: 'Subir logotipo' }))

    expect(await screen.findByText(/en revisión antivirus/)).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(post.url).toBe('/clinic/logo')
    expect(post.data.get('logo').name).toBe('logo.png')
  })
})
