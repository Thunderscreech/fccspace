<?php

require 'tootophp/autoload.php';

// Setting up your instance
$tootoPHP = new TootoPHP\TootoPHP('social.deltayeet.net');

// Setting up your App name and your website
$app = $tootoPHP->registerApp('FCCSpace', 'http://deltayeet.net');
if ( $app === false) {
    throw new Exception('Problem during register app');
}

echo $app->getAuthUrl()."\n";

// Copy this URL and open it.
// Allow App to use Mastodon and copy the given token