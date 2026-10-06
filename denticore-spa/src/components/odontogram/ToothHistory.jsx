import { formatDateTime } from '../../ui/format'
import { FINDING_COLORS, SURFACE_NAMES, acronymOf } from './teeth'

const ENTRY_TYPES = { inicial: 'Inicial', evolucion: 'Evolución', correccion: 'Corrección' }
const ORIGINS = { manual: 'Manual', ia: 'IA', procedimiento: 'Procedimiento' }
const CORRECTION_KINDS = { anulacion: 'anulación', reemplazo: 'reemplazo' }

/**
 * Historial cronológico de una pieza (TASK-051; CUS-24; RF-081, CA-23.1): cada entrada con su
 * hallazgo, sigla y color, superficies, tipo, origen, autor con su COP y fecha. La entrada
 * corregida se muestra tachada, con la etiqueta «Corregida», el motivo y un enlace a su
 * corrección; los datos originales se conservan.
 */
export function ToothHistory({ tooth, entries }) {
  const byId = new Map(entries.map((entry) => [entry.id, entry]))

  return (
    <section aria-labelledby={`tooth-history-${tooth}`} className="flex flex-col gap-3">
      <h2 id={`tooth-history-${tooth}`} className="text-headline-md">
        Historial de la pieza {tooth}
      </h2>

      {entries.length === 0 ? (
        <p className="text-body-md text-on-surface-variant">La pieza {tooth} no tiene registros.</p>
      ) : (
        <ol className="m-0 flex list-none flex-col gap-2 p-0">
          {entries.map((entry) => {
            const correction = entry.corrected_by_id ? byId.get(entry.corrected_by_id) : null
            const corrected = Boolean(entry.corrected_by_id)
            const acronym = acronymOf(entry)
            const type =
              entry.entry_type === 'correccion'
                ? `Corrección (${CORRECTION_KINDS[entry.correction_kind] ?? entry.correction_kind})`
                : ENTRY_TYPES[entry.entry_type]

            return (
              <li
                key={entry.id}
                id={`entry-${entry.id}`}
                className="rounded-lg border border-outline-variant bg-surface-bright p-3 text-body-md"
              >
                <div className="flex flex-wrap items-center gap-2">
                  {acronym && entry.color && (
                    <span className={`font-semibold ${FINDING_COLORS[entry.color].text}`}>{acronym}</span>
                  )}
                  <span className={corrected ? 'line-through' : undefined}>
                    {entry.finding ? `${entry.finding.name} · ${entry.state?.name ?? ''}` : 'Anulación del hallazgo'}
                  </span>
                  {corrected && (
                    <span className="rounded-full bg-warning-container px-2 text-label-sm text-on-warning-container">
                      Corregida
                    </span>
                  )}
                  {entry.origin === 'ia' && (
                    <span className="rounded-full bg-ai-origin-container px-2 text-label-sm text-ai-origin">IA</span>
                  )}
                </div>

                {entry.surfaces?.length > 0 && (
                  <p className="m-0 text-on-surface-variant">
                    Superficies: {entry.surfaces.map((surface) => SURFACE_NAMES[surface]).join(', ')}
                  </p>
                )}
                {entry.note && <p className="m-0">{entry.note}</p>}
                {entry.entry_type === 'correccion' && entry.correction_reason && (
                  <p className="m-0">Motivo: {entry.correction_reason}</p>
                )}
                {corrected && (
                  <p className="m-0">
                    {correction?.correction_reason && (
                      <span>Motivo de la corrección: {correction.correction_reason}</span>
                    )}{' '}
                    <a href={`#entry-${entry.corrected_by_id}`} className="font-semibold text-secondary">
                      Ver la corrección
                    </a>
                  </p>
                )}

                <p className="m-0 text-on-surface-variant">{`${type} · ${ORIGINS[entry.origin] ?? entry.origin}`}</p>
                <p className="m-0 text-on-surface-variant">
                  {entry.author ? `${entry.author.name} · COP ${entry.author.cop}` : null}
                </p>
                <p className="m-0 text-on-surface-variant tabular-nums">{formatDateTime(entry.recorded_at)}</p>
              </li>
            )
          })}
        </ol>
      )}
    </section>
  )
}
