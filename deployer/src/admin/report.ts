interface Boot {
  restUrl: string;
  nonce: string;
  csvUrl: string;
  settings?: string;
  exportUrl?: string;
}

export interface Totals {
  published: number;
  states: Record<string, number>;
  by_type: Record<string, Record<string, number>>;
}

export interface ReportRow {
  id: number;
  status: string;
  scope: string;
  delivery_method: string;
  started_at: string;
  finished_at: string;
  totals: Totals | null;
  deep?: Deep | null;
}

export interface Deep {
  checked: number;
  broken_links: { from: string; to: string; status: number }[];
  empty_files: string[];
  error_pages: { path: string; signature: string }[];
  wp_only: { path: string; url: string }[];
  no_title: number;
  truncated?: boolean;
}

interface Item {
  id: number;
  post_id: number;
  post_type: string;
  title: string;
  url: string;
  path: string;
  state: string;
  status_code: number | null;
  static_path: string;
  file_size: number | null;
  note: string;
  edit_url: string;
}

interface ItemPage {
  items: Item[];
  total: number;
  total_pages: number;
  page: number;
  per_page: number;
}

const LABELS: Record<string, string> = {
  ok: 'Exported',
  missing: 'Missing',
  error: 'Error',
  empty: 'Empty file',
  redirect: 'Redirect',
  excluded: 'Excluded',
};

export function fmtBytes(n: number | null): string {
  if (n === null || n === undefined) return '—';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

/** What to tell the owner at the top of the page. */
export function verdict(t: Totals | null): { level: 'good' | 'warn' | 'bad' | 'none'; text: string } {
  if (!t) return { level: 'none', text: 'No report yet. Run an export, or press "Verify now" to check the last export.' };
  const s = t.states;
  const bad = (s.missing ?? 0) + (s.error ?? 0) + (s.empty ?? 0);
  const total = t.published;
  if (total === 0) return { level: 'warn', text: 'No published posts or pages were found to check.' };
  if (bad === 0) {
    const excl = s.excluded ?? 0;
    return {
      level: 'good',
      text:
        `All ${total.toLocaleString()} published items are in the export.` +
        (excl ? ` ${excl.toLocaleString()} are excluded by your settings.` : ''),
    };
  }
  return {
    level: bad / total > 0.05 ? 'bad' : 'warn',
    text: `${bad.toLocaleString()} of ${total.toLocaleString()} published items are missing or broken. Fix these before you go live.`,
  };
}

function h<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attrs: Record<string, string | boolean> = {},
  ...children: (string | Node)[]
): HTMLElementTagNameMap[K] {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === false) continue;
    if (k === 'class') el.className = String(v);
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  for (const c of children) el.append(c);
  return el;
}

