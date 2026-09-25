import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../../api/client'

export function usePatient(uuid) {
  return useQuery({
    queryKey: ['patients', 'detail', uuid],
    queryFn: async () => (await apiClient.get(`/patients/${uuid}`)).data.data,
    enabled: Boolean(uuid),
    retry: false,
  })
}
