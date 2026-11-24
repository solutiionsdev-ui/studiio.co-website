/* STUDIIO.CO — homepage prototype (EN / AR) */
(() => {
  'use strict';

  const IMG = 'img/';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  /* ------------------------------------------------------------------
     Content — loaded from content/*.json (edited in /admin or by Claude Code)
     copy.json      every string the page shows, in English and Arabic
     services.json  the 5 disciplines        work.json     selected-work grid
     reel.json      hero film strip + reel   clients.json  client logo strip
     articles.json  Thinking section         taxonomy.json sector + category labels
     ------------------------------------------------------------------ */
  let T = { en: {}, ar: {} };
  let SERVICES = [];
  let SECTOR = {};
  let CAT_SHORT = {};
  let WORK = [];
  let STRIP = [];
  let LOGO_VERSION = 1;
  let CLIENT_LOGOS = [];
  let ARTICLES = [];

  const CONTENT_FILES = ['copy', 'services', 'taxonomy', 'work', 'reel', 'clients', 'articles'];
  // Each file loads on its own, so one broken file only empties its own section.
  async function loadContent() {
    const results = await Promise.allSettled(CONTENT_FILES.map((name) =>
      fetch(`content/${name}.json`).then((res) => {
        if (!res.ok) throw new Error(`${name}.json: HTTP ${res.status}`);
        return res.json();
      })
    ));
    const get = (name, fallback) => {
      const r = results[CONTENT_FILES.indexOf(name)];
      if (r.status === 'fulfilled' && r.value) return r.value;
      if (r.status === 'rejected') console.error('[studiio] content not loaded:', r.reason);
      return fallback;
    };
    const copy = get('copy', {});
    T = { en: copy.en || {}, ar: copy.ar || {} };
    SERVICES = asList(get('services', []));
    const tax = get('taxonomy', {});
    SECTOR = tax.sectors || {};
    CAT_SHORT = tax.categories || {};
    WORK = asList(get('work', [])).map((w) => ({ ...w, cats: asList(w.cats) }));
    STRIP = asList(get('reel', []));
    const clients = get('clients', {});
    LOGO_VERSION = clients.version || 1;
    CLIENT_LOGOS = asList(clients.logos);
    ARTICLES = asList(get('articles', []));
  }
  const asList = (v) => (Array.isArray(v) ? v : []);

  /* ------------------------------------------------------------------
     State
     ------------------------------------------------------------------ */
  let lang = 'en';
  let filter = 'all';
  const t = (k) => (T[lang] && T[lang][k]) ?? T.en[k] ?? k;
  // True when copy.json has this key; otherwise the page keeps the text written in index.html.
  const has = (k) => (T[lang] && T[lang][k] != null) || T.en[k] != null;
  const L = (o) => (o && (o[lang] ?? o.en)) ?? '';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  try {
    const fromUrl = new URLSearchParams(location.search).get('lang');
    const stored = localStorage.getItem('studiio-lang');
    if (fromUrl === 'ar' || fromUrl === 'en') lang = fromUrl;
    else if (stored === 'ar' || stored === 'en') lang = stored;
  } catch (e) { /* storage unavailable */ }

  /* ------------------------------------------------------------------
     Renderers
     ------------------------------------------------------------------ */
  function applyStatic() {
    const html = document.documentElement;
    html.lang = lang;
    html.dir = lang === 'ar' ? 'rtl' : 'ltr';
    $$('[data-i18n]').forEach((el) => { if (has(el.dataset.i18n)) el.textContent = t(el.dataset.i18n); });
    $$('[data-i18n-html]').forEach((el) => { if (has(el.dataset.i18nHtml)) el.innerHTML = t(el.dataset.i18nHtml); });
    $$('[data-lang-toggle]').forEach((btn) => {
      btn.textContent = has('lang.switch') ? t('lang.switch') : (lang === 'ar' ? 'English' : 'عربي');
      btn.lang = lang === 'ar' ? 'en' : 'ar';
      btn.setAttribute('aria-label', lang === 'ar' ? 'Switch to English' : 'التبديل إلى العربية');
    });
    const menuBtn = $('.menu-btn');
    if (menuBtn) menuBtn.textContent = document.body.classList.contains('menu-open') ? t('nav.close') : t('nav.menu');
  }

  function renderStrip() {
    const track = $('#strip-track');
    const items = (hidden) => STRIP.map((s, i) => `
      <figure class="strip-item" data-reel-open="${i}" ${hidden ? 'aria-hidden="true"' : 'tabindex="0" role="button"'} aria-label="${esc(L(s.cap))}">
        <img src="${IMG + s.img}" alt="${hidden ? '' : esc(L(s.cap))}" width="1200" height="1500" ${i > 4 || hidden ? 'loading="lazy"' : ''} decoding="async">
        <figcaption class="label">${esc(L(s.cap))}</figcaption>
      </figure>`).join('');
    track.innerHTML = items(false) + items(true);
  }

  function renderServices() {
    $('#svc-list').innerHTML = SERVICES.map((s, i) => {
      const media = s.mock === 'browser'
        ? `<div class="svc-media browser"><div class="browser-bar"><i></i><i></i><i></i><span>studiio.co</span></div><div class="browser-page"><img src="${IMG + s.img}" alt="" loading="lazy"><b>STUDIIO.CO</b></div></div>`
        : `<div class="svc-media"><img src="${IMG + s.img}" alt="" loading="lazy"></div>`;
      return `
      <details class="svc" data-img="${IMG + s.img}">
        <summary>
          <span class="svc-num">0${i + 1}</span>
          <span class="svc-title">${esc(L(s.title))}</span>
          <span class="svc-icon" aria-hidden="true"></span>
        </summary>
        <div class="svc-body">
          <div>
            <p class="svc-lead">${esc(L(s.lead))}</p>
            <ul class="tags">${L(s.tags).map((tg) => `<li class="tag">${esc(tg)}</li>`).join('')}</ul>
          </div>
          ${media}
        </div>
      </details>`;
    }).join('');
  }

  function renderFilters() {
    const keys = ['all', 'digital', 'production', 'pr', 'web', 'events'];
    $('#filters').innerHTML = keys.map((k) =>
      `<button class="pill filter" type="button" data-filter="${k}" aria-pressed="${k === filter}">${esc(k === 'all' ? t('filter.all') : t('cat.' + k))}</button>`
    ).join('');
  }

  function renderWork() {
    const visible = WORK.filter((w) => filter === 'all' || w.cats.includes(filter));
    const grid = $('#work-grid');
    if (!visible.length) {
      grid.innerHTML = `<div class="empty"><p>${esc(t('work.empty'))}</p><a class="pill pill--solid" href="#contact">${esc(t('work.emptyCta'))}</a></div>`;
      return;
    }
    // Desktop rows alternate 2-up (wide) and 3-up; a short last row fills itself.
    const sizes = [];
    let i = 0, three = false;
    while (i < visible.length) {
      let n = three ? 3 : 2;
      n = Math.min(n, visible.length - i);
      const size = n === 3 ? 2 : 3;
      for (let k = 0; k < n; k++) sizes.push(size);
      i += n; three = !three;
    }
    grid.innerHTML = visible.map((w, idx) => {
      const wideMobile = idx % 3 === 0 || (idx === visible.length - 1 && idx % 3 === 1);
      return `
      <article class="tile${wideMobile ? ' is-wide' : ''}" data-size="${sizes[idx]}" style="animation-delay:${idx * 60}ms">
        <a href="#work" aria-label="${esc(L(w.title))} — ${esc(L(w.sub))}">
          <div class="tile-media"><img src="${IMG + w.img}" alt="${esc(L(w.title))}" loading="lazy" decoding="async" width="1200" height="1500"></div>
          <div class="tile-info">
            <div><h3 class="tile-title"${/^[A-Za-z]/.test(L(w.title)) && lang === 'ar' ? ' dir="ltr"' : ''}>${esc(L(w.title))}</h3><p class="tile-sub">${esc(L(w.sub))}</p></div>
            <div class="tile-tags label"><span>${esc(L(SECTOR[w.sector]))}</span><span>${w.cats.map((c) => esc(L(CAT_SHORT[c]))).join(' · ')}</span></div>
          </div>
        </a>
      </article>`;
    }).join('');
  }

  function renderClients() {
    // Equalise visual weight: wide wordmarks get shorter, compact marks taller.
    const logo = (c, dup) => {
      const h = Math.round(Math.max(26, Math.min(60, Math.sqrt(5200 / (c.w / c.h)))) * (c.s || 1));
      return `<span class="client-logo${dup ? ' dup' : ''}"><img src="${IMG}clients/${c.file}?v=${LOGO_VERSION}" alt="" width="${c.w}" height="${c.h}" style="--h:${h}" loading="lazy" decoding="async"></span>`;
    };
    const half = Math.ceil(CLIENT_LOGOS.length / 2);
    [CLIENT_LOGOS.slice(0, half), CLIENT_LOGOS.slice(half)].forEach((row, i) => {
      $(i ? '#logos-b' : '#logos-a').innerHTML =
        row.map((c) => logo(c, false)).join('') + row.map((c) => logo(c, true)).join('');
    });
    $('#clients-list').innerHTML = CLIENT_LOGOS.map((c) => `<li>${esc(c.name)}</li>`).join('');
  }

  function renderArticles() {
    $('#articles').innerHTML = ARTICLES.map((a) => `
      <a class="article reveal" href="#thinking">
        <div class="article-thumb"><img src="${IMG + a.img}" alt="" loading="lazy"></div>
        <div>
          <p class="article-meta label"><span class="cat">${esc(L(CAT_SHORT[a.cat]))}</span><span>${a.mins} ${esc(t('read'))}</span></p>
          <h3 class="article-title">${esc(L(a.title))}</h3>
        </div>
        <span class="article-go" aria-hidden="true"><span class="arrow">→</span></span>
      </a>`).join('');
  }

  function renderServiceSelect() {
    const sel = $('#service-select');
    const current = sel.value;
    sel.innerHTML = `<option value="" disabled ${current ? '' : 'selected'}>${esc(t('form.choose'))}</option>` +
      SERVICES.map((s) => `<option value="${s.id}">${esc(L(s.title))}</option>`).join('') +
      `<option value="all">${esc(t('form.all'))}</option>`;
    if (current) sel.value = current;
  }

  function renderAll() {
    applyStatic();
    renderStrip();
    renderServices();
    renderFilters();
    renderWork();
    renderClients();
    renderArticles();
    renderServiceSelect();
    observeReveals();
    stripMotion.refresh();
  }

  /* ------------------------------------------------------------------
     Logo — the official STUDIIO.CO wordmark redrawn as SVG paths
     (one path per letter so the hero can animate them in)
     ------------------------------------------------------------------ */
  const LOGO_GLYPHS = [
    'M10 0H94Q102 0 102 8V24H80V18H24V30H94Q102 30 102 38V68Q102 76 94 76H10Q2 76 2 68V52H24V58H80V46H10Q2 46 2 38V8Q2 0 10 0Z', // S
    'M108 2H210V18H170V74H148V18H108Z', // T
    'M217 2H239V58H292V2H314V68Q314 76 306 76H225Q217 76 217 68Z', // U
    'M327 2H420Q428 2 428 10V66Q428 74 420 74H327ZM349 20V56H405V20Z', // D
    'M439 2H461V74H439Z', // I
    'M474 2H496V74H474Z', // I
    'M515 0H600Q608 0 608 8V68Q608 76 600 76H515Q507 76 507 68V8Q507 0 515 0ZM529 18V58H586V18Z', // O
    'M617 56H638V74H617Z', // .
    'M654 0H738Q746 0 746 8V26H724V18H668V58H724V50H746V68Q746 76 738 76H654Q646 76 646 68V8Q646 0 654 0Z', // C
    'M764 0H848Q856 0 856 8V68Q856 76 848 76H764Q756 76 756 68V8Q756 0 764 0ZM778 18V58H834V18Z' // O
  ];
  const logoSVG = () =>
    `<svg viewBox="2 0 854 76" fill-rule="evenodd" aria-hidden="true" focusable="false">${
      LOGO_GLYPHS.map((d, i) => `<path class="g${i === 7 ? ' dot' : ''}" style="--i:${i}" d="${d}"/>`).join('')
    }</svg>`;

  /* ------------------------------------------------------------------
     Interactions
     ------------------------------------------------------------------ */
  // Header state
  const header = $('#site-header');
  const darkSections = $$('.services, .contact, .site-footer');
  const onScroll = () => {
    header.classList.toggle('is-scrolled', window.scrollY > 8);
    const probe = header.offsetHeight / 2;
    header.classList.toggle('on-dark', darkSections.some((s) => {
      const r = s.getBoundingClientRect();
      return r.top <= probe && r.bottom >= probe;
    }));
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([entry]) => {
      header.classList.toggle('show-logo', !entry.isIntersecting);
    }, { rootMargin: '-60px 0px 0px 0px' }).observe($('#wordmark'));
  }

  // Scroll reveal
  let revealObserver = null;
  function observeReveals() {
    const els = $$('.reveal:not(.in)');
    if (!('IntersectionObserver' in window) || reducedMotion.matches) { els.forEach((el) => el.classList.add('in')); return; }
    if (!revealObserver) {
      revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); revealObserver.unobserve(e.target); } });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    }
    els.forEach((el) => revealObserver.observe(el));
  }

  // Language toggle
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-lang-toggle]');
    if (!btn) return;
    lang = lang === 'ar' ? 'en' : 'ar';
    try { localStorage.setItem('studiio-lang', lang); } catch (err) { /* ignore */ }
    try {
      const url = new URL(location.href);
      url.searchParams.set('lang', lang);
      history.replaceState(null, '', url);
    } catch (err) { /* ignore */ }
    closeMenu();
    renderAll();
  });

  // Mobile menu
  const menu = $('#mobile-menu');
  const menuBtn = $('.menu-btn');
  function openMenu() { menu.hidden = false; document.body.classList.add('menu-open'); menuBtn.setAttribute('aria-expanded', 'true'); menuBtn.textContent = t('nav.close'); }
  function closeMenu() { if (menu.hidden) return; menu.hidden = true; document.body.classList.remove('menu-open'); menuBtn.setAttribute('aria-expanded', 'false'); menuBtn.textContent = t('nav.menu'); }
  menuBtn.addEventListener('click', () => (menu.hidden ? openMenu() : closeMenu()));
  menu.addEventListener('click', (e) => { if (e.target.closest('a')) closeMenu(); });

  // Work filters
  $('#filters').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-filter]');
    if (!btn) return;
    filter = btn.dataset.filter;
    $$('[data-filter]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
    renderWork();
  });

  // Cursor follower over the film strip
  const cursor = $('#cursor');
  const strip = $('#strip');
  let cx = -200, cy = -200, tx = -200, ty = -200, raf = null;
  function loop() {
    cx += (tx - cx) * 0.22; cy += (ty - cy) * 0.22;
    cursor.style.setProperty('--x', cx + 'px'); cursor.style.setProperty('--y', cy + 'px');
    raf = (Math.abs(tx - cx) > 0.3 || Math.abs(ty - cy) > 0.3) ? requestAnimationFrame(loop) : null;
  }
  strip.addEventListener('pointermove', (e) => {
    if (!finePointer.matches) return;
    tx = e.clientX; ty = e.clientY;
    if (!cursor.classList.contains('on')) { cx = tx; cy = ty; cursor.classList.add('on'); }
    if (!raf) raf = requestAnimationFrame(loop);
  });
  strip.addEventListener('pointerleave', () => cursor.classList.remove('on'));

  // Film strip motion: drifts on its own, and can be dragged (mouse), swiped (touch)
  // or scrolled sideways (trackpad). After a fling it glides, then eases back to drifting.
  const stripMotion = (() => {
    const track = $('#strip-track');
    let x = 0, vel = 0, setW = 0, last = 0, frameId = null, visible = true;
    let hovering = false, down = false, dragging = false, moved = false, pid = null;
    let startX = 0, startOffset = 0, lastX = 0, lastT = 0, flingVel = 0;
    const dir = () => (document.documentElement.dir === 'rtl' ? 1 : -1);
    const autoSpeed = () => (reducedMotion.matches ? 0 : window.innerWidth < 640 ? 38 : 60); // px per second
    const wrap = () => {
      if (!setW) return;
      x = dir() < 0 ? ((x % setW) - setW) % setW : ((x % setW) + setW) % setW;
    };
    const apply = () => { track.style.transform = `translate3d(${x}px,0,0)`; };
    const measure = () => { setW = track.scrollWidth / 2; wrap(); apply(); };
    const frame = (time) => {
      const dt = Math.min(0.05, (time - (last || time)) / 1000);
      last = time;
      if (!dragging) {
        const target = hovering ? 0 : autoSpeed() * dir();
        vel += (target - vel) * (1 - Math.exp(-dt / 0.5));
        x += vel * dt;
        wrap(); apply();
      }
      frameId = visible && !document.hidden ? requestAnimationFrame(frame) : null;
    };
    const start = () => { if (!frameId && visible && !document.hidden) { last = 0; frameId = requestAnimationFrame(frame); } };

    strip.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      down = true; dragging = false; moved = false; pid = e.pointerId;
      startX = lastX = e.clientX; startOffset = x; lastT = e.timeStamp; flingVel = 0;
    });
    strip.addEventListener('pointermove', (e) => {
      if (!down || e.pointerId !== pid) return;
      const dx = e.clientX - startX;
      if (!dragging && Math.abs(dx) > 6) {
        dragging = moved = true;
        strip.setPointerCapture(pid);
        strip.classList.add('is-dragging');
        cursor.classList.add('pressed');
      }
      if (!dragging) return;
      x = startOffset + dx; wrap(); apply();
      const dt = e.timeStamp - lastT;
      if (dt > 0) flingVel = 0.75 * flingVel + 0.25 * ((e.clientX - lastX) / dt) * 1000;
      lastX = e.clientX; lastT = e.timeStamp;
    });
    const end = (e) => {
      if (!down || e.pointerId !== pid) return;
      down = false;
      if (!dragging) return;
      dragging = false;
      const stillFor = e.timeStamp - lastT;
      vel = stillFor > 90 ? 0 : Math.max(-2600, Math.min(2600, flingVel));
      strip.classList.remove('is-dragging');
      cursor.classList.remove('pressed');
    };
    strip.addEventListener('pointerup', end);
    strip.addEventListener('pointercancel', end);
    // A drag shouldn't also count as a click that opens the reel
    strip.addEventListener('click', (e) => { if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; } }, true);
    strip.addEventListener('dragstart', (e) => e.preventDefault());
    strip.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') hovering = true; });
    strip.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') hovering = false; });
    // Trackpad / sideways wheel; vertical scrolling still scrolls the page
    strip.addEventListener('wheel', (e) => {
      if (Math.abs(e.deltaX) <= Math.abs(e.deltaY)) return;
      e.preventDefault();
      x -= e.deltaX; wrap(); apply();
    }, { passive: false });

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; if (visible) start(); }).observe(strip);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) start(); });
    window.addEventListener('resize', measure);
    return {
      refresh() { measure(); vel = autoSpeed() * dir(); start(); },
      get state() { return { x, vel, setW, dragging, hovering }; }
    };
  })();

  // Service hover preview
  const preview = $('#svc-preview');
  const previewImg = $('img', preview);
  const svcList = $('#svc-list');
  svcList.addEventListener('pointerover', (e) => {
    if (!finePointer.matches) return;
    const summary = e.target.closest('summary');
    if (!summary) return;
    const details = summary.parentElement;
    if (details.open) { preview.classList.remove('on'); return; }
    previewImg.src = details.dataset.img;
    preview.classList.add('on');
  });
  svcList.addEventListener('pointermove', (e) => {
    if (!finePointer.matches) return;
    preview.style.setProperty('--x', e.clientX + 'px');
    preview.style.setProperty('--y', e.clientY + 'px');
    if (!e.target.closest('summary')) preview.classList.remove('on');
  });
  svcList.addEventListener('pointerleave', () => preview.classList.remove('on'));
  window.addEventListener('scroll', () => preview.classList.remove('on'), { passive: true });
  svcList.addEventListener('toggle', (e) => {
    if (e.target.open) {
      preview.classList.remove('on');
      $$('.svc', svcList).forEach((d) => { if (d !== e.target) d.open = false; });
    }
  }, true);

  // Reel lightbox
  const reel = $('#reel');
  const reelImg = $('#reel-img');
  const reelCap = $('#reel-cap');
  const reelCount = $('#reel-count');
  const reelBar = $('#reel-bar');
  const DUR = 2600;
  let reelIdx = 0, reelTimer = null, lastFocus = null;
  const pad = (n) => String(n).padStart(2, '0');
  function showSlide(i) {
    reelIdx = (i + STRIP.length) % STRIP.length;
    const s = STRIP[reelIdx];
    reelImg.src = IMG + s.img;
    reelImg.alt = L(s.cap);
    reelImg.style.animation = 'none'; void reelImg.offsetWidth; reelImg.style.animation = '';
    reelCap.textContent = L(s.cap);
    reelCount.textContent = `${pad(reelIdx + 1)} / ${pad(STRIP.length)}`;
    reelBar.classList.remove('run'); void reelBar.offsetWidth;
    if (!reducedMotion.matches) {
      reelBar.style.setProperty('--dur', DUR + 'ms');
      reelBar.classList.add('run');
      clearTimeout(reelTimer);
      reelTimer = setTimeout(() => showSlide(reelIdx + 1), DUR);
    }
  }
  function openReel(i) {
    lastFocus = document.activeElement;
    reel.hidden = false;
    document.body.classList.add('reel-open');
    cursor.classList.remove('on');
    showSlide(i);
    $('#reel-close').focus();
  }
  function closeReel() {
    if (reel.hidden) return;
    reel.hidden = true;
    document.body.classList.remove('reel-open');
    clearTimeout(reelTimer);
    if (lastFocus) lastFocus.focus();
  }
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-reel-open]');
    if (opener) openReel(Number(opener.dataset.reelOpen) || 0);
  });
  strip.addEventListener('keydown', (e) => {
    const item = e.target.closest('[data-reel-open]');
    if (item && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openReel(Number(item.dataset.reelOpen)); }
  });
  $('#reel-close').addEventListener('click', closeReel);
  $('#reel-next').addEventListener('click', () => showSlide(reelIdx + 1));
  $('#reel-prev').addEventListener('click', () => showSlide(reelIdx - 1));
  reel.addEventListener('click', (e) => { if (e.target === reel) closeReel(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeReel(); closeMenu(); }
    if (reel.hidden) return;
    const fwd = lang === 'ar' ? 'ArrowLeft' : 'ArrowRight';
    const back = lang === 'ar' ? 'ArrowRight' : 'ArrowLeft';
    if (e.key === fwd) showSlide(reelIdx + 1);
    if (e.key === back) showSlide(reelIdx - 1);
  });

  // Contact form → contact.php (PHP mail on Hostinger), with a mailto fallback so no enquiry is lost
  const form = $('#contact-form');
  const note = $('#form-note');
  const submitBtn = $('#form-submit');
  const setNote = (key, state) => {
    note.textContent = t(key);
    note.classList.toggle('sent', state === 'sent');
    note.classList.toggle('error', state === 'error');
  };
  const mailtoFallback = (data) => {
    const svc = SERVICES.find((s) => s.id === data.get('service'));
    const subject = `New enquiry — ${svc ? L(svc.title) : 'studiio.co'} — ${data.get('name')}`;
    const body = [
      `Name: ${data.get('name')}`,
      `Company: ${data.get('company') || '—'}`,
      `Email: ${data.get('email')}`,
      `Service: ${svc ? L(svc.title) : '—'}`,
      '',
      String(data.get('message') || '')
    ].join('\n');
    window.location.href = `mailto:info@studiio.co?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  };
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const nameEl = form.elements.namedItem('name');
    const emailEl = form.elements.namedItem('email');
    const nameOk = nameEl.value.trim() !== '';
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(emailEl.value.trim());
    nameEl.setAttribute('aria-invalid', String(!nameOk));
    emailEl.setAttribute('aria-invalid', String(!emailOk));
    if (!nameOk || !emailOk) { setNote('form.invalid', 'error'); (nameOk ? emailEl : nameEl).focus(); return; }

    form.elements.namedItem('lang').value = lang;
    const data = new FormData(form);
    submitBtn.disabled = true;
    setNote('form.sending');
    try {
      const res = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
      const json = await res.json().catch(() => null);
      if (json && json.ok) { form.reset(); renderServiceSelect(); setNote('form.sent', 'sent'); }
      else if (json && json.error === 'invalid') setNote('form.invalid', 'error');
      else if (json && json.error === 'rate') setNote('form.rate', 'error');
      else { mailtoFallback(data); setNote('form.fallback', 'error'); }
    } catch (err) {
      mailtoFallback(data);
      setNote('form.fallback', 'error');
    } finally {
      submitBtn.disabled = false;
    }
  });
  // Result of a no-JavaScript post (contact.php redirects back with ?sent=1 or ?sent=0)
  try {
    const sent = new URLSearchParams(location.search).get('sent');
    if (sent === '1') setNote('form.sent', 'sent');
    if (sent === '0') setNote('form.failed', 'error');
  } catch (err) { /* ignore */ }

  /* ------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------ */
  $$('[data-logo]').forEach((el) => el.insertAdjacentHTML('beforeend', logoSVG()));
  // Set direction before the content arrives so an Arabic page doesn't flip after loading.
  document.documentElement.lang = lang;
  document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
  loadContent().then(renderAll);
})();
