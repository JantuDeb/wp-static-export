import Fuse from 'fuse.js';

export interface Hit {
  url: string;
  title: string;
  /** Plain text with \u0001 / \u0002 marking highlight start/end. */
  snippet: string;
}

export interface Config {
  engine: 'fuse' | 'algolia';
  indexUrl?: string;
  algolia?: { appId: string; searchKey: string; index: string };
  replaceForms?: boolean;
  limit?: number;
}

interface Record_ {
  url: string;
  title: string;
  text: string;
}

const MARK_START = '\u0001';
const MARK_END = '\u0002';

/** Cut ~160 chars around the first occurrence of any query word and mark every occurrence. */
export function makeSnippet(text: string, query: string): string {
  const words = query
    .toLowerCase()
    .split(/\s+/)
    .filter((w) => w.length > 1);
  const lower = text.toLowerCase();
  let first = -1;
  for (const w of words) {
    const i = lower.indexOf(w);
    if (i >= 0 && (first < 0 || i < first)) first = i;
  }
  const start = Math.max(0, first < 0 ? 0 : first - 60);
  let slice = text.slice(start, start + 160);
  if (start > 0) slice = '…' + slice;
  if (start + 160 < text.length) slice += '…';
  for (const w of words) {
    slice = slice.replace(new RegExp(w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), (m) => MARK_START + m + MARK_END);
  }
  return slice;
}

export function fuseEngine(records: Record_[], limit = 8) {
  const fuse = new Fuse(records, {
    keys: [
      { name: 'title', weight: 2 },
      { name: 'text', weight: 1 },
    ],
    threshold: 0.3,
    ignoreLocation: true,
    minMatchCharLength: 2,
  });
  return async (query: string): Promise<Hit[]> =>
    fuse.search(query, { limit }).map((r) => ({ url: r.item.url, title: r.item.title || r.item.url, snippet: makeSnippet(r.item.text, query) }));
}

export function algoliaEngine(cfg: NonNullable<Config['algolia']>, limit = 8, doFetch: typeof fetch = (...a) => fetch(...a)) {
  return async (query: string): Promise<Hit[]> => {
    const res = await doFetch(`https://${cfg.appId}-dsn.algolia.net/1/indexes/${encodeURIComponent(cfg.index)}/query`, {
      method: 'POST',
      headers: { 'X-Algolia-Application-Id': cfg.appId, 'X-Algolia-API-Key': cfg.searchKey, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        query,
        hitsPerPage: limit,
        attributesToSnippet: ['text:25'],
        snippetEllipsisText: '…',
        highlightPreTag: MARK_START,
        highlightPostTag: MARK_END,
        attributesToRetrieve: ['url', 'title'],
      }),
    });
    if (!res.ok) throw new Error(`Search failed (${res.status})`);
    const data = (await res.json()) as { hits: { url: string; title: string; _snippetResult?: { text?: { value?: string } } }[] };
    return data.hits.map((h) => ({ url: h.url, title: h.title || h.url, snippet: h._snippetResult?.text?.value ?? '' }));
  };
}

/** Render text containing highlight markers as DOM nodes without ever using innerHTML. */
export function appendHighlighted(parent: HTMLElement, text: string): void {
  let open = false;
  for (const part of text.split(new RegExp(`([${MARK_START}${MARK_END}])`))) {
    if (part === MARK_START) open = true;
    else if (part === MARK_END) open = false;
    else if (part) {
      if (open) {
        const m = document.createElement('mark');
        m.textContent = part;
        parent.append(m);
      } else parent.append(document.createTextNode(part));
    }
  }
}

function renderHits(container: HTMLElement, hits: Hit[], query: string) {
  container.replaceChildren();
  if (!hits.length) {
    const p = document.createElement('p');
    p.className = 'ss-search-empty';
    p.textContent = `No results for “${query}”.`;
    container.append(p);
    return;
  }
  const ul = document.createElement('ul');
  ul.className = 'ss-search-list';
  for (const h of hits) {
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.href = h.url;
    a.textContent = h.title;
    const p = document.createElement('p');
    appendHighlighted(p, h.snippet);
    li.append(a, p);
    ul.append(li);
  }
  container.append(ul);
}

