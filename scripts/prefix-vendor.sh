#!/usr/bin/env bash
# Copies the runtime libraries (matthiasmullie/minify, phpseclib) into vendor-prefixed/ under this plugin's own
# namespace with Strauss, then fixes the string references Strauss cannot see. Run after `composer install`.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
rm -rf vendor-prefixed
COMPOSER_ALLOW_SUPERUSER=1 vendor/bin/strauss

# matthiasmullie/minify calls its parent constructor through a class name written as a string.
python3 - <<'PY'
import pathlib
for f in pathlib.Path('vendor-prefixed/matthiasmullie/minify/src').glob('*.php'):
    s = f.read_text()
    t = s.replace("'\\\\MatthiasMullie\\Minify\\\\Minify'", "'\\\\WP_Static_Export\\Vendor\\MatthiasMullie\\Minify\\\\Minify'")
    if t != s:
        f.write_text(t)
PY

# phpseclib builds some code as strings (inline crypt functions, engine class names): prefix those references too.
python3 - <<'PY'
import pathlib, re
fq = re.compile(r"(?<![A-Za-z0-9_\\])(\\{1,2})(phpseclib3|MatthiasMullie)(\\{1,2})")
for f in pathlib.Path('vendor-prefixed').rglob('*.php'):
    if f.parts[1] == 'composer':
        continue
    s = f.read_text(errors='ignore')
    t = fq.sub(lambda m: m.group(1) + "WP_Static_Export" + m.group(1) + "Vendor" + m.group(1) + m.group(2) + m.group(3), s)
    if t != s:
        f.write_text(t)
PY

# Nothing unprefixed may remain in code or strings (comments are ignored).
python3 - <<'PY'
import pathlib, re, sys
bad = []
pattern = re.compile(r"(\\|'|\")(phpseclib3|MatthiasMullie)\\|(namespace|use) (phpseclib3|MatthiasMullie)")
prefixed = re.compile(r"WP_Static_Export(\\\\|\\)Vendor(\\\\|\\)")
for f in pathlib.Path('vendor-prefixed').rglob('*.php'):
    if f.parts[1] == 'composer':
        continue
    for n, line in enumerate(f.read_text(errors='ignore').splitlines(), 1):
        stripped = line.strip()
        if stripped.startswith(('*', '//', '/*', '#')):
            continue
        if pattern.search(prefixed.sub('@@V@@', line)):
            bad.append(f"{f}:{n}: {stripped[:120]}")
if bad:
    print("Unprefixed references remain in vendor-prefixed:", *bad[:10], sep="\n", file=sys.stderr)
    sys.exit(1)
PY
echo "vendor-prefixed is ready."
