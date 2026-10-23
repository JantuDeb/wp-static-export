import { mkdir, mkdtemp, readFile, stat, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import sharp from 'sharp';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { matchesAny } from '../src/process/glob.js';
import { runProcess } from '../src/process/index.js';
import { addPictureSources, minifyCssString, minifyHtmlString, minifyJsString } from '../src/process/optimize.js';
import { extractRecord, filePathToUrl, isArchiveUrl, objectId } from '../src/process/search-index.js';
import type { Logger } from '../src/types.js';

const log: Logger = { info() {}, warn() {}, progress() {} };
afterEach(() => vi.unstubAllGlobals());

async function tmp() {
  const root = await mkdtemp(path.join(os.tmpdir(), 'proc-'));
  const dir = path.join(root, 'archive');
  await mkdir(dir);
  return dir;
}

describe('glob', () => {
  it('matches wildcard patterns with or without leading slash', () => {
    expect(matchesAny('blog/post/index.html', ['/blog/*'])).toBe(true);
    expect(matchesAny('about/index.html', ['/blog/*'])).toBe(false);
    expect(matchesAny('a.min.js', ['*.min.js'])).toBe(true);
    expect(matchesAny('x', undefined)).toBe(false);
  });
});

describe('minifiers', () => {
  it('minifies html without gluing inline elements or touching <pre>', async () => {
    const out = await minifyHtmlString(
      '<p>Hello   <b>big</b>  <i>world</i></p>\n<!-- c -->\n<pre>a   b\n  c</pre><script>var   x = 1 ;  console.log( x )</script>',
    );
    expect(out).not.toContain('<!--');
    expect(out).toContain('<b>big</b> <i>world</i>');
    expect(out).toContain('<pre>a   b\n  c</pre>');
    expect(out.length).toBeLessThan(120);
  });
  it('minifies css and js', async () => {
    expect(minifyCssString('a {  color : red ; }\n\nb { margin: 0px }')).toBe('a{color:red}b{margin:0}');
    const js = await minifyJsString('function add(first, second) {\n  return first + second;\n}\nconsole.log(add(1, 2));');
    expect(js.length).toBeLessThan(60);
  });
});

describe('picture rewrite', () => {
  const webp = new Set(['wp-content/uploads/a.jpg.webp', 'wp-content/uploads/a-300.jpg.webp']);
  it('wraps img with webp source preserving srcset descriptors and sizes', () => {
    const html = '<img src="/wp-content/uploads/a.jpg" srcset="/wp-content/uploads/a-300.jpg 300w, /wp-content/uploads/a.jpg 600w" sizes="100vw" alt="x">';
    const out = addPictureSources(html, 'index.html', webp);
    expect(out).toContain(
      '<picture><source type="image/webp" srcset="/wp-content/uploads/a-300.jpg.webp 300w, /wp-content/uploads/a.jpg.webp 600w" sizes="100vw">',
    );
    expect(out.endsWith('</picture>')).toBe(true);
  });
  it('leaves images alone when any variant lacks a webp twin or when already in a picture', () => {
    expect(addPictureSources('<img src="/wp-content/uploads/missing.jpg">', 'index.html', webp)).toContain('<img src="/wp-content/uploads/missing.jpg">');
    expect(addPictureSources('<img src="/wp-content/uploads/missing.jpg">', 'index.html', webp)).not.toContain('<picture>');
    const wrapped = '<picture><img src="/wp-content/uploads/a.jpg"></picture>';
    expect(addPictureSources(wrapped, 'index.html', webp)).toBe(wrapped);
  });
  it('resolves relative URLs against the page directory', () => {
    const out = addPictureSources('<img src="../../wp-content/uploads/a.jpg">', 'blog/post/index.html', webp);
    expect(out).toContain('type="image/webp"');
  });
});

describe('search records', () => {
  it('extracts title and main text, dropping nav/script/footer, and honours noindex', () => {
    const html = `<html lang="de"><head><title>Über uns</title></head><body><nav>MENU</nav><main><h1>Team</h1><p>Wir bauen   Seiten.</p><script>var x=1</script></main><footer>FOOT</footer></body></html>`;
    const rec = extractRecord(html, 'ueber/index.html')!;
    expect(rec).toEqual({ url: '/ueber/', title: 'Über uns', text: 'Team Wir bauen Seiten.', lang: 'de' });
    expect(extractRecord('<html><head><meta name="robots" content="noindex,follow"><title>x</title></head><body>y</body></html>', 'x.html')).toBeNull();
  });
  it('maps files to urls', () => {
    expect(filePathToUrl('index.html')).toBe('/');
    expect(filePathToUrl('a/b/index.html')).toBe('/a/b/');
    expect(filePathToUrl('page.html')).toBe('/page.html');
    expect(objectId('/a/')).toHaveLength(20);
  });
});

describe('archive detection', () => {
  it('recognises WordPress listing pages but not real content', () => {
    for (const u of [
      '/category/news/',
      '/blog/category/news/page/2/',
      '/tag/x/',
      '/author/admin/',
      '/page/3/',
      '/blog/page/2/',
      '/2024/',
      '/2024/05/',
      '/2024/05/17/',
    ])
      expect(isArchiveUrl(u), u).toBe(true);
    for (const u of ['/', '/about/', '/category-guide/', '/pages/', '/hiking-2024/', '/2024-plan/', '/blog/hello/']) expect(isArchiveUrl(u), u).toBe(false);
  });
});

describe('runProcess', () => {
  it('minifies, builds the fuse index with exclusions, and skips unchanged files on rerun', async () => {
    const dir = await tmp();
    await mkdir(path.join(dir, 'private'));
    await writeFile(path.join(dir, 'index.html'), '<html><head><title>Home</title></head><body><main>  Welcome   home </main></body></html>');
    await writeFile(path.join(dir, 'private/index.html'), '<html><head><title>Secret</title></head><body>no</body></html>');
    await writeFile(path.join(dir, '404.html'), '<html><head><title>404</title></head><body>nope</body></html>');
    await writeFile(path.join(dir, 's.css'), 'a  {  color : red }');

    const job = {
      action: 'process' as const,
      dir,
      scope: 'full' as const,
      config: {},
      ops: { optimize: { html: true, css: true }, search: { engine: 'fuse' as const, exclude: ['/private/*'] } },
    };
    const r = await runProcess(job, log);
    expect(r.message).toContain('search index with 1 page');
    expect(await readFile(path.join(dir, 's.css'), 'utf8')).toBe('a{color:red}');
    const index = JSON.parse(await readFile(path.join(dir, 'ss-assets/search-index.json'), 'utf8'));
    expect(index).toHaveLength(1);
    expect(index[0].title).toBe('Home');
    expect(index[0].text).toBe('Welcome home');
  });

  it('leaves archive pages out of the index unless asked', async () => {
    const dir = await tmp();
    await mkdir(path.join(dir, 'category/news'), { recursive: true });
    await mkdir(path.join(dir, 'post'), { recursive: true });
    const html = (t: string) => `<html><head><title>${t}</title></head><body><main>${t} words words</main></body></html>`;
    await writeFile(path.join(dir, 'post/index.html'), html('Post'));
    await writeFile(path.join(dir, 'category/news/index.html'), html('News archive'));
    await runProcess({ action: 'process', dir, scope: 'full', config: {}, ops: { search: { engine: 'fuse' } } }, log);
    expect(JSON.parse(await readFile(path.join(dir, 'ss-assets/search-index.json'), 'utf8')).map((r: any) => r.url)).toEqual(['/post/']);
    await runProcess({ action: 'process', dir, scope: 'full', config: {}, ops: { search: { engine: 'fuse', includeArchives: true } } }, log);
    expect(JSON.parse(await readFile(path.join(dir, 'ss-assets/search-index.json'), 'utf8')).map((r: any) => r.url)).toEqual(['/category/news/', '/post/']);
  });

  it('does not rebuild the index for partial exports', async () => {
    const dir = await tmp();
    await writeFile(path.join(dir, 'index.html'), '<html><title>x</title><body>y</body></html>');
    const warn = vi.fn();
    await runProcess({ action: 'process', dir, scope: 'partial', config: {}, ops: { search: { engine: 'fuse' } } }, { ...log, warn });
    expect(warn).toHaveBeenCalled();
    await expect(stat(path.join(dir, 'ss-assets/search-index.json'))).rejects.toThrow();
  });

  it('pushes records to Algolia: settings, clear, then batched updateObject', async () => {
    const dir = await tmp();
    await writeFile(path.join(dir, 'index.html'), '<html><head><title>Home</title></head><body>hello</body></html>');
    const seen: string[] = [];
    let batch: any;
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
      seen.push(`${init.method} ${new URL(url).pathname}`);
      expect(new Headers(init.headers).get('x-algolia-api-key')).toBe('ADMIN');
      if (url.endsWith('/batch')) batch = JSON.parse(String(init.body));
      return new Response('{}', { status: 200 });
    });
    await runProcess(
      {
        action: 'process',
        dir,
        scope: 'full',
        config: {},
        ops: { search: { engine: 'algolia', algolia: { appId: 'APP', adminKey: 'ADMIN', index: 'site' } } },
      },
      log,
    );
    expect(seen).toEqual(['PUT /1/indexes/site/settings', 'POST /1/indexes/site/clear', 'POST /1/indexes/site/batch']);
    expect(batch.requests[0].action).toBe('updateObject');
    expect(batch.requests[0].body.title).toBe('Home');
  });

  it('compresses real images with sharp, makes webp twins and rewrites html to <picture>', async () => {
    const dir = await tmp();
    await mkdir(path.join(dir, 'wp-content/uploads'), { recursive: true });
    // Photo-like: smooth gradient plus light noise, so q60 JPEG < q100 JPEG and WebP < JPEG.
    const raw = Buffer.alloc(400 * 400 * 3);
    for (let i = 0; i < raw.length; i++) {
      const px = Math.floor(i / 3);
      raw[i] = ((px % 400) + Math.floor(px / 400)) / 3 + (i % 7);
    }
    const big = await sharp(raw, { raw: { width: 400, height: 400, channels: 3 } })
      .jpeg({ quality: 100 })
      .toBuffer();
    await writeFile(path.join(dir, 'wp-content/uploads/photo.jpg'), big);
    await writeFile(path.join(dir, 'index.html'), '<html><body><img src="/wp-content/uploads/photo.jpg" alt=""></body></html>');

    const r = await runProcess({ action: 'process', dir, scope: 'full', config: {}, ops: { optimize: { images: true, webp: true, quality: 60 } } }, log);
    const after = (await stat(path.join(dir, 'wp-content/uploads/photo.jpg'))).size;
    expect(after).toBeLessThan(big.length);
    expect((await stat(path.join(dir, 'wp-content/uploads/photo.jpg.webp'))).size).toBeLessThan(after);
    expect(await readFile(path.join(dir, 'index.html'), 'utf8')).toContain('<picture><source type="image/webp" srcset="/wp-content/uploads/photo.jpg.webp">');
    expect(r.message).toContain('WebP');

    // Second run must not re-encode (state records the optimized hash).
    const mtime = (await stat(path.join(dir, 'wp-content/uploads/photo.jpg'))).mtimeMs;
    await runProcess({ action: 'process', dir, scope: 'full', config: {}, ops: { optimize: { images: true, webp: true, quality: 60 } } }, log);
    expect((await stat(path.join(dir, 'wp-content/uploads/photo.jpg'))).mtimeMs).toBe(mtime);
  });
});
