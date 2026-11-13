<?php

//Monitor https://www.faa.gov/data_research/commercial_space_data/licenses/ launch license status and report on changes
//faalaunchlicenses: license, license_url, company, vehicle, location, expiration
//echo "1\n";
//error_reporting(E_ERROR | E_PARSE);
include 'common.inc';
use DG\Twitter\Twitter;
require_once 'vendor/dg/twitter-php/src/twitter.class.php';

//Check if I'm supposed to be running
$sqlstring	= "SELECT status_data FROM status WHERE status_item = 'active'";
$active		= mysqli_query($conn,$sqlstring)->fetch_object()->status_data;
if($active == '1')
{
	//No action required, we are on like kong
} else
{
	echo "According to status.status_item.active, I am supposed to be asleep.  Exiting...\n";
	exit();
}


//echo "Beginning FAA launch license check...\n";

$url 	= "https://www.faa.gov/data_research/commercial_space_data/licenses/";
$dom	= new domDocument;
$html	= file_get_contents($url);

if(empty($html))
{
	echo "Empty string, aborting.\n";
	//dy_discord("Empty string on FAA License, no further action required if isolated.");
	exit();
}

libxml_use_internal_errors(true);
$dom->loadHTML($html);

//Update last run
$sqlstring	= "update status set status_data = now() where status_item = 'faa_launch_license_lastrun'";
$result		= mysqli_query($conn,$sqlstring);// or die "Error running query: $sqlstring".mysqli_error($conn);

$counter = 0;

//We're examining the second table in the page
$table	= $dom->getElementsByTagName('table')->item(0);
foreach($table->getElementsByTagName('tr') as $tr)
{
	$td	= $tr->getElementsByTagName('td');

	$counter++;

	if($counter >= 2)
	{
		$license	= trim($td->item(0)->nodeValue);
		$url		= "https://www.faa.gov/".$td->item(0)->getElementsByTagName('a')->item(0)->getAttribute('href');
		$company	= trim($td->item(1)->nodeValue);
		$vehicle	= trim($td->item(2)->nodeValue);
		$location	= trim($td->item(3)->nodeValue);
		$expiration_w	= trim($td->item(4)->nodeValue);
		list($expiration,$expiration2)	= explode("\n",$expiration_w);
		$expiration2	= trim($expiration2);
		$expiration	= trim($expiration);

//		echo "L: $license - URL: $url\nC: $company - V: $vehicle - L: $location - E: $expiration\n";

		//Does it exist?
		$sqlstring	= "SELECT count(*) as l_count FROM faalaunchlicenses WHERE license = '$license' AND vehicle = '$vehicle' and location = '$location'"; 
//		echo "$sqlstring\n";
		if(mysqli_query($conn, $sqlstring)->fetch_object()->l_count < 1)
		{
			//There is no existing license for this vehicle and location
			echo "No license has been previously logged for $vehicle launching from $location.  Adding.\n";
			if(str_contains($expiration, "Details"))
			{
				$expiration	= "Dec 31, 2034";//Just cram a random date in
			}
			$sqlstring	= "INSERT INTO faalaunchlicenses (license, license_url, company, vehicle, location, expiration) VALUES ('$license','$url','$company','$vehicle','$location',STR_TO_DATE('$expiration','%Y%m%d'))";
			echo "$sqlstring\n";
			$result		= mysqli_query($conn, $sqlstring);
			pushbullet("New launch vehicle license filing with FAA for $vehicle launching from $location. URL: $url");
			$tweet_string	= "Note: There is a new launch vehicle license filing/revision with the FAA for $vehicle launching from $location. Details: $url";
			//$return_val		= twute($conn, $tweet_string, array(""), '');
			$mastodon_post		= mastodon_post($tweet_string, array(""));
			//exec("php /home/chairboy/Nostradambot/manual_text.php 754432168293433354 \"New launch vehicle license filing with FAA for $vehicle launching from $location. URL: $url\"");


		} else
		{
			//Existing license
			$sqlstring	= "UPDATE faalaunchlicenses SET last_updated = NOW() WHERE vehicle = '$vehicle' and location = '$location'";
			$result		= mysqli_query($conn, $sqlstring);		}

		//- Has the expiration date changed?
		$sqlstring	= "SELECT COUNT(*) as expiration_count FROM faalaunchlicenses WHERE license = '$license' AND vehicle = '$vehicle' AND location = '$location' AND expiration = STR_TO_DATE('$expiration','%Y%m%d')";
		if(mysqli_query($conn, $sqlstring)->fetch_object()->expiration_count < 1)
		{
			//The expiration date has changed
			echo "The expiration date for the $vehicle license out of $location has changed to $expiration2\n";
			//TODO alert on change in expiration date
			//TODO update expiration date
			$sqlstring	= "UPDATE faalaunchlicenses SET expiration = STR_TO_DATE('$expiration','%Y%m%d') WHERE vehicle = '$vehicle' AND location = '$location' AND license = '$license'";
			pushbullet("Updated expiration for $vehicle/$location.  Now $expiration2.");
			echo "$sqlstring\n";
			$result		= mysqli_query($conn, $sqlstring);
		} else
		{
			//It has not changed, boring
		}

		//Has the license been updated?
		$sqlstring	= "SELECT COUNT(*) AS license_count FROM faalaunchlicenses WHERE vehicle = '$vehicle' AND location = '$location' AND license = '$license'";
		if(mysqli_query($conn, $sqlstring)->fetch_object()->license_count < 1)
		{
			//It has changed!
			echo "The license has been changed for $vehicle launching out of $location.\n";
			//TODO Alert on this
			//Update record
		} else
		{
			//It has not changed.  BORING.
		}
		
		//If there's a change, then tweet
		//Update the last_updated field regardless
	}
}


//Do a check to see if the last-update is old and alert on entries that are no longer here then update an announce flag re: it being gone

//If it is an expiration date, tweet about that

function notify_offnom($message)
{
	exec("php /home/chairboy/Nostradambot/manual_text.php 754432168293433354 \"$message\"");
}

?>