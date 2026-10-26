#!/bin/sh
# tar-entry-sha256.sh — one line about one archive member, for
# backup_tree_lines_from_tar in backup-lib.sh.
#
# GNU tar runs this once per member when called with --to-command, with
# the member's content on stdin and its name, type and size in TAR_*
# variables. The line printed must match backup_tree_lines_from_dir
# exactly: "<sha256> <size> <path>".
#
# Only regular files are described, like `find -type f` on the other side.
# stdin is drained for anything else so tar never sees a broken pipe.
if [ "${TAR_FILETYPE:-}" != "f" ]; then
    cat > /dev/null
    exit 0
fi
printf '%s %s %s\n' "$(sha256sum | cut -d' ' -f1)" "$TAR_SIZE" "$TAR_FILENAME"
