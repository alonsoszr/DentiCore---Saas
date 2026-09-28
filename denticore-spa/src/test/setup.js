import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterEach } from 'vitest'

// findBy*/waitFor esperan hasta 5 s (1 s por defecto): con la suite en paralelo en un equipo
// cargado, una respuesta simulada puede tardar más de 1 s en reflejarse en el DOM.
configure({ asyncUtilTimeout: 5000 })

afterEach(() => {
  cleanup()
  sessionStorage.clear()
  localStorage.clear()
})
