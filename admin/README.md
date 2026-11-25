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
| Pipeline | The client system: every client and project through the 11 onboarding stages, with stage checks, checklist, documents, invoices, payments, shoots, messages and an activity log. Stored privately, never in Git. |
| Payments | Invoices and payments across all clients: received, outstanding, per month, CSV export, and the private "how to pay" text for invoices. |
| Leads | Enquiries from the contact form: status, private notes, reply, CSV export, **Add to pipeline**. Stored privately, never in Git. |
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

### Pipeline (client system)

The **Pipeline** page follows every studiio.co client from the first message to the follow-up, through the stages of the onboarding spec: Lead → Intake → Proposal → Agreement → Onboarding → Kickoff → In production → Review → Delivered → Offboarding → Follow-up. It works on this Mac and online.

- **New client**: business, contact, phone, email, preferred channel, track (retainer, production or photography) and source. Or open a website enquiry in **Leads** and press **Add to pipeline**.
- **Board**: one column per stage, one card per project, with the days in the stage, the next action (red when overdue) and a red edge when the project is waiting on the client. **List** shows the same as a table.
- **Client page**: the stage line, and what the current stage needs before the project can move on, with a button to record each step (qualified, intake received, proposal accepted, agreement signed, kickoff done, first draft delivered, approval, testimonial requested, check-in). Also the next action, project details, payments, the asset and access checklist (created from the track when the project reaches Onboarding), shoot dates, messages, documents and the activity log.
- **Rules**: a project moves on only when its stage is done. Moving on anyway, skipping stages, booking a shoot before Kickoff or closing early asks for a reason, and the override is logged with the date and who did it. Every stage change is logged.

Where things live: the records (clients, projects, checklist, documents, payments, messages, activity) and the made PDFs in the private data folder under `crm/` (`.private/data/crm/` on this Mac, `studiio-private/data/crm/` on Hostinger), never in Git. Settings and all client-facing wording in `admin/crm/studiio.co/` (see its `README.md`: `settings.json`, and the templates for the checklist, intake, proposal, agreement, welcome pack, kickoff agenda, feedback rules, messages and labels). After editing any of them, run `php admin/tools/check-crm.php`. Another business (studiio.ai, solutiions.dev) gets its own folder next to `studiio.co`.

**Documents and the intake questionnaire** (phase 2), on the client page:

- **Intake questionnaire**: **Make the link**, then **Send…**. The client fills it in on their phone at `studiio.co/client/?t=…`; the answers appear on the client page, fill in Instagram, TikTok, website, preferred channel and approver, tick the "three accounts you love" checklist item, and complete the Intake stage.
- **Documents**: make the proposal, quotation, agreement, welcome pack and kickoff agenda from the templates with the client's details. Each has a PDF (in the quotation's look: black band, STUDIIO logo, one flat total) and a private page link with a Download PDF button. **Send…** opens WhatsApp or your email with the message ready; you press send there, and it's logged and the document is marked sent, then opened when the client opens it.
- **Messages → Write…**: any message from `messages.json` (kickoff recap, handover, testimonial, referral, check-in, renewal), filled in, editable, sent the same way.

Nothing is sent automatically. PDFs are made by the admin itself (`lib/pdf.php`), so they work on this Mac and online. Client records on this Mac and online are separate: use studiio.co/admin for real clients, so their links point to studiio.co.

**Invoices and payments** (phase 3):

- On the client page, **Payments** lists the project's invoices (deposit, final, monthly for retainers, or other), numbered `INV-YYYY-NNN`, each with a PDF, a client page and **Send…**. Sending the agreement drafts the deposit invoice (a retainer's first month), and recording the client's approval drafts the final invoice; drafts are never sent by themselves.
- **Record payment…** (or **Paid…** on an invoice) records a payment against an invoice; the invoice turns paid when it is covered and its PDF shows what was paid. The stage checks (deposit, final payment) follow the payments.
- The **Payments** page: received this month and this year, outstanding invoices (overdue marked), projects not invoiced yet, totals per month, every payment (filter by month, **Export CSV**), and **How to pay**: the Whish number or bank details printed on invoices, kept privately in the data folder, never in Git.

Not built yet (spec phases 4 to 6): drafted messages with one-click approval and n8n, the client portal (signing, uploads), and monthly retainer cycles.

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

Online, the panel runs on Hostinger next to the site. Saves become commits to GitHub (Hostinger then deploys them, about a minute), leads and Pipeline records are stored on the server, and Ask Claude runs in GitHub Actions and opens a pull request you merge from the panel.

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
  lib/crm.php        Pipeline: clients, projects, stage checks, checklist, payments, messages, activity
  lib/crm-docs.php   Pipeline documents: templates, proposal/quotation/agreement/welcome pack, intake form, messages
  lib/crm-pay.php    Pipeline invoices and the Payments page
  lib/pdf.php        small PDF writer (Helvetica, the STUDIIO logo from img/studiio-logo.svg), no libraries
  crm/studiio.co/    Pipeline settings.json and client-facing templates/ (read from disk, blocked from the web)
  lib/reels.php      Reels: projects, beats.json, engine jobs, reel chat (this Mac only)
  lib/reels-bridge.js  turns beats.json into effects inside the engine's overlay.html
  reels-file.php     serves the engine's overlay, bank and reel files to the editor (key in the URL)
  tools/reels_auto.py  reads the engine's automatic plan for a new reel
  tools/setup.php    create/update the private config
  tools/check-content.php  validate content/*.json
  tools/check-crm.php      validate the Pipeline settings and templates
  dev/router.php     local dev server router
client/              client links (studiio.co/client/?t=…): documents and the intake questionnaire
.github/workflows/ask-claude.yml   Ask Claude online (Claude Code in GitHub Actions → pull request)
```
