<?php
//Monitor FCC RSS feed for any new permits related to space activity
//https://apps.fcc.gov/oetcf/els/rss/NewGrants.cfm is feed
//error_reporting(E_ERROR | E_PARSE);
include 'common.inc';

//2026-11-13: Only one run at a time. A slow FCC could stretch a run past the 5-minute cron
//interval, and two overlapping runs would both see a grant as new and post it twice.
$lock_handle	= fopen(__DIR__.'/fccspacewatch.lock', 'c');
if(!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB))
{
	echo "Another run is still in progress. Exiting.\n";
	exit();
}

//Check if I'm supposed to be running
$sqlstring	= "SELECT status_data FROM status WHERE status_item = 'active'";
$active_result	= mysqli_query($conn,$sqlstring);
$active_row	= $active_result ? $active_result->fetch_object() : null;
if($active_row && $active_row->status_data == '1')
{
	//No action required, we are on like kong
} else
{
	echo "According to status.status_item.active, I am supposed to be asleep (or the status table is unreadable).  Exiting...\n";
	exit();
}


$url 			= "https://apps.fcc.gov/oetcf/els/rss/NewGrants.cfm";
$report_url_header	= "https://apps.fcc.gov/oetcf/els";
//2026-11-11: Matching is now case-insensitive and split into two checks against the feed description
//("A grant was issued on MM/DD/YYYY to <applicant>, experiment type: <type>").
//1) Experiment type keywords. Matched as whole words so "Cubesats", "Space (other than cubesats)",
//   "Rocket Launch" and "big LEO (Low Earth Orbit)" all hit.
$type_watchwords	= array('cubesats?','satellites?','space','rocket','launch','orbit','LEO');
//2) Space-focused applicants, caught regardless of experiment type (they often file as "equipment testing, general").
//   Big diversified aerospace firms (Boeing, Lockheed, L3Harris, Viasat, SES...) are left out on purpose: they file
//   dozens of radar/GPS/military/ground-terminal tests a month that aren't space hardware.
$space_applicants	= array(
	'SpaceX','Space Exploration','Blue Origin','Rocket Lab','Firefly','Relativity Space','ABL Space','Stoke Space',
	'Astra Space','Phantom Space','Stratolaunch','UP Aerospace','United Launch','Virgin Galactic','Virgin Orbit',
	'Kuiper','AST SpaceMobile','AST & Science','Lynk Global','HawkEye 360','Hawkeye360','Planet Labs','Spire Global',
	'Capella Space','Umbra Lab','Muon Space','K2 Space','True Anomaly','Impulse Space','Varda Space','Astranis',
	'Terran Orbital','York Space','Millennium Space','Sierra Space','Axiom Space','Vast Space','Intuitive Machines',
	'Astrobotic','ispace','AstroForge','Aethero','Starfish Space','Sidus Space','Turion Space','Albedo Space',
	'Loft Orbital','Orbit Fab','Momentus','Redwire','Northwood Space','Inversion Space','Outpost Space','Apex Space',
	'NanoAvionics','Kepler Communications','Swarm Technologies','Iridium','Globalstar',
);
//Form 442 (new/modified experimental license) is now processed; only renewals (Form 405) are skipped.
$badreports		= array('FCC FORM 405','APPLICATION FOR RENEWAL'); //We disregard applications with these strings
$tweet_count		= 0;
$max_posts_per_run	= 3; //Throttle so a backlog drains over several runs instead of flooding
$max_attempts		= 36; //A grant that fails to process or post this many runs (~3 hours) is given up on, with an alert
$invalidurl 		= false;
$png_dir		= "/home/chairboy/fccspacewatch/license_renders";

//2026-11-11: FCC's CDN (Akamai) returns 403 to requests with no User-Agent. PHP curl and
//file_get_contents() send none by default, which silently broke this bot from Jan 2026.
$fcc_user_agent		= 'curl/7.74.0';
stream_context_set_default(array('http' => array('user_agent' => $fcc_user_agent)));

echo "Checking FCC applications...\n";

$output		= fcc_fetch($url, false);
$http_code	= $output['code'];

libxml_use_internal_errors(true);
$feeds = ($http_code == 200 && $output['body'] !== '') ? simplexml_load_string($output['body']) : false;

