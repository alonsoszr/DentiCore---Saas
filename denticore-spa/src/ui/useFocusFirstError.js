import { useEffect } from 'react'

/**
 * Tras un 422 lleva el foco al primer campo con error para leerlo y corregirlo de inmediato
 * (RNF-064; DESIGN.md › Input fields): sin esto el foco queda en el botón o en `body`.
 */
export function useFocusFirstError(error) {
  useEffect(() => {
    if (!error) return
    const invalid =
      document.querySelector('main [aria-invalid="true"]') ?? document.querySelector('[aria-invalid="true"]')
    invalid?.focus()
  }, [error])
}
