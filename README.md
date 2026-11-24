# STUDIIO.CO — website

Website for **STUDIIO.CO**, an integrated creative & communications agency connecting the Middle East and the US, with studios in Beirut, Dubai and California.
Bilingual: English and Arabic (right-to-left), switched with the **عربي / English** button or `?lang=ar`.

Plain HTML, CSS and JavaScript — no build step.

## Run locally

```bash
~/.local/bin/php -S localhost:4380 admin/dev/router.php
```

Then open http://localhost:4380 (or http://localhost:4380/?lang=ar for Arabic). The admin panel is at http://localhost:4380/admin/ (see [admin/README.md](admin/README.md)).
A plain static server (`python3 -m http.server 4321`) still works for the site alone; the contact form and admin need PHP.

## Files

| Path | What it is |
| --- | --- |
| `index.html` | Page structure |
| `styles.css` | Design system and layout (monochrome + one accent `#FF4B1F`) |
| `main.js` | Loads the content files and renders the page; interactions |
| `content/` | All copy and content as JSON, edited in the admin panel or by Claude Code (see `CLAUDE.md`) |
| `admin/` | Admin panel: content editors, leads, Ask Claude, publish ([admin/README.md](admin/README.md)) |
| `contact.php` | Contact form: saves the lead for the admin and emails info@studiio.co |
| `img/` | Web-sized photos from the studiio.co portfolio, plus `studiio-logo.svg` |
| `favicon.svg` | Browser tab icon |
| `vercel.json` | Caching headers for Vercel |

Copy lives in `content/copy.json` (keys `en` and `ar`); services, work tiles, hero reel, articles and client logos are `content/services.json`, `work.json`, `reel.json`, `articles.json` and `clients.json`.
Edit them in the admin panel (`/admin`) or ask Claude Code; both work on the same files.
To add a client: Admin → Client logos → Add logo (or drop a transparent PNG in `img/clients/` and add it to `content/clients.json`; `s` optionally scales a logo up or down).

## Sections

1. Hero — full-width logo, one-liner, the 5 services, scrolling work reel (click to open the full-screen reel)
2. Who we are — statement
3. Services — the 5 disciplines with sub-services
4. Selected work — filterable by service
5. Numbers + brands we've worked with
6. studiio.ai — AI content, links out to the full AI portfolio
7. Thinking — articles
8. Contact — form + details

## Before launch

- [ ] Replace the rebuilt logo SVG with the official vector file
- [ ] Case studies for PR, Digital, Websites and Events (those filters currently show a "request the deck" message)
- [x] Client logo strip: 23 client logos in `img/clients/` (brand colours, made from the brand files)
- [ ] Sharper files for the 6 logos taken from Instagram (Beirut Beer, BYD, Île, Karen Wazen, Pilatté, us Coffee — currently 150px)
- [x] Work grid client names confirmed (Table du Chef Jean, Grandpas, Rucker Park, MONS, ILE)
- [x] Stats confirmed: 10+ years, 500+ projects, 80+ brands
- [ ] Real articles (current titles are samples)
- [ ] Native Arabic copywriter review
- [x] Connect the contact form (emails info@studiio.co; also saved as a lead in the admin)

## Deploy

Hostinger caches `main.js` and `styles.css` in browsers for a week: when you change either, bump the `?v=` date on its link in `index.html`.

Static site — on Vercel, import the repo with framework preset **Other**, no build command, output directory = root.
