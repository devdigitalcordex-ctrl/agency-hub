const SCANNER_URL = `http://${process.env.SCANNER_DROPLET_IP}:${process.env.SCANNER_DROPLET_PORT}`
const SCANNER_KEY = process.env.SCANNER_API_KEY!

export async function scanFile(fileBuffer: Buffer, filename: string) {
  const formData = new FormData()
  formData.append('file', new Blob([fileBuffer]), filename)

  const res = await fetch(`${SCANNER_URL}/scan`, {
    method: 'POST',
    headers: { 'x-api-key': SCANNER_KEY },
    body: formData,
  })

  if (!res.ok) throw new Error(`Scanner error: ${res.status}`)
  return res.json()
}

export async function checkScannerHealth(): Promise<boolean> {
  try {
    const res = await fetch(`${SCANNER_URL}/health`, {
      headers: { 'x-api-key': SCANNER_KEY },
      signal: AbortSignal.timeout(5000),
    })
    return res.ok
  } catch {
    return false
  }
}