if($feeds !== false && isset($feeds->channel))
{
	echo "Loaded feed.\n";
}
else
{
	foreach (libxml_get_errors() as $error)
	{
		echo "\t", $error->message;
	}
	$invalidurl = true;
	$feeds	= false;
	echo "ERROR: Could not load RSS feed (HTTP $http_code)\n";
}

//Update last run
$sqlstring	= "update status set status_data = now() where status_item = 'last_run'";
$result		= mysqli_query($conn,$sqlstring);// or die "Error running query: $sqlstring".mysqli_error($conn);

//2026-11-13: last_run updates even when the feed is down, which is how the Jan 2026 outage went
//unnoticed. Count consecutive feed failures and alert after 30 minutes, then every 6 hours.
track_feed_health($conn, $feeds !== false, $http_code);

//Finish posting anything a previous run recorded but couldn't post (Mastodon down, render failed, crash...)
$tweet_count	+= retry_pending_posts($conn, $max_attempts, $max_posts_per_run, $png_dir);

$i=0;
if(!empty($feeds))
{

	$site = $feeds->channel->title;
	$sitelink = $feeds->channel->link;

//	echo "Site: $site";
	foreach ($feeds->channel->item as $item)
	{
		if($tweet_count >= $max_posts_per_run)
		{
			echo "Hit max_posts_per_run ($max_posts_per_run); remaining items will post on later runs.\n";
			break;
		}

		$title		= (string)$item->title;
		$link		= $report_url_header.(str_replace("..","",$item->link));
		$description	= (string)$item->description;
		$postDate	= (string)$item->pubDate;

		//If the experiment type or applicant looks space-related, we process it
		if(!($match_reason = space_match_reason($description, $type_watchwords, $space_applicants)))
		{
			continue; //Nothing to do, this is boring
		}

		echo "Match ($match_reason): ".trim($title)."\n";
		//Process this into database if we haven't already
		if(haveProcessedFCCApp($conn, $title, $max_attempts))
		{
			echo "Already in the system.\n";
			continue;
		}

		//2026-11-13: Each grant is processed in isolation. Previously a failed page fetch or an
		//unexpected page layout was a fatal error that killed the run, and because nothing had been
		//recorded the same grant crashed every later run too, blocking everything after it in the feed.
		try
		{
			$tweet_count	+= process_grant($conn, $title, $link, $description, $postDate, $badreports, $max_attempts, $png_dir);
		} catch(Throwable $e)
		{
			echo "ERROR processing ".trim($title).": ".get_class($e).": ".$e->getMessage()."\n";
			record_skip($conn, $title, get_class($e).": ".$e->getMessage(), false, $max_attempts);
		}
	}

	echo "Run complete.  $tweet_count posts made.\n";

}

//Fetch a URL from the FCC. With $throw (the default) a non-200 or empty response throws, so the
//caller's try/catch handles it; otherwise returns array('code' => ..., 'body' => ...).
function fcc_fetch($url, $throw = true)
{
	global $fcc_user_agent;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1');
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_USERAGENT, $fcc_user_agent);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	$body	= curl_exec($ch);
	$code	= curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$err	= curl_error($ch);
	curl_close($ch);
	$body	= ($body === false) ? '' : $body;
	if($throw && ($code != 200 || trim($body) === ''))
	{
		throw new RuntimeException("Fetch failed (HTTP $code".($err ? ", $err" : '').") for $url");
	}
	return $throw ? $body : array('code' => $code, 'body' => $body);
}

