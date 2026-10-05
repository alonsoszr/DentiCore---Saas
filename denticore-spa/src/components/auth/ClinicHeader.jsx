/**
 * Encabezado con la clínica de la URL (docs/design/screens/README.md). El círculo reserva el
 * lugar del logo, que llegará con GET /public/clinics/{slug}.
 */
export function ClinicHeader({ name }) {
  return (
    <div className="mb-7 flex items-center gap-3.5 border-b border-outline-variant pb-6">
      <span
        className="size-12 shrink-0 rounded-full border border-primary-fixed-dim bg-primary-container"
        aria-hidden="true"
      />
      <p className="m-0 text-title-md text-on-surface">{name}</p>
    </div>
  )
}
