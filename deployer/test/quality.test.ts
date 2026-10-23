import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import sharp from 'sharp';
import { describe, expect, it } from 'vitest';
import { runProcess } from '../src/process/index.js';
import { imageSize } from '../src/quality/image-size.js';
import { fixFontDisplay, fixHtml, headersFile, type FixOptions } from '../src/quality/fixes.js';
import type { Logger } from '../src/types.js';

const log: Logger = { info() {}, warn() {}, progress() {} };
const all: FixOptions = {
  viewport: true,
  lang: 'en',
  metaDescription: true,
  noopener: true,
  imageAlt: true,
  lazyImages: true,
  imageDimensions: true,
  fontDisplay: true,
};
const page = (head: string, body: string, htmlAttrs = '') => `<!DOCTYPE html><html${htmlAttrs}><head><title>T</title>${head}</head><body>${body}</body></html>`;
const sizeOf = (p: string) => (p === 'img/hero.jpg' ? { width: 1200, height: 600 } : p === 'img/logo.png' ? { width: 80, height: 40 } : null);
const titles: Record<string, string> = { 'blog/hello-world/index.html': 'Hello world', 'about/index.html': 'About us', 'index.html': 'Home' };
const fix = (html: string, opts: FixOptions = all, pagePath = 'index.html') => fixHtml(html, { pagePath, sizeOf, titleOf: (f) => titles[f] ?? null }, opts);

describe('imageSize', () => {
  it('reads png, jpeg, webp, gif and svg headers correctly', async () => {
    const mk = (fmt: 'png' | 'jpeg' | 'webp' | 'gif') =>
      sharp({ create: { width: 123, height: 45, channels: 3, background: '#808080' } })
        [fmt]()
        .toBuffer();
    for (const fmt of ['png', 'jpeg', 'webp', 'gif'] as const) expect(imageSize(await mk(fmt)), fmt).toEqual({ width: 123, height: 45 });
    expect(imageSize(Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="64px" height="32"></svg>'))).toEqual({ width: 64, height: 32 });
    expect(imageSize(Buffer.from('<?xml version="1.0"?><svg viewBox="0 0 100 50" xmlns="http://www.w3.org/2000/svg"></svg>'))).toEqual({
      width: 100,
      height: 50,
    });
    expect(imageSize(Buffer.from('not an image at all, just text'))).toBeNull();
  });
  it('swaps dimensions for rotated EXIF jpegs', async () => {
    const rotated = await sharp({ create: { width: 200, height: 100, channels: 3, background: '#fff' } })
      .jpeg()
      .withMetadata({ orientation: 6 })
      .toBuffer();
    expect(imageSize(rotated)).toEqual({ width: 100, height: 200 });
  });
});

describe('font-display', () => {
  it('adds swap only to @font-face blocks lacking font-display', () => {
    const css = '@font-face{font-family:A;src:url(a.woff2)}@font-face{font-family:B;src:url(b.woff2);font-display:optional}a{color:red}';
    const r = fixFontDisplay(css);
    expect(r.count).toBe(1);
    expect(r.css).toBe(
      '@font-face{font-family:A;src:url(a.woff2);font-display:swap}@font-face{font-family:B;src:url(b.woff2);font-display:optional}a{color:red}',
    );
    expect(fixFontDisplay(r.css).count).toBe(0);
  });
  it('also fixes inline <style> blocks in html', () => {
    const r = fix(page('<style>@font-face{font-family:A;src:url(a.woff2);}</style>', '<p>x</p>'));
    expect(r.html).toContain('font-display:swap');
    expect(r.counts.fontDisplay).toBe(1);
  });
});

describe('document fixes', () => {
  it('adds viewport and lang only when missing', () => {
    const r = fix(page('', '<p>x</p>'));
    expect(r.html).toContain('<head><meta name="viewport" content="width=device-width, initial-scale=1"><title>');
    expect(r.html).toContain('<html lang="en">');
    const already = fix(page('<meta name="viewport" content="width=1000">', 'x', ' lang="de"'));
    expect(already.html).toContain('width=1000');
    expect(already.html.match(/name="viewport"/g)).toHaveLength(1);
    expect(already.html).toContain('<html lang="de">');
    expect(already.counts.viewport).toBeUndefined();
    expect(already.counts.lang).toBeUndefined();
  });

  it('derives a meta description from the first real paragraph, escaped and capped', () => {
    const long = 'A "quoted" <b>claim</b> & ' + 'word '.repeat(60);
    const r = fix(page('', `<main><p>short</p><p>${long}</p></main>`));
    const raw = /<meta name="description" content="([^"]*)">/.exec(r.html)![1]!;
    const desc = raw.replace(/&quot;/g, '"').replace(/&amp;/g, '&');
    expect(desc.length).toBeLessThanOrEqual(160);
    expect(raw).toContain('&quot;quoted&quot;');
    expect(desc).not.toContain('<b>');
    expect(desc.endsWith('…')).toBe(true);
  });
  it('does not invent a description for noindex pages, existing descriptions or paragraph-less pages', () => {
    const p = 'This paragraph is comfortably longer than fifty characters in total.';
    expect(fix(page('<meta name="robots" content="noindex">', `<p>${p}</p>`)).html).not.toContain('name="description"');
    expect(fix(page('<meta name="description" content="mine">', `<p>${p}</p>`)).html.match(/name="description"/g)).toHaveLength(1);
    expect(fix(page('', '<p>tiny</p>')).html).not.toContain('name="description"');
  });
});

