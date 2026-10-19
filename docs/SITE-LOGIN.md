# Site Login — remote wp-admin access for licensed sites

One-click wp-admin login to any client site running EzIT Core, from the
license server dashboard. Inspired by the DreamHost panel-login plugin
(one-time `wp-login.php` token URL), generalized into a server↔site
protocol.

## How it works

```
License server admin                 EzIT Core on client site
──────────────────────              ──────────────────────────
[Log in] on an activation
  └─ POST /admin-api/site-login
        └─ POST {site}/wp-json/ezit/v1/sso/token   (signed w/ api_key)
              site mints a one-time token (sha256 in user_meta, 2-min TTL)
        ← { login_url: wp-login.php?ezit_sso_uid=N&ezit_sso_token=… }
  admin's browser opens login_url in a new tab
        site verifies + consumes the token, wp_set_auth_cookie()
        → redirected to wp-admin, logged in
```

The login URL works **once** and expires in ~2 minutes. Nothing reusable
ever crosses the wire — the URL carries only a random token whose *hash*
is stored site-side.

## Login modes

Available from **Licenses → Activations → Log in** per activated site:

| Mode | What it does |
|---|---|
| **First admin** | One-time URL for the site's first `administrator` (classic panel-login). |
| **Log in as…** | Live user list (login + roles) — pick any account. For role/permission testing. |
| **Credentials** | Username + password verified on the site via `wp_authenticate`. Optionally **remembered** — stored encrypted in `site_logins`. |
| **Push account** | Creates a WP user (or resets an existing one's password) with a given role — `administrator` today, any role name accepted — then logs you in. |

## Protocol (site side — EzIT Core `EzIT_Sso`)

```
GET  {site}/wp-json/ezit/v1/sso/users?key=&nonce=&sig=
POST {site}/wp-json/ezit/v1/sso/token
     key, nonce, sig, mode, [user_id | username | password | email | role]
```

- `key` — the product `api_key` shipped in the site's `register_product()`
- `nonce` — `{unix_time}.{rand}`; the site rejects timestamps older than
  5 minutes, so captured requests can't be replayed
- `sig` — `HMAC-SHA256(nonce, api_key)`
- `sso/users` returns `[{id, login, display, roles[]}]`
- `sso/token` returns `{login_url}` or `{error}`

Token mechanics: `user_meta ezit_sso_tokens = [sha256(token) => expiry]`
(max 3 pending, expired pruned). `wp-login.php?ezit_sso_uid&ezit_sso_token`
consumes it on `init`: `hash_equals` + expiry check → `wp_set_auth_cookie`
→ `admin_url()`.

## Server endpoints (`/admin-api/*`)

All require a session + CSRF and the **`site_login` capability**
(`AdminApi::can()` — super_admin has every cap; other roles grant caps
via `ROLE_CAPS`).

| Route | Purpose |
|---|---|
| `GET site-users?activation=N` | Proxy the site's user list |
| `POST site-login` | `{activation_id, mode, …}` → `{login_url}` |
| `GET site-logins?activation=N` | Saved logins (usernames only — never passwords) |
| `POST site-logins` | Save a credential without logging in |
| `POST site-logins/{id}/reveal` | Decrypt for display — audited |
| `DELETE site-logins/{id}` | Forget a saved login |

## Credential vault

`site_logins` rows store `password_enc` — **AES-256-GCM** keyed by
`sha256("ezit.site_logins." . server_secret)` (`SiteLogin::seal()/open()`).
`reveal` is an explicit endpoint and writes an
`admin_site_login_reveal` entry to `activity_logs`, so password exposure
is always auditable. Saved credentials are stored **only after a
successful login** — a wrong password is never parked.

## Auditing

Every attempt lands in `activity_logs`:
`admin_site_login` (mode + admin id, success/fail) and
`admin_site_login_reveal` (which credential, which admin, which site).

## Roles & permissions (planned)

- **Server side**: `admins.role` exists (`super_admin` default) and every
  site-login handler goes through `requireCap('site_login')`. To give a
  future `staff` role login rights — or only provisioning "support"
  accounts — add the cap in `AdminApi::ROLE_CAPS`; nothing else changes.
- **Site side**: `provision` already accepts an arbitrary WP role name.
  A "support" role with limited admin capabilities can be defined by a
  client plugin/theme (`add_role()`), then pushed by name — the protocol
  passes it through untouched.

## Security notes

- Login URLs are single-use, 2-minute TTL, token hashed at rest.
- The api_key + signed nonce authenticates the server; timestamps bound
  replay to 5 minutes.
- `credential` verification is rate-limited site-side (10/min) so a
  leaked key can't brute-force WP passwords through the endpoint.
- The server only POSTs to `site_url` recorded at activation
  (`http`/`https`, no redirects) — a poisoned activation can't exfiltrate
  the api_key to an arbitrary host.
