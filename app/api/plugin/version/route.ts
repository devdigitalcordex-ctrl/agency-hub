import { NextResponse } from 'next/server'
import { readFileSync } from 'fs'
import { join } from 'path'

export async function GET() {
  try {
    const f = readFileSync(join(process.cwd(), 'plugin/agency-hub.php'), 'utf8')
    const m = f.match(/Version:\s*([0-9.]+)/)
    const version = m ? m[1] : '1.2.3'
    const hubUrl = process.env.NEXTAUTH_URL || 'https://agency-hub-gamma.vercel.app'
    return NextResponse.json({ 
      version,
      download_url: `${hubUrl}/api/plugin/download?token=${process.env.PLUGIN_DOWNLOAD_TOKEN || 'agency-hub-dl-2026'}`
    })
  } catch {
    return NextResponse.json({ version: '1.2.3' })
  }
}
