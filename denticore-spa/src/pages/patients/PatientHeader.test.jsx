import { render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../../auth/useAuth'
import { makeUser } from '../../test/utils'
import { PatientHeader } from './PatientHeader'
import { EXPIRY_MS, HEARTBEAT_MS } from './useOtherPatientTabs'

const ana = {
  id: 'p-ana',
  first_name: 'Ana',
  last_name: 'Quispe Mamani',
  age_years: 34,
  clinical_record_number: '70000001',
  allergies: [],
}
const jose = {
  id: 'p-jose',
  first_name: 'José',
  last_name: 'Núñez',
  age_years: 51,
  clinical_record_number: '70000002',
  allergies: [],
}
const luis = { ...jose, id: 'p-luis', first_name: 'Luis', last_name: "D'Angelo" }
const marta = { ...jose, id: 'p-marta', first_name: 'Marta', last_name: 'Huamán' }

const mounted = []

/** La región de estado de una pestaña (cada render se monta en su propio contenedor). */
const statusOf = (tab) => within(tab.container).getByRole('status')

/** Una «pestaña»: su propia raíz de React con la sesión y la ruta de la clínica. */
function tabUi(patient, { user = makeUser('dentist'), slug = 'clinica-demo' } = {}) {
  return (
    <AuthContext.Provider value={{ user }}>
      <MemoryRouter initialEntries={[`/c/${slug}/app/pacientes/x`]}>
        <Routes>
          <Route path="/c/:slug/app/pacientes/:uuid" element={<PatientHeader patient={patient} />} />
        </Routes>
      </MemoryRouter>
    </AuthContext.Provider>
  )
}

function openTab(patient, options) {
  const tab = render(tabUi(patient, options))
  mounted.push(tab)
  return tab
}

afterEach(() => {
  mounted.splice(0).forEach((tab) => tab.unmount())
  vi.useRealTimers()
})

// RNF-146: identidad del paciente visible y aviso si el mismo usuario tiene abierto otro paciente en
// otra pestaña (DEF-008 del TCR-E1).
describe('PatientHeader (RNF-146)', () => {
  it('warns in both tabs when the same user opens another patient in another tab', async () => {
    const first = openTab(ana)
    const second = openTab(jose)

    await waitFor(() =>
      expect(statusOf(first)).toHaveTextContent('En otra pestaña tienes abierta la ficha de José Núñez'),
    )
    expect(statusOf(first)).toHaveTextContent('Lo que registres aquí queda en la ficha de Ana Quispe Mamani.')
    await waitFor(() =>
      expect(statusOf(second)).toHaveTextContent('En otra pestaña tienes abierta la ficha de Ana Quispe Mamani'),
    )
    expect(within(first.container).getByText('Otro paciente abierto en otra pestaña')).toBeInTheDocument()
  })

  it('removes the warning when the other tab closes', async () => {
    const first = openTab(ana)
    const second = openTab(jose)
    await waitFor(() => expect(statusOf(first)).toHaveTextContent('José Núñez'))

    second.unmount()
    mounted.splice(mounted.indexOf(second), 1)

    await waitFor(() => expect(statusOf(first)).toBeEmptyDOMElement())
  })

  it('does not warn for the same patient, another user or another clinic', async () => {
    const first = openTab(ana)
    openTab(ana)
    openTab(luis, { user: makeUser('receptionist') })
    openTab(marta, { slug: 'otra-clinica' })
    // Control: la última pestaña sí es del mismo usuario y la misma clínica.
    openTab(jose)

    await waitFor(() => expect(statusOf(first)).toHaveTextContent('José Núñez'))
    expect(statusOf(first)).toHaveTextContent('En otra pestaña tienes abierta la ficha de José Núñez.')
    expect(statusOf(first)).not.toHaveTextContent(/Luis|Marta|Ana Quispe Mamani,/)
    expect(screen.getAllByRole('heading', { name: 'Ana Quispe Mamani' })).toHaveLength(2)
  })

  it('follows the other tab when it switches to another patient and lists several patients', async () => {
    const first = openTab(ana)
    const second = openTab(jose)
    openTab(luis)
    await waitFor(() =>
      expect(statusOf(first)).toHaveTextContent(
        "En otra pestaña tienes abiertas las fichas de José Núñez y Luis D'Angelo.",
      ),
    )

    second.rerender(tabUi(marta))

    await waitFor(() => expect(statusOf(first)).toHaveTextContent("Luis D'Angelo y Marta Huamán"))
    expect(statusOf(first)).not.toHaveTextContent('José Núñez')
  })

  it('forgets a tab that closed without saying goodbye', async () => {
    // Solo el intervalo y el reloj son simulados (RNF-130): los mensajes del canal siguen su curso.
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    const first = openTab(ana)
    const crashed = new BroadcastChannel('denticore:pacientes:clinica-demo:u-dentist')
    crashed.postMessage({ type: 'presente', tab: 'pestaña-caída', id: 'p-luis', name: "Luis D'Angelo" })
    await waitFor(() => expect(statusOf(first)).toHaveTextContent("Luis D'Angelo"))
    crashed.close()

    vi.advanceTimersByTime(EXPIRY_MS + HEARTBEAT_MS)

    await waitFor(() => expect(statusOf(first)).toBeEmptyDOMElement())
  })
})
