import { describe, expect, it } from 'vitest'
import { makeUser } from '../test/utils'
import { adminPath, appPath, homePathFor, loginPathFor, portalPath, slugFromPathname } from './paths'

describe('paths (DD-29)', () => {
  it('builds the login path of the platform and of each clinic', () => {
    expect(loginPathFor(null)).toBe('/login')
    expect(loginPathFor('clinica-demo')).toBe('/c/clinica-demo/login')
  })

  it('sends each role to the home of its area', () => {
    expect(homePathFor(null)).toBe('/login')
    expect(homePathFor(makeUser('super_admin'))).toBe('/admin/clinicas')
    expect(homePathFor(makeUser('receptionist'))).toBe('/c/clinica-demo/app/pacientes')
    expect(homePathFor(makeUser('patient'))).toBe('/c/clinica-demo/portal')
  })

  it('builds area paths and reads the clinic code from a pathname', () => {
    expect(adminPath('/clinicas')).toBe('/admin/clinicas')
    expect(appPath('x', '/usuarios')).toBe('/c/x/app/usuarios')
    expect(portalPath('x')).toBe('/c/x/portal')
    expect(slugFromPathname('/c/clinica-demo/app/pacientes')).toBe('clinica-demo')
    expect(slugFromPathname('/admin/clinicas')).toBeNull()
  })
})
