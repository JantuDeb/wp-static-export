// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { buildRequest, collectFields, init as initFormsRaw, parseFieldMap, type Rule } from '../src/web/forms.js';
import { algoliaEngine, appendHighlighted, fuseEngine, init as initSearch, makeSnippet } from '../src/web/search.js';

const ctx = { formName: 'contact', pageUrl: 'https://example.com/contact/', now: new Date('2026-01-01T00:00:00Z') };

describe('forms: field map', () => {
  it('parses lines, ignoring comments and blanks', () => {
    const m = parseFieldMap('# comment\nyour-email = entry.111\n\nmsg=entry.222\nbroken');
    expect([...m]).toEqual([
      ['your-email', 'entry.111'],
      ['msg', 'entry.222'],
    ]);
  });
});

describe('forms: requests', () => {
  const fields = { 'your-email': ['a@b.c'], msg: ['hi'], topics: ['x', 'y'] };

  it('google form: maps to entry ids, drops unmapped, uses no-cors and formResponse URL', () => {
    const r = buildRequest({ provider: 'google-form', endpoint: '1FAIpQLSabc', fields: 'your-email=entry.111\nmsg=entry.222\ntopics=entry.333' }, fields, ctx);
    expect(r.url).toBe('https://docs.google.com/forms/d/e/1FAIpQLSabc/formResponse');
    expect(r.init.mode).toBe('no-cors');
    expect(String(r.init.body)).toBe('entry.111=a%40b.c&entry.222=hi&entry.333=x&entry.333=y');
    expect(r.opaque).toBe(true);
    const r2 = buildRequest(
      { provider: 'google-form', endpoint: 'https://docs.google.com/forms/d/e/ID/viewform?usp=sf_link', fields: 'msg=entry.9' },
      fields,
      ctx,
    );
    expect(r2.url).toBe('https://docs.google.com/forms/d/e/ID/formResponse');
    expect(String(r2.init.body)).toBe('entry.9=hi');
  });

  it('google sheet: text/plain JSON with page + timestamp, renamed columns', () => {
    const r = buildRequest({ provider: 'google-sheet', endpoint: 'https://script.google.com/macros/s/X/exec', fields: 'your-email=Email' }, fields, ctx);
    expect((r.init.headers as Record<string, string>)['Content-Type']).toBe('text/plain;charset=utf-8');
    expect(JSON.parse(String(r.init.body))).toEqual({ Email: 'a@b.c', msg: 'hi', topics: 'x, y', _page: ctx.pageUrl, _submitted: '2026-01-01T00:00:00.000Z' });
  });

  it('netlify posts urlencoded with form-name', () => {
    const r = buildRequest({ provider: 'netlify', endpoint: '' }, { msg: ['hi'] }, ctx);
    expect(r.url).toBe('/');
    expect(String(r.init.body)).toBe('form-name=contact&msg=hi');
  });

  it('formspree accepts bare ids and full URLs', () => {
    expect(buildRequest({ provider: 'formspree', endpoint: 'xyz' }, fields, ctx).url).toBe('https://formspree.io/f/xyz');
    expect(buildRequest({ provider: 'formspree', endpoint: 'https://formspree.io/f/abc' }, fields, ctx).url).toBe('https://formspree.io/f/abc');
  });

  it('webhook sends structured JSON; mailto navigates', () => {
    const w = buildRequest({ provider: 'webhook', endpoint: 'https://h.example/x' }, fields, ctx);
    expect(JSON.parse(String(w.init.body)).fields.topics).toEqual(['x', 'y']);
    const m = buildRequest({ provider: 'mailto', endpoint: 'me@example.com' }, { msg: ['hi there'] }, ctx);
    expect(m.navigate).toContain('mailto:me@example.com?subject=');
    expect(decodeURIComponent(m.navigate!)).toContain('msg: hi there');
  });
});

