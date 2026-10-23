import { readFile, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parse } from 'node-html-parser';
import type { FileEntry, Logger } from '../types.js';
import { resolveSitePath } from '../process/paths.js';
import { imageSize, type Size } from './image-size.js';

/** Each option maps to the Lighthouse audit(s) it addresses. */
export interface FixOptions {
  /** meta-viewport */
  viewport?: boolean;
  /** html-has-lang: language code to set when <html> has none */
  lang?: string;
  /** meta-description (heuristic: first paragraph) */
  metaDescription?: boolean;
  /** external-anchors-use-rel-noopener */
  noopener?: boolean;
  /** image-alt (adds alt="" = decorative) */
  imageAlt?: boolean;
  /** offscreen-images + prioritize-lcp-image */
  lazyImages?: boolean;
  /** link-name: label image-only links with the destination page's title */
  linkNames?: boolean;
  /** unsized-images (CLS) */
  imageDimensions?: boolean;
  /** font-display */
  fontDisplay?: boolean;
  /** uses-long-cache-ttl + best-practices headers, written as a Netlify/Cloudflare _headers file */
  headers?: boolean;
}

export type Counts = Record<string, number>;

export interface FixContext {
  /** Path of the page inside the export, e.g. "blog/post/index.html". */
  pagePath: string;
  /** Intrinsic size of an image given its export path. */
  sizeOf?: (sitePath: string) => Size | null;
  /** Title of an exported page given its file path inside the export. */
  titleOf?: (filePath: string) => string | null;
}

// ---- tag helpers --------------------------------------------------------------------------

const tagRe = (name: string) => new RegExp(`<${name}\\b(?:[^>"']|"[^"]*"|'[^']*')*>`, 'gi');

function attr(tag: string, name: string): string | undefined {
  const m = new RegExp(`\\s${name}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s>]+))`, 'i').exec(tag);
  if (m) return m[1] ?? m[2] ?? m[3] ?? '';
  return new RegExp(`\\s${name}(?=[\\s/>])`, 'i').test(tag) ? '' : undefined;
}

/** Insert attributes just before the tag's closing `>` / `/>`, leaving everything else byte-identical. */
function addAttrs(tag: string, attrs: string): string {
  return tag.replace(/\s*(\/?)>$/, ` ${attrs}$1>`);
}

const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** Hide regions whose contents must never be edited, then put them back. */
function protect(html: string): { text: string; restore: (s: string) => string } {
  const saved: string[] = [];
  const text = html.replace(/<!--[\s\S]*?-->|<(script|style|textarea|template|noscript)\b[\s\S]*?<\/\1\s*>/gi, (m) => {
    saved.push(m);
    return `\u0000P${saved.length - 1}\u0000`;
  });
  return { text, restore: (s) => s.replace(/\u0000P(\d+)\u0000/g, (_, i) => saved[Number(i)] as string) };
}

// ---- CSS ----------------------------------------------------------------------------------

export function fixFontDisplay(css: string): { css: string; count: number } {
  let count = 0;
  const out = css.replace(/@font-face\s*\{[^}]*\}/gi, (block) => {
    if (/font-display\s*:/i.test(block)) return block;
    count++;
    return block.replace(/;?\s*\}$/, ';font-display:swap}');
  });
  return { css: out, count };
}

// ---- HTML ---------------------------------------------------------------------------------

function firstParagraph(html: string): string | null {
  const root = parse(html);
  const scope = root.querySelector('main') ?? root.querySelector('article') ?? root.querySelector('body') ?? root;
  for (const p of scope.querySelectorAll('p')) {
    const text = p.text.replace(/\s+/g, ' ').trim();
    if (text.length >= 50) return text;
  }
  return null;
}

function truncate(text: string, max = 155): string {
  if (text.length <= max) return text;
  const cut = text.slice(0, max);
  return cut.slice(0, Math.max(cut.lastIndexOf(' '), 100)).replace(/[\s,;:.-]+$/, '') + '…';
}

/** "About us – My Site" -> "About us". Leaves titles without a separator alone. */
export function cleanTitle(title: string): string {
  const t = title.replace(/\s+/g, ' ').trim();
  const parts = t.split(/\s+[–—|·-]\s+/);
  return (parts[0] && parts[0].length > 2 ? parts[0] : t).trim();
}

/** Export file that serves an internal link target, e.g. "/blog/x/#c" -> "blog/x/index.html". */
export function hrefToFile(href: string, pagePath: string): string | null {
  const site = resolveSitePath(href.split('#')[0] ?? '', pagePath);
  if (site === null || /^[a-z][a-z0-9+.-]*:/i.test(href) || href.startsWith('//')) return null;
  if (site === '' || site.endsWith('/')) return site + 'index.html';
  return /\.[a-z0-9]+$/i.test(site) ? site : site + '/index.html';
}

