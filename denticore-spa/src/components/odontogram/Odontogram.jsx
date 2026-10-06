import { useRef, useState } from 'react'
import { TOOTH_HEIGHT, TOOTH_WIDTH, Tooth } from './Tooth'
import { FINDING_COLORS, SURFACE_NAMES, rowsFor, surfacesFor, toothGeometry } from './teeth'

const GAP = 4
const MIDLINE_GAP = 12
const ROW_GAP = 16
const ARCH_GAP = 28
const WIDTH = 16 * TOOTH_WIDTH + 14 * GAP + MIDLINE_GAP
const SURFACE_KEYS = Object.keys(SURFACE_NAMES)

export const LEGEND =
  'Rojo: patología o mal estado · Azul: buen estado o tratamiento realizado · Siglas según NTS N° 188'

/** Posición de cada pieza visible: fila, índice y coordenadas (filas centradas como en el anexo). */
function layout(rows) {
  const positions = new Map()
  let y = 0
  rows.forEach((row, rowIndex) => {
    if (rowIndex > 0) y += rows[rowIndex - 1].upper && !row.upper ? ARCH_GAP : ROW_GAP
    const half = row.teeth.length / 2
    const rowWidth = row.teeth.length * TOOTH_WIDTH + (row.teeth.length - 2) * GAP + MIDLINE_GAP
    const offset = (WIDTH - rowWidth) / 2
    row.teeth.forEach((tooth, index) => {
      const x = offset + index * (TOOTH_WIDTH + GAP) + (index >= half ? MIDLINE_GAP - GAP : 0)
      positions.set(tooth, { rowIndex, index, x, y })
    })
    y += TOOTH_HEIGHT
  })
  return { positions, height: y }
}

/** Símbolo de un hallazgo de tramo entre dos piezas (§6.1.1, 6.1.2, 6.1.6, 6.1.7, 6.1.11, 6.1.26, 6.1.29 a 6.1.31, 6.1.38). */
function spanShape(code, from, to, tooth, rowY) {
  const g = toothGeometry(tooth)
  const out = g.upper ? 1 : -1
  const apex = rowY + g.apex
  const number = rowY + g.number - 4
  const crown = rowY + (g.crownTop + g.crownBottom) / 2
  const [x1, x2] = [from.x + TOOTH_WIDTH / 2, to.x + TOOTH_WIDTH / 2]
  const middle = (x1 + x2) / 2
  const level = apex

  switch (code) {
    case 'ORTODONCIA_FIJA':
      return (
        <>
          {[x1, x2].map((x) => (
            <path key={x} d={`M${x - 4} ${level - 4} h8 v8 h-8 Z M${x} ${level - 4} v8 M${x - 4} ${level} h8`} />
          ))}
          <line x1={x1 + 4} y1={level} x2={x2 - 4} y2={level} />
        </>
      )
    case 'ORTODONCIA_REMOVIBLE': {
      const steps = Math.max(2, Math.round((x2 - x1) / 10))
      const width = (x2 - x1) / steps
      const zigzag = Array.from(
        { length: steps },
        (_, i) => `L${x1 + (i + 0.5) * width} ${level - out * 5} L${x1 + (i + 1) * width} ${level}`,
      )
      return <path d={`M${x1} ${level} ${zigzag.join(' ')}`} />
    }
    case 'PROTESIS_FIJA':
      return <path d={`M${x1} ${apex} V${level - out * 4} H${x2} V${apex}`} />
    case 'PROTESIS_COMPLETA':
    case 'PROTESIS_REMOVIBLE':
      return (
        <>
          <line x1={x1 - 16} y1={level} x2={x2 + 16} y2={level} />
          <line x1={x1 - 16} y1={level - out * 4} x2={x2 + 16} y2={level - out * 4} />
        </>
      )
    case 'EDENTULO_TOTAL':
      return <line x1={x1 - 18} y1={crown} x2={x2 + 18} y2={crown} />
    case 'DIASTEMA':
      return (
        <path
          d={`M${middle - 1} ${crown - 14} Q${middle + 5} ${crown} ${middle - 1} ${crown + 14} M${middle + 1} ${crown - 14} Q${middle - 5} ${crown} ${middle + 1} ${crown + 14}`}
        />
      )
    case 'FUSION':
      return (
        <>
          <ellipse cx={x1 + 6} cy={number} rx="16" ry="9" />
          <ellipse cx={x2 - 6} cy={number} rx="16" ry="9" />
        </>
      )
    case 'SUPERNUMERARIA':
      return <circle cx={middle} cy={level} r="7" />
    case 'TRANSPOSICION':
      return (
        <path
          d={`M${x1 + 8} ${number + 3} Q${middle} ${number - 9} ${x2 - 8} ${number + 3} M${x2 - 13} ${number + 1} L${x2 - 8} ${number + 3} L${x2 - 10} ${number - 2} M${x2 - 8} ${number - 3} Q${middle} ${number + 9} ${x1 + 8} ${number - 3} M${x1 + 13} ${number - 1} L${x1 + 8} ${number - 3} L${x1 + 10} ${number + 2}`}
        />
      )
    default:
      return <line x1={x1} y1={level} x2={x2} y2={level} />
  }
}

/**
 * Odontograma NTS 188 (TASK-051; CUS-21, CUS-22; RF-077, RNF-061, RNF-151): las piezas de la
 * dentición elegida con sus hallazgos, en el orden del anexo gráfico. La selección de pieza y
 * de superficies ocurre en el cliente, sin viaje al servidor; con el teclado, la pieza se elige
 * por su número (dos dígitos), la superficie por su letra (M, D, O, I, V, L, P) y las flechas
 * recorren las piezas.
 */
