# STUDIIO.CO admin panel

A small PHP admin for studiio.co, connected to Claude Code through **shared content files**:
the panel and Claude Code both edit `content/*.json`, so whatever one changes the other sees.

What it manages today:

| Page | Edits |
| --- | --- |
| Selected work | `content/work.json`: add, edit, reorder, remove projects (image upload, EN/AR titles, service filters, sector) |
| Hero reel | `content/reel.json`: the film strip and full-screen reel |
| Copy (EN / AR) | `content/copy.json`: every string on the page, side by side |
| Client logos | `content/clients.json`: upload, reorder, rename, scale |
| Leads | Enquiries from the contact form: status, private notes, reply, CSV export. Stored privately, never in Git. |
| Ask Claude | Send a task to Claude Code; review its changes; publish or discard |
| Publish | (this Mac) commit + push the changes you pick → Hostinger deploys `main` |
| Raw JSON | Any content file directly (services, articles, sectors…), with the same checks |

Every save is validated (images must exist, categories must be real, Arabic gaps are flagged, copy HTML is limited to `<em>`, `<br>`, `<span>`), and a save is refused if the file changed since you opened it, for example because Claude Code edited it in the meantime.

---

## Run it on your Mac

You need PHP 8.1+ (`~/.local/bin/php` is a standalone PHP 8.4 installed for this) and Git.

```bash
cd ~/Desktop/STUDIIO.CO-WEBSITE
~/.local/bin/php admin/tools/setup.php          # once: your login (email + password)
~/.local/bin/php -S localhost:4380 admin/dev/router.php
```

Open http://localhost:4380/admin/ (the site itself is http://localhost:4380/).

On this Mac, **saves write the files in this folder** and nothing goes live until you press **Publish**, which commits the changes you tick, pushes to GitHub, and Hostinger deploys `main`.
The local server never sends email: a contact-form test is saved as a lead only.

### Ask Claude on this Mac

"Ask Claude" runs Claude Code headless (`claude -p`) in this folder, using model `claude-opus-5-5`, with file tools only (no shell, no web) and a $3 cap per task. It reads `CLAUDE.md`, edits the content files, and the panel shows what changed, ready to publish or discard.

The `claude` command-line tool needs its own sign-in, separate from the Claude desktop app. Once, in Terminal:

```bash
claude auth login
```

(Or put an Anthropic API key in `claude.api_key` in `.private/config.php`.)

---

## Put it online (studiio.co/admin)

Online, the panel runs on Hostinger next to the site. Saves become commits to GitHub (Hostinger then deploys them, about a minute), leads are stored on the server, and Ask Claude runs in GitHub Actions and opens a pull request you merge from the panel.

1. **Create the private folder on Hostinger.** In hPanel → File Manager, next to `public_html` (i.e. `…/domains/studiio.co/`), create a folder `studiio-private`. It is outside the website, so it is never served.
2. **Make a GitHub token.** https://github.com/settings/personal-access-tokens/new: a fine-grained token, repository access *only* `solutiionsdev-ui/studiio.co-website`, permissions **Contents**, **Pull requests**, **Actions** = Read and write.
3. **Write the online config on your Mac** and upload it:
   ```bash
   ~/.local/bin/php admin/tools/setup.php --online
   ```
   This writes `.private/online/config.php` (git-ignored). Upload it to `studiio-private/config.php` on Hostinger. Leads and sessions will be created in `studiio-private/data/`.
4. **Publish the admin code** (commit + push to `main`). Hostinger deploys it; open https://studiio.co/admin/.
5. **Check PHP on Hostinger** (hPanel → Advanced → PHP Configuration): PHP 8.1 or newer, with `curl`, `fileinfo` and `gd` enabled (they usually are).
6. **For Ask Claude online** (optional), in GitHub:
   - Install the Claude GitHub app on the repo: run `claude`, then `/install-github-app`.
   - Add a repository secret `CLAUDE_CODE_OAUTH_TOKEN` (create it with `claude setup-token`; it uses your Claude plan), or `ANTHROPIC_API_KEY`.
   - Settings → Actions → General → Workflow permissions → tick **Allow GitHub Actions to create and approve pull requests**.

Security notes: one admin login (bcrypt hash, 5 failed attempts per 15 minutes per IP), HttpOnly + SameSite=Strict session cookie scoped to `/admin/`, a CSRF token on every change, a strict Content-Security-Policy, and `noindex`. `admin/lib`, `admin/tools` and `admin/dev` are blocked by `.htaccess`, and their PHP does nothing if requested directly. The repo is public: never commit `.private/` (it's in `.gitignore`).

---

## Working with Claude Code directly

Open this folder in Claude Code and ask for content changes in plain words. `CLAUDE.md` tells it where everything lives and the rules (EN + AR together, no invented clients, valid JSON). After it edits, run:

```bash
~/.local/bin/php admin/tools/check-content.php
```

Its changes show up in the panel straight away (on this Mac) under **Publish**.

## Files

```
admin/
  index.php          page shell (login + app), CSP
  api.php            JSON API
  assets/            admin.js (UI), admin.css
  lib/bootstrap.php  config, sessions, login, CSRF, helpers
  lib/content.php    content files, validation, local/GitHub storage, image uploads
  lib/publish.php    pending changes, diff, publish (git), discard
  lib/claude.php     Ask Claude: local `claude -p` runner, GitHub Actions runner
  lib/leads.php      lead storage (used by contact.php too)
  tools/setup.php    create/update the private config
  tools/check-content.php  validate content/*.json
  dev/router.php     local dev server router
.github/workflows/ask-claude.yml   Ask Claude online (Claude Code in GitHub Actions → pull request)
```
