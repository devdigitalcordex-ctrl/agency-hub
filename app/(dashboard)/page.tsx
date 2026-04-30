import { auth } from '@/lib/auth'
import { db } from '@/lib/db'
import Link from 'next/link'
import { Globe, ShieldAlert, CheckCircle, Clock, WifiOff, AlertTriangle, Plus } from 'lucide-react'
import { timeAgo, cn } from '@/lib/utils'

export const dynamic = 'force-dynamic'

export default async function DashboardPage() {
  const session = await auth()

  const sites = await db.site.findMany({
    orderBy: { createdAt: 'desc' },
    include: {
      _count: {
        select: {
          alerts: { where: { resolved: false } },
        },
      },
    },
  })

  const now = Date.now()
  const sitesWithStatus = sites.map(site => ({
    ...site,
    status: site.lastSeen && (now - site.lastSeen.getTime()) > 600000 ? 'offline' : site.status,
  }))

  const online = sitesWithStatus.filter(s => s.status === 'online').length
  const offline = sitesWithStatus.filter(s => s.status === 'offline').length
  const warnings = sitesWithStatus.filter(s => s.status === 'warning').length
  const totalAlerts = sitesWithStatus.reduce((a, s) => a + s._count.alerts, 0)

  const recentAlerts = await db.alert.findMany({
    where: { resolved: false },
    orderBy: { createdAt: 'desc' },
    take: 5,
    include: { site: { select: { name: true, id: true } } },
  })

  return (
    <div className="p-8">
      {/* Header */}
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-2xl font-bold text-white">Dashboard</h1>
          <p className="text-slate-500 text-sm mt-0.5">Welcome back, {session?.user?.name || 'Admin'}</p>
        </div>
        <Link
          href="/sites/new"
          className="flex items-center gap-2 bg-[#5185C8] hover:bg-[#4070b0] text-white text-sm font-semibold px-4 py-2 rounded-lg transition"
        >
          <Plus size={15} />
          Add Site
        </Link>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-4 gap-4 mb-8">
        {[
          { label: 'Total Sites', value: sites.length, icon: Globe, color: 'text-slate-400' },
          { label: 'Online', value: online, icon: CheckCircle, color: 'text-green-400' },
          { label: 'Offline', value: offline, icon: WifiOff, color: 'text-red-400' },
          { label: 'Open Alerts', value: totalAlerts, icon: ShieldAlert, color: 'text-orange-400' },
        ].map(({ label, value, icon: Icon, color }) => (
          <div key={label} className="bg-[#13131f] border border-[#1e1e2e] rounded-xl p-5">
            <div className="flex items-center justify-between mb-3">
              <p className="text-sm text-slate-500">{label}</p>
              <Icon size={16} className={color} />
            </div>
            <p className={`text-3xl font-bold ${color}`}>{value}</p>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-3 gap-6">
        {/* Sites list */}
        <div className="col-span-2 bg-[#13131f] border border-[#1e1e2e] rounded-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-[#1e1e2e]">
            <h2 className="font-semibold text-white text-sm">Sites</h2>
            <Link href="/sites" className="text-xs text-[#5185C8] hover:underline">View all</Link>
          </div>
          <div className="divide-y divide-[#1e1e2e]">
            {sitesWithStatus.length === 0 ? (
              <div className="px-6 py-10 text-center text-slate-500 text-sm">
                No sites yet.{' '}
                <Link href="/sites/new" className="text-[#5185C8] hover:underline">Add your first site</Link>
              </div>
            ) : (
              sitesWithStatus.slice(0, 8).map(site => (
                <Link
                  key={site.id}
                  href={`/sites/${site.id}`}
                  className="flex items-center gap-4 px-6 py-3.5 hover:bg-white/3 transition group"
                >
                  <StatusDot status={site.status} />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-slate-200 group-hover:text-white truncate">{site.name}</p>
                    <p className="text-xs text-slate-600 truncate">{site.url}</p>
                  </div>
                  <div className="flex items-center gap-3 flex-shrink-0">
                    {site._count.alerts > 0 && (
                      <span className="text-xs bg-orange-500/15 text-orange-400 border border-orange-500/20 px-2 py-0.5 rounded-full">
                        {site._count.alerts} alert{site._count.alerts > 1 ? 's' : ''}
                      </span>
                    )}
                    <span className="text-xs text-slate-600">
                      {site.lastSeen ? timeAgo(site.lastSeen) : 'never'}
                    </span>
                  </div>
                </Link>
              ))
            )}
          </div>
        </div>

        {/* Recent alerts */}
        <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-[#1e1e2e]">
            <h2 className="font-semibold text-white text-sm">Recent Alerts</h2>
            <Link href="/alerts" className="text-xs text-[#5185C8] hover:underline">View all</Link>
          </div>
          <div className="divide-y divide-[#1e1e2e]">
            {recentAlerts.length === 0 ? (
              <div className="px-6 py-10 text-center">
                <CheckCircle size={24} className="text-green-400 mx-auto mb-2" />
                <p className="text-sm text-slate-500">No open alerts</p>
              </div>
            ) : (
              recentAlerts.map(alert => (
                <Link
                  key={alert.id}
                  href={`/sites/${alert.site.id}`}
                  className="block px-5 py-3.5 hover:bg-white/3 transition"
                >
                  <div className="flex items-start gap-2.5">
                    <SeverityDot severity={alert.severity} />
                    <div className="flex-1 min-w-0">
                      <p className="text-xs font-medium text-slate-300 truncate">{alert.title}</p>
                      <p className="text-xs text-slate-600 mt-0.5">{alert.site.name}</p>
                      <p className="text-xs text-slate-700 mt-0.5">{timeAgo(alert.createdAt)}</p>
                    </div>
                  </div>
                </Link>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

function StatusDot({ status }: { status: string }) {
  const colors: Record<string, string> = {
    online: 'bg-green-400',
    offline: 'bg-red-400',
    warning: 'bg-yellow-400',
    unknown: 'bg-slate-600',
  }
  return (
    <span className="relative flex-shrink-0">
      <span className={`w-2 h-2 rounded-full block ${colors[status] ?? colors.unknown}`} />
      {status === 'online' && (
        <span className={`absolute inset-0 rounded-full animate-ping opacity-40 ${colors[status]}`} />
      )}
    </span>
  )
}

function SeverityDot({ severity }: { severity: string }) {
  const colors: Record<string, string> = {
    critical: 'bg-red-400',
    high: 'bg-orange-400',
    medium: 'bg-yellow-400',
    low: 'bg-green-400',
    info: 'bg-blue-400',
  }
  return <span className={`w-1.5 h-1.5 rounded-full flex-shrink-0 mt-1.5 ${colors[severity] ?? colors.info}`} />
}
