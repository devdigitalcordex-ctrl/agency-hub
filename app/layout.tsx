import type { Metadata } from 'next'
import { Inter } from 'next/font/google'
import './globals.css'

const inter = Inter({ subsets: ['latin'] })

export const metadata: Metadata = {
  title: 'Agency Hub — Digital Cordex',
  description: 'WordPress security and management platform',
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className="dark">
      <body className={`${inter.className} bg-[#0A0A12] text-slate-200 antialiased`}>
        {children}
      </body>
    </html>
  )
}
