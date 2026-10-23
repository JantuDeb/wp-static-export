import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import type { DeployResult, Job, Logger } from '../types.js';
import { scanDir } from '../util.js';
import { addPictureSources, optimize, type OptimizeOptions } from './optimize.js';
import { buildSearchRecords, pushToAlgolia, writeSearchIndex, type SearchOptions } from './search-index.js';

export interface ProcessOps {
  optimize?: OptimizeOptions;
  search?: SearchOptions;
}

export interface ProcessJob extends Job {
  ops: ProcessOps;
}

const stateFile = (dir: string) => path.join(path.dirname(dir), path.basename(dir) + '.process-state.json');

export async function runProcess(job: ProcessJob, log: Logger): Promise<DeployResult> {
  const dir = job.dir;
  const parts: string[] = [];
  const created: string[] = [];
  let files = await scanDir(dir);

  if (job.ops.optimize) {
    const state: Record<string, string> = await readFile(stateFile(dir), 'utf8')
      .then((s) => JSON.parse(s))
      .catch(() => ({}));
    const o = job.ops.optimize;
    const res = await optimize(dir, files, o, state, log);
    created.push(...res.webpCreated);
    if (o.webp && res.webpCreated.length) {
      const webp = new Set(res.webpCreated);
      for (const f of files.filter((x) => /\.html?$/i.test(x.path))) {
        const html = await readFile(f.abs, 'utf8');
        const next = addPictureSources(html, f.path, webp);
        if (next !== html) await writeFile(f.abs, next);
      }
    }
    files = await scanDir(dir);
    await writeFile(stateFile(dir), JSON.stringify(Object.fromEntries(files.map((f) => [f.path, f.sha256]))));
    parts.push(
      `optimized ${res.files} file(s), saved ${(res.bytesSaved / 1024).toFixed(0)} KB${res.webpCreated.length ? `, ${res.webpCreated.length} WebP` : ''}`,
    );
  }

  const s = job.ops.search;
  if (s) {
    const indexPath = s.indexPath ?? 'ss-assets/search-index.json';
    if (job.scope === 'partial') {
      // A partial export only holds some pages, so rebuilding would drop everything else from search.
      log.warn('Search index not rebuilt: this export contains only some pages. Run a full export to refresh it.');
    } else {
      const records = await buildSearchRecords(files, s.exclude);
      if (s.engine === 'fuse') {
        created.push(await writeSearchIndex(dir, records, indexPath));
        parts.push(`search index with ${records.length} page(s)`);
      } else if (s.algolia) {
        await pushToAlgolia(s.algolia, records, true, log);
        parts.push(`${records.length} page(s) sent to Algolia`);
      }
    }
  }

  return { uploaded: 0, deleted: 0, created, message: parts.length ? `Processed: ${parts.join('; ')}` : 'Nothing to process' };
}
