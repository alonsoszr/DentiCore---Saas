import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Field } from './Field'

describe('Field', () => {
  it('links the error to its own select so it is announced and can take the focus', () => {
    render(
      <Field label="Sexo" name="sex" error="Selecciona el sexo.">
        <select id="sex">
          <option value="">Selecciona…</option>
        </select>
      </Field>,
    )

    const select = screen.getByLabelText('Sexo')
    expect(select).toHaveAttribute('aria-invalid', 'true')
    expect(select).toHaveAttribute('aria-describedby', 'sex-error')
    expect(screen.getByRole('alert')).toHaveTextContent('Selecciona el sexo.')
  })

  it('keeps the attributes that the control already brings', () => {
    render(
      <Field label="Motivo" name="reason" hint="Entre 10 y 500 caracteres.">
        <textarea id="reason" aria-describedby="reason-custom" />
      </Field>,
    )

    expect(screen.getByLabelText('Motivo')).toHaveAttribute('aria-describedby', 'reason-custom')
  })
})