export function fixHtml(input: string, ctx: FixContext, opts: FixOptions): { html: string; counts: Counts } {
  const counts: Counts = {};
  const bump = (k: string, n = 1) => {
    if (n > 0) counts[k] = (counts[k] ?? 0) + n;
  };
  if (!/<html[\s>]/i.test(input)) return { html: input, counts };

  // <style> blocks are CSS; fix them before they get hidden.
  let html = input;
  if (opts.fontDisplay) {
    html = html.replace(/(<style\b[^>]*>)([\s\S]*?)(<\/style\s*>)/gi, (_, open, css, close) => {
      const r = fixFontDisplay(css);
      bump('fontDisplay', r.count);
      return open + r.css + close;
    });
  }

  const robots = /<meta\b[^>]*name=["']robots["'][^>]*content=["'][^"']*noindex/i.test(html);
  const { text: body, restore } = protect(html);
  let text = body;

  if (opts.lang) {
    text = text.replace(/<html\b(?:[^>"']|"[^"]*"|'[^']*')*>/i, (tag) => {
      if (attr(tag, 'lang') !== undefined) return tag;
      bump('lang');
      return addAttrs(tag, `lang="${esc(opts.lang as string)}"`);
    });
  }

  if (opts.viewport && !/<meta\b[^>]*name=["']viewport["']/i.test(restore(text))) {
    text = text.replace(/<head\b[^>]*>/i, (tag) => {
      bump('viewport');
      return tag + '<meta name="viewport" content="width=device-width, initial-scale=1">';
    });
  }

  if (opts.metaDescription && !robots && !/<meta\b[^>]*name=["']description["']/i.test(restore(text))) {
    const p = firstParagraph(restore(text));
    if (p) {
      text = text.replace(/<\/head\s*>/i, (tag) => {
        bump('metaDescription');
        return `<meta name="description" content="${esc(truncate(p))}">` + tag;
      });
    }
  }

  if (opts.noopener) {
    text = text.replace(tagRe('a'), (tag) => {
      if (!/^_blank$/i.test(attr(tag, 'target') ?? '')) return tag;
      const rel = attr(tag, 'rel');
      if (rel !== undefined && /\b(noopener|noreferrer)\b/i.test(rel)) return tag;
      bump('noopener');
      if (rel === undefined) return addAttrs(tag, 'rel="noopener"');
      return tag.replace(/(\srel\s*=\s*)(?:"([^"]*)"|'([^']*)')/i, (_m, pre, a, b) =>
        `${pre}"${(a ?? b ?? '').trim()} noopener"`.replace('" noopener"', '"noopener"'),
      );
    });
  }

  if (opts.linkNames && ctx.titleOf) {
    text = text.replace(/<a\b(?:[^>"']|"[^"]*"|'[^']*')*>([\s\S]*?)<\/a\s*>/gi, (whole, inner: string) => {
      const open = /^<a\b(?:[^>"']|"[^"]*"|'[^']*')*>/i.exec(whole)?.[0] as string;
      const href = attr(open, 'href');
      if (!href || attr(open, 'aria-label') !== undefined || attr(open, 'aria-labelledby') !== undefined || attr(open, 'title') !== undefined) return whole;
      // Only image/icon-only links: any visible text already names the link.
      if (!/<(img|svg)\b/i.test(inner) || inner.replace(/<[^>]*>/g, '').replace(/&nbsp;|\s/g, '') !== '') return whole;
      if ([...inner.matchAll(/<img\b(?:[^>"']|"[^"]*"|'[^']*')*>/gi)].some((m) => (attr(m[0], 'alt') ?? '').trim() !== '')) return whole;
      const file = hrefToFile(href, ctx.pagePath);
      const title = file ? ctx.titleOf?.(file) : null;
      if (!title) return whole;
      bump('linkNames');
      return addAttrs(open, `aria-label="${esc(title)}"`) + whole.slice(open.length);
    });
  }

  let dimsAdded = 0;
  let imgIndex = 0;
  let lcpMarked = false;
  if (opts.imageAlt || opts.lazyImages || opts.imageDimensions) {
    text = text.replace(tagRe('img'), (tag) => {
      let t = tag;
      const i = imgIndex++;

      if (opts.imageAlt && attr(t, 'alt') === undefined) {
        t = addAttrs(t, 'alt=""');
        bump('imageAlt');
      }

      if (opts.imageDimensions && ctx.sizeOf) {
        const w = attr(t, 'width');
        const h = attr(t, 'height');
        const src = attr(t, 'src');
        const numeric = (v: string | undefined) => v !== undefined && /^\d+$/.test(v);
        if (src && (!numeric(w) || !numeric(h)) && !(w?.includes('%') || h?.includes('%'))) {
          const site = resolveSitePath(src, ctx.pagePath);
          const size = site ? ctx.sizeOf(site) : null;
          if (size && size.width > 0 && size.height > 0) {
            const adds: string[] = [];
            if (numeric(w) && !numeric(h)) adds.push(`height="${Math.round((Number(w) * size.height) / size.width)}"`);
            else if (numeric(h) && !numeric(w)) adds.push(`width="${Math.round((Number(h) * size.width) / size.height)}"`);
            else if (!numeric(w) && !numeric(h)) adds.push(`width="${size.width}"`, `height="${size.height}"`);
            if (adds.length) {
              t = addAttrs(t, adds.join(' '));
              dimsAdded++;
              bump('imageDimensions');
            }
          }
        }
      }

      const prioritized = attr(t, 'loading')?.toLowerCase() === 'eager' || attr(t, 'fetchpriority')?.toLowerCase() === 'high';
      if (prioritized) {
        // Author-marked (or marked by a previous run): this is the LCP candidate, leave it alone.
        lcpMarked = true;
      } else if (opts.lazyImages && attr(t, 'loading') === undefined) {
        // The first big image is the likeliest largest-contentful-paint element: load it early, never lazily.
        const tiny = Number(attr(t, 'width') ?? 9999) < 100;
        if (!lcpMarked && i < 3 && !tiny) {
          lcpMarked = true;
          t = addAttrs(t, 'fetchpriority="high"');
          bump('lcpPriority');
        } else if (lcpMarked || i >= 3) {
          t = addAttrs(t, attr(t, 'decoding') === undefined ? 'loading="lazy" decoding="async"' : 'loading="lazy"');
          bump('lazyImages');
        }
      }
      return t;
    });
  }

  if (opts.lazyImages) {
    text = text.replace(tagRe('iframe'), (tag) => {
      if (attr(tag, 'loading') !== undefined) return tag;
      bump('lazyIframes');
      return addAttrs(tag, 'loading="lazy"');
    });
  }

  // Width/height attributes must not stretch images that CSS scales down. This zero-specificity rule
  // restores auto height unless the theme sets one itself.
  if (dimsAdded && !/id=["']ss-dims["']/.test(restore(text))) {
    text = text.replace(/<\/head\s*>/i, (tag) => '<style id="ss-dims">:where(img[width][height]){height:auto}</style>' + tag);
  }

  return { html: restore(text), counts };
}

// ---- _headers -----------------------------------------------------------------------------

/** Netlify / Cloudflare Pages `_headers` file: sane caching + a few security headers. */
export function headersFile(): string {
  return `# Generated by the static exporter. Delete this file to manage headers yourself.
/*
  X-Content-Type-Options: nosniff
  Referrer-Policy: strict-origin-when-cross-origin

/wp-content/uploads/*
  Cache-Control: public, max-age=2592000

/wp-content/themes/*
  Cache-Control: public, max-age=86400

/wp-content/plugins/*
  Cache-Control: public, max-age=86400

/wp-includes/*
  Cache-Control: public, max-age=86400

/*.woff2
  Cache-Control: public, max-age=31536000, immutable

/ss-assets/*
  Cache-Control: public, max-age=0, must-revalidate
`;
}

// ---- driver -------------------------------------------------------------------------------

export interface FixResult {
  files: number;
  counts: Counts;
  created: string[];
}

export async function applyFixes(dir: string, files: FileEntry[], opts: FixOptions, log: Logger): Promise<FixResult> {
  const result: FixResult = { files: 0, counts: {}, created: [] };
  const add = (c: Counts) => Object.entries(c).forEach(([k, v]) => (result.counts[k] = (result.counts[k] ?? 0) + v));

  const titles = new Map<string, string>();
  if (opts.linkNames) {
    for (const f of files.filter((x) => /\.html?$/i.test(x.path))) {
      const m = /<title[^>]*>([\s\S]*?)<\/title>/i.exec(await readFile(f.abs, 'utf8'));
      const t = m ? cleanTitle(m[1] as string) : '';
      if (t) titles.set(f.path, t);
    }
  }
  const titleOf = (file: string) => titles.get(file) ?? null;

  const sizes = new Map<string, Size | null>();
  const sizeOf = (sitePath: string): Size | null => {
    if (!sizes.has(sitePath)) sizes.set(sitePath, null);
    return sizes.get(sitePath) ?? null;
  };
  if (opts.imageDimensions) {
    // Preload sizes for every image once (async), so the per-tag callback can stay synchronous.
    for (const f of files.filter((x) => /\.(png|jpe?g|gif|webp|svg)$/i.test(x.path))) {
      try {
        sizes.set(f.path, imageSize(await readFile(f.abs)));
      } catch {
        sizes.set(f.path, null);
      }
    }
  }

  for (const f of files) {
    try {
      if (/\.html?$/i.test(f.path)) {
        const src = await readFile(f.abs, 'utf8');
        const r = fixHtml(src, { pagePath: f.path, sizeOf, titleOf }, opts);
        if (r.html !== src) {
          await writeFile(f.abs, r.html);
          result.files++;
        }
        add(r.counts);
      } else if (opts.fontDisplay && /\.css$/i.test(f.path)) {
        const src = await readFile(f.abs, 'utf8');
        const r = fixFontDisplay(src);
        if (r.count) {
          await writeFile(f.abs, r.css);
          result.files++;
          add({ fontDisplay: r.count });
        }
      }
    } catch (e) {
      log.warn(`Could not apply fixes to ${f.path}: ${(e as Error).message}`);
    }
  }

  if (opts.headers) {
    const target = path.join(dir, '_headers');
    const exists = await stat(target)
      .then(() => true)
      .catch(() => false);
    if (!exists) {
      await writeFile(target, headersFile());
      result.created.push('_headers');
    }
  }
  return result;
}
