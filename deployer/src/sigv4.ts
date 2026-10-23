import { createHash, createHmac } from 'node:crypto';

export interface Creds {
  accessKeyId: string;
  secretAccessKey: string;
  sessionToken?: string;
}

const hmac = (key: Buffer | string, data: string) => createHmac('sha256', key).update(data).digest();
export const sha256Hex = (data: Buffer | string) => createHash('sha256').update(data).digest('hex');

const enc = (s: string) => encodeURIComponent(s).replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase());

/** Canonical URI: each path segment URI-encoded once (S3 style, no double-encoding). */
export function canonicalPath(p: string): string {
  return p.split('/').map(enc).join('/') || '/';
}

/**
 * AWS Signature V4. Returns headers to add to the request (including Authorization).
 * `query` must already be sorted/encoded as it will appear in the URL.
 */
export function signV4(opts: {
  method: string;
  host: string;
  path: string;
  query?: string;
  headers?: Record<string, string>;
  body: Buffer | string;
  region: string;
  service: string;
  creds: Creds;
  date?: Date;
}): Record<string, string> {
  const d = (opts.date ?? new Date()).toISOString().replace(/[:-]|\.\d{3}/g, '');
  const day = d.slice(0, 8);
  const payloadHash = sha256Hex(opts.body);
  const headers: Record<string, string> = {
    ...Object.fromEntries(Object.entries(opts.headers ?? {}).map(([k, v]) => [k.toLowerCase(), v.trim()])),
    host: opts.host,
    'x-amz-content-sha256': payloadHash,
    'x-amz-date': d,
  };
  if (opts.creds.sessionToken) headers['x-amz-security-token'] = opts.creds.sessionToken;

  const names = Object.keys(headers).sort();
  const canonicalHeaders = names.map((n) => `${n}:${headers[n]}\n`).join('');
  const signedHeaders = names.join(';');
  const canonical = [opts.method, canonicalPath(opts.path), opts.query ?? '', canonicalHeaders, signedHeaders, payloadHash].join('\n');
  const scope = `${day}/${opts.region}/${opts.service}/aws4_request`;
  const toSign = ['AWS4-HMAC-SHA256', d, scope, sha256Hex(canonical)].join('\n');
  const kDate = hmac('AWS4' + opts.creds.secretAccessKey, day);
  const kSigning = hmac(hmac(hmac(kDate, opts.region), opts.service), 'aws4_request');
  const signature = createHmac('sha256', kSigning).update(toSign).digest('hex');

  headers['authorization'] = `AWS4-HMAC-SHA256 Credential=${opts.creds.accessKeyId}/${scope}, SignedHeaders=${signedHeaders}, Signature=${signature}`;
  delete headers['host'];
  return headers;
}
