#!/bin/bash
# Daily Claude Code triage of this project's logs/errors.md, following cron/error_triage_prompt.md. Runs from cron
# as root. The same script is used by spacecareers, fccspacewatch and nasaprocurement; only the block below differs.
#
# When nothing is open in the error log it exits without starting Claude, so a quiet day costs nothing.
#
# One-time setup (shared by all three projects): create a long-lived login token and store it in TOKEN_FILE:
#   <claude> setup-token      (paste the token it prints into /root/.claude/triage_token)
#   chmod 600 /root/.claude/triage_token
#
# Output: logs/triage/YYYY-MM-DD.log (this script and Claude's final message) and .md (Claude's report)

# ---- Per-project settings ----------------------------------------------------------------------------------------
# Files holding credentials: Claude may not read them (scripts still load them for it)
SECRET_FILES=(config.ini bluesky.conf tootophp/tootophp.json)
# Scripts that post to Mastodon/Bluesky/Discord when run: Claude may not run them
POSTERS=(fccspacewatch.php itumonitor.php nasa_procurement_monitor.php ELSmonitor.php ibfmonitor.php faalaunchlicensewatch.php)
# Any other permission rules to deny, e.g. shell scripts that post
EXTRA_DENY=("Bash(./toot.sh:*)" "Bash(bash toot.sh:*)" "Bash(sh toot.sh:*)" "Bash($DIR/toot.sh:*)")
# ------------------------------------------------------------------------------------------------------------------

set -u
DIR=$(cd "$(dirname "$0")/.." && pwd)
TOKEN_FILE=/root/.claude/triage_token
BACKUPS=/root/precfg-backups
cd "$DIR" || exit 1
mkdir -p logs/triage "$BACKUPS"
LOG="logs/triage/$(date +%F).log"
exec 9>logs/triage/.lock
flock -n 9 || { echo "$(date) another triage run is active" >> "$LOG"; exit 0; }

# Nothing open: don't start Claude at all
OPEN=$(php errors.php 2>&1 | tail -1)
if [ "$OPEN" = "0 open." ]; then
	echo "$(date) no open errors, Claude not started" >> "$LOG"
	exit 0
fi

# Prefer an installed claude; otherwise the newest CLI the Claude desktop app keeps on this host
CLAUDE=$(command -v claude || ls -t /root/.local/bin/claude /root/.claude/remote/ccd-cli/* 2>/dev/null | head -1)
if [ -z "$CLAUDE" ] || [ ! -x "$CLAUDE" ]; then
	php -r 'include "errorlog.inc"; sc_log_error("Daily error triage could not find the claude CLI", ["key" => "triage-setup"]);'
	exit 1
fi
if [ ! -s "$TOKEN_FILE" ]; then
	php -r 'include "errorlog.inc"; sc_log_error("Daily error triage has no login token (/root/.claude/triage_token); see cron/error_triage.sh", ["key" => "triage-setup"]);'
	exit 1
fi

DENY=("Bash(git push:*)" "Bash(rm:*)" "Bash(mysql:*)" "Bash(crontab -e:*)" "Bash(crontab -r:*)" "Read(//root/.claude/triage_token)")
for f in "${SECRET_FILES[@]}"; do DENY+=("Read(/$DIR/$f)"); done
for p in "${POSTERS[@]}"; do DENY+=("Bash(php $p:*)" "Bash(php $DIR/$p:*)"); done
DENY+=("${EXTRA_DENY[@]}")

export CLAUDE_CODE_OAUTH_TOKEN="$(cat "$TOKEN_FILE")"
echo "$(date) starting: $OPEN ($CLAUDE)" >> "$LOG"
"$CLAUDE" -p "$(cat cron/error_triage_prompt.md)" \
	--model claude-opus-5-5 \
	--max-budget-usd 5 \
	--output-format text \
	--add-dir "$BACKUPS" \
	--allowedTools "Read" "Glob" "Grep" "WebFetch" "WebSearch" \
		"Edit(/$DIR/**)" "Edit(/$BACKUPS/**)" \
		"Bash(php:*)" "Bash(php7.4:*)" "Bash(curl:*)" "Bash(crontab -l)" "Bash(tail:*)" "Bash(head:*)" "Bash(grep:*)" \
		"Bash(ls:*)" "Bash(wc:*)" "Bash(diff:*)" "Bash(date:*)" "Bash(mkdir -p $BACKUPS/:*)" "Bash(cp -p:*)" \
		"Bash(git status:*)" "Bash(git diff:*)" "Bash(git log:*)" "Bash(git show:*)" "Bash(git add:*)" "Bash(git commit:*)" \
	--disallowedTools "${DENY[@]}" \
	>> "$LOG" 2>&1
STATUS=$?
echo "$(date) finished, exit $STATUS" >> "$LOG"
