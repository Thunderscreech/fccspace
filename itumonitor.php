<?php
//Read https://www.itu.int/ITU-R/space/asreceived/Publication/AsReceived for Notification Of Space Station entries
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

echo "Running ITU monitor...";
$url	= "https://www.itu.int/ITU-R/space/asreceived/Publication/AsReceived";
$dom	= new domDocument;
$html	= file_get_contents($url);

if(!$html)
{
	dy_discord("ITU Monitor string was empty");
	exit();
}	


libxml_use_internal_errors(true);
$dom->loadHTML($html);

//Update last run
$sqlstring	= "update status set status_data = now() where status_item = 'itu_lastrun'";
$result		= mysqli_query($conn,$sqlstring);// or die "Error running query: $sqlstring".mysqli_error($conn);


echo "Checking ITU licenses...\n";

$table = $dom->getElementsByTagName('table')->item(0);

foreach($table->getElementsByTagName('tr') as $tr)
{
	$submission_id	= $tr->getAttribute("data-submission-id");
	$tds	= $tr->getElementsByTagName('td');
	if($tds->length >= 1)
	{
		$ntd_id		= $tds->item(0)->nodeValue;
		$adm		= $tds->item(1)->nodeValue;
		$network_org	= $tds->item(2)->nodeValue;
		$station_name	= $tds->item(3)->nodeValue;
		$long_nom	= $tds->item(4)->nodeValue;
		$br_reg_date	= $tds->item(5)->nodeValue;
		$submission_type= $tds->item(6)->nodeValue;
		$reg		= $tds->item(7)->nodeValue;
		$act_code	= $tds->item(8)->nodeValue;

		//If it is a Notification of Space Station
		if(strpos($submission_type,'Space Station'))
		{
			//Check to see if it is in the database yet by comparing submission number
			$sqlstring	= "SELECT COUNT(*) AS submission_count FROM itu_filings WHERE submission_id = '$submission_id'";
			//echo "$sqlstring\n";
			if(mysqli_query($conn,$sqlstring)->fetch_object()->submission_count >= 1)
			{
				//No action required, they exist in the table
			} else
			{
				//Add it and act on it
				$url	= "https://www.itu.int/ITU-R/space/asreceived/Publication/DisplayPublication/$submission_id";
//				echo "$ntd_id - $station_name at $long_nom filed $br_reg_date\n$url";
				$sqlstring	= "INSERT INTO itu_filings (ntd_id, submission_type, submission_id, station_name, longitude, br_reg_date, org_name) VALUES ('$ntd_id', '$submission_type','$submission_id','$station_name','$long_nom',str_to_date('$br_reg_date','%d.%m.%Y'),'$adm')";
				echo $sqlstring."\n";
				$result		= mysqli_query($conn, $sqlstring);
				if(1 === preg_match('~[0-9]~',$long_nom))
				{
					echo "Longitude: $long_nom\n";
					$lat_text	= " to be located at $long_nom";
				}
	
				$render_string		= "php /home/chairboy/fccspacewatch/render_itu_filing.php $submission_id";
				echo 			"$render_string\n";
				exec			("$render_string");

				$tweet	= "The ITU has received a Satellite Network filing for $station_name ($adm) $lat_text\n$url";
				echo "(".strlen($tweet).") $tweet\n";
				//$return_val		= twute($conn, $tweet, array("/home/chairboy/fccspacewatch/license_renders/$submission_id.png"), '');
				$mastodon_post		= mastodon_post($tweet, "/home/chairboy/fccspacewatch/license_renders/$submission_id.png");	
				//Below is data-submission-id tag value
				//https://www.itu.int/ITU-R/space/asreceived/Publication/DisplayPublication/24397
			}

		} else
		{
			//We don't care

		}


	}

//	var_dump($tr);

}
echo "Done.";

//Do we have this report
function is_report_onfile($conn,$file_number)
{
	$sqlstring	= "SELECT count(*) as report_count FROM ibfs WHERE file_number = '$file_number'";
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
function get_company_name($html)
{
	//Find h2 and read for title
	$dom	= new domDocument;
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);	
	$dom->preserveWhiteSpace = false;
	foreach($dom->getElementsByTagName('h2') as $header)
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
	$dom->preserveWhiteSpace = false;
	$div	= $dom->getElementsByTagName('div');

	foreach($div as $tag)
	{
		if($tag->getAttribute('class') === 'jumbotron')
		{
			$description	= explode("[GOV]",$tag->nodeValue,2)[1];
			$description	= explode("Filing:",$description,2)[0];
			$description	= explode("See attach",$description,2)[0];
			$description	= explode("See exhibi",$description,2)[0];
			$description	= explode("See Attach",$description,2)[0];
			$description	= explode("See Exhibi",$description,2)[0];
			$description	= explode("Please see attached",$description,2)[0];
			$description	= explode("See narrative",$description,2)[0];

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
		//$twitter->send("$tweet_text");
	} catch (DG\Twitter\TwitterException $e) 
	{
		echo 'Error: ' . $e->getMessage();
	}
}


?>