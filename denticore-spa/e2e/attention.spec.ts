import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

// TASK-052: abrir atención → registrar hallazgo → corregir → cerrar → ver el historial de la pieza. Crea
// una ficha nueva en cada ejecución (DNI sintético), así que corre en un solo proyecto.
test.skip(
  ({ browserName, channel, viewport }) => browserName !== 'chromium' || Boolean(channel) || viewport?.width !== 1280,
  'Flujo con datos nuevos: solo en Chrome a 1280 px',
)

test('opens an attention, records and corrects a finding, closes it and shows the history of the tooth', async ({
  page,
}) => {
  // Flujo clínico completo de MS-02: más largo que el límite general de 30 s.
  test.setTimeout(90_000)
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))

  await page.goto('/c/clinica-demo/login')
  await page.getByLabel('Correo electrónico').fill('dentista@clinica-demo.test')
  await page.getByLabel('Contraseña', { exact: true }).fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes$/)

  // Paciente sintético con consentimiento (a) y una alergia registrada.
  await page.getByRole('link', { name: 'Registrar paciente' }).click()
  await page.getByLabel('Número de documento', { exact: true }).fill(dni)
  await page.getByLabel('Nombres', { exact: true }).fill('Mario')
  await page.getByLabel('Apellidos', { exact: true }).fill('Sintético Atención')
  await page.getByLabel('Fecha de nacimiento').fill('1988-04-12')
  await page.getByLabel('Sexo').selectOption('masculino')
  await page.getByLabel('Teléfono').fill('987654321')
  await page.getByRole('button', { name: 'Registrar paciente' }).click()
  await page.getByRole('link', { name: 'Consentimiento', exact: true }).click()
  await page.getByLabel('(a) Atención odontológica (obligatoria)').check()
  await page.getByLabel('Documento del titular (confirmación)').fill(dni)
  await page.getByRole('button', { name: 'Registrar consentimiento' }).click()
  await expect(page.getByText('Consentimiento registrado.')).toBeVisible()
  await page.getByRole('link', { name: 'Antecedentes', exact: true }).click()
  await page.getByLabel('Alergias').fill('Penicilina')
  await page.getByRole('button', { name: 'Guardar antecedentes' }).click()
  await expect(page.getByText('Antecedentes guardados.')).toBeVisible()

  // Abrir la atención (CUS-25): el aviso de alergias encabeza la pantalla (RNF-149).
  await page.getByRole('link', { name: 'Historia clínica', exact: true }).click()
  await page.getByRole('button', { name: 'Atender' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/atenciones\/[0-9a-f-]+$/)
  await expect(page.getByRole('region', { name: 'Paciente' }).getByText('Penicilina')).toBeVisible()

  // Hallazgo en la pieza 36 elegida con el teclado (CUS-22).
  await page.getByRole('button', { name: /^Pieza 18/ }).focus()
  await page.keyboard.type('36')
  await page.keyboard.type('o')
  await expect(page.getByRole('checkbox', { name: 'Oclusal' })).toBeChecked()
  await page.getByLabel('Hallazgo', { exact: true }).selectOption({ label: 'Lesión de caries dental' })
  await page
    .getByLabel('Estado', { exact: true })
    .selectOption({ label: 'CD · Lesión de caries dental a nivel de la dentina (rojo)' })
  await page.getByRole('button', { name: 'Registrar hallazgo' }).click()
  await expect(page.getByText('Hallazgo registrado.')).toBeVisible()
  await expect(page.getByTestId('acronyms-36')).toContainText('CD')

  // Un segundo hallazgo en la superficie equivocada se anula con motivo y confirmación (CUS-23).
  await page.getByRole('checkbox', { name: 'Mesial' }).check()
  await page.getByLabel('Hallazgo', { exact: true }).selectOption({ label: 'Lesión de caries dental' })
  await page
    .getByLabel('Estado', { exact: true })
    .selectOption({ label: 'CE · Lesión de caries dental a nivel del esmalte (rojo)' })
  await page.getByRole('button', { name: 'Registrar hallazgo' }).click()
  await expect(page.getByTestId('acronyms-36')).toContainText('CE')
  const mistaken = page.getByRole('listitem').filter({ hasText: 'Superficies: mesial' })
  await mistaken.getByRole('button', { name: /^Corregir la entrada del/ }).click()
  const correction = page.getByRole('dialog', { name: 'Corregir la entrada' })
  await correction.getByRole('radio', { name: 'Anulación' }).check()
  await correction.getByLabel('Motivo de la corrección').fill('Registrada en la superficie equivocada')
  await correction.getByRole('button', { name: 'Confirmar corrección' }).click()
  await expect(page.getByText('Corrección registrada.')).toBeVisible()
  await expect(mistaken.getByText('Corregida')).toBeVisible()
  await expect(mistaken.getByText('Motivo de la corrección: Registrada en la superficie equivocada')).toBeVisible()
  await expect(page.getByTestId('acronyms-36')).not.toContainText('CE')
  await expectNoAxeViolations(page)

  // Nota y diagnóstico CIE-10 (CUS-80), y cierre con confirmación (CUS-26, RN-77).
  await page.getByLabel('Motivo de consulta').fill('Dolor al masticar')
  await page.getByRole('button', { name: 'Guardar nota' }).click()
  await expect(page.getByText('Nota guardada.')).toBeVisible()
  await page.getByLabel('Buscar diagnóstico CIE-10').fill('caries de la dentina')
  await page.getByRole('button', { name: 'Buscar' }).click()
  await page.getByLabel('Tipo').selectOption('definitivo')
  await page.getByRole('button', { name: 'Agregar diagnóstico' }).click()
  await expect(page.getByRole('button', { name: 'Quitar K02.1' })).toBeVisible()
  await page.getByRole('button', { name: 'Cerrar atención' }).click()
  await page
    .getByRole('dialog', { name: 'Cerrar la atención' })
    .getByRole('button', { name: 'Confirmar cierre' })
    .click()
  await expect(page.getByText('Cerrada', { exact: true })).toBeVisible()

  // Historial de la pieza en la historia clínica (CUS-24).
  await page.getByRole('link', { name: 'Volver a la historia clínica' }).click()
  await page.getByRole('button', { name: /^Pieza 36/ }).click()
  const history = page.getByRole('region', { name: 'Historial de la pieza 36' })
  await expect(
    history.getByText('Lesión de caries dental · Lesión de caries dental a nivel de la dentina'),
  ).toBeVisible()
  await expect(history.getByText('Inicial · Manual')).toHaveCount(2)
  await expect(history.getByText('Corregida')).toBeVisible()
  await expectNoAxeViolations(page)
})
