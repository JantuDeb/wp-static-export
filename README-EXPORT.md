# Static export for WordPress

A fork of the GPL-2.0+ WP Static Export plugin with deployment targets, forms, search,
optimization, comments, multilingual support and Lighthouse tooling. PHP does the export;
TypeScript (in `deployer/`) does everything that talks to other services or runs in the browser.

> Rename the plugin before you distribute it. "WP Static Export" is its authors' trademark.

## Build

```bash
cd deployer
npm install
npm run build      # dist/cli.cjs (server), dist/admin/settings.js, dist/web/{forms,search}.js
npm test           # builds, then runs unit tests and tests of the built bundle
```

`deployer/dist/` is committed so the plugin works when installed from a zip.
Requirements on the web server: PHP `exec()` enabled and Node.js 18+ (only for deploy
targets, optimization, search indexing and Lighthouse fixes). Forms, comments and
multilingual need no Node. For image compression also run `npm install sharp` in `deployer/`.

## Where to configure

**WP Static Export → Deploy & Features.** Secrets (tokens, keys, passwords) are write-only: the
page shows "saved" but never sends them back. Any setting can be fixed with a
`SIMPLY_STATIC_<OPTION>` constant in `wp-config.php`; the page then shows it as locked.
Use **Save & test connection** to check credentials without deploying.

## Deploy targets

GitHub, Netlify, Cloudflare Pages, S3 (and R2, MinIO, Spaces), BunnyCDN, SFTP.
Deploys are incremental: file hashes from the last successful deploy are kept in
`uploads/ss-deploy-state/`, so only changed files are uploaded. Single/update exports never
delete anything; a full export won't delete more than half of what was deployed before (this
guards against a broken crawl wiping the live site).

```bash
wp simply-static run --deploy-to=netlify      # export and deploy
wp simply-static run --type=update            # only changed pages
wp simply-static run --force-full             # ignore saved hashes
wp simply-static doctor                       # checks Node and the bundle
```

## Forms

Each rule maps forms (`*`, `#id`, `.class`, or `cf7`, `wpforms`, `gravityforms`, `fluentform`)
to a destination. WordPress form plugins are detached from `admin-ajax`/REST and submissions
go straight from the browser. Includes a honeypot and a minimum-fill-time check.

| Destination | "URL / ID" | Field map |
|---|---|---|
| **Google Form** | form ID (`1FAIpQLS…`) or the `/viewform` URL | **required**: `field=entry.123456` per line |
| **Google Sheet** | Apps Script web-app URL | optional: `field=Column name` |
| Netlify Forms | – | optional rename |
| Formspree | form ID or URL | optional rename |
| Webhook | URL | optional rename |
| mailto: | address | optional rename |

**Google Form:** open the form, ⋮ → *Get pre-filled link*, fill every question with a
placeholder, *Get link*. The link contains `entry.NNN=` for each question; map your WordPress
field names to those ids. Single-page forms only; Google gives no response (the page assumes
success), and the form must not require sign-in or file upload.

**Google Sheet:** paste `docs/google-sheet-apps-script.gs` into a Sheet's Apps Script, deploy
as a web app that anyone can access, and use the `/exec` URL. New fields become columns.

The endpoint (including a webhook URL) is visible in the page source. Do not put secrets there.

## Search

Fuse.js: an index is built from the exported HTML (full exports only) and loaded on first
use. Algolia: records are pushed with the admin key (server only); pages ship the search-only key.
Add `<div data-ss-search></div>` anywhere, or enable "Replace WordPress search forms".

## Optimization and quality fixes

Run on the exported files, never on your WordPress site: HTML/CSS/JS minification, image
compression with WebP `<picture>` sources, and safe fixes for Lighthouse findings: viewport,
`lang`, image `alt`/`width`/`height`, lazy loading with a prioritized first image,
`font-display: swap`, `rel=noopener`, names for image-only links, a `_headers` file for caching
(Netlify/Cloudflare Pages) and an opt-in generated meta description.

## Lighthouse

```bash
cd deployer
npm run lighthouse:demo                                  # sample site, before/after
npm run lighthouse -- --dir /path/to/export              # audit
npm run lighthouse -- --dir /path/to/export --fix --optimize --runs 3 --out report.md
npm run lighthouse -- --dir /path/to/export --min performance=90,seo=95   # CI gate
```

It serves the export like a static host would (gzip, 404 page, `_headers`), samples pages,
reports the median of `--runs`, and lists remaining findings with whether an automatic fix
exists. `--fix` works on a copy unless you pass `--write`. Needs Chromium (`CHROME_PATH`).
Scores come from one machine's simulated throttling: compare runs on the same machine.
