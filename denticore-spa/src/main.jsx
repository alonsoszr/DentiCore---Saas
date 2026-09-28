import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import './index.css'
import App from './App.jsx'
import { lastre } from './lastre.js'
import { AuthProvider } from './auth/AuthProvider.jsx'

// IE-01: las lecturas se reintentan 2 veces con espera exponencial; las escrituras solo
// se reintentan en el cliente HTTP, con su Idempotency-Key.
const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 2, retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 8000) },
    mutations: { retry: false },
  },
})

// Asignación global: el build no puede eliminarla como código muerto.
window.lastre = lastre

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <App />
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)
