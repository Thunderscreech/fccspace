<?php

//Monitor https://procurement.jsc.nasa.gov/ for new procurement pages being listed

include 'common.inc';

$url	= "https://procurement.jsc.nasa.gov/";
$dom	= new domDocument;
$html	= file_get_contents($url);
libxml_use_internal_errors(true);
$dom->loadHTML($html);
$dom->preserveWhiteSpace = false; 
$links 	= $dom->getElementsByTagName('a'); 

foreach($links as $link)
{
	$linkHref	= $link->getAttribute('href');
	$linkName	= trim($link->nodeValue);
	if($linkHref[0] == '/' && $linkHref[strlen($linkHref) - 1] == '/')
	{
//		$linkHref	= "https://procurement.jsc.nasa.gov".$linkHref;
//		echo "L: $linkName is https://procurement.jsc.nasa.gov$linkHref\n";
		$sqlstring	= "SELECT count(*) as numResults FROM nasa_procurement WHERE procurement_url = 'https://procurement.jsc.nasa.gov$linkHref'";
		$numResults	= mysqli_query($conn, $sqlstring)->fetch_object()->numResults;
	
		if($numResults >= 1)
		{
			//Nothing to do
			echo "$linkName project known to us\n";
		} else
		{
			//New entry!
			echo "$linkName is a new project to us!\n";
			$sqlstring	= "INSERT INTO nasa_procurement (procurement_title, procurement_url) VALUES ('$linkName', 'https://procurement.jsc.nasa.gov$linkHref')";
//			echo "$sqlstring\n";
			$result		= mysqli_query($conn, $sqlstring);
			pushbullet("A new NASA Procurement has posted: $linkName at https://procurement.jsc.nasa.gov$linkHref");
		}

	} 

}

echo "End of line\n";
?>