const STYLE = `.ss-search-results{position:absolute;z-index:99999;left:0;right:0;top:100%;background:var(--ss-bg,#fff);color:var(--ss-fg,#111);border:1px solid rgba(128,128,128,.4);border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-height:70vh;overflow:auto;text-align:left}
.ss-search-list{list-style:none;margin:0;padding:0}.ss-search-list li{padding:10px 14px;border-bottom:1px solid rgba(128,128,128,.2)}
.ss-search-list a{font-weight:600;text-decoration:none}.ss-search-list p{margin:4px 0 0;font-size:.875em;opacity:.85}
.ss-search-list mark{background:rgba(255,200,0,.45);color:inherit}.ss-search-empty{padding:12px 14px;margin:0}
.ss-search-host{position:relative}@media (prefers-color-scheme:dark){.ss-search-results{--ss-bg:#1e1e1e;--ss-fg:#eee}}`;

export function init(config: Config, engineOverride?: (q: string) => Promise<Hit[]>): void {
  const limit = config.limit ?? 8;
  let engine: ((q: string) => Promise<Hit[]>) | null = engineOverride ?? null;
  let loading: Promise<void> | null = null;

  const ensure = (): Promise<void> => {
    if (engine) return Promise.resolve();
    loading ??= (async () => {
      if (config.engine === 'algolia' && config.algolia) engine = algoliaEngine(config.algolia, limit);
      else {
        const res = await fetch(config.indexUrl ?? '/ss-assets/search-index.json');
        if (!res.ok) throw new Error(`Could not load the search index (${res.status})`);
        engine = fuseEngine((await res.json()) as Record_[], limit);
      }
    })();
    return loading;
  };

  if (!document.getElementById('ss-search-style')) {
    const style = document.createElement('style');
    style.id = 'ss-search-style';
    style.textContent = STYLE;
    document.head.append(style);
  }

  const attach = (host: HTMLElement, input: HTMLInputElement) => {
    host.classList.add('ss-search-host');
    const box = document.createElement('div');
    box.className = 'ss-search-results';
    box.hidden = true;
    host.append(box);

    let timer: ReturnType<typeof setTimeout> | undefined;
    let seq = 0;
    const run = () => {
      const q = input.value.trim();
      if (q.length < 2) {
        box.hidden = true;
        return;
      }
      const mine = ++seq;
      ensure()
        .then(() => engine!(q))
        .then((hits) => {
          if (mine !== seq) return; // a newer query superseded this one
          renderHits(box, hits, q);
          box.hidden = false;
        })
        .catch((e: Error) => {
          box.replaceChildren();
          const p = document.createElement('p');
          p.className = 'ss-search-empty';
          p.textContent = e.message;
          box.append(p);
          box.hidden = false;
        });
    };
    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(run, 150);
    });
    input.addEventListener('focus', () => void ensure().catch(() => undefined));
    input.setAttribute('autocomplete', 'off');
    document.addEventListener('click', (e) => {
      if (!host.contains(e.target as Node)) box.hidden = true;
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') box.hidden = true;
    });
    host.closest('form')?.addEventListener('submit', (e) => {
      e.preventDefault(); // there is no server to search; results are shown live
      run();
    });
  };

  // <div data-ss-search></div> => full search box
  document.querySelectorAll<HTMLElement>('[data-ss-search]').forEach((el) => {
    if (el.querySelector('input')) return;
    const form = document.createElement('form');
    form.setAttribute('role', 'search');
    const input = document.createElement('input');
    input.type = 'search';
    input.placeholder = el.getAttribute('data-placeholder') || 'Search…';
    input.setAttribute('aria-label', 'Search');
    form.append(input);
    el.append(form);
    attach(form, input);
  });

  // Existing WordPress search forms
  if (config.replaceForms) {
    document.querySelectorAll<HTMLFormElement>('form[role="search"], form.search-form, .wp-block-search form').forEach((form) => {
      if (form.closest('[data-ss-search]')) return;
      const input = form.querySelector<HTMLInputElement>('input[name="s"], input[type="search"]');
      if (!input) return;
      form.removeAttribute('action');
      attach(form, input);
    });
  }
}

if (typeof document !== 'undefined' && !(globalThis as { __SS_TEST__?: boolean }).__SS_TEST__) {
  const raw = document.getElementById('ss-search-config')?.textContent;
  if (raw) {
    const cfg = JSON.parse(raw) as Config;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(cfg));
    else init(cfg);
  }
}
