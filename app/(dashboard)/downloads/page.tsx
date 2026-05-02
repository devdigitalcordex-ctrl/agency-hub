import { auth } from '@/lib/auth'
import { redirect } from 'next/navigation'
import { readFileSync } from 'fs'
import { join } from 'path'
import { Download, Github, RefreshCw, Package } from 'lucide-react'

export default async function DownloadsPage() {
  const session = await auth()
  if (!session) redirect('/login')

  let version = '1.2.0'
  try {
    const f = readFileSync(join(process.cwd(), 'plugin/agency-hub.php'), 'utf8')
    const m = f.match(/Version:\s*([0-9.]+)/)
    if (m) version = m[1]
  } catch {}

  return (
    <div className="p-8 max-w-2xl">
      <div className="mb-8">
        <h1 className="text-xl font-bold text-white mb-1">Plugin Downloads</h1>
        <p className="text-sm text-slate-500">Install this plugin on any WordPress site to connect it to the Hub.</p>
      </div>

      <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl p-6 mb-4">
        <div className="flex items-start justify-between mb-5">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-[#5185C8]/10 rounded-lg flex items-center justify-center">
              <Package size={18} className="text-[#5185C8]" />
            </div>
            <div>
              <h2 className="text-white font-semibold text-sm">Agency Hub WordPress Plugin</h2>
              <p className="text-xs text-slate-500 mt-0.5">Version <span className="text-[#5185C8]">v{version}</span></p>
            </div>
          </div>
          <span className="text-xs bg-green-500/10 text-green-400 border border-green-500/20 px-2 py-1 rounded-full">Latest</span>
        </div>

        
          href="/api/plugin/download"
          className="flex items-center justify-center gap-2 bg-[#5185C8] hover:bg-[#4070b8] text-white text-sm font-medium px-4 py-2.5 rounded-lg transition w-full mb-3"
        >
          <Download size={14} />
          Download agency-hub-v{version}.zip
        </a>

        
          href="https://github.com/devdigitalcordex-ctrl/agency-hub/releases"
          target="_blank"
          rel="noopener noreferrer"
          className="flex items-center justify-center gap-2 bg-[#1e1e2e] hover:bg-[#252535] text-slate-300 text-sm px-4 py-2.5 rounded-lg transition w-full"
        >
          <Github size={14} />
          View all releases on GitHub
        </a>

        <p className="text-xs text-slate-600 flex items-center gap-1.5 mt-4 pt-4 border-t border-[#1e1e2e]">
          <RefreshCw size={11} />
          Sites auto-update when you push a new plugin version to GitHub
        </p>
      </div>

      <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl p-6">
        <h3 className="text-white font-semibold text-sm mb-4">Installation Guide</h3>
        <ol className="space-y-3">
          {[
            'Download the ZIP file above',
            'Go to WordPress Admin → Plugins → Add New → Upload Plugin',
            'Upload the ZIP and click Install Now → Activate',
            'Go to Settings → Agency Hub',
            'Enter Hub URL: https://agency-hub-gamma.vercel.app',
            'Paste your Connection Key from the site page in Hub',
            'Click Save Settings → Test Connection Now',
            'Site will appear Online in the Hub within seconds',
          ].map((step, i) => (
            <li key={i} className="flex items-start gap-3">
              <span className="w-5 h-5 rounded-full bg-[#5185C8]/10 text-[#5185C8] text-xs flex items-center justify-center flex-shrink-0 mt-0.5">{i + 1}</span>
              <span className="text-xs text-slate-400">{step}</span>
            </li>
          ))}
        </ol>
      </div>
    </div>
  )
}
