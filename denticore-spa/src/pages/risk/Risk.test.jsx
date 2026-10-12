import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { formatPercent } from '../../ui/format'
import { CLINIC, makeUser, mockApi, renderPage } from '../../test/utils'
import { RiskCard } from './RiskCard'
import { RiskPage } from './RiskPage'
import { RiskPanel } from './RiskPanel'
import { RiskVariablesPage } from './RiskVariablesPage'

const RISK_CLINIC = { ...CLINIC, plan: { includes_risk: true } }
const dentist = makeUser('dentist', { tenant: RISK_CLINIC })
const key = (config) => `${config.method.toUpperCase()} ${config.url}`
const body = (calls, wanted) => JSON.parse(calls.filter((call) => key(call) === wanted).at(-1).data)

const PATIENT = { id: 'p-1', first_name: 'Lucía', last_name: 'Sintética', is_minor: false, age_years: 36 }

function prediction(overrides = {}) {
  return {
    id: 'r-1',
    status: 'vigente',
    level: 'alto',
    confidence: '0.6431',
    low_confidence: false,
    model_version: '1.0.0',
    thresholds: { medium: '0.300', high: '0.600' },
    predicted_at: '2026-11-02T15:00:00+00:00',
    expires_at: '2027-11-02T15:00:00+00:00',
    legend: 'Herramienta de apoyo; no constituye diagnóstico',
    explanation_global: [
      { feature: 'sugar_between_meals', label: 'Azúcar entre comidas', mean_abs_shap: '0.412' },
      { feature: 'active_lesions', label: 'Lesiones activas', mean_abs_shap: '0.377' },
    ],
    alert_id: 'al-1',
    probability: '0.6431',
    top_increasing: [
      { feature: 'active_lesions', label: 'Lesiones activas', value: '2', shap: '0.521' },
      { feature: 'sugar_between_meals', label: 'Azúcar entre comidas', value: '3', shap: '0.304' },
    ],
    top_decreasing: [{ feature: 'fluoride_toothpaste', label: 'Pasta fluorada', value: 'sí', shap: '-0.188' }],
    ...overrides,
  }
}

function variableSet(overrides = {}) {
  return {
    id: 's-1',
    age_group: 'adulto',
    age_years: 36,
    clinical: { cpod: 3, ceod: null, plaque_index_pct: 42.5, active_lesions: 2 },
    behavioral: {
      sugar_between_meals: '3',
      fluoride_toothpaste: true,
      brushing_per_day: '2',
      dental_visits_per_year: '1',
    },
    sociodemographic: {},
    is_complete: false,
    missing: ['respondent', 'education_level', 'employment_status'],
    locked_at: null,
    supersedes_id: null,
    created_at: '2026-11-02T14:00:00+00:00',
    ...overrides,
  }
}

describe('RiskCard', () => {
  it('shows the level with text, the probability, the factors and the fixed legend to the dentist', () => {
    renderPage(<RiskCard prediction={prediction()} />, { user: dentist })

    expect(screen.getByText('Riesgo alto')).toBeInTheDocument()
    expect(screen.getByText('Probabilidad calibrada')).toBeInTheDocument()
    expect(screen.getByText('Lesiones activas:')).toBeInTheDocument()
    expect(screen.getByText('Pasta fluorada:')).toBeInTheDocument()
    expect(screen.getByText('Herramienta de apoyo; no constituye diagnóstico')).toBeInTheDocument()
    expect(screen.queryByText('Baja confianza')).not.toBeInTheDocument()
    expect(screen.getByText('Variables con más peso en el modelo')).toBeInTheDocument()
    expect(screen.getAllByText(formatPercent('0.6431'), { selector: 'dd' })).toHaveLength(2)
  })

  it('adds the low confidence badge below 0.60 and shows only the global explanation without SHAP fields', () => {
    const admin = prediction({ level: 'medio', confidence: '0.5500', low_confidence: true })
    delete admin.probability
    delete admin.top_increasing
    delete admin.top_decreasing

    renderPage(<RiskCard prediction={admin} />, { user: makeUser('clinic_admin', { tenant: RISK_CLINIC }) })

    expect(screen.getByText('Riesgo medio')).toBeInTheDocument()
    expect(screen.getByText('Baja confianza')).toBeInTheDocument()
    expect(screen.queryByText('Probabilidad calibrada')).not.toBeInTheDocument()
    expect(screen.getByText('Variables con más peso en el modelo')).toBeInTheDocument()
    expect(screen.getByText('Herramienta de apoyo; no constituye diagnóstico')).toBeInTheDocument()
  })
})

