import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { db } from '@/lib/db'

export async function GET() {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

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

  // Mark sites as offline if no heartbeat in 10 minutes
  const now = Date.now()
  const updated = sites.map(site => ({
    ...site,
    status: site.lastSeen && (now - site.lastSeen.getTime()) > 600000
      ? 'offline'
      : site.status,
  }))

  return NextResponse.json(updated)
}

export async function POST(req: NextRequest) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const body = await req.json()
  const { name, url, notes } = body

  if (!name || !url) {
    return NextResponse.json({ error: 'Name and URL required' }, { status: 400 })
  }

  const site = await db.site.create({
    data: {
      name,
      url: url.replace(/\/$/, ''),
      notes,
    },
  })

  return NextResponse.json(site)
}
