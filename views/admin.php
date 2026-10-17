<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ez IT Solutions — License Manager</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%2376b900'/><text x='16' y='22' font-size='14' font-weight='bold' text-anchor='middle' fill='%230b0e0b'>EZ</text></svg>">
<script>document.documentElement.dataset.theme = localStorage.getItem('ezls-theme') || 'dark';</script>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<!-- ─────────────── Login ─────────────── -->
<div id="login-screen" class="login-wrap hidden">
    <div class="login-card">
        <div class="brand">
            <div class="brand-mark">EZ</div>
            <div>
                <div class="brand-name">EZ IT SOLUTIONS</div>
                <div class="brand-sub">License Manager</div>
            </div>
        </div>
        <div id="login-error" class="alert hidden"></div>
        <form id="login-form">
            <label>Email</label>
            <input type="email" id="login-email" autocomplete="username" required autofocus>
            <label>Password</label>
            <input type="password" id="login-password" autocomplete="current-password" required>
            <button type="submit" class="btn btn-primary btn-block" id="login-btn">Sign in</button>
        </form>
    </div>
</div>

<!-- ─────────────── App ─────────────── -->
<div id="app" class="app hidden">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">EZ</div>
            <div>
                <div class="brand-name">EZ IT SOLUTIONS</div>
                <div class="brand-sub">License Manager</div>
            </div>
        </div>
        <nav>
            <a data-nav="dashboard" class="nav-link active"><span class="nav-ico">◈</span> Dashboard</a>
            <a data-nav="licenses" class="nav-link"><span class="nav-ico">⚿</span> Licenses</a>
            <a data-nav="products" class="nav-link"><span class="nav-ico">▣</span> Products</a>
            <a data-nav="updates" class="nav-link"><span class="nav-ico">⇧</span> Updates</a>
            <a data-nav="logs" class="nav-link"><span class="nav-ico">≡</span> Activity Log</a>
            <a data-nav="config" class="nav-link"><span class="nav-ico">⚙</span> Server Config</a>
            <a data-nav="account" class="nav-link"><span class="nav-ico">◐</span> Account</a>
        </nav>
        <div class="sidebar-foot">
            <div class="avatar" id="foot-avatar"></div>
            <div class="sidebar-user">
                <b id="foot-name"></b>
                <span id="foot-email"></span>
            </div>
        </div>
        <div class="sidebar-cred">Ez IT License Manager · © 2026</div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="top-status"><span id="top-status" class="status-pill status-muted" title="Public API state"><span class="status-dot"></span>Checking…</span></div>
            <div class="top-actions">
                <button id="theme-toggle" class="top-icon" type="button" title="Toggle theme" aria-label="Toggle theme"></button>
                <div class="top-user">
                    <button class="avatar avatar-btn" id="profile-btn" type="button" aria-haspopup="true" aria-expanded="false" title="Account"></button>
                    <div id="profile-menu" class="profile-menu hidden">
                        <div class="profile-head">
                            <div class="avatar" id="pm-avatar"></div>
                            <div class="profile-id">
                                <b id="pm-name"></b>
                                <span id="pm-email"></span>
                            </div>
                        </div>
                        <button class="pm-item" id="pm-profile" type="button">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Account settings
                        </button>
                        <div class="pm-sep"></div>
                        <button class="pm-item pm-logout" id="logout-btn" type="button">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            Logout
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard -->
        <section id="view-dashboard" class="view">
            <h1>Dashboard</h1>
            <div class="stat-grid" id="stat-grid"></div>
            <h2>Recent activity</h2>
            <div class="panel"><table id="recent-table"><thead><tr><th>Action</th><th>Domain</th><th>Result</th><th>When</th></tr></thead><tbody></tbody></table></div>
        </section>

        <!-- Licenses (activations live in the second tab) -->
        <section id="view-licenses" class="view hidden">
            <div class="view-head"><h1>Licenses</h1>
                <input id="license-search" class="input-sm head-search" placeholder="Search code / client / email…">
                <div class="head-actions" id="lic-actions">
                    <button class="btn btn-primary" id="new-license-btn">+ New license</button>
                </div>
            </div>
            <div class="tabs">
                <button class="tab active" data-tab="licenses-list" type="button">Licenses</button>
                <button class="tab" data-tab="activations-list" type="button">Activations</button>
            </div>
            <div id="tab-licenses-list" class="tab-pane">
                <div class="panel"><table id="licenses-table">
                    <thead><tr><th>Code</th><th>Client</th><th>Product</th><th>Status</th><th>Activations</th><th>Expires</th><th></th></tr></thead>
                    <tbody></tbody>
                </table></div>
            </div>
            <div id="tab-activations-list" class="tab-pane hidden">
                <div class="pane-actions">
                    <input id="act-search" class="input-sm" placeholder="Search domain / license / client / IP…">
                </div>
                <div class="panel"><table id="activations-table">
                    <thead><tr><th>Domain</th><th>License</th><th>Client</th><th>Product</th><th>IP</th><th>Status</th><th>Activated</th><th>Last check</th><th></th></tr></thead>
                    <tbody></tbody>
                </table></div>
            </div>
        </section>

        <!-- Products -->
        <section id="view-products" class="view hidden">
            <div class="view-head"><h1>Products</h1><button class="btn btn-primary" id="new-product-btn">+ New product</button></div>
            <div class="panel"><table id="products-table">
                <thead><tr><th>Name</th><th>Product ID</th><th>API key (LB-API-KEY)</th><th>Recovery code</th><th>Version</th><th>Status</th><th>Licenses</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
            <p class="hint">Copy <code>api_key</code> into each site's <code>platform/core/core.json</code> as <code>"apiKey"</code>, and set <code>"apiUrl"</code> to this server's URL.</p>
        </section>

        <!-- Updates -->
        <section id="view-updates" class="view hidden">
            <div class="view-head"><h1>Update packages</h1></div>
            <p class="hint">Published packages feed <code>check_update</code>, <code>get_update_size</code> and <code>download_update</code>. Sites only download with an active license; publishing bumps the product's <code>latest_version</code> when newer.</p>
            <div class="panel form-panel" style="margin-bottom:1rem">
                <div id="upd-msg" class="alert hidden"></div>
                <form id="update-form" enctype="multipart/form-data">
                    <label>Product</label><select id="upd-product" required></select>
                    <label>Version</label><input id="upd-version" placeholder="1.2.0" required>
                    <label>Package (.zip)</label>
                    <div class="file-pick">
                        <input id="upd-file" type="file" accept=".zip" required>
                        <span class="btn file-pick-btn">Choose file</span>
                        <span id="upd-file-name" class="file-pick-name">No file chosen</span>
                    </div>
                    <label>Summary (optional)</label><input id="upd-summary" placeholder="What's new — one line">
                    <label>Changelog (optional)</label><textarea id="upd-changelog" rows="3" spellcheck="false"></textarea>
                    <label style="flex-direction:row;align-items:center;gap:.5rem"><input type="checkbox" id="upd-hassql" style="width:auto"> Requires SQL migration (<code>has_sql</code>)</label>
                    <button class="btn btn-primary" type="submit" id="upd-btn">Publish update</button>
                </form>
            </div>
            <div class="panel"><table id="updates-table">
                <thead><tr><th>Product</th><th>Version</th><th>Released</th><th>Size</th><th>SQL</th><th>Summary</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
        </section>

        <!-- Logs -->
        <section id="view-logs" class="view hidden">
            <div class="view-head"><h1>Activity Log</h1>
                <input id="logs-q" class="input-sm head-search" placeholder="Search license / domain / IP / detail…">
                <div class="head-actions" id="log-actions">
                    <select id="logs-action" class="input-sm"><option value="">All actions</option></select>
                    <select id="logs-result" class="input-sm">
                        <option value="">All results</option>
                        <option value="1">Success</option>
                        <option value="0">Failed</option>
                    </select>
                    <select id="logs-per" class="input-sm">
                        <option value="25">25 / page</option>
                        <option value="50" selected>50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                </div>
            </div>
            <div class="tabs">
                <button class="tab active" data-tab="log-activity" type="button">Activity</button>
                <button class="tab" data-tab="log-retention" type="button">Retention rules</button>
            </div>
            <div id="tab-log-activity" class="tab-pane">
                <div class="pager pager-top">
                    <div class="pager-btns" id="logs-pager-top"></div>
                </div>
                <div class="panel"><table id="logs-table">
                    <thead><tr><th>Time</th><th>Action</th><th>License</th><th>Domain</th><th>IP</th><th>Result</th><th>Detail</th></tr></thead>
                    <tbody></tbody>
                </table></div>
                <div class="pager">
                    <div class="pager-left">
                        <span id="logs-info" class="hint"></span>
                        <span class="pager-jump">Page <select id="logs-page-sel" class="input-sm"></select> of <span id="logs-pages-n">1</span></span>
                    </div>
                    <div class="pager-btns" id="logs-pager"></div>
                </div>
            </div>
            <div id="tab-log-retention" class="tab-pane hidden">
                <p class="hint">Mail-filter style: each action is governed by its most specific rule — <code>*</code> is the catch-all. <em>Keep for</em> is the max age in days (<b>forever</b> = never purge); <em>Pin last</em> always keeps that many newest rows <b>per site &amp; license</b>, even expired — your last-known record for diagnostics. Runs automatically once a day, or on demand.</p>
                <div class="panel">
                    <table id="rules-table">
                        <thead><tr><th>Action</th><th>Keep for</th><th>Pin last</th><th>Enabled</th><th>Log rows</th><th></th></tr></thead>
                        <tbody></tbody>
                    </table>
                    <div class="rule-add">
                        <select id="rule-action" title="Which log action this rule governs"></select>
                        <input id="rule-days" type="number" min="0" value="30" title="Keep for N days">
                        <label class="rule-check"><input type="checkbox" id="rule-forever"> forever</label>
                        <input id="rule-keep" type="number" min="0" max="100" value="0" title="Always pin the newest N rows per site">
                        <button class="btn" id="rule-add" type="button">+ Add rule</button>
                        <button class="btn btn-primary" id="purge-now" type="button">Run purge now</button>
                    </div>
                </div>
                <p class="hint" id="rules-note"></p>
            </div>
        </section>

        <!-- Server config -->
        <section id="view-config" class="view hidden">
            <h1>Server Config <span id="cfg-version" class="badge badge-ok"></span></h1>
            <p class="hint">Bootstrap document served by <code>/api/get_config</code>. EzIT Core reads this to locate the API, endpoint map, services and notices. Every save publishes a new immutable version — sites detect it via <code>config_version</code>.</p>
            <div class="tabs">
                <button class="tab active" data-tab="cfg-editor" type="button">Configuration</button>
                <button class="tab" data-tab="cfg-history" type="button">Publish history</button>
            </div>
            <div id="tab-cfg-editor" class="tab-pane">
                <div class="panel form-panel" style="margin-bottom:1rem">
                    <label>Maintenance mode</label>
                    <p class="hint" style="margin-top:0">Planned outage: the public API answers every call instantly with <code>503 SERVER_UNAVAILABLE</code> so client sites fail fast instead of timing out. The admin panel stays up. Flag file: <code>data/.maintenance</code>.</p>
                    <p id="maint-state" class="mono" style="margin:.4rem 0">loading…</p>
                    <button class="btn" id="maint-toggle">…</button>
                </div>
                <div class="panel form-panel">
                    <div id="cfg-msg" class="alert hidden"></div>
                    <label>Payload (JSON)</label>
                    <textarea id="cfg-payload" class="mono code-area" rows="16" spellcheck="false"></textarea>
                    <label>Version note (why this change)</label><input id="cfg-note" placeholder="e.g. point api_base at EzOps hostname">
                    <button class="btn btn-primary" id="cfg-publish">Publish new version</button>
                </div>
            </div>
            <div id="tab-cfg-history" class="tab-pane hidden">
                <div class="panel"><table id="cfg-history-table">
                    <thead><tr><th>Version</th><th>Note</th><th>Published</th><th></th></tr></thead>
                    <tbody></tbody>
                </table></div>
            </div>
        </section>

        <!-- Account -->
        <section id="view-account" class="view hidden">
            <h1>Account</h1>
            <div class="panel form-panel">
                <div id="account-msg" class="alert hidden"></div>
                <label>Display name</label><input id="acc-name">
                <label>New password (leave blank to keep)</label><input type="password" id="acc-password" placeholder="Min 10 characters">
                <button class="btn btn-primary" id="acc-save">Save changes</button>
            </div>
        </section>
    </main>
