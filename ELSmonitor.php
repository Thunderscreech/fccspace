<?php
//Read https://fcc.report/IBFS/ for Satellite Space Station entries
error_reporting(E_ERROR | E_PARSE);
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


$url	= "https://fcc.report/ELS/";
$dom	= new domDocument;
$html	= file_get_contents($url);
libxml_use_internal_errors(true);
$dom->loadHTML($html);

//Update last run
$sqlstring	= "update status set status_data = now() where status_item = 'els_last_run'";
$result		= mysqli_query($conn,$sqlstring);// or die "Error running query: $sqlstring".mysqli_error($conn);

$interesting_companies	= array("SpaceX");

echo "Checking ELS licenses...\n";

$table = $dom->getElementsByTagName('table')->item(0);

foreach($table->getElementsByTagName('tr') as $tr)
{
	$aerospace	= array("Astra", "Astra Space","SpaceX","United Launch Alliance, LLC","Space Exploration Technologies Corp","Space Exploration Technologies Corp.","Space Explorations Technologies","Space Exploration Technologies Corp. (SpaceX)","Space Exploration Holdings, LLC","Near Space Launch Inc.","Globalstar, Inc","Blue Origin Texas, LLC","Firefly Aerospace","Iridium Satellite, LLC", "Blue Origin, LLC","Sierra Nevada Corporation");
	$tds		= $tr->getElementsByTagName('td');

	$element0	= trim($tds->item(0)->nodeValue);
	$workingarray	= explode("\n", $element0);
	$last_update	= $workingarray[0];
	$file_number	= $workingarray[1];
	$workingarray	= explode("\n", $tds->item(1)->nodeValue);
	$applicant	= $workingarray[0];
	$filing_type	= $workingarray[1];
	echo "$last_update: $file_number - $applicant\n";
//	if(strpos($applicant, "SpaceX"))
	if(in_array($applicant, $aerospace))
	{
		if(is_report_onfile($conn,$file_number))
		{
			//It's in the database, no action required
			//echo "$file_number already in system, skipping.\n";
		} else
		{

			$url		= "https://fcc.report".$tds->item(0)->getElementsByTagName('a')->item(0)->getAttribute('href');
			echo "URL IS: $url\n";
			$html		= file_get_contents($url);
			$company	= get_company_name($html);
			unset($description);
			unset($tweet);
			unset($tweet2);
			$description	= trim(get_description($html));
			$lendelta	= strlen($company)-18;

			$description	= substr($description,0,175-$lendelta);
			if(strlen($description) >= 175)
			{
				$description = $description."(...)";
			}
			$tweet		= "$company has filed $url\n";
			$tweet2		.= "Purpose: $description";
			if(strlen($tweet2) >= 170)
			{
				$second	= substr($tweet2, 0, 170)."(...)";
			} else
			{
				//No change
			}
			$tweet		.= $tweet2;
			//echo "(".strlen($tweet)."): $tweet\n\n";
			echo "Tweeting: (".strlen($tweet).") $tweet\n";
			//tweet($tweet);
			//$tweet_stat	= twute($conn, $tweet, array(''), '');
			$mastodon_post	= mastodon_post($tweet,"");
			$sqlstring	= "INSERT INTO ELS (application_title, application_pub_date, company_name) VALUES ('$file_number',str_to_date('$last_update','%Y-%m-%d'),'$company')";
			$result		= mysqli_query($conn, $sqlstring);
			echo "$sqlstring\n";
		}
		
	}
	
	

//	echo 	"$last_update: $file_number - $filing_type\n";
//	echo "$element0\n";	
//	echo "1:".$tds->item(1)->nodeValue."\n";
//	echo "2:".$tds->item(2)->nodeValue."\n";

}
echo "Done.";

//Do we have this report
function is_report_onfile($conn,$file_number)
{
	$sqlstring	= "SELECT count(*) as report_count FROM ELS WHERE application_title = '$file_number'";
	if(mysqli_query($conn,$sqlstring)->fetch_object()->report_count >= 1)
	{
		//This is already in the system and no action is requires
		$return	= true;
	} else
	{
		$return = false;
	}

	return $return;
}

//Get the company
//This extracts actual name, but we will just use SpaceX for now because that's what we're currently looking for
function get_company_name($html)
{
	//Find h1 and read for title
	$dom	= new domDocument;
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);	
	$dom->preserveWhiteSpace = false;
	foreach($dom->getElementsByTagName('h1') as $header)
	{
		$company_name	= $header->nodeValue;
		//echo $company_name."\n";
	}

$application_titles	= array(
'Application for Earth Exploration Satellite Service Mobile Satellite Service Other by ',
'Application for Fixed Satellite Service by ',
'Application for Direct to Home Fixed Satellite Fixed Satellite Service by ',
'Satellite Space Stations ',
'Application for Fixed Satellite Service Other by ',
'Application for Earth Exploration Satellite Service Other by',
'Application for Earth Exploration Satellite Service by');

	foreach($application_titles as $filter)
	{
		$company_name = str_replace($filter,"",$company_name);
	}


	if(isset($company_name))
	{
		return $company_name;
	} else
	{
		return false;
	}
}

function get_description($html)
{
	//Find the right DIV and <p> inside of it for the description
	$dom	= new domDocument;
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);	
	$dom->preserveWhiteSpace 	= false;
	$fieldset			= $dom->getElementsByTagName('fieldset');

	foreach($fieldset as $fieldsetitem)
	{
		if($fieldsetitem->getAttribute('title') === 'Purpose of Operation')
		{
			echo "Matched the Purpose of operation fieldset\n";
			$td		= $fieldsetitem->getElementsByTagName('td');
			foreach ($td as $td_item)
			{
				echo "Class: ".$td_item->getAttribute('class')."\n";
				if($td_item->getAttribute('class') === 'small-content')
				{
					$description	= $td_item->nodeValue;
					echo "Description: $description\n";
				}
			}

	
			//echo $description."\n";
		}

	}

	if(isset($description))
	{
		return $description;
	} else
	{
		return false;
	}
}



//Loop through table, get links to files with a type of 
function get_current_report_url($url)
{	
	$dom	= new domDocument;
	$html	= file_get_contents($url);
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	$dom->preserveWhiteSpace = false; 
	foreach($dom->getElementsByTagName('a') as $anchor)
	{
		$link	= $anchor->getAttribute('href');
		if (strpos($link,"mode=current"))
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

function tweet($tweet_text)
{
	//Post a tweet as FCCSpace
	//Oauth keys

	$consumerKey = bot_cfg('twitter', 'consumer_key');
	$consumerSecret =bot_cfg('twitter', 'consumer_secret');
	$accessToken = bot_cfg('twitter', 'access_token');
	$accessTokenSecret = bot_cfg('twitter', 'access_token_secret');

	$twitter = new Twitter($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);
	echo "Tweeting: $tweet_text\n";
	try {
		$twitter->send("$tweet_text");
	} catch (DG\Twitter\TwitterException $e) 
	{
		echo 'Error: ' . $e->getMessage();
	}
}


?>