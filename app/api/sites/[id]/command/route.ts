import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { db } from '@/lib/db'

export async function POST(req: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const body = await req.json()
  const { type, payload } = body

  const validTypes = ['scan', 'backup', 'clear_cache', '2fa_bypass', 'block_ip', 'allowlist_ip', 'set_allowlist_mode', 'remove_ip_rule']
  if (!validTypes.includes(type)) {
    return NextResponse.json({ error: 'Invalid command type' }, { status: 400 })
  }

  const command = await db.command.create({
    data: {
      siteId: params.id,
      type,
      payload: payload || {},
      status: 'pending',
    },
  })

  return NextResponse.json({ success: true, commandId: command.id })
}

export async function GET(_: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const commands = await db.command.findMany({
    where: { siteId: params.id },
    orderBy: { createdAt: 'desc' },
    take: 20,
  })

  return NextResponse.json(commands)
}
