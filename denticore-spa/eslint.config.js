import js from '@eslint/js'
import prettier from 'eslint-config-prettier'
import react from 'eslint-plugin-react'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import globals from 'globals'

// ESLint de la SPA (TASK-003; SDD §6.4: 0 errores). Prettier maneja el formato.
export default [
  { ignores: ['dist', 'coverage', 'playwright-report', 'test-results'] },
  { settings: { react: { version: 'detect' } } },
  js.configs.recommended,
  react.configs.flat.recommended,
  react.configs.flat['jsx-runtime'],
  reactHooks.configs.flat.recommended,
  {
    files: ['**/*.{js,jsx}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: { ...globals.browser },
      parserOptions: { ecmaFeatures: { jsx: true } },
    },
    plugins: { 'react-refresh': reactRefresh },
    rules: {
      'react/prop-types': 'off',
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
    },
  },
  {
    files: ['**/*.test.{js,jsx}', 'src/test/**', '*.config.js', 'scripts/**', 'e2e/**'],
    languageOptions: { globals: { ...globals.node } },
    // Fast refresh no aplica a pruebas ni scripts.
    rules: { 'react-refresh/only-export-components': 'off' },
  },
  prettier,
]
