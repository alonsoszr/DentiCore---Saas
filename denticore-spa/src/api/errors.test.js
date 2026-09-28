import { describe, expect, it } from 'vitest'
import { fieldErrors, generalError } from './errors'

const httpError = (status, data = {}) => ({ response: { status, data } })

describe('fieldErrors', () => {
  it('returns the first message of each field for 422 and 409', () => {
    const error = httpError(422, { errors: { email: ['Correo inválido', 'Otro'], name: ['Obligatorio'] } })

    expect(fieldErrors(error)).toEqual({ email: 'Correo inválido', name: 'Obligatorio' })
    expect(fieldErrors(httpError(409, { errors: { slot: ['Ocupado'] } }))).toEqual({ slot: 'Ocupado' })
  })

  it('ignores other statuses and missing errors', () => {
    expect(fieldErrors(httpError(403, { errors: { x: ['y'] } }))).toEqual({})
    expect(fieldErrors(null)).toEqual({})
  })
})

describe('generalError', () => {
  it('returns nothing when there is no error or the errors belong to fields', () => {
    expect(generalError(null)).toBeNull()
    expect(generalError(httpError(422, { errors: { email: ['x'] } }))).toBeNull()
  })

  it('shows the problem detail sent by the API', () => {
    expect(generalError(httpError(422, { detail: 'El paciente no tiene consentimiento.' }))).toBe(
      'El paciente no tiene consentimiento.',
    )
  })

  it('explains network failures and common statuses in Spanish', () => {
    expect(generalError({ message: 'Network Error' })).toMatch(/No se pudo conectar/)
    expect(generalError(httpError(403))).toMatch(/permiso/)
    expect(generalError(httpError(404))).toMatch(/No se encontró/)
    expect(generalError(httpError(409))).toMatch(/conflicto/)
    expect(generalError(httpError(429))).toMatch(/Demasiados intentos/)
    expect(generalError(httpError(500))).toMatch(/inesperado/)
  })
})
