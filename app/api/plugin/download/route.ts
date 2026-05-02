import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { readFileSync } from 'fs'
import { join } from 'path'
import { execSync } from 'child_process'

export async function GET(req: NextRequest) {
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  try {
    const pluginDir = join(process.cwd(), 'plugin')
    const zipPath = '/tmp/agency-hub-latest.zip'
    execSync(`cd "${pluginDir}" && zip -r "${zipPath}" . -x "*.DS_Store" -x "__MACOSX/*"`)
    const file = readFileSync(zipPath)
    return new NextResponse(file, {
      headers: {
        'Content-Type': 'application/zip',
        'Content-Disposition': 'attachment; filename="agency-hub-latest.zip"',
      },
    })
  } catch (e) {
    return NextResponse.json({ error: 'Could not generate ZIP' }, { status: 500 })
  }
}
