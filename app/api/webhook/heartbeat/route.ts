import { NextRequest, NextResponse } from 'next/server'
import { db } from '@/lib/db'
import { setSiteStatus } from '@/lib/redis'
import { sendAlertEmail } from '@/lib/email'

export async function POST(req: NextRequest) {
  try {
    const body = await req.json()
    const { site_key, status, data } = body
console.log('HB_ALERTS:', JSON.stringify(data?.alerts))
    if (!site_key) {
      return NextResponse.json({ error: 'Missing site_key' }, { status: 400 })
    }

    const site = await db.site.findUnique({ where: { siteKey: site_key } })

    if (!site) {
      return NextResponse.json({ error: 'Unknown site' }, { status: 404 })
    }

    const wasOffline = site.status === 'offline'
    const newStatus = status || 'online'

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

    await setSiteStatus(site.id, newStatus)

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

    if (data?.alerts && Array.isArray(data.alerts)) {
      for (const alert of data.alerts) {
        // Handle backup_complete alerts
        if (alert.type === 'backup_complete') {
          await db.backup.create({
            data: {
              siteId: site.id,
              type: alert.components?.includes('files') ? 'full' : (alert.components?.includes('database') ? 'db' : 'full'),
              status: 'complete',
              size: alert.file_size || 0,
              downloadUrl: alert.download_link || null,
              completedAt: new Date(),
            },
          }).catch(() => {})
          continue
        }
// Handle scan_complete alerts sent directly by plugin scanner
        if (alert.type === 'scan_complete') {
          await db.scan.create({
            data: {
              siteId: site.id,
              status: (alert.threats_found || 0) > 0 ? 'complete' : 'complete',
              triggeredBy: 'hub',
              totalFiles: alert.total_files || 0,
              threats: alert.threats_found || 0,
              findings: alert.findings || [],
              completedAt: new Date(),
            },
          })
          continue
        }
        // ── COMMAND RESULTS sent back via alerts channel ──

        if (alert.type === 'command_result') {
          if (alert.command_id) {
            await db.command.updateMany({
              where: { id: alert.command_id, siteId: site.id },
              data: { status: alert.status === 'complete' ? 'completed' : 'failed' },
            }).catch(() => {})
          }

          const r = alert.result || {}

          // Scan: plugin returns findings[], threats_found, total_files
          if (r.findings !== undefined || r.threats_found !== undefined) {
            await db.scan.create({
              data: {
                siteId: site.id,
                status: alert.status === 'complete' ? 'complete' : 'failed',
                triggeredBy: 'hub',
                totalFiles: r.total_files || 0,
                threats: r.threats_found || 0,
                findings: r.findings || [],
                completedAt: new Date(),
              },
            })
            if (Array.isArray(r.findings) && r.findings.length > 0) {
              await db.alert.create({
                data: {
                  siteId: site.id,
                  type: 'malware_found',
                  severity: 'critical',
                  title: `Malware Detected: ${r.findings.length} threat(s) found`,
                  message: r.findings.map((t: any) => t.file || t.threat || '').join(', '),
                  meta: { threats: r.findings },
                },
              })
            }
          }

          // Backup: plugin returns file_size, download_link, filename, backup_id
          if (r.backup_id !== undefined || r.filename !== undefined) {
            await db.backup.create({
              data: {
                siteId: site.id,
                type: Array.isArray(r.components) && r.components.includes('files') ? 'full' : 'db',
                status: alert.status === 'complete' ? 'complete' : 'failed',
                size: r.file_size || 0,
                downloadUrl: r.download_link || null,
                completedAt: new Date(),
              },
            }).catch(() => {})
          }

          continue
        }

        // ── REGULAR ALERTS ──
        const existing = await db.alert.findFirst({
          where: {
            siteId: site.id,
            type: alert.type,
            resolved: false,
            createdAt: { gte: new Date(Date.now() - 3600000) },
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

    // Activity logs — plugin sends user_login not username
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
        username: log.user_login || log.username || null,
        objectType: log.object_type || null,
        objectId: log.object_id ? String(log.object_id) : null,
        meta: {
          user_role: log.user_role || null,
          object_name: log.object_name || null,
          before_value: log.before_value || null,
          after_value: log.after_value || null,
          is_flagged: log.is_flagged || 0,
          ...(log.meta || {}),
        },
        occurredAt: log.occurred_at ? new Date(log.occurred_at) : new Date(),
      }))

      if (logData.length > 0) {
        await db.activityLog.createMany({ data: logData, skipDuplicates: true })
      }
    }

    // Return pending commands to plugin
    const pendingCommands = await db.command.findMany({
      where: { siteId: site.id, status: 'pending' },
      orderBy: { createdAt: 'asc' },
    })

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