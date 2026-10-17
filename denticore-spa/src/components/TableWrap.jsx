/**
 * Contenedor de una tabla que puede desplazarse en horizontal (RNF-052: la página no se desplaza,
 * la tabla sí). Es una región con nombre y recibe el foco para recorrerla con las flechas del teclado
 * (WCAG 2.1.1; axe `scrollable-region-focusable`).
 */
export function TableWrap({ label, children }) {
  return (
    // Una región con nombre puede recibir el foco: es la forma de desplazarla sin ratón.
    <div className="table-wrap" role="region" aria-label={label} tabIndex={0}>
      {children}
    </div>
  )
}
