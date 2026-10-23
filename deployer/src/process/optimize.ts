import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import CleanCSS from 'clean-css';
import { minify as minifyHtml } from 'html-minifier-terser';
import { minify as minifyJs } from 'terser';
import type { FileEntry, Logger } from '../types.js';
import { pool } from '../util.js';
import { matchesAny } from './glob.js';
import { resolveSitePath } from './paths.js';

export interface OptimizeOptions {
  html?: boolean;
  css?: boolean;
  js?: boolean;
  images?: boolean;
  webp?: boolean;
  quality?: number;
  exclude?: string[];
}

export interface OptimizeResult {
  files: number;
  bytesSaved: number;
  webpCreated: string[];
}

const ext = (p: string) => path.extname(p).slice(1).toLowerCase();

/**
 * Minifiers are not idempotent: minifying their own output changes it again (and then the file is
 * re-uploaded on every deploy). The state file that normally prevents this lives in the temp folder,
 * which other parts of the plugin clear, so also recognize minified output by its shape: few, very long lines.
 */
export function looksMinified(text: string): boolean {
  if (text.length < 400) return false;
  const lines = text.split('\n').length;
  return text.length / lines > 250;
}

/** Written into every image we encode (EXIF Software), so re-encoding an already optimized image is skipped. */
export const OPTIMIZED_MARKER = 'wp-static-export';

async function isOptimized(sharp: any, input: Buffer): Promise<boolean> {
  try {
    const md = await sharp(input).metadata();
    return Boolean(md.exif && md.exif.toString('latin1').includes(OPTIMIZED_MARKER));
  } catch {
    return false;
  }
}

/** Files shipped by the exporter itself are already minified and must stay byte-identical between runs. */
const isOwnAsset = (p: string) => p.startsWith('ss-assets/');

export async function minifyHtmlString(html: string): Promise<string> {
  return minifyHtml(html, {
    collapseWhitespace: true,
    conservativeCollapse: true, // keep one space so inline elements don't glue together
    removeComments: true,
    minifyCSS: true,
    minifyJS: true,
    continueOnParseError: true,
    decodeEntities: false,
    // Leave template-ish and JSON blocks alone.
    ignoreCustomFragments: [/<%[\s\S]*?%>/, /<\?[\s\S]*?\?>/],
  });
}

export function minifyCssString(css: string): string {
  const out = new CleanCSS({ level: 1, returnPromise: false }).minify(css);
  if (out.errors.length) throw new Error(out.errors[0]);
  return out.styles;
}

export async function minifyJsString(js: string): Promise<string> {
  const out = await minifyJs(js, { compress: true, mangle: true, format: { comments: /^!|@license|@preserve/i } });
  if (!out.code) throw new Error('empty terser output');
  return out.code;
}

async function loadSharp(log: Logger): Promise<any | null> {
  try {
    // Optional dependency, marked external in the bundle so the plugin still works without it.
    return (await import('sharp')).default;
  } catch {
    log.warn('Image optimization skipped: install the optional "sharp" module (cd deployer && npm install sharp).');
    return null;
  }
}

/**
 * Optimize files in place. `state` maps path -> sha256 of the already-optimized file so that
 * repeated runs (e.g. update exports) don't re-encode images again and lose quality.
 */
