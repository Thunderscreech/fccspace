<?php
// Error reporting for this project's scripts (shared by spacecareers, fccspacewatch and nasaprocurement; keep the copies in sync).
//
// Routine problems (a feed is down, a scrape came back incomplete, a post was rejected) are recorded in
// logs/errors.md for a scheduled Claude job to review and fix. Nothing is sent to Discord for these.
// Only critical, time-sensitive infrastructure problems (database down or refusing writes, posting credentials
// rejected) also go to Discord, at most once per problem every SC_ALERT_EVERY seconds.
//
//   sc_log_error($msg, $opts)       routine problem -> errors.md
//   sc_alert_critical($msg, $opts)  critical problem -> errors.md + Discord (throttled)
//   sc_db_error($conn, $msg, $tail) DB query failure: critical if the server itself is in trouble, else routine
//   sc_errors_recovered($script)    called after a successful scrape: flags that script's open entries as recovered
//   sc_error_cleared($key)          the problem logged with that 'key' is gone: flags that one entry as recovered
//
// $opts: 'key' => fingerprint override (use for problems shared by every script, e.g. 'db-connect'),
//        'company' => company key, 'detail' => extra context, 'script' => script name override,
//        'source' => who noticed it (default: the running script)
//
// logs/errors.json is the source of truth; logs/errors.md is regenerated from it on every change.
// Use `php errors.php` to list, resolve or reopen entries (don't hand-edit errors.md: it is overwritten).

// Name shown in errors.md and alerts: the README's title, else the folder name
defined('SC_ERR_PROJECT') or define('SC_ERR_PROJECT', preg_match('/^#\s*(.+)/', (string)@file_get_contents(__DIR__ . '/README.md', false, null, 0, 200), $__m) ? trim($__m[1]) : basename(__DIR__));
defined('SC_ERR_DIR') or define('SC_ERR_DIR', __DIR__ . '/logs');	// overridable for tests
define('SC_ERR_JSON', SC_ERR_DIR . '/errors.json');
define('SC_ERR_MD', SC_ERR_DIR . '/errors.md');
define('SC_ERR_LOCK', SC_ERR_DIR . '/errors.lock');
define('SC_ALERT_EVERY', 3 * 3600);		// re-send a persisting critical alert at most every 3 hours
define('SC_RESOLVED_KEEP', 30 * 86400);		// drop resolved entries after 30 days
define('SC_SAMPLES_KEEP', 5);			// distinct recent messages kept per entry

// Same clock as the scrapers' logs (jobs.inc does this too; errors.php doesn't load jobs.inc)
if (date_default_timezone_get() == 'UTC' && in_array($__sc_tz = trim((string)@file_get_contents('/etc/timezone')), timezone_identifiers_list())) {
	date_default_timezone_set($__sc_tz);
}

// MySQL errors that mean the server or the account is in trouble, not that one query was bad
$GLOBALS['sc_db_critical_errnos'] = array(
	1021, 1114, 1030,		// disk full, table full, storage engine error
	1036, 1290,			// read-only table / server
	145, 1194, 1195,		// crashed tables
	1040, 1203,			// too many connections
	1044, 1045, 1142, 1146,		// access denied, command denied, table missing
	2002, 2003, 2006, 2013,		// can't connect, server gone away, lost connection
);

// PHP 8.1+ (the CLI here) throws mysqli_sql_exception instead of returning false, so a failed query ends the
// script before any mysqli_error() check runs. Record uncaught exceptions here, then die as PHP would have.
if (PHP_SAPI == 'cli') {
	set_exception_handler(function ($ex) {
		$msg	= get_class($ex) . ': ' . $ex->getMessage() . ' in ' . basename($ex->getFile()) . ':' . $ex->getLine();
		$code	= (int)$ex->getCode();
		if ($ex instanceof mysqli_sql_exception && in_array($code, $GLOBALS['sc_db_critical_errnos'])) {
			sc_alert_critical("Uncaught $msg (MySQL error $code)", array('key' => "db-errno-$code", 'detail' => 'First seen in ' . sc_err_script()));
		} else {
			sc_log_error("Uncaught $msg");
		}
		fwrite(STDERR, "PHP Fatal error:  Uncaught $ex\n");	// same text the monitor looks for
		exit(255);
	});
}

function sc_err_script()
{
	$f = (string)($_SERVER['SCRIPT_FILENAME'] ?? '') ?: (string)($GLOBALS['argv'][0] ?? '');
	return ($f === '' || $f === 'Standard input code') ? 'php -r' : basename($f);
}

// Same problem, different numbers (offsets, counts, job IDs, ratios) -> same entry
function sc_err_fingerprint($script, $msg)
{
	$norm = preg_replace('/\d+(\.\d+)?/', 'N', strtolower($msg));
	return substr(sha1($script . '|' . $norm), 0, 8);
}

