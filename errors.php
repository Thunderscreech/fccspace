<?php
// Manage this project's error log (logs/errors.json -> logs/errors.md). See errorlog.inc.
//
//   php errors.php                     list open entries
//   php errors.php show <id>           full JSON for one entry
//   php errors.php resolve <id> "note" close an entry with what was wrong / what was done
//   php errors.php resolve-recovered "note"   close every open, recovered, non-critical entry
//   php errors.php reopen <id>
//   php errors.php render              rebuild errors.md
//   php errors.php notify "summary"    one quiet Discord message (used by the daily triage job)

if (getenv('SC_ERR_DIR')) {
	define('SC_ERR_DIR', getenv('SC_ERR_DIR'));	// testing
}
include __DIR__ . '/errorlog.inc';	// standalone: no DB connection needed

$cmd	= $argv[1] ?? 'list';
$id	= $argv[2] ?? '';
$data	= is_file(SC_ERR_JSON) ? (json_decode((string)file_get_contents(SC_ERR_JSON), true) ?: array('entries' => array())) : array('entries' => array());

switch ($cmd) {
case 'list':
	$n = 0;
	foreach ($data['entries'] as $e) {
		if ($e['status'] != 'open') continue;
		$n++;
		printf("%s  %-8s %-28s %3dx  last %s  %s\n  %s\n", $e['id'], $e['severity'], $e['script'], $e['count'], $e['last_seen'],
			!empty($e['recovered_at']) ? 'recovered' : 'failing', $e['title']);
	}
	echo "$n open.\n";
	break;
case 'show':
	echo isset($data['entries'][$id]) ? json_encode($data['entries'][$id], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n" : "No entry $id\n";
	break;
case 'resolve':
case 'reopen':
	if (!isset($data['entries'][$id])) {
		exit("No entry $id\n");
	}
	$note = $argv[3] ?? '';
	sc_err_update(function (&$d) use ($cmd, $id, $note) {
		if ($cmd == 'resolve') {
			$d['entries'][$id]['status']		= 'resolved';
			$d['entries'][$id]['resolved_at']	= date('Y-m-d H:i:s');
			$d['entries'][$id]['resolution']	= $note;
		} else {
			$d['entries'][$id]['status'] = 'open';
			unset($d['entries'][$id]['resolved_at'], $d['entries'][$id]['resolution']);
		}
	}) or exit("Could not write the error log\n");
	echo "$cmd $id done.\n";
	break;
case 'resolve-recovered':
	$note = $argv[2] ?? 'transient: later run succeeded';
	$n = sc_err_update(function (&$d) use ($note) {
		$n = 0;
		foreach ($d['entries'] as $k => $e) {
			if ($e['status'] == 'open' && !empty($e['recovered_at']) && $e['severity'] != 'critical') {
				$d['entries'][$k]['status']		= 'resolved';
				$d['entries'][$k]['resolved_at']	= date('Y-m-d H:i:s');
				$d['entries'][$k]['resolution']		= $note;
				$n++;
			}
		}
		return $n;
	});
	echo "Resolved " . (int)$n . " recovered entries.\n";
	break;
case 'notify':
	// One quiet Discord message from the daily triage job (only when something needs the user)
	include __DIR__ . '/common.inc';
	$text = trim((string)($argv[2] ?? ''));
	if ($text === '') {
		exit("Usage: php errors.php notify \"summary\"\n");
	}
	$send = function_exists('dy_discord_quiet') ? 'dy_discord_quiet' : 'dy_discord';
	$send(SC_ERR_PROJECT . " daily error triage: " . mb_substr($text, 0, 1500) . "\nReport: logs/triage/" . date('Y-m-d') . ".md");
	echo "Notified.\n";
	break;
case 'render':
	sc_err_update(function (&$d) {}) or exit("Could not write the error log\n");
	echo "Rendered " . SC_ERR_MD . "\n";
	break;
default:
	exit("Unknown command $cmd\n");
}
?>