//Process one matching feed item. Returns 1 if a post was made, else 0. Throws on fetch/parse failures.
function process_grant($conn, $title, $link, $description, $postDate, $badreports, $max_attempts, $png_dir)
{
	//We get the current report URL from which we'll extract our info
	$current_report_url	= get_current_report_url($link);
	if(!$current_report_url)
	{
		echo "Error getting current application for $link\n";
		record_skip($conn, $title, "no current report link on $link", false, $max_attempts);
		return 0;
	}

	//Renewals (Form 405) have nothing new to report. Decide that from the URL before fetching the
	//report, and remember it so we stop re-downloading the same renewals every run.
	if(strpos($current_report_url, '/405_Print') !== false)
	{
		echo "Skipping application as uninteresting (FCC FORM 405): ".trim($title)."\n";
		record_skip($conn, $title, 'renewal (FCC FORM 405)', true, $max_attempts);
		return 0;
	}

	$html			= fcc_fetch($current_report_url);
	$headline		= "License granted";
	if(strpos($current_report_url, '/442_Print') !== false)
	{
		//New or modified experimental license (Form 442) has no narrative or date range,
		//so we build the purpose from its structured fields.
		$f442			= parse_442($html, $description);
		$report_type		= 'FCC FORM 442';
		$applicant_name		= $f442['applicant_name'];
		$applicant_address	= $f442['address'];
		$purpose		= $f442['purpose'];
		$explanation		= $f442['explanation'];
		$dates			= $f442['dates'];
		$headline		= $f442['headline'];
	} else
	{
		$explanation		= get_explanation($html);
		$applicant_address	= get_applicant_address($html);
		$applicant_name		= get_applicant_name($html);
		$report_type		= get_report_type($html);
		$purpose		= get_purpose($html);
		$dates			= get_dates($html);
	}
	$applicant_name		= trim(preg_replace('/\s+/', ' ', (string)$applicant_name));
	$purpose		= (string)$purpose;
	$dates			= (string)$dates;

	if (containsWatchword($report_type, $badreports))
	{
		echo "Skipping application as uninteresting ($report_type): ".trim($title)."\n";
		record_skip($conn, $title, "uninteresting report type ($report_type)", true, $max_attempts);
		return 0;
	}

	$full_purpose	= $purpose; //We keep an un-shortened one for our records
	if(strlen($purpose) >= 85)
	{
		$purpose = substr($purpose,0,85)."(...)";
	}

	if(strlen($applicant_name) >= 46)
	{
		$applicant_name	= substr($applicant_name, 0, 45)."(...)";
	}

	echo "Applicant: $applicant_name\n";
	echo "Purpose: $purpose\n";
	echo "Dates: $dates\n";

	$tweet		= "$headline: $applicant_name\nDates: $dates\nPurpose: $purpose\n$current_report_url";
	echo 		"Tweet (".strlen($tweet)."): $tweet\n\n";

	if(empty($applicant_name) || empty($purpose))
	{
		echo "Missing applicant name or purpose, not posting: ".trim($title)."\n";
		record_skip($conn, $title, 'missing applicant name or purpose', false, $max_attempts);
		return 0;
	}

	//Log it to prevent re-posting. It's recorded as not-yet-posted; post_application() marks each
	//platform done only once it succeeds, and later runs retry anything left unposted.
	$esc_purpose		= mysqli_real_escape_string($conn, $full_purpose);
	$esc_name		= mysqli_real_escape_string($conn, $applicant_name);
	$esc_address		= mysqli_real_escape_string($conn, (string)$applicant_address);
	$esc_explanation	= mysqli_real_escape_string($conn, (string)$explanation);
	$esc_url		= mysqli_real_escape_string($conn, $current_report_url);
	$esc_description	= mysqli_real_escape_string($conn, substr($description, 0, 256));
	$esc_title		= mysqli_real_escape_string($conn, $title);
	$esc_postdate		= mysqli_real_escape_string($conn, $postDate);
	$esc_dates		= mysqli_real_escape_string($conn, substr($dates, 0, 64));
	$esc_tweet		= mysqli_real_escape_string($conn, $tweet);
	//The feed has always said EST; accept EDT too so a format change can't make every insert fail.
	$sqlstring		= "INSERT INTO fcc_applications (url, application_description, application_pub_date, application_title, application_address, applicant_name, application_purpose, application_explanation, dates, post_text, mastodon_done, bluesky_done, post_attempts) VALUES ('$esc_url','$esc_description',COALESCE(str_to_date(REPLACE(REPLACE('$esc_postdate',' EST',''),' EDT',''),'%a, %d %b %Y %k:%i:%S'), NOW()),'$esc_title','$esc_address', '$esc_name','$esc_purpose', '$esc_explanation', '$esc_dates', '$esc_tweet', 0, 0, 0)";
	echo "$sqlstring\n";
	if(!mysqli_query($conn,$sqlstring))
	{
		//If we couldn't record it, don't post: the dedupe check reads this table,
		//so a failed insert would otherwise re-post the same grant every run.
		throw new RuntimeException("DB insert failed: ".mysqli_error($conn));
	}

	$application_id		= mysqli_insert_id($conn);
	post_application($conn, $application_id, $max_attempts, $png_dir);
	return 1;
}

