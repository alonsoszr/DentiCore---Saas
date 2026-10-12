import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { apiClient } from '../api/client'
import { useAuth } from '../auth/useAuth'
import { formatCurrency, formatDateTime } from '../ui/format'
import { BellIcon } from '../ui/icons'
import { useTimeZone } from '../ui/useTimeZone'

const QUERY_KEY = ['notifications']

/**
 * Texto y destino de cada evento in-app (SDD §4.8). Los enlaces de la API son absolutos; se
 * navega a su ruta dentro del SPA.
 */
function describe(notification, slug) {
  const { params = {}, links = {} } = notification
  switch (notification.event) {
    case 'presupuesto_aceptado':
      return {
        text: `Se aceptó el presupuesto ${params.number} por ${formatCurrency(params.total)}.`,
        path: links.budget ? new URL(links.budget).pathname : null,
      }
    case 'alerta_riesgo_alto':
      return {
        text: 'Una predicción que solicitaste resultó de riesgo alto: reconoce la alerta.',
        path: links.alerts ? new URL(links.alerts).pathname : null,
      }
    case 'atencion_cierre_incompleto':
      return {
        text: 'Una atención se cerró incompleta: complétala con una adenda.',
        path: slug && params.attention_uuid ? `/c/${slug}/app/atenciones/${params.attention_uuid}` : null,
      }
    default:
      return { text: 'Tienes un aviso nuevo.', path: null }
  }
}

/**
 * Campana de notificaciones de la barra superior (CUS-53; RF-158): contador de no leídos y panel
 * con los avisos de los últimos 90 días. Abrir un aviso lo marca como leído; se actualiza cada
 * minuto.
 */
export function NotificationBell() {
  const { user } = useAuth()
  const timeZone = useTimeZone()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const containerRef = useRef(null)
  const buttonRef = useRef(null)

  const notifications = useQuery({
    queryKey: QUERY_KEY,
    queryFn: async () => (await apiClient.get('/notifications')).data,
    refetchInterval: 60_000,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: QUERY_KEY })
  const markRead = useMutation({
    mutationFn: async (id) => apiClient.post(`/notifications/${id}/read`),
    onSuccess: refresh,
  })
  const markAll = useMutation({ mutationFn: async () => apiClient.post('/notifications/read-all'), onSuccess: refresh })

  useEffect(() => {
    if (!open) return undefined
    const close = (event) => {
      if (event.type === 'keydown' && event.key === 'Escape') {
        setOpen(false)
        buttonRef.current?.focus()
      } else if (event.type === 'mousedown' && !containerRef.current?.contains(event.target)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', close)
    return () => {
      document.removeEventListener('mousedown', close)
      document.removeEventListener('keydown', close)
    }
  }, [open])

  const items = notifications.data?.data ?? []
  const unread = notifications.data?.meta?.unread_count ?? 0
  const label = unread > 0 ? `Notificaciones: ${unread} sin leer` : 'Notificaciones'

  const openNotification = (notification, path) => {
    if (!notification.read_at) markRead.mutate(notification.id)
    setOpen(false)
    if (path) navigate(path)
  }

  return (
    <div className="bell" ref={containerRef}>
      <button
        ref={buttonRef}
        type="button"
        className="bell-button"
        aria-label={label}
        aria-expanded={open}
        aria-controls="notification-panel"
        onClick={() => setOpen((value) => !value)}
      >
        <BellIcon className="size-5" />
        {unread > 0 && (
          <span className="bell-count" aria-hidden="true">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div id="notification-panel" className="bell-panel" role="region" aria-label="Notificaciones">
          <div className="flex items-center justify-between gap-3 pb-2">
            <strong>Notificaciones</strong>
            {unread > 0 && (
              <button type="button" className="btn-link" onClick={() => markAll.mutate()} disabled={markAll.isPending}>
                Marcar todas como leídas
              </button>
            )}
          </div>
          {notifications.isLoading && <p className="muted">Cargando…</p>}
          {notifications.isError && <p className="muted">No se pudieron cargar las notificaciones.</p>}
          {notifications.isSuccess && items.length === 0 && <p className="muted">No tienes notificaciones.</p>}
          {items.length > 0 && (
            <ul>
              {items.map((notification) => {
                const { text, path } = describe(notification, user?.tenant?.slug)
                return (
                  <li key={notification.id}>
                    <button
                      type="button"
                      className={notification.read_at ? 'bell-item' : 'bell-item unread'}
                      onClick={() => openNotification(notification, path)}
                    >
                      {!notification.read_at && <span className="sr-only">Sin leer: </span>}
                      {text}
                      <span className="muted block">{formatDateTime(notification.created_at, timeZone)}</span>
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}
