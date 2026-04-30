# Agency Hub

Digital Cordex WordPress Security & Management Platform.

## Stack

- **Next.js 15** (App Router) on Vercel
- **PostgreSQL** via Neon
- **Redis** via Upstash
- **Email** via Resend
- **Error tracking** via Sentry
- **Scanner** via Droplet 2 (ClamAV + YARA)

---

## Deployment

### 1. Push to GitHub

```bash
git init
git add .
git commit -m "initial"
git remote add origin https://github.com/YOUR_ORG/agency-hub.git
git push -u origin main
```

### 2. Deploy to Vercel

1. Go to vercel.com, import the GitHub repo
2. Add all environment variables from `.env.example`
3. Set `NEXTAUTH_URL` to your Vercel deployment URL
4. Generate `NEXTAUTH_SECRET` with: `openssl rand -base64 32`
5. Deploy

### 3. Run database migrations

After first deploy, run in Vercel terminal or locally:

```bash
npx prisma db push
```

### 4. Create first admin user

```bash
ADMIN_EMAIL=you@digitalcordex.com \
ADMIN_PASSWORD=YourPassword123! \
ADMIN_NAME="Your Name" \
npx tsx prisma/seed.ts
```

Or set the env vars in your `.env.local` file and run:

```bash
npx tsx prisma/seed.ts
```

### 5. Connect WordPress sites

1. Install the Agency Hub WordPress plugin on a client site
2. In Agency Hub dashboard, click "Add Site"
3. Copy the connection key shown on the site detail page
4. In WordPress: Settings › Agency Hub › paste the key › Save

The plugin sends a heartbeat every 5 minutes. The site will show as "Online" after the first heartbeat.

---

## Environment Variables

| Variable | Description |
|---|---|
| `DATABASE_URL` | Neon PostgreSQL connection string |
| `UPSTASH_REDIS_REST_URL` | Upstash Redis URL |
| `UPSTASH_REDIS_REST_TOKEN` | Upstash Redis token |
| `RESEND_API_KEY` | Resend API key for email alerts |
| `SENTRY_DSN` | Sentry DSN for error tracking |
| `NEXTAUTH_SECRET` | Random secret for session encryption |
| `NEXTAUTH_URL` | Full URL of the deployed Hub |
| `SCANNER_DROPLET_IP` | IP of Droplet 2 (167.71.80.141) |
| `SCANNER_DROPLET_PORT` | Scanner API port (3500) |
| `SCANNER_API_KEY` | API key set on Droplet 2 |

---

## Heartbeat API

The WordPress plugin sends POST to `/api/webhook/heartbeat` with:

```json
{
  "site_key": "SITE_KEY_FROM_HUB",
  "status": "online",
  "data": {
    "wp_version": "6.7",
    "php_version": "8.2",
    "plugin_version": "1.0.0",
    "admin_email": "admin@site.com",
    "alerts": [],
    "logs": []
  }
}
```

Response includes any pending commands:

```json
{
  "success": true,
  "commands": [
    { "id": "cmd_id", "type": "scan", "payload": {} }
  ]
}
```
