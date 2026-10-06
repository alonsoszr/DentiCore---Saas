import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { PatientCreatePage } from './PatientCreatePage'
import { isMinor } from './patientIdentity'

const ROUTE = { route: '/c/clinica-demo/app/pacientes/nuevo', path: '/c/:slug/app/pacientes/nuevo' }

describe('PatientCreatePage', () => {
  it('sends the identification of SDD §4.5 and opens the new record', async () => {
    const calls = mockApi(() => ({ status: 201, data: { data: { id: 'p-9' } } }))
    renderPage(<PatientCreatePage />, { user: makeUser('clinic_admin'), ...ROUTE })

    await userEvent.selectOptions(screen.getByLabelText('Tipo de documento'), 'ce')
    await userEvent.type(screen.getByLabelText('Número de documento'), '001234567')
    await userEvent.type(screen.getByLabelText('Nombres'), 'Ana')
    await userEvent.type(screen.getByLabelText('Apellidos'), 'Núñez')
    await userEvent.type(screen.getByLabelText('Fecha de nacimiento'), '1990-01-31')
    await userEvent.selectOptions(screen.getByLabelText('Sexo'), 'femenino')
    await userEvent.type(screen.getByLabelText('Teléfono'), '987654321')
    expect(screen.queryByText(/Representante legal/)).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Registrar paciente' }))

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/pacientes/p-9')
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toEqual({
      document_type: 'ce',
      document_number: '001234567',
      first_name: 'Ana',
      last_name: 'Núñez',
      birth_date: '1990-01-31',
      sex: 'femenino',
      phone: '987654321',
      email: null,
      address: null,
    })
  })

  it('asks for the legal representative of a minor and sends it', async () => {
    const calls = mockApi(() => ({ status: 201, data: { data: { id: 'p-9' } } }))
    renderPage(<PatientCreatePage />, { user: makeUser('receptionist'), ...ROUTE })
    const minorBirthDate = `${new Date().getFullYear() - 10}-01-15`

    await userEvent.type(screen.getByLabelText('Fecha de nacimiento'), minorBirthDate)
    expect(screen.getByText('Representante legal (paciente menor de 18 años)')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Número de documento del representante'), '41234567')
    await userEvent.type(screen.getByLabelText('Nombres del representante'), 'Rosa')
    await userEvent.type(screen.getByLabelText('Apellidos del representante'), 'Mamani')
    await userEvent.type(screen.getByLabelText('Teléfono del representante'), '912345678')
    await userEvent.click(screen.getByRole('button', { name: 'Registrar paciente' }))

    await screen.findByTestId('location')
    const payload = JSON.parse(calls.find((call) => call.method === 'post').data)
    expect(payload.representative).toMatchObject({
      document_type: 'dni',
      document_number: '41234567',
      first_name: 'Rosa',
      relationship: 'madre',
      phone: '912345678',
      email: null,
    })
    expect(payload.representative.valid_from).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('links to the existing record when the document is already registered', async () => {
    mockApi(() => ({
      status: 422,
      data: {
        existing_patient_id: 'p-1',
        errors: { document_number: ['El documento ya está registrado en la clínica.'] },
      },
    }))
    renderPage(<PatientCreatePage />, { user: makeUser('receptionist'), ...ROUTE })

    await userEvent.click(screen.getByRole('button', { name: 'Registrar paciente' }))

    expect(await screen.findByText('El documento ya está registrado en la clínica.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver la ficha existente' })).toHaveAttribute(
      'href',
      '/c/clinica-demo/app/pacientes/p-1',
    )
  })

  it('detects a minor by the 18th birthday', () => {
    const year = new Date().getFullYear()
    expect(isMinor(`${year - 18}-01-01`)).toBe(false)
    expect(isMinor(`${year - 17}-01-01`)).toBe(true)
    expect(isMinor('no-es-fecha')).toBe(false)
  })
})
