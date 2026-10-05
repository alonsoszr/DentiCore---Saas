import { BrandPanel } from './BrandPanel'

/**
 * Vista dividida de las pantallas de acceso (DESIGN.md › Layout & Spacing): formulario en un
 * contenedor de 420px a la izquierda y panel de marca a la derecha (55/45), oculto por
 * debajo de 1024px. `variant`: 'clinic' (con `clinicName`) o 'platform'.
 */
export function AuthLayout({ variant, clinicName, footer, children }) {
  return (
    <div className="grid min-h-dvh bg-surface-container-lowest text-body-lg lg:grid-cols-[55fr_45fr]">
      <main className="flex flex-col px-6 py-10 sm:px-10 lg:px-16">
        {variant === 'clinic' && (
          <p className="mx-auto mb-8 w-full max-w-[420px] text-title-lg font-bold text-primary">DentiCore</p>
        )}
        <div className="mx-auto my-auto w-full max-w-[420px] py-2">{children}</div>
        {footer && (
          <footer className="mx-auto mt-10 w-full max-w-[420px] border-t border-outline-variant pt-6 text-center text-body-md text-on-surface-variant">
            {footer}
          </footer>
        )}
      </main>
      <BrandPanel variant={variant} name={variant === 'clinic' ? clinicName : 'DentiCore'} />
    </div>
  )
}
