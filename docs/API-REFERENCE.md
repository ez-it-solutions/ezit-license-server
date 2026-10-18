# EzIT License Server — API Reference

Canonical contract for every channel in and out of the license server.
**Keep this file current** — when an endpoint, header, status code, or
auth rule changes, update it here in the same commit. The interactive
viewer (`api-explorer.html`) mirrors this document.

Three channels:

| Channel | Path | Auth | Who calls |
|---|---|---|---|
| Site → server (LicenseBox-compatible) | `POST /api/{endpoint}` | `LB-API-KEY` header | EzIT Core, License Gate, any CMS plugin |
| Server → site (push) | `POST {site}/wp-json/ezit/v1/license-push` | `key` body param = product api_key | `src/LicensePush.php` |
| Admin SPA → server | `/admin-api/{resource}` | PHP session + `X-CSRF-TOKEN` | `views/admin.php` SPA |

---

## 1. Shared request contract (site → server)

Every site request carries the LicenseBox headers:

| Header | Value | Trusted? |
|---|---|---|
| `LB-API-KEY` | Product `api_key` — **identifies + authenticates the product** | Yes (DB lookup) |
| `LB-URL` | `home_url()` of the calling site | Declared, not verified — bound into the cert at activation |
| `LB-IP` | `$_SERVER['SERVER_ADDR']` | Not trusted — logs/throttles use `REMOTE_ADDR` |
| `LB-LANG` | Site locale | Informational |

- Bodies are `application/x-www-form-urlencoded` (JSON also parsed).
- Responses: `{status: bool, status_code?: string, message: string, ...}`
- Auth failures return HTTP 200 + `status:false` (LicenseBox compat);
  transport-level problems use real codes (400/401/404/429).

### Shared failure codes

| `status_code` | Emitted when |
|---|---|
| `INVALID_API_KEY` | `LB-API-KEY` matches no product |
| `PRODUCT_DISABLED` | Product exists but `status != enabled` — site UI shows *"Product is no longer available"* |
| `LICENSE_SUSPENDED` | License `status = suspended` |
| `LICENSE_INACTIVE` | License `status` not `active` (catch-all) |
| `LICENSE_EXPIRED` | `expires_at` in the past |
| `ACTIVATION_DISABLED` | This domain's activation row was disabled |
| `DOMAIN_NOT_ACTIVATED` | Cert's bound domain ≠ request's `LB-URL` domain |
| `ACTIVATED_MAXIMUM_ALLOWED_PRODUCT_INSTANCES` | `max_activations` seats full |
| `SERVER_UNAVAILABLE` | Maintenance mode is on — every `/api/*` call gets **HTTP 503** + `Retry-After` instantly (fail-fast, not a verdict — sites keep last verdict) |
| HTTP 429 | Per-IP failure throttle (see §5) |
| HTTP 400 | `product_id` mismatch / missing required fields |

**Outage semantics:** transport failure and `SERVER_UNAVAILABLE` are
*not verdicts* — a site keeps its last verdict and re-checks on the
`Retry-After` window (10-min floor). A real `{status:false}` with any
other `status_code` is authoritative.

### License certificate (`lic_response` / `license_file`)

`<64-hex HMAC-SHA256><base64url(json)>` — signature keyed
`server_secret|api_key`; payload pins `license_code`, `product_id`,
`domain`, `client_name`, `ip`, `activated_at`, `expires_at`, `issuer`,
`max_activations`, `used_activations`, `features[]`, `recovery_digest`.
The site stores it verbatim and echoes it back; it cannot be edited or
moved to another domain/product.

`recovery_digest` = `HMAC-SHA256("recovery|" + UPPERCASE(trim(recovery_code)), api_key)`
— same uppercase+trim normalization clients apply before hashing.
Each product has a recovery code (dashboard → Products → Recovery);
the raw code is **shown once** at generation/regeneration and stored
only as a sha256 hash + this digest — it never crosses the wire. A site
validates an entered code offline by recomputing the digest — this is
what arms the site's emergency gate override while the server is down.
Rotating a product's `api_key` rotates the digest (a fresh code is
minted and shown once); admins can also regenerate via
`POST /admin-api/products/{id}/recovery-code`.