describe('forms: runtime', () => {
  const disposers: (() => void)[] = [];
  const initForms = (...a: Parameters<typeof initFormsRaw>) => {
    const d = initFormsRaw(...a);
    disposers.push(d);
    return d;
  };
  afterEach(() => disposers.splice(0).forEach((d) => d()));
  beforeEach(() => {
    document.body.innerHTML = '';
    document.head.innerHTML = '';
  });

  function setup(rule: Rule) {
    document.body.innerHTML = `<script id="ss-forms-config" type="application/json">${JSON.stringify({ rules: [rule] })}</script>
      <form id="contact" data-ss-form="0"><input name="your-email" value="a@b.c" required><input type="hidden" name="_wpcf7" value="12"><input type="hidden" name="_wpnonce" value="n"><button type="submit">Send</button></form>`;
    return document.getElementById('contact') as HTMLFormElement;
  }

  it('sends the mapped fields, strips plugin internals, shows success and resets', async () => {
    const form = setup({ provider: 'google-form', endpoint: 'ID', fields: 'your-email=entry.1', success_message: 'Thanks!' });
    const fetchImpl = vi.fn(async () => new Response(''));
    const pluginHandler = vi.fn();
    form.addEventListener('submit', pluginHandler); // e.g. CF7's own handler
    initForms(document, { minFillMs: 0, fetchImpl: fetchImpl as any });
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await vi.waitFor(() => expect(fetchImpl).toHaveBeenCalled());
    expect(String((fetchImpl.mock.calls[0] as any)[1].body)).toBe('entry.1=a%40b.c');
    expect(pluginHandler).not.toHaveBeenCalled();
    await vi.waitFor(() => expect(document.querySelector('.ss-form-status')?.textContent).toBe('Thanks!'));
    expect(document.querySelector('.ss-form-ok')).not.toBeNull();
  });

  it('collectFields drops technical fields and the honeypot', () => {
    const form = setup({ provider: 'webhook', endpoint: 'x' });
    initForms(document, { minFillMs: 0 });
    expect(collectFields(form)).toEqual({ 'your-email': ['a@b.c'] });
  });

  it('ignores bot submissions (honeypot filled) but still shows success; no request is sent', async () => {
    const form = setup({ provider: 'webhook', endpoint: 'https://h/x' });
    const fetchImpl = vi.fn(async () => new Response(''));
    initForms(document, { minFillMs: 0, fetchImpl: fetchImpl as any });
    (form.querySelector('input[name=_ss_hp]') as HTMLInputElement).value = 'spam';
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await new Promise((r) => setTimeout(r, 10));
    expect(fetchImpl).not.toHaveBeenCalled();
    expect(document.querySelector('.ss-form-ok')).not.toBeNull();
  });

  it('shows the server error for non-opaque providers', async () => {
    const form = setup({ provider: 'formspree', endpoint: 'bad' });
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ errors: [{ message: 'Form not found' }] }), { status: 404 }));
    initForms(document, { minFillMs: 0, fetchImpl: fetchImpl as any });
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await vi.waitFor(() => expect(document.querySelector('.ss-form-error')?.textContent).toContain('Form not found'));
    expect((form.querySelector('button') as HTMLButtonElement).disabled).toBe(false);
  });

  it('rejects submissions made too quickly after load', async () => {
    const form = setup({ provider: 'webhook', endpoint: 'https://h/x' });
    const fetchImpl = vi.fn(async () => new Response(''));
    initForms(document, { minFillMs: 60_000, fetchImpl: fetchImpl as any });
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await new Promise((r) => setTimeout(r, 10));
    expect(fetchImpl).not.toHaveBeenCalled();
    expect(document.querySelector('.ss-form-error')).not.toBeNull();
  });
});

