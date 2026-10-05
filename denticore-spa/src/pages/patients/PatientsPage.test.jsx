import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { PatientsPage } from './PatientsPage'

const patient = (n) => ({
  id: `p-${n}`,
  first_name: `Ana ${n}`,
  last_name: 'Núñez',
  document_number: `4000000${n}`,
  birth_date: '1990-01-31',
  phone: null,
})

describe('PatientsPage', () => {
  it('lists the patients with links inside the clinic area and paginates', async () => {
    const calls = mockApi((config) => ({
      data: {
        data: [patient(config.params.page)],
        meta: { current_page: config.params.page, last_page: 2, total: 16 },
      },
    }))
    renderPage(<PatientsPage />, {
      user: makeUser('receptionist'),
      route: '/c/clinica-demo/app/pacientes',
      path: '/c/:slug/app/pacientes',
    })

    expect(await screen.findByText('Núñez, Ana 1')).toBeInTheDocument()
    expect(screen.getByText('31/01/1990')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver ficha' })).toHaveAttribute('href', '/c/clinica-demo/app/pacientes/p-1')
    expect(screen.getByRole('link', { name: 'Registrar paciente' })).toHaveAttribute(
      'href',
      '/c/clinica-demo/app/pacientes/nuevo',
    )

    await userEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
    expect(await screen.findByText('Núñez, Ana 2')).toBeInTheDocument()
    expect(calls.map((call) => call.params.page)).toEqual([1, 2])

    await userEvent.click(screen.getByRole('button', { name: 'Anterior' }))
    expect(await screen.findByText('Núñez, Ana 1')).toBeInTheDocument()
  })

  it('shows an empty state and API errors', async () => {
    mockApi(() => ({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }))
    renderPage(<PatientsPage />, {
      user: makeUser('dentist'),
      route: '/c/x/app/pacientes',
      path: '/c/:slug/app/pacientes',
    })
    expect(await screen.findByText('Aún no hay pacientes registrados.')).toBeInTheDocument()
  })

  it('shows the error returned by the API', async () => {
    mockApi(() => ({ status: 403, data: {} }))
    renderPage(<PatientsPage />, {
      user: makeUser('dentist'),
      route: '/c/x/app/pacientes',
      path: '/c/:slug/app/pacientes',
    })
    expect(await screen.findByText(/No tienes permiso/)).toBeInTheDocument()
  })
})
