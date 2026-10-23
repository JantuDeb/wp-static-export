import { readFile } from 'node:fs/promises';
import { chunk, http, pool, required } from '../util.js';
import { DeployError, type Provider } from '../types.js';

export interface GithubConfig {
  token: string;
  /** owner/repo */
  repository: string;
  branch: string;
  /** Sub-folder inside the repo to publish into ('' = root). */
  folder?: string;
  commitMessage?: string;
  authorName?: string;
  authorEmail?: string;
  apiUrl?: string;
}

export const github: Provider<GithubConfig> = {
  id: 'github',
  validate(c) {
    const cfg = c as Record<string, unknown>;
    required(cfg, ['token', 'repository'], 'github');
    if (!/^[\w.-]+\/[\w.-]+$/.test(String(cfg.repository))) throw new DeployError('github: repository must look like owner/repo');
    return { branch: 'main', apiUrl: 'https://api.github.com', ...cfg } as GithubConfig;
  },
  async verify(config) {
    const api = (config.apiUrl ?? 'https://api.github.com').replace(/\/$/, '');
    const res = await http(`${api}/repos/${config.repository}`, {
      headers: { Authorization: `Bearer ${config.token}`, Accept: 'application/vnd.github+json', 'User-Agent': 'static-deployer' },
    });
    const repo = (await res.json()) as any;
    if (repo.permissions && !repo.permissions.push) throw new DeployError(`Token can read ${repo.full_name} but cannot push to it`);
    return { message: `Connected to ${repo.full_name}` };
  },
  async deploy({ changed, deleted, config, concurrency, log }) {
    const api = (config.apiUrl ?? 'https://api.github.com').replace(/\/$/, '');
    const base = `${api}/repos/${config.repository}`;
    const headers = {
      Authorization: `Bearer ${config.token}`,
      Accept: 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'static-deployer',
      'Content-Type': 'application/json',
    };
    const gh = async (method: string, p: string, body?: unknown, okStatuses?: number[]) =>
      http(`${base}${p}`, { method, headers, body: body ? JSON.stringify(body) : undefined }, { ok: okStatuses ? (s) => okStatuses.includes(s) : undefined });
    const prefix = (config.folder ?? '').replace(/^\/+|\/+$/g, '');
    const at = (p: string) => (prefix ? `${prefix}/${p}` : p);

    // Resolve current branch head (null for a brand new branch or empty repo).
    let headSha: string | null = null;
    let baseTree: string | undefined;
    const ref = await gh('GET', `/git/ref/heads/${encodeURIComponent(config.branch)}`, undefined, [200, 404, 409]);
    if (ref.status === 200) {
      headSha = ((await ref.json()) as any).object.sha;
      const commit = await (await gh('GET', `/git/commits/${headSha}`)).json();
      baseTree = (commit as any).tree.sha;
    } else if (ref.status === 409) {
      // Empty repository: the Git Data API only works after the first commit.
      log.info('Repository is empty, creating initial commit');
      await gh('PUT', '/contents/.nojekyll', { message: 'Initialize repository', content: '', branch: config.branch });
      const r = await (await gh('GET', `/git/ref/heads/${encodeURIComponent(config.branch)}`)).json();
      headSha = (r as any).object.sha;
      baseTree = ((await (await gh('GET', `/git/commits/${headSha}`)).json()) as any).tree.sha;
    }

    if (changed.length === 0 && deleted.length === 0) {
      return { uploaded: 0, deleted: 0, message: 'Nothing to deploy' };
    }

    log.info(`Uploading ${changed.length} blob(s)`);
    const entries: { path: string; mode: '100644'; type: 'blob'; sha: string | null }[] = [];
    let done = 0;
    await pool(changed, Math.min(concurrency, 6), async (f) => {
      const content = (await readFile(f.abs)).toString('base64');
      const res = await (await gh('POST', '/git/blobs', { content, encoding: 'base64' })).json();
      entries.push({ path: at(f.path), mode: '100644', type: 'blob', sha: (res as any).sha });
      log.progress(++done, changed.length, 'blobs');
    });
    for (const d of deleted) entries.push({ path: at(d), mode: '100644', type: 'blob', sha: null });

    // Trees are built in slices so very large exports don't exceed API payload limits.
    let tree = baseTree;
    for (const part of chunk(entries, 500)) {
      const res = await (await gh('POST', '/git/trees', { base_tree: tree, tree: part })).json();
      tree = (res as any).sha;
    }

    const message = config.commitMessage ?? `Static export ${new Date().toISOString()}`;
    const author = config.authorName && config.authorEmail ? { name: config.authorName, email: config.authorEmail } : undefined;
    const commit = await (await gh('POST', '/git/commits', { message, tree, parents: headSha ? [headSha] : [], ...(author ? { author } : {}) })).json();
    const newSha = (commit as any).sha as string;

    if (headSha) await gh('PATCH', `/git/refs/heads/${encodeURIComponent(config.branch)}`, { sha: newSha });
    else await gh('POST', '/git/refs', { ref: `refs/heads/${config.branch}`, sha: newSha });

    return {
      uploaded: changed.length,
      deleted: deleted.length,
      url: `https://github.com/${config.repository}/commit/${newSha}`,
      message: `Committed ${newSha.slice(0, 7)} to ${config.branch}`,
    };
  },
};