</div>

<!-- ─────────────── License modal ─────────────── -->
<div id="license-modal" class="modal hidden">
    <div class="modal-card">
        <h2 id="license-modal-title">New license</h2>
        <div id="license-modal-error" class="alert hidden"></div>
        <div class="grid2">
            <div><label>License code</label>
                <div class="input-btn"><input id="lic-code" placeholder="Auto-generated"><button type="button" class="btn btn-ghost btn-sm" id="lic-gen">Generate</button></div>
            </div>
            <div><label>Product</label><select id="lic-product"></select></div>
        </div>
        <div class="grid2">
            <div><label>Client / site name</label><input id="lic-client" placeholder="e.g. Legendary 3D Prints" required></div>
            <div><label>Client email</label><input type="email" id="lic-email"></div>
        </div>
        <div class="grid3">
            <div><label>Status</label><select id="lic-status"><option>active</option><option>suspended</option><option>expired</option></select></div>
            <div><label>Max domains</label><input type="number" id="lic-max" value="1" min="1"></div>
            <div><label>Expires (blank = lifetime)</label><input type="date" id="lic-expires"></div>
        </div>
        <label>Notes</label><input id="lic-notes">
        <label>Features <small style="color:var(--muted)">(comma-separated — issued into the license cert)</small></label><input id="lic-features" placeholder="e.g. updates, marketplace, priority-support">
        <div class="modal-actions">
            <button class="btn btn-ghost" data-close>Cancel</button>
            <button class="btn btn-primary" id="lic-save">Save license</button>
        </div>
    </div>
