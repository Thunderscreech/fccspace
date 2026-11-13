<?php

include 'common.inc';

$tweet	= "Test in progress, please disregard.  This should not hit the main feed.";

$return_val		= new_tweet($conn, $tweet, array(""), '');

?>