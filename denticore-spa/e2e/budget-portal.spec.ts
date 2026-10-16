import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type Page } from '@playwright/test'

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

/** TASK-089 criterio 3 (RNF-051, RNF-052): a 360 px ninguna pantalla del portal se desplaza en horizontal. */
async function expectNoHorizontalScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow).toBeLessThanOrEqual(0)
}

async function login(page: Page, email: string, landing: RegExp) {
  await page.goto('/c/clinica-demo/login')
  await page.getByLabel('Correo electrónico').fill(email)
  await page.getByLabel('Contraseña', { exact: true }).fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(landing)
}

/** El odontólogo crea y propone un plan de Pablo Paciente (semilla) y emite su presupuesto. */
async function issueBudgetForPablo(browser: Browser): Promise<string> {
  const context = await browser.newContext()
  const page = await context.newPage()
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
// paciente acepta desde el portal. Cada ejecución crea un plan nuevo de Pablo, así que corre en
// Chrome a 360 px (el portal es móvil primero) y en el resto de proyectos solo revisa la lectura.
test('issues a budget at reception and accepts it from the portal', async ({
  page,
  browser,
  browserName,
  channel,
  viewport,
}) => {
  test.skip(
    browserName !== 'chromium' || Boolean(channel) || viewport?.width !== 360,
    'Crea datos: solo en Chrome a 360 px',
  )
  test.setTimeout(180_000)

  const number = await issueBudgetForPablo(browser)

  await login(page, 'paciente@clinica-demo.test', /\/c\/clinica-demo\/portal$/)
  await expect(page.getByRole('heading', { name: 'Hola, Pablo Paciente' })).toBeVisible()
  await expect(page.getByRole('link', { name: `Presupuesto N° ${number}` }).first()).toBeVisible()
  await expectNoHorizontalScroll(page)
  await expectNoAxeViolations(page)

  await page
    .getByRole('navigation', { name: 'Secciones del portal' })
    .getByRole('link', { name: 'Presupuestos' })
    .click()
  await expect(page.getByRole('heading', { name: 'Presupuestos' })).toBeVisible()
  await expectNoHorizontalScroll(page)
  await expectNoAxeViolations(page)

  await page.getByRole('link', { name: `Presupuesto N° ${number}` }).click()
  await expect(page.getByRole('heading', { name: `Presupuesto N° ${number}` })).toBeVisible()
  await expect(page.getByText('Profilaxis')).toBeVisible()
  await expectNoHorizontalScroll(page)
  await expectNoAxeViolations(page)

  await page.getByRole('button', { name: 'Aceptar presupuesto' }).click()
  await page.getByRole('checkbox', { name: new RegExp(`He leído el presupuesto N° ${number}`) }).check()
  await expectNoAxeViolations(page)
  await page.getByRole('button', { name: 'Confirmar aceptación' }).click()

  await expect(page.getByText('Presupuesto aceptado')).toBeVisible()
  await expect(page.getByText('Aceptado', { exact: true })).toBeVisible()
  await expectNoAxeViolations(page)
})
