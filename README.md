# HitechFibre AI WhatsApp Support System

AI-powered WhatsApp support bot for HitechFibre. Handles technical support, accounts, and sales queries via respond.io + Splynx integration.

---

## Deploying with Portainer + GitHub

### Step 1 — Push to GitHub

```bash
git init
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/YOUR_ORG/hitechfibre-whatsapp.git
git push -u origin main
```

---

### Step 2 — Create a Portainer Stack

1. In Portainer, go to **Stacks → Add Stack**
2. Select **Repository**
3. Fill in:
   - **Stack name:** `hitechfibre`
   - **Repository URL:** `https://github.com/YOUR_ORG/hitechfibre-whatsapp`
   - **Repository reference:** `refs/heads/main`
   - **Compose path:** `docker-compose.yml`
4. If the repo is private, add a **GitHub Personal Access Token** under *Authentication*
5. Tick **"Automatic updates"** → GitOps polling (optional — rebuilds on push)

---

### Step 3 — Set Environment Variables in Portainer

In the **"Environment variables"** panel of the stack, add every variable below.  
These replace your `.env` file — **no secrets ever go in the git repo**.

#### Required

| Variable | Example | Description |
|----------|---------|-------------|
| `DB_ROOT_PASSWORD` | `Str0ngR00tP@ss` | MySQL root password |
| `DB_PASS` | `Str0ngAppP@ss` | MySQL app user password |
| `APP_WEBHOOK_SECRET` | `rand_32_char_string` | Validates respond.io webhooks |
| `SPLYNX_URL` | `https://yourco.splynx.com` | Splynx base URL |
| `SPLYNX_API_KEY` | `abc123...` | Splynx API key |
| `SPLYNX_API_SECRET` | `xyz789...` | Splynx API secret |
| `RESPONDIO_API_KEY` | `ri_...` | respond.io API key |
| `RESPONDIO_INBOX_ID` | `12345` | Your respond.io inbox ID |
| `ADMIN_PASSWORD` | `Str0ngAdminP@ss` | Admin dashboard login |
| `ADMIN_API_TOKEN` | `rand_32_char_string` | Admin API token for dashboard |

#### Optional

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_NAME` | `hitechfibre` | MySQL database name |
| `DB_USER` | `hitechfibre` | MySQL app username |
| `HTTP_PORT` | `80` | Host port for the web container |
| `REDIS_PASSWORD` | *(empty)* | Redis password (leave empty if internal only) |
| `RESPONDIO_TEAM_TECH` | `1` | respond.io team ID for tech support |
| `RESPONDIO_TEAM_ACCOUNTS` | `2` | respond.io team ID for accounts |
| `RESPONDIO_TEAM_SALES` | `3` | respond.io team ID for sales |
| `OPENAI_ENABLED` | `false` | Set `true` to enable AI responses |
| `OPENAI_API_KEY` | *(empty)* | Your OpenAI API key |
| `ADMIN_USERNAME` | `admin` | Admin dashboard username |
| `ADMIN_SECRET_PATH` | `hitechfibre_admin` | URL path for admin dashboard |
| `BH_TIMEZONE` | `Africa/Johannesburg` | Business hours timezone |
| `BH_MON_FRI_START` | `08:00` | Weekday opening time |
| `BH_MON_FRI_END` | `17:00` | Weekday closing time |
| `BH_SAT_START` | `08:00` | Saturday opening time |
| `BH_SAT_END` | `13:00` | Saturday closing time |

---

### Step 4 — Deploy

Click **"Deploy the stack"** in Portainer.

On first deploy, Portainer will:
1. Build the Docker image from your GitHub repo
2. Start MySQL + Redis
3. Wait for MySQL to be healthy
4. The `web` container entrypoint auto-generates `settings.json` from the env vars above
5. Runs `php artisan migrate` to create the database tables
6. Starts Apache

---

### Step 5 — Configure respond.io Webhook

In your respond.io workspace:
- Go to **Settings → Integrations → Webhooks**
- Add webhook URL: `https://YOUR_DOMAIN/webhook.php`
- Events: `message`, `conversation_closed`
- Secret: the value of `APP_WEBHOOK_SECRET`

---

## Access

| URL | Description |
|-----|-------------|
| `https://YOUR_DOMAIN/webhook.php` | respond.io webhook endpoint |
| `https://YOUR_DOMAIN/ADMIN_SECRET_PATH/` | Admin dashboard |

---

## Architecture

```
respond.io (WhatsApp)
    ↓ webhook
webhook.php          ← 200 OK immediately, processes async
    ↓
EventFilter          ← dedup, rate limit, anti-spam
    ↓
Bot Engine           ← state machine + intent detection
    ├── SplynxService   ← customer lookup (full list + local filter)
    └── RespondIOService← send replies
    
On conversation close:
    └── SplynxService.createTicket() ← form-encoded, full transcript
```

---

## Container Roles

All three containers use the same Docker image. The `CONTAINER_ROLE` env var controls what each one does:

| Container | CONTAINER_ROLE | Does |
|-----------|---------------|------|
| `hitechfibre_web` | `web` | Apache — serves webhook + admin |
| `hitechfibre_worker` | `worker` | `php artisan queue:work` |
| `hitechfibre_cron` | `cron` | Splynx sync every 10 min, session cleanup |

---

## Updating the Application

With Portainer GitOps polling enabled, pushing to `main` on GitHub will automatically trigger a rebuild and redeploy.

Manually: In Portainer → Stacks → hitechfibre → **Pull and redeploy**.

---

## Useful Commands

Run these via **Portainer → Containers → hitechfibre_web → Console**:

```bash
# View logs
tail -f /var/www/html/logs/webhook.log

# Check system stats
php artisan stats

# Manually sync Splynx customer cache
php artisan splynx:sync

# Test a fake incoming WhatsApp message
php artisan test:webhook 27821234567 "My internet is down"

# Clean up stale sessions
php artisan sessions:clean
```

---

## Folder Structure

```
hitechfibre/
├── docker-compose.yml         ← Portainer stack file
├── docker/
│   └── php/
│       ├── Dockerfile         ← Single image for all roles
│       ├── entrypoint.sh      ← Generates config + starts process
│       ├── vhost.conf         ← Apache config
│       └── php.ini            ← PHP production settings
├── src/
│   ├── Bot/                   ← StateMachine, Engine, FlowManager, IntentDetector
│   ├── Core/                  ← Database, Cache, Config, Logger, Env
│   ├── Services/              ← SplynxService, RespondIOService, OpenAIService
│   └── Webhook/               ← EventFilter
├── public/
│   ├── webhook.php            ← respond.io webhook endpoint
│   └── admin/
│       ├── index.html         ← Admin dashboard
│       └── api.php            ← Admin API backend
├── migrations/
│   ├── 001_schema.sql         ← Initial tables
│   └── 002_add_columns.sql    ← Additions
├── artisan                    ← CLI tool
├── composer.json
├── .env.example               ← Template (real .env is never committed)
└── .gitignore
```
