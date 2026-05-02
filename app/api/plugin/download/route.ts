import { NextRequest, NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
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
  const session = await auth()
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  try {
    const zip = new AdmZip()
    const pluginDir = join(process.cwd(), 'plugin')
    addFolderToZip(zip, pluginDir, '')
    const buffer = zip.toBuffer()
    return new NextResponse(buffer, {
      headers: {
        'Content-Type': 'application/zip',
        'Content-Disposition': 'attachment; filename="agency-hub-latest.zip"',
      },
    })
  } catch (e) {
    console.error(e)
    return NextResponse.json({ error: 'Could not generate ZIP' }, { status: 500 })
  }
}
