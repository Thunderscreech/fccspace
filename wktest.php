<?php

$url	= "https://apps.fcc.gov/oetcf/els/reports/STA_Print.cfm?mode=current&application_seq=107134";
$render_string	= "/home/chairboy/fccspacewatch/render_license.sh \"$url\" 78";

exec($render_string);

?>