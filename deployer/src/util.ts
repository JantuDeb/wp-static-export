import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { readdir, stat, readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { DeployError, type FileEntry } from './types.js';

export function sha(algo: 'sha1' | 'sha256', file: string): Promise<string> {
  return new Promise((resolve, reject) => {
    const h = createHash(algo);
    createReadStream(file)
      .on('data', (d) => h.update(d))
      .on('end', () => resolve(h.digest('hex')))
      .on('error', reject);
  });
}

export async function scanDir(root: string): Promise<FileEntry[]> {
  const found: { rel: string; abs: string }[] = [];
  async function walk(dir: string): Promise<void> {
    for (const e of await readdir(dir, { withFileTypes: true })) {
      const abs = path.join(dir, e.name);
      if (e.isDirectory()) await walk(abs);
      else if (e.isFile()) found.push({ rel: path.relative(root, abs).split(path.sep).join('/'), abs });
    }
  }
  await walk(root);

  // Hashing one file at a time leaves the disk idle between small files; a handful in flight is much faster on big sites.
  const out: FileEntry[] = new Array(found.length);
  let next = 0;
  const worker = async () => {
    for (let i = next++; i < found.length; i = next++) {
      const f = found[i]!;
      out[i] = { path: f.rel, abs: f.abs, size: (await stat(f.abs)).size, sha256: await sha('sha256', f.abs) };
    }
  };
  await Promise.all(Array.from({ length: 16 }, worker));
  return out.sort((a, b) => a.path.localeCompare(b.path));
}

export type State = Record<string, string>;

export async function loadState(file?: string): Promise<State> {
  if (!file) return {};
  try {
    return JSON.parse(await readFile(file, 'utf8')) as State;
  } catch {
    return {};
  }
}

export async function saveState(file: string, files: FileEntry[]): Promise<void> {
  await mkdir(path.dirname(file), { recursive: true });
  const state: State = {};
  for (const f of files) state[f.path] = f.sha256;
  await writeFile(file, JSON.stringify(state));
}

export function diff(all: FileEntry[], prev: State, scope: 'full' | 'partial', force: boolean) {
  if (force) {
    return { changed: all, deleted: scope === 'full' ? Object.keys(prev).filter((p) => !all.some((f) => f.path === p)) : [] };
  }
  const changed = all.filter((f) => prev[f.path] !== f.sha256);
  const present = new Set(all.map((f) => f.path));
  const deleted = scope === 'full' ? Object.keys(prev).filter((p) => !present.has(p)) : [];
  return { changed, deleted };
}

/** Run `fn` over items with bounded concurrency; rejects on first failure. */
export async function pool<T>(items: T[], limit: number, fn: (item: T, index: number) => Promise<void>): Promise<void> {
  let next = 0;
  const worker = async () => {
    while (true) {
      const i = next++;
      if (i >= items.length) return;
      await fn(items[i] as T, i);
    }
  };
  await Promise.all(Array.from({ length: Math.max(1, Math.min(limit, items.length)) }, worker));
}

export function chunk<T>(items: T[], size: number): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size));
  return out;
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** fetch with retry on network errors, 429 and 5xx. */
export async function http(url: string, init: RequestInit = {}, opts: { retries?: number; ok?: (s: number) => boolean } = {}): Promise<Response> {
  const retries = opts.retries ?? 4;
  let attempt = 0;
  while (true) {
    try {
      const res = await fetch(url, init);
      if ((res.status === 429 || res.status >= 500) && attempt < retries) {
        const ra = Number(res.headers.get('retry-after'));
        await res.arrayBuffer().catch(() => undefined);
        await sleep(ra > 0 ? ra * 1000 : 500 * 2 ** attempt);
        attempt++;
        continue;
      }
      if (!(opts.ok ? opts.ok(res.status) : res.ok)) {
        const body = await res.text().catch(() => '');
        throw new DeployError(`${init.method ?? 'GET'} ${redact(url)} failed: ${res.status} ${body.slice(0, 300)}`);
      }
      return res;
    } catch (e) {
      if (e instanceof DeployError || attempt >= retries) throw e;
      await sleep(500 * 2 ** attempt);
      attempt++;
    }
  }
}

function redact(url: string): string {
  try {
    const u = new URL(url);
    return u.origin + u.pathname;
  } catch {
    return url;
  }
}

export function required(config: Record<string, unknown>, keys: string[], provider: string): void {
  const missing = keys.filter((k) => config[k] === undefined || config[k] === null || config[k] === '');
  if (missing.length) throw new DeployError(`${provider}: missing setting(s): ${missing.join(', ')}`);
}

const MIME: Record<string, string> = {
  html: 'text/html; charset=utf-8',
  htm: 'text/html; charset=utf-8',
  css: 'text/css; charset=utf-8',
  js: 'text/javascript; charset=utf-8',
  mjs: 'text/javascript; charset=utf-8',
  json: 'application/json',
  xml: 'application/xml',
  txt: 'text/plain; charset=utf-8',
  svg: 'image/svg+xml',
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  gif: 'image/gif',
  webp: 'image/webp',
  avif: 'image/avif',
  ico: 'image/x-icon',
  woff: 'font/woff',
  woff2: 'font/woff2',
  ttf: 'font/ttf',
  otf: 'font/otf',
  pdf: 'application/pdf',
  mp4: 'video/mp4',
  webm: 'video/webm',
  mp3: 'audio/mpeg',
  zip: 'application/zip',
  map: 'application/json',
  rss: 'application/rss+xml',
  atom: 'application/atom+xml',
  wasm: 'application/wasm',
};

export function mimeType(file: string): string {
  const ext = path.extname(file).slice(1).toLowerCase();
  return MIME[ext] ?? 'application/octet-stream';
}