const CSS = `
.wpse-r{max-width:1200px;margin:16px 20px 40px 0;font-size:14px;color:#1d2327}
.wpse-r *{box-sizing:border-box}
.wpse-r h2{margin:0;font-size:22px}
.wpse-r .bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-bottom:12px}
.wpse-r .bar .acts{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.wpse-r .verdict{padding:14px 16px;border-radius:6px;border-left:6px solid;background:#fff;margin-bottom:16px;font-size:15px}
.wpse-r .verdict.good{border-color:#00a32a}.wpse-r .verdict.warn{border-color:#dba617}.wpse-r .verdict.bad{border-color:#d63638}.wpse-r .verdict.none{border-color:#8c8f94}
.wpse-r .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:16px}
.wpse-r .card{width:100%;max-width:none;min-width:0;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px;cursor:pointer;text-align:left;font:inherit}
.wpse-r .card:hover,.wpse-r .card.on{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
.wpse-r .card b{display:block;font-size:24px;line-height:1.2}
.wpse-r .card span{color:#50575e}
.wpse-r .panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px;margin-bottom:16px}
.wpse-r .panel h3{margin:0 0 10px;font-size:15px}
.wpse-r .cov{display:grid;grid-template-columns:minmax(90px,160px) 1fr auto;gap:8px 12px;align-items:center}
.wpse-r .meter{height:10px;border-radius:5px;background:#f0f0f1;overflow:hidden;display:flex}
.wpse-r .meter i{display:block;height:100%}
.wpse-r .ok{background:#00a32a}.wpse-r .bad{background:#d63638}.wpse-r .mid{background:#dba617}.wpse-r .mute{background:#a7aaad}
.wpse-r .filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.wpse-r .filters input[type=search]{flex:1 1 220px;min-width:0}
.wpse-r table{width:100%;border-collapse:collapse}
.wpse-r th{text-align:left;padding:8px;border-bottom:2px solid #dcdcde;white-space:nowrap}
.wpse-r th.sort{cursor:pointer}
.wpse-r td{padding:8px;border-bottom:1px solid #f0f0f1;vertical-align:top;word-break:break-word}
.wpse-r td.num{text-align:right;white-space:nowrap}
.wpse-r .path{font-family:ui-monospace,Menlo,monospace;font-size:12px;color:#50575e}
.wpse-r .pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;white-space:nowrap}
.wpse-r .pill.ok{background:#edfaef;color:#00642a}.wpse-r .pill.missing,.wpse-r .pill.error,.wpse-r .pill.empty{background:#fcf0f1;color:#8a2424}
.wpse-r .pill.redirect,.wpse-r .pill.excluded{background:#f0f0f1;color:#50575e}
.wpse-r .pager{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-top:10px}
.wpse-r .progress{height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden;min-width:160px}
.wpse-r .progress i{display:block;height:100%;background:#2271b1;transition:width .3s}
.wpse-r .muted{color:#646970}
.wpse-r ul.l{margin:0;padding-left:18px}
@media(max-width:782px){
 .wpse-r{margin-right:10px}
 .wpse-r table,.wpse-r tbody,.wpse-r tr,.wpse-r td{display:block;width:100%}
 .wpse-r thead{display:none}
 .wpse-r tr{border:1px solid #dcdcde;border-radius:6px;padding:8px;margin-bottom:8px}
 .wpse-r td{border:0;padding:3px 0}
 .wpse-r td.num{text-align:left}
 .wpse-r td[data-l]::before{content:attr(data-l) ": ";color:#646970}
 .wpse-r .cov{grid-template-columns:1fr}
 .wpse-r .cards{grid-template-columns:repeat(2,minmax(0,1fr))}
}`;

