/* STUDIIO.CO admin — single-page app. Talks to api.php; no build step. */

const API = 'api.php';
const IMG = '../img/';
const appEl = document.getElementById('app');

let S = null;                 // session: { user, csrf, backend, branch, claude, files, ... }
let current = null;           // the page on screen: { name, dirty, save, timers }
let shellEls = null;          // { main, savebar, side, ... }

/* ==========================================================================
   Helpers
   ========================================================================== */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/** Safe HTML templates: interpolated values are escaped unless they are html`` results themselves. */
class Html { constructor(s) { this.s = s; } toString() { return this.s; } }
const part = (v) => (v instanceof Html ? v.s : Array.isArray(v) ? v.map(part).join('') : v === false || v == null ? '' : esc(v));
const html = (strings, ...vals) => new Html(strings.reduce((out, str, i) => out + str + (i < vals.length ? part(vals[i]) : ''), ''));
const put = (el, tpl) => { el.innerHTML = String(tpl); return el; };

const clone = (o) => JSON.parse(JSON.stringify(o));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const imgUrl = (file, folder = '') => IMG + (folder ? folder + '/' : '') + encodeURIComponent(file || '');
const fmtDate = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  return d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric', hour: '2-digit', minute: '2-digit' });
};
const plural = (n, one, many = one + 's') => `${n} ${n === 1 ? one : many}`;

async function api(action, { method = 'GET', params = {}, body, form } = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (method !== 'GET') {
    opts.headers['X-CSRF-Token'] = S?.csrf || '';
    if (form) opts.body = form;
    else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body ?? {}); }
  }
  let res;
  try {
    res = await fetch(`${API}?${qs}`, opts);
  } catch (e) {
    throw Object.assign(new Error('Could not reach the server. Check your connection.'), { status: 0 });
  }
  let data = null;
  try { data = await res.json(); } catch (e) { /* not JSON */ }
  if (res.status === 401 && action !== 'login' && action !== 'session') {
    S = null;
    renderLogin('Your session ended. Please sign in again.');
  }
  if (!res.ok) throw Object.assign(new Error(data?.error || `Request failed (${res.status})`), { status: res.status, data });
  return data;
}

function toast(text, kind = 'ok', list = []) {
  let box = $('.toasts');
  if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('role', 'status'); document.body.append(box); }
  const t = document.createElement('div');
  t.className = 'toast ' + (kind === 'ok' ? '' : kind);
  put(t, html`${text}${list.length ? html`<ul>${list.slice(0, 8).map((l) => html`<li>${l}</li>`)}</ul>` : ''}`);
  box.append(t);
  setTimeout(() => t.remove(), kind === 'bad' ? 9000 : list.length ? 7000 : 3500);
}

function showError(e) {
  const errors = e?.data?.errors || [];
  toast(e?.message || 'Something went wrong.', 'bad', errors.length > 1 ? errors : []);
}

function warnings(list) {
  if (list && list.length) toast('Saved, with notes:', 'warn', list);
}

/** Drawer (side panel) or centred modal. Returns { el, close }. */
function openPanel(content, { modal = false, onClose } = {}) {
  const overlay = document.createElement('div');
  overlay.className = 'overlay' + (modal ? ' center' : '');
  const panel = document.createElement('div');
  panel.className = modal ? 'modal' : 'drawer';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'true');
  put(panel, content);
  overlay.append(panel);
  document.body.append(overlay);
  const lastFocus = document.activeElement;
  const close = () => {
    overlay.remove();
    document.removeEventListener('keydown', onKey);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
    onClose && onClose();
  };
  const onKey = (e) => { if (e.key === 'Escape' && overlay === $$('.overlay').at(-1)) close(); };
  document.addEventListener('keydown', onKey);
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
  panel.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) close(); });
  setTimeout(() => ($('input:not([type=file]), textarea, select, button.btn-primary', panel) || panel).focus(), 30);
  return { el: panel, close };
}

/** Upload one image. kind 'photo' → img/, 'logo' → img/clients/. */
async function uploadImage(file, kind) {
  const form = new FormData();
  form.append('file', file);
  form.append('kind', kind);
  return api('upload', { method: 'POST', form });
}

/** Pick an existing image or upload a new one. Resolves { file, w, h } or null. */
function pickImage({ logo = false, current: selected = '' } = {}) {
  return new Promise(async (resolve) => {
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    const { el, close } = openPanel(html`
      <div class="drawer-head"><h2>${logo ? 'Choose a logo' : 'Choose an image'}</h2><button class="icon-btn" data-close aria-label="Close">✕</button></div>
      <div class="toolbar">
        <label class="btn btn-primary upload-btn">Upload new…<input type="file" accept="image/jpeg,image/png,image/webp"></label>
        <span class="muted">${logo ? 'Transparent PNG works best. Resized to 480 px wide.' : 'JPG, PNG or WebP. Resized to max 1600 × 2000 for the web.'}</span>
      </div>
      <div class="img-grid"><p class="muted">Loading images…</p></div>`, { modal: true, onClose: () => finish(null) });
    const grid = $('.img-grid', el);
    try {
      const { files } = await api('images', { params: { folder: logo ? 'clients' : 'img' } });
      put(grid, files.length ? files.map((f) => html`
        <button type="button" class="img-pick${logo ? ' logo' : ''}" data-file="${f}" aria-pressed="${f === selected}">
          <img src="${imgUrl(f, logo ? 'clients' : '')}" alt="" loading="lazy"><span>${f}</span>
        </button>`) : html`<p class="muted">No images yet.</p>`);
    } catch (e) { put(grid, html`<p class="muted">${e.message}</p>`); }
    grid.addEventListener('click', (e) => {
      const b = e.target.closest('.img-pick');
      if (!b) return;
      const im = $('img', b);
      finish({ file: b.dataset.file, w: im.naturalWidth || 0, h: im.naturalHeight || 0 });
      close();
    });
    $('input[type=file]', el).addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const label = e.target.closest('label');
      label.firstChild.textContent = 'Uploading…';
      try {
        const up = await uploadImage(file, logo ? 'logo' : 'photo');
        toast(`Uploaded ${up.file}`);
        finish(up);
        close();
      } catch (err) { showError(err); label.firstChild.textContent = 'Upload new…'; }
    });
  });
}

/* ==========================================================================
   Sign-in
   ========================================================================== */

function renderLogin(message = '') {
  current = null;
  shellEls = null;
  document.body.className = '';
  put(appEl, html`
    <main class="login">
      <div class="login-card">
        <img class="login-logo" src="../img/studiio-logo.svg" alt="STUDIIO.CO">
        <p class="login-sub">Admin</p>
        <form novalidate>
          <label class="field">Email<input type="email" name="email" autocomplete="username" required></label>
          <label class="field">Password<input type="password" name="password" autocomplete="current-password" required></label>
          <p class="error" role="alert">${message}</p>
          <button class="btn btn-primary" type="submit">Sign in</button>
        </form>
      </div>
    </main>`);
  appEl.className = '';
  const form = $('form', appEl);
  $('input[name=email]', form).focus();
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('button', form);
    btn.disabled = true;
    $('.error', form).textContent = '';
    try {
      S = await api('login', { method: 'POST', body: { email: form.email.value, password: form.password.value } });
      renderShell();
    } catch (err) {
      $('.error', form).textContent = err.message;
      form.password.value = '';
      form.password.focus();
    } finally { btn.disabled = false; }
  });
}

/* ==========================================================================
   Shell, navigation, save bar
   ========================================================================== */

const NAV = [
  ['overview', 'Overview'],
  ['label', 'Website'],
  ['work', 'Selected work'],
  ['reel', 'Hero reel'],
  ['copy', 'Copy (EN / AR)'],
  ['clients', 'Client logos'],
  ['label', 'Business'],
  ['leads', 'Leads'],
  ['reels', 'Reels'],
  ['label', 'Claude Code'],
  ['claude', 'Ask Claude'],
  ['publish', 'Publish'],
  ['label', 'Advanced'],
  ['raw', 'Raw JSON'],
];

function renderShell() {
  appEl.className = '';
  put(appEl, html`
    <div class="shell">
      <div class="topbar"><img src="../img/studiio-logo.svg" alt="STUDIIO.CO"><button type="button" data-menu>Menu</button></div>
      <aside class="side">
        <a class="side-logo" href="#/overview"><img src="../img/studiio-logo.svg" alt="STUDIIO.CO"></a>
        <div class="side-tag">Admin · ${S.backend === 'github' ? 'Online' : 'This Mac'}</div>
        <nav class="nav" aria-label="Admin">
          ${NAV.filter(([id]) => id !== 'reels' || S.reels).map(([id, label]) => id === 'label'
            ? html`<div class="nav-label">${label}</div>`
            : html`<a href="#/${id}" data-nav="${id}"><span>${label}</span><span class="badge" data-badge="${id}" hidden></span></a>`)}
        </nav>
        <div class="side-foot">
          <a href="${S.siteUrl}" target="_blank" rel="noopener">View site ↗</a>
          <div class="who" title="${S.user}">${S.user}</div>
          <button type="button" data-logout>Sign out</button>
        </div>
      </aside>
      <main class="main" id="main"></main>
    </div>
    <div class="savebar" role="region" aria-label="Unsaved changes">
      <span>Unsaved changes</span>
      <span class="head-actions"><button class="btn btn-sm" type="button" data-discard>Discard</button><button class="btn btn-sm btn-accent" type="button" data-save>Save</button></span>
    </div>`);
  shellEls = { main: $('#main'), savebar: $('.savebar'), shell: $('.shell') };
  $('[data-logout]').addEventListener('click', async () => {
    if (current?.dirty && !confirm('You have unsaved changes. Sign out anyway?')) return;
    try { await api('logout', { method: 'POST' }); } catch (e) { /* ignore */ }
    S = null;
    renderLogin();
  });
  $('[data-menu]').addEventListener('click', () => shellEls.shell.classList.toggle('menu-open'));
  $('[data-save]').addEventListener('click', saveCurrent);
  $('[data-discard]').addEventListener('click', () => { if (confirm('Throw away your unsaved changes?')) { current.dirty = false; route(); } });
  route();
  refreshBadges();
}

async function saveCurrent() {
  if (!current?.save) return;
  const btn = $('[data-save]');
  btn.disabled = true;
  btn.textContent = 'Saving…';
  try {
    await current.save();
  } catch (e) {
    showError(e);
    if (e.status === 409 && confirm('This file was changed somewhere else (for example by Claude Code). Load the latest version? Your unsaved edits on this page will be lost.')) {
      current.dirty = false;
      route();
    }
  } finally {
    btn.disabled = false;
    btn.textContent = 'Save';
  }
}

function setDirty(dirty) {
  if (!current) return;
  current.dirty = dirty;
  shellEls.savebar.classList.toggle('on', dirty);
}

async function refreshBadges() {
  try {
    const d = await api('overview');
    const set = (id, n) => { const b = $(`[data-badge="${id}"]`); if (b) { b.textContent = n; b.hidden = !n; } };
    set('leads', d.newLeads);
    set('publish', d.changes?.items?.length || 0);
    set('claude', (d.tasks || []).filter((t) => t.status === 'running' || t.status === 'review').length);
  } catch (e) { /* badges are optional */ }
}

window.addEventListener('beforeunload', (e) => { if (current?.dirty) { e.preventDefault(); e.returnValue = ''; } });
window.addEventListener('hashchange', () => {
  if (!shellEls) return;
  if (current?.dirty && !confirm('You have unsaved changes. Leave this page without saving?')) {
    history.replaceState(null, '', '#/' + current.name);
    return;
  }
  route();
});

