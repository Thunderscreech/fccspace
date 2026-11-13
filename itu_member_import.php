<?php
//Import view-source:https://www.itu.int/gladapp/Administration/Members to database

include 'common.inc';

$url	= "https://www.itu.int/gladapp/Administration/Members";
$dom	= new domDocument;
$html	= file_get_contents($url);
libxml_use_internal_errors(true);
$dom->loadHTML($html);

$table = $dom->getElementsByTagName('table')->item(0);

foreach($table->getElementsByTagName('tr') as $tr)
{

	$tds	= $tr->getElementsByTagName('td');
	if($tds->length >= 1)
	{
		$symbol		= $tds->item(0)->nodeValue;
		$designation	= mysqli_real_escape_string($conn, $tds->item(1)->nodeValue);
		$region		= $tds->item(2)->nodeValue;
//		echo "($symbol) $designation ($region)\n";

		$sqlstring	= "INSERT INTO itu_members (symbol, designation, region) VALUES ('$symbol', '$designation', '$region');";
		echo "$sqlstring\n";
		$result		= mysqli_query($conn, $sqlstring);
	}
}


?>