describe('RiskPanel', () => {
  it('computes the risk and shows a neutral card when the engine is unavailable', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /patients/p-1/clinical-record')
        return { data: { data: { current_risk_prediction: null } } }
      if (key(config) === 'GET /patients/p-1/risk-variable-sets')
        return { data: { data: [variableSet({ is_complete: true, missing: [] })] } }
      if (key(config) === 'POST /patients/p-1/risk-predictions')
        return { data: { data: { status: 'no_disponible', message: 'Predicción no disponible' } } }
      return { status: 404 }
    })
    renderPage(<RiskPanel patient={PATIENT} />, { user: dentist })

    await userEvent.click(await screen.findByRole('button', { name: 'Calcular riesgo' }))

    expect(await screen.findByText('Predicción no disponible')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(body(calls, 'POST /patients/p-1/risk-predictions')).toEqual({ risk_variable_set_id: 's-1' })
  })

  it('lists the missing variables and keeps the calculation disabled', async () => {
    mockApi((config) => {
      if (key(config) === 'GET /patients/p-1/clinical-record')
        return { data: { data: { current_risk_prediction: null } } }
      if (key(config) === 'GET /patients/p-1/risk-variable-sets') return { data: { data: [variableSet()] } }
      return { status: 404 }
    })
    renderPage(<RiskPanel patient={PATIENT} />, { user: dentist })

    const warning = await screen.findByText('Faltan variables para calcular')
    expect(within(warning.closest('[role="alert"]')).getByText('Nivel educativo')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Calcular riesgo' })).toBeDisabled()
  })

  it('shows the current prediction of the clinical record', async () => {
    mockApi((config) => {
      if (key(config) === 'GET /patients/p-1/clinical-record')
        return { data: { data: { current_risk_prediction: { id: 'r-1' } } } }
      if (key(config) === 'GET /risk-predictions/r-1') return { data: { data: prediction() } }
      return { status: 404 }
    })
    renderPage(<RiskPanel patient={PATIENT} />, { user: makeUser('clinic_admin', { tenant: RISK_CLINIC }) })

    expect(await screen.findByText('Riesgo alto')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Calcular riesgo' })).not.toBeInTheDocument()
  })
})