async function route() {
  if (!shellEls) return;
  if (current) current.timers.forEach(clearInterval);
  const name = (location.hash.match(/^#\/([a-z]+)/) || [])[1] || 'overview';
  const fn = PAGES[name] || PAGES.overview;
  const page = { name: PAGES[name] ? name : 'overview', dirty: false, save: null, timers: [] };
  current = page;
  setDirty(false);
  $$('[data-nav]').forEach((a) => (a.dataset.nav === page.name ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current')));
  shellEls.shell.classList.remove('menu-open');
  const el = document.createElement('div');
  put(el, html`<p class="boot-msg">Loading…</p>`);
  shellEls.main.replaceChildren(el);
  window.scrollTo(0, 0);
  page.alive = () => current === page;
  try {
    await fn(el, page);
  } catch (e) {
    if (page.alive()) put(el, html`<div class="page-head"><div><h1>Couldn't load this page</h1><p>${e.message}</p></div></div>
      ${e.data?.raw ? html`<p><a class="btn btn-primary" href="#/raw?file=${e.data.file}">Fix it in Raw JSON</a> <span class="muted">or ask Claude Code to repair the file.</span></p>` : ''}`);
  }
}

/* ==========================================================================
   Pages
   ========================================================================== */

const PAGES = {};

/* ---------- Overview ---------- */
PAGES.overview = async (el, page) => {
  const d = await api('overview');
  if (!page.alive()) return;
  const pending = d.changes?.items?.length ?? 0;
  const local = S.backend === 'local';
  put(el, html`
    <div class="page-head"><div><h1>STUDIIO.CO admin</h1>
      <p>Edit the website, follow up on leads, and hand bigger changes to Claude Code. Claude Code and this panel work on the same content files.</p></div></div>
    <div class="cards">
      <a class="card stat" href="#/leads"><b>${d.newLeads}</b><span>new ${d.newLeads === 1 ? 'lead' : 'leads'} · ${d.totalLeads} total</span></a>
      ${local
        ? html`<a class="card stat" href="#/publish"><b>${pending}</b><span>unpublished ${pending === 1 ? 'change' : 'changes'}${d.changes?.branch ? html` · on <code>${d.changes.branch}</code>` : ''}</span></a>`
        : html`<a class="card stat" href="#/publish"><b>Live</b><span>saves commit to GitHub <code>${S.branch}</code>, then Hostinger deploys</span></a>`}
      <div class="card"><h2>How saving works</h2><p class="muted" style="margin:0">${local
        ? 'Saves write to the content files in this folder. Nothing goes live until you press Publish.'
        : 'Each save is a commit to GitHub. Hostinger puts it live in about a minute.'}</p></div>
    </div>
    ${S.claude !== 'off' ? html`
      <div class="card ask" style="margin-bottom:14px">
        <h2>Ask Claude</h2>
        <form data-ask><textarea name="prompt" placeholder="e.g. Add a Grandpas project to Selected work using burgers.jpg, tagged Production and F&B, with Arabic titles."></textarea>
        <div class="ask-foot"><span class="muted">Runs Claude Code ${S.claude === 'github' ? 'in GitHub Actions' : 'on this Mac'} with ${S.model}. You review the changes before they go live.</span><button class="btn btn-accent" type="submit">Send to Claude</button></div></form>
      </div>` : ''}
    <div class="card">
      <h2>Latest leads</h2>
      ${d.latestLeads.length ? html`<div class="rows">${d.latestLeads.map((l) => html`
        <a class="row" href="#/leads" style="grid-template-columns:1fr auto;text-decoration:none">
          <div class="row-main"><p class="row-title">${l.name}${l.company ? html` <span class="muted">· ${l.company}</span>` : ''}</p><p class="row-sub" style="margin:0">${l.service_label || l.service || ''} · ${fmtDate(l.created_at)}</p></div>
          <span class="status ${l.status}">${l.status}</span></a>`)}</div>`
        : html`<p class="muted" style="margin:0">No leads yet. Enquiries from the contact form will show up here.</p>`}
    </div>`);
  const ask = $('[data-ask]', el);
  if (ask) ask.addEventListener('submit', (e) => { e.preventDefault(); submitTask(ask, () => { location.hash = '#/claude'; }); });
};

/* ---------- List editors: Selected work + Hero reel ---------- */

function listEditor(cfg) {
  return async (el, page) => {
    const [main, tax] = await Promise.all([api('content', { params: { file: cfg.file } }), cfg.needsTaxonomy ? api('content', { params: { file: 'taxonomy' } }) : null]);
    if (!page.alive()) return;
    let items = clone(main.data);
    let version = main.version;
    let saved = clone(items);
    const taxonomy = tax?.data || {};
    const touched = new Set();

    const render = () => {
      put(el, html`
        <div class="page-head"><div><h1>${cfg.title}</h1><p>${cfg.intro}</p></div>
          <div class="head-actions"><button class="btn btn-primary" type="button" data-add>${cfg.addLabel}</button></div></div>
        ${items.length ? html`<div class="rows">${items.map((it, i) => html`
          <div class="row${touched.has(it) ? ' is-new' : ''}" data-i="${i}">
            <div class="row-order">
              <button class="icon-btn" type="button" data-up aria-label="Move up" ${i === 0 ? 'disabled' : ''}>▲</button>
              <button class="icon-btn" type="button" data-down aria-label="Move down" ${i === items.length - 1 ? 'disabled' : ''}>▼</button>
            </div>
            <img class="thumb" src="${imgUrl(it.img)}" alt="" loading="lazy">
            <div class="row-main">${cfg.row(it, taxonomy)}</div>
            <div class="row-actions">
              <button class="btn btn-sm" type="button" data-edit>Edit</button>
              <button class="btn btn-sm btn-danger" type="button" data-del aria-label="Remove">Remove</button>
            </div>
          </div>`)}</div>` : html`<div class="empty">Nothing here yet.</div>`}`);
      setDirty(!same(items, saved));
    };

    const edit = (index) => {
      const isNew = index === -1;
      const draft = isNew ? cfg.blank(taxonomy) : clone(items[index]);
      const panel = openPanel(html`
        <div class="drawer-head"><h2>${isNew ? cfg.addLabel : 'Edit'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">✕</button></div>
        <form class="stack-form" novalidate style="display:grid;gap:16px">
          <div class="img-field">
            <img src="${imgUrl(draft.img)}" alt="" data-preview>
            <div class="stack"><button class="btn btn-sm" type="button" data-pick>Choose or upload image</button><span class="mono muted" data-imgname>${draft.img || 'No image yet'}</span></div>
          </div>
          ${cfg.form(draft, taxonomy)}
          <div class="drawer-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">${isNew ? 'Add' : 'Done'}</button></div>
        </form>`);
      const form = $('form', panel.el);
      $('[data-pick]', form).addEventListener('click', async () => {
        const pick = await pickImage({ current: draft.img });
        if (!pick) return;
        draft.img = pick.file;
        $('[data-preview]', form).src = imgUrl(pick.file);
        $('[data-imgname]', form).textContent = pick.file;
      });
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const next = cfg.read(form, draft, taxonomy);
        const problem = !next.img ? 'Choose an image.' : cfg.check(next);
        if (problem) { toast(problem, 'bad'); return; }
        if (isNew) { items.unshift(next); touched.add(next); } else { items[index] = next; touched.add(next); }
        panel.close();
        render();
      });
    };

    el.addEventListener('click', (e) => {
      const row = e.target.closest('[data-i]');
      const i = row ? Number(row.dataset.i) : -1;
      if (e.target.closest('[data-add]')) edit(-1);
      else if (e.target.closest('[data-edit]')) edit(i);
      else if (e.target.closest('[data-up]') && i > 0) { [items[i - 1], items[i]] = [items[i], items[i - 1]]; render(); }
      else if (e.target.closest('[data-down]') && i < items.length - 1) { [items[i + 1], items[i]] = [items[i], items[i + 1]]; render(); }
      else if (e.target.closest('[data-del]') && confirm(`Remove “${cfg.name(items[i])}” from ${cfg.title.toLowerCase()}?`)) { items.splice(i, 1); render(); }
    });

    page.save = async () => {
      const res = await api('content', { method: 'POST', body: { file: cfg.file, data: items, version } });
      version = res.version;
      items = clone(res.data);
      saved = clone(res.data);
      touched.clear();
      render();
      toast(S.backend === 'github' ? 'Saved and committed. Live in about a minute.' : 'Saved. Publish when you’re ready to put it live.');
      warnings(res.warnings);
      refreshBadges();
    };
    render();
  };
}

const pairFields = (draft, key, label, { textarea = false } = {}) => html`
  <div class="grid-2">
    <label class="field">${label} — English${textarea ? html`<textarea name="${key}.en">${draft[key]?.en}</textarea>` : html`<input type="text" name="${key}.en" value="${draft[key]?.en}">`}</label>
    <label class="field">${label} — العربية${textarea ? html`<textarea name="${key}.ar" dir="rtl">${draft[key]?.ar}</textarea>` : html`<input type="text" name="${key}.ar" dir="rtl" value="${draft[key]?.ar}">`}</label>
  </div>`;
const readPair = (form, key) => ({ en: form.elements[key + '.en'].value.trim(), ar: form.elements[key + '.ar'].value.trim() });
const label = (dict, key) => dict?.[key]?.en || key;

PAGES.work = listEditor({
  file: 'work',
  title: 'Selected work',
  intro: 'The project grid on the homepage, in this order. The filters use each project’s services.',
  addLabel: 'Add project',
  needsTaxonomy: true,
  name: (w) => w.title?.en || 'this project',
  row: (w, tax) => html`
    <p class="row-title">${w.title?.en}</p>
    <p class="row-sub ar" dir="rtl">${w.title?.ar} — ${w.sub?.ar}</p>
    <div class="chips">${(w.cats || []).map((c) => html`<span class="tag">${label(tax.categories, c)}</span>`)}<span class="tag">${label(tax.sectors, w.sector)}</span></div>`,
  blank: (tax) => ({ img: '', title: { en: '', ar: '' }, sub: { en: '', ar: '' }, cats: [], sector: Object.keys(tax.sectors || {})[0] || '' }),
  form: (w, tax) => html`
    ${pairFields(w, 'title', 'Title')}
    ${pairFields(w, 'sub', 'Subtitle')}
    <fieldset class="field" style="border:0;padding:0;margin:0"><legend style="font-weight:600;font-size:13px;margin-bottom:6px">Services (work filters)</legend>
      <div class="chips">${Object.entries(tax.categories || {}).map(([k, v]) => html`<label class="chip"><input type="checkbox" name="cats" value="${k}" ${(w.cats || []).includes(k) ? 'checked' : ''}>${v.en}</label>`)}</div>
    </fieldset>
    <label class="field">Sector<select name="sector">${Object.entries(tax.sectors || {}).map(([k, v]) => html`<option value="${k}" ${k === w.sector ? 'selected' : ''}>${v.en} · ${v.ar}</option>`)}</select></label>`,
  read: (form, draft) => ({ ...draft, title: readPair(form, 'title'), sub: readPair(form, 'sub'), cats: $$('input[name=cats]:checked', form).map((c) => c.value), sector: form.elements.sector.value }),
  check: (w) => (!w.title.en ? 'Add an English title.' : !w.cats.length ? 'Pick at least one service.' : ''),
});

PAGES.reel = listEditor({
  file: 'reel',
  title: 'Hero reel',
  intro: 'The scrolling film strip under the logo and the full-screen reel it opens, in this order.',
  addLabel: 'Add slide',
  name: (s) => s.cap?.en || 'this slide',
  row: (s) => html`<p class="row-title">${s.cap?.en}</p><p class="row-sub ar" dir="rtl">${s.cap?.ar}</p><span class="tag">${s.img}</span>`,
  blank: () => ({ img: '', cap: { en: '', ar: '' } }),
  form: (s) => pairFields(s, 'cap', 'Caption'),
  read: (form, draft) => ({ ...draft, cap: readPair(form, 'cap') }),
  check: (s) => (!s.cap.en ? 'Add an English caption.' : ''),
});

/* ---------- Copy ---------- */

const COPY_GROUPS = {
  skip: 'Header & menu', nav: 'Header & menu', header: 'Header & menu', lang: 'Header & menu',
  hero: 'Hero', about: 'Who we are', services: 'Services', work: 'Selected work', filter: 'Selected work', cat: 'Selected work',
  proof: 'In numbers', num: 'In numbers', clients: 'In numbers', ai: 'studiio.ai', thinking: 'Thinking', read: 'Thinking',
  contact: 'Contact', form: 'Contact form', footer: 'Footer', reel: 'Reel',
};

PAGES.copy = async (el, page) => {
  const res = await api('content', { params: { file: 'copy' } });
  if (!page.alive()) return;
  let version = res.version;
  let saved = clone(res.data);
  let copy = clone(res.data);
  copy.en ||= {}; copy.ar ||= {};
  const keys = () => [...new Set([...Object.keys(copy.en), ...Object.keys(copy.ar)])];

  const render = () => {
    const groups = {};
    keys().forEach((k) => { const g = COPY_GROUPS[k.split('.')[0]] || 'Other'; (groups[g] ||= []).push(k); });
    put(el, html`
      <div class="page-head"><div><h1>Copy</h1><p>Every headline, paragraph and button on the page, in English and Arabic. Changed fields turn orange until you save.</p></div></div>
      <div class="toolbar"><input type="search" placeholder="Search text or key…" data-search aria-label="Search copy"><span class="muted" data-count></span></div>
      <div class="copy-head"><span>Key</span><span>English</span><span>العربية</span></div>
      ${Object.entries(groups).map(([g, ks]) => html`
        <section class="copy-group" data-group>
          <h2>${g}</h2>
          ${ks.map((k) => {
            const isHtml = /<[a-z]/i.test((copy.en[k] || '') + (copy.ar[k] || ''));
            return html`<div class="copy-row" data-key="${k}">
              <div class="copy-key">${k}${isHtml ? html`<small>allows &lt;em&gt; &lt;br&gt;</small>` : ''}</div>
              <textarea data-lang="en" rows="1" aria-label="${k} English">${copy.en[k] ?? ''}</textarea>
              <textarea data-lang="ar" rows="1" dir="rtl" aria-label="${k} Arabic">${copy.ar[k] ?? ''}</textarea>
            </div>`;
          })}
        </section>`)}`);
    $$('textarea', el).forEach(fit);
    mark();
  };
  const fit = (t) => { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight + 2, 320) + 'px'; };
  const mark = () => {
    $$('.copy-row', el).forEach((row) => $$('textarea', row).forEach((t) => {
      t.classList.toggle('changed', (copy[t.dataset.lang][row.dataset.key] ?? '') !== (saved[t.dataset.lang]?.[row.dataset.key] ?? ''));
    }));
    setDirty(!same(copy, saved));
  };
  el.addEventListener('input', (e) => {
    const t = e.target;
    if (t.matches('textarea[data-lang]')) {
      copy[t.dataset.lang][t.closest('.copy-row').dataset.key] = t.value;
      fit(t);
      t.classList.toggle('changed', t.value !== (saved[t.dataset.lang]?.[t.closest('.copy-row').dataset.key] ?? ''));
      setDirty(!same(copy, saved));
    } else if (t.matches('[data-search]')) {
      const q = t.value.trim().toLowerCase();
      let n = 0;
      $$('.copy-row', el).forEach((row) => {
        const k = row.dataset.key;
        const hit = !q || k.toLowerCase().includes(q) || (copy.en[k] || '').toLowerCase().includes(q) || (copy.ar[k] || '').includes(q);
        row.hidden = !hit;
        if (hit) n++;
      });
      $$('[data-group]', el).forEach((g) => { g.hidden = !$$('.copy-row:not([hidden])', g).length; });
      $('[data-count]', el).textContent = q ? plural(n, 'match', 'matches') : '';
    }
  });
  page.save = async () => {
    const r = await api('content', { method: 'POST', body: { file: 'copy', data: copy, version } });
    version = r.version;
    saved = clone(r.data);
    copy = clone(r.data);
    mark();
    $$('.copy-row', el).forEach((row) => $$('textarea', row).forEach((t) => { t.value = copy[t.dataset.lang][row.dataset.key] ?? ''; }));
    toast(S.backend === 'github' ? 'Copy saved and committed.' : 'Copy saved. Publish to put it live.');
    warnings(r.warnings);
    refreshBadges();
  };
  render();
};

/* ---------- Client logos ---------- */

const logoHeight = (c) => Math.round(Math.max(26, Math.min(60, Math.sqrt(5200 / (c.w / c.h)))) * (c.s || 1));

PAGES.clients = async (el, page) => {
  const res = await api('content', { params: { file: 'clients' } });
  if (!page.alive()) return;
  let data = clone(res.data);
  let version = res.version;
  let saved = clone(data);

  const render = () => {
    const half = Math.ceil(data.logos.length / 2);
    put(el, html`
      <div class="page-head"><div><h1>Client logos</h1><p>The sliding logo strip under “In numbers”. The first ${half} slide along the top row, the rest along the bottom row. Logos are sized to look equally heavy; use Scale to nudge one.</p></div>
        <div class="head-actions"><button class="btn btn-primary" type="button" data-add>Add logo</button></div></div>
      <div class="logo-grid">${data.logos.map((c, i) => html`
        <div class="logo-card" data-i="${i}">
          <div class="logo-box"><img src="${imgUrl(c.file, 'clients')}?v=${data.version}" alt="${c.name}" style="--h:${logoHeight(c)}"></div>
          <div class="name" title="${c.name}">${c.name}</div>
          <div class="bar">
            <span><button class="icon-btn" type="button" data-up aria-label="Move earlier" ${i === 0 ? 'disabled' : ''}>◀</button>
            <button class="icon-btn" type="button" data-down aria-label="Move later" ${i === data.logos.length - 1 ? 'disabled' : ''}>▶</button></span>
            <span class="mono muted">${i < half ? 'top' : 'bottom'} · ${i + 1}</span>
            <span><button class="icon-btn" type="button" data-edit aria-label="Edit ${c.name}">✎</button>
            <button class="icon-btn" type="button" data-del aria-label="Remove ${c.name}">✕</button></span>
          </div>
        </div>`)}</div>`);
    setDirty(!same(data, saved));
  };

  const edit = (index, preset) => {
    const isNew = index === -1;
    const draft = isNew ? { name: '', file: preset.file, w: preset.w, h: preset.h } : clone(data.logos[index]);
    const panel = openPanel(html`
      <div class="drawer-head"><h2>${isNew ? 'Add logo' : 'Edit logo'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">✕</button></div>
      <form novalidate style="display:grid;gap:16px">
        <div class="logo-box" style="height:120px"><img data-preview src="${imgUrl(draft.file, 'clients')}" alt="" style="--h:${logoHeight(draft)}"></div>
        <label class="field">Client name<input type="text" name="name" value="${draft.name}" required></label>
        <label class="field">Scale <span class="hint" data-scale-val>${(draft.s || 1).toFixed(2)}×</span><input type="range" name="s" min="0.5" max="2" step="0.05" value="${draft.s || 1}"></label>
        <p class="mono muted" style="margin:0">${draft.file} · ${draft.w}×${draft.h}</p>
        <div class="drawer-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">${isNew ? 'Add' : 'Done'}</button></div>
      </form>`);
    const form = $('form', panel.el);
    form.s.addEventListener('input', () => {
      draft.s = Number(form.s.value);
      $('[data-scale-val]', form).textContent = draft.s.toFixed(2) + '×';
      $('[data-preview]', form).style.setProperty('--h', logoHeight(draft));
    });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      draft.name = form.name.value.trim();
      if (!draft.name) { toast('Add the client’s name.', 'bad'); return; }
      if (Math.abs((draft.s || 1) - 1) < 0.001) delete draft.s;
      if (isNew) data.logos.push(draft); else data.logos[index] = draft;
      panel.close();
      render();
    });
  };

  el.addEventListener('click', async (e) => {
    const card = e.target.closest('[data-i]');
    const i = card ? Number(card.dataset.i) : -1;
    const L = data.logos;
    if (e.target.closest('[data-add]')) {
      const pick = await pickImage({ logo: true });
      if (pick && pick.w && pick.h) edit(-1, pick);
      else if (pick) toast('Could not read that logo’s size. Try again.', 'bad');
    } else if (e.target.closest('[data-edit]')) edit(i);
    else if (e.target.closest('[data-up]') && i > 0) { [L[i - 1], L[i]] = [L[i], L[i - 1]]; render(); }
    else if (e.target.closest('[data-down]') && i < L.length - 1) { [L[i + 1], L[i]] = [L[i], L[i + 1]]; render(); }
    else if (e.target.closest('[data-del]') && confirm(`Remove ${L[i].name} from the logo strip?`)) { L.splice(i, 1); render(); }
  });

  page.save = async () => {
    const r = await api('content', { method: 'POST', body: { file: 'clients', data, version } });
    version = r.version;
    data = clone(r.data);
    saved = clone(r.data);
    render();
    toast(S.backend === 'github' ? 'Logos saved and committed.' : 'Logos saved. Publish to put them live.');
    warnings(r.warnings);
    refreshBadges();
  };
  render();
};

