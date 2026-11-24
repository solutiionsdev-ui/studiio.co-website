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
| Reels | (this Mac only) Client reels edited with Reels Montage PRO: beats, an effect per beat, live preview, chat with Claude, preview frames, render |
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

### Reels (this Mac only)

The **Reels** page is an editor for client reels, built on Reels Montage PRO in `~/reels-montage-pro` (found automatically; set `reels.dir` in `.private/config.php` if it moves). It only appears on the Mac that has the package, never online: the engine needs Whisper, Apple Vision, ffmpeg and Chrome.

1. **New reel**: title, client (from your leads), raw take or already edited, and the video. The panel uploads it and runs the engine's `new_project.sh` (transcript, face, person cut-outs). A 20-second clip takes about a minute.
2. **Beats**: the video is split into beats, one per spoken phrase, each with a big word and an effect picked by the engine. Click a beat to change its big word, accent colour, behind-the-head, the effect and the effect's text, list, number or photos. **See all effects** opens the live gallery. The preview on the left shows the effects live over the video (person cut out, so "behind you" effects really go behind you).
3. **Ask Claude**: write what to change ("a collage of my photos at 0:07", "less text after 0:15"). Claude Code edits the reel's `beats.json` (model `claude-opus-5-5`, file tools only, $1 cap per message) and the beats update by themselves.
4. **Preview frames** runs the engine's overlap check and `preview.py` (one frame per beat, with video effects and glass blur). **Render video** runs `build.py`; it stops if text overlaps (you can render anyway), and the result is under **Final video** and **Download**.

Where things live: the engine project (video, transcript, masks, `beats.json`, `overlay.html`, `out.mp4`) in `~/reels-montage-pro/projects/<reel>/`; the client, status, chat and job logs in `.private/data/reels/`. The plan is `beats.json`; before a preview or render the panel copies it into the project's `overlay.html` as a marked `STUDIIO BEATS` block (from `lib/reels-bridge.js`), so the engine itself is unchanged. Reels Montage PRO is a paid, personal-use package: nothing from it is copied into this (public) repository.

You can also work on a reel in Claude Code directly: `cd ~/reels-montage-pro`, start `claude`, and ask it to edit `projects/<reel>/beats.json`. The panel picks the changes up.

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
  lib/reels.php      Reels: projects, beats.json, engine jobs, reel chat (this Mac only)
  lib/reels-bridge.js  turns beats.json into effects inside the engine's overlay.html
  reels-file.php     serves the engine's overlay, bank and reel files to the editor (key in the URL)
  tools/reels_auto.py  reads the engine's automatic plan for a new reel
  tools/setup.php    create/update the private config
  tools/check-content.php  validate content/*.json
  dev/router.php     local dev server router
.github/workflows/ask-claude.yml   Ask Claude online (Claude Code in GitHub Actions → pull request)
```
