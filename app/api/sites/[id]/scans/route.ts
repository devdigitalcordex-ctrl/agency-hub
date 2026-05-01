import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { db } from '@/lib/db'

export async function GET(_: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const scans = await db.scan.findMany({
    where: { siteId: params.id },
    orderBy: { startedAt: 'desc' },
    take: 10,
  })

  return NextResponse.json(scans)
}