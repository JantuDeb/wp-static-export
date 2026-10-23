import path from 'node:path';

/**
 * Map a URL found in a page to a path inside the export (no leading slash), or null for URLs
 * that can't be local files (data:, mailto:, ...). Absolute URLs keep only their path, which is
 * right when the site is deployed at the root of its host; for other layouts the file simply
 * won't be found and the caller skips the element.
 */
export function resolveSitePath(url: string, pagePath: string): string | null {
  if (/^(data:|blob:|mailto:|tel:|javascript:|#)/i.test(url)) return null;
  const pageDir = path.posix.dirname('/' + pagePath);
  try {
    const u = new URL(url, 'https://site.invalid' + (pageDir === '/' ? '/' : pageDir + '/'));
    return decodeURIComponent(u.pathname).replace(/^\//, '');
  } catch {
    return null;
  }
}
