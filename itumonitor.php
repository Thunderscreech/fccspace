<?php
//Read https://www.itu.int/ITU-R/space/asreceived/Publication/AsReceived for Notification Of Space Station entries
//
//2026-11-13: Rewritten. In 2023 the ITU added a "Reference" column at the front of the table, which
//shifted every column by one. The script then read the registry date where it expected the submission
//type, never matched "Space Station", and printed "Done." every hour for three years without posting.
//Columns are now located by their header text, and a layout change or empty table raises an alert.
include 'common.inc';

//Only one run at a time
$lock_handle	= fopen(__DIR__.'/itumonitor.lock', 'c');
if(!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB))
{
	echo "Another run is still in progress. Exiting.\n";
	exit();
}

//Check if I'm supposed to be running
$active_result	= mysqli_query($conn, "SELECT status_data FROM status WHERE status_item = 'active'");
$active_row	= $active_result ? $active_result->fetch_object() : null;
if(!$active_row || $active_row->status_data != '1')
{
	echo "According to status.status_item.active, I am supposed to be asleep (or the status table is unreadable).  Exiting...\n";
	exit();
}

$list_url		= "https://www.itu.int/ITU-R/space/asreceived/Publication/AsReceived";
$detail_url		= "https://www.itu.int/ITU-R/space/asreceived/Publication/DisplayPublication/";
$png_dir		= "/home/chairboy/fccspacewatch/license_renders";
$max_posts_per_run	= 3;	//Drain a backlog over several hourly runs instead of flooding
$max_attempts		= 6;	//Post retries (one per hourly run) before giving up with an alert
$type_match		= 'Space Station';	//Matches "Notification of Space Station" and its resubmissions

//Header text => our field name. Matched case-insensitively, ignoring spaces and punctuation.
$wanted_columns	= array(
	'NTC ID'			=> 'ntd_id',
	'Adm.'				=> 'adm',
	'Station/Satellite Name'	=> 'station_name',
	'Long. Nom.'			=> 'long_nom',
	'BR Registry Date'		=> 'br_reg_date',
	'Type of submission'		=> 'submission_type',
);

echo "Running ITU monitor...\n";

try
{
	$html	= itu_fetch($list_url);
	$rows	= itu_parse_rows($html, $wanted_columns);
} catch(Throwable $e)
{
	echo "ERROR: ".$e->getMessage()."\n";
	track_itu_health($conn, false, $e->getMessage());
	exit();
}
track_itu_health($conn, true, '');

//Update last run (only once we actually have a usable page)
mysqli_query($conn, "UPDATE status SET status_data = now() WHERE status_item = 'itu_lastrun'");

echo "Checking ITU filings (".count($rows)." rows on page)...\n";

//Finish anything a previous run recorded but couldn't post
$post_count	= retry_itu_posts($conn, $max_posts_per_run, $max_attempts, $detail_url, $png_dir);

