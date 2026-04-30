'use client'
import { useState } from 'react'
import { Shield, Plus, X, ToggleLeft, ToggleRight, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'

interface IpRule {
  id: string
  type: string
  value: string
  label?: string | null
  reason?: string | null
  createdAt: string | Date
}

interface Props {
  siteId: string
  initialRules: IpRule[]
  initialAllowlistMode: boolean
}

export default function IpBlockingSection({ siteId, initialRules, initialAllowlistMode }: Props) {
  const [allowlistMode, setAllowlistMode] = useState(initialAllowlistMode)
  const [rules, setRules] = useState(initialRules)
  const [includeInput, setIncludeInput] = useState('')
  const [excludeInput, setExcludeInput] = useState('')
  const [includeLabel, setIncludeLabel] = useState('')
  const [excludeLabel, setExcludeLabel] = useState('')
  const [loading, setLoading] = useState(false)
  const [modeLoading, setModeLoading] = useState(false)
  const [error, setError] = useState('')

  const allowlist = rules.filter(r => r.type.startsWith('allowlist'))
  const blocklist = rules.filter(r => !r.type.startsWith('allowlist'))

  async function toggleMode() {
    setModeLoading(true)
    const newMode = !allowlistMode
    try {
      await fetch(`/api/sites/${siteId}/ip-blocking`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_mode', allowlistMode: newMode }),
      })
      setAllowlistMode(newMode)
    } catch {
      setError('Failed to toggle mode')
    }
    setModeLoading(false)
  }

  async function addRule(type: 'allowlist' | 'blocklist') {
    const value = type === 'allowlist' ? includeInput.trim() : excludeInput.trim()
    const label = type === 'allowlist' ? includeLabel.trim() : excludeLabel.trim()
    if (!value) return

    setLoading(true)
    setError('')

    try {
      const res = await fetch(`/api/sites/${siteId}/ip-blocking`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'add',
          type: value.includes('/') ? `${type}_cidr` : type,
          value,
          label,
        }),
      })

      if (!res.ok) {
        setError('Failed to add IP. Check that it is a valid IP or CIDR range.')
      } else {
        const rule = await res.json()
        setRules(prev => [rule, ...prev])
        if (type === 'allowlist') {
          setIncludeInput('')
          setIncludeLabel('')
        } else {
          setExcludeInput('')
          setExcludeLabel('')
        }
      }
    } catch {
      setError('Request failed')
    }
    setLoading(false)
  }

  async function removeRule(ruleId: string) {
    try {
      await fetch(`/api/sites/${siteId}/ip-blocking`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove', ruleId }),
      })
      setRules(prev => prev.filter(r => r.id !== ruleId))
    } catch {
      setError('Failed to remove rule')
    }
  }

  return (
    <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl">
      {/* Header */}
      <div className="flex items-center justify-between px-6 py-4 border-b border-[#1e1e2e]">
        <div className="flex items-center gap-2.5">
          <Shield size={15} className="text-[#5185C8]" />
          <h2 className="font-semibold text-white text-sm">IP Blocking</h2>
        </div>

        {/* Mode toggle */}
        <button
          onClick={toggleMode}
          disabled={modeLoading}
          className={cn(
            'flex items-center gap-2 text-xs font-semibold px-3 py-1.5 rounded-lg border transition',
            allowlistMode
              ? 'bg-[#5185C8]/15 text-[#5185C8] border-[#5185C8]/30 hover:bg-[#5185C8]/25'
              : 'bg-white/5 text-slate-400 border-[#1e1e2e] hover:bg-white/10'
          )}
        >
          {allowlistMode ? <ToggleRight size={13} /> : <ToggleLeft size={13} />}
          {allowlistMode ? 'Allowlist Mode ON' : 'Allowlist Mode OFF'}
        </button>
      </div>

      {/* Mode description */}
      <div className={cn(
        'mx-6 mt-4 mb-2 px-4 py-3 rounded-lg border text-xs',
        allowlistMode
          ? 'bg-[#5185C8]/5 border-[#5185C8]/20 text-[#5185C8]'
          : 'bg-white/3 border-[#1e1e2e] text-slate-500'
      )}>
        {allowlistMode
          ? 'Allowlist mode is ON — only IPs in the Include list below can access this site. All other visitors are blocked with a 403 error.'
          : 'Blocklist mode is active — only IPs in the Exclude list are blocked. All other visitors can access the site normally.'
        }
      </div>

      {error && (
        <div className="mx-6 mt-3 flex items-center gap-2 bg-red-500/10 border border-red-500/20 text-red-400 text-xs rounded-lg px-4 py-2.5">
          <AlertTriangle size={12} />
          {error}
        </div>
      )}

      <div className="grid grid-cols-2 gap-6 p-6">
        {/* INCLUDE (Allowlist) */}
        <div>
          <div className="flex items-center gap-2 mb-3">
            <span className="w-2 h-2 rounded-full bg-green-400" />
            <h3 className="text-sm font-semibold text-slate-300">Include (Allow)</h3>
            <span className="text-xs text-slate-600 ml-auto">{allowlist.length} IPs</span>
          </div>
          <p className="text-xs text-slate-600 mb-3">
            IPs that are always allowed through. When allowlist mode is on, only these IPs can access the site.
          </p>

          {/* Add input */}
          <div className="space-y-2 mb-3">
            <input
              type="text"
              value={includeInput}
              onChange={e => setIncludeInput(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && addRule('allowlist')}
              placeholder="IP address or CIDR (e.g. 192.168.1.1 or 10.0.0.0/24)"
              className="w-full bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2 text-xs text-white placeholder-slate-600 focus:outline-none focus:border-green-500/50 focus:ring-1 focus:ring-green-500/20 transition font-mono"
            />
            <div className="flex gap-2">
              <input
                type="text"
                value={includeLabel}
                onChange={e => setIncludeLabel(e.target.value)}
                placeholder="Label (optional, e.g. Office IP)"
                className="flex-1 bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2 text-xs text-white placeholder-slate-600 focus:outline-none focus:border-[#1e1e2e] transition"
              />
              <button
                onClick={() => addRule('allowlist')}
                disabled={loading || !includeInput.trim()}
                className="flex items-center gap-1.5 bg-green-500/15 hover:bg-green-500/25 text-green-400 border border-green-500/20 text-xs font-semibold px-3 py-2 rounded-lg transition disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <Plus size={11} />
                Add
              </button>
            </div>
          </div>

          {/* List */}
          <div className="space-y-1.5 max-h-48 overflow-y-auto scrollbar-thin">
            {allowlist.length === 0 ? (
              <p className="text-xs text-slate-700 text-center py-4">No IPs in allowlist</p>
            ) : (
              allowlist.map(rule => (
                <div key={rule.id} className="flex items-center gap-2 bg-green-500/5 border border-green-500/10 rounded-lg px-3 py-2">
                  <span className="text-xs font-mono text-green-400 flex-1">{rule.value}</span>
                  {rule.label && <span className="text-xs text-slate-600">{rule.label}</span>}
                  <button
                    onClick={() => removeRule(rule.id)}
                    className="text-slate-700 hover:text-red-400 transition flex-shrink-0"
                  >
                    <X size={12} />
                  </button>
                </div>
              ))
            )}
          </div>
        </div>

        {/* EXCLUDE (Blocklist) */}
        <div>
          <div className="flex items-center gap-2 mb-3">
            <span className="w-2 h-2 rounded-full bg-red-400" />
            <h3 className="text-sm font-semibold text-slate-300">Exclude (Block)</h3>
            <span className="text-xs text-slate-600 ml-auto">{blocklist.length} IPs</span>
          </div>
          <p className="text-xs text-slate-600 mb-3">
            IPs that are always blocked, regardless of mode. Use this for known bad actors.
          </p>

          {/* Add input */}
          <div className="space-y-2 mb-3">
            <input
              type="text"
              value={excludeInput}
              onChange={e => setExcludeInput(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && addRule('blocklist')}
              placeholder="IP address or CIDR (e.g. 1.2.3.4 or 5.6.7.0/24)"
              className="w-full bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2 text-xs text-white placeholder-slate-600 focus:outline-none focus:border-red-500/50 focus:ring-1 focus:ring-red-500/20 transition font-mono"
            />
            <div className="flex gap-2">
              <input
                type="text"
                value={excludeLabel}
                onChange={e => setExcludeLabel(e.target.value)}
                placeholder="Label (optional, e.g. Spammer)"
                className="flex-1 bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2 text-xs text-white placeholder-slate-600 focus:outline-none focus:border-[#1e1e2e] transition"
              />
              <button
                onClick={() => addRule('blocklist')}
                disabled={loading || !excludeInput.trim()}
                className="flex items-center gap-1.5 bg-red-500/15 hover:bg-red-500/25 text-red-400 border border-red-500/20 text-xs font-semibold px-3 py-2 rounded-lg transition disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <Plus size={11} />
                Add
              </button>
            </div>
          </div>

          {/* List */}
          <div className="space-y-1.5 max-h-48 overflow-y-auto scrollbar-thin">
            {blocklist.length === 0 ? (
              <p className="text-xs text-slate-700 text-center py-4">No IPs in blocklist</p>
            ) : (
              blocklist.map(rule => (
                <div key={rule.id} className="flex items-center gap-2 bg-red-500/5 border border-red-500/10 rounded-lg px-3 py-2">
                  <span className="text-xs font-mono text-red-400 flex-1">{rule.value}</span>
                  {rule.label && <span className="text-xs text-slate-600">{rule.label}</span>}
                  <button
                    onClick={() => removeRule(rule.id)}
                    className="text-slate-700 hover:text-red-400 transition flex-shrink-0"
                  >
                    <X size={12} />
                  </button>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
