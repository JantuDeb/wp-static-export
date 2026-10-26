import { opendir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { parse } from 'node-html-parser';
import type { Logger } from './types.js';

export interface VerifySiteJob {
  action: 'verify_site';
  dir: string;
  /** Hostnames that mean "this site" in absolute links (the WordPress host and the destination host). */
  hosts?: string[];
  /** Stop after this many HTML files; the result says so. */
  maxFiles?: number;
}

export interface VerifySiteResult {
  checked: number;
  broken_links: { from: string; to: string; status: number }[];
  empty_files: string[];
  error_pages: { path: string; signature: string }[];
  wp_only: { path: string; url: string }[];
  no_title: number;
  broken_total: number;
  truncated: boolean;
}

/** Each list in the result is capped so the report stays small; the totals keep counting. */
const LIST_CAP = 200;

/** Things that only exist on a running WordPress and 404 or download PHP on a static host. */
const WP_ONLY = /^\/(wp-json(\/|$)|xmlrpc\.php|wp-login\.php|wp-admin(\/|$)|wp-cron\.php|\?rest_route=|wp-comments-post\.php)/i;

const ERROR_SIGNATURES: [RegExp, string][] = [
  [/There has been a critical error on this website/i, 'WordPress critical error'],
  [/Error establishing a database connection/i, 'Database connection error'],
  [/<b>(Fatal error|Parse error)<\/b>:|(^|\n)(PHP )?(Fatal|Parse) error:/i, 'PHP error'],
  [/<b>(Warning|Notice|Deprecated)<\/b>:\s+.*\s+in\s+<b>/i, 'PHP warning printed in the page'],
  [/Briefly unavailable for scheduled maintenance/i, 'Maintenance mode page'],
];

/** Pure: does this file's HTML look like an error page rather than content? */
export function errorSignature(html: string): string | null {
  const head = html.slice(0, 300_000);
  for (const [re, name] of ERROR_SIGNATURES) if (re.test(head)) return name;
  return null;
}

async function* walk(dir: string, base = ''): AsyncGenerator<{ rel: string; size: number }> {
  const handle = await opendir(path.join(dir, base));
  for await (const e of handle) {
    const rel = base ? `${base}/${e.name}` : e.name;
    if (e.isDirectory()) yield* walk(dir, rel);
    else if (e.isFile()) yield { rel, size: (await stat(path.join(dir, rel))).size };
  }
}

/** Is a link target present in the export, the way a static host would serve it? */
function exists(files: Set<string>, p: string): boolean {
  const clean = p.replace(/^\/+/, '').replace(/\/+$/, '');
  if (clean === '') return files.has('index.html');
  return files.has(clean) || files.has(clean + '/index.html') || files.has(clean + '.html');
}

export async function verifySite(job: VerifySiteJob, log: Logger): Promise<VerifySiteResult> {
  const max = job.maxFiles ?? 200_000;
  const hosts = new Set((job.hosts ?? []).map((h) => h.toLowerCase()));
  const files = new Set<string>();
  const htmlFiles: string[] = [];
  const result: VerifySiteResult = { checked: 0, broken_links: [], empty_files: [], error_pages: [], wp_only: [], no_title: 0, broken_total: 0, truncated: false };

  for await (const f of walk(job.dir)) {
    files.add(f.rel);
    if (f.size === 0 && result.empty_files.length < LIST_CAP) result.empty_files.push(f.rel);
    if (/\.html?$/i.test(f.rel)) htmlFiles.push(f.rel);
  }
  if (htmlFiles.length > max) {
    htmlFiles.length = max;
    result.truncated = true;
  }

  const seenBroken = new Set<string>();
  const seenWp = new Set<string>();

  const check = async (rel: string) => {
    const html = await readFile(path.join(job.dir, rel), 'utf8');
    if (!html.trim()) return;
    const isErrorPage404 = /(^|\/)404\.html$/.test(rel);
    const sig = isErrorPage404 ? null : errorSignature(html);
    if (sig && result.error_pages.length < LIST_CAP) result.error_pages.push({ path: rel, signature: sig });

    const root = parse(html, { blockTextElements: { script: false, style: false, noscript: false, pre: true } });
    const title = root.querySelector('title')?.text.trim();
    const redirect = root.querySelector('meta[http-equiv="refresh" i]');
    if (!title && !redirect) result.no_title++;

    const dir = path.posix.dirname('/' + rel);
    const pageUrl = '/' + rel.replace(/(^|\/)index\.html$/, '$1');
    const targets: string[] = [];
    for (const el of root.querySelectorAll('a[href], img[src], script[src], link[href]')) {
      // <link> also carries alternate/oembed/EditURI hints that nothing needs; only assets matter.
      if (el.tagName === 'LINK' && !/\b(stylesheet|icon|preload|modulepreload)\b/i.test(el.getAttribute('rel') ?? '')) continue;
      const v = el.getAttribute('href') ?? el.getAttribute('src');
      if (v) targets.push(v);
    }
    for (const raw of targets) {
      const v = raw.trim();
      if (!v || /^(data:|blob:|mailto:|tel:|javascript:|sms:|#)/i.test(v)) continue;
      let u: URL;
      try {
        u = new URL(v, 'https://self.invalid' + (dir === '/' ? '/' : dir + '/'));
      } catch {
        continue;
      }
      if (u.host !== 'self.invalid' && !hosts.has(u.hostname.toLowerCase())) continue; // someone else's site
      const target = decodeURIComponent(u.pathname);
      if (WP_ONLY.test(target + u.search) || /rest_route=/.test(u.search)) {
        const k = target + u.search;
        if (!seenWp.has(k)) {
          seenWp.add(k);
          if (result.wp_only.length < LIST_CAP) result.wp_only.push({ path: pageUrl, url: k });
        }
        continue;
      }
      if (!exists(files, target)) {
        if (seenBroken.has(target)) continue;
        seenBroken.add(target);
        result.broken_total++;
        if (result.broken_links.length < LIST_CAP) result.broken_links.push({ from: pageUrl, to: target, status: 404 });
      }
    }
  };

  let done = 0;
  const queue = [...htmlFiles];
  const worker = async () => {
    for (let rel = queue.pop(); rel !== undefined; rel = queue.pop()) {
      try {
        await check(rel);
      } catch (e) {
        log.warn(`Could not check ${rel}: ${e instanceof Error ? e.message : String(e)}`);
      }
      result.checked++;
      if (++done % 500 === 0) log.progress(done, htmlFiles.length, 'pages checked');
    }
  };
  await Promise.all(Array.from({ length: 8 }, worker));
  log.progress(htmlFiles.length, htmlFiles.length, 'pages checked');
  return result;
}
