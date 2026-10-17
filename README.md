# Ez IT Solutions — License Server v1.0

Self-hosted license & registration server for all Ez IT Solutions client websites.
Drop-in replacement for the Botble license server (`license.botble.com`) — speaks the
exact LicenseBox API that Botble CMS expects, so **no CMS code changes are needed**;
only `platform/core/core.json` is repointed.

**Owner:** Chris Hultberg · **Deploy target:** `https://license.ez-it-solutions.com`

---

## Deploy

1. Upload the contents of `registration/v1.0/` to the web root of `license.ez-it-solutions.com` (PHP 8.2+, HTTPS).
2. Browse to `https://license.ez-it-solutions.com/install` — create your admin account, pick SQLite or MySQL.
3. The installer prints the **api_key** for product `5F061D22` (Botble CMS). Copy it.
4. In each client site's `platform/core/core.json` set:
   ```json
   "apiUrl": "https://license.ez-it-solutions.com",
   "apiKey": "<the api_key from step 3>"
   ```
   Then clear the site cache (`php artisan cache:clear` or delete `storage/framework/cache/data`).
5. Log in at `https://license.ez-it-solutions.com/` → **Licenses → New license** → copy the code.
6. On the client site: `Settings → General → License` (or `php artisan cms:license:activate --buyer="Site Name" --purchase_code="CODE"`).
7. Delete or password-protect `/install` after setup.

## Architecture

| Piece | File |
|---|---|
| Front controller / router | `index.php` |
| LicenseBox public API (`/api/*`) | `src/LicenseApi.php` |
| Dashboard JSON API (`/admin-api/*`) | `src/AdminApi.php` |
| Certificate sign/verify | `src/Certificate.php` |
| PDO + schema + audit log | `src/Database.php` |
| Published client config doc | `src/ServerConfig.php` |
| Server→site push fan-out | `src/LicensePush.php` |
| Admin SPA shell | `views/admin.php` + `assets/` |
| First-run installer | `install/index.php` |
| Config | `config/config.php` (created by installer; `config.sample.php` = template) |

DB: **SQLite** by default (`data/license-server.sqlite`) or **MySQL** — both auto-migrated.

## Public API (LicenseBox-compatible)

Client sends headers `LB-API-KEY`, `LB-URL`, `LB-IP`, `LB-LANG` on every call.

| Endpoint | Method | Body | Response |
|---|---|---|---|
| `POST /api/check_connection_ext` | POST | — | `{status:true}` |
| `POST /api/activate_license` | POST | `product_id, license_code, client_name, verify_type` | `{status, lic_response, message}` |
| `POST /api/verify_license` | POST | `product_id, license_file` | `{status, message, licensed_to, activated_at, expires_at}` |
| `POST /api/deactivate_license` | POST | `product_id, license_file` **or** `license_code, client_name` | `{status, message}` |
| `POST /api/check_update` | POST | `product_id, current_version` | `{status, update_id, version, release_date, summary, changelog, has_sql}` |
| `HEAD /api/get_update_size/{id}` | HEAD | — | `Content-Length` |
| `POST /api/download_update/main/{id}` | POST | `product_id, license_file` | zip bytes (401 if unlicensed) |
| `POST /api/get_config` | POST | — | bootstrap doc: api_base, endpoints, services, notices, product block |
| `POST /api/access_login` | POST | `product_id, license_code, username, password` | `{status, token}` — HMAC gate-user token |
| Push → `{site}/wp-json/ezit/v1/license-push` | POST | `key` = product api_key | wake-up only; site re-verifies itself |

Full contract — headers, params, status codes, rate limits:
[`docs/API-REFERENCE.md`](docs/API-REFERENCE.md) · interactive viewer:
[`docs/api-explorer.html`](docs/api-explorer.html).

Max-activations overflow returns `status_code: "ACTIVATED_MAXIMUM_ALLOWED_PRODUCT_INSTANCES"` — the CMS understands this and offers to revoke the old domain.

## Certificate format (`lic_response` → client's `storage/.license`)

```
<64-char HMAC-SHA256 hex><base64url(JSON)>
JSON = {license_code, product_id, client_name, domain, ip, activated_at, expires_at, issuer}
HMAC key = server_secret | product api_key   (config/config.php → server_secret)
```

Tamper-evident: editing the payload, moving to another product, or re-signing without the
secret fails `hash_equals` verification.

## Activation rules

- Same domain re-activating → idempotent refresh (safe to re-run).
- New domain beyond `max_activations` → rejected (revoke the old domain first, in CMS admin or this panel's **Activations** tab).
- `suspended` / `expired` licenses fail verify — the CMS shows its unlicensed screen.

## Security

- Admin passwords `password_hash()`'d; sessions HTTP-only + SameSite=Lax; CSRF token required on all mutations.
- `config/`, `data/`, `src/` blocked by `.htaccess` (and never routed as assets).
- Every API call is audit-logged (`activity_logs`) with domain + IP.
- Per-IP failure throttles (`activate_license` 10/10 min, `access_login` 8/10 min,
  admin login 8/10 min → HTTP 429) keyed on `REMOTE_ADDR`, never client headers.
- `license-push` carries **no verdict** — a forged push can trigger at most a
  re-verify, never disable a site. Full threat model: `docs/LICENSE-SERVER.md` §1a.

### File permissions (production)

Everything sensitive should be readable only by the PHP/web user (`www-data`,
or your PHP-FPM/cPanel account user):

```bash
chown -R www-data:www-data data config
chmod 700 data                          # dir needs write+traverse for SQLite
chmod 600 data/license-server.sqlite    # DB: owner rw only
chmod 600 config/config.php             # contains server_secret (HMAC key)
```

Notes:

- SQLite creates `license-server.sqlite-journal` / `-wal` / `-shm` sidecars at
  runtime — they inherit the `data/` directory's protection, so keep it `700`.
- If a backup/cron job under another user must read the DB, use `640` + a shared
  group instead of `600`.
- `install/` should be deleted or password-protected after setup.
- Belt-and-suspenders: `data/` is already denied over HTTP by `.htaccess` — the
  file perms protect against other local users / misconfig. For maximum
  isolation you can point `db.sqlite_path` in `config/config.php` at a path
  **outside the web root**.

## Adding a second product

Dashboard → **Products → New product** → set `product_id` to the value in that product's
`core.json` → copy the generated `api_key` into that `core.json` → issue licenses as usual.