describe('link fixes', () => {
  it('adds or merges rel for target=_blank, leaving safe links alone', () => {
    const html = page(
      '',
      '<a href="https://x.com" target="_blank">a</a><a href="https://x.com" target="_blank" rel="nofollow">b</a><a href="https://x.com" target=_blank rel="noreferrer">c</a><a href="/in">d</a><a href="x" title="a > b" target="_blank">e</a>',
    );
    const r = fix(html);
    expect(r.html).toContain('<a href="https://x.com" target="_blank" rel="noopener">a</a>');
    expect(r.html).toContain('rel="nofollow noopener"');
    expect(r.html).toContain('<a href="https://x.com" target=_blank rel="noreferrer">c</a>');
    expect(r.html).toContain('<a href="/in">d</a>');
    expect(r.html).toContain('title="a > b" target="_blank" rel="noopener">e</a>');
    expect(r.counts.noopener).toBe(3);
  });
});

describe('image fixes', () => {
  it('adds alt="" only when the attribute is missing', () => {
    const r = fix(page('', '<img src="a.jpg"><img src="b.jpg" alt="B"><img src="c.jpg" alt="">'), { imageAlt: true });
    expect(r.html).toContain('<img src="a.jpg" alt="">');
    expect(r.html).toContain('alt="B"');
    expect(r.counts.imageAlt).toBe(1);
  });

  it('adds intrinsic width/height from the file, completes a half-specified pair, and restores auto height via :where', () => {
    const r = fix(
      page(
        '',
        '<img src="/img/hero.jpg" alt="h"><img src="/img/logo.png" alt="l" width="40"><img src="/img/hero.jpg" alt="x" width="10" height="10"><img src="/missing.jpg" alt="m">',
      ),
      { imageDimensions: true },
    );
    expect(r.html).toContain('<img src="/img/hero.jpg" alt="h" width="1200" height="600">');
    expect(r.html).toContain('width="40" height="20"');
    expect(r.html).toContain('width="10" height="10">');
    expect(r.html).toContain('<img src="/missing.jpg" alt="m">');
    expect(r.html).toContain('<style id="ss-dims">:where(img[width][height]){height:auto}</style></head>');
    expect(r.counts.imageDimensions).toBe(2);
  });

  it('resolves relative image URLs against the page directory', () => {
    const r = fix(page('', '<img src="../img/hero.jpg" alt="">'), { imageDimensions: true }, 'blog/index.html');
    expect(r.html).toContain('width="1200" height="600"');
  });

  it('keeps the first big image eager with high priority and lazy-loads the rest and iframes', () => {
    const imgs = Array.from({ length: 5 }, (_, i) => `<img src="/i${i}.jpg" alt="">`).join('');
    const r = fix(page('', `<img src="/logo.svg" width="40" alt="">${imgs}<iframe src="https://youtube.com/embed/x"></iframe>`), { lazyImages: true });
    expect(r.html).toContain('<img src="/logo.svg" width="40" alt="">'); // tiny: untouched
    expect(r.html).toContain('<img src="/i0.jpg" alt="" fetchpriority="high">');
    expect(r.html).toContain('<img src="/i1.jpg" alt="" loading="lazy" decoding="async">');
    expect(r.html).toContain('<iframe src="https://youtube.com/embed/x" loading="lazy">');
    expect(r.html.match(/fetchpriority/g)).toHaveLength(1);
  });

  it('respects existing loading attributes', () => {
    const r = fix(page('', '<img src="/a.jpg" alt="" loading="eager"><img src="/b.jpg" alt="" loading="lazy"><img src="/c.jpg" alt="">'), { lazyImages: true });
    expect(r.html).toContain('<img src="/a.jpg" alt="" loading="eager">');
    expect(r.html).not.toContain('fetchpriority');
  });
});

