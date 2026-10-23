import { readFile, rm } from 'node:fs/promises';
import { runJob } from './deploy.js';
import type { Job, Logger } from './types.js';

/** Newline-delimited JSON on stdout so the PHP side can stream progress. */
const emit = (o: Record<string, unknown>) => process.stdout.write(JSON.stringify(o) + '\n');

const log: Logger = {
  info: (message) => emit({ type: 'log', level: 'info', message }),
  warn: (message) => emit({ type: 'log', level: 'warn', message }),
  progress: (done, total, label) => emit({ type: 'progress', done, total, label }),
};

async function main() {
  const args = process.argv.slice(2);
  const i = args.indexOf('--job');
  const file = i >= 0 ? args[i + 1] : undefined;
  if (!file) throw new Error('Usage: cli.cjs --job <job.json> [--keep-job]');
  const job = JSON.parse(await readFile(file, 'utf8')) as Job;
  // The job file contains credentials; remove it as soon as it has been read.
  if (!args.includes('--keep-job')) await rm(file, { force: true });
  const result = await runJob(job, log);
  emit({ type: 'result', ...result });
}

main().catch((e) => {
  emit({ type: 'error', message: e instanceof Error ? e.message : String(e) });
  process.exit(1);
});
