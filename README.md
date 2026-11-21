# STUDIIO.CO — website

Homepage prototype for **STUDIIO.CO**, an integrated creative & communications agency in Beirut and Dubai.
Bilingual: English and Arabic (right-to-left), switched with the **عربي / English** button or `?lang=ar`.

Plain HTML, CSS and JavaScript — no build step.

## Run locally

```bash
python3 -m http.server 4321
```

Then open http://localhost:4321 (or http://localhost:4321/?lang=ar for Arabic).

## Files

| Path | What it is |
| --- | --- |
| `index.html` | Page structure |
| `styles.css` | Design system and layout (monochrome + one accent `#FF4B1F`) |
| `main.js` | All copy in English and Arabic, content data (services, work, articles), interactions |
| `img/` | Web-sized photos from the studiio.co portfolio, plus `studiio-logo.svg` |
| `favicon.svg` | Browser tab icon |
| `vercel.json` | Caching headers for Vercel |

Copy lives in the `T` object in `main.js` (keys `en` and `ar`); services, work tiles, articles and client names are the `SERVICES`, `WORK`, `ARTICLES` and `CLIENTS` arrays.

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
- [ ] Confirm the client names shown (Table du Chef Jean, MONS, ILE, Grandpas, Reflekt, Rucker Park)
- [ ] Confirm the stats (10+ years, 250+ projects, 80+ brands)
- [ ] Real articles (current titles are samples)
- [ ] Native Arabic copywriter review
- [ ] Connect the contact form (currently prototype-only)

## Deploy

Static site — on Vercel, import the repo with framework preset **Other**, no build command, output directory = root.
