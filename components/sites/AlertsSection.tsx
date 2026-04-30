'use client'
import { useState } from 'react'
import { ShieldAlert, CheckCircle, X } from 'lucide-react'
import { timeAgo, severityColor } from '@/lib/utils'

interface Alert {
  id: string
  type: string
  severity: string
  title: string
  message: string
  createdAt: string | Date
  resolved: boolean
}

export default function AlertsSection({ siteId, initialAlerts }: { siteId: string; initialAlerts: Alert[] }) {
  const [alerts, setAlerts] = useState(initialAlerts)

  async function resolveAlert(alertId: string) {
    try {
      await fetch(`/api/sites/${siteId}/alerts`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ alertId }),
      })
      setAlerts(prev => prev.filter(a => a.id !== alertId))
    } catch {}
  }

  async function resolveAll() {
    try {
      await fetch(`/api/sites/${siteId}/alerts`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ resolveAll: true }),
      })
      setAlerts([])
    } catch {}
  }

  if (alerts.length === 0) return null

  return (
    <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl">
      <div className="flex items-center justify-between px-6 py-4 border-b border-[#1e1e2e]">
        <div className="flex items-center gap-2.5">
          <ShieldAlert size={15} className="text-orange-400" />
          <h2 className="font-semibold text-white text-sm">Open Alerts</h2>
          <span className="text-xs bg-orange-400/15 text-orange-400 border border-orange-400/20 px-2 py-0.5 rounded-full">{alerts.length}</span>
        </div>
        <button
          onClick={resolveAll}
          className="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-300 transition"
        >
          <CheckCircle size={12} />
          Resolve all
        </button>
      </div>
      <div className="divide-y divide-[#1e1e2e]">
        {alerts.map(alert => (
          <div key={alert.id} className="flex items-start gap-4 px-6 py-4">
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 mb-1">
                <span className={`text-xs font-semibold border px-2 py-0.5 rounded-full ${severityColor(alert.severity)}`}>
                  {alert.severity}
                </span>
                <span className="text-xs font-medium text-slate-300">{alert.title}</span>
              </div>
              <p className="text-xs text-slate-500 leading-relaxed">{alert.message}</p>
              <p className="text-xs text-slate-700 mt-1">{timeAgo(alert.createdAt)}</p>
            </div>
            <button
              onClick={() => resolveAlert(alert.id)}
              className="text-slate-700 hover:text-green-400 transition flex-shrink-0 mt-0.5"
              title="Resolve"
            >
              <CheckCircle size={14} />
            </button>
          </div>
        ))}
      </div>
    </div>
  )
}
