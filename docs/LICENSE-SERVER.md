# License Server — Living Roadmap & API Contract

What `license.ez-it-solutions.com` builds for core's Marketplace,
Support, and Payments phases. Client-side (`ezit-core`) is written
against these contracts — build to these shapes and the sites light up
with no core changes.

**Reference client:** `core/ezit-core/inc/class-ezit-api.php` +
`class-ezit-marketplace.php`.

**Canonical contracts:** [`API-REFERENCE.md`](API-REFERENCE.md) — every
endpoint, header, status code, and rate limit (keep it updated in the
same commit as any API change). Interactive copy with a try-it console:
[`api-explorer.html`](api-explorer.html).

> **Direction:** this codebase is being ported into **EzOps** — a
> Laravel SaaS platform where licensing lives as a module (see
> `EzOps/v1.0/docs/ROADMAP.md`). The wire contracts below are the
> stable interface; module internals may move, these must not.

## Status ledger

| Area | Status | Notes |
|---|---|---|
| License lifecycle (activate/verify/deactivate) | ✅ live | Idempotent activation, max-seat enforcement, per-activation disable |
| `access_login` (gate users) | ✅ live | HMAC token verified client-side, 12h expiry |
| Admin SPA (products/licenses/activations/logs) | ✅ live | Dark + light themes, editable API keys |
| Product validation (dup product_id/api_key) | ✅ live | 422/409 with actionable messages |
| `get_config` bootstrap doc | ✅ live | Versioned JSON (api_base/endpoints/services/notices) + per-product merge; `config_version` also rides on `check_update`; admin publish + history UI live |
| `license-push` server → site | ✅ live | `LicensePush` fans out to `activations.site_url` on license update/delete, activation enable/disable/revoke, product update, and Mode-B revoke; logged as `push` |
| `check_update` | ✅ live | Admin → Updates panel publishes zip packages (`/admin-api/updates`, multipart) into `data/updates/`; serves `get_update_size`/`download_update`; bumps `products.latest_version` |
| Cert payload | ✅ live | Emits base fields + `max_activations`, `used_activations`, `features[]` (from new `licenses.features` JSON column; editable via admin API `features` field — SPA field TODO) |
| Marketplace catalog | ⬜ not started | No catalog schema, no `/api/catalog*` |
| Announcements | ⬜ not started | Banners module waits on this feed |
| Support tickets | ⬜ not started | |
| Payments (Invoice Ninja webhook) | ⬜ not started | billing.ez-it-solutions.com |
| Rate limiting | ✅ live | Per-IP failure throttles: `activate_license` (10/10min), `access_login` (8/10min), admin `login` (8/10min) — backed by `activity_logs`, 429 over the cap |
| Maintenance mode | ✅ live | `data/.maintenance` flag (or `'maintenance' => true` in config.php) → every `/api/*` returns 503 `SERVER_UNAVAILABLE` + `Retry-After` instantly; dashboard toggle at Server Config |
| Offline recovery (recovery codes) | ✅ live | Hash-only storage (`recovery_code_hash` + `recovery_digest`); raw code shown once at mint/regen; digest ships in the signed cert + every `verify_license` — sites validate codes offline (HMAC keyed with `api_key`) to arm the gate override |
| Log retention | ✅ live | `log_rules` — mail-filter style rules per action (or `*`): `retain_days` (0=forever) + `keep_last` pinning newest N rows per action×license×domain; daily auto-run + Activity Log panel; activate/deactivate seeded forever |

## Known caveats

- **API-key rotation invalidates issued certs.** Cert HMAC is keyed
  `secret|api_key` — editing/regenerating a key breaks every stored
  `.license` until the site re-activates. Documented in the UI; consider
  a "re-issue certs" follow-up step later. Rotation also rotates the
  `recovery_digest` (keyed with `api_key`) — sites pick up the new one on
  re-activation/verify.
