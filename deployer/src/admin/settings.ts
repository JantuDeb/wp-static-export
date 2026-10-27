import { FORM_PROVIDERS, providerById } from './forms-help.js';
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


const CSS = `
.wpse-s{--c:#2271b1;--line:#dcdcde;--mut:#646970;max-width:1180px;margin:8px 20px 40px 0;color:#1d2327;font-size:14px}
.wpse-s *{box-sizing:border-box}
.wpse-s{width:auto;min-width:0}
.wpse-s .lay{display:grid;grid-template-columns:230px minmax(0,1fr);gap:20px;align-items:start}
.wpse-s .lay>*,.wpse-s .row>*,.wpse-s .rule,.wpse-s .rule .body{min-width:0}
.wpse-s .nav{background:#fff;border:1px solid var(--line);border-radius:8px;padding:8px;position:sticky;top:44px}
.wpse-s .nav a{display:block;padding:9px 12px;border-radius:6px;color:#1d2327;text-decoration:none;font-weight:500}
.wpse-s .nav a:hover{background:#f0f6fc}
.wpse-s .nav a.on{background:var(--c);color:#fff}
.wpse-s .pane{max-width:none;background:#fff;border:1px solid var(--line);border-radius:8px;padding:22px 24px}
.wpse-s .pane>h2{margin:0 0 4px;font-size:20px}
.wpse-s .lead{margin:0 0 18px;color:var(--mut);max-width:70ch;line-height:1.5}
.wpse-s .row{display:grid;grid-template-columns:240px minmax(0,1fr);gap:6px 24px;padding:16px 0;border-top:1px solid #f0f0f1}
.wpse-s .row>label.t,.wpse-s .row>.t{font-weight:600;padding-top:6px}
.wpse-s .row input[type=text],.wpse-s .row input[type=number],.wpse-s .row input[type=password],.wpse-s .row select,.wpse-s .row textarea{width:100%;max-width:560px}
.wpse-s .help{margin:6px 0 0;color:var(--mut);font-size:13px;line-height:1.45;max-width:70ch}
.wpse-s .banner{border-radius:8px;padding:12px 16px;margin-bottom:16px;background:#fcf9e8;border:1px solid #dba617}
.wpse-s .banner.ok{background:#edfaef;border-color:#00a32a}.wpse-s .banner.err{background:#fcf0f1;border-color:#d63638}
.wpse-s .bar{position:sticky;bottom:0;background:#fff;border-top:1px solid var(--line);margin:22px -24px -22px;padding:14px 24px;display:flex;gap:10px;flex-wrap:wrap;border-radius:0 0 8px 8px}
.wpse-s .switch{display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding-top:4px}
.wpse-s .switch input{position:absolute;opacity:0;width:1px;height:1px}
.wpse-s .switch i{width:40px;height:22px;border-radius:11px;background:#8c8f94;position:relative;transition:background .15s;flex:none}
.wpse-s .switch i::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .15s}
.wpse-s .switch input:checked+i{background:var(--c)}
.wpse-s .switch input:checked+i::after{transform:translateX(18px)}
.wpse-s .switch input:focus-visible+i{outline:2px solid var(--c);outline-offset:2px}
.wpse-s .tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(170px,100%),1fr));gap:10px;max-width:760px}
.wpse-s .tile{border:1px solid var(--line);border-radius:8px;background:#fff;padding:12px;text-align:left;cursor:pointer;font:inherit;color:inherit;min-height:64px}
.wpse-s .tile:hover{border-color:var(--c)}
.wpse-s .tile.on{border-color:var(--c);box-shadow:0 0 0 1px var(--c);background:#f0f6fc}
.wpse-s .tile b{display:block}.wpse-s .tile small{color:var(--mut);display:block;margin-top:2px;line-height:1.35}
.wpse-s .tag{display:inline-block;font-size:11px;font-weight:600;background:#edfaef;color:#00642a;border-radius:9px;padding:1px 7px;margin-left:6px;vertical-align:1px}
.wpse-s .rule{border:1px solid var(--line);border-radius:8px;margin:0 0 14px;background:#fbfbfc}
.wpse-s .rule>header{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--line);font-weight:600}
.wpse-s .rule .body{padding:6px 16px 14px}
.wpse-s .rule .f{padding:10px 0}
.wpse-s .rule .f>label{display:block;font-weight:600;margin-bottom:6px}
.wpse-s .rule input[type=text],.wpse-s .rule textarea{width:100%;max-width:600px}
.wpse-s details{margin:8px 0;border:1px solid var(--line);border-radius:6px;background:#fff}
.wpse-s summary{cursor:pointer;padding:10px 14px;font-weight:600}
.wpse-s details .in{padding:0 16px 14px}
.wpse-s ol.steps{margin:6px 0 0 18px;padding:0;line-height:1.6}
.wpse-s pre.code{max-width:100%;background:#1d2327;color:#f0f0f1;border-radius:6px;padding:12px;overflow:auto;font-size:12px;max-height:260px;margin:8px 0}
.wpse-s .warn{color:#8a4b00;background:#fcf9e8;border-radius:6px;padding:8px 12px;margin:8px 0;font-size:13px}
@media(max-width:900px){
 .wpse-s{margin-right:10px}
 .wpse-s .lay{grid-template-columns:minmax(0,1fr)}
 .wpse-s .nav{position:static;display:flex;overflow-x:auto;gap:4px;white-space:nowrap}
 .wpse-s .row{grid-template-columns:minmax(0,1fr)}
 .wpse-s .row>label.t,.wpse-s .row>.t{padding-top:0}
 .wpse-s .pane{padding:16px}.wpse-s .bar{margin:18px -16px -16px;padding:12px 16px}
}`;

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


  function tiles(options: Record<string, string>, current: string, locked: boolean, onPick: (v: string) => void, id: string): El {
    const wrap = h('div', { class: 'tiles', role: 'radiogroup', id });
    for (const [value, label] of Object.entries(options)) {
      const b = h('button', { type: 'button', class: `tile${value === current ? ' on' : ''}`, role: 'radio', 'aria-checked': String(value === current), 'data-value': value, disabled: locked }, h('b', {}, label));
      b.addEventListener('click', () => onPick(value));
      wrap.append(b);
    }
    return wrap;
  }

  function switchControl(field: Field, value: unknown, locked: boolean, onChange: (v: boolean) => void): El {
    const input = h('input', { type: 'checkbox', id: `ssf-${field.key}`, disabled: locked, checked: Boolean(value) });
    input.addEventListener('change', () => onChange(input.checked));
    return h('label', { class: 'switch' }, input, h('i'), h('span', {}, Boolean(value) ? 'On' : 'Off'));
  }

  function copyButton(text: string): El {
    const b = h('button', { type: 'button', class: 'button button-small' }, 'Copy');
    b.addEventListener('click', () => {
      void navigator.clipboard?.writeText(text).then(
        () => (b.textContent = 'Copied'),
        () => (b.textContent = 'Press Ctrl+C'),
      );
    });
    return b;
  }

  function formRules(field: Field, locked: boolean): El {
    const rows = (Array.isArray(state.values[field.key]) ? state.values[field.key] : []) as Values[];
    const wrap = h('div', { class: 'rules' });
    if (!rows.length) {
      wrap.append(h('p', { class: 'help' }, 'No rules yet. Add one to tell the site where form messages should go. Until then forms keep posting to WordPress, which does not exist on a static host.'));
    }
    const providerTiles: Record<string, string> = {};
    for (const p of FORM_PROVIDERS) providerTiles[p.id] = p.title;

    rows.forEach((row, i) => {
      const prov = providerById(String(row.provider ?? 'google-sheet'));
      const card = h('div', { class: 'rule' });
      const del = h('button', { type: 'button', class: 'button-link button-link-delete', disabled: locked }, 'Remove rule');
      del.addEventListener('click', () => {
        rows.splice(i, 1);
        setValue(field.key, rows);
      });
      card.append(h('header', {}, h('span', {}, `Rule ${i + 1}`), del));
      const body = h('div', { class: 'body' });

      // 1. Which forms
      const all = String(row.match ?? '*') === '*';
      const scope = h('select', { id: `ssf-rule-${i}-scope`, disabled: locked }, h('option', { value: 'all', selected: all }, 'Every form on the site'), h('option', { value: 'one', selected: !all }, 'Only a specific form'));
      scope.addEventListener('change', () => {
        row.match = scope.value === 'all' ? '*' : '';
        setValue(field.key, rows);
      });
      const f1 = h('div', { class: 'f' }, h('label', { for: `ssf-rule-${i}-scope` }, '1. Which forms?'), scope);
      if (!all) {
        const m = h('input', { type: 'text', value: String(row.match ?? ''), placeholder: 'contact-form or wpcf7-f123-p4-o1', style: 'margin-top:8px', disabled: locked });
        m.addEventListener('input', () => (row.match = m.value));
        f1.append(m, h('p', { class: 'help' }, 'The form’s id or a CSS class from the page. The first matching rule wins, so put specific rules above "every form".'));
      }
      body.append(f1);

      // 2. Destination
      const f2 = h('div', { class: 'f' }, h('label', {}, '2. Where should messages go?'));
      const grid = h('div', { class: 'tiles', role: 'radiogroup' });
      for (const p of FORM_PROVIDERS) {
        const t = h('button', { type: 'button', class: `tile${p.id === prov.id ? ' on' : ''}`, role: 'radio', 'aria-checked': String(p.id === prov.id), 'data-value': p.id, disabled: locked }, h('b', {}, p.title, ...(p.tag ? [h('span', { class: 'tag' }, p.tag)] : [])), h('small', {}, p.blurb));
        t.addEventListener('click', () => {
          row.provider = p.id;
          setValue(field.key, rows);
        });
        grid.append(t);
      }
      f2.append(grid);
      body.append(f2);

      // 3. Address
      const endpoint = h('input', { type: 'text', id: `ssf-rule-${i}-endpoint`, value: String(row.endpoint ?? ''), placeholder: prov.placeholder, disabled: locked });
      endpoint.addEventListener('input', () => (row.endpoint = endpoint.value));
      body.append(h('div', { class: 'f' }, h('label', { for: `ssf-rule-${i}-endpoint` }, `3. ${prov.endpointLabel}`), endpoint, h('p', { class: 'help' }, prov.endpointHelp)));

      // How to
      const how = h('details', {}, h('summary', {}, `How to set up ${prov.title}`));
      const inner = h('div', { class: 'in' });
      const ol = h('ol', { class: 'steps' });
      for (const step of prov.steps) ol.append(h('li', {}, step));
      inner.append(ol);
      if (prov.code) {
        inner.append(h('p', { class: 'help' }, prov.code.label), h('pre', { class: 'code' }, prov.code.text), copyButton(prov.code.text));
      }
      if (prov.caveat) inner.append(h('div', { class: 'warn' }, prov.caveat));
      how.append(inner);
      if (!row.endpoint) how.setAttribute('open', '');
      body.append(how);

      // Field map
      const mapLabel = prov.showMap ? '4. Field map (required)' : 'Rename fields (optional)';
      const map = h('textarea', { rows: '4', class: 'code', placeholder: prov.showMap ? 'your-name=entry.1111111111\nyour-email=entry.2222222222\nyour-message=entry.3333333333' : 'your-email=email', disabled: locked }, String(row.fields ?? ''));
      map.addEventListener('input', () => (row.fields = map.value));
      const mapBox = h('div', { class: 'f' }, h('label', {}, mapLabel), map, h('p', { class: 'help' }, prov.showMap ? 'One line per field: website field name = Google entry id. Fields without a line are not sent.' : 'One line per field: current name = new name. Leave empty to send fields as they are.'));
      if (prov.showMap) body.append(mapBox);
      else {
        const adv = h('details', {}, h('summary', {}, 'Advanced: rename fields'), h('div', { class: 'in' }, mapBox));
        body.append(adv);
      }

      // Message
      const msg = h('input', { type: 'text', value: String(row.success_message ?? ''), disabled: locked });
      msg.addEventListener('input', () => (row.success_message = msg.value));
      body.append(h('div', { class: 'f' }, h('label', {}, 'Message shown after sending'), msg));

      card.append(body);
      wrap.append(card);
    });

    const add = h('button', { type: 'button', class: 'button button-primary', disabled: locked }, 'Add a form rule');
    add.addEventListener('click', () => setValue(field.key, [...rows, { ...defaultRow(field.columns ?? []), match: '*', provider: 'google-sheet' }]));
    wrap.append(add);
    return wrap;
  }

  function fieldRow(field: Field): El {
    const locked = Boolean(state.locked[field.key]);
    const cell = h('div', { class: 'c' });
    let wide = false;
    if (field.type === 'repeater') {
      cell.append(formRules(field, locked));
      wide = true;
    } else if (field.secret) cell.append(secretControl(field, locked));
    else if (field.type === 'checkbox') cell.append(switchControl(field, state.values[field.key], locked, (v) => setValue(field.key, v)));
    else if (field.key === 'delivery_method') {
      cell.append(tiles(field.options ?? {}, String(state.values[field.key] ?? ''), locked, (v) => setValue(field.key, v), `ssf-${field.key}`));
    } else {
      cell.append(control(field, state.values[field.key], (v) => (state.values[field.key] = v), locked, false));
      if (field.type === 'select') cell.querySelector('select')?.addEventListener('change', () => render());
    }
    if (locked) cell.append(h('p', { class: 'help' }, `Set by the WPSE_${field.key.toUpperCase()} constant in wp-config.php.`));
    if (field.help) cell.append(h('p', { class: 'help' }, field.help));
    if (wide) return h('div', { class: 'row', style: 'grid-template-columns:minmax(0,1fr)' }, cell);
    return h('div', { class: 'row' }, h('label', { class: 't', for: `ssf-${field.key}` }, field.label), cell);
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
    const page = h('div', { class: 'wpse-s' });

    if (state.env.node_error || !state.env.bundle) {
      page.append(h('div', { class: 'banner' }, h('b', {}, 'Node.js is needed for deploy targets, optimization and site checks. '), state.env.node_error ?? 'The deployer bundle is missing: run "npm run build" in the deployer folder.'));
    }
    if (notice) page.append(h('div', { class: `banner ${notice.kind === 'success' ? 'ok' : 'err'}`, role: 'status' }, notice.text));

    const nav = h('nav', { class: 'nav', 'aria-label': 'Sections' });
    state.schema.forEach((s, i) => {
      const a = h('a', { href: '#', class: i === active ? 'on' : '', 'aria-current': i === active ? 'page' : false }, s.label);
      a.addEventListener('click', (e) => {
        e.preventDefault();
        active = i;
        render();
      });
      nav.append(a);
    });

    const card = h('div', { class: 'pane' }, h('h2', {}, section.label));
    if (section.description) card.append(h('p', { class: 'lead' }, section.description));
    for (const field of section.fields) if (isVisible(field, state.values)) card.append(fieldRow(field));

    const bar = h('div', { class: 'bar' });
    const saveBtn = h('button', { type: 'button', class: 'button button-primary', disabled: busy }, busy ? 'Working…' : 'Save changes');
    saveBtn.addEventListener('click', () => void save());
    bar.append(saveBtn);
    if (section.id === 'deploy' && !['zip', 'local', ''].includes(String(state.values.delivery_method ?? ''))) {
      const t = h('button', { type: 'button', class: 'button', disabled: busy }, 'Save & test connection');
      t.addEventListener('click', () => void verify());
      bar.append(t);
    }
    card.append(bar);

    page.append(h('div', { class: 'lay' }, nav, card));
    root.append(page);
  }

  root.textContent = 'Loading…';
  api('/settings')
    .then((s) => {
      if (!document.getElementById('wpse-settings-css')) document.head.append(h('style', { id: 'wpse-settings-css' }, CSS));
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
