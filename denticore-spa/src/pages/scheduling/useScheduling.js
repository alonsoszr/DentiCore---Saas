import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { affectedIds } from './labels'

/** Tipos de cita de la clínica, activos e inactivos (CUS-45). */
export function useAppointmentTypes() {
  const { slug } = useClinic()
  return useQuery({
    queryKey: ['appointment-types', slug],
    queryFn: async () => (await apiClient.get('/appointment-types')).data.data,
  })
}

/**
 * Odontólogos que puede gestionar el usuario: el administrador ve todos los de la clínica (la lista
 * de usuarios es solo suya); el odontólogo, solo a sí mismo.
 */
export function useManagedDentists() {
  const { slug } = useClinic()
  const { user } = useAuth()
  const isAdmin = user?.role === 'clinic_admin'
  return useQuery({
    queryKey: ['managed-dentists', slug, user?.id],
    queryFn: async () => {
      if (!isAdmin) return [{ id: user.id, name: user.name }]
      const users = (await apiClient.get('/users', { params: { role: 'dentist', status: 'activo', per_page: 100 } }))
        .data.data
      return users.map(({ id, name }) => ({ id, name }))
    },
  })
}

/**
 * Citas afectadas de un 422 RN-82 (un horario o un bloqueo, SDD §5.7), pedidas por su uuid para
 * decidir sobre cada una. Null si el error no es ese o no se pudieron cargar.
 */
export async function loadAffected(error) {
  const ids = affectedIds(error)
  if (error?.response?.data?.rule !== 'RN-82' || ids.length === 0) return null
  try {
    return await Promise.all(ids.map(async (id) => (await apiClient.get(`/appointments/${id}`)).data.data))
  } catch {
    return null
  }
}

/** Mensaje de un error de la API: el general o, si trae errores por campo, su `detail`. */
export function problemDetail(error, general) {
  return general ?? error?.response?.data?.detail ?? null
}
