/**
 * Panel de marca navy de las pantallas de acceso (DESIGN.md › Layout & Spacing). Solo se
 * muestra desde 1024px. Sin fotografías ni decoración; los logos llegarán después.
 */
const CONTENT = {
  // Textos del README de pantallas y, para las tarjetas, del prototipo auth-login.html (aprobados).
  clinic: {
    title: 'Historia clínica, odontograma y agenda en un solo lugar',
    text: 'Gestión odontológica digital para clínicas del Perú.',
    cards: [
      ['Odontograma según NTS N° 188', 'Registro en azul y rojo con las siglas oficiales y numeración FDI.'],
      [
        'Datos aislados por clínica',
        'Cada clínica accede solo a su información, con cifrado de datos sensibles y registro de auditoría.',
      ],
      ['Verificación en dos pasos', 'Protección adicional con código de 6 dígitos para las cuentas que lo requieren.'],
    ],
  },
  // Ficha auth-login-platform.md › Panel de marca.
  platform: {
    title: 'Administración de la plataforma',
    text: 'Gestión de clínicas, planes y seguridad de DentiCore.',
    cards: [
      ['Gestión de clínicas', 'Alta, suspensión y reactivación de clínicas y cambio de plan.'],
      ['Seguridad y auditoría', 'Registro de accesos y eventos críticos de la plataforma.'],
      [
        'Verificación en dos pasos obligatoria',
        'Este acceso requiere un código de 6 dígitos de tu aplicación de autenticación.',
      ],
    ],
  },
}

export function BrandPanel({ variant, name }) {
  const { title, text, cards } = CONTENT[variant]

  return (
    <aside className="hidden flex-col justify-between gap-10 bg-primary p-10 text-on-primary lg:flex xl:p-14">
      <div className="flex flex-col gap-4">
        <p className="m-0 text-title-lg">{name}</p>
        <h2 className="text-headline-lg text-on-primary">{title}</h2>
        <p className="m-0 max-w-md text-body-md text-primary-fixed-dim">{text}</p>
      </div>

      <ul className="m-0 flex list-none flex-col gap-4 p-0">
        {cards.map(([cardTitle, description]) => (
          <li key={cardTitle} className="rounded-lg border border-on-primary-fixed-variant bg-primary-hover p-4">
            <h3 className="text-label-md font-semibold text-on-primary">{cardTitle}</h3>
            <p className="mt-1 mb-0 text-body-md text-primary-fixed-dim">{description}</p>
          </li>
        ))}
      </ul>
    </aside>
  )
}
