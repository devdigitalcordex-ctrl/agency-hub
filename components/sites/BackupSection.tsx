'use client'
import { useState, useEffect } from 'react'
import { Database, Download, Play, Loader } from 'lucide-react'
import { timeAgo, formatBytes } from '@/lib/utils'

interface Backup {
  id: string
  type: string
  status: string
  size: bigint | number
  downloadUrl?: string | null
  expiresAt?: string | Date | null
  createdAt: string | Date
}

export default function BackupSection({ siteId, backups: initial }: { siteId: string; backups: Backup[] }) {
  const [running, setRunning] = useState(false)
  const [msg, setMsg] = useState('')
  const [backups, setBackups] = useState(initial)

  useEffect(() => {
    const poll = setInterval(async () => {
      const res = await fetch(`/api/sites/${siteId}/backups`).catch(() => null)
      if (res?.ok) {
        const data = await res.json()
        setBackups(data)
      }
    }, 15000)
    return () => clearInterval(poll)
  }, [siteId])

  async function triggerBackup(type: 'full' | 'db' | 'files') {
    setRunning(true)
    setMsg('')
    try {
      const res = await fetch(`/api/sites/${siteId}/command`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'backup', payload: { backup_type: type } }),
      })
      if (res.ok) {
        setMsg(`${type} backup queued — will run on next heartbeat.`)
      } else {
        setMsg('Failed to queue backup.')
      }
    } catch {
      setMsg('Request failed.')
    }
    setRunning(false)
  }

  return (
    <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl">
      <div className="flex items-center justify-between px-6 py-4 border-b border-[#1e1e2e]">
        <div className="flex items-center gap-2.5">
          <Database size={15} className="text-[#5185C8]" />
          <h2 className="font-semibold text-white text-sm">Backups</h2>
        </div>
        <div className="flex items-center gap-2">
          {(['full', 'db', 'files'] as const).map(type => (
            <button
              key={type}
              onClick={() => triggerBackup(type)}
              disabled={running}
              className="flex items-center gap-1 bg-[#5185C8]/10 hover:bg-[#5185C8]/20 text-[#5185C8] border border-[#5185C8]/15 text-xs px-2.5 py-1.5 rounded-lg transition disabled:opacity-50 capitalize"
            >
              {running ? <Loader size={10} className="animate-spin" /> : <Play size={10} />}
              {type}
            </button>
          ))}
        </div>
      </div>
      <div className="p-5">
        {msg && (
          <p className="text-xs text-[#5185C8] bg-[#5185C8]/5 border border-[#5185C8]/10 rounded-lg px-3 py-2 mb-4">{msg}</p>
        )}
        {backups.length === 0 ? (
          <div className="text-center py-6">
            <Database size={24} className="text-slate-700 mx-auto mb-2" />
            <p className="text-xs text-slate-600">No backups yet. Trigger one above.</p>
          </div>
        ) : (
          <div className="space-y-2">
            {backups.map(backup => (
              <div key={backup.id} className="flex items-center gap-3 bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-4 py-3">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-0.5">
                    <span className="text-xs font-semibold text-slate-300 capitalize">{backup.type}</span>
                    <BackupBadge status={backup.status} />
                  </div>
                  <p className="text-xs text-slate-600">
                    {timeAgo(backup.createdAt)} · {formatBytes(Number(backup.size))}
                  </p>
                </div>
                {backup.downloadUrl && (
                  
                    href={backup.downloadUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-1 text-xs text-[#5185C8] hover:text-white transition"
                  >
                    <Download size={12} />
                    Download
                  </a>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

function BackupBadge({ status }: { status: string }) {
  const config: Record<string, string> = {
    complete: 'text-green-400 bg-green-400/10',
    running: 'text-blue-400 bg-blue-400/10',
    pending: 'text-yellow-400 bg-yellow-400/10',
    failed: 'text-red-400 bg-red-400/10',
  }
  return (
    <span className={`text-xs px-1.5 py-0.5 rounded font-medium ${config[status] ?? config.pending}`}>
      {status}
    </span>
  )
}
