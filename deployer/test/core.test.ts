import { mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { signV4 } from '../src/sigv4.js';
import { diff, scanDir } from '../src/util.js';
import type { FileEntry } from '../src/types.js';

describe('sigv4', () => {
  it('matches the AWS documented S3 GET Object example', () => {
    const h = signV4({
      method: 'GET',
      host: 'examplebucket.s3.amazonaws.com',
      path: '/test.txt',
      headers: { range: 'bytes=0-9' },
      body: '',
      region: 'us-east-1',
      service: 's3',
      creds: { accessKeyId: 'AKIAIOSFODNN7EXAMPLE', secretAccessKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' },
      date: new Date('2013-05-24T00:00:00Z'),
    });
    expect(h.authorization).toContain('Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41');
  });
});

const f = (p: string, sha256: string): FileEntry => ({ path: p, sha256, size: 1, abs: '/x/' + p });

describe('diff', () => {
  const all = [f('index.html', 'a'), f('new.html', 'n'), f('same.css', 's')];
  const prev = { 'index.html': 'OLD', 'same.css': 's', 'gone.html': 'g' };
  it('full scope uploads changed and deletes missing', () => {
    const d = diff(all, prev, 'full', false);
    expect(d.changed.map((x) => x.path)).toEqual(['index.html', 'new.html']);
    expect(d.deleted).toEqual(['gone.html']);
  });
  it('partial scope never deletes', () => {
    expect(diff(all, prev, 'partial', false).deleted).toEqual([]);
  });
  it('force uploads everything', () => {
    expect(diff(all, prev, 'full', true).changed).toHaveLength(3);
  });
});

describe('scanDir', () => {
  it('returns posix relative paths with hashes', async () => {
    const dir = await mkdtemp(path.join(os.tmpdir(), 'sd-'));
    await mkdir(path.join(dir, 'a/b'), { recursive: true });
    await writeFile(path.join(dir, 'a/b/c.txt'), 'hello');
    const files = await scanDir(dir);
    expect(files[0]?.path).toBe('a/b/c.txt');
    expect(files[0]?.sha256).toBe('2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824');
  });
});

import { runJob } from '../src/deploy.js';
import { writeFile as wf } from 'node:fs/promises';
import { vi } from 'vitest';

describe('delete safety', () => {
  it('skips mass deletions', async () => {
    const dir = await mkdtemp(path.join(os.tmpdir(), 'ds-'));
    await wf(path.join(dir, 'index.html'), 'x');
    const state = path.join(dir, '..', `ds-state-${Date.now()}.json`);
    const prev: Record<string, string> = {};
    for (let i = 0; i < 30; i++) prev[`p${i}.html`] = 'h';
    await wf(state, JSON.stringify(prev));
    const warn = vi.fn();
    const calls: string[] = [];
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
      calls.push(`${init.method} ${url}`);
      return new Response('', { status: 200 });
    });
    const r = await runJob({ provider: 'bunny', dir, stateFile: state, config: { storageKey: 'k', storageZone: 'z' } }, { info() {}, warn, progress() {} });
    vi.unstubAllGlobals();
    expect(r.deleted).toBe(0);
    expect(warn).toHaveBeenCalled();
    expect(calls.some((c) => c.startsWith('DELETE'))).toBe(false);
  });
});

describe('ignore', () => {
  it('never deploys files matching the ignore globs and does not record them in state', async () => {
    const dir = await mkdtemp(path.join(os.tmpdir(), 'ig-'));
    await mkdir(path.join(dir, 'wp-content/uploads/wp-static-export/deploy-state'), { recursive: true });
    await wf(path.join(dir, 'wp-content/uploads/wp-static-export/deploy-state/s3.json'), '{"secret":"paths"}');
    await wf(path.join(dir, 'index.html'), 'x');
    const state = path.join(dir, '..', `ig-state-${Date.now()}.json`);
    const puts: string[] = [];
    vi.stubGlobal('fetch', async (url: string, init: RequestInit = {}) => {
      if (init.method === 'PUT') puts.push(new URL(url).pathname);
      return new Response('', { status: 200 });
    });
    const r = await runJob(
      { provider: 'bunny', dir, stateFile: state, ignore: ['*/wp-static-export/deploy-state/*'], config: { storageKey: 'k', storageZone: 'z' } },
      { info() {}, warn() {}, progress() {} },
    );
    vi.unstubAllGlobals();
    expect(puts).toEqual(['/z/index.html']);
    expect(r.uploaded).toBe(1);
    expect(Object.keys(JSON.parse(await (await import('node:fs/promises')).readFile(state, 'utf8')))).toEqual(['index.html']);
  });
});
