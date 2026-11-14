# FCC Space Watch

Bots that watch government filings for space activity and post what they find to Mastodon and
Bluesky, with alerts to Discord.

| Script | Schedule | What it watches |
|---|---|---|
| `fccspacewatch.php` | every 5 minutes | FCC Experimental Licensing System (ELS) grants for space-related applicants |
| `itumonitor.php` | hourly | ITU "as received" notifications of space stations; renders a filing card (`render_itu_filing.php`) |
| `nasa_procurement_monitor.php` | hourly | New pages on procurement.jsc.nasa.gov |

The other monitors (`ELSmonitor.php`, `ibfmonitor.php`, `faalaunchlicensewatch.php`) are currently
switched off in cron. `cron/crontab.txt` is a snapshot of the live schedule.

## Layout

| Path | What it is |
|---|---|
| `common.inc` | DB connection, `bot_cfg()` config reader, posting helpers (Mastodon via `toot.sh`, Bluesky, Discord) |
| `render_*.php`, `render_*.sh` | Image rendering for posts |
| `toot.sh` | Posts a status (and optional image) to Mastodon |
| `tootophp/` | Small Mastodon client library |
| `schema.sql` | Database structure (no data) |

## Setup

1. Create the database from `schema.sql`.
2. `cp config.example.ini config.ini`, fill it in, then `chown root:www-data config.ini && chmod 640 config.ini`.
3. For Bluesky posting: `cp bluesky.example.conf bluesky.conf`, fill it in, `chmod 600 bluesky.conf`.
4. For the tootophp library: `cp tootophp/tootophp.example.json tootophp/tootophp.json` and fill it in.
5. `php composer.phar install` (or `composer install`).
6. Fonts are not in the repo (third-party licenses). Put these in `fonts/`: `arial.ttf`, `times.ttf`,
   `timesbd.ttf`, `coolvetica_rg.ttf`, `coolvetica_compressed_rg.ttf`, `coolvetica_condensed_rg.ttf`,
   `coolvetica_crammed_rg.ttf`, `Product_Sans_Regular.ttf`, `Product_Sans_Bold.ttf`.
7. Install the cron lines from `cron/crontab.txt`.

`config.ini`, `bluesky.conf`, `tootophp/tootophp.json`, logs, renders and backups are git-ignored;
never commit real credentials.

## Error log and daily triage

Routine problems (a source failing several runs in a row, a post given up on, a notice that couldn't be saved)
are recorded in `logs/errors.md` instead of being sent to Discord. Repeats are grouped into one entry with a
count, and an entry is flagged *recovered* once the problem clears. Only critical, time-sensitive problems still
go to Discord, at most once every 3 hours each: the database being unreachable, or Mastodon/Bluesky (or the
SAM.gov API, for nasaprocurement) rejecting the bot's credentials. Intentional notices are unchanged.

`logs/errors.json` holds the data and `errors.md` is regenerated from it:

```
php errors.php                          # list open entries
php errors.php show <id>
php errors.php resolve <id> "what was wrong and what was done"
php errors.php reopen <id>
```

`cron/error_triage.sh` runs daily from cron. When nothing is open it exits without starting Claude. Otherwise it
runs Claude Code headless with `cron/error_triage_prompt.md`. Claude is limited to this folder and may not read
credentials, run the posting scripts or push. It commits fixes locally, writes `logs/triage/YYYY-MM-DD.md`, and
sends one Discord note only when something needs you. It uses the login token in `/root/.claude/triage_token`
(see the script's header). `errorlog.inc`, `errors.php` and `cron/error_triage.sh` are shared with the other bots
(spacecareers, fccspacewatch, nasaprocurement); keep the copies in sync.
