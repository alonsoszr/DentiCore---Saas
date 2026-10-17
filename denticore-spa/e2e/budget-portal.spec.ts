import { expect, test, type Browser } from '@playwright/test'
import { auditScreen, login, staffPage } from './support/screen'

/** El odontólogo crea y propone un plan de Pablo Paciente (semilla) y emite su presupuesto. */
async function issueBudgetForPablo(browser: Browser, width: number): Promise<string> {
  const page = await staffPage(browser, width)
  const context = page.context()
  await login(page, 'dentista@clinica-demo.test', /\/c\/clinica-demo\/app\/pacientes$/)

  await page.getByLabel('Buscar paciente').fill('70000077')
  await page.getByRole('button', { name: 'Buscar' }).click()
  await page
    .getByRole('row', { name: /Paciente, Pablo/ })
    .getByRole('link', { name: 'Ver ficha' })
    .click()
  await page.getByRole('link', { name: 'Planes', exact: true }).click()
  await page.getByRole('button', { name: 'Nuevo plan' }).click()
  await page.getByLabel('Título del plan').fill('Plan de prevención')
  await page.getByLabel('Procedimiento').first().selectOption({ label: 'PRF-01 · Profilaxis (S/ 80.00)' })
  await page.getByRole('button', { name: 'Crear plan en borrador' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/planes\/[0-9a-f-]+$/)
  await page.getByRole('button', { name: 'Proponer al paciente' }).click()
  await expect(page.getByText('Propuesto', { exact: true }).first()).toBeVisible()

  await page.getByRole('link', { name: 'Nuevo presupuesto' }).click()
  await page.getByRole('button', { name: 'Crear borrador con 1 ítem' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/presupuestos\/[0-9a-f-]+$/)
  await page.getByRole('button', { name: 'Emitir presupuesto' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar emisión' }).click()
  const heading = page.getByRole('heading', { name: /^Presupuesto P-/ })
  await expect(heading).toBeVisible()
  const number = ((await heading.textContent()) ?? '').replace('Presupuesto ', '').trim()
  await context.close()

  return number
}

// T-171 (SDD §6.3; CUS-35, CUS-37, RNF-051): la recepción (aquí, el odontólogo) emite y el
// paciente acepta desde el portal. Cada ejecución crea un plan y un presupuesto nuevos de Pablo, con
// su propio número, así que corre en toda la matriz: el portal en los 4 anchos (desde 360 px) y el
// personal a 1280 px cuando el proyecto es más angosto que 768 px.
test('issues a budget at reception and accepts it from the portal', async ({ page, browser, viewport }) => {
  test.setTimeout(180_000)

  const number = await issueBudgetForPablo(browser, viewport?.width ?? 1280)

  await login(page, 'paciente@clinica-demo.test', /\/c\/clinica-demo\/portal$/)
  await expect(page.getByRole('heading', { name: 'Hola, Pablo Paciente' })).toBeVisible()
  await expect(page.getByRole('link', { name: `Presupuesto N° ${number}` }).first()).toBeVisible()
  await auditScreen(page)

  await page
    .getByRole('navigation', { name: 'Secciones del portal' })
    .getByRole('link', { name: 'Presupuestos' })
    .click()
  await expect(page.getByRole('heading', { name: 'Presupuestos' })).toBeVisible()
  await auditScreen(page)

  await page.getByRole('link', { name: `Presupuesto N° ${number}` }).click()
  await expect(page.getByRole('heading', { name: `Presupuesto N° ${number}` })).toBeVisible()
  await expect(page.getByText('Profilaxis')).toBeVisible()
  await auditScreen(page)

  await page.getByRole('button', { name: 'Aceptar presupuesto' }).click()
  await page.getByRole('checkbox', { name: new RegExp(`He leído el presupuesto N° ${number}`) }).check()
  await auditScreen(page)
  await page.getByRole('button', { name: 'Confirmar aceptación' }).click()

  await expect(page.getByText('Presupuesto aceptado')).toBeVisible()
  await expect(page.getByText('Aceptado', { exact: true })).toBeVisible()
  await auditScreen(page)
})
