// Genera src/api/schemas.js con esquemas zod desde el contrato OpenAPI 3.1 de la API
// (TASK-018; SDD §1.10, RNF-064): los mismos límites que los Form Requests y la forma de
// los Resources. Uso: npm run gen:api [ruta/al/openapi.json]
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { jsonSchemaToZod } from 'json-schema-to-zod'

const root = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const source = resolve(root, process.argv[2] ?? '../denticore-api/openapi.json')
const target = resolve(root, 'src/api/schemas.js')

const document = JSON.parse(readFileSync(source, 'utf8'))
const schemas = document.components?.schemas ?? {}

/** Reemplaza los $ref a #/components/schemas/X por el esquema referido. */
function dereference(node, seen = []) {
  if (Array.isArray(node)) return node.map((item) => dereference(item, seen))
  if (!node || typeof node !== 'object') return node
  if (typeof node.$ref === 'string') {
    const name = node.$ref.replace('#/components/schemas/', '')
    if (seen.includes(name)) return {}
    return dereference(schemas[name], [...seen, name])
  }
  return Object.fromEntries(Object.entries(node).map(([key, value]) => [key, dereference(value, seen)]))
}

const exportName = (name) => `${name.charAt(0).toLowerCase()}${name.slice(1)}Schema`

const body = Object.keys(schemas)
  .sort()
  .map((name) => {
    const schema = dereference(schemas[name], [name])
    return `export const ${exportName(name)} = ${jsonSchemaToZod(schema, { module: 'none' })}`
  })
  .join('\n\n')

const output = `// Archivo generado por scripts/gen-api.mjs desde denticore-api/openapi.json. No editar a mano.
import { z } from 'zod'

${body}
`

writeFileSync(target, output)
console.log(`Esquemas generados: ${Object.keys(schemas).length} → ${target}`)
