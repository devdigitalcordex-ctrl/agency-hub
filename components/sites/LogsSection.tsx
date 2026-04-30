'use client'
import { useState, useEffect } from 'react'
import { Activity, RefreshCw } from 'lucide-react'
import { timeAgo, severityColor } from '@/lib/utils'

interface Log {
  id: string
  eventType: string
  category: string
  severity: string
  message: string
  username?: string | null
  userIp?: string | null
  occurredAt: string | Date
}

export default function LogsSection({ siteId }: { siteId: string }) {
  const [logs, setLogs] = useState<Log[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [severity, setSeverity] = useState('')

  async function fetchLogs() {
    setLoading(true)
    try {
      const params = new URLSearchParams({ take: '50' })
      if (severity) params.set('severity', severity)
      const res = await fetch(`/api/sites/${siteId}/logs?${params}`)
      const data = await res.json()
      setLogs(data.logs || [])
      setTotal(data.total || 0)
    } catch {}
    setLoading(false)
  }

  useEffect(() => { fetchLogs() }, [siteId, severity])

  return (
    <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl">
      <div className="flex items-center justify-between px-6 py-4 border-b border-[#1e1e2e]">
        <div className="flex items-center gap-2.5">
          <Activity size={15} className="text-[#5185C8]" />
          <h2 className="font-semibold text-white text-sm">Activity Log</h2>
          <span className="text-xs text-slate-600">{total.toLocaleString()} events</span>
        </div>
        <div className="flex items-center gap-2">
          <select
            value={severity}
            onChange={e => setSeverity(e.target.value)}
            className="bg-[#0A0A12] border border-[#1e1e2e] text-slate-400 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-[#5185C8] transition"
          >
            <option value="">All severities</option>
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
            <option value="info">Info</option>
          </select>
          <button
            onClick={fetchLogs}
            className="text-slate-600 hover:text-slate-300 transition"
            title="Refresh"
          >
            <RefreshCw size={13} className={loading ? 'animate-spin' : ''} />
          </button>
        </div>
      </div>

      <div className="divide-y divide-[#1e1e2e] max-h-96 overflow-y-auto scrollbar-thin">
        {loading ? (
          <div className="text-center py-8 text-sm text-slate-600">Loading logs...</div>
        ) : logs.length === 0 ? (
          <div className="text-center py-8 text-sm text-slate-600">No activity logs yet.</div>
        ) : (
          logs.map(log => (
            <div key={log.id} className="flex items-start gap-4 px-6 py-3 hover:bg-white/2 transition">
              <span className={`text-xs font-medium border px-2 py-0.5 rounded-full flex-shrink-0 mt-0.5 ${severityColor(log.severity)}`}>
                {log.severity}
              </span>
              <div className="flex-1 min-w-0">
                <p className="text-xs text-slate-300 leading-relaxed">{log.message}</p>
                <div className="flex items-center gap-3 mt-1">
                  {log.username && <span className="text-xs text-slate-600">{log.username}</span>}
                  {log.userIp && <span className="text-xs text-slate-700 font-mono">{log.userIp}</span>}
                  <span className="text-xs text-slate-700">{timeAgo(log.occurredAt)}</span>
                </div>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  )
}