// Log file the crontab redirects this script to (names don't always match the script)
function sc_err_logfile($script)
{
	static $cron = null;
	if ($cron === null) {
		$cron = (string)@shell_exec('crontab -l 2>/dev/null');
	}
	if (preg_match('#^[^\#\n]*/' . preg_quote($script, '#') . '\s*>\s*(\S+)#m', $cron, $m)) {
		return str_replace(__DIR__ . '/', '', $m[1]);
	}
	$guess = 'logs/' . preg_replace('/\.php$/', '.log', $script);
	return is_file(__DIR__ . "/$guess") ? $guess : '';
}

// Read-modify-write errors.json under a lock, then re-render errors.md. Returns false if the files can't be written.
function sc_err_update($fn)
{
	if (!is_dir(SC_ERR_DIR) || !is_writable(SC_ERR_DIR)) {
		return false;	// e.g. the web site (www-data) including common.inc
	}
	$lock = @fopen(SC_ERR_LOCK, 'c');
	if (!$lock || !flock($lock, LOCK_EX)) {
		return false;
	}
	$data = is_file(SC_ERR_JSON) ? json_decode((string)file_get_contents(SC_ERR_JSON), true) : null;
	if (!is_array($data)) {
		$data = array('entries' => array());
	}
	$result = $fn($data);
	$now	= time();
	foreach ($data['entries'] as $id => $e) {
		if ($e['status'] == 'resolved' && $now - strtotime($e['resolved_at']) > SC_RESOLVED_KEEP) {
			unset($data['entries'][$id]);
		}
	}
	$ok = (file_put_contents(SC_ERR_JSON . '.tmp', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) !== false)
		&& rename(SC_ERR_JSON . '.tmp', SC_ERR_JSON)
		&& (file_put_contents(SC_ERR_MD . '.tmp', sc_err_render($data)) !== false)
		&& rename(SC_ERR_MD . '.tmp', SC_ERR_MD);
	flock($lock, LOCK_UN);
	fclose($lock);
	return $ok ? ($result ?? true) : false;
}

function sc_err_record($severity, $msg, $opts = array())
{
	$script	= $opts['script'] ?? sc_err_script();
	$id	= sc_err_fingerprint($opts['key'] ?? $script, $opts['key'] ?? $msg);
	$detail	= $opts['detail'] ?? '';
	if ($detail === '' && !empty($GLOBALS['sc_last_fetch_error'])) {
		$detail = 'Last fetch error: ' . $GLOBALS['sc_last_fetch_error'];
	}
	echo strtoupper($severity) . " logged [$id]: $msg\n";
	return sc_err_update(function (&$data) use ($id, $severity, $msg, $opts, $script, $detail) {
		$now	= date('Y-m-d H:i:s');
		$e	= $data['entries'][$id] ?? null;
		if ($e === null) {
			$e = array('id' => $id, 'first_seen' => $now, 'count' => 0, 'samples' => array(),
				'script' => $script, 'log' => sc_err_logfile($script), 'source' => $opts['source'] ?? $script);
		} elseif ($e['status'] == 'resolved') {
			$e['reopened']	= $now;		// came back after being marked resolved
			$e['count']	= 0;
			$e['first_seen']= $now;
		}
		$e['status']	= 'open';
		$e['severity']	= ($severity == 'critical' || ($e['severity'] ?? '') == 'critical') ? 'critical' : 'error';
		$e['title']	= $msg;
		$e['company']	= $opts['company'] ?? ($e['company'] ?? '');
		$e['last_seen']	= $now;
		$e['count']++;
		unset($e['recovered_at'], $e['resolved_at'], $e['resolution']);
		if ($detail !== '') {
			$e['detail'] = $detail;
		}
		$e['samples'] = array_values(array_unique(array_merge(array($msg), $e['samples'])));
		$e['samples'] = array_slice($e['samples'], 0, SC_SAMPLES_KEEP);
		$data['entries'][$id] = $e;
		return $id;
	});
}

function sc_log_error($msg, $opts = array())
{
	return sc_err_record('error', $msg, $opts);
}

function sc_alert_critical($msg, $opts = array())
{
	$id	= sc_err_record('critical', $msg, $opts);
	$fp	= sc_err_fingerprint($opts['key'] ?? ($opts['script'] ?? sc_err_script()), $opts['key'] ?? $msg);
	// Throttle marker lives in tmpfs so it still works when the disk is full; send only if we could set it,
	// otherwise every script (or every web hit) would page.
	$marker	= "/dev/shm/" . basename(__DIR__) . "-alert-$fp";
	if (is_file($marker) && time() - filemtime($marker) < SC_ALERT_EVERY) {
		return $id;
	}
	if (PHP_SAPI != 'cli' || !@touch($marker)) {
		return $id;	// the web site never pages; cron runs do
	}
	$where	= $opts['key'] ?? sc_err_script();
	if (!function_exists('dy_discord')) {
		return $id;
	}
	dy_discord(SC_ERR_PROJECT . " CRITICAL ($where): $msg" . (!empty($opts['detail']) ? "\n" . $opts['detail'] : '')
		. "\n(Further repeats suppressed for " . (SC_ALERT_EVERY / 3600) . "h; details in logs/errors.md)");
	return $id;
}

