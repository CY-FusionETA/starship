# Starship — Globe Engineering MR/PO System

Procurement system that digitises Globe Engineering's **Material Requisition → Purchase Order → signed Delivery Order → 3-way match → accounting** workflow, with AI OCR of photographed delivery orders (Google Gemini) and a WhatsApp intake hotline (Wazzup, in progress).

Plain **PHP 8** + **SQLite** (single-file database), no framework, no build step.

---

## Requirements

- PHP **8.1+** with extensions: `pdo_sqlite`, `gd`, `curl`, `mbstring` (and `sodium` for the later Xero token encryption)
- A web server (Apache or Nginx) with PHP-FPM
- Write access to the `storage/` directory for the web user

No database server is needed — the whole database is one SQLite file at `storage/starship.sqlite`.

---

## Quick start (local)

```bash
# 1. Configure
cp config/config.sample.php config/config.php
#    then edit config/config.php:
#    - app.app_key : run  php -r "echo bin2hex(random_bytes(32));"
#    - app.base_url: e.g. http://localhost:8000   (no trailing slash)
#    - gemini.api_key, wazzup.*: your keys
#    - deploy.token: a long random string (or set deploy.enabled=false)

# 2. Create + seed the database
php db/migrate.php     # creates storage/starship.sqlite and all tables
php db/seed.php        # loads suppliers/projects/catalogue + an admin user (prints a temp password)

# 3. Run it (dev server uses the bundled router)
php -S localhost:8000 router.php
# open http://localhost:8000  and log in with the admin email + printed password
```

`router.php` is only for the PHP built-in dev server. In production the web server handles routing (see below).

---

## Deploying to a server (DigitalOcean etc.)

The app is a front controller: everything routes to `index.php`, except real files in `api/` and `cron/`. Sensitive dirs (`config/`, `storage/`, `src/`, `db/` runners) must be blocked from the web.

### Apache
The included `.htaccess` already does the routing + the denies. Just:
1. Point the vhost `DocumentRoot` at the project folder.
2. Ensure `AllowOverride All` and `mod_rewrite` are enabled.
3. **Edit `.htaccess`**: change `RewriteBase /web/globe-starship/` to match where you host it (`/` if at the domain root).
4. Set `app.base_url` in `config/config.php` to the public URL (no trailing slash).

### Nginx (+ PHP-FPM)
`.htaccess` is ignored by Nginx — use `deploy/nginx.conf.example` as a starting point (it does the same routing + denies). Set `app.base_url` to the public URL.

### Both
```bash
cp config/config.sample.php config/config.php   # fill in real values
chmod -R u+rwX storage                           # web user must be able to write here
php db/migrate.php
php db/seed.php
```
Make sure the web user owns/that can write `storage/` (SQLite needs to create the `-wal`/`-shm` sidecars there).

### Hosting at the domain root vs a subpath
- **Root** (`https://app.example.com`): `app.base_url = https://app.example.com`; Apache `RewriteBase /`; Nginx as in the example.
- **Subpath** (`https://example.com/starship`): `app.base_url = https://example.com/starship`; Apache `RewriteBase /starship/`; adjust the Nginx `location` accordingly.

---

## Configuration reference (`config/config.php`)

| Key | Purpose |
|---|---|
| `app.app_key` | 64-hex key (encrypts Xero tokens later). Generate once, keep stable. |
| `app.base_url` | Public URL, no trailing slash. Used for links + routing. |
| `db.path` | SQLite file path. Empty = `storage/starship.sqlite`. |
| `gemini.api_key` | Google Gemini key for DO OCR. |
| `gemini.escalate_below` | Confidence (0-100) under which OCR escalates to the stronger model. |
| `wazzup.api_key` / `wazzup.channel_id` | WhatsApp hotline (Phase 5). |
| `xero.*` | Xero OAuth app (Phase 6). `enabled=false` until then. |
| `deploy.enabled` / `deploy.token` | Optional HTTPS self-deploy endpoint (`api/deploy.php`). Set `enabled=false` in production if unused. |

---

## Project layout

```
index.php            front controller (UI routes)
api/deploy.php       optional token-guarded HTTPS deploy endpoint
config/              config.php (git-ignored) + sample
db/                  schema.sql, migrate.php, seed.php, migrations/
src/                 Db, Auth, Router, Csrf, Response, Storage, Repo/*, Service/*, Support/*
views/               plain PHP templates
public/css/app.css   styles (FusionETA design tokens)
storage/             SQLite DB + uploaded DO images (web-denied)
router.php           dev-only router for `php -S`
deploy/              nginx example
```

## Security notes
- `config/config.php` holds all secrets and is git-ignored. Never commit it.
- `config/`, `storage/`, `src/` are denied to the web (via `.htaccess` / nginx). The SQLite file and uploaded DO images are not web-reachable.
- Rotate the Gemini/Wazzup keys if they were ever shared.
- The `db/migrate.php` and `db/seed.php` runners are guarded by `app.app_key` when hit over HTTP; you can delete them after first setup.
