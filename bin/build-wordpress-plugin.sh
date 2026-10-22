#!/bin/sh
set -eu

repository_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
source_directory="$repository_root/wordpress-plugin/jot-library"
output_directory="$repository_root/dist"
staging_directory=$(mktemp -d)
trap 'rm -rf "$staging_directory"' EXIT HUP INT TERM

mkdir -p "$output_directory" "$staging_directory/jot-library"
cp -R "$source_directory/assets" "$source_directory/blocks" "$source_directory/includes" "$staging_directory/jot-library/"
cp "$source_directory/jot-library.php" "$source_directory/readme.txt" "$source_directory/README.md" "$source_directory/MIGRATION.md" "$source_directory/uninstall.php" "$staging_directory/jot-library/"
find "$staging_directory" -type f -name '.DS_Store' -delete

cd "$staging_directory"
zip -qr "$output_directory/jot-library-0.1.2.zip" jot-library
printf 'Built %s\n' "$output_directory/jot-library-0.1.2.zip"
