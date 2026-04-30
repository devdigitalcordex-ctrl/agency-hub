import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { db } from '@/lib/db'

export async function GET(req: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { searchParams } = new URL(req.url)
  const resolved = searchParams.get('resolved') === 'true'
  const take = parseInt(searchParams.get('take') || '50')

  const alerts = await db.alert.findMany({
    where: { siteId: params.id, resolved },
    orderBy: { createdAt: 'desc' },
    take,
  })

  return NextResponse.json(alerts)
}

export async function PATCH(req: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const body = await req.json()

  // Resolve single or all alerts
  if (body.resolveAll) {
    await db.alert.updateMany({
      where: { siteId: params.id, resolved: false },
      data: { resolved: true, resolvedAt: new Date() },
    })
  } else if (body.alertId) {
    await db.alert.update({
      where: { id: body.alertId },
      data: { resolved: true, resolvedAt: new Date() },
    })
  }

  return NextResponse.json({ success: true })
}
