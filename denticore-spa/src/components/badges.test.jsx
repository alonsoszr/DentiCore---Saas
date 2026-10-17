import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AttentionStatusBadge } from '../pages/attentions/AttentionStatusBadge'
import { DiagnosesList } from '../pages/attentions/DiagnosesPanel'
import { UserStatusBadge } from '../pages/UserStatusBadge'
import { RoleBadge } from './RoleBadge'

/** Texto visible e ícono decorativo junto a él (DESIGN.md: ningún estado solo con color). */
function expectTextAndIcon(ui, text) {
  const { container } = render(ui)
  const badge = container.querySelector('.badge')
  expect(badge).toHaveTextContent(text)
  expect(badge.querySelector('svg[aria-hidden="true"]')).not.toBeNull()
}

// RNF-061 y la regla de diseño «texto e ícono» (DEF-009 del TCR-E1).
describe('status badges (RNF-061)', () => {
  it.each([
    ['abierta', 'Abierta'],
    ['cerrada', 'Cerrada'],
    ['cerrada_incompleta', 'Cerrada incompleta'],
  ])('shows the attention status %s with text and icon', (status, text) => {
    expectTextAndIcon(<AttentionStatusBadge status={status} />, text)
  })

  it.each([
    ['pendiente_activacion', 'Pendiente de activación'],
    ['activo', 'Activo'],
    ['bloqueado_temporal', 'Bloqueado temporalmente'],
    ['inactivo', 'Inactivo'],
  ])('shows the user status %s with text and icon', (status, text) => {
    expectTextAndIcon(<UserStatusBadge status={status} />, text)
  })

  it('shows the role with text and icon', () => {
    expectTextAndIcon(<RoleBadge role="dentist" />, 'Odontólogo')
  })
})

describe('diagnosis badges (RNF-061)', () => {
  it('shows each diagnosis type with text and icon', () => {
    const { container } = render(
      <DiagnosesList
        diagnoses={[
          { id: 1, code: 'K02.1', description: 'Caries de la dentina', type: 'definitivo' },
          { id: 2, code: 'K02.0', description: 'Caries limitada al esmalte', type: 'presuntivo' },
        ]}
      />,
    )
    const badges = [...container.querySelectorAll('.badge')]
    expect(badges.map((badge) => badge.textContent)).toEqual(['Definitivo', 'Presuntivo'])
    badges.forEach((badge) => expect(badge.querySelector('svg[aria-hidden="true"]')).not.toBeNull())
  })
})
