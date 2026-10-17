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
            <a data-nav="activations" class="nav-link"><span class="nav-ico">◉</span> Activations</a>
            <a data-nav="products" class="nav-link"><span class="nav-ico">▣</span> Products</a>
            <a data-nav="logs" class="nav-link"><span class="nav-ico">≡</span> Activity Log</a>
            <a data-nav="config" class="nav-link"><span class="nav-ico">⚙</span> Server Config</a>
            <a data-nav="account" class="nav-link"><span class="nav-ico">◐</span> Account</a>
        </nav>
        <div class="sidebar-foot">
            <div id="admin-name" class="admin-name"></div>
            <div class="sidebar-btns">
                <button id="theme-toggle" class="btn btn-ghost btn-sm">◐ Light mode</button>
                <button id="logout-btn" class="btn btn-ghost btn-sm">Sign out</button>
            </div>
        </div>
    </aside>

    <main class="main">
        <!-- Dashboard -->
        <section id="view-dashboard" class="view">
            <h1>Dashboard</h1>
            <div class="stat-grid" id="stat-grid"></div>
            <h2>Recent activity</h2>
            <div class="panel"><table id="recent-table"><thead><tr><th>Action</th><th>Domain</th><th>Result</th><th>When</th></tr></thead><tbody></tbody></table></div>
        </section>

        <!-- Licenses -->
        <section id="view-licenses" class="view hidden">
            <div class="view-head">
                <h1>Licenses</h1>
                <div class="head-actions">
                    <input id="license-search" class="input-sm" placeholder="Search code / client / email…">
                    <button class="btn btn-primary" id="new-license-btn">+ New license</button>
                </div>
            </div>
            <div class="panel"><table id="licenses-table">
                <thead><tr><th>Code</th><th>Client</th><th>Product</th><th>Status</th><th>Activations</th><th>Expires</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
        </section>

        <!-- Activations -->
        <section id="view-activations" class="view hidden">
            <h1>Activations</h1>
            <div class="panel"><table id="activations-table">
                <thead><tr><th>Domain</th><th>License</th><th>Client</th><th>Product</th><th>IP</th><th>Status</th><th>Activated</th><th>Last check</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
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

        <!-- Logs -->
        <section id="view-logs" class="view hidden">
            <div class="view-head">
                <h1>Activity Log</h1>
                <div class="head-actions">
                    <input id="logs-q" class="input-sm" placeholder="Search license / domain / IP / detail…">
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
            <div class="panel"><table id="logs-table">
                <thead><tr><th>Time</th><th>Action</th><th>License</th><th>Domain</th><th>IP</th><th>Result</th><th>Detail</th></tr></thead>
                <tbody></tbody>
            </table></div>
            <div class="pager">
                <span id="logs-info" class="hint"></span>
                <div class="pager-btns">
                    <button class="btn btn-ghost btn-sm" id="logs-prev">← Prev</button>
                    <button class="btn btn-ghost btn-sm" id="logs-next">Next →</button>
                </div>
            </div>
        </section>

        <!-- Server config -->
        <section id="view-config" class="view hidden">
            <h1>Server Config <span id="cfg-version" class="badge badge-ok"></span></h1>
            <p class="hint">Bootstrap document served by <code>/api/get_config</code>. EzIT Core reads this to locate the API, endpoint map, services and notices. Every save publishes a new immutable version — sites detect it via <code>config_version</code>.</p>
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
            <h2>Publish history</h2>
            <div class="panel"><table id="cfg-history-table">
                <thead><tr><th>Version</th><th>Note</th><th>Published</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
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

<div id="toast" class="toast hidden"></div>
<script src="/assets/js/admin.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>
</body>
</html>
