import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { PatientCreatePage } from './PatientCreatePage'

const ROUTE = { route: '/c/clinica-demo/app/pacientes/nuevo', path: '/c/:slug/app/pacientes/nuevo' }

describe('PatientCreatePage', () => {
  it('sends the structured medical history and opens the new record', async () => {
    const calls = mockApi((config) => {
      if (config.method === 'get') {
        return {
          data: {
            data: [
              { id: 'u-1', role: 'patient', is_active: true, name: 'Pablo', email: 'p@x.test', patient_uuid: null },
              { id: 'u-2', role: 'dentist', is_active: true, name: 'Dra', email: 'd@x.test' },
            ],
          },
        }
      }
      return { status: 201, data: { data: { id: 'p-9' } } }
    })
    renderPage(<PatientCreatePage />, { user: makeUser('clinic_admin'), ...ROUTE })

    await userEvent.type(screen.getByLabelText('Nombres'), 'Ana')
    await userEvent.type(screen.getByLabelText('Apellidos'), 'Núñez')
    await userEvent.type(screen.getByLabelText('Documento de identidad (DNI)'), '40000001')
    await userEvent.type(screen.getByLabelText('Fecha de nacimiento'), '1990-01-31')
    await userEvent.type(screen.getByLabelText('Alergias'), 'Penicilina, , Látex')
    await userEvent.type(screen.getByLabelText('Observaciones'), '  Control anual  ')
    await userEvent.selectOptions(await screen.findByLabelText('Cuenta de portal (opcional)'), 'u-1')
    expect(screen.queryByRole('option', { name: /Dra/ })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Registrar paciente' }))

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/pacientes/p-9')
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toEqual({
      document_id: '40000001',
      first_name: 'Ana',
      last_name: 'Núñez',
      birth_date: '1990-01-31',
      phone: null,
      email: null,
      user_uuid: 'u-1',
      medical_history: {
        alergias: ['Penicilina', 'Látex'],
        enfermedades: [],
        medicamentos: [],
        observaciones: 'Control anual',
      },
    })
  })

  it('shows field errors, including the ones of a history item', async () => {
    mockApi(() => ({
      status: 422,
      data: {
        errors: {
          document_id: ['El documento de identidad ya está registrado.'],
          'medical_history.alergias.0': ['El campo alergia no debe superar los 150 caracteres.'],
        },
      },
    }))
    renderPage(<PatientCreatePage />, { user: makeUser('receptionist'), ...ROUTE })

    expect(screen.queryByLabelText('Cuenta de portal (opcional)')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Registrar paciente' }))

    expect(await screen.findByText('El documento de identidad ya está registrado.')).toBeInTheDocument()
    expect(screen.getByText('El campo alergia no debe superar los 150 caracteres.')).toBeInTheDocument()
  })
})
