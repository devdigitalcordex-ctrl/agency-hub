'use client'
import { useState } from 'react'
import { useRouter } from 'next/navigation'
import Link from 'next/link'
import { ArrowLeft } from 'lucide-react'

export default function NewSitePage() {
  const router = useRouter()
  const [name, setName] = useState('')
  const [url, setUrl] = useState('')
  const [notes, setNotes] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)
    setError('')

    try {
      const res = await fetch('/api/sites', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, url, notes }),
      })

      if (!res.ok) {
        const data = await res.json()
        setError(data.error || 'Failed to create site')
      } else {
        const site = await res.json()
        router.push(`/sites/${site.id}`)
      }
    } catch {
      setError('Request failed')
    }
    setLoading(false)
  }

  return (
    <div className="p-8 max-w-xl">
      <Link href="/sites" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-300 transition mb-6">
        <ArrowLeft size={14} />
        Sites
      </Link>

      <h1 className="text-2xl font-bold text-white mb-1">Add Site</h1>
      <p className="text-sm text-slate-500 mb-8">Connect a new WordPress site to Agency Hub.</p>

      {error && (
        <div className="bg-red-500/10 border border-red-500/20 text-red-400 text-sm rounded-lg px-4 py-3 mb-6">
          {error}
        </div>
      )}

      <form onSubmit={handleSubmit} className="bg-[#13131f] border border-[#1e1e2e] rounded-xl p-6 space-y-5">
        <div>
          <label className="block text-sm font-medium text-slate-400 mb-1.5">Site Name</label>
          <input
            type="text"
            value={name}
            onChange={e => setName(e.target.value)}
            required
            className="w-full bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-[#5185C8] focus:ring-1 focus:ring-[#5185C8]/20 transition"
            placeholder="My Client Site"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-400 mb-1.5">Site URL</label>
          <input
            type="url"
            value={url}
            onChange={e => setUrl(e.target.value)}
            required
            className="w-full bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-[#5185C8] focus:ring-1 focus:ring-[#5185C8]/20 transition"
            placeholder="https://example.com"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-400 mb-1.5">Notes <span className="text-slate-600">(optional)</span></label>
          <textarea
            value={notes}
            onChange={e => setNotes(e.target.value)}
            rows={3}
            className="w-full bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-[#5185C8] focus:ring-1 focus:ring-[#5185C8]/20 transition resize-none"
            placeholder="Internal notes about this site..."
          />
        </div>

        <button
          type="submit"
          disabled={loading}
          className="w-full bg-[#5185C8] hover:bg-[#4070b0] disabled:opacity-50 text-white font-semibold py-2.5 rounded-lg transition text-sm"
        >
          {loading ? 'Creating...' : 'Create Site'}
        </button>
      </form>

      <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl p-6 mt-4">
        <p className="text-xs font-medium text-slate-400 mb-2">After creating the site:</p>
        <ol className="text-xs text-slate-600 space-y-1 list-decimal list-inside">
          <li>Install the Agency Hub plugin on the WordPress site</li>
          <li>Go to Settings › Agency Hub in WordPress</li>
          <li>Paste the connection key shown on the site detail page</li>
          <li>Save — the plugin will connect within 5 minutes</li>
        </ol>
      </div>
    </div>
  )
}
