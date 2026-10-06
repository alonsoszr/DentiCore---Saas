import {
  FINDING_COLORS,
  SURFACE_NAMES,
  acronymOf,
  describeEntry,
  isAnterior,
  mesialIsRight,
  rootCount,
  toothGeometry,
} from './teeth'

export const TOOTH_WIDTH = 40
export const TOOTH_HEIGHT = 134

/** Hallazgos que se dibujan pintando las superficies (§6.1.16, §6.1.33, §6.1.36). */
const FILLED_SURFACES = new Set(['CARIES', 'RESTAURACION', 'DESGASTE'])
/** Hallazgos que se dibujan con el contorno de las superficies (§6.1.34). */
const OUTLINED_SURFACES = new Set(['RESTAURACION_TEMP'])

/** Polígonos de las 5 superficies (lista de verificación C5). */
function surfacePolygons(tooth, g) {
  const [x0, x1, y0, y1] = [2, 38, g.crownTop, g.crownBottom]
  const anterior = isAnterior(tooth)
  const [ix0, ix1] = anterior ? [9, 31] : [13, 27]
  const middle = (y0 + y1) / 2
  const [iy0, iy1] = anterior ? [middle - 3, middle + 3] : [middle - 7, middle + 7]
  const points = (...pairs) => pairs.map(([x, y]) => `${x},${y}`).join(' ')
  const right = mesialIsRight(tooth) ? 'M' : 'D'
  const left = right === 'M' ? 'D' : 'M'

  return [
    [g.upper ? 'V' : 'L', points([x0, y0], [x1, y0], [ix1, iy0], [ix0, iy0])],
    [g.upper ? 'P' : 'V', points([x0, y1], [x1, y1], [ix1, iy1], [ix0, iy1])],
    [right, points([x1, y0], [x1, y1], [ix1, iy1], [ix1, iy0])],
    [left, points([x0, y0], [x0, y1], [ix0, iy1], [ix0, iy0])],
    [anterior ? 'I' : 'O', points([ix0, iy0], [ix1, iy0], [ix1, iy1], [ix0, iy1])],
  ]
}

/** Raíces como triángulos desde el borde de la corona hasta el ápice (C6). */
function roots(tooth, g) {
  const count = rootCount(tooth)
  const width = 28 / count
  return Array.from({ length: count }, (_, index) => {
    const left = 6 + index * width
    return `${left},${g.edge} ${left + width},${g.edge} ${left + width / 2},${g.apex}`
  })
}

/** Flecha vertical de `from` a `to`, con la punta en `to`. */
function arrow(from, to) {
  const tip = to > from ? -4 : 4
  return `M20 ${from} L20 ${to} M16 ${to + tip} L20 ${to} L24 ${to + tip}`
}

/** Símbolo gráfico de un hallazgo de pieza (§6.1). Los de solo sigla no dibujan nada. */
function glyph(code, g) {
  const out = g.upper ? 1 : -1
  const occlusalOut = g.occlusal + out * 4
  switch (code) {
    case 'CORONA':
    case 'CORONA_TEMPORAL':
      return <rect x="0.5" y={g.crownTop - 1.5} width="39" height="39" />
    case 'ESPIGO_MUNON':
      return (
        <>
          <line x1="20" y1={g.apex + out * 4} x2="20" y2={g.edge} />
          <rect x="15" y={(g.crownTop + g.crownBottom) / 2 - 5} width="10" height="10" />
        </>
      )
    case 'FRACTURA':
      return <line x1="9" y1={(g.apex + g.edge) / 2} x2="31" y2={g.occlusal - out * 6} />
    case 'AUSENTE':
      return (
        <>
          <line x1="4" y1={g.apex} x2="36" y2={g.occlusal} />
          <line x1="36" y1={g.apex} x2="4" y2={g.occlusal} />
        </>
      )
    case 'GEMINACION':
      return <circle cx="20" cy={g.number - 4} r="10" />
    case 'GIROVERSION':
      return (
        <path
          d={`M9 ${occlusalOut} Q20 ${occlusalOut + out * 8} 31 ${occlusalOut} M27 ${occlusalOut - 3} L31 ${occlusalOut} L27 ${occlusalOut + 3}`}
        />
      )
    case 'CLAVIJA':
      return <polygon points={`14,${g.apex - out * 2} 26,${g.apex - out * 2} 20,${g.apex - out * 10}`} />
    case 'EN_ERUPCION':
      return (
        <path
          d={`M20 ${g.apex} L14 ${g.apex + out * 10} L26 ${g.apex + out * 20} L14 ${g.apex + out * 30} L26 ${g.apex + out * 40} L20 ${g.occlusal - out * 4} M16 ${g.occlusal - out * 8} L20 ${g.occlusal - out * 4} L24 ${g.occlusal - out * 8}`}
        />
      )
    case 'EXTRUIDA':
      return <path d={arrow(g.occlusal + out * 3, g.occlusal + out * 15)} />
    case 'INTRUIDA':
      return <path d={arrow(g.occlusal + out * 15, g.occlusal + out * 3)} />
    case 'PULPOTOMIA':
      return <rect x="15" y={(g.crownTop + g.crownBottom) / 2 - 5} width="10" height="10" data-filled="true" />
    case 'TRATAMIENTO_CONDUCTO':
      return <line x1="20" y1={g.apex + out * 4} x2="20" y2={g.edge} />
    case 'SELLANTE': {
      const middle = (g.crownTop + g.crownBottom) / 2
      return <path d={`M12 ${middle} L28 ${middle} M20 ${middle - 7} L20 ${middle + 7}`} />
    }
    default:
      return null
  }
}

