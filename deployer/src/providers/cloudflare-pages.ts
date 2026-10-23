import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { blake3 } from '@noble/hashes/blake3';
import { bytesToHex } from '@noble/hashes/utils';
import { chunk, http, mimeType, required } from '../util.js';
import { DeployError, type FileEntry, type Provider } from '../types.js';

export interface CloudflareConfig {
  apiToken: string;
  accountId: string;
  project: string;
  /** Branch name; the project's production branch yields a production deployment. */
  branch?: string;
  apiUrl?: string;
}

/** Same scheme as wrangler: blake3(base64(content) + extension), first 32 hex chars. */
export function cfHash(content: Buffer, file: string): string {
  const ext = path.extname(file).slice(1);
  return bytesToHex(blake3(new TextEncoder().encode(content.toString('base64') + ext))).slice(0, 32);
}

const SPECIAL = new Set(['_headers', '_redirects', '_routes.json', '_worker.js']);
const MAX_BUCKET_BYTES = 40 * 1024 * 1024;
const MAX_BUCKET_FILES = 2000;

export const cloudflarePages: Provider<CloudflareConfig> = {
  id: 'cloudflare-pages',
  validate(c) {
    required(c as Record<string, unknown>, ['apiToken', 'accountId', 'project'], 'cloudflare-pages');
    return c as CloudflareConfig;
  },
  async deploy({ all, config, log }) {
    const api = (config.apiUrl ?? 'https://api.cloudflare.com/client/v4').replace(/\/$/, '');
    const acct = `${api}/accounts/${config.accountId}/pages/projects/${encodeURIComponent(config.project)}`;
    const cf = { Authorization: `Bearer ${config.apiToken}` };

    const jwt = ((await (await http(`${acct}/upload-token`, { headers: cf })).json()) as any).result.jwt as string;
    const jwtAuth = { Authorization: `Bearer ${jwt}` };

    const manifest: Record<string, string> = {};
    const byHash = new Map<string, FileEntry>();
    for (const f of all) {
      if (SPECIAL.has(f.path)) continue;
      const h = cfHash(await readFile(f.abs), f.path);
      manifest['/' + f.path] = h;
      byHash.set(h, f);
    }

    const missing = ((await (
      await http(`${api}/pages/assets/check-missing`, {
        method: 'POST',
        headers: { ...jwtAuth, 'Content-Type': 'application/json' },
        body: JSON.stringify({ hashes: [...byHash.keys()] }),
      })
    ).json()) as any).result as string[];
    log.info(`Cloudflare is missing ${missing.length} of ${byHash.size} assets`);

    // Upload in buckets bounded by payload size/count, as the API enforces limits.
    const buckets: FileEntry[][] = [];
    let cur: FileEntry[] = [];
    let bytes = 0;
    for (const h of missing) {
      const f = byHash.get(h);
      if (!f) continue;
      if (cur.length && (bytes + f.size > MAX_BUCKET_BYTES || cur.length >= MAX_BUCKET_FILES)) {
        buckets.push(cur);
        cur = [];
        bytes = 0;
      }
      cur.push(f);
      bytes += f.size;
    }
    if (cur.length) buckets.push(cur);

    let done = 0;
    for (const bucket of buckets) {
      const payload = await Promise.all(
        bucket.map(async (f) => ({
          key: manifest['/' + f.path],
          value: (await readFile(f.abs)).toString('base64'),
          metadata: { contentType: mimeType(f.path) },
          base64: true,
        })),
      );
      await http(`${api}/pages/assets/upload`, { method: 'POST', headers: { ...jwtAuth, 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      done += bucket.length;
      log.progress(done, missing.length, 'assets');
    }
    for (const part of chunk([...byHash.keys()], 5000)) {
      await http(`${api}/pages/assets/upsert-hashes`, {
        method: 'POST',
        headers: { ...jwtAuth, 'Content-Type': 'application/json' },
        body: JSON.stringify({ hashes: part }),
      });
    }

    const form = new FormData();
    form.set('manifest', JSON.stringify(manifest));
    if (config.branch) form.set('branch', config.branch);
    for (const f of all) {
      if (SPECIAL.has(f.path) && f.path !== '_worker.js') form.set(f.path, new Blob([await readFile(f.abs)]), f.path);
    }
    const dep = (await (await http(`${acct}/deployments`, { method: 'POST', headers: cf, body: form })).json()) as any;
    if (!dep.success) throw new DeployError(`Cloudflare deployment rejected: ${JSON.stringify(dep.errors)}`);
    return { uploaded: missing.length, deleted: 0, url: dep.result?.url, message: 'Cloudflare Pages deployment created' };
  },
};
