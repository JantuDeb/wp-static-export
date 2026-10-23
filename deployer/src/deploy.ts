import { providers } from './providers.js';
import { DeployError, type DeployResult, type Job, type Logger } from './types.js';
import { matchesAny } from './process/glob.js';
import { diff, loadState, saveState, scanDir } from './util.js';

export async function runJob(job: Job, log: Logger): Promise<DeployResult> {
  const provider = job.provider && providers[job.provider];
  if (!provider) throw new DeployError(`Unknown provider: ${job.provider}`);
  const config = provider.validate(job.config);

  const all = (await scanDir(job.dir)).filter((f) => !matchesAny(f.path, job.ignore));
  if (all.length === 0) throw new DeployError(`Export directory is empty: ${job.dir}`);

  const scope = job.scope ?? 'full';
  const prev = job.force ? {} : await loadState(job.stateFile);
  let { changed, deleted } = diff(all, prev, scope, !!job.force);

  // A crawl that silently lost most pages must not wipe the live site.
  const prevCount = Object.keys(prev).length;
  const maxRatio = job.maxDeleteRatio ?? 0.5;
  if (prevCount >= 20 && deleted.length > prevCount * maxRatio) {
    log.warn(`Refusing to delete ${deleted.length} of ${prevCount} previously deployed files (limit ${Math.round(maxRatio * 100)}%). Uploading only.`);
    deleted = [];
  }
  log.info(`${all.length} files, ${changed.length} changed, ${deleted.length} removed`);

  const result = await provider.deploy({ dir: job.dir, all, changed, deleted, config, concurrency: job.concurrency ?? 8, log });

  if (job.stateFile) {
    // A partial export only knows about its own files, so merge into the saved state.
    if (scope === 'partial') {
      const merged = await loadState(job.stateFile);
      for (const f of all) merged[f.path] = f.sha256;
      const { writeFile } = await import('node:fs/promises');
      await writeFile(job.stateFile, JSON.stringify(merged));
    } else {
      await saveState(job.stateFile, all);
    }
  }
  return result;
}

export async function verifyJob(job: Job): Promise<DeployResult> {
  const provider = job.provider && providers[job.provider];
  if (!provider) throw new DeployError(`Unknown provider: ${job.provider}`);
  const { message } = await provider.verify(provider.validate(job.config));
  return { uploaded: 0, deleted: 0, message };
}
