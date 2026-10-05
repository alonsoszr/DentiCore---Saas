import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../api/client'

/**
 * Nombre de la clínica para las pantallas de acceso (GET /public/clinics/{slug}, SDD §4.2).
 * Mientras carga, si responde 404 (PEND-08) o si la API aún no expone la ruta (TASK-027),
 * se muestra el código de la URL como marcador.
 */
export function usePublicClinic(slug) {
  const query = useQuery({
    queryKey: ['public-clinic', slug],
    queryFn: async () => {
      const { data } = await apiClient.get(`/public/clinics/${encodeURIComponent(slug)}`)
      return data?.data ?? data
    },
    enabled: Boolean(slug),
    retry: false,
    staleTime: 60 * 60 * 1000,
  })

  return { name: query.data?.name || slug }
}
