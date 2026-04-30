import { auth } from '@/lib/auth'
import { NextResponse } from 'next/server'

export default auth((req) => {
  const isLoggedIn = !!req.auth
  const isAuthPage = req.nextUrl.pathname.startsWith('/login')
  const isApiWebhook = req.nextUrl.pathname.startsWith('/api/webhook')

  // Webhooks are public (auth via site_key)
  if (isApiWebhook) return NextResponse.next()

  // Redirect to login if not authenticated
  if (!isLoggedIn && !isAuthPage) {
    return NextResponse.redirect(new URL('/login', req.url))
  }

  // Redirect to dashboard if already logged in and going to login page
  if (isLoggedIn && isAuthPage) {
    return NextResponse.redirect(new URL('/', req.url))
  }

  return NextResponse.next()
})

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|.*\\.png$).*)'],
}
