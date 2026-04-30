'use client'
import { useState } from 'react'
import { ShieldCheck, Play, AlertTriangle, CheckCircle, Clock, Loader } from 'lucide-react'
import { timeAgo } from '@/lib/utils'

interface Scan {
  id: string
  status: string
  triggeredBy: string
  startedAt: string | Date
  completedAt?: string | Date | null
  totalFiles: number
  threats: number
  findings: any
}

export default function ScanSection({ siteId, scans }: { siteId: string; scans: Scan[] }) {
  const [running, setRunning] = useState(false)
  const [localScans, setLocalScans] = useState(scans)
  const [msg, setMsg] = useState('')

  async function triggerScan() {
    setRunning(true)
    setMsg('')
    try {
      const res = await fetch(`/api/sites/${siteId}/command`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'scan', payload: { full: true } }),
      })
      if (res.ok) {
        setMsg('Scan queued — will run on next heartbeat.')
      } else {
        setMsg('Failed to queue scan.')
      }
    } catch {
      setMsg('Request failed.')
    }
    setRunning(false)
  }

  const lastScan = localScans[0]

  return (
    <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl">
      <div className="flex items-center justify-between px-6 py-4 border-b border-[#1e1e2e]">
        <div className="flex items-center gap-2.5">
          <ShieldCheck size={15} className="text-[#5185C8]" />
          <h2 className="font-semibold text-white text-sm">Security Scanner</h2>
        </div>
        <button
          onClick={triggerScan}
          disabled={running}
          className="flex items-center gap-1.5 bg-[#5185C8]/15 hover:bg-[#5185C8]/25 text-[#5185C8] border border-[#5185C8]/20 text-xs font-semibold px-3 py-1.5 rounded-lg transition disabled:opacity-50"
        >
          {running ? <Loader size={11} className="animate-spin" /> : <Play size={11} />}
          {running ? 'Queuing...' : 'Run Scan'}
        </button>
      </div>

      <div className="p-5">
        {msg && (
          <p className="text-xs text-[#5185C8] bg-[#5185C8]/5 border border-[#5185C8]/10 rounded-lg px-3 py-2 mb-4">{msg}</p>
        )}

        {lastScan ? (
          <div className="mb-4">
            <div className="flex items-center gap-2 mb-3">
              <StatusBadge status={lastScan.status} />
              <span className="text-xs text-slate-600">Last scan {timeAgo(lastScan.startedAt)}</span>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Stat label="Files Scanned" value={lastScan.totalFiles} />
              <Stat label="Threats Found" value={lastScan.threats} warn={lastScan.threats > 0} />
            </div>
            {lastScan.threats > 0 && Array.isArray(lastScan.findings) && lastScan.findings.length > 0 && (
              <div className="mt-3 space-y-1.5">
                {lastScan.findings.slice(0, 3).map((f: any, i: number) => (
                  <div key={i} className="flex items-start gap-2 bg-red-500/5 border border-red-500/10 rounded-lg px-3 py-2">
                    <AlertTriangle size={11} className="text-red-400 mt-0.5 flex-shrink-0" />
                    <div>
                      <p className="text-xs font-medium text-red-400">{f.threat || f.type}</p>
                      <p className="text-xs text-slate-600 font-mono truncate">{f.file}</p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        ) : (
          <div className="text-center py-6">
            <ShieldCheck size={24} className="text-slate-700 mx-auto mb-2" />
            <p className="text-xs text-slate-600">No scans yet. Run your first scan.</p>
          </div>
        )}

        {/* Scan history */}
        {localScans.length > 1 && (
          <div className="border-t border-[#1e1e2e] pt-4 mt-4 space-y-2">
            <p className="text-xs text-slate-600 uppercase tracking-wide mb-2">History</p>
            {localScans.slice(1, 5).map(scan => (
              <div key={scan.id} className="flex items-center gap-3 text-xs">
                <StatusBadge status={scan.status} small />
                <span className="text-slate-600 flex-1">{timeAgo(scan.startedAt)}</span>
                <span className={scan.threats > 0 ? 'text-red-400' : 'text-slate-600'}>
                  {scan.threats > 0 ? `${scan.threats} threat${scan.threats > 1 ? 's' : ''}` : 'Clean'}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

function StatusBadge({ status, small }: { status: string; small?: boolean }) {
  const config: Record<string, { label: string; class: string }> = {
    complete: { label: 'Complete', class: 'text-green-400 bg-green-400/10' },
    running: { label: 'Running', class: 'text-blue-400 bg-blue-400/10' },
    pending: { label: 'Pending', class: 'text-yellow-400 bg-yellow-400/10' },
    failed: { label: 'Failed', class: 'text-red-400 bg-red-400/10' },
  }
  const c = config[status] ?? config.pending
  return (
    <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${c.class} ${small ? 'text-xs' : ''}`}>
      {c.label}
    </span>
  )
}

function Stat({ label, value, warn }: { label: string; value: number; warn?: boolean }) {
  return (
    <div className="bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-4 py-3">
      <p className="text-xs text-slate-600 mb-1">{label}</p>
      <p className={`text-xl font-bold ${warn ? 'text-red-400' : 'text-slate-200'}`}>{value}</p>
    </div>
  )
}
