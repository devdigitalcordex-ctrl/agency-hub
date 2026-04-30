import { NextRequest, NextResponse } from 'next/server'
import { db } from '@/lib/db'
import { setSiteStatus } from '@/lib/redis'
import { sendAlertEmail } from '@/lib/email'

export async function POST(req: NextRequest) {
  try {
    const body = await req.json()
    const { site_key, status, data } = body

    if (!site_key) {
      return NextResponse.json({ error: 'Missing site_key' }, { status: 400 })
    }

    const site = await db.site.findUnique({ where: { siteKey: site_key } })

    if (!site) {
      return NextResponse.json({ error: 'Unknown site' }, { status: 404 })
    }

    // Was site offline before?
    const wasOffline = site.status === 'offline'
    const newStatus = status || 'online'

    // Update site with latest info
    await db.site.update({
      where: { id: site.id },
      data: {
        status: newStatus,
        lastSeen: new Date(),
        wpVersion: data?.wp_version || site.wpVersion,
        phpVersion: data?.php_version || site.phpVersion,
        pluginVersion: data?.plugin_version || site.pluginVersion,
        adminEmail: data?.admin_email || site.adminEmail,
      },
    })

    // Cache status in Redis
    await setSiteStatus(site.id, newStatus)

    // If back online after being offline, create alert
    if (wasOffline && newStatus === 'online') {
      await db.alert.create({
        data: {
          siteId: site.id,
          type: 'site_recovered',
          severity: 'info',
          title: 'Site is back online',
          message: `${site.name} has reconnected and is sending heartbeats again.`,
        },
      })
    }

    // Process alerts from plugin if included
    if (data?.alerts && Array.isArray(data.alerts)) {
      for (const alert of data.alerts) {
        const existing = await db.alert.findFirst({
          where: {
            siteId: site.id,
            type: alert.type,
            resolved: false,
            createdAt: { gte: new Date(Date.now() - 3600000) }, // dedup within 1hr
          },
        })

        if (!existing) {
          const created = await db.alert.create({
            data: {
              siteId: site.id,
              type: alert.type || 'unknown',
              severity: alert.severity || 'medium',
              title: alert.title || 'Security Alert',
              message: alert.message || '',
              meta: alert.meta || {},
            },
          })

          // Send email for high/critical alerts
          if (['high', 'critical'].includes(created.severity) && site.adminEmail) {
            try {
              await sendAlertEmail({
                to: site.adminEmail,
                siteName: site.name,
                alertTitle: created.title,
                alertMessage: created.message,
                severity: created.severity,
                siteUrl: site.url,
              })
              await db.alert.update({
                where: { id: created.id },
                data: { emailSent: true },
              })
            } catch (e) {
              console.error('Email send failed:', e)
            }
          }
        }
      }
    }

    // Process activity logs if included
    if (data?.logs && Array.isArray(data.logs)) {
      const logData = data.logs.map((log: any) => ({
        siteId: site.id,
        eventType: log.event_type || 'unknown',
        category: log.event_category || 'general',
        severity: log.severity || 'info',
        message: log.message || '',
        userIp: log.user_ip || null,
        userAgent: log.user_agent || null,
        userId: log.user_id ? String(log.user_id) : null,
        username: log.username || null,
        objectType: log.object_type || null,
        objectId: log.object_id ? String(log.object_id) : null,
        meta: log.meta || {},
        occurredAt: log.occurred_at ? new Date(log.occurred_at) : new Date(),
      }))

      if (logData.length > 0) {
        await db.activityLog.createMany({ data: logData })
      }
    }

    // Return any pending commands for this site
    const pendingCommands = await db.command.findMany({
      where: { siteId: site.id, status: 'pending' },
      orderBy: { createdAt: 'asc' },
    })

    // Mark them as sent
    if (pendingCommands.length > 0) {
      await db.command.updateMany({
        where: { id: { in: pendingCommands.map(c => c.id) } },
        data: { status: 'sent' },
      })
    }

    return NextResponse.json({
      success: true,
      commands: pendingCommands.map(c => ({
        id: c.id,
        type: c.type,
        payload: c.payload,
      })),
    })
  } catch (err) {
    console.error('Heartbeat error:', err)
    return NextResponse.json({ error: 'Internal error' }, { status: 500 })
  }
}
