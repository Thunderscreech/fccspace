<?php
//Generic off script for deltayeet commander
include 'common.inc';

$sqlstring 	= "UPDATE status SET status_data = '1' WHERE status_item = 'active'";
$result		= mysqli_query($conn, $sqlstring);
echo "Script has been turned on.\n";

?>