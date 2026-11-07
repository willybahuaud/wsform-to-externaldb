#!/usr/bin/env bash
#
# Construit l'archive de distribution : dist/wsform-to-externaldb-X.Y.Z.zip,
# avec un dossier racine wsform-to-externaldb/ et le contenu filtre par .distignore.
#
# Usage : bin/build-release.sh X.Y.Z
#
# Refuse de construire si X.Y.Z ne correspond pas a l'en-tete Version, a la
# constante WS_FORM_DB_VERSION et au Stable tag de readme.txt : une archive
# annoncee en 1.2.0 qui contiendrait du 1.1.0 serait reproposee en boucle.

set -euo pipefail

slug="wsform-to-externaldb"
version="${1:-}"
root="$(cd "$(dirname "$0")/.." && pwd)"

fail() {
	echo "Erreur : $1" >&2
	exit 1
}

[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "version attendue au format X.Y.Z, recu « $version »."

cd "$root"

header_version="$(sed -n 's/^ \* Version: *//p' "$slug.php" | tr -d '[:space:]')"
constant_version="$(sed -n "s/.*define( 'WS_FORM_DB_VERSION', '\([^']*\)' );.*/\1/p" "$slug.php")"
stable_tag="$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '[:space:]')"

[[ "$header_version" == "$version" ]] || fail "l'en-tete Version vaut $header_version, pas $version."
[[ "$constant_version" == "$version" ]] || fail "WS_FORM_DB_VERSION vaut $constant_version, pas $version."
[[ "$stable_tag" == "$version" ]] || fail "le Stable tag de readme.txt vaut $stable_tag, pas $version."

npm ci --no-audit --no-fund
npm run build

build_dir="$root/dist/build"
rm -rf "$root/dist"
mkdir -p "$build_dir/$slug"
rsync -a --exclude-from=.distignore ./ "$build_dir/$slug/"

(cd "$build_dir" && zip -qr "$root/dist/$slug-$version.zip" "$slug")
rm -rf "$build_dir"

echo "Archive : dist/$slug-$version.zip"
