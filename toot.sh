#!/bin/bash

# The access token is read from config.ini next to this script (not in git; see config.example.ini)
bot_cfg() { php -r '$c = parse_ini_file($argv[1], true, INI_SCANNER_RAW); echo $c[$argv[2]][$argv[3]] ?? "";' "$(dirname "$0")/config.ini" "$1" "$2"; }

# Mastodon instance details
INSTANCE_URL="https://social.deltayeet.net"     # Your Mastodon instance URL
ACCESS_TOKEN="$(bot_cfg mastodon access_token)"                # Your Mastodon access token

POST_TEXT="$1"  # The text of the toot
IMG_PATH="$2"  # Optional: Path to the image file

# Step 1: Upload the media (if an image is provided)
if [[ -n "$IMG_PATH" ]]; then
    echo "Uploading media: $IMG_PATH"
    MEDIA_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$INSTANCE_URL/api/v2/media" \
        -H "Authorization: Bearer $ACCESS_TOKEN" \
        -F "file=@$IMG_PATH")

    # Separate the JSON response and HTTP status code
    MEDIA_JSON=$(echo "$MEDIA_RESPONSE" | sed '$d')
    MEDIA_STATUS=$(echo "$MEDIA_RESPONSE" | tail -n1)

    if [[ "$MEDIA_STATUS" -ne 200 ]]; then
        echo "Media upload failed with status $MEDIA_STATUS"
        echo "Response: $MEDIA_JSON"
        exit 1
    fi

    # Extract media ID from the JSON response
    MEDIA_ID=$(echo "$MEDIA_JSON" | jq -r '.id')
    echo "Media uploaded successfully. Media ID: $MEDIA_ID"
fi

# Step 2: Post the status
echo "Posting status: $POST_TEXT"

# Create JSON data using jq to ensure proper escaping
if [[ -n "$MEDIA_ID" ]]; then
    POST_DATA=$(jq -n --arg status "$POST_TEXT" --arg media_id "$MEDIA_ID" \
        '{status: $status, media_ids: [$media_id]}')
else
    POST_DATA=$(jq -n --arg status "$POST_TEXT" '{status: $status}')
fi

# Send the toot
TOOT_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$INSTANCE_URL/api/v1/statuses" \
    -H "Authorization: Bearer $ACCESS_TOKEN" \
    -H "Content-Type: application/json" \
    -d "$POST_DATA")

# Separate the JSON response and HTTP status code
TOOT_JSON=$(echo "$TOOT_RESPONSE" | sed '$d')
TOOT_STATUS=$(echo "$TOOT_RESPONSE" | tail -n1)

# Check if the status post was successful
if [[ "$TOOT_STATUS" -ne 200 ]]; then
    echo "Status post failed with status $TOOT_STATUS"
    echo "Response: $TOOT_JSON"
    exit 1
else
    echo "Status posted successfully!"
    echo "Response: $TOOT_JSON"
fi
