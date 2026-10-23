import { createServer, type Server } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import { mimeType } from '../../src/util.js';

/** Parse a Netlify/Cloudflare-style `_headers` file into [pattern, headers] rules. */
export function parseHeaders(text: string): { re: RegExp; headers: Record<string, string> }[] {
  const rules: { re: RegExp; headers: Record<string, string> }[] = [];
  let current: { re: RegExp; headers: Record<string, string> } | null = null;
  for (const raw of text.split('\n')) {
    if (!raw.trim() || raw.trim().startsWith('#')) continue;
    if (!/^\s/.test(raw)) {
      const pattern = raw
        .trim()
        .replace(/[.+?^${}()|[\]\\]/g, '\\$&')
        .replace(/\*/g, '.*');
      current = { re: new RegExp('^' + pattern + '$'), headers: {} };
      rules.push(current);
    } else if (current) {
      const i = raw.indexOf(':');
      if (i > 0) current.headers[raw.slice(0, i).trim().toLowerCase()] = raw.slice(i + 1).trim();
    }
  }
  return rules;
}

const COMPRESSIBLE = /^(text\/|application\/(json|javascript|xml|rss\+xml|atom\+xml)|image\/svg\+xml)/;

export interface ServerHandle {
  url: string;
  close: () => Promise<void>;
}

/** Serve an export directory the way static hosts do: pretty URLs, 404.html, gzip, `_headers`. */
export async function serveExport(dir: string, port = 0): Promise<ServerHandle> {
  const rules = parseHeaders(await readFile(path.join(dir, '_headers'), 'utf8').catch(() => ''));
  const root = path.resolve(dir);

  const resolveFile = async (urlPath: string): Promise<string | null> => {
    const clean = path.normalize(decodeURIComponent(urlPath.split('?')[0] ?? '/')).replace(/^(\.\.[/\\])+/, '');
    const abs = path.join(root, clean);
    if (!abs.startsWith(root)) return null;
    const s = await stat(abs).catch(() => null);
    if (s?.isFile()) return abs;
    const index = path.join(abs, 'index.html');
    if ((await stat(index).catch(() => null))?.isFile()) return index;
    const html = abs + '.html';
    if ((await stat(html).catch(() => null))?.isFile()) return html;
    return null;
  };

  const server: Server = createServer(async (req, res) => {
    try {
      const urlPath = req.url ?? '/';
      let file = await resolveFile(urlPath);
      let status = 200;
      if (!file) {
        file = await resolveFile('/404.html');
        status = 404;
        if (!file) {
          res.writeHead(404).end('Not found');
          return;
        }
      }
      let body: Buffer = await readFile(file);
      const type = mimeType(file);
      const headers: Record<string, string> = { 'content-type': type };

      const pathname = new URL(urlPath, 'http://x').pathname;
      for (const rule of rules) if (rule.re.test(pathname)) Object.assign(headers, rule.headers);

      if (COMPRESSIBLE.test(type) && /\bgzip\b/.test(String(req.headers['accept-encoding'])) && body.length > 1024) {
        body = gzipSync(body);
        headers['content-encoding'] = 'gzip';
        headers['vary'] = 'Accept-Encoding';
      }
      headers['content-length'] = String(body.length);
      res.writeHead(status, headers).end(req.method === 'HEAD' ? undefined : body);
    } catch (e) {
      res.writeHead(500).end(String(e));
    }
  });

  await new Promise<void>((resolve) => server.listen(port, '127.0.0.1', resolve));
  const addr = server.address();
  const actual = typeof addr === 'object' && addr ? addr.port : port;
  return {
    url: `http://127.0.0.1:${actual}`,
    close: () => new Promise((resolve) => server.close(() => resolve())),
  };
}