</div>

<!-- ─────────────── Product modal ─────────────── -->
<div id="product-modal" class="modal hidden">
    <div class="modal-card">
        <h2 id="product-modal-title">New product</h2>
        <div id="product-modal-error" class="alert hidden"></div>
        <div class="grid2">
            <div><label>Product ID (matches core.json)</label><input id="prod-id" placeholder="5F061D22"></div>
            <div><label>Latest version</label><input id="prod-version" value="1.0.0"></div>
        </div>
        <div class="grid2">
            <div><label>Name</label><input id="prod-name" placeholder="Botble CMS"></div>
            <div id="prod-status-wrap" class="hidden"><label>Status</label><select id="prod-status"><option value="enabled">enabled</option><option value="disabled">disabled</option></select></div>
        </div>
        <div id="prod-key-wrap" class="hidden">
            <label>API key (LB-API-KEY)</label>
            <div class="input-btn"><input id="prod-key" class="mono" readonly><button type="button" class="btn btn-ghost btn-sm" id="prod-key-edit">Edit</button><button type="button" class="btn btn-ghost btn-sm" id="prod-regen">Regenerate</button></div>
            <p class="hint">Changing the key breaks connected sites until their core.json apiKey is updated.</p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-ghost" data-close>Cancel</button>
            <button class="btn btn-primary" id="prod-save">Save product</button>
        </div>
    </div>
