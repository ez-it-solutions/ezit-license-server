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
        return d.toLocaleDateString();
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
    function showApp() {
        $('#login-screen').classList.add('hidden');
        $('#app').classList.remove('hidden');
        // Legacy #view URLs → clean paths
        if (location.hash.length > 1) {
            const legacy = location.hash.slice(1);
            history.replaceState({}, '', VIEWS[legacy] ? '/' + legacy : '/');
        }
        navigate(location.pathname.slice(1) || 'dashboard');
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

    /* ── navigation ── */
    $$('.nav-link').forEach((a) => a.addEventListener('click', () => navigate(a.dataset.nav)));

    const VIEWS = { dashboard: loadDashboard, licenses: loadLicenses, activations: loadActivations, products: loadProducts, updates: loadUpdates, logs: loadLogs, config: loadConfig, account: () => {} };
    const TITLES = { dashboard: 'Dashboard', licenses: 'Licenses', activations: 'Activations', products: 'Products', updates: 'Update packages', logs: 'Activity log', config: 'Server config', account: 'Account' };

    function navigate(view) {
        if (!VIEWS[view]) view = 'dashboard';
        const path = view === 'dashboard' ? '/' : '/' + view;
        if (location.pathname !== path) {
            history.pushState({}, '', path);
        }
        $$('.nav-link').forEach((a) => a.classList.toggle('active', a.dataset.nav === view));
        $$('.view').forEach((v) => v.classList.add('hidden'));
        $('#view-' + view).classList.remove('hidden');
        $('#topbar-title').textContent = TITLES[view] || 'Dashboard';
        VIEWS[view]();
    }
    window.addEventListener('popstate', () => navigate(location.pathname.slice(1) || 'dashboard'));

    /* ── dashboard ── */
    async function loadDashboard() {
        const s = await api('stats');
        $('#stat-grid').innerHTML = [
            [s.products, 'Products'],
            [s.licenses, 'Licenses'],
            [s.active_licenses, 'Active licenses'],
            [s.activations, 'Live activations'],
            [s.checks_24h, 'Checks (24h)'],
        ].map(([n, l]) => `<div class="stat"><div class="num">${n}</div><div class="lbl">${l}</div></div>`).join('');

        $('#recent-table tbody').innerHTML = s.recent_activity.length
            ? s.recent_activity.map((r) => `<tr><td>${esc(r.action)}</td><td class="mono">${esc(r.domain || r.ip || r.detail || '—')}</td><td>${okBadge(!!r.success)}</td><td>${ago(r.created_at)}</td></tr>`).join('')
            : '<tr><td colspan="4" class="empty">No activity yet</td></tr>';
    }

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
                <td>${u.has_sql ? '<span class="badge badge-suspended">yes</span>' : '—'}</td>
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
        btn.disabled = true;
        const fd = new FormData();
        fd.append('product_id', $('#upd-product').value);
        fd.append('version', $('#upd-version').value.trim());
        fd.append('summary', $('#upd-summary').value.trim());
        fd.append('changelog', $('#upd-changelog').value.trim());
        if ($('#upd-hassql').checked) fd.append('has_sql', '1');
        fd.append('package', $('#upd-file').files[0]);
        try {
            // Multipart — bypass the JSON api() helper but keep the CSRF header.
            const res = await fetch('/admin-api/updates', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf },
                body: fd,
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
            toast('Update ' + data.data.version + ' published');
            e.target.reset();
            loadUpdates();
        } catch (err) {
            toast('Publish failed: ' + err.message);
        } finally {
            btn.disabled = false;
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
                    `<tr><td class="mono">v${h.version}</td><td>${esc(h.note || '—')}</td><td>${ago(h.published_at)}</td>` +
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
            $('#maint-state').textContent = m.maintenance
                ? 'ON — all public API calls return 503 SERVER_UNAVAILABLE instantly' + (m.since ? ' (since ' + m.since + ')' : '')
                : 'OFF — public API is serving normally';
            $('#maint-toggle').textContent = m.maintenance ? 'Disable maintenance mode' : 'Enable maintenance mode';
            $('#maint-toggle').className = 'btn ' + (m.maintenance ? 'btn-primary' : 'btn-danger');
        } catch (e2) {
            $('#maint-state').textContent = 'status unknown: ' + e2.message;
        }
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

    /* ── license gate users ── */
    let usersLicense = null;

    async function loadLicenseUsers() {
        const r = await api('licenses/' + usersLicense + '/users');
        $('#users-table tbody').innerHTML = r.data.length
            ? r.data.map((u) => `<tr>
                <td class="mono">${esc(u.username)}</td>
                <td>${esc(u.name || '—')}</td>
                <td>${ago(u.created_at)}</td>
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
    async function loadActivations() {
        const r = await api('activations');
        $('#activations-table tbody').innerHTML = r.data.length
            ? r.data.map((a) => `<tr>
                <td class="mono">${esc(a.domain)}</td>
                <td class="mono">${esc(a.license_code)}</td>
                <td>${esc(a.client_name)}</td>
                <td>${esc(a.product_name)}</td>
                <td class="mono">${esc(a.ip || '—')}</td>
                <td>${badge(a.status || 'enabled')}</td>
                <td>${ago(a.activated_at)}</td>
                <td>${ago(a.last_check_at)}</td>
                <td><div class="row-actions">
                    <button class="btn" onclick="EZL.toggleActivation(${a.id}, '${a.status || 'enabled'}')">${(a.status || 'enabled') === 'enabled' ? 'Disable' : 'Enable'}</button>
                    <button class="btn btn-danger" onclick="EZL.deleteActivation(${a.id})">Revoke</button>
                </div></td></tr>`).join('')
            : '<tr><td colspan="9" class="empty">No activations yet</td></tr>';
    }

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

    function openProductModal(p) {
        editingProduct = p || null;
        regenRequested = false;
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

    $('#prod-save').addEventListener('click', async () => {
        const err = $('#product-modal-error');
        err.classList.add('hidden');
        const body = {
            product_id: $('#prod-id').value.trim(),
            name: $('#prod-name').value.trim(),
            latest_version: $('#prod-version').value.trim(),
            status: $('#prod-status').value,
        };
        try {
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
            const newKey = r.api_key || (r.data && r.data.api_key);
            const newCode = r.recovery_code || (r.data && r.data.recovery_code);
            $('#product-modal').classList.add('hidden');
            toast(editingProduct ? 'Product updated' + (newKey ? ' — new key: ' + newKey : '') : 'Product created — copy its API key into core.json');
            if (newCode) showRecoveryCode(newCode, newKey ? 'The API key rotated — the old recovery code is dead too.' : null);
            loadProducts();
        } catch (e2) {
            err.textContent = e2.message;
            err.classList.remove('hidden');
        }
    });

    /* ── logs ── */
    let logQTimer = null;
    let logActionsLoaded = false;

    function logQuery() {
        const p = new URLSearchParams({ page: logPage, per: $('#logs-per').value });
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
            $('#logs-table tbody').innerHTML = r.data.map((l, i) => `<tr data-i="${i}">
                <td>${ago(l.created_at)}</td>
                <td>${esc(l.action)}</td>
                <td class="mono">${esc(l.license_code || '—')}</td>
                <td class="mono">${esc(l.domain || '—')}</td>
                <td class="mono">${esc(l.ip || '—')}</td>
                <td>${okBadge(!!l.success)}</td>
                <td>${esc(l.detail || '')}</td></tr>`).join('')
                || '<tr><td colspan="7" class="empty">No activity matches these filters.</td></tr>';

            $('#logs-info').textContent = r.total
                ? `${r.total.toLocaleString()} entr${r.total === 1 ? 'y' : 'ies'} — page ${r.page} of ${r.pages}`
                : 'No entries';
            $('#logs-prev').disabled = r.page <= 1;
            $('#logs-next').disabled = r.page >= r.pages;

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
    $('#logs-prev').addEventListener('click', () => { logPage--; loadLogs(true); });
    $('#logs-next').addEventListener('click', () => { logPage++; loadLogs(true); });
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
        if (e.key === 'Escape') $$('.modal').forEach((m) => m.classList.add('hidden'));
    });

    init();
})();
