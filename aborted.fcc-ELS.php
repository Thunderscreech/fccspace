<?php
//Monitor FCC RSS feed for any new permits related to space activity
//https://apps.fcc.gov/oetcf/els/rss/NewGrants.cfm is feed
error_reporting(E_ERROR | E_PARSE);
include 'common.inc';
use DG\Twitter\Twitter;
require_once 'vendor/dg/twitter-php/src/twitter.class.php';


$url 			= "https://fcc.report/ELS.rss";
$report_url_header	= "https://apps.fcc.gov/oetcf/els";
$watchwords		= array('Spacex','Cubesat','type: Space'); //Check for any of these in a description
$badreports		= array('APPLICATION FOR NEW OR MODIFIED RADIO STATION','FCC FORM 405','APPLICATION FOR RENEWAL'); //We disregard applications with these strings
$tweet_count		= 0;
$invalidurl 		= false;

echo "Checking FCC applications...\n";

if(@simplexml_load_file($url))
{
	$feeds = simplexml_load_file($url);
}
else
{
	$invalidurl = true;
	echo "Invalid RSS feed URL";
}

//Update last run
$sqlstring	= "update status set status_data = now() where status_item = 'els_last_run'";
$result		= mysqli_query($conn,$sqlstring);// or die "Error running query: $sqlstring".mysqli_error($conn);

$i=0;
if(!empty($feeds))
{

	$site = $feeds->channel->title;
	$sitelink = $feeds->channel->link;

//	echo "Site: $site";
	foreach ($feeds->channel->item as $item) 
	{
		$title		= $item->title;	
		$link		= $report_url_header.(str_replace("..","",$item->link));
		$description	= $item->description;
		$postDate	= $item->pubDate;

		echo "T: $title\nD: $description\nL: $link\n\n";

		//$realReportURL	= getCurrentReport($link);


		//If the description contains a watchword, we process it
		if(containsWatchword($description, $watchwords))
		{
			//Matched a watchword
			echo "T:$title\nL:$link\nD:$description\nDt: $postDate\n";
			//Process this into database if we haven't already
			if(haveProcessedELSApp($conn, $title, $postDate))
			{
				//No action is required
				echo "Already in the system.\n";
			} else
			{
				//We have work to do processing this into the database and getting our tweet

				//We get the current report URL from which we'll extract our info
				if($current_report_url	= get_current_report_url($link))
				{
					//echo "Current report url: $current_report_url\n";
					//Load the HTML then pass it to the three functions to extract what we need
					$html			= file_get_contents($current_report_url);
					$applicant_name		= get_applicant_name($html);
					$report_type		= get_report_type($html);
					$purpose		= get_purpose($html);
					$dates			= get_dates($html);
					
					if(strlen($purpose) >= 85)
					{
						$purpose = substr($purpose,0,85)."(...)";
					}
					
					if(strlen($applicant_name) >= 46)
					{
						$applicant_name	= substr($applicant_name, 0, 45)."(...)";
					}

					if (containsWatchword($report_type, $badreports))
					{	
						echo "Skipping application as uninteresting: $purpose\n";
					} else
					{
	
						//echo "Report type: $report_type\n";
						echo "Applicant: $applicant_name\n";
						echo "Purpose: $purpose\n";
						echo "Dates: $dates\n";
	
						$tweet		= "Applicant: $applicant_name\nDates: $dates\nPurpose: $purpose\n$current_report_url";
						echo 		"Tweet (".strlen($tweet)."): $tweet\n\n";

						if(!empty($tweet) && !empty($applicant_name) && !empty($purpose))
						{
							//Tweet it 
							tweet($tweet);
							$tweet_count++;
							//Log it to prevent re-tweeting
							$sqlstring	= "INSERT INTO ELS (application_description, application_pub_date, application_title) VALUES ('$description',str_to_date('$postDate','%a, %d %b %Y %k:%i:%S EST'),'$title')";
							echo "USING SQL: $sqlstring\n";
							//$result		= mysqli_query($conn,$sqlstring);

						} else
						{
							//Log an error I guess
						}
					}
				} else
				{
					echo "Error getting current application for $link\n";
				}
//			if($tweet_count >=3)
//			{
//				exit;
//			}
			}
			
		} else
		{
			//Nothing to do, this is boring
		}


	}

	echo "Run complete.  $tweet_count tweets posted.";

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
	foreach ($rows as $row) 
	{
		$cols = $row->getElementsByTagName('td'); 
		if(strpos($cols->item(0)->nodeValue,'Start Date'))
		{
			$start_date	= $cols->item(1)->nodeValue;
		}
		if(strpos($cols->item(0)->nodeValue,'End Date'))
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
		echo "0:".$cols->item(0)->nodeValue."\n";
		echo "1:".$cols->item(1)->nodeValue."\n";
		echo "2:".$cols->item(2)->nodeValue."\n";
		echo "3:".$cols->item(3)->nodeValue."\n";
		echo "4:".$cols->item(4)->nodeValue."\n";
		echo "5:".$cols->item(5)->nodeValue."\n";
		echo "6:".$cols->item(6)->nodeValue."\n";
		echo "7:".$cols->item(7)->nodeValue."\n";
		echo "8:".$cols->item(8)->nodeValue."\n";
		echo "9:".$cols->item(9)->nodeValue."\n";
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


function haveProcessedELSApp($conn, $title, $postDate)
{
	$sqlstring	= "SELECT count(*) as application_count FROM ELS WHERE application_title= '$title'";
	if(mysqli_query($conn,$sqlstring)->fetch_object()->application_count >= 1)
	{
		//This is already in the system and no action is requires
		$return	= true;
	} else
	{
		$return = false;
	}

	return $return;
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

function tweet($tweet_text)
{
	//Post a tweet as FCCSpace
	//Oauth keys

//	$consumerKey = bot_cfg('twitter', 'consumer_key');
//	$consumerSecret =bot_cfg('twitter', 'consumer_secret');
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