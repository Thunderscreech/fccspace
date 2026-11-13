#!/bin/bash
# download_ibfs_inspect.sh — Download IBFS.zip and inspect file structures

WORKDIR="/tmp/ibfs_inspect"
ZIPURL="ftp://ftp.fcc.gov/pub/Bureaus/International/databases/IBFS.zip"
ZIPFILE="$WORKDIR/IBFS.zip"
EXTRACTDIR="$WORKDIR/extracted"

# Clean up any previous run
rm -rf "$WORKDIR"
mkdir -p "$WORKDIR"

echo "=== Downloading IBFS.zip ==="
curl -sS -o "$ZIPFILE" "$ZIPURL"

if [ $? -ne 0 ] || [ ! -s "$ZIPFILE" ]; then
    echo "ERROR: Failed to download IBFS.zip"
    exit 1
fi

echo "Downloaded $(du -h "$ZIPFILE" | cut -f1)"
echo ""

echo "=== Extracting ==="
mkdir -p "$EXTRACTDIR"
unzip -q -o "$ZIPFILE" -d "$EXTRACTDIR"

FILECOUNT=$(find "$EXTRACTDIR" -type f | wc -l)
echo "Extracted $FILECOUNT file(s)"
echo ""

echo "=== File listing ==="
ls -lh "$EXTRACTDIR"
echo ""

echo "=== First 10 lines of each file ==="
echo ""

find "$EXTRACTDIR" -type f | sort | while read -r FILE; do
    FNAME=$(basename "$FILE")
    LINES=$(wc -l < "$FILE")
    SIZE=$(du -h "$FILE" | cut -f1)

    echo "──────────────────────────────────────────────────────────"
    echo "FILE: $FNAME  ($LINES lines, $SIZE)"
    echo "──────────────────────────────────────────────────────────"
    echo ""
    head -10 "$FILE"
    echo ""
    echo ""
done

echo "=== Done ==="
echo ""
echo "Extracted files are in: $EXTRACTDIR"
echo "Clean up with: rm -rf $WORKDIR"
