import { NextResponse } from 'next/server'
import { readFileSync } from 'fs'
import { join } from 'path'

export async function GET() {
  try {
    const f = readFileSync(join(process.cwd(), 'plugin/agency-hub.php'), 'utf8')
    const m = f.match(/Version:\s*([0-9.]+)/)
    const version = m ? m[1] : '1.2.1'
    return NextResponse.json({ version })
  } catch {
    return NextResponse.json({ version: '1.2.1' })
  }
}
