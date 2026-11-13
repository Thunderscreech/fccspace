<?php
//Monitor NASA Johnson Space Center procurement pages and send a Discord alert when a new one is posted.
//
//2026-11-13: Rewritten. In Sept 2023 procurement.jsc.nasa.gov started redirecting to the nasa.gov
//homepage, so the old link scraper recorded 8 nasa.gov menu links as "procurements" and never saw a
//real one again. JSC procurements now live under https://www.nasa.gov/johnson/jsc-procurement/ as
//WordPress "topic" pages. We read them from the WordPress REST API, which lists every child page
//(including ones the landing page doesn't link to) with a stable ID, title and publish date.
include 'common.inc';

//Only one run at a time
$lock_handle	= fopen(__DIR__.'/nasa_procurement_monitor.lock', 'c');
if(!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB))
{
	echo "Another run is still in progress. Exiting.\n";
	exit();
}

$parent_topic_id	= 176337;	//https://www.nasa.gov/johnson/jsc-procurement/
$api_url		= "https://www.nasa.gov/wp-json/wp/v2/topic?parent=$parent_topic_id&per_page=100&orderby=date&order=asc&_fields=id,date,link,slug,title";
$skip_slugs		= array('about-jsc-procurement');	//Not a procurement

echo "Checking JSC procurements...\n";

try
{
	$topics	= json_decode(nasa_fetch($api_url), true);
	if(!is_array($topics) || !$topics)
	{
		throw new RuntimeException("API returned no procurement pages (did the parent page move?)");
	}
} catch(Throwable $e)
{
	echo "ERROR: ".$e->getMessage()."\n";
	track_nasa_health($conn, false, $e->getMessage());
	exit();
}
track_nasa_health($conn, true, '');

//First run after the rewrite: record what's already there and send one summary instead of an alert per page
$res		= mysqli_query($conn, "SELECT COUNT(*) AS c FROM nasa_procurement WHERE wp_id IS NOT NULL");
if(!$res)
{
	echo "ERROR: DB query failed: ".mysqli_error($conn)."\n";
	exit();
}
$bootstrap	= ($res->fetch_object()->c == 0);
$seeded		= array();
$new_count	= 0;

foreach($topics as $topic)
{
	$wp_id	= (int)($topic['id'] ?? 0);
	$link	= (string)($topic['link'] ?? '');
	$slug	= (string)($topic['slug'] ?? '');
	if(!$wp_id || $link === '' || in_array($slug, $skip_slugs))
	{
		continue;
	}

	$res	= mysqli_query($conn, "SELECT COUNT(*) AS c FROM nasa_procurement WHERE wp_id = $wp_id");
	if(!$res)
	{
		//Don't treat a DB failure as "new", or every procurement would be announced again
		echo "ERROR: dedupe query failed: ".mysqli_error($conn)."\n";
		break;
	}
	if($res->fetch_object()->c >= 1)
	{
		echo "Known: $link\n";
		continue;
	}

	$title		= procurement_title($topic);
	$published	= date('Y-m-d H:i:s', strtotime($topic['date'] ?? 'now'));
	$e_title	= mysqli_real_escape_string($conn, mb_substr($title, 0, 255));
	$e_link		= mysqli_real_escape_string($conn, substr($link, 0, 255));
	$e_pub		= mysqli_real_escape_string($conn, $published);
	if(!mysqli_query($conn, "INSERT INTO nasa_procurement (procurement_title, procurement_url, wp_id, published_date) VALUES ('$e_title', '$e_link', $wp_id, '$e_pub')"))
	{
		//Not recorded means it would be announced every run, so don't announce it
		echo "ERROR: insert failed for $link, not announcing: ".mysqli_error($conn)."\n";
		continue;
	}

	if($bootstrap)
	{
		$seeded[]	= "$title (posted ".substr($published, 0, 10).")";
		echo "Seeded: $title\n";
	} else
	{
		echo "New procurement: $title at $link\n";
		pushbullet("A new NASA JSC procurement has posted: $title at $link");
		$new_count++;
	}
}

if($bootstrap && $seeded)
{
	pushbullet("NASA procurement monitor is working again (it had been broken since Sept 2023). Currently listed JSC procurements:\n- ".implode("\n- ", $seeded)."\nYou'll be alerted when new ones appear.");
}

echo "End of line. $new_count new.\n";


//Fetch a URL from nasa.gov. Throws on failure.
function nasa_fetch($url)
{
	$ch	= curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER	=> true,
		CURLOPT_FOLLOWLOCATION	=> true,
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

//Title from the API; some pages have none, so fall back to the page's first <h1>, then the slug.
function procurement_title($topic)
{
	$title	= trim(html_entity_decode(strip_tags($topic['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if($title !== '')
	{
		return $title;
	}
	try
	{
		$dom	= new DOMDocument;
		libxml_use_internal_errors(true);
		$dom->loadHTML(nasa_fetch($topic['link']));
		$h1	= $dom->getElementsByTagName('h1')->item(0);
		if($h1 && trim($h1->textContent) !== '')
		{
			return trim(preg_replace('/\s+/', ' ', $h1->textContent));
		}
	} catch(Throwable $e)
	{
		echo "Couldn't fetch page for title: ".$e->getMessage()."\n";
	}
	return ucwords(str_replace('-', ' ', (string)($topic['slug'] ?? 'Untitled procurement')));
}

//Count consecutive failed runs (hourly); alert at 3 and then once a day.
function track_nasa_health($conn, $ok, $error)
{
	if($ok)
	{
		mysqli_query($conn, "UPDATE status SET status_data = '0' WHERE status_item = 'nasa_failures'");
		return;
	}
	mysqli_query($conn, "UPDATE status SET status_data = CAST(status_data AS UNSIGNED) + 1 WHERE status_item = 'nasa_failures'");
	$res	= mysqli_query($conn, "SELECT status_data FROM status WHERE status_item = 'nasa_failures'");
	$row	= $res ? $res->fetch_object() : null;
	$fails	= $row ? (int)$row->status_data : 0;
	if($fails == 3 || ($fails > 3 && ($fails - 3) % 24 == 0))
	{
		pushbullet("NASA procurement monitor has failed $fails runs in a row. Last error: $error");
	}
}
