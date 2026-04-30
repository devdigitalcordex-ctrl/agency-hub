import { db } from '@/lib/db'
import { auth } from '@/lib/auth'
import Link from 'next/link'
import { ShieldAlert, CheckCircle } from 'lucide-react'
import { timeAgo, severityColor } from '@/lib/utils'

export const dynamic = 'force-dynamic'

export default async function AlertsPage() {
  await auth()

  const alerts = await db.alert.findMany({
    where: { resolved: false },
    orderBy: { createdAt: 'desc' },
    include: { site: { select: { id: true, name: true } } },
  })

  const grouped = alerts.reduce((acc: Record<string, typeof alerts>, alert) => {
    const key = alert.severity
    if (!acc[key]) acc[key] = []
    acc[key].push(alert)
    return acc
  }, {})

  return (
    <div className="p-8">
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-white">Alerts</h1>
        <p className="text-sm text-slate-500 mt-0.5">{alerts.length} unresolved alert{alerts.length !== 1 ? 's' : ''}</p>
      </div>

      {alerts.length === 0 ? (
        <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl p-16 text-center">
          <CheckCircle size={32} className="text-green-400 mx-auto mb-3" />
          <p className="text-slate-400 font-medium">All clear</p>
          <p className="text-sm text-slate-600 mt-1">No open alerts across all sites.</p>
        </div>
      ) : (
        <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl overflow-hidden">
          <table className="w-full">
            <thead>
              <tr className="border-b border-[#1e1e2e]">
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">Severity</th>
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">Alert</th>
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">Site</th>
                <th className="text-left text-xs font-medium text-slate-600 uppercase tracking-wide px-6 py-3">Time</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#1e1e2e]">
              {alerts.map(alert => (
                <tr key={alert.id} className="hover:bg-white/2 transition">
                  <td className="px-6 py-4">
                    <span className={`text-xs font-semibold border px-2 py-0.5 rounded-full ${severityColor(alert.severity)}`}>
                      {alert.severity}
                    </span>
                  </td>
                  <td className="px-6 py-4">
                    <p className="text-sm font-medium text-slate-200">{alert.title}</p>
                    <p className="text-xs text-slate-600 mt-0.5 max-w-sm truncate">{alert.message}</p>
                  </td>
                  <td className="px-6 py-4">
                    <Link href={`/sites/${alert.site.id}`} className="text-sm text-[#5185C8] hover:underline">
                      {alert.site.name}
                    </Link>
                  </td>
                  <td className="px-6 py-4">
                    <span className="text-xs text-slate-500">{timeAgo(alert.createdAt)}</span>
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
