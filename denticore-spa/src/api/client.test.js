import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi } from '../test/utils'
import { getToken, setToken } from '../auth/token'
import { apiClient, onUnauthorized } from './client'

afterEach(() => onUnauthorized(null))

describe('apiClient', () => {
  it('sends the bearer token, the Spanish locale and a correlation id', async () => {
    setToken('1|abc')
    const calls = mockApi(() => ({ data: {} }))

    await apiClient.get('/patients')

    const headers = calls[0].headers
    expect(calls[0].baseURL).toMatch(/\/api\/v1$/)
    expect(headers.Authorization).toBe('Bearer 1|abc')
    expect(headers['Accept-Language']).toBe('es-PE')
    expect(headers['X-Correlation-Id']).toMatch(/^[0-9a-f-]{36}$/)
    expect(headers['Idempotency-Key']).toBeUndefined()
  })

  it('adds an Idempotency-Key to writes and reuses it when retrying a network failure', async () => {
    let attempts = 0
    const calls = mockApi(() => {
      attempts += 1
      if (attempts === 1) {
        throw Object.assign(new Error('Network Error'), { isAxiosError: true })
      }
      return { status: 201, data: {} }
    })
    apiClient.defaults.adapter = wrapNetworkError(apiClient.defaults.adapter)

    await apiClient.post('/patients', { first_name: 'Ana' })

    expect(calls).toHaveLength(2)
    expect(calls[0].headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(calls[1].headers['Idempotency-Key']).toBe(calls[0].headers['Idempotency-Key'])
  })

  it('stops retrying a write after two network failures', async () => {
    const calls = mockApi(() => {
      throw Object.assign(new Error('Network Error'), { isAxiosError: true })
    })
    apiClient.defaults.adapter = wrapNetworkError(apiClient.defaults.adapter)

    await expect(apiClient.post('/patients', {})).rejects.toThrow('Network Error')
    expect(calls).toHaveLength(3)
  })

  it('clears the token and notifies the session on 401', async () => {
    setToken('1|abc')
    const handler = vi.fn()
    onUnauthorized(handler)
    mockApi(() => ({ status: 401, data: {} }))

    await expect(apiClient.get('/auth/me')).rejects.toThrow()
    expect(getToken()).toBeNull()
    expect(handler).toHaveBeenCalledOnce()
  })

  it('treats a 401 from the login itself as invalid credentials', async () => {
    const handler = vi.fn()
    onUnauthorized(handler)
    mockApi(() => ({ status: 401, data: {} }))

    await expect(apiClient.post('/auth/login', {})).rejects.toThrow()
    expect(handler).not.toHaveBeenCalled()
  })
})

/** Convierte las excepciones del manejador en errores de red de Axios (sin respuesta). */
function wrapNetworkError(adapter) {
  return async (config) => {
    try {
      return await adapter(config)
    } catch (error) {
      if (!error.response) {
        error.config = config
      }
      throw error
    }
  }
}
