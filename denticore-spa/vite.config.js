import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  test: {
    environment: 'jsdom',
    pool: 'threads',
    include: ['src/**/*.test.{js,jsx}'],
    setupFiles: ['./src/test/setup.js'],
    restoreMocks: true,
    // Las pruebas que teclean formularios completos superan los 5 s por defecto en equipos
    // cargados; un tecleo que se corta además escribe en la prueba siguiente.
    testTimeout: 15000,
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{js,jsx}'],
      exclude: ['src/main.jsx', 'src/test/**', 'src/**/*.test.{js,jsx}'],
      reporter: ['text-summary', 'lcov'],
      // SDD §6.4 (RNF-126): cobertura del frontend ≥ 70 %.
      thresholds: { lines: 70 },
    },
  },
})
