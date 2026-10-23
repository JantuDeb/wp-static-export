import { readFile } from 'node:fs/promises';
import { http, pool, required, sha } from '../util.js';
import { DeployError, type Provider } from '../types.js';

export interface NetlifyConfig {
  token: string;
  siteId: string;
  /** Publish as a draft/preview deploy instead of production. */
  draft?: boolean;
  title?: string;
  apiUrl?: string;
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/**
 * Netlify deploys are atomic snapshots, so the complete file list (not just the diff)
 * is sent; Netlify tells us which digests it is missing and we upload only those.
 */
export const netlify: Provider<NetlifyConfig> = {
  id: 'netlify',
  validate(c) {
    required(c as Record<string, unknown>, ['token', 'siteId'], 'netlify');
    return c as NetlifyConfig;
  },
  async deploy({ all, config, concurrency, log }) {
    const api = (config.apiUrl ?? 'https://api.netlify.com/api/v1').replace(/\/$/, '');
    const auth = { Authorization: `Bearer ${config.token}` };

    const digests = new Map<string, string>(); // path -> sha1
    await pool(all, 8, async (f) => void digests.set('/' + f.path, await sha('sha1', f.abs)));

    const body: Record<string, unknown> = { files: Object.fromEntries(digests), draft: !!config.draft };
    if (config.title) body.title = config.title;
    const created = (await (
      await http(`${api}/sites/${config.siteId}/deploys`, { method: 'POST', headers: { ...auth, 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    ).json()) as any;

    const required_: string[] = created.required ?? [];
    const needed = new Set(required_);
    const toUpload = all.filter((f) => needed.has(digests.get('/' + f.path) as string));
    log.info(`Netlify needs ${toUpload.length} of ${all.length} files`);
    let done = 0;
    await pool(toUpload, Math.min(concurrency, 8), async (f) => {
      await http(`${api}/deploys/${created.id}/files/${f.path.split('/').map(encodeURIComponent).join('/')}`, {
        method: 'PUT',
        headers: { ...auth, 'Content-Type': 'application/octet-stream' },
        body: await readFile(f.abs),
      });
      log.progress(++done, toUpload.length, 'files');
    });

    for (let i = 0; i < 120; i++) {
      const d = (await (await http(`${api}/deploys/${created.id}`, { headers: auth })).json()) as any;
      if (d.state === 'ready') {
        return { uploaded: toUpload.length, deleted: 0, url: d.ssl_url ?? d.deploy_ssl_url ?? d.url, message: 'Netlify deploy is live' };
      }
      if (d.state === 'error') throw new DeployError(`Netlify deploy failed: ${d.error_message ?? 'unknown error'}`);
      await sleep(2000);
    }
    throw new DeployError('Timed out waiting for Netlify deploy to become ready');
  },
};
