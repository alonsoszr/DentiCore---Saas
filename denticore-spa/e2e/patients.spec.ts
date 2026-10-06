import { expect, test } from '@playwright/test'

// TASK-041: registrar paciente → consentimiento → antecedentes. Crea una ficha nueva en cada
// ejecución (DNI sintético), así que corre en un solo proyecto de la matriz.
test.skip(({ browserName }, testInfo) => browserName !== 'chromium' || !testInfo.project.name.endsWith('1280'))

test('registers a patient, the data consent and the medical history', async ({ page }) => {
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))

  await page.goto('/c/clinica-demo/login')
  await page.getByLabel('Correo electrónico').fill('recepcion@clinica-demo.test')
  await page.getByLabel('Contraseña', { exact: true }).fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes$/)

  // Alta (CUS-14).
  await page.getByRole('link', { name: 'Registrar paciente' }).click()
  await page.getByLabel('Número de documento', { exact: true }).fill(dni)
  await page.getByLabel('Nombres', { exact: true }).fill('Lucía')
  await page.getByLabel('Apellidos', { exact: true }).fill('Sintética Prueba')
  await page.getByLabel('Fecha de nacimiento').fill('1990-01-31')
  await page.getByLabel('Sexo').selectOption('femenino')
  await page.getByLabel('Teléfono').fill('987654321')
  await page.getByRole('button', { name: 'Registrar paciente' }).click()

  const header = page.getByRole('region', { name: 'Paciente' })
  await expect(header.getByRole('heading', { name: 'Lucía Sintética Prueba' })).toBeVisible()
  await expect(header.getByText('Sin consentimiento vigente')).toBeVisible()

  // Consentimiento presencial (CUS-17): (a) obligatoria y confirmación con el documento.
  await page.getByRole('link', { name: 'Consentimiento', exact: true }).click()
  await expect(page.getByLabel('(b) Notificaciones')).not.toBeChecked()
  await page.getByLabel('(a) Atención odontológica (obligatoria)').check()
  await page.getByLabel('Documento del titular (confirmación)').fill(dni)
  await page.getByRole('button', { name: 'Registrar consentimiento' }).click()
  await expect(page.getByText('Consentimiento registrado.')).toBeVisible()
  await expect(header.getByText('Consentimiento vigente')).toBeVisible()

  // Antecedentes con aviso de alergias (RF-064, RNF-149).
  await page.getByRole('link', { name: 'Antecedentes', exact: true }).click()
  await page.getByLabel('Alergias').fill('Penicilina')
  await page.getByRole('button', { name: 'Guardar antecedentes' }).click()
  await expect(page.getByText('Antecedentes guardados.')).toBeVisible()
  await expect(header.getByText('Penicilina')).toBeVisible()
})
