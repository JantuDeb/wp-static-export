# Static export for WordPress

A fork of the GPL-2.0+ WP Static Export plugin with deployment targets, forms, search,
optimization, comments, multilingual support and Lighthouse tooling. It is PHP only on the server: the
deploy and optimization engine lives in `src/engine`, and TypeScript (in `deployer/`) builds only what runs
in the browser (the admin screens and the form and search scripts).

> Rename the plugin before you distribute it. "WP Static Export" is its authors' trademark.

## Requirements

PHP 7.4+ with the DOM extension. Image optimization needs the GD extension (WebP needs GD built with WebP,
which is the default in current PHP). Nothing else: no Node.js, no `exec()`, no command line access. Run
`wp static-export doctor` to see what a server supports.

Large sites are handled in time-boxed steps (about 8 seconds each) by the background queue, so PHP's
`max_execution_time` and slow hosts are not a problem. Deploys are incremental and resume where they stopped.

## Performance

`php scripts/bench.php [pages] [step-seconds] [images] [page-kb]` builds a synthetic export and times every engine stage
with peak memory. Measured on a small cloud VM, 20,000 pages of 20 KB (400 MB), 8-second steps, HTTP faked so only the
engine's own cost shows:

| Stage | Time | Peak memory |
|---|---|---|
| Deploy, first run (hash, plan, 20,051 uploads) | 4.6 s | 26 MB |
| Deploy, nothing changed | 2.2 s | 29 MB |
| Lighthouse fixes | 8.6 s | 14 MB |
| Minify HTML and CSS | 18.9 s | 34 MB |
| Search index | 10.6 s | 14 MB (skipped above 30 MB of text: use Algolia) |
| Images and WebP (50 images) | 8.5 s | 16 MB |
| Site check | 4.1 s | 9 MB |

Real exports are dominated by crawling the site, not by these stages (the 7,150-page learny site took 4 minutes 21 seconds
end to end). Memory does not grow with the number of files: state is kept on disk and each step loads only what it needs.
The budgets are enforced by `WPSE_PERF=1 vendor/bin/phpunit tests/Performance`, which also checks that time per page does
not grow as the site gets bigger.

## Build

```bash
composer install && composer prefix-vendor   # copies the minifier and SFTP libraries to vendor-prefixed/ under this plugin's own namespace
cd deployer
npm install
npm run build      # dist/admin/{settings,report}.js, dist/web/{forms,search}.js
npm test
cd .. && ./scripts/build-release.sh           # the installable ZIP in build/release/
```

`deployer/dist/` and `vendor-prefixed/` are committed so the plugin works when installed straight from the
repository. The engine's tests run with `vendor/bin/phpunit tests/Unit/Engine`.

## Where to configure

**WP Static Export → Deploy & Features.** Secrets (tokens, keys, passwords) are write-only: the
page shows "saved" but never sends them back. Any setting can be fixed with a
`WPSE_<OPTION>` constant in `wp-config.php`; the page then shows it as locked.
Use **Save & test connection** to check credentials without deploying.

## Deploy targets

GitHub, Netlify, Cloudflare Pages, Cloudflare Workers (static assets), S3 (and R2, MinIO, Spaces), BunnyCDN, SFTP.
Deploys are incremental: file hashes from the last successful deploy are kept in
`uploads/ss-deploy-state/`, so only changed files are uploaded. Single/update exports never
delete anything; a full export won't delete more than half of what was deployed before (this
guards against a broken crawl wiping the live site).

```bash
wp static-export run --deploy-to=netlify      # export and deploy
wp static-export run --type=update            # only changed pages
wp static-export run --force-full             # ignore saved hashes
wp static-export doctor                       # checks what this server can do
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

## Deliver an export you already have

An export stays on disk after it finishes. To push it again (after a failed deploy, a fixed token, or to a different target) pick **Deliver existing export (no crawl)** in the Generate page drop-down, use **Deliver** on the Export Report page, or run `wp static-export run --existing [--deploy-to=<target>]`. Nothing is crawled, optimized or verified again; the files on disk go to the configured delivery method. Starting a normal full export replaces the files on disk.

## Export report (checking nothing was missed)

After every full export the plugin compares **every published post, page and custom post type** with the files it produced and stores one row per item. Open **WP Static Export > Export Report**:

- A verdict at the top ("All 152 published items are in the export" or "3 of 152 are missing or broken").
- Cards per status (Exported, Missing, Error, Empty file, Redirect, Excluded) that filter the table when clicked, and coverage per content type.
- A searchable, sortable, paginated table with title, URL path, exported file, HTTP status and size; "Export now" re-exports a single broken item. Works the same on a phone.
- **Download CSV** streams the (filtered) report, so it works for very large sites.
- **Verify now** re-checks the posts against the last export without exporting again, with live progress.
- **Site checks**: broken internal links, pages that contain PHP/WordPress error text, empty files and links that need WordPress (`/wp-json`, `xmlrpc.php`) and so will not work on a static host.
- Setting "Stop before delivery if published items are missing" keeps a broken export from being uploaded.

Large sites: posts are checked in batches of 200 by ID, with a time limit per request, and the report tables are indexed and paged on the server, so memory does not grow with site size. The site check scans 30,000 pages in about 10 seconds.

## Lighthouse

```bash
cd deployer
npm run lighthouse:demo                                  # sample site, before/after (needs the php CLI for the fixes)
npm run lighthouse -- --dir /path/to/export              # audit
npm run lighthouse -- --dir /path/to/export --fix --optimize --runs 3 --out report.md
npm run lighthouse -- --dir /path/to/export --min performance=90,seo=95   # CI gate
```

It serves the export like a static host would (gzip, 404 page, `_headers`), samples pages,
reports the median of `--runs`, and lists remaining findings with whether an automatic fix
exists. `--fix` works on a copy unless you pass `--write`. Needs Chromium (`CHROME_PATH`).
Scores come from one machine's simulated throttling: compare runs on the same machine.
