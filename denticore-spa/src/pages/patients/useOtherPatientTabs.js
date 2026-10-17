import { useContext, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { AuthContext } from '../../auth/useAuth'

/** Cada pestaña repite su anuncio con esta frecuencia… */
export const HEARTBEAT_MS = 15_000
/** …y las demás la olvidan si dejan de oírla durante este tiempo (cierre sin despedida). */
export const EXPIRY_MS = 3 * HEARTBEAT_MS

const fullName = (patient) => `${patient.first_name} ${patient.last_name}`

/**
 * RNF-146: pacientes que el mismo usuario tiene abiertos en otras pestañas de este navegador,
 * distintos del de esta pestaña. Las pestañas se avisan por BroadcastChannel, sin pasar por el
 * servidor:
 * - al abrir una ficha, la pestaña se anuncia, y las que ya estaban le responden con su paciente;
 * - cada pestaña repite su anuncio cada 15 s, y la que no se oye en 45 s se olvida (una pestaña que
 *   se cerró sin despedirse, p. ej. por una caída);
 * - al cambiar de paciente o cerrarse, se despide;
 * - al volver desde la caché del navegador (bfcache), se anuncia otra vez.
 *
 * El canal es por clínica y por usuario, así que dos cuentas en el mismo equipo no se mezclan. Solo
 * viajan el id y el nombre del paciente, entre pestañas del mismo origen.
 */
export function useOtherPatientTabs(patient) {
  const { slug } = useParams()
  const userId = useContext(AuthContext)?.user?.id
  const [tabId] = useState(() => crypto.randomUUID())
  const [others, setOthers] = useState({})
  const patientId = patient?.id
  const name = patient ? fullName(patient) : ''

  useEffect(() => {
    if (typeof BroadcastChannel === 'undefined' || !patientId || !userId) return undefined

    const channel = new BroadcastChannel(`denticore:pacientes:${slug ?? ''}:${userId}`)
    const me = { tab: tabId, id: patientId, name }
    const announce = (type) => channel.postMessage({ type, ...me })
    const forget = (tab) =>
      setOthers((current) => {
        const next = { ...current }
        delete next[tab]
        return next
      })

    channel.onmessage = ({ data }) => {
      if (!data || data.tab === me.tab) return
      if (data.type === 'cerrada') {
        forget(data.tab)
        return
      }
      if (data.type === 'abierta') announce('presente')
      setOthers((current) => ({ ...current, [data.tab]: { id: data.id, name: data.name, seenAt: Date.now() } }))
    }
    announce('abierta')

    const heartbeat = setInterval(() => {
      announce('presente')
      const limit = Date.now() - EXPIRY_MS
      setOthers((current) => {
        const alive = Object.entries(current).filter(([, other]) => other.seenAt >= limit)
        return alive.length === Object.keys(current).length ? current : Object.fromEntries(alive)
      })
    }, HEARTBEAT_MS)
    const close = () => channel.postMessage({ type: 'cerrada', tab: me.tab })
    const reopen = (event) => event.persisted && announce('abierta')
    window.addEventListener('pagehide', close)
    window.addEventListener('pageshow', reopen)
    return () => {
      clearInterval(heartbeat)
      window.removeEventListener('pagehide', close)
      window.removeEventListener('pageshow', reopen)
      close()
      channel.close()
      setOthers({})
    }
  }, [slug, userId, tabId, patientId, name])

  const seen = new Set()
  return Object.values(others).filter((other) => {
    if (other.id === patientId || seen.has(other.id)) return false
    seen.add(other.id)
    return true
  })
}
