<?php
//Render an FCC license application that's passed at command-line
error_reporting(E_ALL);
include 'common.inc';

$application_id	= $argv[1];

if(empty($application_id))
{
	echo "Pass noticeId like 113 to script.\n";
	exit();
}

$sqlstring	= "SELECT url, application_description, application_purpose, application_address, application_explanation, applicant_name, DATE_FORMAT(application_pub_date, '%Y-%c-%d') AS application_pub_date, application_title, dates FROM fcc_applications WHERE application_id = '$application_id'";
echo $sqlstring."\n";
$result		= mysqli_query($conn, $sqlstring);
while ($row	= mysqli_fetch_assoc($result))
{
	$application_description	= $row['application_description'];
	$application_purpose		= wordwrap($row['application_purpose'], 70, "\n", false);;
	$applicant_name			= $row['applicant_name'];
	//Form 442 details are dense lists of part numbers (wider than prose), so they wrap narrower
	$is_form_442			= (strpos($row['url'], '/442_Print') !== false);
	$application_explanation	= wordwrap($row['application_explanation'], $is_form_442 ? 60 : 70, "\n", $is_form_442);
	$application_address		= $row['application_address'];
	$application_pub_date		= $row['application_pub_date'];
	$working_title			= extract_file_and_station($row['application_title']);
	$file_number			= $working_title[1][0];
	$callsign			= $working_title[2][0];
	$url				= $row['url'];
	$dates				= $row['dates'];
	

	$shortened_url			= "This is a generated summary, the complete\noriginal application can be read at:\n".get_short_url($url);

	echo "Debug:\nAddress: $application_address\nFile: $file_number\nCallsign: $callsign\n";

	//Truncate long text 
	$application_purpose		= truncate_lines($application_purpose, 15);
	$application_explanation	= truncate_lines($application_explanation, 15);
}

//Begin image rendering
$png_save_path		= "/home/chairboy/fccspacewatch/license_renders/";
$font_path		= "/home/chairboy/fccspacewatch/fonts/";
//Form 442 (new/modified license) gets its own template; built by make_442_template.php
$template_path		= $png_save_path.((!empty($is_form_442) && file_exists($png_save_path."form442_template.png")) ? "form442_template.png" : "sta_form.png");
//Optional 2nd argument: write the PNG somewhere else (for test renders)
$output_file		= !empty($argv[2]) ? $argv[2] : $png_save_path."$application_id.png";
echo "Template: $template_path\n";

//Define our workspace, 1600/2074 to match the template
$im			= imagecreatetruecolor(1600,2074);
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

//Calculate Text location for centered date field
$text_box	= imagettfbbox(26,0,$times, $application_pub_date);
$text_width	= $text_box[2]-$text_box[0];
$text_height	= $text_box[7]-$text_box[1];
$date_num_x	= (1380) - ($text_width/2); 
$date_num_y	= 144;

imagettftext($im, 26, 0, $date_num_x, $date_num_y, $black, $times, $application_pub_date);
imagettftext($im, 26, 0, 300,200, $black, $times, $applicant_name);
imagettftext($im, 26, 0, 1247, 199, $black, $times, $file_number);
imagettftext($im, 26, 0, 1247, 258, $black, $times, $callsign);
imagettftext($im, 26, 0, 1152, 477, $black, $times, $dates);
imagettftext($im, 26, 0, 77,262, $black, $times, $application_address);
imagettftext($im, 33, 0, 77,550, $black, $times, $application_purpose);
imagettftext($im, 33, 0, 77,1324, $black, $times, $application_explanation);
imagettftext($im, 26, 0, 885,325, $black, $times, $shortened_url);


//Overlay transparent working area on template
imagecopy	($template_image, $im, 0,0,0,0,$image_width, $image_height);
imagepng	($template_image, $output_file);

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