describe('link names', () => {
  const only = { linkNames: true } as FixOptions;
  it('labels image-only internal links with the destination page title', () => {
    const r = fix(
      page('', '<a href="/blog/hello-world/"><img src="/a.jpg" alt=""></a><a href="/about/#team"><img src="/b.jpg"></a><a href="../about"><svg></svg></a>'),
      only,
    );
    expect(r.html).toContain('<a href="/blog/hello-world/" aria-label="Hello world"><img');
    expect(r.html).toContain('<a href="/about/#team" aria-label="About us">');
    expect(r.html).toContain('<a href="../about" aria-label="About us"><svg>');
    expect(r.counts.linkNames).toBe(3);
  });
  it('leaves links that already have a name, visible text, a real alt, an external target or an unknown page', () => {
    const html = page(
      '',
      '<a href="/about/" aria-label="x"><img src="/a.jpg"></a><a href="/about/" title="t"><img src="/a.jpg"></a><a href="/about/"><img src="/a.jpg"> Read more</a>' +
        '<a href="/about/"><img src="/a.jpg" alt="Team photo"></a><a href="https://example.org/about/"><img src="/a.jpg"></a><a href="/nowhere/"><img src="/a.jpg"></a><a href="/about/">Text only</a>',
    );
    const r = fix(html, only);
    expect(r.html).toBe(html);
    expect(r.counts.linkNames).toBeUndefined();
  });
  it('trims the site name from titles', async () => {
    const { cleanTitle, hrefToFile } = await import('../src/quality/fixes.js');
    expect(cleanTitle('About us – My Site')).toBe('About us');
    expect(cleanTitle('Hello | Blog')).toBe('Hello');
    expect(cleanTitle('Plain title')).toBe('Plain title');
    expect(cleanTitle('Mid-term plan')).toBe('Mid-term plan');
    expect(hrefToFile('/a/b', 'index.html')).toBe('a/b/index.html');
    expect(hrefToFile('/a/b.html', 'index.html')).toBe('a/b.html');
    expect(hrefToFile('mailto:x@y.z', 'index.html')).toBeNull();
    expect(hrefToFile('//cdn.example/x', 'index.html')).toBeNull();
  });
});

describe('safety', () => {
  it('never edits script, style, comment, textarea or noscript contents', () => {
    const body =
      '<script>var s = \'<img src="/x.jpg"><a href="y" target="_blank">\';</script>' +
      '<!-- <img src="/c.jpg"> --><noscript><img src="/n.jpg"></noscript><textarea><img src="/t.jpg"></textarea><template><img src="/tp.jpg"></template><img src="/real.jpg">';
    const r = fix(page('', body));
    expect(r.html).toContain('\'<img src="/x.jpg"><a href="y" target="_blank">\';');
    expect(r.html).toContain('<!-- <img src="/c.jpg"> -->');
    expect(r.html).toContain('<noscript><img src="/n.jpg"></noscript>');
    expect(r.html).toContain('<textarea><img src="/t.jpg"></textarea>');
    expect(r.html).toContain('<template><img src="/tp.jpg"></template>');
    expect(r.html).toContain('<img src="/real.jpg" alt=');
  });

  it('is idempotent', () => {
    const html = page(
      '<style>@font-face{font-family:A;src:url(a.woff2)}</style>',
      '<main><p>' +
        'A long enough paragraph for a description. '.repeat(3) +
        '</p><img src="/img/hero.jpg"><img src="/b.jpg"><a href="x" target="_blank">l</a></main>',
    );
    const once = fix(html);
    const twice = fix(once.html);
    expect(twice.html).toBe(once.html);
    expect(twice.counts).toEqual({});
  });

  it('leaves non-documents (fragments, xml) untouched', () => {
    expect(fix('<img src="/a.jpg">').html).toBe('<img src="/a.jpg">');
  });
});

describe('headers file', () => {
  it('covers security, long-lived fonts/media and uncached exporter assets', () => {
    const h = headersFile();
    expect(h).toContain('X-Content-Type-Options: nosniff');
    expect(h).toContain('/wp-content/uploads/*\n  Cache-Control: public, max-age=2592000');
    expect(h).toContain('/*.woff2\n  Cache-Control: public, max-age=31536000, immutable');
    expect(h).toContain('/ss-assets/*\n  Cache-Control: public, max-age=0, must-revalidate');
  });
});

describe('process integration', () => {
  it('applies fixes to a real export directory, writes _headers once, and reports counts', async () => {
    const root = await mkdtemp(path.join(os.tmpdir(), 'q-'));
    const dir = path.join(root, 'a');
    await mkdir(path.join(dir, 'img'), { recursive: true });
    await writeFile(
      path.join(dir, 'img/hero.jpg'),
      await sharp({ create: { width: 300, height: 150, channels: 3, background: '#abc' } })
        .jpeg()
        .toBuffer(),
    );
    await writeFile(path.join(dir, 'style.css'), '@font-face{font-family:A;src:url(a.woff2)}');
    await writeFile(path.join(dir, 'index.html'), page('<link rel="stylesheet" href="/style.css">', '<main><img src="/img/hero.jpg"></main>'));
    await writeFile(path.join(dir, '_headers'), 'custom\n');

    const job = { action: 'process' as const, dir, scope: 'full' as const, config: {}, ops: { fixes: { ...all, headers: true } } };
    const r = await runProcess(job, log);
    expect(r.message).toMatch(/fixed 2 file\(s\).*imageDimensions 1/);
    const html = await readFile(path.join(dir, 'index.html'), 'utf8');
    expect(html).toContain('width="300" height="150"');
    expect(await readFile(path.join(dir, 'style.css'), 'utf8')).toContain('font-display:swap');
    expect(await readFile(path.join(dir, '_headers'), 'utf8')).toBe('custom\n'); // user's file is never overwritten
    expect(r.created).toEqual([]);

    const again = await runProcess(job, log);
    expect(again.message).toBe('Processed: no Lighthouse fixes needed');
  });
});
