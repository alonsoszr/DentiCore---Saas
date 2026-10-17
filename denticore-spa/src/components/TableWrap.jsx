/**
 * Contenedor de una tabla que puede desplazarse en horizontal (RNF-052: la página no se desplaza,
 * la tabla sí). Recibe el foco para recorrerla con las flechas del teclado (WCAG 2.1.1; axe
 * `scrollable-region-focusable`). Es un `group` con nombre y no un landmark `region`: así no se suman
 * landmarks con nombres repetidos (la agenda semanal tiene una tabla por día). El nombre sale del
 * encabezado visible (`labelledBy`, el `id` del h1/h2) o, si la tabla no tiene encabezado propio, de
 * `label`.
 */
export function TableWrap({ label, labelledBy, children }) {
  if (import.meta.env.DEV && !label && !labelledBy) {
    console.warn('TableWrap necesita `label` o `labelledBy`: sin nombre, el lector anuncia un grupo vacío.')
  }

  return (
    <div
      className="table-wrap"
      role="group"
      aria-label={labelledBy ? undefined : label}
      aria-labelledby={labelledBy}
      tabIndex={0}
    >
      {children}
    </div>
  )
}