---

## 2. Site → server endpoints

### `check_connection_ext`
Handshake/health check.
**In:** — **Out:** `{status, message, server_time}`

### `activate_license`
**In:** `product_id`, `license_code`, `client_name`
**Out:** `{status, lic_response, message}`
Idempotent — re-activating the same domain refreshes the cert. Creates
the `activations` row with declared `site_url`/domain.

### `verify_license`
**In:** `license_file`
**Out:** `{status, status_code?, expires_at, recovery_digest?, config_version, message}`
The verdict call. `config_version` hints the site to re-read
`get_config` when it moved.

### `deactivate_license`
Two modes:
- **A — self:** `license_file` → deletes *that domain's* activation.
- **B — revoke:** `license_code` (+ `client_name`) → deletes **all**
  activations of the license. Queues a push to every site first.

### `access_login`
Gate-user login for UC-page forms.
**In:** `product_id`, `license_code`, `username`, `password`
**Out:** `{status, token}` — token = `<hmac>.<base64url payload>` keyed
`{api_key}|access`, payload has `sub`, `name`, `license_id`, `domain`,
`exp` (+12h). Verified client-side, no server call per request.

### `check_update`
**In:** `product_id`, `current_version`
**Out:** `{status, update_id?, version?, release_date?, summary?, changelog?, has_sql?, config_version}`
Serves the newest published package for the product (admin → Updates
panel). An update is only announced when `version > current_version`.

### `get_update_size/{update_id}`
Headers only — returns `Content-Length` of the package.

### `download_update/main/{update_id}`
**In:** `license_file` (must map to a usable license with an activation
on this domain) → binary zip stream, else 401/404.

### `get_config`
**In:** — **Out:**
```json
{"status": true, "config_version": 3, "generated_at": "…",
 "config": {"api_base": "…", "endpoints": {"…": "…"},
            "services": {"…": "…"}, "notices": [],
            "product": {"product_id": "…", "name": "…", "latest_version": "…"}}}
```
The bootstrap doc — sites hardcode only the base URL + api_key and
learn everything else here. Client-side, `api_base` is honored only for
HTTPS `*.ez-it-solutions.com` hosts (`ezit_allowed_api_hosts` filter).

### `catalog`
Marketplace listing — the one endpoint that does **not** need an
`LB-API-KEY`. Soft-gated on `LB-URL` (missing → 400).
**In:** — **Out:** `{status, items:[{slug, name, product_id, category,
excerpt, thumb, version, price, free, installed}]}`
Only `published` items on `enabled` products are listed; `installed`
is true when the calling domain has an activation for that product.
`version` mirrors `products.latest_version` (same source `check_update`
reads — no double bookkeeping).

### `catalog/{slug}`
Detail for the install flow. Same `LB-URL` soft gate; 404 when
unpublished/unknown. Adds `body` + `screenshots[]` to the item shape.
**`api_key` is included only for `free` listings** — the site needs it
to activate after installing; paid items must be purchased (Phase 5),
then activated with an issued `license_code` like usual.

---

## 3. Server → site — `license-push`

`POST {site_url}/wp-json/ezit/v1/license-push`, body `key={api_key}`.

- **Receiver:** EzIT Core (`EzIT_Licensing::rest_push`) — `hash_equals`
  against every registered product key; License Gate does **not**
  implement it.
- **Semantics:** carries **no verdict** — it only triggers a re-verify
  (15s throttle site-side). A forged push cannot disable a site.
- **Fan-out:** license update/delete/suspend, activation
  enable/disable/revoke, product update/delete/disable, Mode-B revoke.
- **Bounds:** 5s timeout, max 25 sites/event, queued pre-response and
  flushed after. Targets are `http(s)`-only; redirects are **not**
  followed (a 3xx would re-POST the key to a second host).
