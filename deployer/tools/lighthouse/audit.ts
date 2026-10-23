import { existsSync, readdirSync } from 'node:fs';
import { launch } from 'chrome-launcher';
import lighthouse from 'lighthouse';

export const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'] as const;
export type Category = (typeof CATEGORIES)[number];

export interface AuditFinding {
  id: string;
  title: string;
  score: number | null;
  /** Up to a few example items (urls / node snippets). */
  examples: string[];
}

export interface PageResult {
  url: string;
  scores: Record<Category, number>;
  metrics: Record<string, number>;
  failing: AuditFinding[];
}

export function findChrome(): string | undefined {
  if (process.env.CHROME_PATH && existsSync(process.env.CHROME_PATH)) return process.env.CHROME_PATH;
  const base = process.env.PLAYWRIGHT_BROWSERS_PATH ?? '/opt/pw-browsers';
  if (existsSync(base)) {
    for (const d of readdirSync(base)
      .filter((n) => /^chromium-\d+$/.test(n))
      .sort()
      .reverse()) {
      const p = `${base}/${d}/chrome-linux/chrome`;
      if (existsSync(p)) return p;
    }
  }
  return undefined; // let chrome-launcher look in the usual places
}

const METRICS: Record<string, string> = {
  'first-contentful-paint': 'FCP',
  'largest-contentful-paint': 'LCP',
  'total-blocking-time': 'TBT',
  'cumulative-layout-shift': 'CLS',
  'speed-index': 'SI',
};

function examplesOf(audit: any): string[] {
  const items: any[] = audit.details?.items ?? [];
  return items
    .slice(0, 3)
    .map((i) => i.url ?? i.node?.snippet ?? i.source?.url ?? i.label ?? i.node?.selector ?? '')
    .filter(Boolean)
    .map((s: string) => String(s).replace(/\s+/g, ' ').slice(0, 140));
}

export async function auditUrls(urls: string[], opts: { preset: 'mobile' | 'desktop'; chromePath?: string; runs?: number }): Promise<PageResult[]> {
  const runs = Math.max(1, opts.runs ?? 1);
  const chrome = await launch({
    chromePath: opts.chromePath ?? findChrome(),
    chromeFlags: ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
  });
  try {
    const results: PageResult[] = [];
    for (const url of urls) {
      const samples: PageResult[] = [];
      for (let n = 0; n < runs; n++) {
        const run = await lighthouse(
          url,
          { port: chrome.port, output: 'json', logLevel: 'error', onlyCategories: [...CATEGORIES] },
          opts.preset === 'desktop'
            ? {
                extends: 'lighthouse:default',
                settings: {
                  formFactor: 'desktop',
                  screenEmulation: { mobile: false, width: 1350, height: 940, deviceScaleFactor: 1, disabled: false },
                  throttling: {
                    rttMs: 40,
                    throughputKbps: 10240,
                    cpuSlowdownMultiplier: 1,
                    requestLatencyMs: 0,
                    downloadThroughputKbps: 0,
                    uploadThroughputKbps: 0,
                  },
                },
              }
            : undefined,
        );
        if (!run) throw new Error(`Lighthouse returned nothing for ${url}`);
        const lhr = run.lhr;
        if (lhr.runtimeError) throw new Error(`Lighthouse failed on ${url}: ${lhr.runtimeError.message}`);

        const scores = Object.fromEntries(CATEGORIES.map((c) => [c, Math.round((lhr.categories[c]?.score ?? 0) * 100)])) as Record<Category, number>;
        const metrics = Object.fromEntries(Object.entries(METRICS).map(([id, label]) => [label, lhr.audits[id]?.numericValue ?? NaN]));
        const ids = new Set(
          CATEGORIES.flatMap((c) => (lhr.categories[c]?.auditRefs ?? []).filter((r) => r.weight > 0 || r.group === 'diagnostics').map((r) => r.id)),
        );
        const failing: AuditFinding[] = [];
        for (const id of ids) {
          const a: any = lhr.audits[id];
          if (!a || a.score === null || a.score >= 0.9 || ['notApplicable', 'manual', 'informative'].includes(a.scoreDisplayMode)) continue;
          failing.push({ id, title: a.title, score: a.score, examples: examplesOf(a) });
        }
        samples.push({ url, scores, metrics, failing: failing.sort((x, y) => (x.score ?? 0) - (y.score ?? 0)) });
      }
      // Lighthouse performance varies run to run; report the median run so comparisons are meaningful.
      samples.sort((a, b) => a.scores.performance - b.scores.performance);
      results.push(samples[Math.floor(samples.length / 2)] as PageResult);
    }
    return results;
  } finally {
    await chrome.kill();
  }
}
