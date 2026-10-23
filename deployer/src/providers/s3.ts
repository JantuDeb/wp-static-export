import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { chunk, http, mimeType, pool, required } from '../util.js';
import { canonicalPath, signV4, type Creds } from '../sigv4.js';
import { type Provider } from '../types.js';

export interface S3Config extends Creds {
  bucket: string;
  region: string;
  /** Custom endpoint for S3-compatible stores (R2, MinIO, DigitalOcean Spaces...). */
  endpoint?: string;
  /** Use path-style addressing (implied when a custom endpoint is set). */
  pathStyle?: boolean;
  /** Key prefix, e.g. "site/". */
  prefix?: string;
  cacheControl?: string;
  /** Per-extension override of Cache-Control, e.g. { html: "no-cache" }. */
  cacheControlByExt?: Record<string, string>;
  cloudfrontDistributionId?: string;
}

function target(cfg: S3Config, key: string) {
  const pathStyle = cfg.pathStyle || !!cfg.endpoint;
  const base = cfg.endpoint ? new URL(cfg.endpoint) : new URL(`https://s3.${cfg.region}.amazonaws.com`);
  if (pathStyle) {
    const p = `${base.pathname.replace(/\/$/, '')}/${cfg.bucket}/${key}`;
    return { host: base.host, path: p, url: `${base.protocol}//${base.host}` };
  }
  const host = `${cfg.bucket}.${base.host}`;
  return { host, path: `/${key}`, url: `${base.protocol}//${host}` };
}

async function s3(cfg: S3Config, method: string, key: string, body: Buffer | string = '', headers: Record<string, string> = {}, query = '') {
  const t = target(cfg, key);
  const signed = signV4({ method, host: t.host, path: t.path, query, headers, body, region: cfg.region, service: 's3', creds: cfg });
  return http(`${t.url}${canonicalPath(t.path)}${query ? '?' + query : ''}`, { method, headers: signed, body: body === '' ? undefined : (body as any) });
}

export const s3Provider: Provider<S3Config> = {
  id: 's3',
  validate(c) {
    required(c as Record<string, unknown>, ['bucket', 'region', 'accessKeyId', 'secretAccessKey'], 's3');
    return c as S3Config;
  },
  async deploy({ changed, deleted, config, concurrency, log }) {
    const prefix = (config.prefix ?? '').replace(/^\/+/, '').replace(/([^/])$/, '$1/').replace(/^\/$/, '');
    let done = 0;
    await pool(changed, concurrency, async (f) => {
      const ext = f.path.split('.').pop()?.toLowerCase() ?? '';
      const cache = config.cacheControlByExt?.[ext] ?? config.cacheControl;
      await s3(config, 'PUT', prefix + f.path, await readFile(f.abs), {
        'content-type': mimeType(f.path),
        ...(cache ? { 'cache-control': cache } : {}),
      });
      log.progress(++done, changed.length, 'objects');
    });

    // Batch delete (max 1000 keys per request).
    for (const part of chunk(deleted, 1000)) {
      const xml =
        '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>' +
        part.map((p) => `<Object><Key>${escapeXml(prefix + p)}</Key></Object>`).join('') +
        '</Delete>';
      const md5 = createHash('md5').update(xml).digest('base64');
      await s3(config, 'POST', '', xml, { 'content-type': 'application/xml', 'content-md5': md5 }, 'delete=');
    }

    if (config.cloudfrontDistributionId && (changed.length || deleted.length)) {
      await invalidate(config, [...changed.map((f) => f.path), ...deleted], prefix);
      log.info('CloudFront invalidation requested');
    }
    return { uploaded: changed.length, deleted: deleted.length, message: `Synced to s3://${config.bucket}/${prefix}` };
  },
};

const escapeXml = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

async function invalidate(cfg: S3Config, paths: string[], _prefix: string) {
  // Invalidate index pages by their pretty URL as well as the file path.
  const urls = new Set<string>();
  for (const p of paths) {
    urls.add('/' + p);
    if (p.endsWith('/index.html')) urls.add('/' + p.slice(0, -'index.html'.length));
  }
  // Wildcard is cheaper than thousands of paths (first 1000/month free, then per path).
  const items = urls.size > 500 ? ['/*'] : [...urls];
  const xml =
    '<?xml version="1.0" encoding="UTF-8"?><InvalidationBatch xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">' +
    `<CallerReference>${Date.now()}</CallerReference><Paths><Quantity>${items.length}</Quantity><Items>` +
    items.map((i) => `<Path>${escapeXml(i)}</Path>`).join('') +
    '</Items></Paths></InvalidationBatch>';
  const path = `/2020-05-31/distribution/${cfg.cloudfrontDistributionId}/invalidation`;
  const headers = signV4({ method: 'POST', host: 'cloudfront.amazonaws.com', path, headers: { 'content-type': 'application/xml' }, body: xml, region: 'us-east-1', service: 'cloudfront', creds: cfg });
  await http(`https://cloudfront.amazonaws.com${path}`, { method: 'POST', headers, body: xml });
}