//The page lists newest first; post oldest first so a backlog goes out in order
foreach(array_reverse($rows) as $row)
{
	if($post_count >= $max_posts_per_run)
	{
		echo "Hit max_posts_per_run ($max_posts_per_run); remaining filings will post on later runs.\n";
		break;
	}
	if(stripos($row['submission_type'], $type_match) === false)
	{
		continue;
	}

	$submission_id	= (int)$row['submission_id'];
	$res		= mysqli_query($conn, "SELECT COUNT(*) AS c FROM itu_filings WHERE submission_id = $submission_id");
	if(!$res)
	{
		//Don't treat a DB failure as "new", or everything on the page would be posted again
		echo "ERROR: dedupe query failed: ".mysqli_error($conn)."\n";
		break;
	}
	if($res->fetch_object()->c >= 1)
	{
		continue;
	}

	$station_name	= $row['station_name'];
	$adm		= $row['adm'];
	$long_nom	= $row['long_nom'];
	$lat_text	= preg_match('~[0-9]~', $long_nom) ? " to be located at $long_nom" : '';
	$post_text	= "The ITU has received a Satellite Network filing for $station_name ($adm)$lat_text\n$detail_url$submission_id";

	echo "New filing $submission_id: $station_name ($adm) $long_nom, ".$row['submission_type']."\n";

	$e_ntd		= mysqli_real_escape_string($conn, substr($row['ntd_id'], 0, 16));
	$e_type		= mysqli_real_escape_string($conn, substr($row['submission_type'], 0, 128));
	$e_station	= mysqli_real_escape_string($conn, substr($station_name, 0, 128));
	$e_long		= mysqli_real_escape_string($conn, substr($long_nom, 0, 32));
	$e_date		= mysqli_real_escape_string($conn, $row['br_reg_date']);
	$e_adm		= mysqli_real_escape_string($conn, substr($adm, 0, 24));
	$e_post		= mysqli_real_escape_string($conn, $post_text);
	$sqlstring	= "INSERT INTO itu_filings (ntd_id, submission_type, submission_id, station_name, longitude, br_reg_date, org_name, post_text, posted, post_attempts)
			   VALUES ('$e_ntd', '$e_type', $submission_id, '$e_station', '$e_long', COALESCE(str_to_date('$e_date','%d.%m.%Y'), CURDATE()), '$e_adm', '$e_post', 0, 0)";
	if(!mysqli_query($conn, $sqlstring))
	{
		//Not recorded means it would be re-posted every run, so don't post it
		echo "ERROR: insert failed for $submission_id, not posting: ".mysqli_error($conn)."\n";
		continue;
	}

	post_itu_filing($conn, $submission_id, $max_attempts, $png_dir);
	$post_count++;
}

echo "Done. $post_count posts made.\n";


//Fetch a URL from the ITU. Throws on failure.
//IPv4 only: this host has no working IPv6 route, and www.itu.int publishes an AAAA record.
function itu_fetch($url)
{
	$ch	= curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER	=> true,
		CURLOPT_FOLLOWLOCATION	=> true,
		CURLOPT_IPRESOLVE	=> CURL_IPRESOLVE_V4,
		CURLOPT_USERAGENT	=> 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
		CURLOPT_CONNECTTIMEOUT	=> 20,
		CURLOPT_TIMEOUT		=> 60,
	));
	$body	= curl_exec($ch);
	$code	= curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$err	= curl_error($ch);
	curl_close($ch);
	if($code != 200 || !is_string($body) || trim($body) === '')
	{
		throw new RuntimeException("Fetch failed (HTTP $code".($err ? ", $err" : '').") for $url");
	}
	return $body;
}

function itu_norm($s)
{
	return strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
}

//Parse the As Received table into rows keyed by our field names, plus submission_id.
//Throws if the table or any wanted column is missing, so a layout change is noticed.
function itu_parse_rows($html, $wanted_columns)
{
	$dom	= new DOMDocument;
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	$table	= $dom->getElementsByTagName('table')->item(0);
	if(!$table)
	{
		throw new RuntimeException("No table on the As Received page (layout changed?)");
	}

	//Map header text to column position
	$index	= array();
	$ths	= $table->getElementsByTagName('th');
	foreach($ths as $pos => $th)
	{
		$index[itu_norm($th->textContent)]	= $pos;
	}
	$cols	= array();
	foreach($wanted_columns as $header => $field)
	{
		if(!isset($index[itu_norm($header)]))
		{
			throw new RuntimeException("Column '$header' not found in As Received table (layout changed?). Headers seen: ".
				implode(', ', array_map(function($th){ return trim($th->textContent); }, iterator_to_array($ths))));
		}
		$cols[$field]	= $index[itu_norm($header)];
	}

	$rows	= array();
	foreach($table->getElementsByTagName('tr') as $tr)
	{
		$submission_id	= $tr->getAttribute('data-submission-id');
		$tds		= $tr->getElementsByTagName('td');
		if(!ctype_digit($submission_id) || $tds->length < max($cols) + 1)
		{
			continue;
		}
		$row	= array('submission_id' => $submission_id);
		foreach($cols as $field => $pos)
		{
			$row[$field]	= trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $tds->item($pos)->textContent));
		}
		$rows[]	= $row;
	}
	if(!$rows)
	{
		throw new RuntimeException("As Received table has no filing rows (layout changed?)");
	}
	return $rows;
}

