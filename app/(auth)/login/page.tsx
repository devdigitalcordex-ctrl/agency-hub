'use client'
import { useState } from 'react'
import { signIn } from 'next-auth/react'
import { useRouter } from 'next/navigation'
import Image from 'next/image'

export default function LoginPage() {
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)
    setError('')

    const res = await signIn('credentials', {
      email,
      password,
      redirect: false,
    })

    if (res?.error) {
      setError('Invalid email or password')
      setLoading(false)
    } else {
      router.push('/')
      router.refresh()
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-[#0A0A12] px-4">
      <div className="w-full max-w-sm">
        <div className="flex justify-center mb-8">
          <img
            src="https://digitalcordex.com/wp-content/uploads/2024/10/Untitled-1.png"
            alt="Digital Cordex"
            className="h-10 object-contain"
          />
        </div>

        <div className="bg-[#13131f] border border-[#1e1e2e] rounded-xl p-8">
          <h1 className="text-xl font-semibold text-white mb-1">Agency Hub</h1>
          <p className="text-sm text-slate-500 mb-6">Sign in to manage your sites</p>

          {error && (
            <div className="bg-red-500/10 border border-red-500/20 text-red-400 text-sm rounded-lg px-4 py-3 mb-4">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-slate-400 mb-1.5">Email</label>
              <input
                type="email"
                value={email}
                onChange={e => setEmail(e.target.value)}
                required
                className="w-full bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-[#5185C8] focus:ring-1 focus:ring-[#5185C8] transition"
                placeholder="you@digitalcordex.com"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-400 mb-1.5">Password</label>
              <input
                type="password"
                value={password}
                onChange={e => setPassword(e.target.value)}
                required
                className="w-full bg-[#0A0A12] border border-[#1e1e2e] rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-[#5185C8] focus:ring-1 focus:ring-[#5185C8] transition"
                placeholder="••••••••"
              />
            </div>
            <button
              type="submit"
              disabled={loading}
              className="w-full bg-[#5185C8] hover:bg-[#4070b0] disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold py-2.5 rounded-lg transition text-sm"
            >
              {loading ? 'Signing in...' : 'Sign in'}
            </button>
          </form>
        </div>

        <p className="text-center text-xs text-slate-600 mt-6">
          Digital Cordex Agency Hub v1.0
        </p>
      </div>
    </div>
  )
}
