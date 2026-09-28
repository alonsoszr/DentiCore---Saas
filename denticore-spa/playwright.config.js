import { defineConfig, devices } from '@playwright/test'

/**
 * Pruebas E2E (TASK-020; SDD §6.1, RNF-051, RNF-052): Chrome, Edge, Firefox y WebKit a
 * 360, 768, 1280 y 1920 px, contra el entorno local (Compose + API + SPA).
 * E2E_BROWSERS y E2E_VIEWPORTS permiten acotar la matriz (p. ej. "chromium" y "1280").
 */
const BROWSERS = {
  chromium: devices['Desktop Chrome'],
  msedge: { ...devices['Desktop Edge'], channel: 'msedge' },
  firefox: devices['Desktop Firefox'],
  webkit: devices['Desktop Safari'],
}

const VIEWPORTS = { 360: 740, 768: 1024, 1280: 800, 1920: 1080 }

const pick = (value, all) => (value ? value.split(',').map((item) => item.trim()) : Object.keys(all))

const projects = pick(process.env.E2E_BROWSERS, BROWSERS).flatMap((browser) =>
  pick(process.env.E2E_VIEWPORTS, VIEWPORTS).map((width) => ({
    name: `${browser}-${width}`,
    use: { ...BROWSERS[browser], viewport: { width: Number(width), height: VIEWPORTS[width] } },
  })),
)

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    trace: 'retain-on-failure',
  },
  projects,
})
