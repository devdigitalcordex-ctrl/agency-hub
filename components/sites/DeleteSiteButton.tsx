'use client'
import { useState } from 'react'
import { Trash2, Loader } from 'lucide-react'
import { useRouter } from 'next/navigation'

export default function DeleteSiteButton({ siteId, siteName }: { siteId: string; siteName: string }) {
  const [confirming, setConfirming] = useState(false)
  const [loading, setLoading] = useState(false)
  const router = useRouter()

  async function deleteSite() {
    setLoading(true)
    await fetch(`/api/sites/${siteId}`, { method: 'DELETE' })
    router.push('/sites')
    router.refresh()
  }

  if (confirming) return (
    <div className="flex items-center gap-2">
      <span className="text-xs text-slate-400">Delete {siteName}?</span>
      <button onClick={deleteSite} disabled={loading}
        className="text-xs bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 px-3 py-1.5 rounded-lg transition">
        {loading ? <Loader size={10} className="animate-spin" /> : 'Yes, delete'}
      </button>
      <button onClick={() => setConfirming(false)}
        className="text-xs text-slate-500 hover:text-slate-300 px-3 py-1.5 rounded-lg transition">
        Cancel
      </button>
    </div>
  )

  return (
    <button onClick={() => setConfirming(true)}
      className="flex items-center gap-1.5 text-xs text-slate-500 hover:text-red-400 transition">
      <Trash2 size={13} />
      Delete Site
    </button>
  )
}
