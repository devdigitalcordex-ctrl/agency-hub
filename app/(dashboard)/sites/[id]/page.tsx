import { notFound } from 'next/navigation'
import { db } from '@/lib/db'
import { auth } from '@/lib/auth'
import { timeAgo, formatBytes, severityColor, cn } from '@/lib/utils'
import Link from 'next/link'
import {
  ArrowLeft, Globe, ShieldCheck, Database, Activity,
  Shield, Clock, AlertTriangle, CheckCircle, ExternalLink,
  Cpu, Code, RefreshCw
} from 'lucide-react'
import ScanSection from '@/components/sites/ScanSection'
import BackupSection from '@/components/sites/BackupSection'
import IpBlockingSection from '@/components/sites/IpBlockingSection'
import AlertsSection from '@/components/sites/AlertsSection'
import LogsSection from '@/components/sites/LogsSection'

export const dynamic = 'force-dynamic'

export default async function SiteDetailPage({ params }: { params: { id: string } }) {
  await auth()

  const site = await db.site.findUnique({
    where: { id: params.id },
    include: {
      alerts: {
        where: { resolved: false },
        orderBy: { createdAt: 'desc' },
        take: 20,
      },
      scans: {
        orderBy: { startedAt: 'desc' },
        take: 10,
      },
      backups: {
        orderBy: { createdAt: 'desc' },
        take: 10,
      },
      ipRules: {
        orderBy: { createdAt: 'desc' },
      },
    },
  })

  if (!site) notFound()

  const now = Date.now()
  const status = site.lastSeen && (now - site.lastSeen.getTime()) > 600000 ? 'offline' : site.status

  const statusConfig: Record<string, { label: string; color: string; dot: string }> = {
    online: { label: 'Online', color: 'text-green-400 bg-green-400/10 border-green-400/20', dot: 'bg-green-400' },
    offline: { label: 'Offline', color: 'text-red-400 bg-red-400/10 border-red-400/20', dot: 'bg-red-400' },
    warning: { label: 'Warning', color: 'text-yellow-400 bg-yellow-400/10 border-yellow-400/20', dot: 'bg-yellow-400' },
    unknown: { label: 'Unknown', color: 'text-slate-400 bg-slate-400/10 border-slate-400/20', dot: 'bg-slate-500' },
  }
  const s = statusConfig[status] ?? statusConfig.unknown

  return (
    <div className="p-8">
      {/* Back */}
      <Link href="/" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-300 transition mb-6">
        <ArrowLeft size={14} />
        Dashboard
      </Link>

      {/* Site header */}
      <div className="flex items-start justify-between mb-8">
        <div className="flex items-start gap-4">
          <div className="w-12 h-12 rounded-xl bg-[#5185C8]/10 border border-[#5185C8]/20 flex items-center justify-center flex-shrink-0">
            <Globe size={20} className="text-[#5185C8]" />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-white">{site.name}</h1>
            <a
              href={site.url}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#5185C8] transition mt-0.5"
            >
              {site.url}
              <ExternalLink size={11} />
            </a>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <span className={`inline-flex items-center gap-1.5 text-xs font-semibold border px-3 py-1.5 rounded-full ${s.color}`}>
            <span className={`w-1.5 h-1.5 rounded-full ${s.dot}`} />
            {s.label}
          </span>
        </div>
      </div>

      {/* Info bar */}
      <div className="grid grid-cols-4 gap-4 mb-8">
        {[
          { icon: Clock, label: 'Last Seen', value: site.lastSeen ? timeAgo(site.lastSeen) : 'Never' },
          { icon: Code, label: 'WordPress', value: site.wpVersion || '—' },
          { icon: Cpu, label: 'PHP', value: site.phpVersion || '—' },
          { icon: Shield, label: 'Plugin', value: site.pluginVersion || '—' },
        ].map(({ icon: Icon, label, value }) => (
          <div key={label} className="bg-[#13131f] border border-[#1e1e2e] rounded-xl px-5 py-4">
            <div className="flex items-center gap-2 mb-1.5">
              <Icon size={13} className="text-slate-600" />
              <p className="text-xs text-slate-600 uppercase tracking-wide font-medium">{label}</p>
            </div>
            <p className="text-sm font-semibold text-slate-200">{value}</p>
          </div>
        ))}
      </div>

      {/* Plugin install key */}
      <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl px-6 py-4 mb-8">
        <p className="text-xs text-slate-600 uppercase tracking-wide font-medium mb-2">Plugin Connection Key</p>
        <div className="flex items-center gap-3">
          <code className="text-sm font-mono text-[#5185C8] bg-[#5185C8]/5 border border-[#5185C8]/10 px-3 py-1.5 rounded-lg flex-1 select-all">
            {site.siteKey}
          </code>
          <span className="text-xs text-slate-600">Paste this in the plugin settings on the WordPress site</span>
        </div>
      </div>

      {/* Open alerts */}
      {site.alerts.length > 0 && (
        <div className="mb-8">
          <AlertsSection siteId={site.id} initialAlerts={site.alerts} />
        </div>
      )}

      {/* Main sections */}
      <div className="grid grid-cols-2 gap-6 mb-6">
        <ScanSection siteId={site.id} scans={site.scans} />
        <BackupSection siteId={site.id} backups={site.backups} />
      </div>

      {/* IP Blocking */}
      <div className="mb-6">
        <IpBlockingSection
          siteId={site.id}
          initialRules={site.ipRules}
          initialAllowlistMode={site.allowlistMode}
        />
      </div>

      {/* Activity Logs */}
      <div>
        <LogsSection siteId={site.id} />
      </div>
    </div>
  )
}
