#!/bin/sh
set -eu

repository_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
source_directory="$repository_root/wordpress-plugin/jot-library"
output_directory="$repository_root/dist"
staging_directory=$(mktemp -d)
trap 'rm -rf "$staging_directory"' EXIT HUP INT TERM
version=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$source_directory/jot-library.php" | head -n 1)

if [ -z "$version" ]; then
	printf 'Could not read the plugin version.\n' >&2
	exit 1
fi

mkdir -p "$output_directory" "$staging_directory/jot-library"
cp -R "$source_directory/assets" "$source_directory/blocks" "$source_directory/includes" "$staging_directory/jot-library/"
cp "$source_directory/jot-library.php" "$source_directory/readme.txt" "$source_directory/README.md" "$source_directory/MIGRATION.md" "$source_directory/uninstall.php" "$staging_directory/jot-library/"
cp "$repository_root/LICENSE" "$staging_directory/jot-library/LICENSE"
find "$staging_directory" -type f -name '.DS_Store' -delete

cd "$staging_directory"
zip -qr "$output_directory/jot-library-$version.zip" jot-library
printf 'Built %s\n' "$output_directory/jot-library-$version.zip"
