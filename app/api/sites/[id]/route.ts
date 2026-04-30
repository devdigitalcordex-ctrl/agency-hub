import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { db } from '@/lib/db'

export async function GET(_: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const site = await db.site.findUnique({
    where: { id: params.id },
    include: {
      alerts: {
        where: { resolved: false },
        orderBy: { createdAt: 'desc' },
        take: 10,
      },
      scans: {
        orderBy: { startedAt: 'desc' },
        take: 5,
      },
      backups: {
        orderBy: { createdAt: 'desc' },
        take: 5,
      },
      ipRules: {
        orderBy: { createdAt: 'desc' },
      },
    },
  })

  if (!site) return NextResponse.json({ error: 'Not found' }, { status: 404 })
  return NextResponse.json(site)
}

export async function PATCH(req: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const body = await req.json()
  const site = await db.site.update({
    where: { id: params.id },
    data: {
      name: body.name,
      notes: body.notes,
      allowlistMode: body.allowlistMode,
    },
  })

  return NextResponse.json(site)
}

export async function DELETE(_: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  await db.site.delete({ where: { id: params.id } })
  return NextResponse.json({ success: true })
}
