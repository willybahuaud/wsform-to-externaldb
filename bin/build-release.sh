#!/usr/bin/env bash
#
# Construit l'archive de distribution : dist/wsform-to-externaldb-X.Y.Z.zip,
# avec un dossier racine wsform-to-externaldb/ et le contenu filtre par .distignore,
# et son manifeste de compatibilite dist/wsform-to-externaldb-X.Y.Z.json.
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
requires="$(sed -n 's/^ \* Requires at least: *//p' "$slug.php" | tr -d '[:space:]')"
requires_php="$(sed -n 's/^ \* Requires PHP: *//p' "$slug.php" | tr -d '[:space:]')"
tested="$(sed -n 's/^Tested up to: *//p' readme.txt | tr -d '[:space:]')"

[[ "$header_version" == "$version" ]] || fail "l'en-tete Version vaut $header_version, pas $version."
[[ "$constant_version" == "$version" ]] || fail "WS_FORM_DB_VERSION vaut $constant_version, pas $version."
[[ "$stable_tag" == "$version" ]] || fail "le Stable tag de readme.txt vaut $stable_tag, pas $version."

for value in "$requires" "$requires_php" "$tested"; do
	[[ "$value" =~ ^[0-9]+(\.[0-9]+)*$ ]] || fail "Requires at least, Requires PHP et Tested up to doivent etre des versions, recu « $value »."
done

# Aucun script d'installation des dépendances ne s'exécute.
npm ci --no-audit --no-fund --ignore-scripts
npm run build

build_dir="$root/dist/build"
rm -rf "$root/dist"
mkdir -p "$build_dir/$slug"

# Fichiers suivis par git et build seulement : un .env ou un fichier local
# présent dans le dossier de travail ne peut pas partir dans l'archive.
stage_dir="$build_dir/stage"
{ git ls-files; find admin/js admin/css -type f; } | rsync -a --files-from=- ./ "$stage_dir/"
rsync -a --exclude-from=.distignore "$stage_dir/" "$build_dir/$slug/"

(cd "$build_dir" && zip -qr "$root/dist/$slug-$version.zip" "$slug")
rm -rf "$build_dir"

# Lu par l'updater des sites : les en-tetes du plugin installe decrivent l'ancienne version.
printf '{"requires":"%s","requires_php":"%s","tested":"%s"}\n' "$requires" "$requires_php" "$tested" > "$root/dist/$slug-$version.json"

echo "Archive : dist/$slug-$version.zip"
echo "Manifeste : dist/$slug-$version.json"
