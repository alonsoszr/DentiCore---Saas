import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ConsentForm } from './ConsentForm'

const PREVIEW = {
  template_version: 1,
  text: 'CONSENTIMIENTO PARA EL TRATAMIENTO DE DATOS PERSONALES',
  available_purposes: ['purpose_care', 'purpose_notifications', 'purpose_ai', 'purpose_risk', 'purpose_surveys'],
}

describe('ConsentForm', () => {
  it('renders every optional purpose unchecked (T-035, CA-17.1)', () => {
    render(<ConsentForm preview={PREVIEW} onSubmit={() => {}} />)

    for (const label of [
      '(b) Notificaciones',
      '(c) Asistencia de IA generativa',
      '(d) Predicción de riesgo',
      '(e) Encuestas',
    ]) {
      expect(screen.getByLabelText(label)).not.toBeChecked()
    }
    // La finalidad (a) también la marca la persona (FE-1).
    expect(screen.getByLabelText('(a) Atención odontológica (obligatoria)')).not.toBeChecked()
    expect(screen.getByText(PREVIEW.text)).toBeInTheDocument()
  })

  it('hides the purposes the clinic does not offer (CA-17.5)', () => {
    render(
      <ConsentForm
        preview={{ ...PREVIEW, available_purposes: ['purpose_care', 'purpose_notifications', 'purpose_surveys'] }}
        onSubmit={() => {}}
      />,
    )

    expect(screen.queryByLabelText('(c) Asistencia de IA generativa')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('(d) Predicción de riesgo')).not.toBeInTheDocument()
  })

  it('sends the purposes that were marked', async () => {
    const onSubmit = vi.fn()
    render(<ConsentForm preview={PREVIEW} onSubmit={onSubmit} />)

    await userEvent.click(screen.getByLabelText('(a) Atención odontológica (obligatoria)'))
    await userEvent.click(screen.getByLabelText('(e) Encuestas'))
    await userEvent.click(screen.getByRole('button', { name: 'Registrar consentimiento' }))

    expect(onSubmit).toHaveBeenCalledWith({
      purpose_care: true,
      purpose_notifications: false,
      purpose_ai: false,
      purpose_risk: false,
      purpose_surveys: true,
    })
  })
})
