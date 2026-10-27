import { readFile } from 'node:fs/promises';
import { http, mimeType, required } from '../util.js';
import { DeployError, type FileEntry, type Provider } from '../types.js';
import { cfHash } from './cloudflare-pages.js';

export interface CloudflareWorkersConfig {
  apiToken: string;
  accountId: string;
  /** Name of the Worker that serves the assets; created on the first deploy. */
  project: string;
  apiUrl?: string;
}

const MAX_FILE_BYTES = 25 * 1024 * 1024;

/**
 * Cloudflare Workers static assets (the replacement for Pages direct upload): a three step flow of
 * upload session, asset upload per bucket, then publishing the Worker with the completion token.
 * Every deploy sends the full manifest, so files removed from the export disappear from the site.
 */
export const cloudflareWorkers: Provider<CloudflareWorkersConfig> = {
  id: 'cloudflare-workers',
  validate(c) {
    required(c as Record<string, unknown>, ['apiToken', 'accountId', 'project'], 'cloudflare-workers');
    return c as CloudflareWorkersConfig;
  },
  async verify(config) {
    const api = (config.apiUrl ?? 'https://api.cloudflare.com/client/v4').replace(/\/$/, '');
    const res = (await (
      await http(`${api}/accounts/${config.accountId}/workers/scripts`, { headers: { Authorization: `Bearer ${config.apiToken}` } })
    ).json()) as { result?: { id?: string }[] };
    const exists = (res.result ?? []).some((s) => s.id === config.project);
    return { message: exists ? `Connected; Worker ${config.project} exists and will be updated` : `Connected; Worker ${config.project} will be created on the first deploy` };
  },
  async deploy({ all, config, log }) {
    const api = (config.apiUrl ?? 'https://api.cloudflare.com/client/v4').replace(/\/$/, '');
    const base = `${api}/accounts/${config.accountId}/workers`;
    const cf = { Authorization: `Bearer ${config.apiToken}` };

    const manifest: Record<string, { hash: string; size: number }> = {};
    const byHash = new Map<string, FileEntry>();
    for (const f of all) {
      if (f.size > MAX_FILE_BYTES) throw new DeployError(`${f.path} is larger than Cloudflare's 25 MiB per-file limit`);
      const hash = cfHash(await readFile(f.abs), f.path);
      manifest['/' + f.path] = { hash, size: f.size };
      byHash.set(hash, f);
    }

    const session = (await (
      await http(`${base}/scripts/${encodeURIComponent(config.project)}/assets-upload-session`, {
        method: 'POST',
        headers: { ...cf, 'Content-Type': 'application/json' },
        body: JSON.stringify({ manifest }),
      })
    ).json()) as { result?: { jwt?: string; buckets?: string[][] } };
    let token = session.result?.jwt;
    if (!token) throw new DeployError('Cloudflare did not return an upload token');
    const buckets = session.result?.buckets ?? [];
    const total = buckets.reduce((n, b) => n + b.length, 0);
    log.info(`Cloudflare needs ${total} of ${byHash.size} assets`);

    let done = 0;
    for (const bucket of buckets) {
      const form = new FormData();
      for (const hash of bucket) {
        const f = byHash.get(hash);
        if (!f) continue;
        form.set(hash, new Blob([(await readFile(f.abs)).toString('base64')], { type: mimeType(f.path) }), hash);
      }
      const res = (await (await http(`${base}/assets/upload?base64=true`, { method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: form })).json()) as {
        result?: { jwt?: string };
      };
      // The last bucket answers with the completion token that replaces the session token.
      if (res.result?.jwt) token = res.result.jwt;
      done += bucket.length;
      log.progress(done, total, 'assets');
    }

    const metadata = {
      compatibility_date: new Date().toISOString().slice(0, 10),
      assets: { jwt: token, config: { html_handling: 'auto-trailing-slash', not_found_handling: '404-page' } },
    };
    const form = new FormData();
    form.set('metadata', new Blob([JSON.stringify(metadata)], { type: 'application/json' }), 'metadata');
    const dep = (await (await http(`${base}/scripts/${encodeURIComponent(config.project)}`, { method: 'PUT', headers: cf, body: form })).json()) as { success?: boolean; errors?: unknown };
    if (dep.success === false) throw new DeployError(`Cloudflare rejected the Worker: ${JSON.stringify(dep.errors)}`);
    return { uploaded: total, deleted: 0, message: `Cloudflare Workers deployment published (${total} new assets)` };
  },
};
