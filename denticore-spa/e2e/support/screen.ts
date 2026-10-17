import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type Locator, type Page } from '@playwright/test'
import { randomInt } from 'node:crypto'

/**
 * Revisiones comunes de cada pantalla que recorren los E2E (TASK-094; RNF-051, RNF-052, RNF-188).
 * Cada flujo corre en la matriz 4 × 4 de playwright.config.js (Chrome, Edge, Firefox y WebKit a
 * 360, 768, 1280 y 1920 px).
 */

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

/** Ancho mínimo de la aplicación del personal (RNF-052): por debajo solo se usan el login y el portal. */
export const STAFF_MIN_WIDTH = 768

/** Los flujos del personal se saltan en los proyectos de menos de 768 px. */
export function staffOnly() {
  test.skip(({ viewport }) => (viewport?.width ?? 0) < STAFF_MIN_WIDTH, 'El personal usa la aplicación desde 768 px')
}

/**
 * Palabras de interfaz en inglés (RNF-188): textos que no existen en español del Perú y delatan una
 * cadena sin traducir, un mensaje del navegador o un valor sin formatear. Se comparan como palabras
 * completas y respetando mayúsculas, para no confundirlas con nombres o siglas.
 */
const ENGLISH_UI = [
  'Loading',
  'Submit',
  'Cancel',
  'Save',
  'Delete',
  'Edit',
  'Search',
  'Next',
  'Previous',
  'Back',
  'Close',
  'Required',
  'Invalid',
  'Sign in',
  'Sign out',
  'Log in',
  'Log out',
  'Logout',
  'Password',
  'Username',
  'Settings',
  'Dashboard',
  'Welcome',
  'Please',
  'Something went wrong',
  'Not found',
  'Unauthorized',
  'Forbidden',
  'Show more',
  'Show less',
  'Select',
  'Choose',
  'Upload',
  'Download',
  'Confirm',
  'Continue',
  'Today',
  'Tomorrow',
  'Yesterday',
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
  'January',
  'February',
  'March',
  'April',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
  'The field',
  'is required',
  'must be',
  'Untitled',
  'undefined',
  'null',
  'NaN',
  'Invalid Date',
  '[object Object]',
]

const ENGLISH_PATTERN = new RegExp(
  `(?<![\\p{L}\\p{N}])(${ENGLISH_UI.map((word) => word.replace(/[[\]]/g, '\\$&')).join('|')})(?![\\p{L}\\p{N}])`,
  'gu',
)

/** Palabras de interfaz en inglés que aparecen en `text`. */
export function englishWords(text: string): string[] {
  return [...new Set([...text.matchAll(ENGLISH_PATTERN)].map((match) => match[1]))]
}

/** Texto visible de la pantalla más los nombres accesibles que no se ven (aria-label, title, placeholder, alt). */
async function screenText(page: Page): Promise<string> {
  return page.evaluate(() => {
    const attributes = [...document.querySelectorAll('[aria-label], [title], [placeholder], img[alt]')].flatMap(
      (element) => ['aria-label', 'title', 'placeholder', 'alt'].map((name) => element.getAttribute(name) ?? ''),
    )
    return [document.title, document.body.innerText, ...attributes].join('\n')
  })
}

export async function expectSpanishText(page: Page) {
  expect(englishWords(await screenText(page)), `Textos en inglés en ${page.url()}`).toEqual([])
}

export async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

/** RNF-052: ninguna pantalla se desplaza en horizontal en su ancho de uso. */
export async function expectNoHorizontalScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow, `Desplazamiento horizontal en ${page.url()}`).toBeLessThanOrEqual(0)
}

/** Las tres revisiones de cada pantalla: WCAG 2.1 A/AA, textos en español y sin desplazamiento horizontal. */
export async function auditScreen(page: Page) {
  await expectNoAxeViolations(page)
  await expectSpanishText(page)
  await expectNoHorizontalScroll(page)
}

/** Inicia sesión en la clínica de demostración (semilla local, datos sintéticos). */
export async function login(page: Page, email: string, landing = /\/c\/clinica-demo\/app\/pacientes$/) {
  await page.goto('/c/clinica-demo/login')
  await page.getByLabel('Correo electrónico').fill(email)
  await page.getByLabel('Contraseña', { exact: true }).fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(landing)
}

/**
 * Página nueva del personal en otro contexto (otra sesión). Hereda las opciones del proyecto, pero
 * en los proyectos de menos de 768 px usa 1280 px: el personal no trabaja en anchos de teléfono.
 */
export async function staffPage(browser: Browser, width: number): Promise<Page> {
  const context = await browser.newContext(width < STAFF_MIN_WIDTH ? { viewport: { width: 1280, height: 800 } } : {})
  return context.newPage()
}

/** Minutos que faltan, en la hora de Lima, para el horario «HH:MM» de hoy. */
function minutesUntil(time: string): number {
  const [hours, minutes] = time.split(':').map(Number)
  const now = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'America/Lima',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  })
    .format(new Date())
    .split(':')
    .map(Number)
  return hours * 60 + minutes - (now[0] * 60 + now[1])
}

/** Índices de los horarios de `slots` que empiezan dentro del rango [desde, hasta) en minutos. */
async function slotsBetween(slots: Locator, from: number, to: number): Promise<number[]> {
  const times = await slots.allInnerTexts()
  return times.flatMap((text, index) => {
    const time = text.match(/\d{2}:\d{2}/)?.[0]
    const minutes = time ? minutesUntil(time) : -1
    return minutes >= from && minutes < to ? [index] : []
  })
}

/**
 * Horarios de hoy con los que trabajan los flujos. El único odontólogo de la semilla tiene horarios
 * cada 15 min (AvailabilityCalculator::STEP_MINUTES) y la llegada se registra desde 60 min antes del
 * inicio (RN-50): la primera hora queda para T-170, el único flujo que registra la llegada, y el
 * resto del día para las demás reservas.
 */
export const CHECK_IN_WINDOW = { from: 0, to: 60 }
export const LATER_TODAY = { from: 60, to: 24 * 60 }

/** ¿Hay horarios libres de hoy en el rango? */
export async function hasSlotsBetween(slots: Locator, range: { from: number; to: number }): Promise<boolean> {
  await expect(slots.first().or(slots.page().getByText('No hay horarios libres ese día.'))).toBeVisible()
  return (await slotsBetween(slots, range.from, range.to)).length > 0
}

/**
 * Reserva uno de los horarios libres de `slots` dentro de `range` (CUS-47). Los proyectos de la
 * matriz reservan el mismo día a la vez: se elige un horario al azar y, si otro lo tomó antes (409,
 * RN-46), la pantalla vuelve a mostrar los libres y se prueba con otro.
 */
export async function reserveFreeSlot(
  page: Page,
  slots: Locator,
  reserve: Locator,
  range: { from: number; to: number },
) {
  for (let attempt = 0; attempt < 5; attempt += 1) {
    await expect(slots.first()).toBeVisible()
    const candidates = await slotsBetween(slots, range.from, range.to)
    expect(candidates.length, 'Horarios libres en el rango').toBeGreaterThan(0)
    await slots.nth(candidates[randomInt(candidates.length)]).click()
    const [response] = await Promise.all([
      page.waitForResponse(
        (candidate) => candidate.request().method() === 'POST' && /\/api\/v1\/appointments$/.test(candidate.url()),
      ),
      reserve.click(),
    ])
    if (response.status() !== 409) {
      expect(response.status()).toBe(201)
      return
    }
  }
  throw new Error('No se pudo reservar un horario libre en 5 intentos')
}
