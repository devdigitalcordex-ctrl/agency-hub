import { PrismaClient } from '@prisma/client'
import bcrypt from 'bcryptjs'

const db = new PrismaClient()

async function main() {
  const email = process.env.ADMIN_EMAIL || 'admin@digitalcordex.com'
  const password = process.env.ADMIN_PASSWORD || 'ChangeMe123!'
  const name = process.env.ADMIN_NAME || 'Admin'

  const existing = await db.user.findUnique({ where: { email } })
  if (existing) {
    console.log(`User ${email} already exists`)
    return
  }

  const hashed = await bcrypt.hash(password, 12)
  const user = await db.user.create({
    data: { email, password: hashed, name, role: 'admin' },
  })

  console.log(`Created admin user: ${user.email}`)
  console.log(`Password: ${password}`)
  console.log('Change this password immediately after first login.')
}

main()
  .catch(console.error)
  .finally(() => db.$disconnect())
