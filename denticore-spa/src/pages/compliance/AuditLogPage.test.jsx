import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { AuditLogPage } from './AuditLogPage'

describe('Bitácora de auditoría (CUS-66)', () => {
  it('filtra y pagina la bitácora de la clínica', async () => {
    const calls = mockApi(({ params }) => ({
      data: {
        data: [{ id: String(params.page), action: params.action || 'auth.login', actor_role: 'clinic_admin' }],
        meta: { current_page: params.page, last_page: 2 },
      },
    }))
    renderPage(<AuditLogPage />, { user: makeUser('clinic_admin') })
    expect(await screen.findByText('auth.login')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Acción'), 'audit.exported')
    await userEvent.type(screen.getByLabelText('Tipo de recurso'), 'patient')
    await userEvent.click(screen.getByRole('button', { name: 'Filtrar' }))
    expect(await screen.findByText('audit.exported')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
    await waitFor(() =>
      expect(calls.at(-1)).toMatchObject({
        url: '/audit-logs',
        params: { action: 'audit.exported', resource_type: 'patient', page: 2 },
      }),
    )
  })

  it('exporta los mismos filtros por la ruta de plataforma', async () => {
    const download = vi.fn()
    const calls = mockApi(({ method, params }) =>
      method === 'post'
        ? { data: new Blob(['accion']) }
        : { data: { data: [], meta: { current_page: params.page, last_page: 1 } } },
    )
    renderPage(<AuditLogPage platform download={download} />, { user: makeUser('super_admin') })
    const exportButton = screen.getByRole('button', { name: 'Exportar CSV' })
    expect(exportButton).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Desde'), '2026-11-01')
    await userEvent.type(screen.getByLabelText('Hasta'), '2026-11-22')
    expect(exportButton).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Filtrar' }))
    await waitFor(() => expect(exportButton).toBeEnabled())
    await userEvent.clear(screen.getByLabelText('Hasta'))
    await userEvent.click(exportButton)
    await waitFor(() => expect(download).toHaveBeenCalledOnce())
    expect(calls.at(-1)).toMatchObject({
      url: '/platform/audit-logs/exports',
      responseType: 'blob',
      data: JSON.stringify({ from: '2026-11-01', to: '2026-11-22' }),
    })
  })
})
