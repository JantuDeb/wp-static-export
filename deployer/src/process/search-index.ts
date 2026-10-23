import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parse } from 'node-html-parser';
import type { FileEntry, Logger } from '../types.js';
import { http, chunk } from '../util.js';
import { matchesAny } from './glob.js';

export interface SearchRecord {
  url: string;
  title: string;
  text: string;
  lang?: string;
}

export interface SearchOptions {
  engine: 'fuse' | 'algolia';
  exclude?: string[];
  /** Index location inside the export. */
  indexPath?: string;
  algolia?: { appId: string; adminKey: string; index: string };
}

const STRIP = 'script,style,nav,header,footer,aside,form,noscript,svg,iframe,template,[hidden],.ss-search-panel,.sidebar,.widget';
const MAX_TEXT = 5000;

export function filePathToUrl(p: string): string {
  if (p === 'index.html') return '/';
  if (p.endsWith('/index.html')) return '/' + p.slice(0, -'index.html'.length);
  return '/' + p;
}

export function extractRecord(html: string, filePath: string): SearchRecord | null {
  const root = parse(html, { blockTextElements: { script: false, style: false, noscript: false } });
  const robots = root.querySelector('meta[name="robots"]')?.getAttribute('content') ?? '';
  if (/noindex/i.test(robots)) return null;

  const title = (root.querySelector('title')?.text ?? root.querySelector('h1')?.text ?? '').replace(/\s+/g, ' ').trim();
  const main =
    root.querySelector('main') ??
    root.querySelector('article') ??
    root.querySelector('[role="main"]') ??
    root.querySelector('#content') ??
    root.querySelector('body') ??
    root;
  main.querySelectorAll(STRIP).forEach((n) => n.remove());
  // node-html-parser joins text nodes without separators, which glues "<h1>A</h1><p>B</p>" into "AB".
  const spaced = main.innerHTML.replace(/<\/?(p|div|h[1-6]|li|ul|ol|br|section|article|tr|td|th|table|blockquote|figure|figcaption|dd|dt)\b[^>]*>/gi, ' ');
  const text = parse(spaced).text.replace(/\s+/g, ' ').trim().slice(0, MAX_TEXT);
  if (!title && !text) return null;

  const lang = root.querySelector('html')?.getAttribute('lang') || undefined;
  return { url: filePathToUrl(filePath), title, text, ...(lang ? { lang } : {}) };
}

export async function buildSearchRecords(files: FileEntry[], exclude: string[] | undefined): Promise<SearchRecord[]> {
  const records: SearchRecord[] = [];
  for (const f of files) {
    if (!/\.html?$/i.test(f.path) || /(^|\/)404\.html$/.test(f.path) || matchesAny(f.path, exclude) || matchesAny(filePathToUrl(f.path), exclude)) continue;
    const rec = extractRecord(await readFile(f.abs, 'utf8'), f.path);
    if (rec) records.push(rec);
  }
  return records.sort((a, b) => a.url.localeCompare(b.url));
}

export async function writeSearchIndex(dir: string, records: SearchRecord[], indexPath = 'ss-assets/search-index.json'): Promise<string> {
  const target = path.join(dir, indexPath);
  await mkdir(path.dirname(target), { recursive: true });
  await writeFile(target, JSON.stringify(records));
  return indexPath;
}

export const objectId = (url: string) => createHash('sha1').update(url).digest('hex').slice(0, 20);

export async function pushToAlgolia(
  cfg: { appId: string; adminKey: string; index: string },
  records: SearchRecord[],
  replaceAll: boolean,
  log: Logger,
): Promise<void> {
  const base = `https://${cfg.appId}.algolia.net/1/indexes/${encodeURIComponent(cfg.index)}`;
  const headers = { 'X-Algolia-Application-Id': cfg.appId, 'X-Algolia-API-Key': cfg.adminKey, 'Content-Type': 'application/json' };

  await http(`${base}/settings`, {
    method: 'PUT',
    headers,
    body: JSON.stringify({ searchableAttributes: ['title', 'unordered(text)'], attributesToSnippet: ['text:30'], attributesToHighlight: ['title', 'text'] }),
  });
  if (replaceAll) await http(`${base}/clear`, { method: 'POST', headers, body: '{}' });

  let done = 0;
  for (const part of chunk(records, 500)) {
    // Algolia caps records at ~10KB on free plans; text is already limited to 5000 chars.
    await http(`${base}/batch`, {
      method: 'POST',
      headers,
      body: JSON.stringify({ requests: part.map((r) => ({ action: 'updateObject', body: { objectID: objectId(r.url), ...r } })) }),
    });
    done += part.length;
    log.progress(done, records.length, 'records');
  }
}
