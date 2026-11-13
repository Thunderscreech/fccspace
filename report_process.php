<?php
//Build out the report processor function
//Function takes URL to report and returns salient details
//Applicant name, purpose of operation, operation start and end date
//Test URL: https://apps.fcc.gov/oetcf/els/reports/STA_Print.cfm?mode=current&application_seq=100425
error_reporting(E_ERROR | E_PARSE);

$testURL = "https://apps.fcc.gov/oetcf/els/reports/STA_Print.cfm?mode=current&application_seq=100425";
$html 	= file_get_contents($testURL);
$applicant_name	= get_applicant_name($html);
$purpose	= get_purpose($html);
$dates		= get_dates($html);
echo "Applicant: $applicant_name\n";
echo "Purpose: $purpose\n";
echo "Dates: $dates\n";
$tweet		= "Applicant: $applicant_name\nPurpose: $purpose\nDates: $dates\nDetails: $testURL";
echo 		"Tweet (".strlen($tweet)."): $tweet\n";

function get_applicant_name($html)
{	
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

function get_purpose($html)
{

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
	return "$start_date-$end_date";
}

?>