/* ---------- Leads ---------- */

PAGES.leads = async (el, page) => {
  let { leads, statuses } = await api('leads');
  if (!page.alive()) return;
  let filter = 'open';
  let q = '';
  const FILTERS = [['open', 'Open'], ['all', 'All'], ...statuses.map((s) => [s, s[0].toUpperCase() + s.slice(1)])];
  const matches = (l) => (filter === 'all' || (filter === 'open' ? !['won', 'lost', 'archived'].includes(l.status) : l.status === filter))
    && (!q || [l.name, l.company, l.email, l.message].join(' ').toLowerCase().includes(q));
  const count = (f) => leads.filter((l) => f === 'all' || (f === 'open' ? !['won', 'lost', 'archived'].includes(l.status) : l.status === f)).length;

  const render = () => {
    const list = leads.filter(matches);
    put(el, html`
      <div class="page-head"><div><h1>Leads</h1><p>Every enquiry from the website’s contact form. They’re stored privately on the server, never in the public code repository.</p></div>
        <div class="head-actions"><a class="btn" href="${API}?action=leads-csv" download>Export CSV</a></div></div>
      <div class="toolbar">
        <div class="tabs" role="group" aria-label="Filter by status">${FILTERS.map(([f, name]) => html`<button class="tab" type="button" data-filter="${f}" aria-pressed="${f === filter}">${name}<small>${count(f)}</small></button>`)}</div>
        <input type="search" placeholder="Search name, company, email…" value="${q}" data-q aria-label="Search leads">
      </div>
      ${list.length ? html`<table class="table"><thead><tr><th>Received</th><th>From</th><th>Service</th><th>Status</th></tr></thead><tbody>
        ${list.map((l) => html`<tr data-id="${l.id}" tabindex="0">
          <td><span class="mono">${fmtDate(l.created_at)}</span></td>
          <td><b>${l.name}</b><div class="sub">${[l.company, l.email].filter(Boolean).join(' · ')}</div></td>
          <td>${l.service_label || l.service || '—'}</td>
          <td><span class="status ${l.status}">${l.status}</span></td></tr>`)}
      </tbody></table>` : html`<div class="empty">${leads.length ? 'No leads match this filter.' : 'No leads yet. When someone sends the contact form on studiio.co, it lands here (and in the info@studiio.co inbox).'}</div>`}`);
    const box = $('[data-q]', el);
    if (q) { box.focus(); box.setSelectionRange(q.length, q.length); }
  };

  const open = (id) => {
    const lead = leads.find((l) => l.id === id);
    if (!lead) return;
    const subject = encodeURIComponent(`Re: your enquiry to STUDIIO.CO`);
    const panel = openPanel(html`
      <div class="drawer-head"><h2>${lead.name}</h2><button class="icon-btn" type="button" data-close aria-label="Close">✕</button></div>
      <dl class="dl">
        <dt>Received</dt><dd>${fmtDate(lead.created_at)}</dd>
        <dt>Email</dt><dd><a href="mailto:${encodeURIComponent(lead.email)}">${lead.email}</a></dd>
        <dt>Company</dt><dd>${lead.company || '—'}</dd>
        <dt>Service</dt><dd>${lead.service_label || lead.service || '—'}</dd>
        <dt>Language</dt><dd>${lead.lang === 'ar' ? 'Arabic' : 'English'}</dd>
        ${lead.mail_sent === false ? html`<dt>Email alert</dt><dd>Not emailed to info@ (mail failed, or a local test)</dd>` : ''}
      </dl>
      <div><p style="font-weight:600;margin:0 0 6px">Message</p><p class="lead-msg" ${lead.lang === 'ar' ? 'dir="auto"' : ''}>${lead.message || '—'}</p></div>
      <label class="field">Status<select name="status">${statuses.map((s) => html`<option value="${s}" ${s === lead.status ? 'selected' : ''}>${s}</option>`)}</select></label>
      <label class="field">Notes <span class="hint">Only you see these.</span><textarea name="notes" rows="4">${lead.notes}</textarea></label>
      <div class="drawer-foot">
        <button class="btn btn-danger" type="button" data-delete>Delete</button>
        <a class="btn" href="mailto:${encodeURIComponent(lead.email)}?subject=${subject}">Reply by email</a>
        <button class="btn btn-primary" type="button" data-save-lead>Save</button>
      </div>`);
    const p = panel.el;
    const update = async (changes, msg) => {
      try {
        const r = await api('lead', { method: 'POST', body: { id, ...changes } });
        leads = leads.map((l) => (l.id === id ? r.lead : l));
        Object.assign(lead, r.lead);
        toast(msg);
        render();
        refreshBadges();
      } catch (e) { showError(e); }
    };
    $('[name=status]', p).addEventListener('change', (e) => update({ status: e.target.value }, `Marked as ${e.target.value}.`));
    $('[data-save-lead]', p).addEventListener('click', () => update({ notes: $('[name=notes]', p).value }, 'Notes saved.'));
    $('[data-delete]', p).addEventListener('click', async () => {
      if (!confirm(`Delete ${lead.name}'s enquiry for good? This can't be undone. (Archive it instead to keep a record.)`)) return;
      try {
        await api('lead-delete', { method: 'POST', body: { id } });
        leads = leads.filter((l) => l.id !== id);
        panel.close();
        render();
        refreshBadges();
        toast('Lead deleted.');
      } catch (e) { showError(e); }
    });
  };

  el.addEventListener('click', (e) => {
    const f = e.target.closest('[data-filter]');
    if (f) { filter = f.dataset.filter; render(); return; }
    const tr = e.target.closest('tr[data-id]');
    if (tr) open(tr.dataset.id);
  });
  el.addEventListener('keydown', (e) => { const tr = e.target.closest('tr[data-id]'); if (tr && e.key === 'Enter') open(tr.dataset.id); });
  el.addEventListener('input', (e) => { if (e.target.matches('[data-q]')) { q = e.target.value.trim().toLowerCase(); render(); } });
  render();
};