/**
 * Pieza del odontograma NTS 188 (TASK-051; RF-077, RNF-061, RNF-151): corona de 5 superficies,
 * raíces, número, recuadro de siglas y el símbolo de cada hallazgo en azul o rojo. Cada
 * hallazgo con color muestra su sigla oficial en el recuadro; los que no tienen sigla se
 * reconocen por su símbolo y por el nombre accesible de la pieza.
 */
export function Tooth({
  tooth,
  entries,
  spanEntries = [],
  x = 0,
  y = 0,
  selected = false,
  selectedSurfaces = [],
  focusable = false,
  onSelect,
  onToggleSurface,
  onFocus,
  toothRef,
}) {
  const g = toothGeometry(tooth)

  const surfacePaint = {}
  for (const entry of entries) {
    if (!entry.finding || !entry.color) continue
    const mode = FILLED_SURFACES.has(entry.finding.code)
      ? 'fill'
      : OUTLINED_SURFACES.has(entry.finding.code)
        ? 'outline'
        : null
    if (mode) entry.surfaces.forEach((surface) => (surfacePaint[surface] = { mode, color: entry.color }))
  }

  const acronyms = [...entries, ...spanEntries]
    .filter((entry) => entry.color && acronymOf(entry))
    .map((entry) => ({ id: entry.id, text: acronymOf(entry), color: entry.color }))

  const described = [...entries, ...spanEntries].map(describeEntry)
  const label = `Pieza ${tooth}${described.length ? `: ${described.join('; ')}` : ': sin hallazgos'}`
  const boxY = g.box

  return (
    <g
      ref={toothRef}
      transform={`translate(${x} ${y})`}
      role="button"
      tabIndex={focusable ? 0 : -1}
      aria-label={label}
      aria-pressed={selected}
      data-tooth={tooth}
      className="group cursor-pointer outline-none"
      onClick={() => onSelect?.(tooth)}
      onFocus={() => onFocus?.(tooth)}
    >
      <rect
        x="-2"
        y="-2"
        width={TOOTH_WIDTH + 4}
        height={TOOTH_HEIGHT + 4}
        rx="6"
        className={`fill-transparent stroke-focus-ring ${selected ? 'opacity-100' : 'opacity-0 group-focus-visible:opacity-100'}`}
        strokeWidth="2"
        strokeDasharray={selected ? undefined : '4 3'}
      />

      <g data-testid={`acronyms-${tooth}`}>
        <rect x="1" y={boxY} width="38" height="22" className="fill-surface-bright stroke-outline" />
        {acronyms.slice(0, 4).map((acronym, index) => (
          <text
            key={acronym.id}
            x={acronyms.length === 1 ? 20 : index % 2 === 0 ? 11 : 29}
            y={boxY + (acronyms.length <= 2 ? 15 : index < 2 ? 10 : 20)}
            textAnchor="middle"
            fontSize={acronyms.length === 1 ? 10 : 8}
            fontWeight="600"
            className={FINDING_COLORS[acronym.color].fill}
          >
            {acronym.text}
          </text>
        ))}
      </g>

      <text x="20" y={g.number} textAnchor="middle" fontSize="10" className="fill-on-surface tabular-nums">
        {tooth}
      </text>

      {roots(tooth, g).map((points) => (
        <polygon key={points} points={points} className="fill-transparent stroke-outline" strokeWidth="1" />
      ))}

      {surfacePolygons(tooth, g).map(([surface, points]) => {
        const paint = surfacePaint[surface]
        const isSelected = selectedSurfaces.includes(surface)
        const colorClasses = !paint
          ? 'fill-transparent stroke-outline'
          : paint.mode === 'fill'
            ? `${FINDING_COLORS[paint.color].fill} stroke-outline`
            : `fill-transparent ${FINDING_COLORS[paint.color].stroke}`
        return (
          <polygon
            key={surface}
            points={points}
            data-testid={`surface-${tooth}-${surface}`}
            data-selected={isSelected}
            className={isSelected ? `${colorClasses} stroke-focus-ring` : colorClasses}
            strokeWidth={isSelected || paint?.mode === 'outline' ? 2 : 1}
            onClick={(event) => {
              if (!selected || !onToggleSurface) return
              event.stopPropagation()
              onToggleSurface(surface)
            }}
          >
            <title>{`Superficie ${SURFACE_NAMES[surface]}`}</title>
          </polygon>
        )
      })}

      {entries.map((entry) => {
        if (!entry.finding || !entry.color) return null
        const shape = glyph(entry.finding.code, g)
        if (!shape) return null
        const colors = FINDING_COLORS[entry.color]
        const filled = entry.finding.code === 'PULPOTOMIA'
        return (
          <g
            key={entry.id}
            data-testid={`glyph-${tooth}-${entry.finding.code}`}
            className={`${colors.stroke} ${filled ? colors.fill : 'fill-none'}`}
            strokeWidth="2"
            strokeLinecap="round"
            pointerEvents="none"
          >
            {shape}
          </g>
        )
      })}
    </g>
  )
}
