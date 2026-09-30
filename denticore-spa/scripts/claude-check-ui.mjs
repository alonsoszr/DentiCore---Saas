// Hook PostToolUse de Claude Code (.claude/settings.json). Tras cada edición de un archivo de src/,
// ejecuta ESLint y check-tokens sobre ese archivo. Con errores sale con código 2: Claude Code
// le muestra el detalle a Claude para que lo corrija antes de seguir.
import { spawnSync } from 'node:child_process'
import { extname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { checkFiles, formatViolations } from './check-tokens.mjs'

const ROOT = resolve(fileURLToPath(new URL('..', import.meta.url)))

let input = ''
for await (const chunk of process.stdin) input += chunk

let filePath
try {
  filePath = JSON.parse(input).tool_input?.file_path
} catch {
  process.exit(0)
}
if (!filePath) process.exit(0)

const file = resolve(filePath)
const rel = relative(ROOT, file)
if (rel.startsWith('..') || rel.split(sep)[0] !== 'src' || !['.js', '.jsx', '.css'].includes(extname(file))) {
  process.exit(0)
}

const problems = []

const violations = checkFiles([file])
if (violations.length > 0) problems.push(`check:tokens\n${formatViolations(violations)}`)

if (extname(file) !== '.css') {
  const eslint = spawnSync(process.execPath, [join(ROOT, 'node_modules', 'eslint', 'bin', 'eslint.js'), file], {
    cwd: ROOT,
    encoding: 'utf8',
  })
  if (eslint.status !== 0) problems.push(`ESLint\n${(eslint.stdout || eslint.stderr).trim()}`)
}

if (problems.length > 0) {
  console.error(`Errores en ${rel}. Corrígelos antes de continuar:\n\n${problems.join('\n\n')}`)
  process.exit(2)
}