- **Response:** `{"ok": true, "statuses": {"slug": "active|invalid|none"}}`

---

## 4. Admin API (`/admin-api/*`) — session + CSRF

`POST /admin-api/login {email, password}` → session cookie + `csrf`.
All other calls need `X-CSRF-TOKEN`. Throttled 8 fails/IP/10min.

| Route | Verb | Purpose |
|---|---|---|
| `session` | GET | `{authenticated, admin, csrf}` |
| `logout` | POST | destroy session |
| `account` | PUT | name/password change |
| `stats` | GET | dashboard counts + recent activity |
| `products` | GET/POST | list / create (`product_id`, `name`, `latest_version`, `status`) |
| `products/{id}` | PUT/DELETE | update; `regenerate_key` or `api_key` rotates the key (invalidates certs) |
| `licenses` | GET/POST | `?q=` search; create with code/features/etc |
| `licenses/{id}` | PUT/DELETE | edit / delete (cascades activations) |
| `licenses/{id}/users` | GET/POST | gate users for UC-page login |
| `licenses/{id}/users/{uid}` | DELETE | remove gate user |
| `activations` | GET | list |
| `activations/{id}` | PUT/DELETE | enable/disable / revoke |
| `updates` | GET/POST | list packages / publish — **multipart**, fields `product_id`, `version`, `summary?`, `changelog?`, `has_sql?`, `package` (zip, required, ≤250 MB). Bumps `products.latest_version` when newer |
| `updates/{id}` | DELETE | remove package + stored zip |
| `logs` | GET | `?q=&action=&result=&page=&per=` (per 10–200) + distinct `actions` list |
| `logs/purge` | POST | run retention rules now — returns `{deleted: {action: n}, total}` |
| `log-rules` | GET/POST | retention rules — `{action|'*', retain_days (0=forever), keep_last, enabled}`; `keep_last` pins the newest N rows per action×license×domain |
| `log-rules/{id}` | PUT/DELETE | toggle/edit / remove rule (unmatched actions keep forever) |
| `config` | GET/POST | current doc + history / publish new version |
| `config/{id}` | GET | one historical version |
| `maintenance` | GET/PUT | read / toggle the `data/.maintenance` flag — while set, `/api/*` answers 503 `SERVER_UNAVAILABLE` instantly; admin panel stays up |
| `settings` | GET/PUT | dashboard display prefs — `timezone` (IANA or '' = server default), `date_format` mdy/dmy/ymd, `time_format` 24h/12h |
| `catalog` | GET/POST | marketplace listings + category list / create `{product_id, slug, category, excerpt?, body?, thumb?, screenshots[], price?, is_free, published}` |
| `catalog/{id}` | PUT/DELETE | update / remove a listing (product row untouched) |

---

## 5. Rate limits & bounds

| Surface | Limit |
|---|---|
| `activate_license` | 10 failed/IP/10min → 429 |
| `access_login` | 8 failed/IP/10min → 429 |
| admin `login` | 8 failed/IP/10min → 429 |
| `license-push` receiver | 1 re-verify per 15s per site |
| Push fan-out | 25 sites/event, 5s each, post-response |

Throttles key on `REMOTE_ADDR` (TCP peer), never client headers. Behind
a reverse proxy, `REMOTE_ADDR` is the proxy for everyone — pass the
real client IP through a trusted header first.

## 6. Security model (summary — full analysis in `LICENSE-SERVER.md` §1a)

- **`server_secret`** never leaves the server; its compromise is total
  (forgeable certs/tokens).
- **`api_key` is distributed, not secret** — an identifier. Every
  destructive call also requires `license_code` or a signed cert.
- **No inbound "disable" exists.** A site flips to unlicensed only on a
  real `verify_license` answer over HTTPS to an allowlisted host.
- **`site_url`/`LB-URL` are client-declared** — a keyholder can aim
  *their own license's* pushes anywhere; scheme restriction +
  no-redirects bound the blast radius.