/* ---------- Reels (client reels edited with Reels Montage PRO on this Mac) ---------- */

const REEL_STATUS = { editing: 'Editing', review: 'Client review', approved: 'Approved', delivered: 'Delivered' };
const REEL_JOB = { prepare: 'Preparing the video', auto: 'Splitting into beats', preview: 'Preview frames', render: 'Rendering the video' };
// Effects that take the place of the big word (keep in step with NOBIG in lib/reels-bridge.js).
const REEL_NOBIG = new Set(['title', 'bracketWord', 'ghostWord', 'noiseWord', 'focusWord', 'ribbon', 'ctaWord', 'typeLine', 'behindWord', 'walls', 'wordSwarm', 'titleWall', 'split', 'uiWall', 'duoCards', 'collage', 'shutter', 'carouselWide', 'flowNodes', 'phaseCards', 'halfBlur', 'mapRoute']);
// Gallery names (bank/gallery.html) that are video effects in the editor.
const REEL_GALLERY_VFX = { backgroundSwap: 'bg', outline: 'outline', clones: 'clones', echo: 'echo', windowUI: 'window', effectsGrid: 'grid', dzoom: 'zoom' };

const fmtT = (s, tenths = false) => {
  s = Math.max(0, +s || 0);
  const m = Math.floor(s / 60);
  const r = s - m * 60;
  return `${m}:${tenths ? r.toFixed(1).padStart(4, '0') : String(Math.floor(r)).padStart(2, '0')}`;
};

const reelJobLine = (job) => {
  if (!job) return html`<span class="muted">—</span>`;
  const p = job.progress || {};
  if (job.status === 'running') {
    const pct = p.total ? ` ${Math.round((100 * p.done) / p.total)}%` : p.of ? ` ${p.step}/${p.of}` : '';
    return html`<span class="job-line"><span class="spinner"></span> ${REEL_JOB[job.kind]}${pct}<small>${p.label || ''}</small></span>`;
  }
  if (job.status === 'failed') return html`<span class="status failed">${job.kind} failed</span>`;
  return html`<span class="muted">${job.kind === 'render' ? 'Rendered' : job.kind === 'preview' ? 'Previewed' : 'Ready'} ${fmtDate(job.finished_at)}</span>`;
};

/** Claude's reply as safe HTML: **bold** becomes bold, everything else stays plain text. */
const chatText = (text) => String(text).split('**').map((part, i) => (i % 2 ? html`<b>${part}</b>` : part));

/** Send a video to the server in 4 MB pieces (works with PHP's default upload limits). */
async function uploadReelVideo(file, onProgress) {
  const id = [...crypto.getRandomValues(new Uint8Array(12))].map((b) => b.toString(16).padStart(2, '0')).join('');
  const CHUNK = 4 * 1024 * 1024;
  let offset = 0;
  onProgress(0);
  while (offset < file.size) {
    for (let attempt = 0; ; attempt++) {
      try {
        const res = await fetch(`${API}?${new URLSearchParams({ action: 'reel-upload', id, offset })}`, {
          method: 'POST', credentials: 'same-origin', body: file.slice(offset, offset + CHUNK),
          headers: { 'X-CSRF-Token': S.csrf, 'Content-Type': 'application/octet-stream', Accept: 'application/json' },
        });
        const data = await res.json().catch(() => null);
        if (res.status === 409 && data?.size != null) { offset = data.size; break; }
        if (!res.ok) throw Object.assign(new Error(data?.error || `Upload failed (${res.status})`), { fatal: res.status < 500 });
        offset = data.size;
        break;
      } catch (e) {
        if (e.fatal || attempt >= 4) throw e;
        await new Promise((r) => setTimeout(r, 1500 * (attempt + 1)));
      }
    }
    onProgress(offset / file.size);
  }
  return id;
}

function newReelPanel(clients) {
  const { el: p, close } = openPanel(html`
    <div class="drawer-head"><h2>New reel</h2><button class="icon-btn" type="button" data-close aria-label="Close">✕</button></div>
    <form class="stack" data-form>
      <label class="field">Title<input type="text" name="title" required maxlength="80" placeholder="Reflekt launch, take 2"></label>
      <label class="field">Client<select name="client">
        <option value="">My own (STUDIIO)</option>
        ${clients.map((c) => html`<option value="${c.id}">${c.name}</option>`)}
        <option value="__other">Someone else…</option></select>
        <span class="hint">Clients come from your leads marked contacted, qualified or won.</span></label>
      <label class="field" data-other hidden>Client name<input type="text" name="clientName" maxlength="120"></label>
      <fieldset class="field-set"><legend>What kind of video is it?</legend>
        <label class="radio"><input type="radio" name="mode" value="raw" checked> <span><b>A raw take.</b> Cut the pauses, stumbles and repeats, and switch the framing.</span></label>
        <label class="radio"><input type="radio" name="mode" value="edited"> <span><b>Already edited.</b> Keep the cuts, only add effects.</span></label>
      </fieldset>
      <label class="field">Video (.mov or .mp4, vertical, one person talking)<input type="file" name="file" accept="video/mp4,video/quicktime,.mov,.mp4,.m4v" required></label>
      <div class="progress" data-progress hidden><i></i><span></span></div>
      <p class="muted small">The video stays on this Mac, in ~/reels-montage-pro/projects. Preparing takes a few minutes: transcript, face, cut-outs.</p>
      <div class="drawer-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Upload and prepare</button></div>
    </form>`);
  const form = $('[data-form]', p);
  form.elements.client.addEventListener('change', () => { $('[data-other]', p).hidden = form.elements.client.value !== '__other'; });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const file = form.elements.file.files[0];
    if (!file) return;
    const btn = $('button[type=submit]', form);
    const bar = $('[data-progress]', p);
    btn.disabled = true;
    bar.hidden = false;
    try {
      const upload = await uploadReelVideo(file, (f) => {
        $('i', bar).style.width = `${Math.round(f * 100)}%`;
        $('span', bar).textContent = `Uploading ${Math.round(f * 100)}% of ${(file.size / 1048576).toFixed(0)} MB`;
      });
      $('span', bar).textContent = 'Starting…';
      const sel = form.elements.client;
      const client = sel.value === '__other' ? { id: '', name: form.elements.clientName.value.trim() }
        : sel.value ? { id: sel.value, name: sel.selectedOptions[0].textContent } : { id: '', name: '' };
      const r = await api('reel-create', { method: 'POST', body: { title: form.elements.title.value, upload, filename: file.name, mode: form.elements.mode.value, client } });
      close();
      location.hash = `#/reels/${r.reel.slug}`;
    } catch (err) {
      showError(err);
      btn.disabled = false;
      bar.hidden = true;
    }
  });
}

