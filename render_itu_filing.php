<?php
//Render an FCC license application that's passed at command-line
error_reporting(E_ALL);
include 'common.inc';

$submission_id	= $argv[1];

if(empty($submission_id))
{
	echo "Pass submission_id like 113 to script.\n";
	exit();
}

$sqlstring	= "SELECT ntd_id, submission_id, submission_type, designation, region, org_name, station_name, longitude, br_reg_date FROM itu_filings, itu_members WHERE itu_members.symbol = itu_filings.org_name AND submission_id = '$submission_id'";
echo $sqlstring."\n";
$result		= mysqli_query($conn, $sqlstring);
while ($row	= mysqli_fetch_assoc($result))
{
	$org_name			= $row['org_name'];
	$station_name			= $row['station_name'];
	$longitude			= $row['longitude'];
	$br_reg_date			= $row['br_reg_date'];
	$ntd_id				= $row['ntd_id'];
	$submission_id			= $row['submission_id'];
	$submission_type		= $row['submission_type'];
	$designation			= $row['designation'];
	$region				= $row['region'];
}

//Convert longitude
echo "Longitude: $longitude\n";
preg_match('/(\d*)/', $longitude, $output_array);
$long	= $output_array[0];
if(str_contains($longitude, 'W'))
{
	$long = $long * -1;
}

//Begin image rendering
$png_save_path		= "/home/chairboy/fccspacewatch/license_renders/";
$font_path		= "/home/chairboy/fccspacewatch/fonts/";
$template_path		= $png_save_path."itu_filing.png";
$save_file_loc 		= "/home/chairboy/fccspacewatch/license_renders/$submission_id-map.png";

echo "Template: $template_path\n";

if (!empty($long))
{
	$uestation	= urlencode($station_name);
	$marker	= "&markers=anchor:top|icon:https://deltayeet.net/itu_sat_icon.png|color:blue%7Clabel:$uestation%7C0,$long&";
	echo "Marker set: $long\n";
	$longtext	= $longitude;
} else
{
	$marker	= "&";	
	echo "marker not set: $long\n";
	$longtext	= "UNK";
}

//Render a map using Google API
$map_string	= "https://maps.googleapis.com/maps/api/staticmap?center=0,$long&zoom=1&size=600x300&maptype=satellite$marker&key=" . bot_cfg('google', 'maps_api_key') . "";
echo "Map: $map_string\n";
//IP limited to our server
$image 		= file_get_contents($map_string);
file_put_contents($save_file_loc, $image);
//exec		("convert $save_file_loc /home/chairboy/tfrmonitor/maps/$png_name 2> /dev/null");


//Define our workspace, 640/566 to match the template
$im			= imagecreatetruecolor(640,566);
$google_map_image	= imagecreatefrompng($save_file_loc);
$template_image		= imagecreatefrompng($template_path);

if(!$template_image)
{
	echo "Error loading template image\n";
}

var_dump($template_image);

imagealphablending	($template_image, true); 
$image_width		= imagesx($im); //Keeping this dynamic for future resize
$image_height		= imagesy($im);

//Colors used
$black 		= imagecolorallocate($im, 0, 0, 0);
$white 		= imagecolorallocate($im, 255, 255, 255);
$transparent 	= imagecolorallocatealpha($im, 255, 255, 255, 127);
$font		= $font_path."Product_Sans_Bold.ttf";
$font_blk	= $font_path."coolvetica_rg.ttf";
$arial		= $font_path."arial.ttf";
$times		= $font_path."Product_Sans_Regular.ttf";

//Create workspace
imagealphablending	($im, false);
imagesavealpha		($im, true);
imagefilledrectangle($im, 0, 0, $image_width, $image_height, $transparent);
	
imagettftext($im, 20, 0, 166,229, $black, $times, $station_name);
imagettftext($im, 20, 0, 555,229, $black, $times, $longtext);
imagettftext($im, 20, 0, 473,120, $black, $times, $br_reg_date);
imagettftext($im, 20, 0, 167,120, $black, $times, $ntd_id);
imagettftext($im, 20, 0, 535,85,  $black, $times, $submission_id);
imagettftext($im, 20, 0, 104,156, $black, $times, $submission_type);
imagettftext($im, 20, 0, 127,192, $black, $times, $designation);

//Overlay template on map so it shows through transparent window
echo "File path for map: $save_file_loc\n";




//Overlay transparent working area on template
imagecopy	($im, $google_map_image, 21,237,0,0, 600, 298);
imagecopy	($template_image, $im, 0,0,0,0,$image_width, $image_height);
imagepng	($template_image, $png_save_path."$submission_id.png");

function extract_file_and_station($input_lines)
{
	//This should return an array where [1] has file number and [2] station id
	preg_match_all('/File Number: (.*), Callsign: (.*)/', $input_lines, $output_array);
	return($output_array);
}

function truncate_lines($text, $number_of_lines)
{
	$lines_of_text			=substr_count($text,"\n");
	if ($lines_of_text 		>= $number_of_lines)
	{
		$working_text		= explode("\n", $text);
		$working_text		= array_slice($working_text,0,($number_of_lines-1));
		$working_text[]		= "[ Text truncated, visit full application link above for full copy ]";
		$return_text		= implode("\n", $working_text);
		echo "Truncated text, $lines_of_desc found with $number_of_lines max\n";
	} else
	{
		$return_text		= $text;
	}

	return $return_text;

}

function get_short_url($url)
{
	return file_get_contents('http://tinyurl.com/api-create.php?url='.$url);
}

echo "End of line.\n";

?>