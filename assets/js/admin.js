/* Ez IT Solutions — License Manager SPA (vanilla JS, no build step) */
(() => {
    'use strict';

    const $ = (sel, el = document) => el.querySelector(sel);
    const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];

    let csrf = '';
    let products = [];
    let licenses = [];
    let logPage = 1;
    let logRows = [];
    let currentView = 'dashboard';
    let pendingLogFocus = 0;

    /* Dashboard display settings — loaded from GET admin-api/settings.
     * timezone: '' means "server default" (server_tz). */
    let SETTINGS = { timezone: '', date_format: 'mdy', time_format: '24h' };
    let SERVER_TZ = 'UTC';

    /* ── fetch helper ── */
    async function api(path, opts = {}) {
        const res = await fetch('/admin-api/' + path, {
            method: opts.method || 'GET',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: opts.body ? JSON.stringify(opts.body) : undefined,
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
        return data;
    }

    /** Multipart POST with real upload progress (fetch can't report it). */
    function postForm(path, fd, onprogress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/admin-api/' + path);
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
            xhr.withCredentials = true;
            xhr.upload.onprogress = (e) => {
                if (onprogress) onprogress(e.lengthComputable ? e.loaded / e.total : null);
            };
            xhr.onload = () => {
                let data = {};
                try { data = JSON.parse(xhr.responseText || '{}'); } catch { /* non-JSON */ }
                if (xhr.status >= 400) { reject(new Error(data.error || ('HTTP ' + xhr.status))); return; }
                resolve(data);
            };
            xhr.onerror = () => reject(new Error('Network error during upload'));
            xhr.send(fd);
        });
    }

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (msg) => {
        const t = $('#toast');
        t.textContent = msg;
        t.classList.remove('hidden');
        clearTimeout(t._t);
        t._t = setTimeout(() => t.classList.add('hidden'), 3000);
    };
    const ago = (ts) => {
        const d = new Date(ts.replace(' ', 'T') + 'Z');
        const s = (Date.now() - d.getTime()) / 1000;
        if (s < 60) return 'just now';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return fmtDate(ts);
    };
    /* Timestamps arrive as UTC "Y-m-d H:i:s" — parse as such, render in
     * the configured timezone / formats (Settings page). */
    const DATE_LOCALE = { mdy: 'en-US', dmy: 'en-GB', ymd: 'en-CA' };
    const tsToDate = (ts) => (ts ? new Date(String(ts).replace(' ', 'T') + 'Z') : null);
    const fmtDate = (ts, s = SETTINGS) => {
        const d = tsToDate(ts);
        if (!d) return '—';
        try {
            return d.toLocaleDateString(DATE_LOCALE[s.date_format] || 'en-US', { timeZone: s.timezone || SERVER_TZ });
        } catch { return d.toLocaleDateString(); }
    };
    const fmtTime = (ts, s = SETTINGS) => {
        const d = tsToDate(ts);
        if (!d) return '—';
        try {
            return d.toLocaleTimeString('en-US', {
                timeZone: s.timezone || SERVER_TZ,
                hour12: s.time_format === '12h',
                hour: '2-digit', minute: '2-digit', second: '2-digit',
            });
        } catch { return d.toLocaleTimeString(); }
    };
    const badge = (status) => `<span class="badge badge-${esc(status)}">${esc(status)}</span>`;
    const okBadge = (ok) => ok ? '<span class="badge badge-ok">ok</span>' : '<span class="badge badge-fail">fail</span>';

    /* Promise-based confirm modal — never use window.confirm/alert. */
    function askConfirm(text, okLabel = 'Confirm') {
        return new Promise((resolve) => {
            const m = $('#confirm-modal');
            let settled = false;
            const done = (v) => {
                if (settled) return;
                settled = true;
                m.classList.add('hidden');
                document.removeEventListener('keydown', onKey);
                resolve(v);
            };
            const onKey = (e) => { if (e.key === 'Escape') done(false); };
            $('#confirm-text').textContent = text;
            $('#confirm-ok').textContent = okLabel;
            $('#confirm-ok').onclick = () => done(true);
            $('#confirm-cancel').onclick = () => done(false);
            m.onclick = (e) => { if (e.target === m) done(false); };
            document.addEventListener('keydown', onKey);
            m.classList.remove('hidden');
        });
    }

    /* ── theme toggle (dark default; persisted in localStorage) ── */
    const ICON_SUN = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    const ICON_MOON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
    const themeBtn = $('#theme-toggle');
    const syncThemeBtn = () => {
        // Icon shows what you'll switch to — moon in light mode, sun in dark.
        const light = document.documentElement.dataset.theme === 'light';
        themeBtn.innerHTML = light ? ICON_MOON : ICON_SUN;
        themeBtn.title = light ? 'Switch to dark mode' : 'Switch to light mode';
    };
    themeBtn.addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
        document.documentElement.dataset.theme = next;
        localStorage.setItem('ezls-theme', next);
        syncThemeBtn();
    });
    syncThemeBtn();

    /* ── auth ── */
    async function init() {
        try {
            const s = await api('session');
            if (s.authenticated) {
                csrf = s.csrf;
                fillAdmin(s.admin);
                $('#acc-name').value = s.admin.name;
                showApp();
            } else {
                showLogin();
            }
        } catch {
            showLogin();
        }
    }

    function fillAdmin(admin) {
        const initials = String(admin.name || '?')
            .split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
        $('#foot-name').textContent = admin.name;
        $('#foot-email').textContent = admin.email;
        $('#foot-avatar').textContent = initials;
        $('#profile-btn').textContent = initials;
        $('#pm-avatar').textContent = initials;
        $('#pm-name').textContent = admin.name;
        $('#pm-email').textContent = admin.email;
    }

    /* ── profile dropdown ── */
    const pmBtn = $('#profile-btn');
    const pmMenu = $('#profile-menu');
    const closeMenu = () => { pmMenu.classList.add('hidden'); pmBtn.setAttribute('aria-expanded', 'false'); };
    pmBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = pmMenu.classList.toggle('hidden') === false;
        pmBtn.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('.top-user')) closeMenu(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
    $('#pm-profile').addEventListener('click', () => { closeMenu(); navigate('account'); });

    function showLogin() {
        $('#login-screen').classList.remove('hidden');
        $('#app').classList.add('hidden');
    }
    async function showApp() {
        $('#login-screen').classList.add('hidden');
        $('#app').classList.remove('hidden');
        await loadSettingsData().catch(() => {}); // formatters need it before first render
        // Legacy #view URLs → clean paths
        if (location.hash.length > 1) {
            const legacy = location.hash.slice(1);
            history.replaceState({}, '', ROUTES[legacy] ? '/' + legacy : '/');
        }
        navigate(location.pathname.slice(1) || 'dashboard');
        loadTopStatus();
    }

    $('#login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = $('#login-btn');
        btn.disabled = true;
        try {
            const r = await api('login', {
                method: 'POST',
                body: { email: $('#login-email').value.trim(), password: $('#login-password').value },
            });
            csrf = r.csrf;
            fillAdmin(r.admin);
            $('#acc-name').value = r.admin.name;
            showApp();
        } catch (err) {
            const el = $('#login-error');
            el.textContent = err.message;
            el.classList.remove('hidden');
        } finally {
            btn.disabled = false;
        }
    });

    $('#logout-btn').addEventListener('click', async () => {
        await api('logout', { method: 'POST' }).catch(() => {});
        location.reload();
    });

    /* ── mobile nav drawer ── */
    const navOpen = (on) => {
        $('#app').classList.toggle('nav-open', on);
        $('#nav-scrim').classList.toggle('hidden', !on);
        $('#nav-toggle').setAttribute('aria-expanded', String(on));
    };
    $('#nav-toggle').addEventListener('click', () => navOpen(!$('#app').classList.contains('nav-open')));
    $('#nav-scrim').addEventListener('click', () => navOpen(false));

    /* ── navigation ── */
    $$('.nav-link').forEach((a) => a.addEventListener('click', () => navigate(a.dataset.nav)));

    const VIEWS = { dashboard: loadDashboard, licenses: loadLicenses, products: loadProducts, updates: loadUpdates, logs: loadLogs, config: loadConfig, settings: loadSettingsView, account: () => {} };

    // URL slug → {view, tab}. 'config' stays routable for old links but
    // canonicalizes to 'server-config' — /config collides with the
    // physical config/ dir, which Apache resolves to a 403 before
    // index.php ever runs. 'activations' maps to the Licenses tab.
    const ROUTES = {
        '':              { view: 'dashboard' },
        'dashboard':     { view: 'dashboard' },
        'licenses':      { view: 'licenses' },
        'activations':   { view: 'licenses', tab: 'activations-list' },
        'products':      { view: 'products' },
        'updates':       { view: 'updates' },
        'logs':          { view: 'logs' },
        'config':        { view: 'config' },
        'server-config': { view: 'config' },
        'settings':      { view: 'settings' },
        'account':       { view: 'account' },
    };
    // Canonical slug per view — what the address bar normalizes to.
    const SLUG = { dashboard: '', licenses: 'licenses', products: 'products', updates: 'updates', logs: 'logs', config: 'server-config', settings: 'settings', account: 'account' };

    function openTab(tabId) {
        const pane = $('#tab-' + tabId);
        if (!pane) return;
        const view = pane.closest('.view');
        const firstTab = view.querySelector('.tab').dataset.tab;
        view.querySelectorAll('.tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === tabId));
        view.querySelectorAll('.tab-pane').forEach((p) => p.classList.toggle('hidden', p.id !== 'tab-' + tabId));
        // Head actions + view-head search only make sense on the primary
        // tab — hide them elsewhere (activations has its own pane search).
        view.querySelectorAll('.head-actions, .head-search').forEach((el) => {
            el.classList.toggle('hidden', tabId !== firstTab);
        });
        // …unless a head button claims a non-primary tab (data-tab-show),
        // e.g. Products → Marketplace keeps its "+ New listing" in the header.
        view.querySelectorAll('.head-actions [data-tab-show]').forEach((el) => {
            el.classList.toggle('hidden', el.dataset.tabShow !== tabId);
            if (el.dataset.tabShow === tabId) {
                el.closest('.head-actions').classList.remove('hidden');
            }
        });
        if (tabId === 'activations-list') loadActivations();
        if (tabId === 'catalog-list') loadCatalog();
    }
    $$('.tab').forEach((b) => b.addEventListener('click', () => openTab(b.dataset.tab)));

    /* ── sortable headers — every table, client-side ── */
    (function initTableSort() {
        $$('table thead').forEach((thead) => {
            const table = thead.closest('table');
            const tbody = table.tBodies[0];
            if (!tbody) return;
            thead.querySelectorAll('th').forEach((th) => {
                if (th.textContent.trim()) th.classList.add('sortable');
            });
            thead.addEventListener('click', (e) => {
                const th = e.target.closest('th');
                if (!th || !th.textContent.trim()) return;
                const idx = th.cellIndex;
                const dir = th.dataset.sort === 'asc' ? 'desc' : 'asc';
                thead.querySelectorAll('th[data-sort]').forEach((h) => { h.classList.remove('sort-asc', 'sort-desc'); delete h.dataset.sort; });
                th.dataset.sort = dir;
                th.classList.add('sort-' + dir);
                const rows = [...tbody.rows];
                const data = rows.filter((r) => r.cells.length > 1);
                const tail = rows.filter((r) => r.cells.length <= 1); // empty-state colspan rows stay at the bottom
                const val = (r) => {
                    const c = r.cells[idx];
                    // data-sort wins — timestamp cells carry their ISO value
                    // so "time" columns sort chronologically, not lexically.
                    return c ? (c.dataset.sort !== undefined ? c.dataset.sort : c.textContent.trim()) : '';
                };
                const NUM = /^-?[\d.,]+%?$/;
                data.sort((a, b) => {
                    const x = val(a), y = val(b);
                    const cmp = (NUM.test(x) && NUM.test(y))
                        ? parseFloat(x.replace(/,/g, '')) - parseFloat(y.replace(/,/g, ''))
                        : x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
                    return dir === 'asc' ? cmp : -cmp;
                });
                [...data, ...tail].forEach((r) => tbody.appendChild(r));
            });
        });
    })();

    function navigate(slug) {
        const r = ROUTES[slug] || ROUTES.dashboard;
        const canonical = r.tab ? slug : SLUG[r.view]; // tab slugs keep their own URL
        const path = canonical ? '/' + canonical : '/';
        if (location.pathname !== path) {
            history.pushState({}, '', path);
        }
        $$('.nav-link').forEach((a) => a.classList.toggle('active', a.dataset.nav === r.view));
        $$('.view').forEach((v) => v.classList.add('hidden'));
        $('#view-' + r.view).classList.remove('hidden');
        currentView = r.view;
        navOpen(false);
        VIEWS[r.view]();
        if (r.tab) openTab(r.tab);
    }
    window.addEventListener('popstate', () => navigate(location.pathname.slice(1) || 'dashboard'));

    /* ── dashboard ── */
    $('#stat-grid').addEventListener('click', (e) => {
        const card = e.target.closest('[data-go]');
        if (card) navigate(card.dataset.go);
    });

    async function loadDashboard() {
        const s = await api('stats');
        $('#stat-grid').innerHTML = [
            [s.products, 'Products', 'products'],
            [s.licenses, 'Licenses', 'licenses'],
            [s.active_licenses, 'Active licenses', 'licenses'],
            [s.activations, 'Live activations', 'activations'],
            [s.checks_24h, 'Checks (24h)', 'logs'],
        ].map(([n, l, go]) => `<a class="stat stat-link" data-go="${go}"><div class="num">${n}</div><div class="lbl">${l}</div></a>`).join('');

        $('#recent-table tbody').innerHTML = s.recent_activity.length
            ? s.recent_activity.map((r) => `<tr data-log="${r.id}" title="Open in Activity Log"><td>${esc(r.action)}</td><td class="mono">${esc(r.domain || r.ip || r.detail || '—')}</td><td>${okBadge(!!r.success)}</td><td class="mono" data-sort="${esc(r.created_at)}">${fmtDate(r.created_at)}</td><td class="mono" title="${ago(r.created_at)}">${fmtTime(r.created_at)}</td></tr>`).join('')
            : '<tr><td colspan="5" class="empty">No activity yet</td></tr>';
    }

    // Dashboard row → jump straight to that event in the Activity Log.
    $('#recent-table tbody').addEventListener('click', (e) => {
        const tr = e.target.closest('tr[data-log]');
        if (!tr) return;
        pendingLogFocus = +tr.dataset.log;
        // Clear filters so the focused event can't be filtered out.
        $('#logs-q').value = '';
        $('#logs-action').value = '';
        $('#logs-result').value = '';
        navigate('logs');
    });

    /* ── licenses ── */
    async function loadLicenses() {
        await loadProductOptions();
        const q = $('#license-search').value.trim();
        const r = await api('licenses' + (q ? '?q=' + encodeURIComponent(q) : ''));
        licenses = r.data;
        $('#licenses-table tbody').innerHTML = licenses.length
            ? licenses.map((l) => `<tr>
                <td class="mono">${esc(l.code)}</td>
                <td>${esc(l.client_name)}${l.client_email ? `<br><small style="color:var(--muted)">${esc(l.client_email)}</small>` : ''}</td>
                <td>${esc(l.product_name)}</td>
                <td>${badge(l.status)}</td>
                <td>${l.activation_count} / ${l.max_activations}</td>
                <td>${l.expires_at ? esc(l.expires_at.slice(0, 10)) : 'lifetime'}</td>
                <td><div class="row-actions">
                    <button class="btn" onclick="EZL.editLicense(${l.id})">Edit</button>
                    <button class="btn" onclick="EZL.manageUsers(${l.id})">Users</button>
                    <button class="btn btn-danger" onclick="EZL.deleteLicense(${l.id})">Delete</button>
                </div></td></tr>`).join('')
            : '<tr><td colspan="7" class="empty">No licenses yet — create your first one</td></tr>';
    }

    async function loadProductOptions() {
        const r = await api('products');
        products = r.data;
        const opts = products.map((p) => `<option value="${p.id}">${esc(p.name)} (${esc(p.product_id)})</option>`).join('');
        if ($('#lic-product')) $('#lic-product').innerHTML = opts;
        if ($('#upd-product')) $('#upd-product').innerHTML = opts;
    }

    let editingLicense = null;
    window.EZL = window.EZL || {};
    window.EZL.editLicense = (id) => {
        const l = licenses.find((x) => x.id === id);
        if (!l) return;
        editingLicense = l;
        $('#license-modal-title').textContent = 'Edit license';
        $('#lic-code').value = l.code;
        $('#lic-code').disabled = true;
        $('#lic-product').value = l.product_id;
        $('#lic-product').disabled = true;
        $('#lic-client').value = l.client_name;
        $('#lic-email').value = l.client_email || '';
        $('#lic-status').value = l.status;
        $('#lic-max').value = l.max_activations;
        $('#lic-expires').value = l.expires_at ? l.expires_at.slice(0, 10) : '';
        $('#lic-notes').value = l.notes || '';
        try { $('#lic-features').value = l.features ? JSON.parse(l.features).join(', ') : ''; } catch { $('#lic-features').value = ''; }
        $('#license-modal').classList.remove('hidden');
    };
    window.EZL.deleteLicense = async (id) => {
        if (!await askConfirm('Delete this license and all its activations?', 'Delete')) return;
        try {
            await api('licenses/' + id, { method: 'DELETE' });
            toast('License deleted');
        } catch (e) { toast('Delete failed: ' + e.message); return; }
        loadLicenses();
    };
    window.EZL.toggleActivation = async (id, current) => {
        const next = current === 'enabled' ? 'disabled' : 'enabled';
        if (!await askConfirm((next === 'disabled' ? 'Disable' : 'Enable') + ' this activation? Disabling fails the site\'s next license check.', next === 'disabled' ? 'Disable' : 'Enable')) return;
        try {
            await api('activations/' + id, { method: 'PUT', body: { status: next } });
            toast('Activation ' + next);
        } catch (e) { toast('Update failed: ' + e.message); return; }
        loadActivations();
    };
    window.EZL.deleteActivation = async (id) => {
        if (!await askConfirm('Remove this activation? The site will go unlicensed on its next check.', 'Revoke')) return;
        try {
            await api('activations/' + id, { method: 'DELETE' });
            toast('Activation removed');
        } catch (e) { toast('Revoke failed: ' + e.message); return; }
        loadActivations();
    };
    /* ── update packages ── */
    const fmtSize = (b) => (b || 0) >= 1048576
        ? (b / 1048576).toFixed(1) + ' MB'
        : Math.max(1, Math.round((b || 0) / 1024)) + ' KB';

    async function loadUpdates() {
        await loadProductOptions();
        const r = await api('updates');
        $('#updates-table tbody').innerHTML = r.data.length
            ? r.data.map((u) => `<tr>
                <td>${esc(u.product_name)}<br><small style="color:var(--muted)">${esc(u.product_code)}</small></td>
                <td class="mono">${esc(u.version)}</td>
                <td>${esc(String(u.release_date || '').slice(0, 10))}</td>
                <td>${u.has_file ? fmtSize(u.file_size) : '<span class="badge badge-fail">missing</span>'}</td>
                <td>${((u.has_sql ? '<span class="badge badge-suspended">sql</span> ' : '') + (+u.forced ? '<span class="badge badge-fail">forced</span>' : '')) || '—'}</td>
                <td>${esc(u.summary || '')}</td>
                <td><div class="row-actions">
                    <button class="btn btn-danger" onclick="EZL.deleteUpdate(${u.id})">Delete</button>
                </div></td></tr>`).join('')
            : '<tr><td colspan="7" class="empty">No packages yet — publish your first update</td></tr>';
    }

    $('#upd-file').addEventListener('change', (e) => {
        $('#upd-file-name').textContent = e.target.files[0]?.name || 'No file chosen';
    });
    $('#update-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = $('#upd-btn');
        const setBtn = (html) => { btn.innerHTML = html; };
        btn.disabled = true;
        setBtn('<span class="btn-spin"></span> Uploading…');
        const fd = new FormData();
        fd.append('product_id', $('#upd-product').value);
        fd.append('version', $('#upd-version').value.trim());
        fd.append('summary', $('#upd-summary').value.trim());
        fd.append('changelog', $('#upd-changelog').value.trim());
        if ($('#upd-hassql').checked) fd.append('has_sql', '1');
        if ($('#upd-forced').checked) fd.append('forced', '1');
        fd.append('package', $('#upd-file').files[0]);
        try {
            const data = await postForm('updates', fd, (pct) => {
                setBtn(pct === null
                    ? '<span class="btn-spin"></span> Uploading…'
                    : `<span class="btn-spin"></span> Uploading… ${Math.round(pct * 100)}%`);
                if (pct === 1) setBtn('<span class="btn-spin"></span> Publishing…');
            });
            toast('Update ' + data.data.version + ' published');
            e.target.reset();
            loadUpdates();
        } catch (err) {
            toast('Publish failed: ' + err.message);
        } finally {
            btn.disabled = false;
            setBtn('Publish update');
        }
    });

    window.EZL.deleteUpdate = async (id) => {
        if (!await askConfirm('Delete this update package? Sites on older versions will no longer see it.', 'Delete')) return;
        try {
            await api('updates/' + id, { method: 'DELETE' });
            toast('Update deleted');
        } catch (e) { toast('Delete failed: ' + e.message); return; }
        loadUpdates();
    };

    /* ── server config ── */
    async function loadConfig() {
        const msg = $('#cfg-msg');
        try {
            const r = await api('config');
            msg.classList.add('hidden');
            const cur = r.data;
            $('#cfg-version').textContent = 'v' + (cur ? cur.version : 0);
            if (cur) {
                $('#cfg-payload').value = JSON.stringify(cur.payload, null, 2);
            }
            $('#cfg-history-table tbody').innerHTML = r.history.length
                ? r.history.map((h) =>
                    `<tr><td class="mono">v${h.version}</td><td>${esc(h.note || '—')}</td><td data-sort="${esc(h.published_at)}">${ago(h.published_at)}</td>` +
                    `<td class="row-actions"><button class="btn btn-ghost btn-sm cfg-view" data-id="${h.id}">Load</button></td></tr>`
                ).join('')
                : '<tr><td colspan="4" class="empty">No published configs.</td></tr>';
            $$('.cfg-view').forEach((b) => b.addEventListener('click', async () => {
                const v = await api('config/' + b.dataset.id);
                $('#cfg-payload').value = JSON.stringify(v.data.payload, null, 2);
                $('#cfg-note').value = 'revert to v' + v.data.version;
                toast('Loaded v' + v.data.version + ' into editor — publish to revert');
            }));
        } catch (err) {
            msg.textContent = 'Failed to load config: ' + err.message;
            msg.classList.remove('hidden');
        }
        try {
            const m = await api('maintenance');
            setTopStatus(m);
            $('#maint-state').textContent = m.maintenance
                ? 'ON — all public API calls return 503 SERVER_UNAVAILABLE instantly' + (m.since ? ' (since ' + m.since + ')' : '')
                : 'OFF — public API is serving normally';
            $('#maint-toggle').textContent = m.maintenance ? 'Disable maintenance mode' : 'Enable maintenance mode';
            $('#maint-toggle').className = 'btn ' + (m.maintenance ? 'btn-primary' : 'btn-danger');
        } catch (e2) {
            $('#maint-state').textContent = 'status unknown: ' + e2.message;
            setTopStatus(null);
        }
    }

    /* ── topbar status pill — fleet-wide API health at a glance ── */
    function setTopStatus(m) {
        const el = $('#top-status');
        if (!m) {
            el.innerHTML = '<span class="status-dot"></span>API status unknown';
            el.className = 'status-pill status-muted';
            el.title = 'Could not read maintenance/API state';
            return;
        }
        el.innerHTML = '<span class="status-dot"></span>' + (m.maintenance ? 'Maintenance mode' : 'API Online');
        el.className = 'status-pill ' + (m.maintenance ? 'status-bad' : 'status-ok');
        el.title = m.maintenance
            ? 'All public license calls return 503' + (m.since ? ' — since ' + m.since : '')
            : 'Public API is serving license calls normally';
    }

    async function loadTopStatus() {
        try {
            setTopStatus(await api('maintenance'));
        } catch { setTopStatus(null); }
    }

    $('#maint-toggle').addEventListener('click', async () => {
        const turningOn = $('#maint-toggle').textContent.indexOf('Enable') === 0;
        if (!await askConfirm(
            turningOn
                ? 'Enable maintenance mode? Every client license call will fail fast with 503 SERVER_UNAVAILABLE until you turn this off. Sites with valid licenses stay open on their grace window.'
                : 'Disable maintenance mode and resume normal license service?',
            turningOn ? 'Enable' : 'Disable'
        )) return;
        try {
            await api('maintenance', { method: 'PUT', body: { enabled: turningOn } });
            toast('Maintenance mode ' + (turningOn ? 'ON' : 'OFF'));
            loadConfig();
        } catch (e3) { toast('Toggle failed: ' + e3.message); }
    });

    $('#cfg-publish').addEventListener('click', async () => {
        const msg = $('#cfg-msg');
        msg.classList.add('hidden');
        let payload;
        try {
            payload = JSON.parse($('#cfg-payload').value);
        } catch (e) {
            msg.textContent = 'Invalid JSON: ' + e.message;
            msg.classList.remove('hidden');
            return;
        }
        try {
            const r = await api('config', { method: 'POST', body: { payload, note: $('#cfg-note').value } });
            toast('Published config v' + r.version);
            $('#cfg-note').value = '';
            loadConfig();
        } catch (err) {
            msg.textContent = err.message;
            msg.classList.remove('hidden');
        }
    });

    /* ── settings ── */
    async function loadSettingsData() {
        const r = await api('settings');
        SETTINGS = r.settings;
        SERVER_TZ = r.server_tz || 'UTC';
        return r;
    }

    const FALLBACK_TZ = ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London', 'Europe/Berlin', 'Asia/Tokyo', 'Australia/Sydney'];
    // US zones pin to the top of the list — they're the common case.
    const TZ_PINNED = ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'America/Adak'];
    let TZ_ZONES = null;

    function tzOrdered() {
        if (!TZ_ZONES) {
            TZ_ZONES = typeof Intl.supportedValuesOf === 'function'
                ? Intl.supportedValuesOf('timeZone')
                : FALLBACK_TZ;
        }
        const pinned = TZ_PINNED.filter((z) => TZ_ZONES.includes(z));
        return [...pinned, ...TZ_ZONES.filter((z) => !pinned.includes(z))];
    }

    /* ── timezone combobox (Select2-style, dependency-free) ── */
    const tzInput = $('#set-tz-filter');
    const tzHidden = $('#set-timezone');
    const tzList = $('#tz-list');
    const tzCombo = $('#tz-combo');
    const tzDefaultLabel = () => 'Server default (' + SERVER_TZ + ')';

    function tzRender(filter = '') {
        const f = filter.trim().toLowerCase();
        const items = [{ v: '', label: tzDefaultLabel() }]
            .concat(tzOrdered().filter((z) => !f || z.toLowerCase().includes(f)).map((z) => ({ v: z, label: z })));
        tzList.innerHTML = items.slice(0, 80).map((it) =>
            `<div class="combo-item${it.v === tzHidden.value ? ' active' : ''}" data-v="${esc(it.v)}" role="option">${esc(it.label)}</div>`).join('')
            || '<div class="combo-empty">No matching timezone</div>';
        tzList.classList.remove('hidden');
        tzCombo.classList.add('open');
    }
    const tzClose = () => {
        tzList.classList.add('hidden');
        tzCombo.classList.remove('open');
    };

    // Chevron toggles the list like a native select.
    tzCombo.querySelector('.combo-caret').addEventListener('mousedown', (e) => {
        e.preventDefault();
        if (tzList.classList.contains('hidden')) {
            tzInput.focus();
            tzRender('');
        } else {
            tzClose();
        }
    });

    function tzPick(v, label) {
        tzHidden.value = v;
        tzInput.value = label;
        tzClose();
        updateSettingsPreview();
    }

    tzInput.addEventListener('focus', () => { tzInput.select(); tzRender(tzInput.value === tzHidden.value || tzInput.value === tzDefaultLabel() ? '' : tzInput.value); });
    tzInput.addEventListener('input', () => tzRender(tzInput.value));
    tzInput.addEventListener('blur', () => {
        // Tabbed away — accept an exact typed match, else restore the label.
        const v = tzHidden.value;
        if (tzInput.value === v || (v === '' && tzInput.value === tzDefaultLabel())) return;
        const hit = tzZonesMatch(tzInput.value);
        if (hit) { tzPick(hit, hit); return; }
        tzInput.value = v || tzDefaultLabel();
    });
    const tzZonesMatch = (s) => tzOrdered().find((z) => z.toLowerCase() === s.trim().toLowerCase()) || null;

    tzList.addEventListener('mousedown', (e) => { // mousedown beats input blur
        const it = e.target.closest('.combo-item');
        if (!it) return;
        e.preventDefault();
        tzPick(it.dataset.v, it.textContent);
    });

    tzInput.addEventListener('keydown', (e) => {
        const items = $$('.combo-item', tzList);
        if (e.key === 'Escape') { tzClose(); tzInput.value = tzHidden.value || tzDefaultLabel(); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            const sel = tzList.querySelector('.combo-item.sel') || items[0];
            if (sel) tzPick(sel.dataset.v, sel.textContent);
            return;
        }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        if (tzList.classList.contains('hidden')) { tzRender(''); return; }
        let i = items.findIndex((el) => el.classList.contains('sel'));
        i = e.key === 'ArrowDown' ? Math.min(items.length - 1, i + 1) : Math.max(0, i - 1);
        items.forEach((el) => el.classList.remove('sel'));
        if (items[i]) { items[i].classList.add('sel'); items[i].scrollIntoView({ block: 'nearest' }); }
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('#tz-combo')) tzClose(); });

    async function loadSettingsView() {
        await loadSettingsData();
        tzHidden.value = SETTINGS.timezone;
        tzInput.value = SETTINGS.timezone || tzDefaultLabel();
        $('#set-server-tz').textContent = SERVER_TZ;
        $('#set-datefmt').value = SETTINGS.date_format;
        $('#set-timefmt').value = SETTINGS.time_format;
        updateSettingsPreview();
    }

    function updateSettingsPreview() {
        const s = {
            timezone: $('#set-timezone').value,
            date_format: $('#set-datefmt').value,
            time_format: $('#set-timefmt').value,
        };
        const now = new Date().toISOString().slice(0, 19).replace('T', ' '); // UTC, our ts shape
        $('#set-preview').textContent = fmtDate(now, s) + '  ' + fmtTime(now, s) + '  ·  ' + (s.timezone || SERVER_TZ);
    }
    ['set-timezone', 'set-datefmt', 'set-timefmt'].forEach((id) => {
        $('#' + id).addEventListener('change', updateSettingsPreview);
    });

    $('#settings-save').addEventListener('click', async () => {
        const msg = $('#settings-msg');
        msg.classList.add('hidden');
        try {
            const r = await api('settings', {
                method: 'PUT',
                body: {
                    timezone: $('#set-timezone').value,
                    date_format: $('#set-datefmt').value,
                    time_format: $('#set-timefmt').value,
                },
            });
            SETTINGS = r.settings;
            SERVER_TZ = r.server_tz || SERVER_TZ;
            toast('Settings saved');
            if (VIEWS[currentView]) VIEWS[currentView](); // re-render with new formats
        } catch (e) {
            msg.textContent = e.message;
            msg.className = 'alert';
            msg.classList.remove('hidden');
        }
    });

    /* ── license gate users ── */
    let usersLicense = null;

    async function loadLicenseUsers() {
        const r = await api('licenses/' + usersLicense + '/users');
        $('#users-table tbody').innerHTML = r.data.length
            ? r.data.map((u) => `<tr>
                <td class="mono">${esc(u.username)}</td>
                <td>${esc(u.name || '—')}</td>
                <td data-sort="${esc(u.created_at)}">${ago(u.created_at)}</td>
                <td><div class="row-actions"><button class="btn btn-danger btn-sm" onclick="EZL.deleteUser(${u.id})">Remove</button></div></td></tr>`).join('')
            : '<tr><td colspan="4" class="empty">No gate users for this license</td></tr>';
    }

    window.EZL.manageUsers = (licenseId) => {
        const l = licenses.find((x) => x.id === licenseId);
        usersLicense = licenseId;
        $('#users-modal-title').textContent = 'Gate users — ' + (l ? l.code : '#' + licenseId);
        $('#users-modal-error').classList.add('hidden');
        $('#users-modal').classList.remove('hidden');
        loadLicenseUsers();
    };

    window.EZL.deleteUser = async (userId) => {
        if (!await askConfirm('Remove this gate user? They will lose access on next login.', 'Remove')) return;
        try {
            await api('licenses/' + usersLicense + '/users/' + userId, { method: 'DELETE' });
            toast('User removed');
        } catch (e) { toast('Remove failed: ' + e.message); return; }
        loadLicenseUsers();
    };

    $('#lu-add').addEventListener('click', async () => {
        const err = $('#users-modal-error');
        err.classList.add('hidden');
        try {
            await api('licenses/' + usersLicense + '/users', {
                method: 'POST',
                body: {
                    username: $('#lu-user').value.trim(),
                    name: $('#lu-name').value.trim(),
                    password: $('#lu-pass').value,
                },
            });
            $('#lu-user').value = $('#lu-name').value = $('#lu-pass').value = '';
            toast('User added');
            loadLicenseUsers();
        } catch (e2) {
            err.textContent = e2.message;
            err.classList.remove('hidden');
        }
    });

    // Recovery codes are stored hashed — the only time a code is ever
    // visible is inside this modal, straight from the create/regenerate
    // response. Closing it means it's gone.
    function showRecoveryCode(code, extraNote) {
        $('#code-value').value = code;
        $('#code-note').textContent = (extraNote ? extraNote + ' ' : '') +
            'Shown once — the server stores only its hash. Save it somewhere safe; it arms a site\'s emergency gate override while this server is offline.';
        $('#code-modal').classList.remove('hidden');
    }
    $('#code-copy').addEventListener('click', async () => {
        await navigator.clipboard.writeText($('#code-value').value);
        $('#code-copy').textContent = 'Copied ✓';
        setTimeout(() => $('#code-copy').textContent = 'Copy', 1500);
    });

    window.EZL.regenRecovery = async (id) => {
        const p = products.find((x) => x.id === id);
        if (!p) return;
        if (!await askConfirm(`Mint a new recovery code for "${p.name}"? The old code stops working immediately (its digest rotates) — save the new one when it appears.`, 'Regenerate')) return;
        try {
            const r = await api('products/' + id + '/recovery-code', { method: 'POST' });
            showRecoveryCode(r.recovery_code);
        } catch (e) { toast('Failed: ' + e.message); return; }
        loadProducts();
    };

    window.EZL.deleteProduct = async (id) => {
        if (!await askConfirm('Delete this product and ALL its licenses?', 'Delete')) return;
        try {
            await api('products/' + id, { method: 'DELETE' });
            toast('Product deleted');
        } catch (e) { toast('Delete failed: ' + e.message); return; }
        loadProducts();
    };

    $('#new-license-btn').addEventListener('click', async () => {
        await loadProductOptions();
        editingLicense = null;
        $('#license-modal-title').textContent = 'New license';
        $('#lic-code').value = '';
        $('#lic-code').disabled = false;
        $('#lic-product').disabled = false;
        ['lic-client', 'lic-email', 'lic-notes', 'lic-features'].forEach((id) => ($('#' + id).value = ''));
        $('#lic-status').value = 'active';
        $('#lic-max').value = 1;
        $('#lic-expires').value = '';
        $('#license-modal').classList.remove('hidden');
    });

    $('#lic-gen').addEventListener('click', () => {
        const h = (n) => [...crypto.getRandomValues(new Uint8Array(n))].map((b) => b.toString(16).padStart(2, '0')).join('').toUpperCase();
        $('#lic-code').value = `${h(4)}-${h(2)}-${h(2)}-${h(2)}-${h(6)}`;
    });

    $('#lic-save').addEventListener('click', async () => {
        const err = $('#license-modal-error');
        err.classList.add('hidden');
        try {
            const payload = {
                code: $('#lic-code').value.trim(),
                product_id: $('#lic-product').value,
                client_name: $('#lic-client').value.trim(),
                client_email: $('#lic-email').value.trim(),
                status: $('#lic-status').value,
                max_activations: +$('#lic-max').value,
                expires_at: $('#lic-expires').value || null,
                notes: $('#lic-notes').value.trim(),
                features: $('#lic-features').value.split(',').map((f) => f.trim()).filter(Boolean),
            };
            if (editingLicense) {
                await api('licenses/' + editingLicense.id, { method: 'PUT', body: payload });
            } else {
                await api('licenses', { method: 'POST', body: payload });
            }
            $('#license-modal').classList.add('hidden');
            toast(editingLicense ? 'License updated' : 'License created');
            loadLicenses();
        } catch (e2) {
            err.textContent = e2.message;
            err.classList.remove('hidden');
        }
    });

    let searchTimer;
    $('#license-search').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadLicenses, 300);
    });

    /* ── activations ── */
    let actQTimer = null;
    $('#act-search').addEventListener('input', () => {
        clearTimeout(actQTimer);
        actQTimer = setTimeout(loadActivations, 300); // debounce typing
    });

    async function loadActivations() {
        const q = $('#act-search').value.trim();
        const r = await api('activations' + (q ? '?q=' + encodeURIComponent(q) : ''));
        $('#activations-table tbody').innerHTML = r.data.length
            ? r.data.map((a) => `<tr>
                <td class="mono">${esc(a.domain)}</td>
                <td class="mono">${esc(a.license_code)}</td>
                <td>${esc(a.client_name)}</td>
                <td>${esc(a.product_name)}</td>
                <td class="mono">${esc(a.ip || '—')}</td>
                <td>${badge(a.status || 'enabled')}</td>
                <td data-sort="${esc(a.activated_at)}">${ago(a.activated_at)}</td>
                <td data-sort="${esc(a.last_check_at)}">${ago(a.last_check_at)}</td>
                <td><div class="row-actions">
                    <span data-sllogin="${a.id}" data-domain="${esc(a.domain)}"></span>
                    <button class="btn" onclick="EZL.toggleActivation(${a.id}, '${a.status || 'enabled'}')">${(a.status || 'enabled') === 'enabled' ? 'Disable' : 'Enable'}</button>
                    <button class="btn btn-danger" onclick="EZL.deleteActivation(${a.id})">Revoke</button>
                </div></td></tr>`).join('')
            : '<tr><td colspan="9" class="empty">No activations yet</td></tr>';
        probeSiteLogins();
    }

    /** Reveal 'Log in' only for sites running EzIT Core >=1.3.0 — each
     *  row gets a signed probe; a 404 on the site's sso route (old
     *  plugin) or an unreachable site leaves the button hidden. */
    function probeSiteLogins() {
        $$('[data-sllogin]').forEach(async (slot) => {
            try {
                const r = await api('site-login-capable?activation=' + slot.dataset.sllogin);
                if (!r.data.capable) return;
                slot.innerHTML = `<button class="btn" onclick="EZL.openSiteLogin(${+slot.dataset.sllogin}, '${esc(slot.dataset.domain)}')">Log in</button>`;
            } catch { /* probe failed — stay hidden */ }
        });
    }

    /* ── client-site login (SSO) ── */
    let slActivation = 0;

    const slError = (msg) => {
        const el = $('#sitelogin-error');
        el.textContent = msg || '';
        el.classList.toggle('hidden', !msg);
    };

    window.EZL.openSiteLogin = (activationId, domain) => {
        slActivation = activationId;
        $('#sl-domain').textContent = domain;
        slError('');
        ['#sl-user', '#sl-pass', '#sl-p-user', '#sl-p-pass', '#sl-p-email'].forEach((id) => { $(id).value = ''; });
        $('#sl-remember').checked = false;
        $('#sl-p-remember').checked = false;
        $('#sitelogin-modal').classList.remove('hidden');
        slTab('users');
        loadSiteUsers();
        loadSiteLogins();
    };

    /** Modal tabs — Site users / Credentials / New account. */
    function slTab(name) {
        $$('#sitelogin-modal .sl-tab').forEach((t) => t.classList.toggle('active', t.dataset.sltab === name));
        $$('#sitelogin-modal .sl-pane').forEach((p) => p.classList.toggle('hidden', p.id !== 'sl-pane-' + name));
    }
    $$('#sitelogin-modal .sl-tab').forEach((t) => t.addEventListener('click', () => slTab(t.dataset.sltab)));

    /** Provision role picker — the site sends its registered roles
     *  (slug => label); older sso builds don't, so fall back to the
     *  union of roles the users are actually assigned. */
    function slFillRoles(roles, users) {
        let map = (roles && typeof roles === 'object' && !Array.isArray(roles)) ? roles : null;
        if (!map) {
            map = {};
            (users || []).forEach((u) => (u.roles || []).forEach((r) => { map[r] = r; }));
        }
        if (!map.administrator) map.administrator = 'Administrator';
        $('#sl-p-role').innerHTML = Object.entries(map)
            .map(([slug, label]) => `<option value="${esc(slug)}"${slug === 'administrator' ? ' selected' : ''}>${esc(label)}</option>`)
            .join('');
    }

    /** POST site-login → open the returned one-time URL in a new tab. */
    async function siteLogin(params, btn) {
        slError('');
        const prev = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="btn-spin"></span> Contacting site…';
        try {
            const r = await api('site-login', {
                method: 'POST',
                body: { activation_id: slActivation, ...params },
            });
            window.open(r.data.login_url, '_blank', 'noopener');
            if (params.remember) loadSiteLogins();
        } catch (e) {
            slError(e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = prev;
        }
    }

    /** Site's WP users — the "Log in as" picker. */
    async function loadSiteUsers() {
        const box = $('#sl-users');
        box.innerHTML = '<p class="hint">Loading…</p>';
        try {
            const r = await api('site-users?activation=' + slActivation);
            const users = r.data.users || r.data; // {users, roles} | [users] legacy
            slFillRoles(r.data.roles, users);
            box.innerHTML = users.length
                ? users.map((u) => `<div class="sl-row">
                    <span><strong>${esc(u.login)}</strong>${u.display && u.display !== u.login ? ` <small style="color:var(--muted)">${esc(u.display)}</small>` : ''}<br>
                    <small style="color:var(--muted)">${esc((u.roles || []).join(', ') || 'no role')}</small></span>
                    <button class="btn btn-sm" data-uid="${u.id}">Log in</button>
                </div>`).join('')
                : '<p class="hint">No users found.</p>';
            box.querySelectorAll('[data-uid]').forEach((b) =>
                b.addEventListener('click', () => siteLogin({ mode: 'user', user_id: +b.dataset.uid }, b)));
        } catch (e) {
            box.innerHTML = `<p class="hint">${esc(e.message)}</p>`;
        }
    }

    /** Saved (encrypted) credentials for this activation. */
    async function loadSiteLogins() {
        const box = $('#sl-saved');
        try {
            const r = await api('site-logins?activation=' + slActivation);
            box.innerHTML = r.data.length
                ? r.data.map((c) => `<div class="sl-row">
                    <span><strong>${esc(c.username)}</strong>${c.label ? ` <small style="color:var(--muted)">${esc(c.label)}</small>` : ''}<br>
                    <small class="mono" id="sl-pass-${c.id}" style="color:var(--muted)">••••••••</small></span>
                    <span class="row-actions">
                        <button class="btn btn-sm" data-slogin="${c.id}">Log in</button>
                        <button class="btn btn-ghost btn-sm" data-reveal="${c.id}">Reveal</button>
                        <button class="btn btn-danger btn-sm" data-sdel="${c.id}">Delete</button>
                    </span>
                </div>`).join('')
                : '<p class="hint">None saved for this site.</p>';

            box.querySelectorAll('[data-slogin]').forEach((b) =>
                b.addEventListener('click', () => siteLogin({ mode: 'credentials', credential_id: +b.dataset.slogin }, b)));
            box.querySelectorAll('[data-reveal]').forEach((b) =>
                b.addEventListener('click', async () => {
                    try {
                        const r = await api('site-logins/' + b.dataset.reveal + '/reveal', { method: 'POST' });
                        $('#sl-pass-' + b.dataset.reveal).textContent = r.data.password;
                    } catch (e) { slError(e.message); }
                }));
            box.querySelectorAll('[data-sdel]').forEach((b) =>
                b.addEventListener('click', async () => {
                    if (!await askConfirm('Forget this saved login? (The account on the site is untouched.)', 'Delete')) return;
                    try {
                        await api('site-logins/' + b.dataset.sdel, { method: 'DELETE' });
                        loadSiteLogins();
                    } catch (e) { slError(e.message); }
                }));
        } catch (e) {
            box.innerHTML = `<p class="hint">${esc(e.message)}</p>`;
        }
    }

    $('#sl-first-admin').addEventListener('click', (e) =>
        siteLogin({ mode: 'first_admin' }, e.currentTarget));
    $('#sl-users-reload').addEventListener('click', loadSiteUsers);
    $('#sl-cred-login').addEventListener('click', (e) =>
        siteLogin({
            mode: 'credentials',
            username: $('#sl-user').value.trim(),
            password: $('#sl-pass').value,
            remember: $('#sl-remember').checked ? 1 : 0,
        }, e.currentTarget));
    $('#sl-provision').addEventListener('click', (e) =>
        siteLogin({
            mode: 'provision',
            username: $('#sl-p-user').value.trim(),
            password: $('#sl-p-pass').value,
            email: $('#sl-p-email').value.trim(),
            role: $('#sl-p-role').value,
            remember: $('#sl-p-remember').checked ? 1 : 0,
        }, e.currentTarget));

    /* ── products ── */
    let editingProduct = null;
    let regenRequested = false;

    async function loadProducts() {
        const r = await api('products');
        products = r.data;
        $('#products-table tbody').innerHTML = products.length
            ? products.map((p) => `<tr>
                <td>${esc(p.name)}</td>
                <td class="mono">${esc(p.product_id)}</td>
                <td class="mono">${esc(p.api_key)}</td>
                <td class="mono" title="Offline emergency-override code — shown once when generated/regenerated; stored hashed on the server">${p.has_recovery_code ? '••••••••' : '—'}</td>
                <td>${esc(p.latest_version)}</td>
                <td>${badge(p.status || 'enabled')}</td>
                <td>${p.license_count}</td>
                <td><div class="row-actions">
                    <button class="btn" onclick="EZL.editProduct(${p.id})">Edit</button>
                    <button class="btn" onclick="EZL.regenRecovery(${p.id})" title="Mint a new emergency-override recovery code">Recovery</button>
                    <button class="btn" onclick="EZL.toggleProduct(${p.id})">${(p.status || 'enabled') === 'enabled' ? 'Disable' : 'Enable'}</button>
                    <button class="btn btn-danger" onclick="EZL.deleteProduct(${p.id})">Delete</button>
                </div></td></tr>`).join('')
            : '<tr><td colspan="8" class="empty">No products</td></tr>';
    }

    /* ── product wizard state: the dropped zip + its detected metadata ── */
    let pkgFile = null, pkgInfo = null, pkgMatch = null;

    function openProductModal(p) {
        editingProduct = p || null;
        regenRequested = false;
        pkgFile = pkgInfo = pkgMatch = null;
        $('#prod-file').value = '';
        $('#prod-drop').classList.remove('hidden');
        $('#prod-pkg').classList.add('hidden');
        $('#prod-update').classList.add('hidden');
        $('#prod-fields').classList.remove('hidden');
        $('#prod-publish-wrap').classList.remove('hidden');
        $('#prod-publish').checked = true;
        $('#prod-mkt-wrap').classList.add('hidden');
        $('#prod-mkt').checked = false;
        $('#prod-id').value = '';
        $('#prod-id').readOnly = false;
        ['#upd-m-version', '#upd-m-summary', '#upd-m-changelog'].forEach((id) => { $(id).value = ''; });
        $('#upd-m-hassql').checked = false;
        $('#upd-m-forced').checked = false;
        $('#upd-m-sqlhint').textContent = '';
        $('#product-modal-title').textContent = p ? 'Edit product' : 'New product';
        $('#prod-id').value = p ? p.product_id : '';
        $('#prod-name').value = p ? p.name : '';
        $('#prod-version').value = p ? p.latest_version : '1.0.0';
        $('#prod-status').value = p ? (p.status || 'enabled') : 'enabled';
        $('#prod-status-wrap').classList.toggle('hidden', !p);
        $('#prod-key-wrap').classList.toggle('hidden', !p);
        $('#prod-key').value = p ? p.api_key : '';
        $('#prod-key').readOnly = true;   // locked until Edit is clicked
        $('#prod-key-edit').textContent = 'Edit';
        $('#product-modal').classList.remove('hidden');
    }

    /** Drop a zip → POST package-inspect → morph the modal. */
    async function inspectPackage(f) {
        const err = $('#product-modal-error');
        err.classList.add('hidden');
        const dz = $('#prod-drop');
        dz.classList.add('busy');
        try {
            const fd = new FormData();
            fd.append('package', f);
            const r = await postForm('package-inspect', fd, null);
            pkgFile = f;
            pkgInfo = r.data;
            applyPkgInfo();
        } catch (e) {
            err.textContent = e.message;
            err.classList.remove('hidden');
        } finally {
            dz.classList.remove('busy');
        }
    }

    /** Decide create vs publish-update from what the zip told us. */
    function applyPkgInfo() {
        const i = pkgInfo;
        if (!i) return;
        if (editingProduct) {
            // Dropped a zip while editing — only allowed for THIS product.
            const same = i.product_id ? i.product_id === editingProduct.product_id : true;
            if (!same) {
                pkgInfo = pkgFile = null;
                $('#product-modal-error').textContent =
                    `Package belongs to product ${i.product_id} — this is ${editingProduct.product_id}.`;
                $('#product-modal-error').classList.remove('hidden');
                return;
            }
            pkgMatch = editingProduct;
        } else {
            pkgMatch = i.match || null;
        }
        renderPkgCard();

        if (pkgMatch) {
            // Publish-update mode: product fields hide, version/summary prefill.
            $('#product-modal-title').textContent = `Publish ${pkgMatch.name} ${i.version}`;
            $('#prod-fields').classList.add('hidden');
            $('#prod-update').classList.remove('hidden');
            $('#prod-publish-wrap').classList.add('hidden');
            $('#prod-publish').checked = true;
            $('#prod-mkt-wrap').classList.add('hidden');
        } else {
            // New-product mode: everything prefills, update + listing optional.
            $('#prod-fields').classList.remove('hidden');
            $('#prod-update').classList.remove('hidden');
            $('#prod-publish-wrap').classList.remove('hidden');
            $('#prod-publish').checked = true;
            $('#prod-mkt-wrap').classList.remove('hidden');
            $('#prod-name').value = i.name || '';
            $('#prod-version').value = i.version || '1.0.0';
            $('#prod-id').value = i.product_id || '';
            $('#prod-id').readOnly = !!i.product_id;  // baked into the zip — lock it
        }
        $('#upd-m-version').value = i.version || '';
        $('#upd-m-summary').value = i.summary || '';
        $('#upd-m-changelog').value = i.changelog || '';
        $('#upd-m-hassql').checked = !!i.has_sql;
        $('#upd-m-sqlhint').textContent = i.has_sql ? '(auto-detected)' : '(none found — tick to force)';
    }

    function renderPkgCard() {
        const card = $('#prod-pkg');
        if (!pkgInfo) { card.classList.add('hidden'); return; }
        const i = pkgInfo;
        card.innerHTML = `
            ${i.screenshot_url ? `<img class="pkg-thumb" src="${esc(i.screenshot_url)}" alt="">` : ''}
            <div class="pkg-meta">
                <div><strong>${esc(i.name || pkgFile.name)}</strong> <span class="badge">${esc(i.kind)}</span></div>
                <div class="pkg-sub">${esc(i.slug || '')} · v${esc(i.version || '?')}${i.author ? ' · ' + esc(i.author) : ''}</div>
                ${pkgMatch ? `<div class="pkg-hit">Matches <strong>${esc(pkgMatch.name)}</strong> (${esc(pkgMatch.product_id)}, latest ${esc(pkgMatch.latest_version)}) — this saves as a new version.</div>`
                          : '<div class="pkg-new">New product — will be created on save.</div>'}
                <button type="button" class="btn btn-ghost btn-sm" id="pkg-clear">Remove package</button>
            </div>`;
        card.classList.remove('hidden');
        $('#prod-drop').classList.add('hidden');  // card replaces the dropzone
        $('#pkg-clear').addEventListener('click', () => {
            pkgFile = pkgInfo = pkgMatch = null;
            card.classList.add('hidden');
            $('#prod-drop').classList.remove('hidden');
            $('#prod-update').classList.add('hidden');
            $('#prod-mkt-wrap').classList.add('hidden');
            if (!editingProduct) {
                $('#prod-fields').classList.remove('hidden');
                $('#prod-id').readOnly = false;
            }
            $('#product-modal-title').textContent = editingProduct ? 'Edit product' : 'New product';
        });
    }

    // Drop zone — click opens the picker; drag-over highlights.
    const prodDrop = $('#prod-drop');
    prodDrop.addEventListener('click', () => $('#prod-file').click());
    ['dragover', 'dragenter'].forEach((ev) => prodDrop.addEventListener(ev, (e) => {
        e.preventDefault(); prodDrop.classList.add('over');
    }));
    ['dragleave', 'drop'].forEach((ev) => prodDrop.addEventListener(ev, (e) => {
        e.preventDefault(); prodDrop.classList.remove('over');
    }));
    prodDrop.addEventListener('drop', (e) => {
        const f = e.dataTransfer.files[0];
        if (f) inspectPackage(f);
    });
    $('#prod-file').addEventListener('change', (e) => {
        const f = e.target.files[0];
        if (f) inspectPackage(f);
    });

    window.EZL.editProduct = (id) => openProductModal(products.find((x) => x.id === id));
    window.EZL.toggleProduct = async (id) => {
        const p = products.find((x) => x.id === id);
        if (!p) return;
        const next = (p.status || 'enabled') === 'enabled' ? 'disabled' : 'enabled';
        if (!await askConfirm(`${next === 'disabled' ? 'Disable' : 'Enable'} "${p.name}"? Disabling blocks ALL license checks for its sites.`, next === 'disabled' ? 'Disable' : 'Enable')) return;
        try {
            await api('products/' + id, { method: 'PUT', body: { product_id: p.product_id, name: p.name, latest_version: p.latest_version, status: next } });
            toast('Product ' + next);
        } catch (e) { toast('Update failed: ' + e.message); return; }
        loadProducts();
    };

    $('#new-product-btn').addEventListener('click', () => openProductModal(null));

    // Locked by default — Edit unlocks for a manual key, Lock re-locks.
    $('#prod-key-edit').addEventListener('click', () => {
        const input = $('#prod-key');
        input.readOnly = !input.readOnly;
        $('#prod-key-edit').textContent = input.readOnly ? 'Edit' : 'Lock';
        if (!input.readOnly) { regenRequested = false; input.focus(); input.select(); }
    });

    $('#prod-regen').addEventListener('click', async () => {
        if (await askConfirm('Regenerate the API key? Every site using it must update core.json apiKey.', 'Regenerate')) {
            regenRequested = true;
            $('#prod-key').readOnly = true;
            $('#prod-key-edit').textContent = 'Edit';
            $('#prod-key').value = '⟳ will be regenerated on save';
        }
    });

    /** Publish the attached package as an update row for product rowId. */
    async function publishPackage(rowId) {
        const fd = new FormData();
        fd.append('product_id', rowId);
        fd.append('version', $('#upd-m-version').value.trim());
        fd.append('summary', $('#upd-m-summary').value.trim());
        fd.append('changelog', $('#upd-m-changelog').value.trim());
        if ($('#upd-m-hassql').checked) fd.append('has_sql', '1');
        if ($('#upd-m-forced').checked) fd.append('forced', '1');
        fd.append('package', pkgFile);
        return postForm('updates', fd, null);
    }

    $('#prod-save').addEventListener('click', async () => {
        const err = $('#product-modal-error');
        err.classList.add('hidden');
        const saveBtn = $('#prod-save');
        saveBtn.disabled = true;
        const done = () => { saveBtn.disabled = false; };

        try {
            // A dropped package that matched a product → pure publish-update.
            if (pkgFile && pkgMatch) {
                await publishPackage(pkgMatch.id);
                $('#product-modal').classList.add('hidden');
                toast(`Published ${pkgMatch.name} ${$('#upd-m-version').value.trim()}`);
                loadProducts();
                loadUpdates();
                done();
                return;
            }

            const body = {
                product_id: $('#prod-id').value.trim(),
                name: $('#prod-name').value.trim(),
                latest_version: $('#prod-version').value.trim(),
                status: $('#prod-status').value,
            };
            let r;
            if (editingProduct) {
                if (regenRequested) {
                    body.regenerate_key = true;
                } else {
                    // Manual key edit — only send when it actually changed.
                    const key = $('#prod-key').value.trim();
                    if (key !== editingProduct.api_key) body.api_key = key;
                }
                r = await api('products/' + editingProduct.id, { method: 'PUT', body });
            } else {
                r = await api('products', { method: 'POST', body });
            }
            const newId   = r.data && r.data.id;
            const newKey  = r.api_key || (r.data && r.data.api_key);
            const newCode = r.recovery_code || (r.data && r.data.recovery_code);

            // Attached zip on a NEW product → optionally publish as an update.
            if (pkgFile && !editingProduct && $('#prod-publish').checked) {
                try {
                    await publishPackage(newId);
                } catch (e3) {
                    toast('Product saved, but update publish failed: ' + e3.message);
                }
            }

            $('#product-modal').classList.add('hidden');
            toast(editingProduct
                ? 'Product updated' + (newKey ? ' — new key: ' + newKey : '')
                : 'Product created — copy its API key into core.json');
            if (newCode) showRecoveryCode(newCode, newKey ? 'The API key rotated — the old recovery code is dead too.' : null);
            loadProducts();
            loadUpdates();

            // Marketplace opt-in → continue straight into a prefilled listing.
            if (pkgFile && !editingProduct && $('#prod-mkt').checked && pkgInfo) {
                const pk = { ...pkgInfo, product_id: newId };
                // loadProducts repopulates `products` — wait for it before opening.
                const iv = setInterval(() => {
                    if (products.some((p) => p.id === newId)) {
                        clearInterval(iv);
                        openCatalogModal(null, pk);
                    }
                }, 150);
                setTimeout(() => clearInterval(iv), 5000);
            }
        } catch (e2) {
            err.textContent = e2.message;
            err.classList.remove('hidden');
        }
        done();
    });

    /* ── marketplace catalog (Products → Marketplace tab) ── */
    let catalogItems = [];
    let licenseOptions = [];
    let catScope = [];
    let editingCatalog = null;

    async function loadCatalog() {
        try {
            const r = await api('catalog');
            catalogItems = r.data;
            $('#cat-category').innerHTML = r.categories.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
            $('#cat-type').innerHTML = r.types.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('');
            licenseOptions = r.licenses || [];
        } catch (e) { toast('Marketplace load failed: ' + e.message); return; }
        $('#catalog-table tbody').innerHTML = catalogItems.length
            ? catalogItems.map((c) => `<tr>
                <td>${esc(c.product_name)}</td>
                <td class="mono">${esc(c.slug)}</td>
                <td>${esc(c.type || 'product')}</td>
                <td>${esc(c.category)}</td>
                <td class="mono">${esc(c.latest_version || '—')}</td>
                <td>${c.price !== null ? '$' + Number(c.price).toFixed(2) : '—'}</td>
                <td>${badge(c.is_free ? 'free' : 'paid')}</td>
                <td>${badge(c.published ? 'published' : 'draft')}
                    ${(() => { try { const s = JSON.parse(c.license_scope || '[]');
                        return s.length ? badge('scoped') : ''; } catch { return ''; } })()}</td>
                <td><div class="row-actions">
                    <button class="btn" onclick="EZL.editCatalog(${c.id})">Edit</button>
                    <button class="btn btn-danger" onclick="EZL.deleteCatalog(${c.id})">Delete</button>
                </div></td></tr>`).join('')
            : '<tr><td colspan="9" class="hint" style="padding:1rem">No marketplace listings yet.</td></tr>';
    }

    /** item = existing listing to edit; prefill = package-inspect data for a new one. */
    function openCatalogModal(item, prefill) {
        editingCatalog = item || null;
        $('#catalog-modal-title').textContent = item ? 'Edit listing' : 'New listing';
        $('#catalog-modal-error').classList.add('hidden');
        const selId = item ? item.product_id : (prefill ? prefill.product_id : null);
        $('#cat-product').innerHTML = products.map((p) =>
            `<option value="${p.id}"${selId === p.id ? ' selected' : ''}>${esc(p.name)} (${esc(p.product_id)})</option>`).join('');
        $('#cat-slug').value = item ? item.slug : (prefill ? (prefill.slug || '') : '');
        $('#cat-type').value = (item && item.type) || (prefill && prefill.kind) || 'product';
        $('#cat-category').value = item ? item.category : 'generic';
        $('#cat-price').value = item && item.price !== null ? item.price : '';
        $('#cat-thumb').value = (item && item.thumb) || (prefill && prefill.screenshot_url) || '';
        try { $('#cat-screens').value = item && item.screenshots ? JSON.parse(item.screenshots).join(', ') : ''; } catch { $('#cat-screens').value = ''; }
        $('#cat-excerpt').value = (item && item.excerpt) || (prefill && prefill.excerpt) || '';
        $('#cat-body').value = (item && item.body) || (prefill && prefill.description) || '';
        $('#cat-free').checked = !!(item && +item.is_free);
        $('#cat-published').checked = item ? !!(item.published) : !!prefill;
        try { catScope = item && item.license_scope ? JSON.parse(item.license_scope) : []; }
        catch { catScope = []; }
        renderScopeChips();
        $('#cat-thumb-file').value = ''; $('#cat-screens-file').value = '';
        $('#catalog-modal').classList.remove('hidden');
    }

    /* ── license scope: chips in the modal + nested searchable picker ── */

    function renderScopeChips() {
        const box = $('#cat-scope-chips');
        if (!catScope.length) {
            box.innerHTML = '<span class="hint" style="align-self:center">Public — no restrictions</span>';
            return;
        }
        box.innerHTML = catScope.map((code) => {
            const l = licenseOptions.find((x) => x.code === code);
            const label = l && l.client_name ? `${esc(code)} <small>· ${esc(l.client_name)}</small>` : esc(code);
            return `<span class="scope-chip">${label}<button type="button" class="scope-x" data-code="${esc(code)}" title="Remove">&times;</button></span>`;
        }).join('');
    }

    $('#cat-scope-chips').addEventListener('click', (e) => {
        const x = e.target.closest('.scope-x');
        if (!x) return;
        catScope = catScope.filter((c) => c !== x.dataset.code);
        renderScopeChips();
    });

    function renderScopeList(q) {
        q = (q || '').toLowerCase();
        $('#scope-list').innerHTML = licenseOptions.length
            ? licenseOptions
                .filter((l) => !q || (l.code + ' ' + (l.client_name || '')).toLowerCase().includes(q))
                .map((l) => `<label class="scope-row">
                    <input type="checkbox" value="${esc(l.code)}"${catScope.includes(l.code) ? ' checked' : ''}>
                    <span class="mono">${esc(l.code)}</span><small>${esc(l.client_name || '')}</small>
                </label>`).join('')
            : '<div class="hint" style="padding:1rem">No licenses yet — create one under Licenses.</div>';
    }

    $('#cat-scope-open').addEventListener('click', () => {
        $('#scope-search').value = '';
        renderScopeList('');
        $('#scope-modal').classList.remove('hidden');
    });
    $('#scope-search').addEventListener('input', (e) => renderScopeList(e.target.value));
    $('#scope-cancel').addEventListener('click', () => $('#scope-modal').classList.add('hidden'));
    $('#scope-apply').addEventListener('click', () => {
        catScope = [...$('#scope-list').querySelectorAll('input:checked')].map((c) => c.value);
        renderScopeChips();
        $('#scope-modal').classList.add('hidden');
    });

    /* Ghost buttons trigger the hidden native pickers. */
    $$('[data-upload]').forEach((b) => b.addEventListener('click', () => $('#' + b.dataset.upload).click()));

    /** Upload one catalog image → public URL stored in the field. */
    async function uploadCatImage(file) {
        const fd = new FormData();
        fd.append('image', file);
        const data = await postForm('catalog-upload', fd, null);
        return data.data.url;
    }

    $('#cat-thumb-file').addEventListener('change', async (e) => {
        const f = e.target.files[0];
        if (!f) return;
        try { $('#cat-thumb').value = await uploadCatImage(f); toast('Thumbnail uploaded'); }
        catch (err) { toast('Upload failed: ' + err.message); }
    });

    $('#cat-screens-file').addEventListener('change', async (e) => {
        for (const f of e.target.files) {
            try {
                const url = await uploadCatImage(f);
                const cur = $('#cat-screens').value.trim();
                $('#cat-screens').value = cur ? cur + ', ' + url : url;
            } catch (err) { toast('Upload failed (' + f.name + '): ' + err.message); }
        }
        toast('Screenshots uploaded');
        e.target.value = '';
    });

    $('#new-catalog-btn').addEventListener('click', () => openCatalogModal(null));
    window.EZL.editCatalog = (id) => openCatalogModal(catalogItems.find((c) => c.id === id));
    window.EZL.deleteCatalog = async (id) => {
        const c = catalogItems.find((x) => x.id === id);
        if (!await askConfirm(`Delete the listing "${c ? c.slug : id}"? The product itself is untouched.`, 'Delete')) return;
        try { await api('catalog/' + id, { method: 'DELETE' }); toast('Listing deleted'); }
        catch (e) { toast('Delete failed: ' + e.message); return; }
        loadCatalog();
    };

    $('#cat-save').addEventListener('click', async () => {
        const err = $('#catalog-modal-error');
        err.classList.add('hidden');
        const payload = {
            product_id:  +$('#cat-product').value,
            slug:        $('#cat-slug').value.trim(),
            type:        $('#cat-type').value,
            category:    $('#cat-category').value,
            price:       $('#cat-price').value,
            thumb:       $('#cat-thumb').value.trim(),
            screenshots: $('#cat-screens').value,
            excerpt:     $('#cat-excerpt').value.trim(),
            body:        $('#cat-body').value.trim(),
            is_free:     $('#cat-free').checked,
            published:   $('#cat-published').checked,
            license_scope: catScope,
        };
        try {
            if (editingCatalog) {
                await api('catalog/' + editingCatalog.id, { method: 'PUT', body: payload });
            } else {
                await api('catalog', { method: 'POST', body: payload });
            }
            $('#catalog-modal').classList.add('hidden');
            toast(editingCatalog ? 'Listing updated' : 'Listing created');
            loadCatalog();
        } catch (e) {
            err.textContent = e.message;
            err.classList.remove('hidden');
        }
    });

    /* ── logs ── */
    let logQTimer = null;
    let logActionsLoaded = false;

    function logQuery() {
        const p = new URLSearchParams({ page: logPage, per: $('#logs-per').value });
        if (pendingLogFocus) p.set('focus', pendingLogFocus);
        const q = $('#logs-q').value.trim();
        if (q) p.set('q', q);
        if ($('#logs-action').value) p.set('action', $('#logs-action').value);
        if ($('#logs-result').value !== '') p.set('result', $('#logs-result').value);
        return p;
    }

    async function loadLogs(scrollTop = false) {
        try {
            const r = await api('logs?' + logQuery());
            logPage = r.page;
            logRows = r.data;
            $('#logs-table tbody').innerHTML = r.data.map((l, i) => `<tr data-i="${i}" data-id="${l.id}">
                <td class="mono" data-sort="${esc(l.created_at)}">${fmtDate(l.created_at)}</td>
                <td class="mono" data-sort="${esc(l.created_at)}" title="${ago(l.created_at)}">${fmtTime(l.created_at)}</td>
                <td>${esc(l.action)}</td>
                <td class="mono">${esc(l.license_code || '—')}</td>
                <td class="mono">${esc(l.domain || '—')}</td>
                <td class="mono">${esc(l.ip || '—')}</td>
                <td>${okBadge(!!l.success)}</td>
                <td>${esc(l.detail || '')}</td></tr>`).join('')
                || '<tr><td colspan="8" class="empty">No activity matches these filters.</td></tr>';

            // Dashboard deep-link: flash + scroll to the focused event.
            if (r.focused) {
                pendingLogFocus = 0;
                const tr = $(`#logs-table tbody tr[data-id="${r.focused}"]`);
                if (tr) {
                    tr.classList.add('row-focus');
                    tr.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            }

            $('#logs-info').textContent = r.total
                ? `${r.total.toLocaleString()} entr${r.total === 1 ? 'y' : 'ies'}`
                : 'No entries';
            renderLogPager(r.page, r.pages);

            if (!logActionsLoaded && r.actions.length) {
                logActionsLoaded = true;
                const sel = $('#logs-action');
                r.actions.forEach((a) => {
                    const o = document.createElement('option');
                    o.value = o.textContent = a;
                    sel.appendChild(o);
                });
            }

            // Pager clicks return the admin to the top of the list — the
            // first row, not wherever they were mid-scroll.
            if (scrollTop) {
                $('#view-logs').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        } catch (e) {
            $('#logs-info').textContent = 'Load failed: ' + e.message;
        }
        loadLogRules();
    }

    // Windowed page list, Google-style: 1 … p-2 p-1 [p] p+1 p+2 … N.
    // Each gap is {lo,hi} — clicking it opens a dropdown of that range.
    function pagerItems(page, pages) {
        if (pages <= 7) return Array.from({ length: pages }, (_, i) => i + 1);
        const lo = Math.max(2, page - 2), hi = Math.min(pages - 1, page + 2);
        const items = [1];
        if (lo > 2) items.push({ lo: 2, hi: lo - 1 });
        for (let i = lo; i <= hi; i++) items.push(i);
        if (hi < pages - 1) items.push({ lo: hi + 1, hi: pages - 1 });
        items.push(pages);
        return items;
    }

    function renderLogPager(page, pages) {
        pages = Math.max(1, pages);
        // Left: jump-select "Page [n] of N"
        $('#logs-page-sel').innerHTML = Array.from({ length: pages }, (_, i) =>
            `<option value="${i + 1}"${i + 1 === page ? ' selected' : ''}>${i + 1}</option>`).join('');
        $('#logs-pages-n').textContent = pages;
        // ‹ 1 … numbers … N › — mirrored top and bottom of the table
        const btns =
            `<button class="pg" data-pg="${page - 1}"${page <= 1 ? ' disabled' : ''} aria-label="Previous page">‹</button>` +
            pagerItems(page, pages).map((it) => typeof it === 'object'
                ? `<button class="pg pg-gap" data-lo="${it.lo}" data-hi="${it.hi}" title="Jump to a page in this range">…</button>`
                : `<button class="pg${it === page ? ' active' : ''}" data-pg="${it}">${it}</button>`).join('') +
            `<button class="pg" data-pg="${page + 1}"${page >= pages ? ' disabled' : ''} aria-label="Next page">›</button>`;
        $('#logs-pager').innerHTML = btns;
        $('#logs-pager-top').innerHTML = btns;
    }

    /* ── page-gap dropdown — click a "…" to pick a page in that range ── */
    const pgDrop = document.createElement('div');
    pgDrop.id = 'pg-drop';
    pgDrop.className = 'pg-drop hidden';
    document.body.appendChild(pgDrop);

    function hidePageDrop() { pgDrop.classList.add('hidden'); }

    function showPageDrop(btn) {
        const lo = +btn.dataset.lo, hi = +btn.dataset.hi;
        pgDrop.innerHTML = '';
        for (let p = lo; p <= hi; p++) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'pg-drop-item' + (p === logPage ? ' active' : '');
            b.textContent = p;
            b.addEventListener('click', () => { hidePageDrop(); goLogPage(p); });
            pgDrop.appendChild(b);
        }
        pgDrop.classList.remove('hidden');
        const r = btn.getBoundingClientRect();
        let left = r.left + r.width / 2 - pgDrop.offsetWidth / 2;
        left = Math.max(8, Math.min(left, innerWidth - pgDrop.offsetWidth - 8));
        const top = (r.top - pgDrop.offsetHeight - 6) >= 8 ? r.top - pgDrop.offsetHeight - 6 : r.bottom + 6;
        pgDrop.style.left = left + 'px';
        pgDrop.style.top = top + 'px';
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#pg-drop') && !e.target.closest('.pg-gap')) hidePageDrop();
    });

    function goLogPage(p) {
        logPage = p;
        loadLogs(true);
    }

    /* ── log retention rules ── */
    async function loadLogRules() {
        let d;
        try {
            d = (await api('log-rules')).data;
        } catch (e) {
            $('#rules-note').textContent = 'Rules failed to load: ' + e.message;
            return;
        }
        const counts = d.counts || {};
        const totalRows = d.total || 0;

        // Action select — known actions + the '*' catch-all.
        const sel = $('#rule-action');
        const opts = d.actions.includes('*') ? d.actions : ['*', ...d.actions];
        sel.innerHTML = opts.map((a) => `<option value="${a}">${a === '*' ? '* — everything else (catch-all)' : a}</option>`).join('');

        $('#rules-table tbody').innerHTML = d.rules.length
            ? d.rules.map((r) => `<tr>
                <td class="mono">${esc(r.action)}${r.action === '*' ? ' <small style="color:var(--muted)">(catch-all)</small>' : ''}</td>
                <td>${+r.retain_days === 0 ? '<span class="badge badge-ok">forever</span>' : r.retain_days + ' days'}</td>
                <td>${+r.keep_last > 0 ? 'last ' + r.keep_last + ' / site' : '—'}</td>
                <td><input type="checkbox" ${+r.enabled ? 'checked' : ''} onchange="EZL.toggleRule(${r.id}, this.checked)" style="width:auto"></td>
                <td>${r.action === '*' ? totalRows : (counts[r.action] || 0)}</td>
                <td><div class="row-actions"><button class="btn btn-danger" onclick="EZL.deleteRule(${r.id})">Delete</button></div></td>
            </tr>`).join('')
            : '<tr><td colspan="6" class="empty">No rules — the log grows forever</td></tr>';

        $('#rules-note').textContent = `${totalRows.toLocaleString()} rows total · next automatic purge within 24h`;
    }

    window.EZL.toggleRule = async (id, on) => {
        try {
            await api('log-rules/' + id, { method: 'PUT', body: { enabled: on ? 1 : 0 } });
            toast('Rule ' + (on ? 'enabled' : 'paused'));
        } catch (e) { toast('Update failed: ' + e.message); }
        loadLogRules();
    };
    window.EZL.deleteRule = async (id) => {
        if (!await askConfirm('Delete this retention rule? Rows it covered fall to the catch-all (or keep forever if none).', 'Delete')) return;
        try {
            await api('log-rules/' + id, { method: 'DELETE' });
            toast('Rule deleted');
        } catch (e) { toast('Delete failed: ' + e.message); return; }
        loadLogRules();
    };

    $('#rule-forever').addEventListener('change', (e) => { $('#rule-days').disabled = e.target.checked; });
    $('#rule-add').addEventListener('click', async () => {
        try {
            await api('log-rules', {
                method: 'POST',
                body: {
                    action: $('#rule-action').value,
                    retain_days: $('#rule-forever').checked ? 0 : Math.max(0, parseInt($('#rule-days').value || '0', 10)),
                    keep_last: Math.max(0, parseInt($('#rule-keep').value || '0', 10)),
                    enabled: 1,
                },
            });
            toast('Rule added');
        } catch (e) { toast('Add failed: ' + e.message); return; }
        loadLogRules();
    });

    $('#purge-now').addEventListener('click', async () => {
        try {
            const r = await api('logs/purge', { method: 'POST' });
            toast(r.total ? 'Purged ' + r.total + ' rows' : 'Nothing to purge');
        } catch (e) { toast('Purge failed: ' + e.message); return; }
        loadLogs();
    });

    const resetLogs = () => { logPage = 1; loadLogs(); };
    const onPagerClick = (e) => {
        const gap = e.target.closest('.pg-gap');
        if (gap) {
            if (gap.classList.contains('open')) { hidePageDrop(); gap.classList.remove('open'); }
            else { $$('.pg-gap.open').forEach((b) => b.classList.remove('open')); gap.classList.add('open'); showPageDrop(gap); }
            return;
        }
        const b = e.target.closest('.pg');
        if (!b || b.disabled) return;
        hidePageDrop();
        goLogPage(+b.dataset.pg);
    };
    $('#logs-pager').addEventListener('click', onPagerClick);
    $('#logs-pager-top').addEventListener('click', onPagerClick);
    $('#logs-page-sel').addEventListener('change', (e) => goLogPage(+e.target.value));
    $('#logs-action').addEventListener('change', resetLogs);
    $('#logs-result').addEventListener('change', resetLogs);
    $('#logs-per').addEventListener('change', resetLogs);
    $('#logs-q').addEventListener('input', () => {
        clearTimeout(logQTimer);
        logQTimer = setTimeout(resetLogs, 300); // debounce typing
    });

    /* ── log row context menu — right-click to filter by that row ── */
    const ctxMenu = document.createElement('div');
    ctxMenu.id = 'log-ctx';
    ctxMenu.className = 'ctx-menu hidden';
    document.body.appendChild(ctxMenu);
    const hideCtx = () => ctxMenu.classList.add('hidden');
    document.addEventListener('click', hideCtx);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideCtx(); });
    window.addEventListener('blur', hideCtx);
    window.addEventListener('scroll', hideCtx, true);
    // Right-click anywhere outside the log table closes an open menu;
    // inside the table the row handler repositions it instead.
    document.addEventListener('contextmenu', (e) => {
        if (!e.target.closest('#logs-table') && !e.target.closest('#log-ctx')) hideCtx();
    });

    $('#logs-table tbody').addEventListener('contextmenu', (e) => {
        const tr = e.target.closest('tr[data-i]');
        const row = tr ? logRows[+tr.dataset.i] : null;
        if (!row) return; // 'No entries' row — leave the native menu alone
        e.preventDefault();

        // Each value becomes a filter action; empty cells are skipped.
        const items = [];
        if (row.domain) items.push(['q', row.domain, 'Filter domain: ' + row.domain]);
        if (row.ip) items.push(['q', row.ip, 'Filter IP: ' + row.ip]);
        if (row.license_code) items.push(['q', row.license_code, 'Filter license: ' + row.license_code]);
        if (row.action) items.push(['action', row.action, 'Filter action: ' + row.action]);
        if (!items.length) return;

        ctxMenu.innerHTML = items.map(([k, v, label]) =>
            `<button type="button" class="ctx-item" data-k="${esc(k)}" data-v="${esc(v)}">${esc(label)}</button>`).join('');
        ctxMenu.classList.remove('hidden');
        // Clamp inside the viewport so the menu never opens off-screen.
        ctxMenu.style.left = Math.min(e.clientX, innerWidth - ctxMenu.offsetWidth - 8) + 'px';
        ctxMenu.style.top = Math.min(e.clientY, innerHeight - ctxMenu.offsetHeight - 8) + 'px';
    });

    ctxMenu.addEventListener('click', (e) => {
        const btn = e.target.closest('.ctx-item');
        if (!btn) return;
        if (btn.dataset.k === 'action') {
            const sel = $('#logs-action');
            // Actions populate lazily — add the option if it isn't there yet.
            if (![...sel.options].some((o) => o.value === btn.dataset.v)) {
                const o = document.createElement('option');
                o.value = o.textContent = btn.dataset.v;
                sel.appendChild(o);
            }
            sel.value = btn.dataset.v;
        } else {
            $('#logs-q').value = btn.dataset.v;
        }
        hideCtx();
        resetLogs();
    });

    /* ── account ── */
    $('#acc-save').addEventListener('click', async () => {
        const msg = $('#account-msg');
        try {
            await api('account', {
                method: 'PUT',
                body: { name: $('#acc-name').value.trim(), password: $('#acc-password').value },
            });
            msg.textContent = 'Saved.';
            msg.className = 'alert ok';
            msg.classList.remove('hidden');
        } catch (e2) {
            msg.textContent = e2.message;
            msg.className = 'alert';
            msg.classList.remove('hidden');
        }
    });

    /* ── modal close ── */
    $$('.modal').forEach((m) => {
        m.addEventListener('click', (e) => {
            if (e.target === m || e.target.closest('[data-close]')) m.classList.add('hidden');
        });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { $$('.modal').forEach((m) => m.classList.add('hidden')); navOpen(false); }
    });

    init();
})();
