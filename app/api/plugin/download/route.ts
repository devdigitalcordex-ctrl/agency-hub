import { NextRequest, NextResponse } from 'next/server'
import { join } from 'path'
import { createReadStream, statSync, readdirSync } from 'fs'
import archiver from 'archiver'
import { Readable } from 'stream'

export async function GET(req: NextRequest) {
  const token = req.nextUrl.searchParams.get('token')
  const validToken = process.env.PLUGIN_DOWNLOAD_TOKEN || 'agency-hub-dl-2026'
  if (token !== validToken) {
    return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })
  }

  const pluginDir = join(process.cwd(), 'plugin')

  const buffer = await new Promise<Buffer>((resolve, reject) => {
    const archive = archiver('zip', { zlib: { level: 6 } })
    const chunks: Buffer[] = []
    archive.on('data', (chunk: Buffer) => chunks.push(chunk))
    archive.on('end', () => resolve(Buffer.concat(chunks)))
    archive.on('error', reject)
    archive.directory(pluginDir, 'agency-hub')
    archive.finalize()
  })

  return new NextResponse(buffer, {
    headers: {
      'Content-Type': 'application/zip',
      'Content-Disposition': 'attachment; filename="agency-hub.zip"',
    },
  })
}
