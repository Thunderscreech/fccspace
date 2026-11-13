<?php
//2026-11-11: One-time generator for license_renders/form442_template.png, the Form 442
//(new/modified experimental license) version of sta_form.png. It patches blank paper texture
//over the STA-specific headings and draws new ones. Re-run if sta_form.png ever changes.

$dir	= '/home/chairboy/fccspacewatch/';
$src	= $dir.'license_renders/sta_form.png';
$dst	= $dir.'license_renders/form442_template.png';
$bold	= $dir.'fonts/Product_Sans_Bold.ttf';
$reg	= $dir.'fonts/Product_Sans_Regular.ttf';

$im	= imagecreatefrompng($src);
imagealphablending($im, true);
imagesavealpha($im, true);
$ink	= imagecolorallocate($im, 0, 0, 0);

//Blank paper is taken from the empty middle of the "purpose" box
$paper_x	= 70;
$paper_y	= 760;

//Cover a rectangle with blank paper texture
function patch($im, $x, $y, $w, $h, $px, $py)
{
	imagecopy($im, $im, $x, $y, $px, $py, $w, $h);
}

//Draw text centered horizontally between $x1 and $x2, with its baseline at $baseline
function centered($im, $size, $font, $text, $x1, $x2, $baseline, $color)
{
	$box	= imagettfbbox($size, 0, $font, $text);
	$w	= $box[2] - $box[0];
	imagettftext($im, $size, 0, (int)(($x1 + $x2 - $w) / 2), $baseline, $color, $font, $text);
}

//1) Title block, between the FCC logo and the DATE FILED column
patch($im, 225, 52, 985, 100, $paper_x, $paper_y);
centered($im, 25, $bold, 'FEDERAL COMMUNICATIONS COMMISSION', 225, 1210, 93, $ink);
centered($im, 25, $bold, 'EXPERIMENTAL RADIO LICENSE (FORM 442)', 225, 1210, 140, $ink);

//2) Lower section heading
patch($im, 150, 1211, 1300, 50, 150, $paper_y); //stays clear of the box borders
centered($im, 23, $reg, 'LICENSE DETAILS: LOCATIONS, FREQUENCIES AND EQUIPMENT', 52, 1550, 1250, $ink);

imagepng($im, $dst);
echo "Wrote $dst\n";
