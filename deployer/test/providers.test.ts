import { mkdtemp, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { runJob } from '../src/deploy.js';
import { cfHash } from '../src/providers/cloudflare-pages.js';
import type { Logger } from '../src/types.js';

const log: Logger = { info() {}, warn() {}, progress() {} };
const json = (o: unknown, status = 200) => new Response(JSON.stringify(o), { status, headers: { 'content-type': 'application/json' } });

async function site() {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'site-'));
  await writeFile(path.join(dir, 'index.html'), '<h1>hi</h1>');
  await writeFile(path.join(dir, 'a.css'), 'body{}');
  return dir;
}

afterEach(() => vi.unstubAllGlobals());

describe('github provider', () => {
  it('creates blobs, tree, commit and moves the ref; skips unchanged files next time', async () => {
    const calls: string[] = [];
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
      const p = new URL(url).pathname.replace('/repos/o/r', '');
      calls.push(`${init.method ?? 'GET'} ${p}`);
      if (p === '/git/ref/heads/main') return json({ object: { sha: 'head1' } });
      if (p === '/git/commits/head1') return json({ tree: { sha: 'tree0' } });
      if (p === '/git/blobs') return json({ sha: 'blob' });
      if (p === '/git/trees') {
        const body = JSON.parse(String(init.body));
        expect(body.base_tree).toBe('tree0');
        expect(body.tree.map((t: any) => t.path).sort()).toEqual(['public/a.css', 'public/index.html']);
        return json({ sha: 'tree1' });
      }
      if (p === '/git/commits') return json({ sha: 'commit1abcdef' });
      if (p === '/git/refs/heads/main') return json({});
      throw new Error('unexpected ' + p);
    });
    const dir = await site();
    const state = path.join(dir, '..', `state-${Date.now()}.json`);
    const job = { provider: 'github' as const, dir, stateFile: state, config: { token: 't', repository: 'o/r', branch: 'main', folder: 'public' } };
    const r1 = await runJob(job, log);
    expect(r1.uploaded).toBe(2);
    expect(calls).toContain('PATCH /git/refs/heads/main');

    calls.length = 0;
    const r2 = await runJob(job, log);
    expect(r2.uploaded).toBe(0);
    expect(calls.filter((c) => c.includes('blobs'))).toHaveLength(0);
  });

  it('rejects a malformed repository', async () => {
    await expect(runJob({ provider: 'github', dir: await site(), config: { token: 't', repository: 'nope' } }, log)).rejects.toThrow(/owner\/repo/);
  });
});

describe('netlify provider', () => {
  it('uploads only digests Netlify reports as required, then waits for ready', async () => {
    const uploaded: string[] = [];
    let polls = 0;
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
      const u = new URL(url);
      if (init.method === 'POST' && u.pathname.endsWith('/sites/S/deploys')) {
        const body = JSON.parse(String(init.body));
        expect(Object.keys(body.files).sort()).toEqual(['/a.css', '/index.html']);
        return json({ id: 'D', required: [body.files['/index.html']] });
      }
      if (init.method === 'PUT') {
        uploaded.push(u.pathname);
        return json({});
      }
      if (u.pathname.endsWith('/deploys/D')) return json({ state: ++polls ? 'ready' : '', ssl_url: 'https://x.netlify.app' });
      throw new Error('unexpected ' + url);
    });
    const r = await runJob({ provider: 'netlify', dir: await site(), config: { token: 't', siteId: 'S' } }, log);
    expect(uploaded).toEqual(['/api/v1/deploys/D/files/index.html']);
    expect(r.url).toBe('https://x.netlify.app');
  });
});

describe('cloudflare hash', () => {
  it('is 32 hex chars and depends on extension', () => {
    const a = cfHash(Buffer.from('x'), 'a.css');
    expect(a).toMatch(/^[0-9a-f]{32}$/);
    expect(cfHash(Buffer.from('x'), 'a.js')).not.toBe(a);
  });
});

describe('s3 provider', () => {
  it('PUTs signed objects with content-type and deletes removed keys in one batch', async () => {
    const seen: { m: string; url: string; h: Headers }[] = [];
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
      seen.push({ m: init.method!, url, h: new Headers(init.headers) });
      return new Response('', { status: 200 });
    });
    const dir = await site();
    const state = path.join(dir, '..', `s3state-${Date.now()}.json`);
    await writeFile(state, JSON.stringify({ 'old.html': 'zzz' }));
    await runJob(
      { provider: 's3', dir, stateFile: state, config: { bucket: 'b', region: 'eu-west-1', accessKeyId: 'k', secretAccessKey: 's', prefix: 'site' } },
      log,
    );
    const puts = seen.filter((s) => s.m === 'PUT');
    expect(puts.map((p) => p.url).sort()).toEqual(['https://b.s3.eu-west-1.amazonaws.com/site/a.css', 'https://b.s3.eu-west-1.amazonaws.com/site/index.html']);
    expect(puts[0]!.h.get('authorization')).toMatch(/^AWS4-HMAC-SHA256/);
    expect(seen.filter((s) => s.url.endsWith('?delete='))).toHaveLength(1);
  });
});

describe('cloudflare workers provider', () => {
  it('opens a session, uploads the buckets it asks for, then publishes the Worker with the completion token', async () => {
    const calls: string[] = [];
    let published: any;
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
      const u = new URL(url);
      const p = u.pathname.replace('/client/v4/accounts/acc/workers', '');
      calls.push(`${init.method ?? 'GET'} ${p}${u.search}`);
      if (p === '/scripts/site/assets-upload-session') {
        const manifest = JSON.parse(String(init.body)).manifest;
        expect(Object.keys(manifest).sort()).toEqual(['/a.css', '/index.html']);
        expect(manifest['/a.css'].hash).toMatch(/^[0-9a-f]{32}$/);
        return json({ success: true, result: { jwt: 'upload-jwt', buckets: [[manifest['/index.html'].hash]] } });
      }
      if (p === '/assets/upload') {
        expect(new Headers(init.headers).get('authorization')).toBe('Bearer upload-jwt');
        expect([...(init.body as FormData).keys()]).toHaveLength(1);
        return json({ success: true, result: { jwt: 'done-jwt' } }, 201);
      }
      if (p === '/scripts/site') {
        published = JSON.parse(await ((init.body as FormData).get('metadata') as Blob).text());
        return json({ success: true });
      }
      return json({ success: false }, 404);
    });
    const dir = await site();
    const res = await runJob({ provider: 'cloudflare-workers', dir, config: { apiToken: 't', accountId: 'acc', project: 'site' } }, log);
    expect(res.uploaded).toBe(1);
    expect(published.assets.jwt).toBe('done-jwt');
    expect(calls).toEqual(['POST /scripts/site/assets-upload-session', 'POST /assets/upload?base64=true', 'PUT /scripts/site']);
  });

  it('skips the upload when nothing is needed and uses the session token', async () => {
    let published: any;
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
      const p = new URL(url).pathname;
      if (p.endsWith('assets-upload-session')) return json({ success: true, result: { jwt: 'complete', buckets: [] } });
      published = JSON.parse(await ((init.body as FormData).get('metadata') as Blob).text());
      return json({ success: true });
    });
    const res = await runJob({ provider: 'cloudflare-workers', dir: await site(), config: { apiToken: 't', accountId: 'acc', project: 'site' } }, log);
    expect(res.uploaded).toBe(0);
    expect(published.assets.jwt).toBe('complete');
  });
});
