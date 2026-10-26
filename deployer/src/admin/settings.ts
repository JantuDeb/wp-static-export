import { buildPayload, defaultRow, isVisible, type Field, type Section, type Values } from './logic.js';

interface Boot {
  restUrl: string;
  nonce: string;
}

interface State {
  schema: Section[];
  values: Values;
  secrets: Record<string, boolean>;
  locked: Record<string, boolean>;
  env: { node: string | null; node_error: string | null; bundle: boolean; multilingual: Record<string, unknown> | null };
}

type El = HTMLElement;

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

export function mount(root: El, boot: Boot): void {
  const api = async (path: string, init: RequestInit = {}) => {
    const res = await fetch(boot.restUrl + path, {
      ...init,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce, ...(init.headers ?? {}) },
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((body as any).message ?? `Request failed (${res.status})`);
    return body;
  };

  let state: State;
  let active = 0;
  const secretEdits: Record<string, string> = {};
  const secretClears = new Set<string>();
  let notice: { kind: 'success' | 'error'; text: string } | null = null;
  let busy = false;

  const setValue = (key: string, v: unknown) => {
    state.values[key] = v;
    render(); // visibility may depend on this value
  };

  function control(field: Field, value: unknown, onChange: (v: unknown) => void, locked: boolean, rerenderOnInput: boolean): El {
    const id = `ssf-${field.key}`;
    switch (field.type) {
      case 'checkbox': {
        const input = h('input', { type: 'checkbox', id, disabled: locked, checked: Boolean(value) });
        input.addEventListener('change', () => onChange(input.checked));
        return input;
      }
      case 'select': {
        const select = h('select', { id, disabled: locked });
        for (const [v, label] of Object.entries(field.options ?? {})) {
          const o = h('option', { value: v, selected: String(value ?? '') === v }, label);
          select.append(o);
        }
        select.addEventListener('change', () => onChange(select.value));
        return select;
      }
      case 'textarea': {
        const ta = h('textarea', { id, rows: '4', class: 'large-text code', disabled: locked }, String(value ?? ''));
        ta.addEventListener(rerenderOnInput ? 'change' : 'input', () => onChange(ta.value));
        return ta;
      }
      default: {
        const input = h('input', {
          id,
          type: field.type === 'number' ? 'number' : 'text',
          class: 'regular-text',
          disabled: locked,
          value: String(value ?? ''),
        });
        input.addEventListener(rerenderOnInput ? 'change' : 'input', () => onChange(field.type === 'number' ? Number(input.value) : input.value));
        return input;
      }
    }
  }

  function secretControl(field: Field, locked: boolean): El {
    const wrap = h('span', { class: 'ss-secret' });
    const isSet = state.secrets[field.key] && !secretClears.has(field.key);
    const input =
      field.type === 'textarea'
        ? h('textarea', { rows: '4', class: 'large-text code', disabled: locked, placeholder: isSet ? '•••••••• (saved — paste to replace)' : '' })
        : h('input', { type: 'password', class: 'regular-text', autocomplete: 'new-password', disabled: locked, placeholder: isSet ? '•••••••• (saved)' : '' });
    input.addEventListener('input', () => {
      secretEdits[field.key] = (input as HTMLInputElement).value;
      secretClears.delete(field.key);
    });
    wrap.append(input);
    if (isSet && !locked) {
      const clear = h('button', { type: 'button', class: 'button-link button-link-delete', style: 'margin-left:8px' }, 'Remove');
      clear.addEventListener('click', () => {
        secretClears.add(field.key);
        delete secretEdits[field.key];
        render();
      });
      wrap.append(clear);
    }
    return wrap;
  }

  function repeater(field: Field, locked: boolean): El {
    const rows = (Array.isArray(state.values[field.key]) ? state.values[field.key] : []) as Values[];
    const cols = field.columns ?? [];
    const table = h('table', { class: 'widefat striped', style: 'max-width:960px' });
    table.append(h('thead', {}, h('tr', {}, ...cols.map((c) => h('th', {}, c.label)), h('th', {}, ''))));
    const body = h('tbody');
    rows.forEach((row, i) => {
      const tr = h('tr');
      for (const col of cols) {
        const td = h('td');
        td.append(
          control(
            col,
            row[col.key],
            (v) => {
              row[col.key] = v;
            },
            locked,
            false,
          ),
        );
        tr.append(td);
      }
      const del = h('button', { type: 'button', class: 'button-link button-link-delete' }, 'Remove');
      del.addEventListener('click', () => {
        rows.splice(i, 1);
        setValue(field.key, rows);
      });
      tr.append(h('td', {}, del));
      body.append(tr);
    });
    table.append(body);
    const add = h('button', { type: 'button', class: 'button', style: 'margin-top:8px', disabled: locked }, 'Add rule');
    add.addEventListener('click', () => setValue(field.key, [...rows, defaultRow(cols)]));
    return h('div', {}, table, add);
  }

  function fieldRow(field: Field): El {
    const locked = Boolean(state.locked[field.key]);
    const cell = h('td');
    if (field.type === 'repeater') cell.append(repeater(field, locked));
    else if (field.secret) cell.append(secretControl(field, locked));
    else cell.append(control(field, state.values[field.key], (v) => (state.values[field.key] = v), locked, false));
    if (field.type === 'select' || field.type === 'checkbox') {
      // Selecting/toggling can reveal other fields.
      const input = cell.querySelector('select,input[type=checkbox]');
      input?.addEventListener('change', () => render());
    }
    if (locked) cell.append(h('p', { class: 'description' }, `Set by the WPSE_${field.key.toUpperCase()} constant in wp-config.php.`));
    if (field.help) cell.append(h('p', { class: 'description' }, field.help));
    return h('tr', {}, h('th', { scope: 'row' }, h('label', { for: `ssf-${field.key}` }, field.label)), cell);
  }

  async function save() {
    busy = true;
    notice = null;
    render();
    try {
      const body = JSON.stringify({ values: buildPayload({ schema: state.schema, values: state.values, secretEdits, secretClears, locked: state.locked }) });
      state = (await api('/settings', { method: 'POST', body })) as State;
      for (const k of Object.keys(secretEdits)) delete secretEdits[k];
      secretClears.clear();
      notice = { kind: 'success', text: 'Settings saved.' };
    } catch (e) {
      notice = { kind: 'error', text: (e as Error).message };
    }
    busy = false;
    render();
  }

  async function verify() {
    busy = true;
    notice = null;
    render();
    try {
      // Test what is saved, so save pending edits first.
      await save();
      if (notice && (notice as any).kind === 'error') return;
      const r = (await api('/verify', { method: 'POST', body: '{}' })) as { message?: string };
      notice = { kind: 'success', text: r.message ?? 'Connection OK.' };
    } catch (e) {
      notice = { kind: 'error', text: (e as Error).message };
    }
    busy = false;
    render();
  }

  function render() {
    root.replaceChildren();
    const section = state.schema[active] as Section;

    if (state.env.node_error || !state.env.bundle) {
      root.append(
        h(
          'div',
          { class: 'notice notice-warning inline' },
          h(
            'p',
            {},
            state.env.node_error ?? 'The deployer bundle is missing — run "npm run build" in the deployer folder. Deploy targets will not work until fixed.',
          ),
        ),
      );
    }
    if (notice) root.append(h('div', { class: `notice notice-${notice.kind} inline` }, h('p', {}, notice.text)));

    const tabs = h('h2', { class: 'nav-tab-wrapper' });
    state.schema.forEach((s, i) => {
      const a = h('a', { href: '#', class: `nav-tab${i === active ? ' nav-tab-active' : ''}` }, s.label);
      a.addEventListener('click', (e) => {
        e.preventDefault();
        active = i;
        render();
      });
      tabs.append(a);
    });
    root.append(tabs);

    if (section.description) root.append(h('p', {}, section.description));
    const table = h('table', { class: 'form-table', role: 'presentation' });
    const tbody = h('tbody');
    for (const field of section.fields) if (isVisible(field, state.values)) tbody.append(fieldRow(field));
    table.append(tbody);
    root.append(table);

    const actions = h('p', { class: 'submit' });
    const saveBtn = h('button', { type: 'button', class: 'button button-primary', disabled: busy }, busy ? 'Working…' : 'Save changes');
    saveBtn.addEventListener('click', () => void save());
    actions.append(saveBtn);
    if (section.id === 'deploy' && !['zip', 'local', ''].includes(String(state.values.delivery_method ?? ''))) {
      const t = h('button', { type: 'button', class: 'button', style: 'margin-left:8px', disabled: busy }, 'Save & test connection');
      t.addEventListener('click', () => void verify());
      actions.append(t);
    }
    root.append(actions);
  }

  root.textContent = 'Loading…';
  api('/settings')
    .then((s) => {
      state = s as State;
      render();
    })
    .catch((e: Error) => {
      root.replaceChildren(h('div', { class: 'notice notice-error inline' }, h('p', {}, e.message)));
    });
}

declare global {
  interface Window {
    ssFeatures?: Boot;
  }
}

if (typeof window !== 'undefined' && window.ssFeatures) {
  const root = document.getElementById('ss-features-root');
  if (root) mount(root, window.ssFeatures);
}
