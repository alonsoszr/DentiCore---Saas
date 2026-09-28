import { describe, expect, it } from 'vitest'
import { clearToken, getToken, setToken, TOKEN_KEY } from './token'

// T-165 (SDD §6.3.10; DD-44, RNF-094)
describe('token storage', () => {
  it('stores the token in sessionStorage and never in localStorage', () => {
    setToken('1|abc')

    expect(TOKEN_KEY).toBe('dc.token')
    expect(sessionStorage.getItem('dc.token')).toBe('1|abc')
    expect(localStorage.length).toBe(0)
    expect(getToken()).toBe('1|abc')
  })

  it('removes the token on clear', () => {
    setToken('1|abc')
    clearToken()

    expect(getToken()).toBeNull()
  })
})
