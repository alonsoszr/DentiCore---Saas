import { AlertTriangleIcon, InfoIcon } from '../../ui/icons'
import { formatDate, formatDateTime, formatPercent } from '../../ui/format'
import { RiskLevelBadge } from './RiskLevelBadge'

/** Contribuciones de una dirección con barra proporcional y valor visible (no solo color). */
function Factors({ title, items, direction, scale }) {
  return (
    <div>
      <h3 className="text-title-md">{title}</h3>
      {items.length === 0 ? (
        <p className="muted">Ninguna variable en esta dirección.</p>
      ) : (
        <ul className="m-0 list-none p-0">
          {items.map((item) => {
            const width = scale > 0 ? Math.max(4, Math.round((Math.abs(Number(item.shap)) / scale) * 100)) : 0
            return (
              <li key={item.feature} className="risk-factor">
                <span>
                  {item.label}: <strong>{item.value}</strong>
                </span>
                <span className="muted">
                  {direction === 'increasing' ? '+' : '−'}
                  {Math.abs(Number(item.shap)).toFixed(2)}
                </span>
                <span className={`risk-bar risk-bar-${direction}`} aria-hidden="true">
                  <span style={{ width: `${width}%` }} />
                </span>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

/**
 * Tarjeta del riesgo de caries (DESIGN.md › Caries risk card; CUS-56; RN-62, RN-63): nivel con
 * texto e ícono, probabilidad (solo el odontólogo, SDD §3.5), confianza, versión y fecha del
 * modelo, las 3 contribuciones a favor y en contra con barras, la leyenda fija y el aviso
 * «Baja confianza» bajo 0,60. El administrador ve la explicación global en su lugar.
 */
export function RiskCard({ prediction, title = 'Riesgo de caries' }) {
  const increasing = prediction.top_increasing
  const decreasing = prediction.top_decreasing
  const individual = increasing !== undefined
  const scale = individual
    ? Math.max(0, ...[...increasing, ...decreasing].map((item) => Math.abs(Number(item.shap))))
    : 0

  return (
    <section className="card" aria-labelledby="risk-card-title">
      <div className="flex flex-wrap items-center gap-3">
        <h2 id="risk-card-title" className="m-0">
          {title}
        </h2>
        <RiskLevelBadge level={prediction.level} />
        {prediction.low_confidence && (
          <span className="badge badge-warning">
            <AlertTriangleIcon className="size-4" />
            Baja confianza
          </span>
        )}
      </div>

      <dl className="risk-facts">
        {prediction.probability !== undefined && (
          <div>
            <dt>Probabilidad calibrada</dt>
            <dd>{formatPercent(prediction.probability)}</dd>
          </div>
        )}
        <div>
          <dt>Confianza</dt>
          <dd>{formatPercent(prediction.confidence)}</dd>
        </div>
        <div>
          <dt>Modelo</dt>
          <dd>Versión {prediction.model_version}</dd>
        </div>
        <div>
          <dt>Calculada</dt>
          <dd>{formatDateTime(prediction.predicted_at)}</dd>
        </div>
        <div>
          <dt>Vigente hasta</dt>
          <dd>{formatDate(prediction.expires_at)}</dd>
        </div>
      </dl>

      {individual && (
        <div className="risk-factors">
          <Factors title="Factores que aumentan el riesgo" items={increasing} direction="increasing" scale={scale} />
          <Factors title="Factores que lo reducen" items={decreasing} direction="decreasing" scale={scale} />
        </div>
      )}
      {(prediction.explanation_global ?? []).length > 0 && (
        <div className="mt-4">
          <h3 className="text-title-md">Variables con más peso en el modelo</h3>
          <ol className="m-0 pl-5">
            {prediction.explanation_global.slice(0, 3).map((item) => (
              <li key={item.feature}>{item.label}</li>
            ))}
          </ol>
        </div>
      )}

      <p className="muted mt-4 mb-0">{prediction.legend}</p>
    </section>
  )
}

/** Tarjeta neutra cuando el motor no respondió (RN-64, CA-55.3): no es un error y la atención sigue. */
export function RiskUnavailableCard({ message = 'Predicción no disponible' }) {
  return (
    <section className="card" aria-labelledby="risk-unavailable-title" role="status">
      <div className="flex items-start gap-3">
        <InfoIcon className="mt-0.5 size-5 shrink-0" />
        <div>
          <h2 id="risk-unavailable-title" className="m-0 text-title-md">
            {message}
          </h2>
          <p className="muted mt-1 mb-0">
            No se pudo calcular el riesgo en este momento. Puede continuar con la atención e intentarlo más tarde.
          </p>
        </div>
      </div>
    </section>
  )
}
