import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'

export function usePatient(uuid) {
  const { slug } = useParams()
  return useQuery({
    queryKey: ['patients', slug, uuid],
    queryFn: async () => (await apiClient.get(`/patients/${uuid}`)).data.data,
    enabled: Boolean(uuid),
    retry: false,
  })
}