PAGES.reels = async (el, page) => {
  const slug = (location.hash.match(/^#\/reels\/([a-z0-9-]+)/) || [])[1];
  if (!S.reels) {
    put(el, html`<div class="page-head"><div><h1>Reels</h1><p>The reel editor runs on the Mac that has Reels Montage PRO. Open the admin there: <code>http://localhost:4380/admin/</code></p></div></div>`);
    return;
  }
  if (slug) return reelEditor(el, page, slug);
  let d = await api('reels');
  if (!page.alive()) return;
  const render = () => put(el, html`
    <div class="page-head"><div><h1>Reels</h1><p>Client reels edited with Reels Montage PRO on this Mac. Each video is split into beats, one per spoken phrase. Pick an effect for each beat, or tell Claude what to change.</p></div>
      <div class="head-actions"><button class="btn btn-primary" type="button" data-new>New reel</button></div></div>
    ${d.reels.length ? html`<table class="table"><thead><tr><th>Reel</th><th>Client</th><th>Status</th><th>Progress</th></tr></thead><tbody>
      ${d.reels.map((r) => html`<tr data-slug="${r.slug}" tabindex="0">
        <td><b>${r.title}</b><div class="sub">${[r.duration ? fmtT(r.duration) : '', r.beats ? plural(r.beats, 'beat') : '', r.rendered ? 'rendered' : ''].filter(Boolean).join(' · ') || 'new'}</div></td>
        <td>${r.client?.name || html`<span class="muted">Own</span>`}</td>
        <td><span class="status reel-${r.status}">${REEL_STATUS[r.status] || r.status}</span></td>
        <td>${reelJobLine(r.job)}</td></tr>`)}
    </tbody></table>` : html`<div class="empty">No reels yet. Click New reel and pick a vertical talking-head video.</div>`}`);
  render();
  el.addEventListener('click', (e) => {
    if (e.target.closest('[data-new]')) newReelPanel(d.clients);
    const tr = e.target.closest('tr[data-slug]');
    if (tr) location.hash = `#/reels/${tr.dataset.slug}`;
  });
  el.addEventListener('keydown', (e) => { const tr = e.target.closest('tr[data-slug]'); if (tr && e.key === 'Enter') location.hash = `#/reels/${tr.dataset.slug}`; });
  page.timers.push(setInterval(async () => {
    if (!page.alive() || !d.reels.some((r) => r.job?.status === 'running')) return;
    try { d = await api('reels'); if (page.alive()) render(); } catch (e) { /* try again next time */ }
  }, 3000));
};

async function reelEditor(el, page, slug) {
  let d = await api('reel', { params: { slug } });
  if (!page.alive()) return;
  const proj = `${d.base}projects/${slug}/`;
  const cat = Object.fromEntries(d.catalog.map((c) => [c.name, c]));
  const fps = d.project.fps || 30;

  /* ----- still preparing (or failed) ----- */
  if (!d.ready || !d.beats) {
    const job = d.jobs.prepare || d.jobs.auto;
    const failed = job?.status === 'failed';
    const p = job?.progress || {};
    put(el, html`
      <div class="page-head"><div><a class="back" href="#/reels">← Reels</a><h1>${d.reel.title}</h1><p>${d.reel.client?.name || 'Own reel'}</p></div></div>
      <div class="card prep">
        ${failed ? html`<h2>Preparing the video failed</h2><p>The engine stopped. Its last lines are below.</p><pre class="log">${job.log || '(no output)'}</pre>
          <p><button class="btn btn-primary" type="button" data-retry>Try again</button></p>`
        : job?.status === 'running' ? html`<h2><span class="spinner"></span> ${p.label || 'Preparing'}…</h2>
          <div class="progress"><i style="width:${Math.round((100 * (p.step || 0)) / (p.of || 6))}%"></i></div>
          <p class="muted">Step ${p.step || 0} of ${p.of || 6}. The engine converts the video, writes the transcript, finds your face and cuts you out frame by frame. A 30-second clip takes a few minutes. You can leave this page.</p>
          ${job.log ? html`<pre class="log small">${job.log}</pre>` : ''}`
        : d.ready ? html`<h2>Almost ready</h2><p>The video is prepared but has no beats yet.</p><p><button class="btn btn-primary" type="button" data-auto>Split it into beats</button></p>`
        : html`<h2>Nothing to show yet</h2><p class="muted">This reel has no prepared video.</p>`}
      </div>`);
    el.addEventListener('click', async (e) => {
      const kind = e.target.closest('[data-retry]') ? (d.jobs.prepare ? 'prepare' : 'auto') : e.target.closest('[data-auto]') ? 'auto' : null;
      if (!kind) return;
      try { await api('reel-job', { method: 'POST', body: { slug, kind } }); route(); } catch (err) { showError(err); }
    });
    if (job?.status === 'running') page.timers.push(setTimeout(() => { if (page.alive()) route(); }, 2500));
    return;
  }

  /* ----- the editor ----- */
  let doc = clone(d.beats);
  let saved = clone(d.beats);
  let version = d.version;
  let sel = -1;
  let view = 'edit';
  let live = true;
  let photos = d.photos;
  let jobs = d.jobs;
  let chat = d.chat;
  let files = d.files;
  const clientName = d.reel.client?.name || 'Own reel';

  put(el, html`
    <div class="page-head reel-head">
      <div><a class="back" href="#/reels">← Reels</a><h1 data-title>${d.reel.title}</h1>
        <p>${clientName} · ${fmtT(d.project.dur)} · ${plural(doc.beats.length, 'beat')}</p></div>
      <div class="head-actions">
        <label class="sr-only" for="reel-status">Status</label>
        <select id="reel-status" data-status>${d.statuses.map((s) => html`<option value="${s}" ${s === d.reel.status ? 'selected' : ''}>${REEL_STATUS[s] || s}</option>`)}</select>
        <button class="btn" type="button" data-details>Details</button>
      </div>
    </div>
    <div class="reel-grid">
      <section class="reel-left" aria-label="Preview">
        <div class="seg" role="group" aria-label="View" data-views></div>
        <div class="stage" data-stage>
          <video data-video playsinline preload="auto" src="${proj}src/main.mp4?v=${files.main}"></video>
          <iframe data-layer="back" title="Effects behind you" sandbox="allow-scripts" tabindex="-1" src="${proj}overlay.html?layer=back"></iframe>
          <iframe data-layer="front" title="Effects in front" sandbox="allow-scripts" tabindex="-1" src="${proj}overlay.html?layer=front"></iframe>
          <video data-final playsinline controls hidden></video>
        </div>
        <div class="stage-ctl" data-ctl>
          <button class="btn btn-sm" type="button" data-play>Play</button>
          <input type="range" min="0" max="${d.project.dur}" step="0.01" value="0" data-seek aria-label="Position">
          <span class="mono" data-time>0:00.0</span>
        </div>
        <label class="check small"><input type="checkbox" data-live checked> Show effects live</label>
        <p class="muted small stage-note">Close to the final look. Video effects and the glass blur only show in Preview frames and the render.</p>
        <p class="stage-err" data-stage-err hidden></p>
        <div class="reel-actions" data-actions></div>
        <div data-sheet></div>
      </section>
      <section class="reel-beats" aria-label="Beats">
        <div class="beats-head"><h2>Beats</h2><span class="muted small">Click a beat to change its big word and effect.</span></div>
        <div class="note warn" data-outside hidden></div>
        <ol class="beats" data-beats></ol>
        <div class="reel-more" data-more></div>
      </section>
      <section class="reel-chat" aria-label="Ask Claude">
        <div class="beats-head"><h2>Ask Claude</h2><span class="muted small">Claude edits this reel’s beats.</span></div>
        <div class="chat-log" data-log></div>
        <form class="chat-form" data-chat-form>
          <textarea name="message" rows="3" maxlength="2000" placeholder="Use a collage with my 4 product photos at 0:07, and less text after 0:20"></textarea>
          <button class="btn btn-primary" type="submit">Send</button>
        </form>
      </section>
    </div>`);

  const video = $('[data-video]', el);
  const finalV = $('[data-final]', el);
  const stageEl = $('[data-stage]', el);
  const layers = { back: $('[data-layer=back]', el), front: $('[data-layer=front]', el) };
  const ready = { back: false, front: false };
  const beatsEl = $('[data-beats]', el);

  /* live preview: the engine's overlay page in two sandboxed frames over the video */
  const fit = () => { const k = stageEl.clientWidth / 1080; stageEl.style.setProperty('--k', k); };
  fit();
  const ro = new ResizeObserver(fit);
  ro.observe(stageEl);
  const post = (msg) => Object.entries(layers).forEach(([k, f]) => { if (ready[k] && f.contentWindow) f.contentWindow.postMessage(msg, '*'); });
  const applyLive = () => post({ type: 'apply', doc, t: video.currentTime });
  const onMsg = (e) => {
    if (!page.alive()) { window.removeEventListener('message', onMsg); ro.disconnect(); return; }
    const layer = Object.keys(layers).find((k) => layers[k].contentWindow === e.source);
    if (!layer || !e.data) return;
    if (e.data.type === 'studiio-ready') {
      ready[layer] = true;
      layers[layer].contentWindow.postMessage({ type: 'apply', doc, t: video.currentTime }, '*');
    } else if (e.data.type === 'studiio-error') {
      const box = $('[data-stage-err]', el);
      box.hidden = false;
      box.textContent = `Preview error (${layer} layer): ${e.data.msg}`;
    }
  };
  window.addEventListener('message', onMsg);

  // Person cut-out: hide the back layer where you are, using the engine's mask for this frame.
  const maskCv = Object.assign(document.createElement('canvas'), { width: 270, height: 480 });
  const maskCx = maskCv.getContext('2d', { willReadFrequently: true });
  const maskCache = new Map();
  let maskWant = -1;
  let maskBusy = false;
  const applyMask = (url) => { layers.back.style.webkitMaskImage = layers.back.style.maskImage = `url(${url})`; };
  const loadMask = () => {
    const fr = maskWant;
    maskBusy = true;
    const img = new Image();
    img.onload = () => {
      maskCx.clearRect(0, 0, 270, 480);
      maskCx.drawImage(img, 0, 0, 270, 480);
      const px = maskCx.getImageData(0, 0, 270, 480);
      const a = px.data;
      for (let k = 0; k < a.length; k += 4) { a[k + 3] = 255 - a[k]; a[k] = a[k + 1] = a[k + 2] = 0; }
      maskCx.putImageData(px, 0, 0);
      const url = maskCv.toDataURL('image/png');
      maskCache.set(fr, url);
      if (maskCache.size > 150) maskCache.delete(maskCache.keys().next().value);
      if (maskWant === fr) applyMask(url);
      maskBusy = false;
      if (maskWant !== fr) loadMask();
    };
    img.onerror = () => { maskBusy = false; };
    img.src = `${proj}masks/m_${String(fr).padStart(5, '0')}.png`;
  };
  const wantMask = (t) => {
    if (!files.masks) return;
    const fr = Math.max(0, Math.min((d.project.frames || 1) - 1, Math.round(t * fps)));
    maskWant = fr;
    if (maskCache.has(fr)) applyMask(maskCache.get(fr));
    else if (!maskBusy) loadMask();
  };

  let lastT = -1;
  let playingIdx = -2;
  const tick = (force = false) => {
    const t = video.currentTime;
    if (!force && Math.abs(t - lastT) < 0.001) return;
    lastT = t;
    if (live) { post({ t }); wantMask(t); }
    $('[data-time]', el).textContent = fmtT(t, true);
    $('[data-seek]', el).value = t;
    const idx = doc.beats.findIndex((b, i) => t >= b.a && (t < b.b || i === doc.beats.length - 1));
    if (idx !== playingIdx) {
      playingIdx = idx;
      $$('.beat', beatsEl).forEach((li) => li.classList.toggle('playing', +li.dataset.i === idx));
    }
  };
  const loop = () => { if (!page.alive()) return; tick(); if (!video.paused) requestAnimationFrame(loop); };
  video.addEventListener('play', () => { $('[data-play]', el).textContent = 'Pause'; requestAnimationFrame(loop); });
  video.addEventListener('pause', () => { $('[data-play]', el).textContent = 'Play'; tick(true); });
  video.addEventListener('seeked', () => tick(true));
  video.addEventListener('loadeddata', () => tick(true));
  const into = (b) => b.a + Math.min(0.7, (b.b - b.a) / 2);
  const seek = (t) => { video.currentTime = Math.max(0, Math.min(d.project.dur - 0.05, t)); if (video.paused) tick(true); };

  /* views: edit (live) / final render */
  const renderViews = () => {
    put($('[data-views]', el), html`<button type="button" class="tab" data-view="edit" aria-pressed="${view === 'edit'}">Editing</button>
      <button type="button" class="tab" data-view="final" aria-pressed="${view === 'final'}" ${files.out ? '' : 'disabled'} title="${files.out ? '' : 'Render the video first'}">Final video</button>`);
    const final = view === 'final' && files.out;
    finalV.hidden = !final;
    [video, layers.back, layers.front].forEach((x) => { x.hidden = !!final; });
    $('[data-ctl]', el).hidden = !!final;
    if (final) { video.pause(); const src = `${proj}out.mp4?v=${files.out}`; if (!finalV.src.endsWith(src)) finalV.src = src; } else finalV.pause();
  };

  /* beats list */
  const effectOptions = (cur) => html`<option value="">No effect</option>
    ${Object.entries(d.groups).map(([g, label]) => html`<optgroup label="${label}">${d.catalog.filter((c) => c.group === g).map((c) => html`<option value="${c.name}" ${c.name === cur ? 'selected' : ''}>${c.name}${c.good ? ' — ' + (c.good.length > 44 ? c.good.slice(0, 42) + '…' : c.good) : ''}</option>`)}</optgroup>`)}`;

  const photoPicker = (b, need) => {
    const chosen = b.fields.photos;
    return html`<div class="field"><span class="field-label">Photos ${need ? html`<span class="hint">(at least ${need})</span>` : html`<span class="hint">(optional)</span>`}</span>
      ${photos.length ? html`<div class="pick-photos">${photos.map((p) => {
        const n = chosen.indexOf(p) + 1;
        return html`<button type="button" class="pick-photo${n ? ' on' : ''}" data-photo="${p}" title="${p}"><img src="${proj}photos/${encodeURIComponent(p)}" alt="" loading="lazy">${n ? html`<b>${n}</b>` : ''}</button>`;
      })}</div>` : ''}
      ${chosen.length < need ? html`<p class="note warn small">This effect needs ${plural(need, 'photo')}${photos.length ? ': click them above in the order you want' : ': upload them first'}. Without them it shows nothing.</p>` : ''}
      <label class="btn btn-sm upload-btn">Upload photos…<input type="file" accept="image/jpeg,image/png,image/webp" multiple data-photo-upload></label></div>`;
  };

  const beatDetail = (b) => {
    const c = cat[b.effect];
    const f = c?.fields || {};
    const nobig = REEL_NOBIG.has(b.effect);
    return html`<div class="beat-detail">
      <p class="beat-hint">${c ? html`<b>${c.name}</b>${c.say ? html` · ${c.say}` : ''}${c.good ? html`. Good for ${c.good}.` : ''}` : 'No effect on this beat.'}
        <button type="button" class="link" data-browse>See all effects</button></p>
      <div class="grid-2">
        <label class="field">Big word<input type="text" data-k="big" value="${b.big}" maxlength="40" placeholder="none"></label>
        <label class="field">Small line above<input type="text" data-k="eyebrow" value="${b.eyebrow}" maxlength="80" placeholder="none"></label>
      </div>
      <div class="checks">
        <label class="check"><input type="checkbox" data-k="accent" ${b.accent ? 'checked' : ''}> Accent colour</label>
        <label class="check"><input type="checkbox" data-k="behind" ${b.behind ? 'checked' : ''}> Behind the head</label>
        ${nobig && b.big ? html`<span class="muted small">This effect replaces the big word (the accent colour still applies to word effects).</span>` : ''}
      </div>
      ${f.s ? html`<label class="field">${f.s}<input type="text" data-f="say" value="${b.fields.say}" maxlength="140"></label>` : ''}
      ${f.i ? html`<label class="field">${f.i}<textarea data-f="items" rows="3">${b.fields.items.join('\n')}</textarea></label>` : ''}
      ${f.n ? html`<label class="field">${f.n}<input type="text" data-f="number" value="${b.fields.number}" maxlength="14"></label>` : ''}
      ${'p' in f ? photoPicker(b, f.p) : ''}
      ${b.effect === 'cta' ? html`<p class="muted small">Uses the code word under Ending, below the beats.</p>` : ''}
    </div>`;
  };

  const renderBeats = () => {
    put(beatsEl, html`${doc.beats.map((b, i) => {
      const needs = cat[b.effect]?.fields?.p || 0;
      const short = needs && b.fields.photos.length < needs;
      return html`<li class="beat${i === sel ? ' open' : ''}${i === playingIdx ? ' playing' : ''}" data-i="${i}">
        <div class="beat-row" data-toggle>
          <button type="button" class="beat-time mono" data-seek-to="${b.a}" title="Play from ${fmtT(b.a, true)}">${fmtT(b.a)}</button>
          <div class="beat-text"><span class="beat-said">${b.text || '…'}</span>
            ${b.big ? html`<span class="beat-big${b.accent ? ' accent' : ''}${REEL_NOBIG.has(b.effect) ? ' off' : ''}">${b.big}</span>` : ''}
            ${short ? html`<span class="beat-warn">needs photos</span>` : ''}</div>
          <select class="beat-fx" data-k="effect" aria-label="Effect for beat ${i + 1}">${effectOptions(b.effect)}</select>
        </div>
        ${i === sel ? beatDetail(b) : ''}
      </li>`;
    })}`);
  };

  const renderMore = () => {
    put($('[data-more]', el), html`
      <div class="reel-section">
        <h3>Ending</h3>
        <div class="grid-2">
          <label class="field">Code word people comment<input type="text" data-cta="word" value="${doc.cta.word}" maxlength="20" placeholder="none: ends with “follow for more”"></label>
          <label class="field">What they get<input type="text" data-cta="gift" value="${doc.cta.gift}" maxlength="40"></label>
        </div>
      </div>
      <div class="reel-section">
        <h3>Video effects</h3>
        <p class="muted small">These change the picture itself (new background, outline, clones…). 1.5 to 4 seconds each, at most 3. They show in Preview frames and the render, not in the live preview.</p>
        ${doc.vfx.length ? html`<div class="vfx-list">${doc.vfx.map((v, i) => {
          const def = d.vfx.find((x) => x.kind === v.kind) || { opts: [] };
          return html`<div class="vfx-row" data-v="${i}">
            <select data-vk="kind" aria-label="Kind">${d.vfx.map((x) => html`<option value="${x.kind}" ${x.kind === v.kind ? 'selected' : ''}>${x.label}</option>`)}</select>
            <label>from<input type="number" step="0.1" min="0" data-vk="a" value="${v.a}"></label>
            <label>to<input type="number" step="0.1" min="0" data-vk="b" value="${v.b}"></label>
            ${def.opts.includes('color') ? html`<input type="color" data-vk="color" value="${v.color || '#F2632A'}" aria-label="Colour">` : ''}
            ${def.opts.includes('caption') ? html`<input type="text" data-vk="caption" value="${v.caption || ''}" maxlength="60" placeholder="Caption" aria-label="Caption">` : ''}
            <button type="button" class="icon-btn" data-vfx-del="${i}" aria-label="Remove">✕</button></div>`;
        })}</div>` : ''}
        <button type="button" class="btn btn-sm" data-vfx-add ${doc.vfx.length >= 3 ? 'disabled' : ''}>Add a video effect here (${fmtT(video.currentTime, true)})</button>
      </div>
      <div class="reel-section">
        <h3>Photos for this reel</h3>
        <p class="muted small">For collage, orbit, phone and other photo effects. Pick them inside a beat.</p>
        ${photos.length ? html`<div class="pick-photos static">${photos.map((p) => html`<span class="pick-photo"><img src="${proj}photos/${encodeURIComponent(p)}" alt="${p}" loading="lazy"></span>`)}</div>` : ''}
        <label class="btn btn-sm upload-btn">Upload photos…<input type="file" accept="image/jpeg,image/png,image/webp" multiple data-photo-upload></label>
      </div>`);
  };

  const touch = (rebuild = false) => {
    setDirty(!same(doc, saved));
    if (rebuild) renderBeats();
    applyLive();
  };

  /* jobs: preview frames and render */
  const busy = () => Object.values(jobs).some((j) => j?.status === 'running') || chat.running;
  const renderActions = () => {
    const pv = jobs.preview;
    const rd = jobs.render;
    const run = Object.values(jobs).find((j) => j?.status === 'running');
    const p = run?.progress || {};
    const overlapStop = rd?.status === 'failed' && /overlap/i.test(rd.log || '');
    put($('[data-actions]', el), html`
      <div class="row-btns">
        <button class="btn" type="button" data-job="preview" ${busy() ? 'disabled' : ''}>Preview frames</button>
        <button class="btn btn-accent" type="button" data-job="render" ${busy() ? 'disabled' : ''}>Render video</button>
        ${files.out ? html`<a class="btn" href="${proj}out.mp4?download=1&v=${files.out}">Download</a>` : ''}
      </div>
      ${run ? html`<div class="job-box"><span class="spinner"></span> ${REEL_JOB[run.kind]}: ${p.label || ''}
        ${p.total ? html`<div class="progress"><i style="width:${Math.round((100 * p.done) / p.total)}%"></i><span>${Math.round((100 * p.done) / p.total)}%</span></div>` : ''}</div>` : ''}
      ${!run && rd?.status === 'failed' ? html`<div class="job-box bad">
        <b>${overlapStop ? 'The render stopped: some text overlaps.' : 'The render failed.'}</b>
        ${overlapStop ? html`<p class="small">Run Preview frames to see where, then change those beats. Or render anyway if the overlap is on purpose.</p>` : ''}
        <pre class="log small">${rd.log}</pre>
        ${overlapStop ? html`<button class="btn btn-sm" type="button" data-job="render" data-force>Render anyway</button>` : ''}</div>` : ''}
      ${!run && pv?.status === 'failed' ? html`<div class="job-box bad"><b>Preview frames failed.</b><pre class="log small">${pv.log}</pre></div>` : ''}
      ${!run && rd?.status === 'done' && files.out ? html`<p class="small muted">Rendered ${fmtDate(rd.finished_at)}. Watch it under Final video.</p>` : ''}`);
  };

  const renderSheet = () => {
    const pv = jobs.preview;
    const box = $('[data-sheet]', el);
    if (!files.preview || pv?.status !== 'done' || !pv.times?.length) { put(box, ''); return; }
    const n = pv.times.length;
    const over = pv.overlaps || [];
    put(box, html`<div class="reel-section">
      <h3>Preview frames <span class="muted small">${fmtDate(pv.finished_at)}</span></h3>
      <div class="sheet">${pv.times.map((t, i) => html`<button type="button" class="sheet-tile" data-seek-to="${t}" title="Go to ${fmtT(t, true)}"
        style="background-image:url('${proj}preview.jpg?v=${files.preview}');background-size:${n * 100}% 100%;background-position:${n > 1 ? (i / (n - 1)) * 100 : 0}% 0"><span>${fmtT(t, true)}</span></button>`)}</div>
      ${over.length ? html`<div class="note warn small"><b>${plural(over.length, 'overlap')} found.</b> The render stops until they’re fixed (or you render anyway).
        <ul>${over.slice(0, 8).map((o) => html`<li><button type="button" class="link" data-seek-to="${o.t}">${fmtT(o.t, true)}</button> ${o.what}</li>`)}</ul></div>`
      : html`<p class="small muted">No overlaps: text never covers the face or other text.</p>`}
    </div>`);
  };

  /* chat */
  const renderChat = () => {
    const log = $('[data-log]', el);
    put(log, html`${chat.messages.length ? '' : html`<div class="chat-empty">
        <p>Tell Claude what to change, in your own words or with effect names. For example:</p>
        ${['Make the hook stronger: a title effect on the first beat with the word EDIT', 'Add a collage of my photos where I talk about clients', 'Less text: no big words after 0:15', 'Pick different effects so nothing repeats'].map((x) => html`<button type="button" class="chip" data-example>${x}</button>`)}
      </div>`}
      ${chat.messages.map((m) => m.role === 'user'
        ? html`<div class="msg me">${m.text}</div>`
        : html`<div class="msg claude${m.error ? ' bad' : ''}">${m.text ? html`<div>${chatText(m.text)}</div>` : ''}${m.error ? html`<div class="err">${m.error}</div>` : ''}
            ${m.changed?.length ? html`<div class="chips small">${m.changed.map((t) => typeof t === 'number' ? html`<button type="button" class="chip" data-goto="${t}">${fmtT(t, true)}</button>` : html`<span class="chip">${t === 'vfx' ? 'video effects' : 'ending'}</span>`)}</div>` : ''}
            ${m.cost != null ? html`<div class="muted small">$${m.cost.toFixed(2)}</div>` : ''}</div>`)}
      ${chat.running ? html`<div class="msg claude"><span class="spinner"></span> Claude is editing…${chat.steps?.length ? html`<div class="muted small">${chat.steps.at(-1)}</div>` : ''}</div>` : ''}`);
    log.scrollTop = log.scrollHeight;
    const form = $('[data-chat-form]', el);
    $('button', form).disabled = !!chat.running || S.claude !== 'local';
    if (S.claude !== 'local') form.elements.message.placeholder = 'Ask Claude is off on this Mac (claude.runner is not "local").';
  };

  const reloadBeats = async () => {
    const r = await api('reel', { params: { slug } });
    if (!page.alive()) return;
    d = r;
    doc = clone(r.beats);
    saved = clone(r.beats);
    version = r.version;
    photos = r.photos;
    setDirty(false);
    $('[data-outside]', el).hidden = true;
    renderBeats();
    renderMore();
    applyLive();
  };

  /* save */
  page.save = async () => {
    const r = await api('reel-beats', { method: 'POST', body: { slug, doc, version } });
    doc = clone(r.doc);
    saved = clone(r.doc);
    version = r.version;
    setDirty(false);
    warnings(r.warnings);
    if (!r.warnings?.length) toast('Saved.');
    renderBeats();
    renderMore();
    applyLive();
  };

  /* events */
  el.addEventListener('click', async (e) => {
    const t = e.target;
    const seekTo = t.closest('[data-seek-to]');
    if (seekTo) {
      e.preventDefault();
      const li0 = seekTo.closest('.beat');
      seek(li0 ? into(doc.beats[+li0.dataset.i]) : +seekTo.dataset.seekTo);
      const li = seekTo.closest('.beat');
      if (li && +li.dataset.i !== sel) { sel = +li.dataset.i; renderBeats(); }
      return;
    }
    if (t.closest('[data-play]')) { if (video.paused) video.play(); else video.pause(); return; }
    const v = t.closest('[data-view]');
    if (v && !v.disabled) { view = v.dataset.view; renderViews(); return; }
    const go = t.closest('[data-goto]');
    if (go) { const i = doc.beats.findIndex((b) => Math.abs(b.a - +go.dataset.goto) < 0.01); if (i >= 0) { sel = i; renderBeats(); seek(into(doc.beats[i])); $(`.beat[data-i="${i}"]`, beatsEl)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } return; }
    if (t.closest('[data-example]')) { const f = $('[data-chat-form]', el); f.elements.message.value = t.closest('[data-example]').textContent; f.elements.message.focus(); return; }
    const row = t.closest('[data-toggle]');
    if (row && !t.closest('select, button, input')) {
      const i = +row.closest('.beat').dataset.i;
      sel = sel === i ? -1 : i;
      renderBeats();
      if (sel === i) seek(into(doc.beats[i]));
      return;
    }
    const ph = t.closest('[data-photo]');
    if (ph) {
      const b = doc.beats[sel];
      const list = b.fields.photos;
      const k = list.indexOf(ph.dataset.photo);
      if (k >= 0) list.splice(k, 1); else list.push(ph.dataset.photo);
      touch(true);
      return;
    }
    if (t.closest('[data-browse]')) { browseReelEffects(d.base, cat, (name, vfxKind) => {
      if (vfxKind) { addVfx(vfxKind); return; }
      doc.beats[sel].effect = name;
      touch(true);
    }); return; }
    if (t.closest('[data-vfx-add]')) { addVfx('bg'); return; }
    const del = t.closest('[data-vfx-del]');
    if (del) { doc.vfx.splice(+del.dataset.vfxDel, 1); renderMore(); touch(); return; }
    const job = t.closest('[data-job]');
    if (job && !job.disabled) {
      try {
        if (current.dirty) await page.save();
        const r = await api('reel-job', { method: 'POST', body: { slug, kind: job.dataset.job, force: job.hasAttribute('data-force') } });
        jobs[r.job.kind] = r.job;
        renderActions();
      } catch (err) { showError(err); }
      return;
    }
    if (t.closest('[data-details]')) { reelDetails(d, slug, (meta) => { d.reel = meta; $('[data-title]', el).textContent = meta.title; }); return; }
    if (t.closest('[data-reload-beats]')) { reloadBeats(); }
  });

  const addVfx = (kind) => {
    if (doc.vfx.length >= 3) { toast('A reel takes at most 3 video effects.', 'warn'); return; }
    const t0 = Math.round(video.currentTime * 10) / 10;
    const beat = doc.beats.find((b) => t0 >= b.a && t0 < b.b);
    const a = beat ? beat.a : t0;
    doc.vfx.push({ a, b: Math.round(Math.min(d.project.dur, a + 2.5) * 10) / 10, kind, ...(kind === 'bg' || kind === 'outline' || kind === 'window' ? { color: kind === 'bg' ? '#F2632A' : '#9DB9CC' } : {}), ...(kind === 'window' || kind === 'grid' ? { caption: '' } : {}) });
    doc.vfx.sort((x, y) => x.a - y.a);
    renderMore();
    touch();
  };

  el.addEventListener('input', (e) => {
    const t = e.target;
    const li = t.closest('.beat');
    if (li && t.dataset.k && t.dataset.k !== 'effect') {
      const b = doc.beats[+li.dataset.i];
      b[t.dataset.k] = t.type === 'checkbox' ? t.checked : t.value;
      if (t.dataset.k === 'big') b.big = t.value.toUpperCase();
      touch();
      if (t.type === 'checkbox') renderBeats();
      return;
    }
    if (li && t.dataset.f) {
      const b = doc.beats[+li.dataset.i];
      b.fields[t.dataset.f] = t.dataset.f === 'items' ? t.value.split('\n').map((s) => s.trim()).filter(Boolean) : t.value;
      touch();
      return;
    }
    if (t.dataset.cta) { doc.cta[t.dataset.cta] = t.value; touch(); return; }
    const vr = t.closest('[data-v]');
    if (vr && t.dataset.vk) {
      const v = doc.vfx[+vr.dataset.v];
      v[t.dataset.vk] = t.type === 'number' ? Math.max(0, +t.value || 0) : t.value;
      touch();
      if (t.dataset.vk === 'kind') renderMore();
      return;
    }
    if (t.matches('[data-seek]')) { video.pause(); seek(+t.value); return; }
    if (t.matches('[data-live]')) {
      live = t.checked;
      layers.back.style.visibility = layers.front.style.visibility = live ? '' : 'hidden';
      tick(true);
    }
  });

  el.addEventListener('change', async (e) => {
    const t = e.target;
    if (t.matches('.beat-fx')) {
      const i = +t.closest('.beat').dataset.i;
      doc.beats[i].effect = t.value;
      sel = i;
      touch(true);
      seek(into(doc.beats[i]));
      return;
    }
    if (t.matches('[data-status]')) {
      try { const r = await api('reel-meta', { method: 'POST', body: { slug, status: t.value } }); d.reel = r.reel; toast(`Marked as ${REEL_STATUS[t.value]}.`); } catch (err) { showError(err); }
      return;
    }
    if (t.matches('[data-photo-upload]')) {
      const list = [...t.files];
      t.value = '';
      for (const file of list) {
        try {
          const form = new FormData();
          form.append('file', file);
          form.append('slug', slug);
          const r = await api('reel-photo', { method: 'POST', form, params: { slug } });
          photos = r.photos;
          if (sel >= 0 && 'p' in (cat[doc.beats[sel].effect]?.fields || {})) doc.beats[sel].fields.photos.push(r.name);
        } catch (err) { showError(err); }
      }
      renderBeats();
      renderMore();
      touch();
    }
  });

  $('[data-chat-form]', el).addEventListener('submit', async (e) => {
    e.preventDefault();
    const box = e.target.elements.message;
    const message = box.value.trim();
    if (!message) return;
    try {
      if (current.dirty) await page.save();
      chat = await api('reel-chat', { method: 'POST', body: { slug, message } }).then((r) => r.chat);
      box.value = '';
      renderChat();
      renderActions();
    } catch (err) { showError(err); }
  });
  $('[data-chat-form] textarea', el).addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); e.target.form.requestSubmit(); }
  });

  /* keep up with jobs, Claude, and edits made outside this page */
  let polling = false;
  page.timers.push(setInterval(async () => {
    if (!page.alive() || polling) return;
    const active = busy();
    if (!active && Date.now() - (page.lastPoll || 0) < 8000) return;
    polling = true;
    page.lastPoll = Date.now();
    try {
      const st = await api('reel-status', { params: { slug } });
      if (!page.alive()) return;
      const was = JSON.stringify([jobs, chat.running, chat.messages.length, files]);
      jobs = st.jobs;
      files = st.files;
      const chatChanged = st.chat.running !== chat.running || st.chat.messages.length !== chat.messages.length || JSON.stringify(st.chat.steps) !== JSON.stringify(chat.steps);
      chat = st.chat;
      if (chatChanged) renderChat();
      if (was !== JSON.stringify([jobs, chat.running, chat.messages.length, files])) { renderActions(); renderSheet(); renderViews(); }
      if (st.version && st.version !== version) {
        if (!current.dirty) { await reloadBeats(); toast('Beats updated.'); }
        else {
          const box = $('[data-outside]', el);
          box.hidden = false;
          put(box, html`Claude (or someone in Claude Code) changed this reel’s beats while you were editing. <button type="button" class="btn btn-sm" data-reload-beats>Load their version</button> <span class="small">(your unsaved changes here would be lost)</span>`);
        }
      }
    } catch (err) { /* next time */ } finally { polling = false; }
  }, 2000));

  renderViews();
  renderBeats();
  renderMore();
  renderActions();
  renderSheet();
  renderChat();
}