</div>

<!-- ─────────────── License users modal ─────────────── -->
<div id="users-modal" class="modal hidden">
    <div class="modal-card">
        <h2 id="users-modal-title">Gate users</h2>
        <p class="hint">These accounts log in through the site's under-construction gate (WordPress plugin). Managed here — no WP users needed.</p>
        <div id="users-modal-error" class="alert hidden"></div>
        <table id="users-table" class="mini-table">
            <thead><tr><th>Username</th><th>Name</th><th>Created</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
        <div class="grid3">
            <div><label>Username</label><input id="lu-user" placeholder="e.g. chris.h"></div>
            <div><label>Name</label><input id="lu-name" placeholder="Optional"></div>
            <div><label>Password</label><input id="lu-pass" type="password" placeholder="Min 8 chars"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-ghost" data-close>Close</button>
            <button class="btn btn-primary" id="lu-add">Add user</button>
        </div>
    </div>
</div>

<!-- ─────────────── Confirm modal (replaces window.confirm) ─────────────── -->
<div id="confirm-modal" class="modal hidden">
    <div class="modal-card">
        <h2 id="confirm-title">Are you sure?</h2>
        <p id="confirm-text" class="hint"></p>
        <div class="modal-actions">
            <button class="btn btn-ghost" id="confirm-cancel">Cancel</button>
            <button class="btn btn-danger" id="confirm-ok">Confirm</button>
        </div>
    </div>
</div>

<!-- ─────────────── Recovery code modal (shown ONCE — stored hashed) ─────────────── -->
<div id="code-modal" class="modal hidden">
    <div class="modal-card">
        <h2 id="code-title">Recovery code</h2>
        <p class="hint" id="code-note">Shown once — the server stores only its hash. Save it somewhere safe; it arms a site's emergency gate override while this server is offline.</p>
        <div class="input-btn"><input id="code-value" class="mono" readonly><button type="button" class="btn btn-ghost btn-sm" id="code-copy">Copy</button></div>
        <div class="modal-actions">
            <button class="btn btn-primary" data-close>Done</button>
        </div>
    </div>
</div>

<div id="toast" class="toast hidden"></div>
<script src="/assets/js/admin.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>
</body>
</html>