export async function optimize(dir: string, files: FileEntry[], opts: OptimizeOptions, state: Record<string, string>, log: Logger): Promise<OptimizeResult> {
  const result: OptimizeResult = { files: 0, bytesSaved: 0, webpCreated: [] };
  const sharp = opts.images ? await loadSharp(log) : null;
  const quality = Math.max(1, Math.min(100, opts.quality ?? 80));

  const candidates = files.filter((f) => !isOwnAsset(f.path) && !matchesAny(f.path, opts.exclude) && state[f.path] !== f.sha256);

  await pool(candidates, 4, async (f) => {
    const e = ext(f.path);
    const abs = f.abs;
    const isMin = /\.min\.(js|css)$/.test(f.path);
    let before = f.size;
    let after: number | null = null;

    try {
      if ((e === 'html' || e === 'htm') && opts.html) {
        const src = await readFile(abs, 'utf8');
        const out = looksMinified(src) ? src : await minifyHtmlString(src);
        if (out.length < src.length) {
          await writeFile(abs, out);
          after = Buffer.byteLength(out);
        }
      } else if (e === 'css' && opts.css && !isMin) {
        const src = await readFile(abs, 'utf8');
        const out = looksMinified(src) ? src : minifyCssString(src);
        if (out && out.length < src.length) {
          await writeFile(abs, out);
          after = Buffer.byteLength(out);
        }
      } else if (e === 'js' && opts.js && !isMin) {
        const src = await readFile(abs, 'utf8');
        const out = looksMinified(src) ? src : await minifyJsString(src);
        if (out.length < src.length) {
          await writeFile(abs, out);
          after = Buffer.byteLength(out);
        }
      } else if (sharp && ['jpg', 'jpeg', 'png'].includes(e)) {
        const input = await readFile(abs);
        // JPEG is lossy: every re-encode loses quality, so an image we already optimized is left alone.
        if (await isOptimized(sharp, input)) return;
        const mark = { IFD0: { Software: OPTIMIZED_MARKER } };
        const img = sharp(input).withExif(mark);
        const encoded: Buffer = await (e === 'png' ? img.png({ compressionLevel: 9, effort: 8 }) : img.jpeg({ quality, mozjpeg: true })).toBuffer();
        if (encoded.length < input.length) {
          await writeFile(abs, encoded);
          after = encoded.length;
        }
        if (opts.webp) {
          const webp: Buffer = await sharp(input).withExif(mark).webp({ quality }).toBuffer();
          // A WebP that isn't smaller than the (optimized) original isn't worth serving.
          if (webp.length < (after ?? before)) {
            await writeFile(abs + '.webp', webp);
            result.webpCreated.push(f.path + '.webp');
          }
        }
      }
    } catch (err) {
      log.warn(`Could not optimize ${f.path}: ${(err as Error).message}`);
    }

    if (after !== null) {
      result.files++;
      result.bytesSaved += before - after;
    }
  });

  return result;
}

/** Wrap <img> in <picture> with a WebP <source> when every referenced file has a WebP twin. */
export function addPictureSources(html: string, pagePath: string, webp: Set<string>): string {
  const toSitePath = (u: string) => resolveSitePath(u, pagePath);
  const attr = (tag: string, name: string) =>
    new RegExp(`\\s${name}\\s*=\\s*("([^"]*)"|'([^']*)')`, 'i')
      .exec(tag)
      ?.slice(2, 4)
      .find((x) => x !== undefined);

  let lastIndex = 0;
  let out = '';
  const re = /<img\b[^>]*>/gi;
  let m: RegExpExecArray | null;
  while ((m = re.exec(html))) {
    const tag = m[0];
    const before = html.slice(0, m.index);
    const insidePicture = before.lastIndexOf('<picture') > before.lastIndexOf('</picture>');
    const src = attr(tag, 'src');
    const srcset = attr(tag, 'srcset');
    if (insidePicture || !src) continue;

    const entries: { url: string; desc: string }[] = srcset
      ? srcset
          .split(',')
          .map((s) => s.trim())
          .filter(Boolean)
          .map((s) => {
            const [url, ...rest] = s.split(/\s+/);
            return { url: url as string, desc: rest.join(' ') };
          })
      : [{ url: src, desc: '' }];

    const converted = entries.map((e) => {
      const p = toSitePath(e.url);
      return p && webp.has(p + '.webp') ? { ...e, url: e.url + '.webp' } : null;
    });
    if (converted.some((c) => c === null)) continue;

    const sizes = attr(tag, 'sizes');
    const source = `<source type="image/webp" srcset="${(converted as { url: string; desc: string }[]).map((c) => (c.desc ? `${c.url} ${c.desc}` : c.url)).join(', ')}"${sizes ? ` sizes="${sizes}"` : ''}>`;
    out += html.slice(lastIndex, m.index) + `<picture>${source}${tag}</picture>`;
    lastIndex = m.index + tag.length;
  }
  return lastIndex ? out + html.slice(lastIndex) : html;
}