/** The live effect gallery (bank/gallery.html). Open an effect there, then use it for the beat. */
function browseReelEffects(base, cat, onPick) {
  const { el: m, close } = openPanel(html`
    <div class="drawer-head"><h2>All effects</h2>
      <div class="head-actions"><span class="muted small" data-pick-note>Click an effect to open it.</span>
        <button class="btn btn-primary" type="button" data-use disabled>Use this effect</button>
        <button class="icon-btn" type="button" data-close aria-label="Close">✕</button></div></div>
    <iframe class="gallery-frame" title="Effect gallery" src="${base}bank/gallery.html"></iframe>`, { modal: true });
  m.classList.add('modal-gallery');
  const frame = $('iframe', m);
  const btn = $('[data-use]', m);
  const note = $('[data-pick-note]', m);
  let pick = null;
  const timer = setInterval(() => {
    if (!m.isConnected) { clearInterval(timer); return; }
    let name = '';
    try {
      const gd = frame.contentDocument;
      if (gd?.getElementById('player')?.classList.contains('on')) name = (gd.getElementById('pn')?.textContent || '').trim();
    } catch (e) { /* not loaded yet */ }
    const base = name.split(' ')[0];
    const vfx = REEL_GALLERY_VFX[base];
    pick = cat[base] ? { name: base } : vfx ? { vfx } : null;
    btn.disabled = !pick;
    btn.textContent = pick?.vfx ? `Add “${base}” as a video effect` : pick ? `Use ${base} for this beat` : 'Use this effect';
    note.textContent = name && !pick ? `${name} isn’t available in the editor yet.` : name ? '' : 'Click an effect to open it.';
  }, 250);
  btn.addEventListener('click', () => { if (!pick) return; onPick(pick.name, pick.vfx); close(); });
}

