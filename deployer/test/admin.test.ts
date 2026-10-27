// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import { buildPayload, CLEAR_MARK, isVisible, type Section } from '../src/admin/logic.js';
import { mount } from '../src/admin/settings.js';

const schema: Section[] = [
  {
    id: 'deploy',
    label: 'Deployment',
    fields: [
      { key: 'delivery_method', label: 'Deliver to', type: 'select', options: { zip: 'ZIP', netlify: 'Netlify', github: 'GitHub' } },
      { key: 'netlify_token', label: 'Token', type: 'password', secret: true, show_if: { delivery_method: 'netlify' } },
      { key: 'netlify_site_id', label: 'Site ID', type: 'text', show_if: { delivery_method: 'netlify' } },
      { key: 'github_repository', label: 'Repo', type: 'text', show_if: { delivery_method: 'github' } },
    ],
  },
  {
    id: 'forms',
    label: 'Forms',
    fields: [
      { key: 'forms_enabled', label: 'Enable', type: 'checkbox' },
      {
        key: 'forms_rules',
        label: 'Rules',
        type: 'repeater',
        show_if: { forms_enabled: true },
        columns: [
          { key: 'match', label: 'Match', type: 'text', default: '*' },
          { key: 'endpoint', label: 'Endpoint', type: 'text' },
        ],
      },
    ],
  },
];

describe('logic', () => {
  it('evaluates show_if for strings, lists and booleans', () => {
    expect(isVisible({ key: 'a', label: '', type: 'text', show_if: { m: 'x' } }, { m: 'x' })).toBe(true);
    expect(isVisible({ key: 'a', label: '', type: 'text', show_if: { m: ['x', 'y'] } }, { m: 'y' })).toBe(true);
    expect(isVisible({ key: 'a', label: '', type: 'text', show_if: { m: ['x', 'y'] } }, { m: 'z' })).toBe(false);
    expect(isVisible({ key: 'a', label: '', type: 'text', show_if: { on: true } }, { on: false })).toBe(false);
  });

  it('sends secrets only when edited or cleared, and skips locked fields', () => {
    const base = { schema, values: { delivery_method: 'netlify', netlify_site_id: 's' }, locked: { netlify_site_id: true } };
    expect(buildPayload({ ...base, secretEdits: {}, secretClears: new Set() })).not.toHaveProperty('netlify_token');
    expect(buildPayload({ ...base, secretEdits: {}, secretClears: new Set() })).not.toHaveProperty('netlify_site_id');
    expect(buildPayload({ ...base, secretEdits: { netlify_token: 'abc' }, secretClears: new Set() }).netlify_token).toBe('abc');
    expect(buildPayload({ ...base, secretEdits: {}, secretClears: new Set(['netlify_token']) }).netlify_token).toBe(CLEAR_MARK);
  });
});

describe('settings UI', () => {
  const tick = () => new Promise((r) => setTimeout(r, 0));

  it('shows target fields for the selected method, hides the saved secret and posts only edits', async () => {
    const calls: { url: string; init?: RequestInit }[] = [];
    const state = {
      schema,
      values: { delivery_method: 'zip', netlify_site_id: '', github_repository: '', forms_enabled: false, forms_rules: [] },
      secrets: { netlify_token: true },
      locked: {},
      env: { node: 'node', node_error: null, bundle: true, multilingual: null },
    };
    vi.stubGlobal('fetch', async (url: string, init?: RequestInit) => {
      calls.push({ url, init });
      return new Response(JSON.stringify(state), { status: 200 });
    });
    const root = document.createElement('div');
    document.body.append(root);
    mount(root, { restUrl: '/rest', nonce: 'n' });
    await tick();

    expect(root.querySelector('#ssf-netlify_site_id')).toBeNull();
    (root.querySelector('#ssf-delivery_method [data-value="netlify"]') as HTMLElement).click();
    expect(root.querySelector('#ssf-netlify_site_id')).not.toBeNull();

    // The saved secret is never rendered as a value.
    const secret = root.querySelector('input[type=password]') as HTMLInputElement;
    expect(secret.value).toBe('');
    expect(secret.placeholder).toContain('saved');

    (root.querySelector('.button-primary') as HTMLButtonElement).click();
    await tick();
    const post = calls.find((c) => c.init?.method === 'POST')!;
    const sent = JSON.parse(String(post.init!.body)).values;
    expect(sent.delivery_method).toBe('netlify');
    expect(sent).not.toHaveProperty('netlify_token');
    expect((post.init!.headers as Record<string, string>)['X-WP-Nonce']).toBe('n');
    vi.unstubAllGlobals();
  });

  it('adds repeater rows with defaults', async () => {
    const state = {
      schema,
      values: { delivery_method: 'zip', forms_enabled: true, forms_rules: [] },
      secrets: {},
      locked: {},
      env: { node: 'node', node_error: null, bundle: true, multilingual: null },
    };
    vi.stubGlobal('fetch', async () => new Response(JSON.stringify(state), { status: 200 }));
    const root = document.createElement('div');
    mount(root, { restUrl: '/rest', nonce: 'n' });
    await tick();
    (root.querySelectorAll('.nav a')[1] as HTMLElement).click();
    const add = Array.from(root.querySelectorAll('button')).find((b) => b.textContent === 'Add a form rule')!;
    add.click();
    // A new rule covers every form and starts on the recommended destination.
    expect((root.querySelector('#ssf-rule-0-scope') as HTMLSelectElement).value).toBe('all');
    expect(root.querySelector('.tile.on')?.textContent).toContain('Google Sheet');
    expect(root.textContent).toContain('Extensions → Apps Script');
    (root.querySelector('[data-value="formspree"]') as HTMLElement).click();
    expect(root.querySelector('.tile.on')?.textContent).toContain('Formspree');
    expect(root.textContent).toContain('formspree.io');
    vi.unstubAllGlobals();
  });
});
