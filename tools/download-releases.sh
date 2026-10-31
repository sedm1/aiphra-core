#!/usr/bin/env bash
set -euo pipefail

repository=${1:-}
output=${2:-}
if [[ ! "$repository" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ || ! "$output" ]]; then
    echo 'Usage: bash tools/download-releases.sh OWNER/REPOSITORY output-directory' >&2; exit 1
fi

tags=$(gh api --method GET --paginate "repos/$repository/releases" --jq '.[] | select(.draft == false and .prerelease == false) | .tag_name')
found=false
while IFS= read -r tag; do
    if [[ ! "$tag" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then continue; fi
    found=true
    version=${tag#v}
    gh release download "$tag" --repo "$repository" --pattern "*-$version.zip" --pattern SHA256SUMS \
        --dir "$output/$tag" --skip-existing
    (cd "$output/$tag" && sha256sum --check --strict SHA256SUMS)
done <<< "$tags"
if [[ "$found" != true ]]; then echo 'No stable published releases found.' >&2; exit 1; fi
