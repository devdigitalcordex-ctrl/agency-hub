'use client'
import { useState, useEffect } from 'react'
import { ShieldCheck, Play, AlertTriangle, CheckCircle, Clock, Loader, ChevronDown, ChevronUp, File, Database } from 'lucide-react'
import { timeAgo } from '@/lib/utils'

interface Finding {
  file_path: string
  file_type: string
  issue_type: string
  severity: string
  confidence_score: number
  description: string
  snippet?: string
  matched_rule: string
  recommendation: string
}

interface Scan {
  id: string
  status: string
  triggeredBy: string
  startedAt: string | Date
  completedAt?: string | Date | null
  totalFiles: number
  threats: number
  findings: Finding[] | any
}

export default function ScanSection({ siteId, scans: initialScans }: { siteId: string; scans: Scan[] }) {
  const [running, setRunning] = useState(false)
  const [scans, setScans] = useState(initialScans)
  const [msg, setMsg] = useState('')
  const [expanded, setExpanded] = useState<string | null>(null)
  const [polling, setPolling] = useState(false)

  // Poll for scan results after triggering
  useEffect(() => {
    if (!polling) return
    const interval = setInterval(async () => {
      const res = await fetch(`/api/sites/${siteId}/scans`)
      if (res.ok) {
        const data = await res.json()
        if (data.length > scans.length || (data[0] && data[0].status === 'complete')) {
          setScans(data)
          setPolling(false)
          setMsg('')
        }
      }
    }, 5000)
    return () => clearInterval(interval)
  }, [polling, siteId, scans.length])

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
        setMsg('Scan queued — results will appear automatically (up to 5 min)')
        setPolling(true)
      } else {
        setMsg('Failed to queue scan.')
      }
    } catch {
      setMsg('Request failed.')
    }
    setRunning(false)
  }

  const lastScan = scans[0]
  const findings: Finding[] = Array.isArray(lastScan?.findings) ? lastScan.findings : []

  const severityOrder: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3 }
  const sortedFindings = [...findings].sort((a, b) =>
    (severityOrder[a.severity] ?? 9) - (severityOrder[b.severity] ?? 9)
  )

  const severityConfig: Record<string, { color: string; bg: string; border: string }> = {
    critical: { color: 'text-red-400',    bg: 'bg-red-500/5',    border: 'border-red-500/20' },
    high:     { color: 'text-orange-400', bg: 'bg-orange-500/5', border: 'border-orange-500/20' },
    medium:   { color: 'text-yellow-400', bg: 'bg-yellow-500/5', border: 'border-yellow-500/20' },
    low:      { color: 'text-blue-400',   bg: 'bg-blue-500/5',   border: 'border-blue-500/20' },
  }

  return (
    <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl">
      {/* Header */}
      <div className="flex items-center justify-between px-6 py-4 border-b border-[#1e1e2e]">
        <div className="flex items-center gap-2.5">
          <ShieldCheck size={15} className="text-[#5185C8]" />
          <h2 className="font-semibold text-white text-sm">Security Scanner</h2>
          {polling && (
            <span className="flex items-center gap-1 text-xs text-[#5185C8]">
              <Loader size={10} className="animate-spin" /> scanning...
            </span>
          )}
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
          <>
            {/* Scan summary */}
            <div className="flex items-center gap-3 mb-4">
              <StatusBadge status={lastScan.status} />
              <span className="text-xs text-slate-500">Last scan {timeAgo(lastScan.startedAt)}</span>
              <span className="text-xs text-slate-600 ml-auto">{lastScan.totalFiles || 0} files scanned</span>
            </div>

            {/* Stats */}
            <div className="grid grid-cols-2 gap-3 mb-4">
              <Stat label="Files Scanned" value={lastScan.totalFiles || 0} />
              <Stat label="Threats Found" value={lastScan.threats || 0} warn={(lastScan.threats || 0) > 0} />
            </div>

            {/* Findings list */}
            {sortedFindings.length > 0 && (
              <div className="space-y-2">
                <p className="text-xs text-slate-500 uppercase tracking-wide font-medium mb-2">
                  Threats ({sortedFindings.length})
                </p>
                {sortedFindings.map((f, i) => {
                  const sc = severityConfig[f.severity] ?? severityConfig.medium
                  const isOpen = expanded === `${i}`
                  const isDb = f.file_path?.startsWith('database://')
                  const displayPath = isDb
                    ? f.file_path.replace('database://', 'DB: ')
                    : f.file_path?.replace('/home/digital6/public_html/', '')

                  return (
                    <div key={i} className={`rounded-lg border ${sc.border} ${sc.bg} overflow-hidden`}>
                      <button
                        onClick={() => setExpanded(isOpen ? null : `${i}`)}
                        className="w-full flex items-start gap-2.5 px-3 py-2.5 text-left"
                      >
                        <AlertTriangle size={12} className={`${sc.color} mt-0.5 flex-shrink-0`} />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 mb-0.5">
                            <span className={`text-xs font-bold uppercase ${sc.color}`}>{f.severity}</span>
                            <span className="text-xs text-slate-400 font-medium">{f.issue_type.replace(/_/g, ' ')}</span>
                          </div>
                          <p className="text-xs text-slate-500 font-mono truncate">{displayPath}</p>
                        </div>
                        {isOpen ? <ChevronUp size={12} className="text-slate-600 mt-0.5 flex-shrink-0" /> : <ChevronDown size={12} className="text-slate-600 mt-0.5 flex-shrink-0" />}
                      </button>

                      {isOpen && (
                        <div className="px-3 pb-3 border-t border-white/5 pt-2 space-y-2">
                          <p className="text-xs text-slate-300">{f.description}</p>
                          <div className="flex items-center gap-2">
                            {isDb ? <Database size={10} className="text-slate-600" /> : <File size={10} className="text-slate-600" />}
                            <p className="text-xs text-slate-500 font-mono break-all">{f.file_path}</p>
                          </div>
                          <div className="bg-[#0A0A12] rounded px-3 py-2">
                            <p className="text-xs text-yellow-400 font-medium mb-1">Recommendation</p>
                            <p className="text-xs text-slate-400">{f.recommendation}</p>
                          </div>
                          {f.snippet && (
                            <div className="bg-[#0A0A12] rounded px-3 py-2">
                              <p className="text-xs text-slate-600 mb-1">Matched snippet</p>
                              <pre className="text-xs text-red-400 font-mono overflow-x-auto whitespace-pre-wrap break-all">{f.snippet}</pre>
                            </div>
                          )}
                          <div className="flex items-center gap-3 text-xs text-slate-600">
                            <span>Rule: <span className="text-slate-500">{f.matched_rule}</span></span>
                            <span>Confidence: <span className="text-slate-500">{f.confidence_score}%</span></span>
                          </div>
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>
            )}

            {sortedFindings.length === 0 && lastScan.status === 'complete' && (
              <div className="text-center py-4">
                <CheckCircle size={20} className="text-green-500 mx-auto mb-2" />
                <p className="text-xs text-slate-500">No threats found — site is clean</p>
              </div>
            )}
          </>
        ) : (
          <div className="text-center py-6">
            <ShieldCheck size={24} className="text-slate-700 mx-auto mb-2" />
            <p className="text-xs text-slate-600">No scans yet. Run your first scan.</p>
          </div>
        )}

        {/* Scan history */}
        {scans.length > 1 && (
          <div className="border-t border-[#1e1e2e] pt-4 mt-4 space-y-2">
            <p className="text-xs text-slate-600 uppercase tracking-wide mb-2">History</p>
            {scans.slice(1, 5).map(scan => (
              <div key={scan.id} className="flex items-center gap-3 text-xs">
                <StatusBadge status={scan.status} small />
                <span className="text-slate-600 flex-1">{timeAgo(scan.startedAt)}</span>
                <span className={(scan.threats || 0) > 0 ? 'text-red-400' : 'text-slate-600'}>
                  {(scan.threats || 0) > 0 ? `${scan.threats} threat${scan.threats > 1 ? 's' : ''}` : 'Clean'}
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
    complete:     { label: 'Complete',     class: 'text-green-400 bg-green-400/10' },
    threats_found:{ label: 'Threats Found',class: 'text-red-400 bg-red-400/10' },
    running:      { label: 'Running',      class: 'text-blue-400 bg-blue-400/10' },
    pending:      { label: 'Pending',      class: 'text-yellow-400 bg-yellow-400/10' },
    failed:       { label: 'Failed',       class: 'text-red-400 bg-red-400/10' },
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