//Render (if needed) and post one recorded application to each platform not yet done.
//Returns true once every platform is done.
function post_application($conn, $application_id, $max_attempts, $png_dir)
{
	$application_id	= (int)$application_id;
	$res	= mysqli_query($conn, "SELECT post_text, mastodon_done, bluesky_done, post_attempts FROM fcc_applications WHERE application_id = $application_id");
	$row	= $res ? mysqli_fetch_assoc($res) : null;
	if(!$row || $row['post_text'] === null)
	{
		echo "post_application: no post text for application $application_id\n";
		return false;
	}
	$attempts	= $row['post_attempts'] + 1;
	mysqli_query($conn, "UPDATE fcc_applications SET post_attempts = $attempts WHERE application_id = $application_id");

	$png	= "$png_dir/$application_id.png";
	if(!is_readable($png) || filesize($png) == 0)
	{
		$render_string	= "php /home/chairboy/fccspacewatch/render_application.php $application_id";
		echo "$render_string\n";
		exec("$render_string 2>&1", $render_out, $render_rc);
		if($render_rc !== 0 || !is_readable($png) || filesize($png) == 0)
		{
			echo "Render failed (exit $render_rc):\n".implode("\n", array_slice($render_out, -10))."\n";
		}
	}
	$img	= (is_readable($png) && filesize($png) > 0) ? $png : null;
	if($img === null && $attempts < 3)
	{
		//Give the render a few runs before falling back to a text-only post
		echo "No image for application $application_id yet; will retry next run.\n";
		return false;
	}

	$mastodon_done	= (int)$row['mastodon_done'];
	$bluesky_done	= (int)$row['bluesky_done'];
	if(!$mastodon_done && mastodon_post($row['post_text'], $img))
	{
		$mastodon_done	= 1;
	}
	if(!$bluesky_done)
	{
		//null means Bluesky isn't configured, which counts as done
		$bluesky_result	= bluesky_post($row['post_text'], $img);
		if($bluesky_result !== false)
		{
			$bluesky_done	= 1;
		}
	}
	mysqli_query($conn, "UPDATE fcc_applications SET mastodon_done = $mastodon_done, bluesky_done = $bluesky_done WHERE application_id = $application_id");

	$all_done	= ($mastodon_done && $bluesky_done);
	if(!$all_done && $attempts >= $max_attempts)
	{
		dy_discord("FCCSpace: giving up posting application $application_id after $attempts attempts (mastodon_done=$mastodon_done, bluesky_done=$bluesky_done)");
	}
	return $all_done;
}

//Retry applications recorded by an earlier run but not fully posted. Returns how many were attempted.
function retry_pending_posts($conn, $max_attempts, $max_posts, $png_dir)
{
	$max_attempts	= (int)$max_attempts;
	$max_posts	= (int)$max_posts;
	$res	= mysqli_query($conn, "SELECT application_id FROM fcc_applications WHERE (mastodon_done = 0 OR bluesky_done = 0) AND post_attempts < $max_attempts ORDER BY application_id LIMIT $max_posts");
	$count	= 0;
	while($res && ($row = mysqli_fetch_assoc($res)))
	{
		echo "Retrying post for application ".$row['application_id']."\n";
		try
		{
			post_application($conn, $row['application_id'], $max_attempts, $png_dir);
		} catch(Throwable $e)
		{
			echo "ERROR retrying application ".$row['application_id'].": ".$e->getMessage()."\n";
		}
		$count++;
	}
	return $count;
}

