import { defineConfig, devices } from '@playwright/test'

/**
 * Pruebas E2E (TASK-020; SDD §6.1, RNF-051, RNF-052): Chrome, Edge, Firefox y WebKit a
 * 360, 768, 1280 y 1920 px, y Chrome para Android emulado, contra el entorno local (Compose + API + SPA).
 * E2E_BROWSERS y E2E_VIEWPORTS permiten acotar la matriz (p. ej. "chromium,android" y "1280").
 */
const BROWSERS = {
  chromium: devices['Desktop Chrome'],
  msedge: { ...devices['Desktop Edge'], channel: 'msedge' },
  firefox: devices['Desktop Firefox'],
  webkit: devices['Desktop Safari'],
}

const VIEWPORTS = { 360: 740, 768: 1024, 1280: 800, 1920: 1080 }

// RNF-051: el portal también en Chrome para Android. Es una emulación (Chromium con el agente, la
// pantalla de 412 px y el tacto de un Pixel 7), no un teléfono real; las pantallas del personal se
// omiten solas porque piden desde 768 px (RNF-052).
const ANDROID = { name: 'android-pixel7', use: { ...devices['Pixel 7'] } }

const pick = (value, all) => (value ? value.split(',').map((item) => item.trim()) : Object.keys(all))

const projects = pick(process.env.E2E_BROWSERS, { ...BROWSERS, android: ANDROID }).flatMap((browser) =>
  browser === 'android'
    ? [ANDROID]
    : pick(process.env.E2E_VIEWPORTS, VIEWPORTS).map((width) => ({
        name: `${browser}-${width}`,
        use: { ...BROWSERS[browser], viewport: { width: Number(width), height: VIEWPORTS[width] } },
      })),
)

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  // La API de desarrollo (`php artisan serve`) tarda hasta 2–3 s en las escrituras clínicas cuando
  // corren varios proyectos de la matriz a la vez; 5 s de espera por aserción no alcanzaban en Firefox.
  expect: { timeout: 10_000 },
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    // Navegador en español del Perú (RNF-188, RNF-189): también los mensajes nativos y los formatos.
    locale: 'es-PE',
    timezoneId: 'America/Lima',
    trace: 'retain-on-failure',
  },
  projects,
})