export function Odontogram({
  entries = [],
  dentition = 'permanente',
  selectedTooth = null,
  selectedSurfaces = [],
  onSelectTooth,
  onToggleSurface,
  label = 'Odontograma',
}) {
  const rows = rowsFor(dentition)
  const { positions, height } = layout(rows)
  const [focusedTooth, setFocusedTooth] = useState(null)
  const [announcement, setAnnouncement] = useState('')
  const digit = useRef(null)
  const toothRefs = useRef(new Map())

  const spans = entries.filter((entry) => entry.tooth_end && entry.finding && entry.color && positions.has(entry.tooth))
  const toothEntries = (tooth) => entries.filter((entry) => !entry.tooth_end && entry.tooth === tooth)
  const spanEntries = (tooth) => spans.filter((entry) => entry.tooth === tooth)
  const rovingTooth = focusedTooth ?? selectedTooth ?? rows[0].teeth[0]

  const focusTooth = (tooth) => {
    setFocusedTooth(tooth)
    toothRefs.current.get(tooth)?.focus()
  }

  const select = (tooth) => {
    onSelectTooth?.(tooth)
    setAnnouncement(`Pieza ${tooth} seleccionada`)
  }

  const move = (rowDelta, indexDelta) => {
    const current = positions.get(rovingTooth)
    if (!current) return
    const row = rows[current.rowIndex + rowDelta]
    if (!row) return
    const index = Math.min(Math.max(current.index + indexDelta, 0), row.teeth.length - 1)
    const target =
      rowDelta === 0
        ? row.teeth[index]
        : row.teeth[Math.round((current.index / (rows[current.rowIndex].teeth.length - 1)) * (row.teeth.length - 1))]
    focusTooth(target)
  }

  const handleKeyDown = (event) => {
    if (/^[0-9]$/.test(event.key)) {
      event.preventDefault()
      const value = Number(event.key)
      if (digit.current === null) {
        if (value >= 1 && value <= 8) digit.current = value
        return
      }
      const tooth = digit.current * 10 + value
      digit.current = null
      if (positions.has(tooth)) {
        focusTooth(tooth)
        select(tooth)
      }
      return
    }

    const letter = event.key.length === 1 ? event.key.toUpperCase() : null
    if (letter && SURFACE_KEYS.includes(letter) && selectedTooth !== null) {
      event.preventDefault()
      if (surfacesFor(selectedTooth).includes(letter)) {
        onToggleSurface?.(letter)
        setAnnouncement(`Superficie ${SURFACE_NAMES[letter]} de la pieza ${selectedTooth}`)
      } else {
        setAnnouncement(`La superficie ${SURFACE_NAMES[letter]} no aplica a la pieza ${selectedTooth}`)
      }
      return
    }

    const moves = { ArrowRight: [0, 1], ArrowLeft: [0, -1], ArrowUp: [-1, 0], ArrowDown: [1, 0] }
    if (moves[event.key]) {
      event.preventDefault()
      move(...moves[event.key])
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      select(rovingTooth)
    } else if (event.key === 'Escape') {
      digit.current = null
    }
  }

  return (
    <figure className="m-0 flex flex-col gap-3">
      <div onKeyDown={handleKeyDown} className="overflow-x-auto">
        <svg
          role="group"
          aria-label={`${label}. Elija la pieza con su número y la superficie con su letra.`}
          viewBox={`-4 -4 ${WIDTH + 8} ${height + 8}`}
          className="h-auto w-full min-w-[640px] max-w-[960px]"
        >
          {rows.map((row) =>
            row.teeth.map((tooth) => {
              const position = positions.get(tooth)
              return (
                <Tooth
                  key={tooth}
                  tooth={tooth}
                  x={position.x}
                  y={position.y}
                  entries={toothEntries(tooth)}
                  spanEntries={spanEntries(tooth)}
                  selected={selectedTooth === tooth}
                  selectedSurfaces={selectedTooth === tooth ? selectedSurfaces : []}
                  focusable={rovingTooth === tooth}
                  onSelect={select}
                  onFocus={setFocusedTooth}
                  onToggleSurface={onToggleSurface}
                  toothRef={(element) =>
                    element ? toothRefs.current.set(tooth, element) : toothRefs.current.delete(tooth)
                  }
                />
              )
            }),
          )}

          {spans.map((entry) => {
            const from = positions.get(entry.tooth)
            const to = positions.get(entry.tooth_end) ?? from
            const [start, end] = from.x <= to.x ? [from, to] : [to, from]
            const colors = FINDING_COLORS[entry.color]
            return (
              <g
                key={entry.id}
                role="img"
                aria-label={`${entry.finding.name}, ${entry.state.name.toLowerCase()}: piezas ${entry.tooth} a ${entry.tooth_end}`}
                className={`${colors.stroke} fill-none`}
                strokeWidth="2"
                strokeLinecap="round"
                pointerEvents="none"
              >
                {spanShape(entry.finding.code, start, end, entry.tooth, from.y)}
                {entry.finding.code === 'SUPERNUMERARIA' && (
                  <text
                    x={(start.x + end.x) / 2 + TOOTH_WIDTH / 2}
                    y={from.y + toothGeometry(entry.tooth).apex + 3}
                    textAnchor="middle"
                    fontSize="9"
                    fontWeight="600"
                    stroke="none"
                    className={colors.fill}
                  >
                    S
                  </text>
                )}
              </g>
            )
          })}
        </svg>
      </div>
      <figcaption className="text-body-md text-on-surface-variant">{LEGEND}</figcaption>
      <p role="status" aria-live="polite" className="sr-only">
        {announcement}
      </p>
    </figure>
  )
}
