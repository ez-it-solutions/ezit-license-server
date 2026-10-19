# Roles & permissions — design draft

> Status: **draft** — the design surface for who can do what on the
> license server. First consumer: the site-side provisioning wizard
> (`ROADMAP-BILLING.md` §9g) and price/promo overrides (§9a).

## Current state

`admins` is flat — every logged-in account is a superadmin. Auth is
session-based (HTTP-only + SameSite=Lax), CSRF on mutations, all admin
traffic flows through `AdminApi` (`/admin-api/*`) into the SPA. Per-IP
throttles already sit on login. Nothing to rip out — this is additive.

## Model: flat roles + per-admin permission overrides

Match the server's single-table style instead of a full RBAC join
ladder:

- `admins.role VARCHAR(32) NOT NULL DEFAULT 'admin'` — additive ALTER,
  existing rows stay `admin` until reassigned (the installer account
  gets `owner`).
- `admins.permissions TEXT NULL` — JSON array of *extra* grants on top
  of the role (e.g. give one `staff` member `pricing.override` without
  promoting them). Revocation = reassign the role; overrides are
  additive only, never subtractive — keeps the mental model small.

### Seeded roles

| Role | Can |
|---|---|
| `owner` | Everything, incl. `admins.manage` and `config.publish` / maintenance. The installer account. |
| `admin` | Licenses, products, customers, catalog, updates, logs — everything except managing admins and publishing server config. |
| `billing` | Customers, invoices/pricing views, `pricing.override`, `promos.apply`, `licenses.read`. |
| `staff` | Provisioning: `customers.write`, `licenses.issue`, `catalog.read`, `staff_api` — the wizard's default. **No** price overrides unless explicitly granted. |
| `viewer` | Read-only everything except `admins.*` and `settings.*`. For bookkeepers/auditors. |

### Permission catalog (dot-notated)

```
licenses.read / licenses.issue / licenses.suspend / licenses.delete
activations.read / activations.revoke
customers.read / customers.write / customers.import / customers.delete
pricing.read / pricing.override / promos.apply
products.read / products.write / products.recovery_mint
catalog.read / catalog.write / catalog.publish
updates.read / updates.publish / updates.delete
logs.view / logs.retention
config.read / config.publish / maintenance.toggle
admins.read / admins.manage
staff_api                              -- may authenticate via staff token
```

`role → permissions` is a static map in `src/Auth.php` (new, ~60 lines);
`require_perm($pdo, 'licenses.issue')` is the single enforcement helper
called at the top of each `AdminApi` route handler. The map ships as
data (PHP const array), not a table — roles don't need runtime editing
in v1; per-admin overrides carry the flexibility.

## Staff tokens — how the site-side wizard authenticates

The wizard runs inside a client site's wp-admin — cookie sessions don't
fit cross-origin, so we reuse the `access_login` pattern:

- `POST /api/staff_login` — `email + password` (+ existing per-IP
  throttle) → `{status, token}` where token is an HMAC-signed payload:
  `{admin_id, perms:[…], exp}` — stateless, ~15 min expiry, refreshed
  by the wizard mid-flow.
- `POST /api/staff/*` endpoints carry the token; each verifies HMAC +
  exp + `staff_api` permission, then `require_perm()` for the specific
  action. A revoked admin fails on the next token refresh (tokens are
  short-lived by design).
- Staff endpoints in v1: `staff/customers_search`, `staff/customer_create`,
  `staff/catalog` (plans + prices), `staff/provision` (license + invoice
  draft). All audit-logged as `staff_*` actions with admin_id in detail.

## Schema (additive, both drivers)

```sql
ALTER TABLE admins ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'admin';
ALTER TABLE admins ADD COLUMN permissions TEXT DEFAULT NULL;   -- JSON []
CREATE TABLE IF NOT EXISTS staff_token_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL REFERENCES admins(id) ON DELETE CASCADE,
    action VARCHAR(40) NOT NULL,        -- which staff_* endpoint ran
    site_url VARCHAR(255) DEFAULT NULL, -- which client site the wizard ran on
    detail TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

Tokens themselves stay stateless (no token table to leak); the audit
table records *use*, which is what we care about.

## SPA side

Login response gains `{role, perms:[]}`; the SPA hides/disables UI per
permission but the server stays authoritative — a hidden button isn't
security, `require_perm` is.

## Build order

1. `admins.role` + `permissions` + `src/Auth.php` (map + `require_perm`)
2. Sprinkle `require_perm()` across existing `AdminApi` handlers
3. `staff_login` + staff token plumbing + `staff/customers_search`
4. Wizard-facing `staff/provision` lands with billing 9a/9b
5. Admin-management UI in the SPA (list admins, set role + overrides)

## Open questions

- ❓ Do overrides ever need to be *subtractive* (admin minus one perm)?
  v1 says no — demote the role instead. Flagging in case we hit a real
  need.
- ❓ `viewer` needed at launch, or is three roles (owner/admin/staff)
  enough until billing lands?
- ❓ 2FA for owner/admin — TOTP now or post-9g? Login throttles exist;
  TOTP is ~40 lines on top of `admins`.
