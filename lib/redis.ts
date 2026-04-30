import { Redis } from '@upstash/redis'

export const redis = new Redis({
  url: process.env.UPSTASH_REDIS_REST_URL!,
  token: process.env.UPSTASH_REDIS_REST_TOKEN!,
})

export async function setSiteStatus(siteId: string, status: string) {
  await redis.set(`site:status:${siteId}`, status, { ex: 300 })
}

export async function getCachedSiteStatus(siteId: string): Promise<string | null> {
  return redis.get(`site:status:${siteId}`)
}

export async function queueCommand(siteId: string, commandId: string) {
  await redis.lpush(`site:commands:${siteId}`, commandId)
  await redis.expire(`site:commands:${siteId}`, 3600)
}
