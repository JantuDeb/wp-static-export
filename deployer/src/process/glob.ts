/** Match a path against simple patterns where `*` matches anything (including slashes). */
export function matchesAny(path: string, patterns: string[] | undefined): boolean {
  if (!patterns?.length) return false;
  return patterns.some((p) => {
    const trimmed = p.trim();
    if (!trimmed) return false;
    const re = new RegExp('^' + trimmed.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
    // Patterns written with a leading slash (URL style) should match our slash-less paths.
    return re.test(path) || re.test('/' + path);
  });
}
