import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { db } from '@/lib/db'

export async function GET(req: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { searchParams } = new URL(req.url)
  const severity = searchParams.get('severity')
  const category = searchParams.get('category')
  const take = parseInt(searchParams.get('take') || '100')
  const skip = parseInt(searchParams.get('skip') || '0')

  const where: any = { siteId: params.id }
  if (severity) where.severity = severity
  if (category) where.category = category

  const [logs, total] = await Promise.all([
    db.activityLog.findMany({
      where,
      orderBy: { occurredAt: 'desc' },
      take,
      skip,
    }),
    db.activityLog.count({ where }),
  ])

  return NextResponse.json({ logs, total })
}