- **Deactivate Mode B revokes ALL activations** of a license (not just
  the caller's domain). Intentional — matches Botble's free-the-seat
  flow — but be aware when scripting admin actions. Auth is
  `LB-API-KEY` + `license_code`; the api_key ships with products, so
  treat `license_code` as the secret that gates this call.
- **`site_url`/`domain` are client-declared** (`LB-URL` header). An
  activation row's target is whatever its own site claimed — that's
  correct for delivery, but means a keyholder can point *their own
  license's* pushes anywhere. Push sends are scheme-restricted to
  http/https and never follow redirects, so the key can't be relayed
  to a second host.
- **If the server sits behind a reverse proxy,** `REMOTE_ADDR` is the
  proxy for every caller — activity-log IPs and rate limits become
  per-proxy, not per-site. Terminate TLS at the app or pass the real
  client IP through a trusted header before relying on per-IP data.

---

## 1. Communication protocol (already live — do not change)

Every request from a site carries the LicenseBox header contract:

| Header | Value |
|---|---|
| `LB-API-KEY` | the product's `api_key` (per-product, from the dashboard) |
| `LB-URL` | the site's `home_url()` |
| `LB-IP` | `$_SERVER['SERVER_ADDR']` |
| `LB-LANG` | site locale (e.g. `en_US`) |

- POST endpoints use `application/x-www-form-urlencoded` bodies and return
  `{status: bool, message: string, ...}` JSON.
- Errors: HTTP ≥ 400 with `{"message": "…"}` — core maps that to WP_Error
  and shows `message` to the admin.
- `license_file` / `lic_response` = `<64 hex hmac><base64url(json)>` —
  core decodes the JSON tail for display only. Any fields you add to the
  cert payload (e.g. `features[]`, `max_activations`, `expires_at`,
  `domain`, `client_name`) are automatically surfaced in wp-admin —
  **additive changes are free; never rename/remove existing keys.**

### Server → site push — `license-push` (live)

**Purpose:** zero-delay propagation. When an admin or API action
changes license state (suspend, revoke, disable a product, rotate a
domain's activation), the affected site shouldn't wait for its hourly
cron to find out.

**Direction:** server → site. Implemented in `src/LicensePush.php`;
queued by mutation handlers, flushed *after* the HTTP response so a
dead site never stalls the admin UI.

#### Contract

| | |
|---|---|
| **Target** | `POST {site_url}/wp-json/ezit/v1/license-push` |
| **Body** | `application/x-www-form-urlencoded` — `key={product api_key}` |
| **Auth** | `key` must equal an api_key registered on the site (EzIT Core compares `hash_equals` against every registered product) |
| **Response** | `{"ok": true, "statuses": {"slug": "active|invalid|none"}}` |
| **Timeout** | 5s per site, 3s connect; max 25 sites per event |

#### Semantics — a push carries NO verdict

The endpoint only *asks* the site to re-verify. EzIT Core runs a normal
`verify_license` call back to the server (throttled to once per 15s)
and stores whatever the server answers. A forged or replayed push
therefore **cannot disable a site** — the disable verdict can only come
from the server itself, over HTTPS.

Targets come from `activations.site_url` (the `LB-URL` each site
declared). Sends are restricted to `http(s)` and do **not** follow
redirects, so a key is never re-POSTed to a second host. Each push is
logged as action `push` with success + reason; unreachable sites are
skipped — their hourly cron still converges.

#### Fan-out triggers

`license()` / `product()` / `activation()` queue helpers, called from:
license update/delete/suspend, activation enable/disable/revoke,
product update/delete/disable, deactivate Mode B.

---

## 1a. Security model — who can do what, and why a site can't be remotely killed

Threat in scope: an attacker wants to **disable a client site** (trip
the under-construction gate / mark a license invalid) or impersonate a
site/license.

### What each secret actually unlocks

| Secret | Held by | Compromise gives |
|---|---|---|
| `server_secret` | license server only | Forge certs + access tokens → **total**. Lives in `config/config.php`, never leaves the box. |
| product `api_key` | every site running the product (in `core.json` / EzIT Core registry) | Call public API *as that product*; authenticate `license-push`; sign-check access tokens. **Cannot** issue certs, disable activations, or flip verdicts. |
| `license_code` | license holder (inside the signed cert payload) | Activate/deactivate that license, `access_login`, Mode-B revoke. |
| cert (`license_file`) | site only | Echo-verified each request; HMAC'd `secret\|api_key` — can't be edited or moved between products/domains. |
| admin session + CSRF | admin SPA only | Full dashboard: suspend licenses, disable products/activations. |

### The disable paths, and what protects each

| Path | Guard |
|---|---|
| Forged `verify_license` response | HTTPS only; EzIT Core restricts `api_base` to `*.ez-it-solutions.com` (see `ezit_allowed_api_hosts` filter) — config can't repoint `LB-API-KEY` elsewhere. |
| Forged `license-push` | Push carries no verdict — worst case is an extra verify. Throttled 15s/site. |
| `deactivate_license` Mode B | Needs `license_code` (not shipped; lives inside the cert) — api_key alone isn't enough. |
| Domain spoofing (`LB-URL`) | Activation binds the claimed domain into the cert; verify rejects when `payload.domain ≠ LB-URL` domain. A keyholder can only claim seats on *their own* license. |
| Cert forgery/reuse | HMAC `sha256(server_secret\|api_key)`; domain + product bound inside. |
| Brute force (license codes, gate passwords, admin login) | Per-IP failure throttles → 429. IP taken from `REMOTE_ADDR` (TCP peer), never from client headers. |
| Push as SSRF | `site_url` targets are http(s)-only and redirects are not followed. |

### Residual risks (accepted / documented)

- The `api_key` is distributed, not secret — treat it as an
  *identifier*, never as authorization for state-changing calls on its
  own. Every destructive endpoint also requires `license_code` or a
  signed cert.
- `get_config`'s doc is unsigned; its trust boundary is enforced
  client-side by the api_base allowlist. Signing the doc would need a
  server keypair — revisit if we ever ship unsigned-config clients.
- Admin login throttle is per-IP; an attacker rotating IPs can still
  guess — admin passwords must be strong (bcrypt cost default).

---

## 2. Already implemented

Public API: `check_connection_ext`, `activate_license`, `access_login`,
`verify_license`, `deactivate_license`, `check_update`,
`get_update_size/{id}`, `download_update/main/{id}`, `get_config`.

Admin API + SPA: auth/session+CSRF, products CRUD (status toggle,
manual/regenerate API key), licenses CRUD, activations
disable/revoke, gate-user management, paginated activity log, account
settings, server-config publish + history.

---

## 2a. Bootstrap config — `GET|POST /api/get_config` (frozen)

**The one endpoint EzIT Core may always rely on.** A site stores only
two hardcoded facts — a bootstrap URL and its `LB-API-KEY` — and
learns everything else from this document. This is how we move to
`ops.ez-it-solutions.com` without redeploying plugins: publish a new
config version pointing `api_base` at the new host; every site
re-points itself on next check.

```json
{
  "status": true,
  "config_version": 3,
  "generated_at": "2026-10-16T19:00:00Z",
  "config": {
    "api_base": "https://license.ez-it-solutions.com/api/",
    "endpoints": { "check_connection": "check_connection_ext", "…": "…" },
    "services": { "marketplace": null, "support": null, "billing": "https://billing.ez-it-solutions.com" },
    "notices": [],
    "product": { "product_id": "5F061D22", "name": "…", "latest_version": "1.0.0" }
  }
}
```

- **Versioned + immutable.** `server_configs` rows are append-only;
  each publish = `version` +1. Core caches the doc + version and only
  re-reads when `config_version` changes — which is why `verify_license`
  and `check_update` responses also carry `config_version` as a
  staleness hint.
- **Theme rule:** themes never talk to the server directly — they ask
  EzIT Core, which owns this bootstrap + licensing state.
- **Audit:** every fetch is logged as `config_fetch` (domain, IP,
  product, version served) — full who/what/where/when in the activity
  log alongside verify/activate.
- **Admin:** Server Config tab → JSON editor, per-version note,
  publish history, one-click load-for-revert.

---

## 2b. PHASE 0 — Plumbing & hardening (do before marketplace)

- ~~[P0] `license-push` caller~~ — done (`src/LicensePush.php`); reuse
  the same fan-out for the Phase 5 payment webhook.
- ~~[P0] Update package manager~~ — done: Updates panel uploads
  zip + version + changelog + `has_sql` → `updates.*` + `data/updates/`;
  bumps `products.latest_version`. Real `check_update` responses now
  flow; foundation for theme/plugin auto-updates.
- ~~[P0] Enrich cert payload~~ — done: `Certificate::issue($extra)`
  merges `max_activations`/`used_activations`/`features[]`.
  Remaining: features field in the licenses SPA form (API already
  accepts it; only re-issued certs pick up changes).
- ~~[P1] Rate limiting~~ — done (activity_logs-backed per-IP failure
  throttles on `activate_license`, `access_login`, admin `login`).
- ~~[P1] Log retention~~ — done: `src/LogRetention.php` + `log_rules`
  table; per-action rules with per-site `keep_last` pinning; daily
  file-flag run in `index.php` + manual purge from Activity Log.

---

## 3. PHASE 3 — Marketplace (the blocker)

### `GET /api/catalog` — **build first**

Public listing, no auth needed beyond the `LB-*` headers (reject requests
missing `LB-URL` entirely if you want a soft gate).

```json
{
  "items": [
    {
      "slug": "trezise-insurance",
      "type": "theme",
      "name": "Trezise Insurance",
      "version": "1.2.0",
      "thumb": "https://license.ez-it-solutions.com/media/thumbs/trezise.png",
      "excerpt": "Insurance agency theme — one-page, contact-first.",
      "category": "business",
      "price": 0,
      "product_id": "F43D2273",
      "installed": false
    }
  ]
}
```

Core reads `items[]` and renders cards; every field optional except
`slug`, `name`, `product_id`. `installed` is a nice-to-have (server can
match `LB-URL` against activation records).

### `GET /api/catalog/{slug}` — detail for the install flow

```json
{
  "slug": "trezise-insurance",
  "name": "Trezise Insurance",
  "type": "theme",
  "version": "1.2.0",
  "product_id": "F43D2273",
  "api_key": "…",                // needed so core can register_product()
  "description": "…html…",
  "screenshots": ["https://…"],
  "changelog": "…",
  "requires": "7.4",             // min PHP (optional)
  "download_endpoint": "download_update/main/F43D2273"
}
```

`api_key` here is the product's server-side key — returning it is safe
(the site needs it to activate), but only return it for items flagged
installable/free; paid items return it after purchase.

### Server-side admin CRUD to support this

- **Catalog item** = a product + marketing fields (thumb, excerpt,
  category, screenshots). One product may exist without a catalog entry
  (internal products); a catalog entry may be `published: false`.
- **Categories** — flat list, mirror Envato-ish: `business, church,
  school, athletics, gov, dental, law, portfolio, generic`.
- **Version bumps** — catalog `version` should drive `check_update`
  responses; keep one source of truth.

### Auth model for install

Core's install flow will call `download_update/main/{product_id}` — it
already exists. Decide per item:

- **Free item** → return the zip for any request with valid `LB-URL`.
- **Paid item** → require `license_file` of a purchased license (same
  check as updates). Returns HTTP 402/403 + `{"message": "Purchase required."}`.

---

## 4. PHASE 4 — Support tickets & announcements

### `GET|POST /api/support/tickets`

Auth: `license_file` in the body (same as verify) — a ticket belongs to a
license+site pair.

- `GET` → `{"tickets":[{"id","subject","status":"open|answered|closed","updated_at","replies":[{"from":"client|staff","body","at"}]}]}`
- `POST` → `{"license_file","product_id","subject","body"}` creates;
  `{"license_file","ticket_id","body"}` replies.
- Core renders a Support admin page; keep `status` values stable.

### `GET /api/announcements?product_id=…`

Broadcast feed for the dashboard banner module (`class-ezit-banners.php`
already caches a transient — wire it to this):

```json
{"items":[{"id","title","body","severity":"info|warning|critical",
"starts_at","ends_at","product_id"|null,"dismissible":true}]}
```

Null `product_id` = broadcast to all products. Site dismisses by `id`.

---

## 5. PHASE 5 — Payments

Site never stores cards; Invoice Ninja (billing.ez-it-solutions.com) is
the payment frontend. License server only needs:

- `POST /api/purchase` → `{"product_id","license_file"|null}` → returns
  `{"checkout_url": "https://billing.ez-it-solutions.com/…"}` —
  core deep-links, Stripe/etc. handled by Invoice Ninja.
- **Webhook handler** — on Invoice Ninja payment events: issue/extend
  license, update `features[]` in the cert, then POST
  `license-push` to every activated site (instant propagation).

---

## 6. Cert payload — fields the client already displays

Core's Licenses tab decodes and renders these (all optional):

`client_name`/`registered_name`, `domain`, `expires_at`/`expiry_date`,
`max_activations`, `used_activations`, `features[]` (Phase 9).

**Currently issued:** `license_code`, `product_id`, `client_name`,
`domain`, `ip`, `activated_at`, `expires_at`, `issuer`,
`max_activations`, `used_activations`, `features[]`.
Features live in `licenses.features` (JSON column) and enter the cert
at activation — editing flags takes effect on the site's next
activate/re-activate, or read them server-side at verify time instead.

---

## 7. Build order

**Phase 0 (plumbing):**
1. `license-push` caller (AdminApi hooks + shared push service)
2. Update package manager (upload → `updates` table → real `check_update`)
3. Cert enrichment (`max_activations`, `used_activations`, `features[]`)
4. Rate limiting + log retention

**Then phases:**
5. `GET /api/catalog` (+ minimal catalog CRUD in the dashboard)
6. `GET /api/catalog/{slug}` with `api_key` for free items
7. Install flow in core (client-side — starts once #6 returns)
8. Announcements feed → banners module
9. Support tickets
10. `/api/purchase` + Invoice Ninja webhook → license issue/extend + push

Items 1–3 fix silent gaps in what's already deployed; 5–6 unblock the
entire Phase 3 client.
