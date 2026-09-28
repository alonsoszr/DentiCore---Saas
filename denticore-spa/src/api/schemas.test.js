import { describe, expect, it } from 'vitest'
import { patientResourceSchema, problemDetailsSchema, storePatientRequestSchema } from './schemas'

// Esquemas zod generados desde el contrato OpenAPI (TASK-018; SDD §1.10, RNF-064).
describe('generated API schemas', () => {
  it('apply the same limits as the Form Requests', () => {
    const valid = { document_id: '40000001', first_name: 'Ana', last_name: 'Núñez', birth_date: '1990-01-31' }

    expect(storePatientRequestSchema.safeParse(valid).success).toBe(true)
    expect(storePatientRequestSchema.safeParse({ ...valid, first_name: 'A'.repeat(101) }).success).toBe(false)
    expect(storePatientRequestSchema.safeParse({ ...valid, document_id: undefined }).success).toBe(false)
  })

  it('describe the resources and the problem+json errors of the API', () => {
    expect(
      patientResourceSchema.safeParse({
        id: '9eac9416-34db-43f2-a0cb-7b33a7d97e0f',
        document_id: '40000001',
        first_name: 'Ana',
        last_name: 'Núñez',
        birth_date: '1990-01-31',
        phone: null,
        email: null,
        medical_history: null,
        created_at: '2026-09-27T23:00:00.000000Z',
      }).success,
    ).toBe(true)

    expect(
      problemDetailsSchema.safeParse({
        type: 'https://denticore.pe/problems/validation',
        title: 'Datos no válidos',
        status: 422,
        detail: 'Revise los datos enviados.',
        instance: 'urn:correlation:7d0c9e1a-2b7f-4f53-9a55-0d1b8e7f4a10',
        errors: { first_name: ['El campo nombres es obligatorio.'] },
      }).success,
    ).toBe(true)
  })
})
