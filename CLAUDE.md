# STUDIIO.CO website — notes for Claude Code

Bilingual (English + Arabic, right-to-left) agency site. Plain HTML/CSS/JS, no build step.
Live on Hostinger: pushing to `main` on GitHub (`solutiionsdev-ui/studiio.co-website`) deploys it.

## Content lives in `content/*.json` — shared with the admin panel

The admin panel (`/admin`) and Claude Code edit **the same files**. Change content there, not in `main.js`.

| File | What it is |
| --- | --- |
| `content/copy.json` | Every string on the page: `{ "en": { key: text }, "ar": { key: text } }`. Keys match `data-i18n` / `data-i18n-html` in `index.html`. Only `data-i18n-html` keys may contain `<em>`, `<br>`, `<span class="lc">`. |
| `content/work.json` | Selected-work grid, in display order: `img`, `title{en,ar}`, `sub{en,ar}`, `cats[]` (category keys), `sector` (sector key). |
| `content/reel.json` | Hero film strip + full-screen reel: `img`, `cap{en,ar}`. |
| `content/clients.json` | `version` (bump when a logo *file* is replaced) and `logos[]`: `name`, `file` (in `img/clients/`), `w`, `h` (pixel size of the file), optional `s` scale. |
| `content/services.json` | The 5 services: `id`, `img`, optional `mock: "browser"`, `title`, `lead`, `tags{en:[],ar:[]}`. Service ids feed the contact form. |
| `content/articles.json` | Thinking section: `img`, `cat`, `mins`, `title{en,ar}`. |
| `content/taxonomy.json` | `sectors` and `categories` labels `{key: {en, ar}}`. Category keys (`digital`, `production`, `pr`, `web`, `events`) are also the work filters in `main.js`; don't rename them. |

Rules:
- Keep English and Arabic in step. When adding or changing English, write the Arabic too (Modern Standard Arabic, same tone as the existing Arabic). Brand and client names stay in Latin script.
- Never invent clients, results, numbers or quotes. Placeholder case studies must be flagged, not made up.
- Images: only reference files that exist in `img/` (photos, ~1200×1500, 4:5) or `img/clients/` (logos, transparent PNG, ≤480 px wide).
- Keep JSON valid, 2-space indent, UTF-8 (Arabic unescaped). After editing, run `php admin/tools/check-content.php` (same rules the admin applies on save). PHP is at `~/.local/bin/php` on this Mac.
- Voice: "we", agency (not founder-led). Studios: Beirut, Dubai and California — always all three.
- Hostinger tells browsers to cache `main.js` and `styles.css` for a week. Whenever you change either file, bump the `?v=` date in `index.html` (`styles.css?v=YYYYMMDD`, `main.js?v=YYYYMMDD`), or returning visitors keep running the old file against the new page. Content JSON is not affected (served with `no-cache`).

## The admin panel (`admin/`)

PHP 8.1+, no framework. See `admin/README.md` for setup, running locally and going online.
- `admin/api.php` JSON API · `admin/assets/admin.js` UI · `admin/lib/` logic (content rules, stores, leads, git publish, Ask Claude).
- Private config, login hash, leads and task logs live in `.private/` locally or `studiio-private/` next to `public_html` on Hostinger. Never commit them; leads are personal data and the repo is public.
- "Ask Claude" in the panel runs Claude Code headless with this file as context (locally: `claude -p` in this folder; online: `.github/workflows/ask-claude.yml`, which opens a pull request).
- "Pipeline" is the client system (clients → projects → 11 stages, stage checks, checklist, payments, messages, activity): `admin/lib/crm.php`. Its records are personal data in the private data folder (`crm/*.json`); never commit them. Settings (terms, timings, open decisions) and all client-facing wording live in `admin/crm/studiio.co/` (`settings.json`, `templates/<lang>/*.json`), not in code; after editing them run `php admin/tools/check-crm.php`. Every record carries `business`, so another business is another folder under `admin/crm/`. Documents (`admin/lib/crm-docs.php`, PDFs by `admin/lib/pdf.php`), invoices and the Payments page (`admin/lib/crm-pay.php`; bank details only in the private data folder, never in the repo), the client portal (`admin/lib/crm-portal.php`; deliverables and client uploads live only in the private data folder), and the automation rules with the Approvals inbox (`admin/lib/crm-auto.php`, n8n calls `admin/hooks.php`; nothing reaches a client without Mario's approval unless he ticked that template) and the intake questionnaire are served to clients by `client/index.php` behind a 32-character token; placeholders are listed in `admin/crm/studiio.co/README.md`.
- "Reels" (this Mac only) drives Reels Montage PRO in `~/reels-montage-pro`: `admin/lib/reels.php`, `admin/lib/reels-bridge.js` (effect renderers; keep its list in step with `REEL_EFFECTS` in `reels.php`), `admin/reels-file.php`. Never copy files from that package (bank, engine, template) into this repo: it is a paid, personal-use package and this repo is public.

## Run locally

```bash
~/.local/bin/php -S localhost:4380 admin/dev/router.php
```
Site: http://localhost:4380 · Arabic: http://localhost:4380/?lang=ar · Admin: http://localhost:4380/admin/
