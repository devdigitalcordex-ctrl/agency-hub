import { Resend } from 'resend'

const resend = new Resend(process.env.RESEND_API_KEY)
const FROM = 'Agency Hub <alerts@digitalcordex.com>'

export async function sendAlertEmail({
  to,
  siteName,
  alertTitle,
  alertMessage,
  severity,
  siteUrl,
}: {
  to: string
  siteName: string
  alertTitle: string
  alertMessage: string
  severity: string
  siteUrl: string
}) {
  const color: Record<string, string> = {
    critical: '#ef4444',
    high: '#f97316',
    medium: '#eab308',
    low: '#22c55e',
    info: '#5185C8',
  }

  const html = `
    <div style="font-family:sans-serif;max-width:600px;margin:0 auto;background:#0A0A12;color:#e2e8f0;padding:32px;border-radius:12px">
      <div style="margin-bottom:24px">
        <img src="https://digitalcordex.com/wp-content/uploads/2024/10/Untitled-1.png" height="32" alt="Digital Cordex" />
      </div>
      <div style="background:#13131f;border:1px solid #1e1e2e;border-radius:8px;padding:24px;margin-bottom:24px">
        <div style="display:flex;align-items:center;margin-bottom:16px">
          <span style="background:${color[severity] || '#5185C8'};color:#fff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:1px">${severity}</span>
        </div>
        <h2 style="margin:0 0 8px;color:#fff;font-size:20px">${alertTitle}</h2>
        <p style="margin:0 0 16px;color:#94a3b8;line-height:1.6">${alertMessage}</p>
        <div style="background:#0A0A12;border-radius:6px;padding:12px 16px;font-size:13px;color:#64748b">
          Site: <a href="${siteUrl}" style="color:#5185C8">${siteName}</a>
        </div>
      </div>
      <a href="${process.env.NEXTAUTH_URL}/alerts" style="display:inline-block;background:#5185C8;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600">
        View in Agency Hub
      </a>
      <p style="margin-top:24px;font-size:12px;color:#475569">Digital Cordex Agency Hub — automated security monitoring</p>
    </div>
  `

  await resend.emails.send({
    from: FROM,
    to,
    subject: `[${severity.toUpperCase()}] ${alertTitle} — ${siteName}`,
    html,
  })
}

export async function sendSiteOfflineEmail({
  to,
  siteName,
  siteUrl,
  lastSeen,
}: {
  to: string
  siteName: string
  siteUrl: string
  lastSeen: string
}) {
  await sendAlertEmail({
    to,
    siteName,
    alertTitle: 'Site is offline',
    alertMessage: `${siteName} has stopped sending heartbeats. Last seen: ${lastSeen}. The site may be down or the Agency Hub plugin has been deactivated.`,
    severity: 'high',
    siteUrl,
  })
}