// Replaces dy_discord("... failure ...:" . mysqli_error($conn))
function sc_db_error($conn, $msg, $tail = '')
{
	$errno	= $conn ? mysqli_errno($conn) : 0;
	$full	= $msg . ($conn ? mysqli_error($conn) : '') . $tail;
	if (in_array($errno, $GLOBALS['sc_db_critical_errnos'])) {
		return sc_alert_critical("$full (MySQL error $errno)", array('key' => "db-errno-$errno", 'detail' => 'First seen in ' . sc_err_script()));
	}
	return sc_log_error($full . ($errno ? " (MySQL error $errno)" : ''));
}

// A run of $script finished cleanly: mark its open entries recovered (the triage job can then close transient ones)
function sc_errors_recovered($script = null, $id = null)
{
	$script = $script ?? sc_err_script();
	if (!is_file(SC_ERR_JSON)) {
		return;
	}
	sc_err_update(function (&$data) use ($script, $id) {
		foreach ($data['entries'] as $k => $e) {
			if ($e['status'] == 'open' && empty($e['recovered_at']) && ($id !== null ? $k == $id : $e['script'] == $script)) {
				$data['entries'][$k]['recovered_at'] = date('Y-m-d H:i:s');
			}
		}
	});
}

// The problem logged with array('key' => $key) is gone (e.g. a feed that failed N runs in a row answered again)
function sc_error_cleared($key)
{
	sc_errors_recovered(null, sc_err_fingerprint($key, $key));
}

function sc_err_render($data)
{
	$open = $resolved = array();
	foreach ($data['entries'] as $e) {
		if ($e['status'] == 'open') {
			$open[] = $e;
		} else {
			$resolved[] = $e;
		}
	}
	// Critical first, then still-failing before recovered, then most recent
	usort($open, function ($a, $b) {
		return array(($a['severity'] != 'critical'), !empty($a['recovered_at']), -strtotime($a['last_seen']))
			<=> array(($b['severity'] != 'critical'), !empty($b['recovered_at']), -strtotime($b['last_seen']));
	});
	usort($resolved, function ($a, $b) { return strcmp($b['resolved_at'], $a['resolved_at']); });

	$md  = "# " . SC_ERR_PROJECT . " error log\n\n";
	$md .= "Generated " . date('Y-m-d H:i:s T') . " from `logs/errors.json`. Don't edit this file: it is rewritten on every new error.\n\n";
	$md .= "## How to process this file\n\n";
	$md .= "For each open entry: read its log (overwritten each run, so it shows the latest run) and the script, decide the cause, fix it if the fix is in this project, then close it with `php errors.php resolve <id> \"what was wrong and what was done\"`.\n\n";
	$md .= "- *Recovered* means a later run of the same script finished cleanly. If nothing else is wrong, resolve it as transient.\n";
	$md .= "- An entry that is resolved and happens again is reopened automatically with its count reset.\n";
	$md .= "- Critical entries were also sent to Discord. Routine ones were not.\n\n";
	$md .= "## Open (" . count($open) . ")\n\n";
	if (empty($open)) {
		$md .= "No open errors.\n\n";
	}
	foreach ($open as $e) {
		$state	= !empty($e['recovered_at']) ? "recovered {$e['recovered_at']}" : 'still failing';
		$md .= "### [{$e['id']}] " . ($e['severity'] == 'critical' ? 'CRITICAL: ' : '') . sc_err_md($e['title']) . "\n\n";
		$md .= "- Script: `{$e['script']}`" . ($e['log'] ? " | Log: `{$e['log']}`" : '') . ($e['company'] ? " | Company: `{$e['company']}`" : '') . "\n";
		$md .= "- Seen {$e['count']}x, first {$e['first_seen']}, last {$e['last_seen']} | Status: $state"
			. (!empty($e['reopened']) ? " | Reopened {$e['reopened']}" : '') . "\n";
		if (!empty($e['detail'])) {
			$md .= "- Detail: " . sc_err_md($e['detail']) . "\n";
		}
		if (count($e['samples']) > 1) {
			$md .= "- Recent variants:\n";
			foreach ($e['samples'] as $s) {
				$md .= "  - " . sc_err_md($s) . "\n";
			}
		}
		$md .= "\n";
	}
	$md .= "## Resolved, last 30 days (" . count($resolved) . ")\n\n";
	foreach ($resolved as $e) {
		$md .= "- [{$e['id']}] {$e['resolved_at']} `{$e['script']}`: " . sc_err_md($e['title']) . " -> " . sc_err_md($e['resolution'] ?: '(no note)') . "\n";
	}
	return $md;
}

function sc_err_md($s)
{
	$s = preg_replace('/\s+/', ' ', trim((string)$s));
	return (mb_strlen($s) > 600) ? mb_substr($s, 0, 597) . '...' : $s;
}
?>
