import { mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { errorSignature, verifySite } from '../src/verify-site.js';

const log = { info() {}, warn() {}, progress() {} };

async function site(files: Record<string, string>) {
  const dir = await mkdtemp(path.join(tmpdir(), 'vs-'));
  for (const [rel, body] of Object.entries(files)) {
    await mkdir(path.dirname(path.join(dir, rel)), { recursive: true });
    await writeFile(path.join(dir, rel), body);
  }
  return dir;
}

const page = (body: string, title = 'T') => `<html><head><title>${title}</title></head><body>${body}</body></html>`;

describe('verifySite', () => {
  it('finds nothing wrong in a healthy site', async () => {
    const dir = await site({
      'index.html': page('<a href="/about/">a</a><a href="https://wp.test/about/">b</a><img src="/img/a.png"><a href="https://other.com/x">x</a>'),
      'about/index.html': page('<a href="../">home</a><script src="/app.js"></script>'),
      'img/a.png': 'x',
      'app.js': 'x',
    });
    const r = await verifySite({ action: 'verify_site', dir, hosts: ['wp.test'] }, log);
    expect(r.broken_links).toEqual([]);
    expect(r.checked).toBe(2);
    expect(r.no_title).toBe(0);
  });

  it('reports broken links once, with where they were found', async () => {
    const dir = await site({
      'index.html': page('<a href="/gone/">x</a><a href="/gone/#top">y</a><img src="/missing.png">'),
      'b/index.html': page('<a href="/gone/">z</a>'),
    });
    const r = await verifySite({ action: 'verify_site', dir }, log);
    expect(r.broken_total).toBe(2);
    expect(r.broken_links.map((b) => b.to).sort()).toEqual(['/gone/', '/missing.png']);
  });

  it('flags error pages, empty files, missing titles and WordPress-only links', async () => {
    const dir = await site({
      'index.html': '<html><body><a href="/wp-json/wp/v2/posts">api</a><link rel="alternate" href="/wp-json/oembed/1.0/embed"></body></html>',
      'oops/index.html': page('<p>There has been a critical error on this website.</p>'),
      '404.html': page('<p>There has been a critical error on this website.</p>'),
      'blank.txt': '',
    });
    const r = await verifySite({ action: 'verify_site', dir }, log);
    expect(r.error_pages.map((e) => e.path)).toEqual(['oops/index.html']);
    expect(r.empty_files).toEqual(['blank.txt']);
    expect(r.no_title).toBe(1);
    expect(r.wp_only.map((w) => w.url)).toEqual(['/wp-json/wp/v2/posts']);
  });

  it('serves /x as x.html and does not mind redirect pages without a title', async () => {
    const dir = await site({
      'index.html': page('<a href="/x">x</a>'),
      'x.html': page(''),
      'old/index.html': '<html><head><meta http-equiv="refresh" content="0;url=/"></head></html>',
    });
    const r = await verifySite({ action: 'verify_site', dir }, log);
    expect(r.broken_links).toEqual([]);
    expect(r.no_title).toBe(0);
  });

  it('stops at the limit and says so', async () => {
    const dir = await site({ 'a/index.html': page(''), 'b/index.html': page(''), 'c/index.html': page('') });
    const r = await verifySite({ action: 'verify_site', dir, maxFiles: 2 }, log);
    expect(r.checked).toBe(2);
    expect(r.truncated).toBe(true);
  });
});

describe('errorSignature', () => {
  it('recognises PHP errors but not prose about errors', () => {
    expect(errorSignature('<br /><b>Fatal error</b>: Uncaught Error')).toBe('PHP error');
    expect(errorSignature('<p>How to fix a fatal error in your code</p>')).toBeNull();
  });
});
