// Anexo de trazabilidad del informe de aceptación de E1 (TASK-097; RNF-005, RNF-128, DD-21).
// Uso: node --test scripts/
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { annex } from './e1.mjs'

const required = { rf: ['RF-001', 'RF-002'], rn: ['RN-01'], ca: ['CA-06.1'], rnf: ['RNF-005', 'RNF-006'] }
const cited = new Map([
  ['RF-001', new Set(['denticore-api\\tests\\Feature\\B.php', 'denticore-api/tests/Feature/A.php'])],
  ['RN-01', new Set(['denticore-spa/e2e/login.spec.ts'])],
  ['CA-06.1', new Set(['denticore-api/tests/Feature/A.php'])],
])
const declared = { 'RNF-005': { verificacion: 'ci', evidencia: 'Workflow traceability.' } }

test('lists every E1 requirement with all the tests that cite it, in order and with forward slashes', () => {
  const text = annex(required, cited, declared)

  assert.match(text, /^# Anexo: trazabilidad de E1 por ID/)
  assert.ok(text.includes('| RF-001 | 2 | `denticore-api/tests/Feature/A.php`<br>`denticore-api/tests/Feature/B.php` |'))
  assert.ok(text.includes('| RN-01 | 1 | `denticore-spa/e2e/login.spec.ts` |'))
  assert.ok(text.includes('| CA-06.1 | 1 | `denticore-api/tests/Feature/A.php` |'))
})

test('marks the declared evidence and the gaps instead of hiding them', () => {
  const text = annex(required, cited, declared)

  assert.ok(text.includes('| RF-002 | 0 | **sin prueba** |'))
  assert.ok(text.includes('| RNF-005 | 0 | ci: Workflow traceability. |'))
  assert.ok(text.includes('| RNF-006 | 0 | **sin prueba** |'))
  for (const section of ['## RF', '## RN', '## CA', '## RNF']) assert.ok(text.includes(`\n${section} `), section)
})
