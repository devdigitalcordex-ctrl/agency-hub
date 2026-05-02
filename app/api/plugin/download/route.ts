import { NextRequest, NextResponse } from 'next/server'
import { join } from 'path'
import { readFileSync, readdirSync, statSync } from 'fs'
import JSZip from 'jszip'

function addFolder(zip: JSZip, folderPath: string, zipPath: string) {
  const items = readdirSync(folderPath)
  for (const item of items) {
    if (item === '.DS_Store' || item === '__MACOSX') continue
    const full = join(folderPath, item)
    const zPath = zipPath ? `${zipPath}/${item}` : item
    if (statSync(full).isDirectory()) {
      addFolder(zip, full, zPath)
    } else {
      zip.file(zPath, readFileSync(full))
    }
  }
}

export async function GET(req: NextRequest) {
  const token = req.nextUrl.searchParams.get('token')
  if (token !== (process.env.PLUGIN_DOWNLOAD_TOKEN || 'agency-hub-dl-2026')) {
    return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })
  }
  const zip = new JSZip()
  addFolder(zip, join(process.cwd(), 'plugin'), 'agency-hub')
  const buffer = await zip.generateAsync({ type: 'nodebuffer', compression: 'DEFLATE' })
  return new NextResponse(buffer, {
    headers: {
      'Content-Type': 'application/zip',
      'Content-Disposition': 'attachment; filename="agency-hub.zip"',
    },
  })
}
