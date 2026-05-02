import { NextRequest, NextResponse } from 'next/server'
import { join } from 'path'
import { readdirSync, statSync, readFileSync } from 'fs'
import AdmZip from 'adm-zip'

function addFolderToZip(zip: AdmZip, folderPath: string, zipPath: string) {
  const items = readdirSync(folderPath)
  for (const item of items) {
    if (item === '.DS_Store' || item === '__MACOSX') continue
    const fullPath = join(folderPath, item)
    const zipItemPath = zipPath ? `${zipPath}/${item}` : item
    const stat = statSync(fullPath)
    if (stat.isDirectory()) {
      addFolderToZip(zip, fullPath, zipItemPath)
    } else {
      zip.addFile(zipItemPath, readFileSync(fullPath))
    }
  }
}

export async function GET(req: NextRequest) {
  const token = req.nextUrl.searchParams.get('token')
  const validToken = process.env.PLUGIN_DOWNLOAD_TOKEN || 'agency-hub-dl-2026'
  
  if (token !== validToken) {
    return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })
  }

  try {
    const zip = new AdmZip()
    addFolderToZip(zip, join(process.cwd(), 'plugin'), 'agency-hub')
    const buffer = zip.toBuffer()
    return new NextResponse(buffer, {
      headers: {
        'Content-Type': 'application/zip',
        'Content-Disposition': 'attachment; filename="agency-hub-latest.zip"',
      },
    })
  } catch (e) {
    return NextResponse.json({ error: 'Could not generate ZIP' }, { status: 500 })
  }
}
