#!/usr/bin/env bash

set -euo pipefail

plugin_slug="wp-static-export"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_root="${1:-build/release}"

if [[ "$output_root" != /* ]]; then
	output_root="$repo_root/$output_root"
fi

case "$output_root" in
	"/"|"$repo_root"|"$repo_root/")
		echo "Refusing to use unsafe release output directory: $output_root" >&2
		exit 1
		;;
esac

package_dir="$output_root/$plugin_slug"
archive_path="$output_root/$plugin_slug.zip"
checksum_path="$archive_path.sha256"

mkdir -p "$output_root"
rm -rf -- "$package_dir"
rm -f -- "$archive_path" "$checksum_path"
mkdir -p "$package_dir"

rsync -a \
	--delete \
	--exclude-from="$repo_root/.distignore" \
	"$repo_root/" \
	"$package_dir/"

if find "$package_dir" -type d \( -name node_modules -o -name vendor -o -name tests \) -print -quit | grep -q .; then
	echo "Release package contains a dependency or test directory." >&2
	exit 1
fi

if find "$package_dir" -type f \( -name '*.test.js' -o -name '*.test.jsx' -o -name '*.map' \) -print -quit | grep -q .; then
	echo "Release package contains a test or source-map file." >&2
	exit 1
fi

development_paths=(
	".git"
	".github"
	".context"
	".distignore"
	".gitignore"
	"build"
	"composer.json"
	"composer.lock"
	"phpunit.xml.dist"
	"security.md"
	"tests"
	"vendor"
	"RELEASING.md"
	"scripts"
	"assets/install-plugins/package.json"
	"assets/install-plugins/package-lock.json"
	"assets/install-plugins/src"
	"src/admin/coverage"
	"src/admin/package.json"
	"src/admin/package-lock.json"
	"src/admin/src"
	"src/admin/webpack.config.js"
)

for relative_path in "${development_paths[@]}"; do
	if [[ -e "$package_dir/$relative_path" ]]; then
		echo "Release package contains development path: $relative_path" >&2
		exit 1
	fi
done

if find "$package_dir/assets/install-plugins" -mindepth 1 -maxdepth 1 ! -name build -print -quit | grep -q .; then
	echo "Plugin installer package contains files outside its build directory." >&2
	exit 1
fi

if find "$package_dir/src/admin" -mindepth 1 -maxdepth 1 ! -name build ! -name inc -print -quit | grep -q .; then
	echo "Admin package contains files outside its runtime inc and build directories." >&2
	exit 1
fi

test -s "$package_dir/wp-static-export.php"
test -s "$package_dir/readme.txt"
test -s "$package_dir/src/admin/build/index.js"
test -s "$package_dir/assets/install-plugins/build/index.js"

# The deploy and optimization engine is PHP; it needs its two libraries (prefixed under this plugin's
# namespace by `composer prefix-vendor`) and the browser scripts built from deployer/src.
for required in \
	"vendor-prefixed/autoload.php" \
	"vendor-prefixed/matthiasmullie/minify/src/CSS.php" \
	"vendor-prefixed/phpseclib/phpseclib/phpseclib/Net/SFTP.php" \
	"deployer/dist/admin/settings.js" \
	"deployer/dist/admin/report.js" \
	"deployer/dist/web/forms.js" \
	"deployer/dist/web/search.js" \
	"src/engine/autoload.php"; do
	if [[ ! -s "$package_dir/$required" ]]; then
		echo "Release package is missing $required. Run: composer install && composer prefix-vendor, and npm run build in deployer." >&2
		exit 1
	fi
done

# Nothing may load the unprefixed libraries: they could clash with another plugin shipping the same ones.
if grep -rqE '^namespace (MatthiasMullie|phpseclib3)' "$package_dir/vendor-prefixed" 2>/dev/null; then
	echo "vendor-prefixed contains an unprefixed library namespace." >&2
	exit 1
fi

# Node is no longer needed on the server: make sure no Node bundle or runtime dependency ships.
if [[ -e "$package_dir/deployer/dist/cli.cjs" ]] || find "$package_dir/deployer" -type d -name node_modules -print -quit | grep -q .; then
	echo "Release package contains a Node runtime bundle." >&2
	exit 1
fi

(
	cd "$output_root"
	zip -qr "$plugin_slug.zip" "$plugin_slug"
	shasum -a 256 "$plugin_slug.zip" > "$plugin_slug.zip.sha256"
)

echo "Built $archive_path"
echo "Checksum: $checksum_path"