export function mount(root: HTMLElement, boot: Boot): void {
  if (!document.getElementById('wpse-report-css')) {
    const st = h('style', { id: 'wpse-report-css' }, CSS);
    document.head.append(st);
  }
  const api = async (path: string, init: RequestInit = {}) => {
    const res = await fetch(boot.restUrl + path, {
      ...init,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce, ...(init.headers ?? {}) },
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((body as { message?: string }).message ?? `Request failed (${res.status})`);
    return body;
  };

  let reports: ReportRow[] = [];
  let typeLabels: Record<string, string> = {};
  let current: ReportRow | null = null;
  let running = false;
  let query = { state: '', post_type: '', search: '', orderby: 'id', order: 'asc', page: 1, per_page: 50 };
  let list: ItemPage | null = null;
  let verifying: { done: number; total: number } | null = null;
  let message: { kind: 'error' | 'success'; text: string } | null = null;
  let existing: { exists: boolean; files: number; modified: number; delivery_method: string; busy: boolean } | null = null;
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
  let seq = 0;

  async function loadItems(): Promise<void> {
    if (!current) return;
    const mine = ++seq;
    const q = new URLSearchParams({ ...query, page: String(query.page), per_page: String(query.per_page) } as Record<string, string>);
    for (const [k, v] of [...q]) if (v === '') q.delete(k);
    try {
      const page = (await api(`/reports/${current.id}/items?${q}`)) as ItemPage;
      if (mine !== seq) return; // a newer request superseded this one
      list = page;
    } catch (e) {
      message = { kind: 'error', text: (e as Error).message };
    }
    render();
  }

  async function loadReport(id: number | null): Promise<void> {
    try {
      const idx = (await api('/reports')) as { reports: ReportRow[]; post_types: Record<string, string>; running: boolean };
      reports = idx.reports;
      typeLabels = idx.post_types;
      running = idx.running;
      existing = (await api('/existing-export').catch(() => null)) as typeof existing;
      const pick = id ?? reports[0]?.id ?? null;
      current = pick ? ((await api(`/reports/${pick}`)) as ReportRow & { post_types: Record<string, string> }) : null;
      list = null;
      query.page = 1;
      render();
      if (current) await loadItems();
    } catch (e) {
      root.replaceChildren(h('div', { class: 'notice notice-error inline' }, h('p', {}, (e as Error).message)));
    }
  }

  async function verifyNow(): Promise<void> {
    message = null;
    verifying = { done: 0, total: 0 };
    render();
    let restart = true;
    try {
      for (;;) {
        const r = (await api('/report-verify', { method: 'POST', body: JSON.stringify({ restart }) })) as {
          status: string;
          report_id: number;
          done: number;
          total: number;
        };
        restart = false;
        verifying = { done: r.done, total: r.total };
        render();
        if (r.status === 'done') {
          verifying = null;
          await loadReport(r.report_id);
          return;
        }
      }
    } catch (e) {
      verifying = null;
      message = { kind: 'error', text: (e as Error).message };
      render();
    }
  }

  async function exportNow(item: Item, btn: HTMLButtonElement): Promise<void> {
    btn.disabled = true;
    try {
      await api('/export-single', { method: 'POST', body: JSON.stringify({ post_ids: [item.post_id] }) });
      message = { kind: 'success', text: `Exporting "${item.title}". When it finishes, press "Verify now" to update this report.` };
    } catch (e) {
      message = { kind: 'error', text: (e as Error).message };
      btn.disabled = false;
    }
    render();
  }

  async function deliverExisting(btn: HTMLButtonElement): Promise<void> {
    btn.disabled = true;
    try {
      await api('/deliver-existing', { method: 'POST', body: '{}' });
      message = { kind: 'success', text: 'Delivery started. No new crawl is done. Follow it in the Activity Log on the Generate page.' };
    } catch (e) {
      message = { kind: 'error', text: (e as Error).message };
      btn.disabled = false;
    }
    render();
  }

  function existingPanel(): HTMLElement | null {
    if (!existing || !existing.exists) return null;
    const when = existing.modified ? new Date(existing.modified * 1000).toLocaleString() : '';
    const names: Record<string, string> = { zip: 'a ZIP file', local: 'the local folder', github: 'GitHub', netlify: 'Netlify', 'cloudflare-pages': 'Cloudflare Pages', 'cloudflare-workers': 'Cloudflare Workers', s3: 'S3', bunny: 'BunnyCDN', sftp: 'SFTP' };
    const btn = h('button', { type: 'button', class: 'button', disabled: existing.busy || running }, `Deliver to ${names[existing.delivery_method] ?? existing.delivery_method}`);
    btn.addEventListener('click', () => void deliverExisting(btn));
    return h(
      'div',
      { class: 'panel' },
      h('h3', {}, 'Export on disk'),
      h('p', { class: 'muted' }, `${existing.files.toLocaleString()} files${when ? `, last written ${when}` : ''}. Deliver them again without crawling the site, for example after a failed deploy or after changing the delivery target in Deploy & Features.`),
      btn,
    );
  }

  const csvHref = () => {
    const u = new URL(boot.csvUrl, window.location.href);
    if (current) u.searchParams.set('report', String(current.id));
    for (const k of ['state', 'post_type', 'search'] as const) if (query[k]) u.searchParams.set(k, query[k]);
    return u.toString();
  };

  function cards(t: Totals): HTMLElement {
    const defs: [string, string, string][] = [
      ['', 'Published', String(t.published)],
      ['ok', 'Exported', String(t.states.ok ?? 0)],
      ['missing', 'Missing', String(t.states.missing ?? 0)],
      ['error', 'Errors', String(t.states.error ?? 0)],
      ['empty', 'Empty files', String(t.states.empty ?? 0)],
      ['redirect', 'Redirects', String(t.states.redirect ?? 0)],
      ['excluded', 'Excluded', String(t.states.excluded ?? 0)],
    ];
    const wrap = h('div', { class: 'cards' });
    for (const [state, label, n] of defs) {
      const b = h('button', { type: 'button', class: 'card' + (query.state === state ? ' on' : '') }, h('b', {}, Number(n).toLocaleString()), h('span', {}, label));
      b.addEventListener('click', () => {
        query = { ...query, state, page: 1 };
        void loadItems();
      });
      wrap.append(b);
    }
    return wrap;
  }

  function coverage(t: Totals): HTMLElement {
    const panel = h('div', { class: 'panel' }, h('h3', {}, 'Coverage by content type'));
    const grid = h('div', { class: 'cov' });
    for (const [type, st] of Object.entries(t.by_type)) {
      const total = Object.values(st).reduce((a, b) => a + b, 0) || 1;
      const bad = (st.missing ?? 0) + (st.error ?? 0) + (st.empty ?? 0);
      const pct = (n: number) => `${(n / total) * 100}%`;
      const meter = h('div', { class: 'meter', role: 'img', 'aria-label': `${st.ok ?? 0} of ${total} exported` });
      for (const [cls, n] of [['ok', st.ok ?? 0], ['bad', bad], ['mid', st.redirect ?? 0], ['mute', st.excluded ?? 0]] as [string, number][]) {
        const seg = h('i', { class: cls });
        seg.style.width = pct(n);
        meter.append(seg);
      }
      const name = h('a', { href: '#', class: 'row-title' }, typeLabels[type] ?? type);
      name.addEventListener('click', (ev) => {
        ev.preventDefault();
        query = { ...query, post_type: type, page: 1 };
        void loadItems();
      });
      grid.append(name, meter, h('span', { class: 'muted' }, `${st.ok ?? 0} / ${total}${bad ? ` · ${bad} problem${bad > 1 ? 's' : ''}` : ''}`));
    }
    panel.append(grid);
    return panel;
  }

  function deepPanel(d: Deep): HTMLElement {
    const panel = h('div', { class: 'panel' }, h('h3', {}, `Site checks (${d.checked.toLocaleString()} HTML pages scanned)`));
    const sections: [string, string[]][] = [
      ['Broken internal links', d.broken_links.map((b) => `${b.to} → ${b.status} (on ${b.from})`)],
      ['Pages that look like errors', d.error_pages.map((p) => `${p.path}: "${p.signature}"`)],
      ['Empty files', d.empty_files],
      ['Links that need WordPress (will not work on a static host)', d.wp_only.map((w) => `${w.url} (on ${w.path})`)],
    ];
    let any = false;
    for (const [title, rows] of sections) {
      if (!rows.length) continue;
      any = true;
      const ul = h('ul', { class: 'l' });
      for (const r of rows.slice(0, 50)) ul.append(h('li', { class: 'path' }, r));
      if (rows.length > 50) ul.append(h('li', { class: 'muted' }, `…and ${rows.length - 50} more`));
      panel.append(h('p', {}, h('b', {}, `${title}: ${rows.length}`)), ul);
    }
    if (d.no_title) panel.append(h('p', {}, `${d.no_title} pages have no <title>.`));
    if (!any && !d.no_title) panel.append(h('p', { class: 'muted' }, 'No broken links, error pages or empty files found.'));
    if (d.truncated) panel.append(h('p', { class: 'muted' }, 'The scan stopped at its limit; results cover part of the site.'));
    return panel;
  }

  function table(): HTMLElement {
    const panel = h('div', { class: 'panel' });
    const types = Object.keys(typeLabels);
    const search = h('input', { type: 'search', placeholder: 'Search title, URL or file path', value: query.search, 'aria-label': 'Search' });
    search.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        query = { ...query, search: search.value, page: 1 };
        void loadItems();
      }, 300);
    });
    const state = h('select', { 'aria-label': 'Status' });
    for (const [v, l] of [['', 'All statuses'], ['problems', 'Problems only'], ...Object.entries(LABELS)] as [string, string][]) {
      state.append(h('option', { value: v, selected: query.state === v }, l));
    }
    state.addEventListener('change', () => {
      query = { ...query, state: state.value, page: 1 };
      void loadItems();
    });
    const type = h('select', { 'aria-label': 'Content type' }, h('option', { value: '' }, 'All content types'));
    for (const t of types) type.append(h('option', { value: t, selected: query.post_type === t }, typeLabels[t]!));
    type.addEventListener('change', () => {
      query = { ...query, post_type: type.value, page: 1 };
      void loadItems();
    });
    panel.append(h('div', { class: 'filters' }, search, state, type));

    if (!list) {
      panel.append(h('p', { class: 'muted' }, 'Loading…'));
      return panel;
    }
    if (!list.items.length) {
      panel.append(h('p', { class: 'muted' }, 'Nothing matches these filters.'));
      return panel;
    }

    const cols: [string, string][] = [['title', 'Title'], ['post_type', 'Type'], ['url', 'Path'], ['state', 'Status'], ['status_code', 'HTTP'], ['file_size', 'Size']];
    const head = h('tr', {});
    for (const [key, label] of cols) {
      const th = h('th', { class: 'sort', scope: 'col', tabindex: '0', 'aria-sort': query.orderby === key ? (query.order === 'asc' ? 'ascending' : 'descending') : 'none' }, label + (query.orderby === key ? (query.order === 'asc' ? ' ▲' : ' ▼') : ''));
      const sort = () => {
        query = { ...query, orderby: key, order: query.orderby === key && query.order === 'asc' ? 'desc' : 'asc', page: 1 };
        void loadItems();
      };
      th.addEventListener('click', sort);
      th.addEventListener('keydown', (e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), sort()));
      head.append(th);
    }
    head.append(h('th', { scope: 'col' }, 'Actions'));

    const body = h('tbody', {});
    for (const it of list.items) {
      const tr = h('tr', {});
      const title = h('td', { 'data-l': 'Title' }, h('b', {}, it.title));
      if (it.note) title.append(h('div', { class: 'muted' }, it.note));
      const path = h('td', { 'data-l': 'Path' }, h('div', { class: 'path' }, it.path));
      if (it.static_path) path.append(h('div', { class: 'path' }, `→ ${it.static_path}`));
      const acts = h('td', {});
      acts.append(h('a', { href: it.url, target: '_blank', rel: 'noopener noreferrer' }, 'View'));
      if (it.edit_url) acts.append(' · ', h('a', { href: it.edit_url }, 'Edit'));
      if (['missing', 'error', 'empty'].includes(it.state) && it.post_id) {
        const b = h('button', { type: 'button', class: 'button button-small', style: 'margin-left:6px' }, 'Export now');
        b.addEventListener('click', () => void exportNow(it, b));
        acts.append(b);
      }
      tr.append(
        title,
        h('td', { 'data-l': 'Type' }, typeLabels[it.post_type] ?? it.post_type),
        path,
        h('td', { 'data-l': 'Status' }, h('span', { class: `pill ${it.state}` }, LABELS[it.state] ?? it.state)),
        h('td', { class: 'num', 'data-l': 'HTTP' }, it.status_code ? String(it.status_code) : '—'),
        h('td', { class: 'num', 'data-l': 'Size' }, fmtBytes(it.file_size)),
        acts,
      );
      body.append(tr);
    }
    panel.append(h('table', {}, h('thead', {}, head), body));

    const prev = h('button', { type: 'button', class: 'button', disabled: list.page <= 1 }, '‹ Previous');
    const next = h('button', { type: 'button', class: 'button', disabled: list.page >= list.total_pages }, 'Next ›');
    prev.addEventListener('click', () => ((query = { ...query, page: query.page - 1 }), void loadItems()));
    next.addEventListener('click', () => ((query = { ...query, page: query.page + 1 }), void loadItems()));
    const size = h('select', { 'aria-label': 'Rows per page' });
    for (const n of [25, 50, 100, 200]) size.append(h('option', { value: String(n), selected: query.per_page === n }, `${n} per page`));
    size.addEventListener('change', () => ((query = { ...query, per_page: Number(size.value), page: 1 }), void loadItems()));
    panel.append(
      h(
        'div',
        { class: 'pager' },
        h('span', { class: 'muted' }, `${list.total.toLocaleString()} item${list.total === 1 ? '' : 's'} · page ${list.page} of ${Math.max(1, list.total_pages)}`),
        h('span', {}, prev, ' ', next, ' ', size),
      ),
    );
    return panel;
  }

  function render(): void {
    const t = current?.totals ?? null;
    const v = verdict(t);
    const verifyBtn = h('button', { type: 'button', class: 'button button-primary', disabled: Boolean(verifying) || running }, verifying ? 'Verifying…' : 'Verify now');
    verifyBtn.addEventListener('click', () => void verifyNow());
    const acts = h('div', { class: 'acts' });
    if (reports.length > 1) {
      const sel = h('select', { 'aria-label': 'Report' });
      for (const r of reports) sel.append(h('option', { value: String(r.id), selected: current?.id === r.id }, `${r.finished_at || r.started_at} UTC · ${r.scope}`));
      sel.addEventListener('change', () => void loadReport(Number(sel.value)));
      acts.append(sel);
    }
    if (current) acts.append(h('a', { class: 'button', href: csvHref() }, 'Download CSV'));
    acts.append(verifyBtn);

    const out: Node[] = [h('div', { class: 'bar' }, h('h2', {}, 'Export report'), acts)];
    if (running) out.push(h('div', { class: 'notice notice-info inline' }, h('p', {}, 'An export is running. Results update when it finishes; reload this page afterwards.')));
    if (message) out.push(h('div', { class: `notice notice-${message.kind} inline` }, h('p', {}, message.text)));
    if (verifying) {
      const pct = verifying.total ? Math.min(100, Math.round((verifying.done / verifying.total) * 100)) : 0;
      const bar = h('i', {});
      bar.style.width = `${pct}%`;
      out.push(h('div', { class: 'panel' }, h('p', {}, `Checked ${verifying.done.toLocaleString()} of ${verifying.total.toLocaleString()}`), h('div', { class: 'progress', role: 'progressbar', 'aria-valuenow': String(pct), 'aria-valuemin': '0', 'aria-valuemax': '100' }, bar)));
    }
    out.push(h('div', { class: `verdict ${v.level}`, role: 'status' }, v.text));
    if (current && current.scope === 'partial') {
      out.push(h('p', { class: 'muted' }, 'This report was updated by a single-page export. Run a full export to refresh everything.'));
    }
    const ex = existingPanel();
    if (ex) out.push(ex);
    if (t) out.push(cards(t), coverage(t));
    if (current?.deep) out.push(deepPanel(current.deep));
    if (current) out.push(table());
    root.replaceChildren(h('div', { class: 'wpse-r' }, ...out));
  }

  root.textContent = 'Loading…';
  void loadReport(null);
}

declare global {
  interface Window {
    wpseReport?: Boot;
  }
}

if (typeof window !== 'undefined' && window.wpseReport) {
  const root = document.getElementById('wpse-report-root');
  if (root) mount(root, window.wpseReport);
}
