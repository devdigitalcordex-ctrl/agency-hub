'use client'
import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { signOut } from 'next-auth/react'
import {
  LayoutDashboard, Globe, ShieldAlert, Settings, LogOut, Bell
} from 'lucide-react'
import { cn } from '@/lib/utils'

const nav = [
  { href: '/', icon: LayoutDashboard, label: 'Dashboard' },
  { href: '/sites', icon: Globe, label: 'Sites' },
  { href: '/alerts', icon: ShieldAlert, label: 'Alerts' },
  { href: '/settings', icon: Settings, label: 'Settings' },
]

export default function Sidebar({ user }: { user: any }) {
  const pathname = usePathname()

  return (
    <aside className="w-56 flex-shrink-0 bg-[#0d0d1a] border-r border-[#1e1e2e] flex flex-col">
      {/* Logo */}
      <div className="p-5 border-b border-[#1e1e2e]">
        <img
          src="https://digitalcordex.com/wp-content/uploads/2024/10/Untitled-1.png"
          alt="Digital Cordex"
          className="h-7 object-contain"
        />
        <p className="text-xs text-slate-600 mt-1.5 font-medium tracking-wide uppercase">Agency Hub</p>
      </div>

      {/* Nav */}
      <nav className="flex-1 p-3 space-y-0.5">
        {nav.map(({ href, icon: Icon, label }) => {
          const active = pathname === href || (href !== '/' && pathname.startsWith(href))
          return (
            <Link
              key={href}
              href={href}
              className={cn(
                'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all',
                active
                  ? 'bg-[#5185C8]/15 text-[#5185C8] font-medium'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-white/5'
              )}
            >
              <Icon size={16} />
              {label}
            </Link>
          )
        })}
      </nav>

      {/* User */}
      <div className="p-3 border-t border-[#1e1e2e]">
        <div className="flex items-center gap-3 px-3 py-2 mb-1">
          <div className="w-7 h-7 rounded-full bg-[#5185C8]/20 flex items-center justify-center text-[#5185C8] text-xs font-bold">
            {user?.name?.[0] || user?.email?.[0] || 'A'}
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-xs font-medium text-slate-300 truncate">{user?.name || 'Admin'}</p>
            <p className="text-xs text-slate-600 truncate">{user?.email}</p>
          </div>
        </div>
        <button
          onClick={() => signOut({ callbackUrl: '/login' })}
          className="flex items-center gap-3 px-3 py-2 w-full rounded-lg text-sm text-slate-500 hover:text-slate-300 hover:bg-white/5 transition"
        >
          <LogOut size={14} />
          Sign out
        </button>
      </div>
    </aside>
  )
}