describe('RiskPanel results', () => {
  it('shows the new prediction after computing it and the missing variables of a 422', async () => {
    let attempt = 0
    mockApi((config) => {
      if (key(config) === 'GET /patients/p-1/clinical-record')
        return { data: { data: { current_risk_prediction: attempt > 1 ? { id: 'r-1' } : null } } }
      if (key(config) === 'GET /patients/p-1/risk-variable-sets')
        return { data: { data: [variableSet({ is_complete: true, missing: [] })] } }
      if (key(config) === 'GET /risk-predictions/r-1') return { data: { data: prediction() } }
      if (key(config) === 'POST /patients/p-1/risk-predictions') {
        attempt += 1
        if (attempt === 1)
          return { status: 422, data: { rule: 'RN-58', missing: ['variables capturadas hace más de 6 meses'] } }
        return { status: 201, data: { data: prediction() } }
      }
      return { status: 404 }
    })
    renderPage(<RiskPanel patient={PATIENT} />, { user: dentist })

    await userEvent.click(await screen.findByRole('button', { name: 'Calcular riesgo' }))
    expect(await screen.findByText('variables capturadas hace más de 6 meses')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Calcular riesgo' }))
    expect(await screen.findByText('Riesgo alto')).toBeInTheDocument()
    expect(screen.getByText('Factores que aumentan el riesgo')).toBeInTheDocument()
  })

  it('keeps showing the current prediction when the engine is unavailable', async () => {
    mockApi((config) => {
      if (key(config) === 'GET /patients/p-1/clinical-record')
        return { data: { data: { current_risk_prediction: { id: 'r-1' } } } }
      if (key(config) === 'GET /risk-predictions/r-1') return { data: { data: prediction({ level: 'medio' }) } }
      if (key(config) === 'GET /patients/p-1/risk-variable-sets')
        return { data: { data: [variableSet({ is_complete: true, missing: [] })] } }
      if (key(config) === 'POST /patients/p-1/risk-predictions')
        return { data: { data: { status: 'no_disponible', message: 'Predicción no disponible' } } }
      return { status: 404 }
    })
    renderPage(<RiskPanel patient={PATIENT} />, { user: dentist })

    await userEvent.click(await screen.findByRole('button', { name: 'Calcular riesgo' }))

    expect(await screen.findByText('Predicción no disponible')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Predicción vigente' })).toBeInTheDocument()
    expect(screen.getByText('Riesgo medio')).toBeInTheDocument()
  })

  it('sends reception straight to the variables and shows a load error', async () => {
    mockApi(() => ({ status: 500 }))
    renderPage(<RiskPage patient={PATIENT} />, {
      user: makeUser('receptionist', { tenant: RISK_CLINIC }),
      route: '/riesgo',
      path: '/riesgo',
    })
    expect(screen.getByTestId('location')).toHaveTextContent('/riesgo/variables')

    renderPage(<RiskPanel patient={PATIENT} />, { user: dentist })
    expect((await screen.findAllByText('Ocurrió un error inesperado. Inténtalo nuevamente.')).length).toBeGreaterThan(0)
  })
})

describe('RiskVariablesPage', () => {
  it('lets reception write only the sociodemographic section, with the respondent by age', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /patients/p-1/risk-variable-sets') return { data: { data: [variableSet()] } }
      if (key(config) === 'PATCH /risk-variable-sets/s-1') return { data: { data: variableSet({ is_complete: true }) } }
      return { status: 404 }
    })
    renderPage(<RiskVariablesPage patient={PATIENT} />, { user: makeUser('receptionist', { tenant: RISK_CLINIC }) })

    await userEvent.selectOptions(await screen.findByLabelText('Nivel educativo'), 'secundaria')
    await userEvent.selectOptions(screen.getByLabelText('Situación laboral'), 'informal')
    expect(screen.queryByLabelText('Estructura familiar')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('CPOD')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Guardar variables' }))

    await waitFor(() =>
      expect(body(calls, 'PATCH /risk-variable-sets/s-1')).toEqual({
        sociodemographic: { respondent: 'titular', education_level: 'secundaria', employment_status: 'informal' },
      }),
    )
    expect(await screen.findByText('Variables guardadas.')).toBeInTheDocument()
  })

  it('shows the API error next to its field and the family structure for a minor', async () => {
    mockApi((config) => {
      if (key(config) === 'GET /patients/p-1/risk-variable-sets') return { data: { data: [] } }
      if (key(config) === 'POST /patients/p-1/risk-variable-sets')
        return { status: 422, data: { errors: { 'clinical.cpod': ['El campo CPOD debe estar entre 0 y 32.'] } } }
      return { status: 404 }
    })
    renderPage(<RiskVariablesPage patient={{ ...PATIENT, is_minor: true }} />, { user: dentist })

    await userEvent.type(await screen.findByLabelText('CPOD'), '40')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar variables' }))

    expect(await screen.findByText('El campo CPOD debe estar entre 0 y 32.')).toBeInTheDocument()
    expect(screen.getByLabelText('CPOD')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByText('Estructura familiar')).toBeInTheDocument()
    expect(screen.getByText('Informa el representante del paciente.', { exact: false })).toBeInTheDocument()
  })
})
