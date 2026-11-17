#!/bin/sh
# Deletes files on the server that an EARLIER deploy uploaded and the current
# one no longer contains (files removed or renamed in the repository, Composer
# packages dropped from vendor/). Run by bin\deploy.bat on the server, inside
# the deploy directory, right after the upload was extracted:
#
#   sh bin/deploy-prune.sh NEW_LIST
#
# NEW_LIST is what `tar -xv` printed while extracting this upload (one path per
# line, "./" prefix and directory entries allowed). The cleaned list is kept as
# .deploy-manifest for the next deploy; only paths in the PREVIOUS manifest and
# missing from the new one are deleted. So anything a deploy never uploaded -
# storage/, .env, public/.user.ini, public/app/, the WSI icon cache, tools/.venv,
# __pycache__ - can never be deleted here, whatever bin/deploy-excludes.txt says;
# the case below protects those paths once more on top.
#
# First run (no .deploy-manifest yet): deletes nothing, only records the list.
# A new list with fewer than half the entries of the previous one is refused
# (nothing deleted, the old manifest kept) - a broken upload must not empty the
# server.

set -u

NEW_LIST="${1:?usage: deploy-prune.sh NEW_LIST}"
MANIFEST=.deploy-manifest
TMP_NEW="$MANIFEST.new.$$"
TMP_OLD="$MANIFEST.old.$$"
trap 'rm -f "$TMP_NEW" "$TMP_OLD"' EXIT

# Files only: drop directory entries (trailing /) and the "./" prefix; the
# locale files travel as resources/i18n-incoming and are merged by
# bin/merge-i18n.php, so they are no deploy files of their own.
sed -e 's#^\./##' -e '/\/$/d' -e '/^\.\{0,1\}$/d' -e '/^resources\/i18n-incoming\//d' "$NEW_LIST" \
    | LC_ALL=C sort -u > "$TMP_NEW"
rm -f "$NEW_LIST"

new_count=$(wc -l < "$TMP_NEW")
if [ ! -f "$MANIFEST" ]; then
    mv "$TMP_NEW" "$MANIFEST"
    echo "prune: first run, $new_count files recorded, nothing deleted"
    exit 0
fi

LC_ALL=C sort -u "$MANIFEST" > "$TMP_OLD"
old_count=$(wc -l < "$TMP_OLD")
if [ "$new_count" -lt $((old_count / 2)) ]; then
    echo "prune: REFUSED - upload lists $new_count files, previous deploy $old_count; nothing deleted"
    exit 0
fi

for_each_gone() {
    LC_ALL=C comm -23 "$TMP_OLD" "$TMP_NEW"
}
for_each_gone | while IFS= read -r f; do
    case "$f" in
        ''|/*|*..*|.env|.env.*|storage|storage/*|public/.user.ini|public/app/*|public/images/wsi/*|tools/.venv/*|resources/i18n/*|.deploy-manifest*)
            echo "prune: skipped protected $f"
            continue
            ;;
    esac
    if [ -f "$f" ] || [ -L "$f" ]; then
        rm -f -- "$f" && echo "prune: deleted $f"
        # remove directories that became empty, up to (not including) the deploy root
        d=$(dirname -- "$f")
        while [ "$d" != "." ] && rmdir -- "$d" 2>/dev/null; do
            echo "prune: deleted empty directory $d/"
            d=$(dirname -- "$d")
        done
    fi
done
gone=$(for_each_gone | wc -l)

mv "$TMP_NEW" "$MANIFEST"
echo "prune: $new_count files deployed, $gone no longer part of the deploy"