//Render (if needed) and post one recorded filing. Returns true once posted.
function post_itu_filing($conn, $submission_id, $max_attempts, $png_dir)
{
	$submission_id	= (int)$submission_id;
	$res	= mysqli_query($conn, "SELECT post_text, post_attempts FROM itu_filings WHERE submission_id = $submission_id");
	$row	= $res ? mysqli_fetch_assoc($res) : null;
	if(!$row || $row['post_text'] === null)
	{
		echo "post_itu_filing: no post text for $submission_id\n";
		return false;
	}
	$attempts	= $row['post_attempts'] + 1;
	mysqli_query($conn, "UPDATE itu_filings SET post_attempts = $attempts WHERE submission_id = $submission_id");

	$png	= "$png_dir/$submission_id.png";
	if(!is_readable($png) || filesize($png) == 0)
	{
		exec("php /home/chairboy/fccspacewatch/render_itu_filing.php $submission_id 2>&1", $render_out, $render_rc);
		if($render_rc !== 0 || !is_readable($png) || filesize($png) == 0)
		{
			echo "Render failed for $submission_id (exit $render_rc):\n".implode("\n", array_slice($render_out, -10))."\n";
		}
	}
	$img	= (is_readable($png) && filesize($png) > 0) ? $png : null;
	if($img === null && $attempts < 2)
	{
		echo "No image for $submission_id yet; will retry next run.\n";
		return false;
	}

	if(mastodon_post($row['post_text'], $img))	//Posts text-only if $img is null
	{
		mysqli_query($conn, "UPDATE itu_filings SET posted = 1 WHERE submission_id = $submission_id");
		return true;
	}
	if($attempts >= $max_attempts)
	{
		dy_discord("ITU monitor: giving up posting submission $submission_id after $attempts attempts");
	}
	return false;
}

//Retry filings recorded by an earlier run but not posted. Returns how many were attempted.
function retry_itu_posts($conn, $max_posts, $max_attempts, $detail_url, $png_dir)
{
	$res	= mysqli_query($conn, "SELECT submission_id FROM itu_filings WHERE posted = 0 AND post_attempts < ".(int)$max_attempts." ORDER BY submission_id LIMIT ".(int)$max_posts);
	$count	= 0;
	while($res && ($row = mysqli_fetch_assoc($res)))
	{
		echo "Retrying post for submission ".$row['submission_id']."\n";
		post_itu_filing($conn, $row['submission_id'], $max_attempts, $png_dir);
		$count++;
	}
	return $count;
}

//Count consecutive failed runs (hourly); alert at 3 and then once a day.
function track_itu_health($conn, $ok, $error)
{
	if($ok)
	{
		mysqli_query($conn, "UPDATE status SET status_data = '0' WHERE status_item = 'itu_failures'");
		return;
	}
	mysqli_query($conn, "UPDATE status SET status_data = CAST(status_data AS UNSIGNED) + 1 WHERE status_item = 'itu_failures'");
	$res	= mysqli_query($conn, "SELECT status_data FROM status WHERE status_item = 'itu_failures'");
	$row	= $res ? $res->fetch_object() : null;
	$fails	= $row ? (int)$row->status_data : 0;
	if($fails == 3 || ($fails > 3 && ($fails - 3) % 24 == 0))
	{
		dy_discord("ITU monitor has failed $fails runs in a row. Last error: $error");
	}
}
