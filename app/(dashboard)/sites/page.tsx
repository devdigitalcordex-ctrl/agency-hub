import { db } from '@/lib/db'
import { auth } from '@/lib/auth'
import Link from 'next/link'
import { Globe, Plus, WifiOff, AlertTriangle, CheckCircle } from 'lucide-react'
import { timeAgo } from '@/lib/utils'

export const dynamic = 'force-dynamic'

export default async function SitesPage() {
  await auth()

  const sites = await db.site.findMany({
    orderBy: { createdAt: 'desc' },
    include: {
      _count: {
        select: { alerts: { where: { resolved: false } } },
      },
    },
  })

  const now = Date.now()
  const sitesWithStatus = sites.map(s => ({
    ...s,
    status: s.lastSeen && (now - s.lastSeen.getTime()) > 600000 ? 'offline' : s.status,
  }))

  return (
    <div className="p-8">
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-2xl font-bold text-white">Sites</h1>
          <p className="text-sm text-slate-500 mt-0.5">{sites.length} site{sites.length !== 1 ? 's' : ''} managed</p>
        </div>
        <Link
          href="/sites/new"
          className="flex items-center gap-2 bg-[#5185C8] hover:bg-[#4070b0] text-white text-sm font-semibold px-4 py-2 rounded-lg transition"
        >
          <Plus size={15} />
          Add Site
        </Link>
      </div>

      {sitesWithStatus.length === 0 ? (
        <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl p-16 text-center">
          <Globe size={32} className="text-slate-700 mx-auto mb-3" />
          <p className="text-slate-400 font-medium mb-1">No sites yet</p>
          <p className="text-sm text-slate-600 mb-5">Add your first WordPress site to get started.</p>
          <Link
            href="/sites/new"
            className="inline-flex items-center gap-2 bg-[#5185C8] hover:bg-[#4070b0] text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition"
          >
            <Plus size={14} />
            Add Site
          </Link>
        </div>
      ) : (
        <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl overflow-hidden">
          <table className="w-full">
            <thead>
              <tr className="border-b border-[#1e1e2e]">
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">Site</th>
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">Status</th>
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">Last Seen</th>
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">WordPress</th>
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">Alerts</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#1e1e2e]">
              {sitesWithStatus.map(site => (
                <tr key={site.id} className="hover:bg-white/2 transition group">
                  <td className="px-6 py-4">
                    <Link href={`/sites/${site.id}`} className="block">
                      <p className="text-sm font-medium text-slate-200 group-hover:text-white">{site.name}</p>
                      <p className="text-xs text-slate-600 mt-0.5">{site.url}</p>
                    </Link>
                  </td>
                  <td className="px-6 py-4">
                    <StatusBadge status={site.status} />
                  </td>
                  <td className="px-6 py-4">
                    <span className="text-xs text-slate-500">{site.lastSeen ? timeAgo(site.lastSeen) : 'Never'}</span>
                  </td>
                  <td className="px-6 py-4">
                    <span className="text-xs text-slate-500">{site.wpVersion || '—'}</span>
                  </td>
                  <td className="px-6 py-4">
                    {site._count.alerts > 0 ? (
                      <span className="text-xs bg-orange-500/15 text-orange-400 border border-orange-500/20 px-2 py-0.5 rounded-full">
                        {site._count.alerts}
                      </span>
                    ) : (
                      <span className="text-xs text-slate-700">—</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function StatusBadge({ status }: { status: string }) {
  const config: Record<string, { label: string; class: string }> = {
    online: { label: 'Online', class: 'text-green-400 bg-green-400/10 border-green-400/20' },
    offline: { label: 'Offline', class: 'text-red-400 bg-red-400/10 border-red-400/20' },
    warning: { label: 'Warning', class: 'text-yellow-400 bg-yellow-400/10 border-yellow-400/20' },
    unknown: { label: 'Unknown', class: 'text-slate-500 bg-slate-500/10 border-slate-500/20' },
  }
  const c = config[status] ?? config.unknown
  return (
    <span className={`text-xs font-medium border px-2.5 py-1 rounded-full ${c.class}`}>{c.label}</span>
  )
}
