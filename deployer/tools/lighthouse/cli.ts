import { cp, mkdtemp, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { runProcess } from '../../src/process/index.js';
import { filePathToUrl } from '../../src/process/search-index.js';
import type { Logger } from '../../src/types.js';
import { auditUrls, CATEGORIES, type PageResult } from './audit.js';
import { makeFixture } from './fixture.js';
import { average, findingsTable, markdown, summary } from './report.js';
import { serveExport } from './static-server.js';

const { values: args } = parseArgs({
  options: {
    dir: { type: 'string' },
    demo: { type: 'boolean', default: false },
    pages: { type: 'string', default: '4' },
    urls: { type: 'string' },
    preset: { type: 'string', default: 'mobile' },
    runs: { type: 'string', default: '1' },
    min: { type: 'string' },
    fix: { type: 'boolean', default: false },
    optimize: { type: 'boolean', default: false },
    meta: { type: 'boolean', default: false },
    write: { type: 'boolean', default: false },
    out: { type: 'string' },
    chrome: { type: 'string' },
  },
});

const log: Logger = { info: () => {}, warn: (m) => console.warn('  warn:', m), progress: () => {} };

async function listHtml(dir: string, sub = ''): Promise<string[]> {
  const out: string[] = [];
  for (const e of await readdir(path.join(dir, sub), { withFileTypes: true })) {
    const rel = path.posix.join(sub, e.name);
    if (e.isDirectory()) out.push(...(await listHtml(dir, rel)));
    else if (/\.html?$/i.test(e.name)) out.push(rel);
  }
  return out;
}

/** Home page first, then pages spread across the site (skipping 404 and noindex). */
async function pickPages(dir: string, n: number): Promise<string[]> {
  const files = (await listHtml(dir)).filter((f) => !/(^|\/)404\.html$/.test(f));
  const usable: string[] = [];
  for (const f of files) if (!/noindex/i.test(await readFile(path.join(dir, f), 'utf8'))) usable.push(filePathToUrl(f));
  usable.sort((a, b) => a.split('/').length - b.split('/').length || a.localeCompare(b));
  const home = usable.filter((u) => u === '/');
  const rest = usable.filter((u) => u !== '/');
  const step = Math.max(1, Math.floor(rest.length / Math.max(1, n - home.length)));
  return [...home, ...rest.filter((_, i) => i % step === 0)].slice(0, n);
}

async function audit(dir: string, paths: string[], label: string): Promise<PageResult[]> {
  const server = await serveExport(dir);
  try {
    const results = await auditUrls(
      paths.map((p) => server.url + p),
      { preset: args.preset === 'desktop' ? 'desktop' : 'mobile', chromePath: args.chrome },
    );
    for (const r of results) r.url = r.url.replace(server.url, '');
    console.log('\n' + summary(label, results) + '\n' + findingsTable(results));
    return results;
  } finally {
    await server.close();
  }
}

function parseMin(spec: string | undefined): Partial<Record<(typeof CATEGORIES)[number], number>> {
  const out: Partial<Record<(typeof CATEGORIES)[number], number>> = {};
  for (const part of (spec ?? '').split(',').filter(Boolean)) {
    const [k, v] = part.split('=');
    if (!CATEGORIES.includes(k as never) || !Number.isFinite(Number(v))) throw new Error(`Bad --min entry "${part}" (use e.g. performance=90,seo=95)`);
    out[k as (typeof CATEGORIES)[number]] = Number(v);
  }
  return out;
}

async function main() {
  const min = parseMin(args.min);
  const tmp = await mkdtemp(path.join(os.tmpdir(), 'ss-lh-'));
  try {
    let dir = args.demo ? await makeFixture(path.join(tmp, 'demo')) : args.dir ? path.resolve(args.dir) : '';
    if (!dir) throw new Error('Pass --dir <export folder> or --demo');

    const paths = args.urls ? args.urls.split(',') : await pickPages(dir, Number(args.pages));
    console.log(`Auditing ${paths.length} page(s) of ${dir} (${args.preset})`);
    const runs: { label: string; results: PageResult[] }[] = [];
    const before = await audit(dir, paths, 'BEFORE');
    runs.push({ label: 'Before', results: before });
    let final = before;

    if (args.fix || args.optimize) {
      // Work on a copy so the original export is untouched unless --write is given.
      const work = args.write ? dir : path.join(tmp, 'fixed');
      if (!args.write) await cp(dir, work, { recursive: true });
      const res = await runProcess(
        {
          action: 'process',
          dir: work,
          scope: 'full',
          config: {},
          ops: {
            ...(args.fix
              ? {
                  fixes: {
                    viewport: true,
                    lang: 'en',
                    noopener: true,
                    imageAlt: true,
                    linkNames: true,
                    imageDimensions: true,
                    lazyImages: true,
                    fontDisplay: true,
                    headers: true,
                    metaDescription: args.meta,
                  },
                }
              : {}),
            ...(args.optimize ? { optimize: { html: true, css: true, js: true, images: true, webp: true, quality: 80 } } : {}),
          },
        },
        log,
      );
      console.log(`\nApplied: ${res.message}`);
      const after = await audit(work, paths, 'AFTER');
      runs.push({ label: 'After', results: after });
      final = after;

      console.log('\nChange');
      for (const c of CATEGORIES) {
        const d = average(after, c) - average(before, c);
        console.log(`  ${c.padEnd(15)} ${average(before, c)} → ${average(after, c)}  (${d >= 0 ? '+' : ''}${d})`);
      }
    }

    if (args.out) {
      await writeFile(args.out, args.out.endsWith('.json') ? JSON.stringify(runs, null, 2) : markdown(runs));
      console.log(`\nReport written to ${args.out}`);
    }

    const failed = CATEGORIES.filter((c) => min[c] !== undefined && average(final, c) < (min[c] as number));
    if (failed.length) {
      console.error(`\nBelow threshold: ${failed.map((c) => `${c} ${average(final, c)} < ${min[c]}`).join(', ')}`);
      process.exitCode = 1;
    }
  } finally {
    await rm(tmp, { recursive: true, force: true });
  }
}

main().catch((e) => {
  console.error(e instanceof Error ? e.message : e);
  process.exit(1);
});
