import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readFile, stat, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { photo } from '../tools/lighthouse/fixture.js';
import { describe, expect, it } from 'vitest';

// These tests run the *built* bundle the way PHP does. Unit tests import the TypeScript sources and
// cannot catch bundling problems (e.g. a dependency using import.meta.url in a CommonJS build).
const cli = path.resolve(__dirname, '../dist/cli.cjs');

function run(job: object, cwd: string) {
  return (async () => {
    const file = path.join(cwd, `job-${Math.random().toString(36).slice(2)}.json`);
    await writeFile(file, JSON.stringify(job));
    const r = spawnSync(process.execPath, [cli, '--job', file], { encoding: 'utf8' });
    const events = r.stdout
      .split('\n')
      .filter(Boolean)
      .map((l) => JSON.parse(l) as Record<string, any>);
    return { code: r.status, events, stderr: r.stderr, jobFileStillThere: existsSync(file) };
  })();
}

describe.skipIf(!existsSync(cli))('built cli.cjs', () => {
  it('starts, reports errors as JSON and deletes the job file (which holds credentials)', async () => {
    const dir = await mkdtemp(path.join(os.tmpdir(), 'bundle-'));
    const r = await run({ provider: 'bunny', dir, config: {} }, dir);
    expect(r.stderr).toBe('');
    expect(r.code).toBe(1);
    expect(r.events.at(-1)).toEqual({ type: 'error', message: 'bunny: missing setting(s): storageKey, storageZone' });
    expect(r.jobFileStillThere).toBe(false);
  });

  it('runs the whole process stage: fixes, minification, image compression with sharp, WebP, search index', async () => {
    const dir = await mkdtemp(path.join(os.tmpdir(), 'bundle-'));
    const site = path.join(dir, 'site');
    await mkdir(path.join(site, 'img'), { recursive: true });
    await writeFile(path.join(site, 'img/p.jpg'), await photo(600, 400, 5, 100));
    await writeFile(path.join(site, 'style.css'), '@font-face { font-family: A; src: url(a.woff2) }\n\nbody {   color : red }');
    await writeFile(path.join(site, 'app.js'), 'function add(first, second) {\n  return first + second;\n}\nconsole.log(add(1, 2));');
    await writeFile(
      path.join(site, 'index.html'),
      '<html><head><title>Home</title></head><body><main><p>' +
        'Searchable words about static sites. '.repeat(4) +
        '</p><img src="/img/p.jpg"></main></body></html>',
    );
    const before = (await stat(path.join(site, 'img/p.jpg'))).size;

    const r = await run(
      {
        action: 'process',
        dir: site,
        scope: 'full',
        config: {},
        ops: {
          fixes: { viewport: true, lang: 'en', imageAlt: true, imageDimensions: true, fontDisplay: true, headers: true },
          optimize: { html: true, css: true, js: true, images: true, webp: true, quality: 60 },
          search: { engine: 'fuse' },
        },
      },
      dir,
    );
    expect(r.stderr).toBe('');
    expect(r.code, JSON.stringify(r.events)).toBe(0);
    expect(r.events.filter((e) => e.type === 'log' && e.level === 'warn')).toEqual([]); // e.g. "sharp not found"
    const result = r.events.find((e) => e.type === 'result')!;
    expect(result.created).toEqual(expect.arrayContaining(['_headers', 'img/p.jpg.webp', 'ss-assets/search-index.json']));

    expect((await stat(path.join(site, 'img/p.jpg'))).size).toBeLessThan(before);
    expect(await readFile(path.join(site, 'style.css'), 'utf8')).toContain('font-display:swap');
    const html = await readFile(path.join(site, 'index.html'), 'utf8');
    expect(html).toContain('<picture>');
    expect(html).toContain('width="600"');
    expect(JSON.parse(await readFile(path.join(site, 'ss-assets/search-index.json'), 'utf8'))[0].title).toBe('Home');
  });

  it('prints its version-independent usage error without a stack trace when no job is given', () => {
    const r = spawnSync(process.execPath, [cli], { encoding: 'utf8' });
    expect(r.status).toBe(1);
    expect(r.stdout).toContain('Usage: cli.cjs --job');
    expect(execFileSync).toBeDefined();
  });
});