describe('search', () => {
  const records = [
    { url: '/about/', title: 'About us', text: 'We build static websites from WordPress for speed and security.' },
    { url: '/blog/hello/', title: 'Hello world', text: 'The first post. Nothing about hosting here at all really.' },
    { url: '/contact/', title: 'Contact', text: 'Reach out through the form below.' },
  ];

  it('fuse engine ranks title matches first and builds highlighted snippets', async () => {
    const search = fuseEngine(records);
    const hits = await search('static websites');
    expect(hits[0]?.url).toBe('/about/');
    expect(hits[0]?.snippet).toContain('\u0001static\u0002');
  });

  it('prefers exact matches and only falls back to fuzzy ones for typos', async () => {
    const recs = [
      { url: '/take/', title: 'Packing list', text: 'Take a make-up bag and take a map. Make sure you take enough water to make it.' },
      { url: '/lake/', title: 'A quiet morning at the lake', text: 'The water is still.' },
      { url: '/body/', title: 'Gear', text: 'We camped near the lake overnight.' },
    ];
    const hits = await fuseEngine(recs)('lake');
    expect(hits.map((h) => h.url).slice(0, 2)).toEqual(['/lake/', '/body/']); // title match, then body match
    expect(hits.map((h) => h.url)).not.toContain('/take/'); // near-matches are noise when exact hits exist
    expect((await fuseEngine(recs)('lakr')).map((h) => h.url)).toContain('/lake/'); // but typos still find pages
    expect((await fuseEngine(recs)('quiet morning')).at(0)?.url).toBe('/lake/');
  });

  it('snippet marks all query words and adds ellipses', () => {
    const s = makeSnippet('x'.repeat(100) + ' needle in a haystack ' + 'y'.repeat(200), 'needle haystack');
    expect(s.startsWith('…')).toBe(true);
    expect(s).toContain('\u0001needle\u0002');
    expect(s).toContain('\u0001haystack\u0002');
  });

  it('never interprets result text as HTML', () => {
    const p = document.createElement('p');
    appendHighlighted(p, '<img src=x onerror=alert(1)> \u0001hit\u0002 <script>bad()</script>');
    expect(p.querySelector('img')).toBeNull();
    expect(p.querySelector('script')).toBeNull();
    expect(p.querySelector('mark')?.textContent).toBe('hit');
    expect(p.textContent).toContain('<img src=x');
  });

  it('algolia engine calls the DSN endpoint with the search-only key and maps hits', async () => {
    const f = vi.fn(async () => new Response(JSON.stringify({ hits: [{ url: '/a/', title: 'A', _snippetResult: { text: { value: 'x \u0001y\u0002' } } }] })));
    const hits = await algoliaEngine({ appId: 'APP', searchKey: 'PUB', index: 'idx' }, 5, f as any)('y');
    const [url, init] = f.mock.calls[0] as any;
    expect(url).toBe('https://APP-dsn.algolia.net/1/indexes/idx/query');
    expect(init.headers['X-Algolia-API-Key']).toBe('PUB');
    expect(JSON.parse(init.body).hitsPerPage).toBe(5);
    expect(hits).toEqual([{ url: '/a/', title: 'A', snippet: 'x \u0001y\u0002' }]);
  });

  it('wires a data-ss-search box and WordPress search forms, showing results on input', async () => {
    document.body.innerHTML = '<div data-ss-search></div><form role="search" action="/"><input type="search" name="s"></form>';
    initSearch({ engine: 'fuse', replaceForms: true }, fuseEngine(records));
    const inputs = document.querySelectorAll<HTMLInputElement>('input');
    expect(inputs).toHaveLength(2);
    expect(document.querySelector('form[role=search][action]')).toBeNull(); // action removed from WP form

    inputs[1]!.value = 'contact';
    inputs[1]!.dispatchEvent(new Event('input'));
    await vi.waitFor(() => {
      const box = inputs[1]!.closest('form')!.querySelector('.ss-search-results') as HTMLElement;
      expect(box.hidden).toBe(false);
      expect(box.querySelector('a')?.getAttribute('href')).toBe('/contact/');
    });
  });
});