//Remember a feed item we couldn't (or shouldn't) post. Permanent skips (renewals) are never looked
//at again; other failures are retried on later runs until $max_attempts, then alerted on once.
function record_skip($conn, $title, $reason, $permanent, $max_attempts)
{
	$esc_title	= mysqli_real_escape_string($conn, $title);
	$esc_reason	= mysqli_real_escape_string($conn, substr($reason, 0, 255));
	$perm		= $permanent ? 1 : 0;
	mysqli_query($conn, "INSERT INTO fcc_skipped (application_title, reason, permanent, attempts) VALUES ('$esc_title', '$esc_reason', $perm, 1)
		ON DUPLICATE KEY UPDATE reason = VALUES(reason), permanent = VALUES(permanent), attempts = attempts + 1, last_seen = NOW()");
	if(!$permanent)
	{
		$res	= mysqli_query($conn, "SELECT attempts FROM fcc_skipped WHERE application_title = '$esc_title'");
		$row	= $res ? $res->fetch_object() : null;
		if($row && $row->attempts == $max_attempts)
		{
			dy_discord("FCCSpace: giving up on ".trim($title)." after $max_attempts failed attempts. Last error: $reason");
		}
	}
}

//Count consecutive feed failures in the status table; alert after 6 (30 min) and every 72 (6 hours) after that.
function track_feed_health($conn, $ok, $http_code)
{
	if($ok)
	{
		mysqli_query($conn, "UPDATE status SET status_data = '0' WHERE status_item = 'feed_failures'");
		return;
	}
	mysqli_query($conn, "UPDATE status SET status_data = CAST(status_data AS UNSIGNED) + 1 WHERE status_item = 'feed_failures'");
	$res	= mysqli_query($conn, "SELECT status_data FROM status WHERE status_item = 'feed_failures'");
	$row	= $res ? $res->fetch_object() : null;
	$fails	= $row ? (int)$row->status_data : 0;
	if($fails == 6 || ($fails > 6 && ($fails - 6) % 72 == 0))
	{
		dy_discord("FCCSpace: the FCC grants feed has failed $fails runs in a row (last HTTP $http_code). Nothing is being posted.");
	}
}

function name_replace($applicant)
{
	if(strpos($applicant, "Space Exploration Technologies"))
	{
		$applicant	= "SpaceX";
		echo "MATCHED SPACEX\n";
	}

	if(strpos($applicant, "Lynk"))
	{
		$applicant	= "Lynk Global";
	}

	if(strpos($applicant, "Boeing Company"))
	{
		$applicant	= "Boeing";
	}

	if(strpos($applicant, "Northrop"))
	{
		$applicant	= "Northrop Grumman";
	}

	return $applicant;
}

function get_applicant_address($html)
{	
	//for processing the actual report
	$dom = new domDocument; 
	$dom->loadHTML($html); 
	$dom->preserveWhiteSpace = false; 
	$tables = $dom->getElementsByTagName('table'); 
	$rows = $tables->item(2)->getElementsByTagName('tr'); 
	foreach ($rows as $rowkey => $row) 
	{
		echo "Row: $rowkey\n";
		$cols = $row->getElementsByTagName('td'); 
//		echo "0:".$cols->item(0)->nodeValue."\n";
//		echo "1:".$cols->item(1)->nodeValue."\n";
		$address_working[]	= $cols->item(1)->nodeValue;
//		echo "2:".$cols->item(2)->nodeValue."\n";
//		echo "3:".$cols->item(3)->nodeValue."\n";
//		echo "4:".$cols->item(4)->nodeValue."\n";
//		echo "5:".$cols->item(5)->nodeValue."\n";
//		echo "6:".$cols->item(6)->nodeValue."\n";
//		echo "7:".$cols->item(7)->nodeValue."\n";
//		echo "8:".$cols->item(8)->nodeValue."\n";
//		echo "9:".$cols->item(9)->nodeValue."\n";
	}
	//Array: 0: name, 1: street address, 2: PO B 3:City 4: State 5: zip 6: country

	$applicant_address	= $address_working[0]."\n".$address_working[1]."\n".$address_working[2]."\n".$address_working[3].", ".$address_working[4].", ".$address_working[5]."\n".$address_working[6];

	return $applicant_address;

}



function get_applicant_name($html)
{	
	//for processing the actual report
	$dom = new domDocument; 
	$dom->loadHTML($html); 
	$dom->preserveWhiteSpace = false; 
	$tables = $dom->getElementsByTagName('table'); 
	$rows = $tables->item(1)->getElementsByTagName('tr'); 
	foreach ($rows as $row) 
	{
		$cols = $row->getElementsByTagName('td'); 
		//echo "0:".$cols->item(0)->nodeValue."\n";
		//echo "1:".$cols->item(1)->nodeValue."\n";
		$applicant_name		= $cols->item(1)->nodeValue;
		//echo "2:".$cols->item(2)->nodeValue."\n";
		//echo "3:".$cols->item(3)->nodeValue."\n";
		//echo "4:".$cols->item(4)->nodeValue."\n";
		//echo "5:".$cols->item(5)->nodeValue."\n";
		//echo "6:".$cols->item(6)->nodeValue."\n";
		//echo "7:".$cols->item(7)->nodeValue."\n";
		//echo "8:".$cols->item(8)->nodeValue."\n";
		//echo "9:".$cols->item(9)->nodeValue."\n";
	}
	return $applicant_name;
}

function get_report_type($html)
{
	//for processing the actual report
	$dom = new domDocument; 
	$dom->loadHTML($html); 
	$dom->preserveWhiteSpace = false; 
	$tables = $dom->getElementsByTagName('table'); 
	$rows = $tables->item(0)->getElementsByTagName('tr'); 
	foreach ($rows as $row) 
	{
		$cols = $row->getElementsByTagName('td'); 
		$report_type		= $cols->item(0)->nodeValue;
	}
	return trim($report_type);
}

function get_explanation($html)
{
	$dom = new domDocument; 
	$dom->loadHTML($html); 
	$dom->preserveWhiteSpace = false; 
	$tables = $dom->getElementsByTagName('table'); 
	$rows = $tables->item(4)->getElementsByTagName('tr'); 
	foreach ($rows as $row) 
	{
		$cols = $row->getElementsByTagName('td'); 
		$explanation	= $cols->item(0)->nodeValue;
	}
	$explanation	= trim(str_replace('Please explain in the area below why an STA is necessary:','',$explanation));
	return $explanation;
}


function get_purpose($html)
{
	//for processing the actual report
	$dom = new domDocument; 
	$dom->loadHTML($html); 
	$dom->preserveWhiteSpace = false; 
	$tables = $dom->getElementsByTagName('table'); 
	$rows = $tables->item(5)->getElementsByTagName('tr'); 
	foreach ($rows as $row) 
	{
		$cols = $row->getElementsByTagName('td'); 
		$purpose		= $cols->item(1)->nodeValue;
	}
	return $purpose;
}


function get_dates($html)
{
	//For processing the actual report
	$dom = new domDocument; 
	$dom->loadHTML($html); 
	$dom->preserveWhiteSpace = false; 
	$tables = $dom->getElementsByTagName('table'); 
	$rows = $tables->item(7)->getElementsByTagName('tr');
	$start_date = $end_date = null;
	foreach ($rows as $row)
	{
		$cols = $row->getElementsByTagName('td');
		if($cols->length < 2)
		{
			continue;
		}
		//!== false: a label at position 0 used to be missed
		if(strpos($cols->item(0)->nodeValue,'Start Date') !== false)
		{
			$start_date	= $cols->item(1)->nodeValue;
		}
		if(strpos($cols->item(0)->nodeValue,'End Date') !== false)
		{
			$end_date	= $cols->item(1)->nodeValue;
		}

	}
	if((null !== $start_date) && (null !== $end_date))
	{
		return "$start_date-$end_date";
	} else
	{
		return false;
	}
}


function get_current_report_url($url)
{	
	$dom	= new domDocument;
	$html	= fcc_fetch($url); //Throws on failure; the caller's try/catch records it
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	$dom->preserveWhiteSpace = false;
	foreach($dom->getElementsByTagName('a') as $anchor)
	{
		$link	= $anchor->getAttribute('href');
		if (strpos($link,"mode=current") !== false)
		{
			//this is our link to the real report
			$return_url	= "https://apps.fcc.gov$link";
		} 
	}

	if(isset($return_url))
	{
		return $return_url;
	} else
	{
		return false;
	}
}	

function getReportType($url)
{

}

function getCurrentReport($url)
{
	//Load page, find URL to current report for mining
	$html 	= file_get_contents($url);
	$dom 	= new domDocument; 
	libxml_use_internal_errors(true);
	$dom->loadHTML($html); 
	$dom->preserveWhiteSpace = false; 
	$tables = $dom->getElementsByTagName('table'); 
	$rows = $tables->item(5)->getElementsByTagName('tr'); 
	foreach ($rows as $row) 
	{
		$cols = $row->getElementsByTagName('td'); 
//		echo "0:".$cols->item(0)->nodeValue."\n";
//		echo "1:".$cols->item(1)->nodeValue."\n";
//		echo "2:".$cols->item(2)->nodeValue."\n";
//		echo "3:".$cols->item(3)->nodeValue."\n";
//		echo "4:".$cols->item(4)->nodeValue."\n";
//		echo "5:".$cols->item(5)->nodeValue."\n";
//		echo "6:".$cols->item(6)->nodeValue."\n";
//		echo "7:".$cols->item(7)->nodeValue."\n";
//		echo "8:".$cols->item(8)->nodeValue."\n";
//		echo "9:".$cols->item(9)->nodeValue."\n";
		$realReportURL	= $cols->item(1)->nodeValue;

	}
	return $realReportURL;


}

function processFCCapp($conn, $link, $description, $postDate)
{
	//Process the application in the link to extract the salient info to report then build a tweet
	//Notes: URL is about 90 bytes. Use link shortener?  https://davidwalsh.name/create-tiny-url-php

	return $tweet_text;
}


//True if this grant is already recorded, was skipped for good (renewal), or has failed too often.
//Throws if the DB can't be read, so a DB hiccup can't make everything look new and get re-posted.
function haveProcessedFCCApp($conn, $title, $max_attempts)
{
	$esc_title	= mysqli_real_escape_string($conn, (string)$title);
	$max_attempts	= (int)$max_attempts;
	$sqlstring	= "SELECT (SELECT count(*) FROM fcc_applications WHERE application_title = '$esc_title')
			 + (SELECT count(*) FROM fcc_skipped WHERE application_title = '$esc_title' AND (permanent = 1 OR attempts >= $max_attempts)) AS application_count";
	$result		= mysqli_query($conn, $sqlstring);
	if(!$result)
	{
		throw new RuntimeException("Dedupe query failed: ".mysqli_error($conn));
	}
	return $result->fetch_object()->application_count >= 1;
}


//Returns a short reason string if this feed item looks space-related, else false.
function space_match_reason($description, $type_watchwords, $space_applicants)
{
	$description	= (string)$description;
	if(preg_match('/ to (.*), experiment type: (.*)$/s', $description, $m))
	{
		$applicant	= $m[1];
		$type		= $m[2];
	} else
	{
		//Unexpected format: fall back to checking the whole description for type keywords
		$applicant	= '';
		$type		= $description;
	}

	$type_regex	= '/\b('.implode('|', $type_watchwords).')\b/i';
	if(preg_match($type_regex, $type))
	{
		return "type: ".trim($type);
	}

	foreach($space_applicants as $name)
	{
		if(stripos($applicant, $name) !== false)
		{
			return "applicant: $name";
		}
	}
	return false;
}

//Tidy an ELS frequency like "2294.75000000- MHz" or "14.00000000-14.50000000 GHz"
function fmt_442_freq($freq)
{
	if(!preg_match('/^([\d.]+)-([\d.]*)\s*([kMGT]?Hz)/', trim($freq), $m))
	{
		return trim($freq);
	}
	$lo	= rtrim(rtrim($m[1], '0'), '.');
	$hi	= ($m[2] === '') ? '' : rtrim(rtrim($m[2], '0'), '.');
	return ($hi === '' || $hi === $lo) ? "$lo $m[3]" : "$lo-$hi $m[3]";
}

//Summarize a list, keeping the first $max and noting how many more
function list_some($items, $max)
{
	$items	= array_values(array_unique(array_filter($items)));
	$extra	= count($items) - $max;
	$out	= implode('; ', array_slice($items, 0, $max));
	return ($extra > 0) ? "$out (+$extra more)" : $out;
}

//Parse a Form 442 (new or modified experimental license) print page into the fields we post.
function parse_442($html, $feed_description)
{
	$dom	= new domDocument;
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	//FCC pages are full of non-breaking spaces, which plain \s doesn't match
	$text	= preg_replace('/[\s\x{00A0}]+/u', ' ', $dom->textContent);
	if($text === null)
	{
		$text	= preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', $dom->textContent));
	}

	$r	= array('applicant_name' => '', 'address' => '', 'purpose' => '', 'explanation' => '', 'dates' => '', 'headline' => 'License granted');

	if(preg_match("/Applicant's Name \(company\):\s*(.*?)\s+File No/", $text, $m))
	{
		$r['applicant_name']	= trim($m[1]);
	}

	$addr	= array();
	foreach(array('Street Address', 'City', 'State', 'Zip Code', 'Country') as $field)
	{
		if(preg_match("/$field:\s*(.*?)\s*(?=(?:P\.O\. Box|City|State|Country|Zip Code|E-Mail Address):)/", $text, $m) && trim($m[1]) !== '')
		{
			$addr[]	= trim($m[1]);
		}
	}
	$r['address']	= $r['applicant_name']."\n".implode(', ', $addr);

	$kind	= '';
	if(preg_match('/Application is for:\s*(NEW LICENSE|MODIFICATION OF LICENSE|[A-Z ]+?)\s+(?:For Modification|File No)/', $text, $m))
	{
		$kind	= trim($m[1]);
	}
	if(stripos($kind, 'MODIFICATION') !== false)
	{
		$kind_label		= 'Modified license';
		$r['headline']		= 'License modification granted';
	} else
	{
		$kind_label		= 'New license';
		$r['headline']		= 'New license granted';
	}

	$months	= '';
	if(preg_match('/length of time.*?:\s*(\d+)\s*Months/i', $text, $m))
	{
		$months	= $m[1];
	}

	$grant_date	= '';
	if(preg_match('/grant was issued on (\d{2}\/\d{2}\/\d{4})/', $feed_description, $m))
	{
		$grant_date	= $m[1];
	}
	//Kept short: the render's Dates box only fits ~18 characters. Duration goes in the explanation.
	$r['dates']	= $grant_date ? "Granted $grant_date" : '';

	//Walk table rows for equipment, locations and frequencies
	$equipment = $locations = $freqs = array();
	foreach($dom->getElementsByTagName('tr') as $tr)
	{
		$cells	= array();
		foreach($tr->getElementsByTagName('td') as $td)
		{
			$cells[]	= trim(preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', $td->textContent)));
		}
		$n	= count($cells);
		if($n == 4 && in_array($cells[3], array('Yes', 'No')) && $cells[0] !== '')
		{
			//Manufacturer | Model | Units | Experimental
			$equipment[]	= "$cells[0] $cells[1]".(($cells[2] !== '' && $cells[2] !== '1') ? " (x$cells[2])" : '');
		} elseif($n >= 7 && preg_match('/^\d+$/', $cells[0]))
		{
			//Location row: description follows the longitude cell; fall back to "City, State"
			$loc	= '';
			foreach($cells as $ci => $c)
			{
				if(preg_match('/^(West|East)\b/', $c))
				{
					$loc	= isset($cells[$ci + 1]) ? $cells[$ci + 1] : '';
					break;
				}
			}
			if($loc === '' && $cells[1] !== '')
			{
				$loc	= $cells[1].($cells[2] !== '' ? ", $cells[2]" : '');
			}
			$locations[]	= $loc;
		} elseif($n >= 7 && preg_match('/[kMGT]?Hz$/', $cells[1]))
		{
			$freqs[]	= fmt_442_freq($cells[1]);
		}
	}

	$where		= list_some($locations, 2);
	$r['purpose']	= $kind_label.($where ? ": $where" : '').($freqs ? " | ".list_some($freqs, 3) : '');

	$exp	= array();
	if($locations)	$exp[]	= "Locations: ".list_some($locations, 10);
	if($freqs)	$exp[]	= "Frequencies: ".list_some($freqs, 12);
	if($equipment)	$exp[]	= "Equipment: ".list_some($equipment, 8);
	if($months)	$exp[]	= "Estimated program duration: $months months";
	$r['explanation']	= implode("\n", $exp);

	return $r;
}

function containsWatchword($string, $targets)
{
	//$string is the string to search
	//$targets is an array of watchwords
	$return	= false;
	foreach ($targets as $t)
	{
		if(strpos($string, $t) !== false)
		{
			//We got a live one
			$return	= true;
		}
	}
	return $return;
}


?>