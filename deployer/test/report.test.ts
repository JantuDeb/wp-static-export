// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import { fmtBytes, mount, verdict, type Totals } from '../src/admin/report.js';

const totals = (over: Partial<Record<string, number>> = {}): Totals => ({
  published: 3,
  states: { ok: 3, missing: 0, error: 0, redirect: 0, excluded: 0, empty: 0, ...over },
  by_type: { post: { ok: 2, missing: 0, error: 0, redirect: 0, excluded: 0, empty: 0 }, page: { ok: 1, missing: 0, error: 0, redirect: 0, excluded: 0, empty: 0 } },
});

describe('verdict', () => {
  it('is good when everything is exported', () => {
    expect(verdict(totals()).level).toBe('good');
  });
  it('flags missing items', () => {
    const v = verdict(totals({ ok: 2, missing: 1 }));
    expect(v.level).not.toBe('good');
    expect(v.text).toContain('1 of 3');
  });
  it('is bad when many are broken', () => {
    expect(verdict({ ...totals({ ok: 0, missing: 3 }) }).level).toBe('bad');
  });
  it('handles no report and empty sites', () => {
    expect(verdict(null).level).toBe('none');
    expect(verdict({ published: 0, states: {}, by_type: {} }).level).toBe('warn');
  });
});

describe('fmtBytes', () => {
  it('formats', () => {
    expect(fmtBytes(null)).toBe('—');
    expect(fmtBytes(10)).toBe('10 B');
    expect(fmtBytes(2048)).toBe('2.0 KB');
    expect(fmtBytes(3 * 1024 * 1024)).toBe('3.0 MB');
  });
});

function setup(itemsFor: (url: string) => unknown) {
  const calls: string[] = [];
  const fetchMock = vi.fn(async (url: string) => {
    const path = url.replace('http://x/v1', '');
    calls.push(path);
    let body: unknown = {};
    if (path === '/reports') body = { reports: [{ id: 7, status: 'done', scope: 'full', delivery_method: 'zip', started_at: 'a', finished_at: 'b', totals: totals({ ok: 2, missing: 1 }) }], post_types: { post: 'Posts', page: 'Pages' }, running: false };
    else if (path === '/reports/7') body = { id: 7, scope: 'full', totals: totals({ ok: 2, missing: 1 }), deep: null };
    else body = itemsFor(path);
    return { ok: true, status: 200, json: async () => body } as Response;
  });
  vi.stubGlobal('fetch', fetchMock);
  const root = document.createElement('div');
  document.body.append(root);
  mount(root, { restUrl: 'http://x/v1', nonce: 'n', csvUrl: 'http://x/admin-post.php?action=csv&_wpnonce=1' });
  return { root, calls };
}

const flush = () => new Promise((r) => setTimeout(r, 10));

describe('dashboard', () => {
  const item = (o: object) => ({ id: 1, post_id: 5, post_type: 'post', title: 'Hello', url: 'http://s/hello/', path: '/hello/', state: 'ok', status_code: 200, static_path: 'hello/index.html', file_size: 1500, note: '', edit_url: 'http://s/edit', ...o });

  it('shows the verdict, cards and a row per item', async () => {
    const { root } = setup(() => ({ items: [item({}), item({ id: 2, post_id: 6, title: 'Gone', state: 'missing', status_code: null, static_path: '', file_size: null, note: 'Not found' })], total: 2, total_pages: 1, page: 1, per_page: 50 }));
    await flush();
    expect(root.textContent).toContain('1 of 3 published items are missing');
    expect(root.querySelectorAll('tbody tr').length).toBe(2);
    expect(root.textContent).toContain('/hello/');
    expect(root.textContent).toContain('1.5 KB');
    // Only the broken item offers a re-export.
    expect(root.querySelectorAll('tbody button').length).toBe(1);
  });

  it('escapes titles instead of rendering markup', async () => {
    const { root } = setup(() => ({ items: [item({ title: '<img src=x onerror=alert(1)>' })], total: 1, total_pages: 1, page: 1, per_page: 50 }));
    await flush();
    expect(root.querySelector('tbody img')).toBeNull();
    expect(root.textContent).toContain('<img src=x');
  });

  it('asks the server for the clicked status', async () => {
    const { root, calls } = setup(() => ({ items: [], total: 0, total_pages: 0, page: 1, per_page: 50 }));
    await flush();
    const missing = [...root.querySelectorAll<HTMLButtonElement>('.card')].find((b) => b.textContent?.includes('Missing'))!;
    missing.click();
    await flush();
    expect(calls.some((c) => c.includes('/items') && c.includes('state=missing'))).toBe(true);
    expect(root.textContent).toContain('Nothing matches');
  });

  it('points the CSV link at the current filters', async () => {
    const { root } = setup(() => ({ items: [], total: 0, total_pages: 0, page: 1, per_page: 50 }));
    await flush();
    const a = [...root.querySelectorAll('a')].find((x) => x.textContent === 'Download CSV')!;
    expect(a.getAttribute('href')).toContain('report=7');
  });
});