function reelDetails(d, slug, onSaved) {
  const r = d.reel;
  const { el: p, close } = openPanel(html`
    <div class="drawer-head"><h2>Reel details</h2><button class="icon-btn" type="button" data-close aria-label="Close">✕</button></div>
    <label class="field">Title<input type="text" name="title" value="${r.title}" maxlength="80"></label>
    <label class="field">Client name<input type="text" name="client" value="${r.client?.name || ''}" maxlength="120" placeholder="Own reel"></label>
    <dl class="dl">
      <dt>Source video</dt><dd>${r.source?.name || '—'} ${r.source?.mode === 'edited' ? '(already edited)' : r.source ? '(raw take)' : ''}</dd>
      <dt>Created</dt><dd>${fmtDate(r.created_at) || '—'}</dd>
      <dt>Folder</dt><dd><code>${d.project.path}</code></dd>
      <dt>Length</dt><dd>${fmtT(d.project.dur, true)} · ${d.project.frames} frames</dd>
    </dl>
    <div class="note small">In Claude Code you can edit the same reel: <code>cd ~/reels-montage-pro</code>, start <code>claude</code>, and ask it to change <code>projects/${slug}/beats.json</code>. This page picks up the changes by itself.</div>
    <div class="drawer-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="button" data-save-meta>Save</button></div>`);
  $('[data-save-meta]', p).addEventListener('click', async () => {
    try {
      const name = $('[name=client]', p).value.trim();
      const res = await api('reel-meta', { method: 'POST', body: { slug, title: $('[name=title]', p).value, client: { id: name === (r.client?.name || '') ? r.client?.id || '' : '', name } } });
      onSaved(res.reel);
      close();
      toast('Saved.');
    } catch (e) { showError(e); }
  });
}

/* ---------- Ask Claude ---------- */

const EXAMPLES = [
  'Translate any copy that is missing in Arabic.',
  'Move the MONS project to the top of Selected work.',
  'Add the Reflekt photo (reflekt.jpg) to Selected work as a Wellness project for Branding & Production, with Arabic titles.',
  'Proofread all the English copy and fix typos only.',
];

async function submitTask(form, after) {
  const prompt = form.prompt.value.trim();
  if (!prompt) { form.prompt.focus(); return; }
  const btn = $('button[type=submit]', form);
  btn.disabled = true;
  try {
    const { task } = await api('task', { method: 'POST', body: { prompt } });
    form.prompt.value = '';
    toast(task.status === 'failed' ? task.error : 'Sent to Claude Code.', task.status === 'failed' ? 'bad' : 'ok');
    after && after(task);
    refreshBadges();
  } catch (e) { showError(e); } finally { btn.disabled = false; }
}

const diffView = (diff) => html`<pre class="diff">${(diff || '(no text changes)').split('\n').map((l) => html`<span class="${l.startsWith('+') && !l.startsWith('+++') ? 'add' : l.startsWith('-') && !l.startsWith('---') ? 'del' : l.startsWith('@@') ? 'hunk' : ''}">${l || ' '}</span>`)}</pre>`;

