import { redirect } from 'next/navigation'
import { auth } from '@/lib/auth'
import Sidebar from '@/components/layout/Sidebar'

export default async function DashboardLayout({ children }: { children: React.ReactNode }) {
  const session = await auth()
  if (!session) redirect('/login')

  return (
    <div className="flex h-screen overflow-hidden bg-[#0A0A12]">
      <Sidebar user={session.user} />
      <main className="flex-1 overflow-y-auto scrollbar-thin">
        {children}
      </main>
    </div>
  )
}
