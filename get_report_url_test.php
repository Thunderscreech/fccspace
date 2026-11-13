<?php
//Work out how to get URL to current report here
//https://apps.fcc.gov/oetcf/els/reports/GetApplicationInfo.cfm?id_file_num=0798-EX-ST-2020
error_reporting(E_ERROR | E_PARSE);

$testURL	= "https://apps.fcc.gov/oetcf/els/reports/GetApplicationInfo.cfm?id_file_num=0798-EX-ST-2020";
$html 		= file_get_contents($testURL);

$current_url	= get_current_url($html);

echo "Current url: $current_url\n";


function get_current_url($html)
{
	$dom	= new domDocument;
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



?>