PAGES.claude = async (el, page) => {
  let { runner, tasks } = await api('tasks');
  if (!page.alive()) return;
  const diffs = {};
  const RUNNING = ['running', 'queued'];

  const taskCard = (t) => html`
    <div class="card task" data-task="${t.id}">
      <div class="task-head">
        <p class="task-prompt">${t.prompt}</p>
        <span class="status ${t.status}">${RUNNING.includes(t.status) ? html`<span class="spinner"></span> ` : ''}${t.status === 'review' ? 'ready to review' : t.status}</span>
      </div>
      <div class="task-meta">${fmtDate(t.created_at)} · ${t.runner === 'github' ? 'GitHub Actions' : 'this Mac'}${t.cost_usd != null ? ` · $${t.cost_usd.toFixed(2)}` : ''}${t.files?.length ? ` · ${plural(t.files.length, 'file')} changed` : ''}
        ${t.run_url ? html` · <a href="${t.run_url}" target="_blank" rel="noopener">run log ↗</a>` : ''}${t.pr?.url ? html` · <a href="${t.pr.url}" target="_blank" rel="noopener">pull request ↗</a>` : ''}</div>
      ${RUNNING.includes(t.status) && t.steps?.length ? html`<ul class="steps">${t.steps.slice(-8).map((s) => html`<li>› ${s}</li>`)}</ul>` : ''}
      ${t.result ? html`<p class="result">${t.result}</p>` : ''}
      ${t.error ? html`<p class="note warn" style="margin:0">${t.error}</p>` : ''}
      ${t.status === 'review' ? html`
        ${diffs[t.id] ? diffs[t.id].map((d) => html`<details open><summary class="mono">${d.path}</summary>${diffView(d.diff)}</details>`) : html`<p class="muted" style="margin:0">Loading changes…</p>`}
        <div class="head-actions"><button class="btn btn-accent" type="button" data-publish>${t.runner === 'github' ? 'Merge & publish' : 'Publish these changes'}</button><button class="btn" type="button" data-discard-task>Discard</button></div>` : ''}
    </div>`;

  const render = () => {
    put(el, html`
      <div class="page-head"><div><h1>Ask Claude</h1>
        <p>Describe a change in plain words. Claude Code edits the same content files this panel uses (and the code, if needed). You review what it changed before anything goes live.</p></div></div>
      ${runner === 'off' ? html`<div class="note warn">Ask Claude is turned off. Set <code>claude.runner</code> to <code>"local"</code> (this Mac) or <code>"github"</code> (online) in config.php.</div>` : html`
      <form class="card ask" data-ask>
        <label class="sr-only" for="ask-prompt">What should Claude change?</label>
        <textarea id="ask-prompt" name="prompt" placeholder="What should Claude change on the site?"></textarea>
        <div class="examples">${EXAMPLES.map((x) => html`<button type="button" data-example>${x}</button>`)}</div>
        <div class="ask-foot"><span class="muted">${runner === 'github' ? 'Runs Claude Code in GitHub Actions and opens a pull request.' : 'Runs Claude Code on this Mac in the website folder.'} Model: ${S.model}.</span>
          <button class="btn btn-accent" type="submit">Send to Claude</button></div>
      </form>`}
      <div class="rows section-gap">${tasks.length ? tasks.map(taskCard) : html`<div class="empty">No tasks yet.</div>`}</div>`);
  };

  const refreshTask = async (id) => {
    const { task, diff } = await api('task', { params: { id } });
    if (!page.alive()) return;
    if (diff?.length || task.status === 'review') diffs[id] = diff;
    tasks = tasks.map((t) => (t.id === id ? task : t));
    const card = $(`[data-task="${id}"]`, el);
    if (card) card.outerHTML = String(taskCard(task));
  };

  el.addEventListener('submit', (e) => {
    if (!e.target.matches('[data-ask]')) return;
    e.preventDefault();
    submitTask(e.target, (task) => { tasks.unshift(task); render(); });
  });
  el.addEventListener('click', async (e) => {
    const ex = e.target.closest('[data-example]');
    if (ex) { const t = $('#ask-prompt', el); t.value = ex.textContent; t.focus(); return; }
    const card = e.target.closest('[data-task]');
    if (!card) return;
    const id = card.dataset.task;
    const t = tasks.find((x) => x.id === id);
    try {
      if (e.target.closest('[data-publish]')) {
        if (!confirm(t.runner === 'github' ? 'Merge this pull request? Hostinger will put it live.' : 'Publish these changes? This commits them and pushes to GitHub, and Hostinger puts them live.')) return;
        const r = await api('task-publish', { method: 'POST', body: { id } });
        toast(r.log.join(' '));
        await refreshTask(id);
        refreshBadges();
      } else if (e.target.closest('[data-discard-task]')) {
        if (!confirm('Throw away the changes Claude made for this task?')) return;
        await api('task-discard', { method: 'POST', body: { id } });
        toast('Changes discarded.');
        await refreshTask(id);
        refreshBadges();
      }
    } catch (err) { showError(err); }
  });

  // Keep running tasks up to date; load the changes of tasks waiting for review.
  page.timers.push(setInterval(() => {
    tasks.filter((t) => RUNNING.includes(t.status)).forEach((t) => refreshTask(t.id).catch(() => {}));
    if (!tasks.some((t) => RUNNING.includes(t.status))) refreshBadges();
  }, 2500));
  render();
  tasks.filter((t) => t.status === 'review').forEach((t) => refreshTask(t.id).catch(() => {}));
};

/* ---------- Publish ---------- */

PAGES.publish = async (el, page) => {
  let d = await api('changes');
  if (!page.alive()) return;
  const open = {};

  const render = (log) => {
    if (d.backend === 'github') {
      put(el, html`
        <div class="page-head"><div><h1>Publish</h1><p>With the online backend every save is committed to GitHub (<code>${d.branch}</code>) right away, and Hostinger deploys it within about a minute. Recent changes:</p></div></div>
        <div class="card" style="padding:0">${d.commits.map((c) => html`<div class="change" style="grid-template-columns:auto 1fr auto"><span class="mono muted">${c.sha}</span><span>${c.message}<br><span class="muted" style="font-size:13px">${c.author} · ${fmtDate(c.date)}</span></span><a href="${c.url}" target="_blank" rel="noopener">view ↗</a></div>`)}</div>`);
      return;
    }
    const groups = [['content', 'Content'], ['image', 'Images'], ['code', 'Code (changed by Claude Code or by hand)']];
    put(el, html`
      <div class="page-head"><div><h1>Publish</h1><p>Your saves and Claude Code’s edits wait here as changes in this folder. Publishing commits the ones you tick, pushes to GitHub, and Hostinger puts them live.</p></div></div>
      ${d.branch !== d.liveBranch ? html`<div class="note warn" style="margin-bottom:14px">This folder is on branch <code>${d.branch}</code>. The live site deploys from <code>${d.liveBranch}</code>, so publishing here won’t change studiio.co until that branch is merged.</div>` : ''}
      ${d.unpushed ? html`<div class="note warn" style="margin-bottom:14px">${plural(d.unpushed, 'commit')} on <code>${d.branch}</code> ${d.unpushed === 1 ? 'is' : 'are'} not pushed to GitHub yet. Publishing will push ${d.unpushed === 1 ? 'it' : 'them'} too.</div>` : ''}
      ${log ? html`<pre class="log" style="margin-bottom:14px">${log.join('\n')}</pre>` : ''}
      ${d.items.length ? html`
        ${groups.map(([kind, name]) => {
          const items = d.items.filter((i) => i.kind === kind);
          return items.length ? html`<h2 style="font-size:15px;margin:18px 0 8px">${name}</h2><div class="card" style="padding:0">${items.map((i) => html`
            <div>
              <div class="change">
                <input type="checkbox" data-path="${i.path}" ${kind !== 'code' ? 'checked' : ''} aria-label="Include ${i.path}">
                <button class="path" type="button" data-open="${i.path}">${i.path}</button>
                <span class="head-actions"><span class="status ${i.status === 'deleted' ? 'lost' : i.status === 'added' ? 'won' : 'review'}">${i.status}</span>
                <button class="btn btn-sm" type="button" data-undo="${i.path}">Discard</button></span>
              </div>
              ${open[i.path] !== undefined ? html`<div style="padding:0 12px 12px">${i.kind === 'image' ? html`<img src="../${i.path}" alt="" style="max-height:220px;border-radius:8px">` : diffView(open[i.path])}</div>` : ''}
            </div>`)}</div>` : '';
        })}
        <div class="card section-gap" style="display:grid;gap:12px">
          <label class="field">What changed? <span class="hint">Saved as the commit message.</span><input type="text" data-message value="${suggestMessage(d.items)}"></label>
          <div><button class="btn btn-accent" type="button" data-publish>Publish selected</button></div>
        </div>` : html`<div class="empty">No unpublished changes. Everything in this folder is committed${d.branch === d.liveBranch && !d.unpushed ? ' and matches what’s live' : ''}.</div>`}`);
  };

  el.addEventListener('click', async (e) => {
    const openBtn = e.target.closest('[data-open]');
    const undo = e.target.closest('[data-undo]');
    try {
      if (openBtn) {
        const p = openBtn.dataset.open;
        if (open[p] !== undefined) delete open[p];
        else open[p] = (await api('diff', { params: { path: p } })).diff;
        render();
      } else if (undo) {
        if (!confirm(`Discard the change to ${undo.dataset.undo}? It goes back to the published version.`)) return;
        await api('discard', { method: 'POST', body: { path: undo.dataset.undo } });
        d = await api('changes');
        render();
        refreshBadges();
      } else if (e.target.closest('[data-publish]')) {
        const paths = $$('input[data-path]:checked', el).map((c) => c.dataset.path);
        if (!paths.length) { toast('Tick at least one change.', 'bad'); return; }
        if (!confirm(`Publish ${plural(paths.length, 'change')}? This commits and pushes to GitHub, and Hostinger puts it live.`)) return;
        e.target.disabled = true;
        const r = await api('publish', { method: 'POST', body: { paths, message: $('[data-message]', el).value } });
        d = await api('changes');
        render(r.log);
        toast(r.pushed ? 'Published.' : 'Committed, but not pushed. See the log.', r.pushed ? 'ok' : 'warn');
        refreshBadges();
      }
    } catch (err) { showError(err); }
  });
  render();
};

function suggestMessage(items) {
  const names = { 'content/work.json': 'selected work', 'content/reel.json': 'hero reel', 'content/copy.json': 'copy', 'content/clients.json': 'client logos', 'content/services.json': 'services', 'content/articles.json': 'articles', 'content/taxonomy.json': 'sectors' };
  const parts = [...new Set(items.map((i) => names[i.path] || (i.kind === 'image' ? 'images' : null)).filter(Boolean))];
  return parts.length ? 'Update ' + parts.join(', ') : 'Update site';
}

/* ---------- Raw JSON ---------- */

PAGES.raw = async (el, page) => {
  let file = new URLSearchParams(location.hash.split('?')[1] || '').get('file') || 'services';
  let version = null;
  let original = '';

  const load = async () => {
    let broken = '';
    try {
      const r = await api('content', { params: { file } });
      version = r.version;
      original = JSON.stringify(r.data, null, 2);
    } catch (e) {
      if (e.data?.raw === undefined) throw e;
      // The file isn't valid JSON: show its text so it can be repaired here.
      version = e.data.version;
      original = e.data.raw;
      broken = e.message;
    }
    if (!page.alive()) return;
    put(el, html`
      <div class="page-head"><div><h1>Raw JSON</h1><p>Edit any content file directly: services, articles, sectors and categories, or anything the other pages don’t cover. The same checks run when you save, so a mistake can’t break the site.</p></div></div>
      <div class="toolbar"><select data-file aria-label="Content file">${Object.entries(S.files).map(([k, v]) => html`<option value="${k}" ${k === file ? 'selected' : ''}>${v} · content/${k}.json</option>`)}</select></div>
      ${broken ? html`<div class="note warn" style="margin-bottom:12px">${broken}</div>` : ''}
      <textarea class="mono" data-json spellcheck="false" style="min-height:60vh;font-size:13px;white-space:pre">${original}</textarea>`);
    setDirty(false);
  };
  el.addEventListener('change', async (e) => {
    if (!e.target.matches('[data-file]')) return;
    if (current.dirty && !confirm('Discard your unsaved changes to this file?')) { e.target.value = file; return; }
    file = e.target.value;
    await load();
  });
  el.addEventListener('input', (e) => { if (e.target.matches('[data-json]')) setDirty(e.target.value !== original); });
  page.save = async () => {
    let data;
    try { data = JSON.parse($('[data-json]', el).value); } catch (err) { throw new Error('That isn’t valid JSON: ' + err.message); }
    const r = await api('content', { method: 'POST', body: { file, data, version } });
    toast(`content/${file}.json saved.`);
    warnings(r.warnings);
    await load();
    refreshBadges();
  };
  await load();
};

/* ==========================================================================
   Start
   ========================================================================== */

(async () => {
  try {
    const s = await api('session');
    if (s.user) { S = s; renderShell(); } else renderLogin();
  } catch (e) {
    put(appEl, html`<main class="setup"><h1>Admin unavailable</h1><p>${e.message}</p></main>`);
  }
})();
