/**
 * Runtime for static forms. Loaded on exported pages that contain rewritten forms.
 * Each form carries data-ss-form="<rule index>"; the rules live in #ss-forms-config.
 */

export type Provider = 'google-form' | 'google-sheet' | 'netlify' | 'formspree' | 'webhook' | 'mailto';

export interface Rule {
  provider: Provider;
  endpoint: string;
  fields?: string;
  success_message?: string;
  redirect?: string;
}

export type Fields = Record<string, string[]>;

export interface Built {
  url: string;
  init: RequestInit;
  /** no-cors request: the response is opaque, so success can't be verified. */
  opaque: boolean;
  /** For mailto: the URL to navigate to instead of fetching. */
  navigate?: string;
}

/** Hidden/technical inputs added by form plugins that must not reach the destination. */
const EXCLUDE =
  /^(_wpcf7.*|_wpnonce|_wp_http_referer|_ss_hp|gform_.*|is_submit_\d+|state_\d+|wpforms\[(id|nonce|submit|post_id|hp)\]|wpforms_.*|_fluentform_.*|__fluent_.*|fluent_.*|ff_.*|g-recaptcha-response|h-captcha-response|cf-turnstile-response|action|form-name|bot-field)$/;

export function parseFieldMap(text: string | undefined): Map<string, string> {
  const map = new Map<string, string>();
  for (const raw of (text ?? '').split('\n')) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const i = line.indexOf('=');
    if (i < 1) continue;
    map.set(line.slice(0, i).trim(), line.slice(i + 1).trim());
  }
  return map;
}

export function collectFields(form: HTMLFormElement): Fields {
  const out: Fields = {};
  new FormData(form).forEach((value, key) => {
    if (typeof value !== 'string' || EXCLUDE.test(key)) return;
    (out[key] ??= []).push(value);
  });
  return out;
}

const joined = (v: string[]) => (v.length === 1 ? (v[0] as string) : v.join(', '));

export function googleFormUrl(endpoint: string): string {
  if (/^https?:\/\//i.test(endpoint)) return endpoint.replace(/\/viewform.*$/, '/formResponse');
  return `https://docs.google.com/forms/d/e/${endpoint}/formResponse`;
}

export function buildRequest(rule: Rule, fields: Fields, ctx: { formName: string; pageUrl: string; now?: Date }): Built {
  const map = parseFieldMap(rule.fields);
  const renamed: Fields = {};
  for (const [k, v] of Object.entries(fields)) renamed[map.get(k) ?? k] = v;

  switch (rule.provider) {
    case 'google-form': {
      // Google only accepts entry.NNN parameters, so unmapped fields are dropped.
      const body = new URLSearchParams();
      for (const [k, v] of Object.entries(fields)) {
        const target = map.get(k);
        if (target) v.forEach((x) => body.append(target, x));
      }
      return { url: googleFormUrl(rule.endpoint), init: { method: 'POST', mode: 'no-cors', body }, opaque: true };
    }
    case 'google-sheet': {
      const payload: Record<string, string> = Object.fromEntries(Object.entries(renamed).map(([k, v]) => [k, joined(v)]));
      payload._page = ctx.pageUrl;
      payload._submitted = (ctx.now ?? new Date()).toISOString();
      // text/plain avoids a CORS preflight, which Apps Script web apps don't answer.
      return {
        url: rule.endpoint,
        init: { method: 'POST', mode: 'no-cors', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify(payload) },
        opaque: true,
      };
    }
    case 'netlify': {
      const body = new URLSearchParams({ 'form-name': ctx.formName });
      for (const [k, v] of Object.entries(renamed)) v.forEach((x) => body.append(k, x));
      return { url: '/', init: { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() }, opaque: false };
    }
    case 'formspree': {
      const url = /^https?:\/\//i.test(rule.endpoint) ? rule.endpoint : `https://formspree.io/f/${rule.endpoint}`;
      const payload = Object.fromEntries(Object.entries(renamed).map(([k, v]) => [k, joined(v)]));
      return {
        url,
        init: { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload) },
        opaque: false,
      };
    }
    case 'webhook': {
      const payload = {
        form: ctx.formName,
        page: ctx.pageUrl,
        submitted: (ctx.now ?? new Date()).toISOString(),
        fields: Object.fromEntries(Object.entries(renamed).map(([k, v]) => [k, v.length === 1 ? v[0] : v])),
      };
      return { url: rule.endpoint, init: { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }, opaque: false };
    }
    case 'mailto': {
      const lines = Object.entries(renamed).map(([k, v]) => `${k}: ${joined(v)}`);
      const navigate = `mailto:${rule.endpoint}?subject=${encodeURIComponent('Message from ' + ctx.pageUrl)}&body=${encodeURIComponent(lines.join('\n'))}`;
      return { url: '', init: {}, opaque: true, navigate };
    }
    default:
      throw new Error(`Unknown form provider: ${String((rule as Rule).provider)}`);
  }
}

