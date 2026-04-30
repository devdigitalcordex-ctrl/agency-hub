import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { db } from '@/lib/db'

export async function GET(_: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const site = await db.site.findUnique({
    where: { id: params.id },
    select: { allowlistMode: true },
  })

  const rules = await db.ipRule.findMany({
    where: { siteId: params.id },
    orderBy: { createdAt: 'desc' },
  })

  return NextResponse.json({
    allowlistMode: site?.allowlistMode ?? false,
    allowlist: rules.filter(r => r.type.startsWith('allowlist')),
    blocklist: rules.filter(r => !r.type.startsWith('allowlist')),
  })
}

export async function POST(req: NextRequest, { params }: { params: { id: string } }) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const body = await req.json()
  const { action, type, value, label, reason, allowlistMode } = body

  // Toggle allowlist mode
  if (action === 'set_mode') {
    await db.site.update({
      where: { id: params.id },
      data: { allowlistMode: allowlistMode === true },
    })
    // Queue command to WP plugin
    await db.command.create({
      data: {
        siteId: params.id,
        type: 'set_allowlist_mode',
        payload: { enabled: allowlistMode === true },
      },
    })
    return NextResponse.json({ success: true })
  }

  // Add IP rule
  if (action === 'add') {
    const rule = await db.ipRule.create({
      data: {
        siteId: params.id,
        type: type || 'blocklist',
        value,
        label,
        reason,
      },
    })
    // Queue command to WP plugin
    const cmdType = type?.startsWith('allowlist') ? 'allowlist_ip' : 'block_ip'
    await db.command.create({
      data: {
        siteId: params.id,
        type: cmdType,
        payload: { ip: value, type, label, reason },
      },
    })
    return NextResponse.json(rule)
  }

  // Remove IP rule
  if (action === 'remove') {
    const rule = await db.ipRule.findUnique({ where: { id: body.ruleId } })
    if (rule) {
      await db.ipRule.delete({ where: { id: rule.id } })
      await db.command.create({
        data: {
          siteId: params.id,
          type: 'remove_ip_rule',
          payload: { ip: rule.value, type: rule.type },
        },
      })
    }
    return NextResponse.json({ success: true })
  }

  return NextResponse.json({ error: 'Invalid action' }, { status: 400 })
}
