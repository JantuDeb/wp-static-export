import { CATEGORIES, type AuditFinding, type PageResult } from './audit.js';

/** Which auto-fix (FixOptions key / optimize flag) addresses a Lighthouse audit. */
export const FIXABLE: Record<string, string> = {
  'meta-viewport': 'viewport',
  'html-has-lang': 'lang',
  'image-alt': 'imageAlt',
  'link-name': 'linkNames (image-only links)',
  'unsized-images': 'imageDimensions',
  'offscreen-images': 'lazyImages',
  'prioritize-lcp-image': 'lazyImages',
  'lcp-lazy-loaded': 'lazyImages',
  'font-display': 'fontDisplay',
  'meta-description': 'metaDescription',
  'uses-long-cache-ttl': 'headers (Netlify / Cloudflare Pages)',
  'unminified-css': 'optimize.css',
  'unminified-javascript': 'optimize.js',
  'uses-optimized-images': 'optimize.images',
  'modern-image-formats': 'optimize.images + webp',
};

export const average = (rs: PageResult[], c: (typeof CATEGORIES)[number]) => Math.round(rs.reduce((n, r) => n + r.scores[c], 0) / Math.max(1, rs.length));

export function aggregate(rs: PageResult[]): { finding: AuditFinding; pages: number }[] {
  const map = new Map<string, { finding: AuditFinding; pages: number }>();
  for (const r of rs) {
    for (const f of r.failing) {
      const e = map.get(f.id);
      if (e) e.pages++;
      else map.set(f.id, { finding: f, pages: 1 });
    }
  }
  return [...map.values()].sort((a, b) => b.pages - a.pages || (a.finding.score ?? 0) - (b.finding.score ?? 0));
}

const ms = (n: number | undefined) => (n === undefined || Number.isNaN(n) ? '–' : n >= 1000 ? `${(n / 1000).toFixed(1)} s` : `${Math.round(n)} ms`);

export function summary(label: string, rs: PageResult[]): string {
  const lines = [`${label} — ${rs.length} page(s)`];
  lines.push(`  ${CATEGORIES.map((c) => `${c} ${average(rs, c)}`).join('   ')}`);
  const m = (k: string) => rs.reduce((n, r) => n + (r.metrics[k] ?? 0), 0) / Math.max(1, rs.length);
  lines.push(`  LCP ${ms(m('LCP'))}   FCP ${ms(m('FCP'))}   TBT ${ms(m('TBT'))}   CLS ${m('CLS').toFixed(3)}   SI ${ms(m('SI'))}`);
  return lines.join('\n');
}

export function findingsTable(rs: PageResult[]): string {
  const rows = aggregate(rs);
  if (!rows.length) return '  No findings below 90.';
  return rows
    .map(({ finding: f, pages }) => {
      const fix = FIXABLE[f.id];
      const ex = f.examples.length ? `\n      e.g. ${f.examples.join(' | ')}` : '';
      return `  ${String(Math.round((f.score ?? 0) * 100)).padStart(3)}  ${f.title} (${f.id}) on ${pages}/${rs.length} page(s) — ${fix ? `auto-fix: ${fix}` : 'manual'}${ex}`;
    })
    .join('\n');
}

export function markdown(runs: { label: string; results: PageResult[] }[]): string {
  const out = ['# Lighthouse report', ''];
  out.push('| Run | ' + CATEGORIES.join(' | ') + ' |', '|---|' + CATEGORIES.map(() => '---').join('|') + '|');
  for (const r of runs) out.push(`| ${r.label} | ${CATEGORIES.map((c) => average(r.results, c)).join(' | ')} |`);
  for (const r of runs) {
    out.push('', `## ${r.label}`, '', '| Page | ' + CATEGORIES.join(' | ') + ' |', '|---|' + CATEGORIES.map(() => '---').join('|') + '|');
    for (const p of r.results) out.push(`| ${p.url} | ${CATEGORIES.map((c) => p.scores[c]).join(' | ')} |`);
    out.push('', '```', findingsTable(r.results), '```');
  }
  return out.join('\n') + '\n';
}
