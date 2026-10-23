import { readFile } from 'node:fs/promises';
import { http, pool, required } from '../util.js';
import { type Provider } from '../types.js';

export interface BunnyConfig {
  /** Storage zone password (not the account API key). */
  storageKey: string;
  storageZone: string;
  /** Region code: '' (Falkenstein), 'ny', 'la', 'sg', 'syd', 'uk', 'se', 'br', 'jh'. */
  region?: string;
  /** Account API key; needed only to purge the pull zone cache. */
  apiKey?: string;
  pullZoneId?: string | number;
}

const host = (region?: string) => (region ? `${region}.storage.bunnycdn.com` : 'storage.bunnycdn.com');

export const bunny: Provider<BunnyConfig> = {
  id: 'bunny',
  validate(c) {
    required(c as Record<string, unknown>, ['storageKey', 'storageZone'], 'bunny');
    return c as BunnyConfig;
  },
  async deploy({ changed, deleted, config, concurrency, log }) {
    const base = `https://${host(config.region)}/${encodeURIComponent(config.storageZone)}`;
    const enc = (p: string) => p.split('/').map(encodeURIComponent).join('/');
    let done = 0;
    await pool(changed, concurrency, async (f) => {
      await http(`${base}/${enc(f.path)}`, {
        method: 'PUT',
        headers: { AccessKey: config.storageKey, 'Content-Type': 'application/octet-stream' },
        body: await readFile(f.abs),
      });
      log.progress(++done, changed.length, 'files');
    });
    await pool(deleted, concurrency, async (p) => {
      await http(`${base}/${enc(p)}`, { method: 'DELETE', headers: { AccessKey: config.storageKey } }, { ok: (s) => s < 300 || s === 404 });
    });

    if (config.apiKey && config.pullZoneId && (changed.length || deleted.length)) {
      await http(`https://api.bunny.net/pullzone/${config.pullZoneId}/purgeCache`, {
        method: 'POST',
        headers: { AccessKey: config.apiKey, 'Content-Type': 'application/json' },
        body: '{}',
      });
      log.info('Pull zone cache purged');
    }
    return { uploaded: changed.length, deleted: deleted.length, message: `Synced to Bunny storage zone ${config.storageZone}` };
  },
};
