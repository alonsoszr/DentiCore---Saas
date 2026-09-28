import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { PatientDetailPage } from './PatientDetailPage'

const ROUTE = { route: '/c/clinica-demo/app/pacientes/p-1', path: '/c/:slug/app/pacientes/:uuid' }

describe('PatientDetailPage', () => {
  it('shows the record with its medical history', async () => {
    mockApi(() => ({
      data: {
        data: {
          id: 'p-1',
          first_name: 'Ana',
          last_name: 'Núñez',
          document_id: '40000001',
          birth_date: '1990-01-31',
          phone: '999888777',
          email: null,
          user_uuid: 'u-1',
          medical_history: { alergias: ['Penicilina'], enfermedades: [], medicamentos: [], observaciones: null },
        },
      },
    }))
    renderPage(<PatientDetailPage />, { user: makeUser('dentist'), ...ROUTE })

    expect(await screen.findByRole('heading', { name: 'Ana Núñez' })).toBeInTheDocument()
    expect(screen.getByText('40000001')).toBeInTheDocument()
    expect(screen.getByText('Penicilina')).toBeInTheDocument()
    expect(screen.getAllByText('Ninguna registrada')).toHaveLength(2)
    expect(screen.getByText('Vinculada')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Volver' })).toHaveAttribute('href', '/c/clinica-demo/app/pacientes')
  })

  it('shows a record without medical history', async () => {
    mockApi(() => ({
      data: {
        data: {
          id: 'p-1',
          first_name: 'Ana',
          last_name: 'Núñez',
          document_id: '1',
          birth_date: '1990-01-31',
          phone: null,
          email: null,
          medical_history: null,
        },
      },
    }))
    renderPage(<PatientDetailPage />, { user: makeUser('dentist'), ...ROUTE })

    expect(await screen.findByText('No se registraron antecedentes.')).toBeInTheDocument()
    expect(screen.getByText('Sin cuenta vinculada')).toBeInTheDocument()
  })

  it('shows not found for a record of another clinic', async () => {
    mockApi(() => ({ status: 404, data: {} }))
    renderPage(<PatientDetailPage />, { user: makeUser('dentist'), ...ROUTE })

    expect(await screen.findByText('No se encontró el recurso solicitado.')).toBeInTheDocument()
  })
})