function status(form: HTMLFormElement): HTMLElement {
  let el = form.nextElementSibling as HTMLElement | null;
  if (!el || !el.classList.contains('ss-form-status')) {
    el = document.createElement('div');
    el.className = 'ss-form-status';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    form.after(el);
  }
  return el;
}

function setStatus(form: HTMLFormElement, kind: 'ok' | 'error', text: string) {
  const el = status(form);
  el.className = `ss-form-status ss-form-${kind}`;
  el.textContent = text;
}

export interface InitOptions {
  /** Minimum ms between page load and submit; faster submits are treated as bots. */
  minFillMs?: number;
  fetchImpl?: typeof fetch;
  navigate?: (url: string) => void;
}

export function init(doc: Document = document, opts: InitOptions = {}): () => void {
  const raw = doc.getElementById('ss-forms-config')?.textContent;
  if (!raw) return () => undefined;
  const rules: Rule[] = (JSON.parse(raw) as { rules: Rule[] }).rules;
  const started = Date.now();
  const minFill = opts.minFillMs ?? 1200;
  const doFetch = opts.fetchImpl ?? ((...a: Parameters<typeof fetch>) => fetch(...a));
  const go = opts.navigate ?? ((u: string) => void (location.href = u));

  // Honeypot: real users never see or fill it.
  doc.querySelectorAll<HTMLFormElement>('form[data-ss-form]').forEach((form) => {
    const wrap = doc.createElement('div');
    wrap.setAttribute('aria-hidden', 'true');
    wrap.style.cssText = 'position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden';
    const hp = doc.createElement('input');
    hp.type = 'text';
    hp.name = '_ss_hp';
    hp.tabIndex = -1;
    hp.autocomplete = 'off';
    wrap.append(hp);
    form.append(wrap);
  });

  const onSubmit = async (event: Event) => {
    const form = event.target as HTMLFormElement;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ss-form')) return;
    // Take over from form-plugin scripts (CF7, WPForms...) that would call WordPress endpoints.
    event.preventDefault();
    event.stopImmediatePropagation();

    const rule = rules[Number(form.getAttribute('data-ss-form'))];
    if (!rule) return;
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    const hp = form.querySelector<HTMLInputElement>('input[name="_ss_hp"]');
    const tooFast = Date.now() - started < minFill;
    const message = rule.success_message || 'Thank you! Your message has been sent.';
    if (hp && hp.value) {
      // Pretend success so bots get no signal.
      setStatus(form, 'ok', message);
      return;
    }
    if (tooFast) {
      setStatus(form, 'error', 'Please wait a moment and try again.');
      return;
    }

    const buttons = Array.from(form.querySelectorAll<HTMLButtonElement | HTMLInputElement>('button:not([type=button]), input[type=submit]'));
    buttons.forEach((b) => (b.disabled = true));
    try {
      const built = buildRequest(rule, collectFields(form), {
        formName: form.getAttribute('data-ss-name') || form.id || `form-${form.getAttribute('data-ss-form')}`,
        pageUrl: location.href,
      });
      if (built.navigate) {
        go(built.navigate);
      } else {
        const res = await doFetch(built.url, built.init);
        if (!built.opaque && !res.ok) {
          let detail = '';
          try {
            const body = (await res.json()) as { errors?: { message?: string }[]; error?: string };
            detail = body.errors?.map((e) => e.message).join(' ') || body.error || '';
          } catch {
            /* not JSON */
          }
          throw new Error(detail || `The server answered ${res.status}`);
        }
      }
      form.reset();
      setStatus(form, 'ok', message);
      if (rule.redirect) go(rule.redirect);
    } catch (e) {
      setStatus(form, 'error', `Sorry, your message could not be sent. ${(e as Error).message}`);
    } finally {
      buttons.forEach((b) => (b.disabled = false));
    }
  };
  doc.addEventListener('submit', onSubmit, true);
  return () => doc.removeEventListener('submit', onSubmit, true);
}

if (typeof document !== 'undefined' && !(globalThis as { __SS_TEST__?: boolean }).__SS_TEST__) {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
  else init();
}
