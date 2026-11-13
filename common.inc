<?php
echo ".";
include('vendor/autoload.php');
use Abraham\TwitterOAuth\TwitterOAuth;
require 'tootophp/autoload.php';

// Credentials and private settings live in config.ini next to this file (not in git; see config.example.ini).
// bot_cfg('section', 'key') returns the value as a string ('' when unset).
function bot_cfg($section, $key)
{
	static $cfg = null;
	if ($cfg === null) {
		$file	= __DIR__ . '/config.ini';
		$cfg	= is_readable($file) ? parse_ini_file($file, true, INI_SCANNER_RAW) : false;
		if (!is_array($cfg)) {
			error_log("cannot read $file (copy config.example.ini and fill it in)");
			die("Missing or unreadable config.ini\n");
		}
	}
	return (string)($cfg[$section][$key] ?? '');
}

//DB connect - do it every time
$dbhost = bot_cfg('database', 'host');
$dbuser = bot_cfg('database', 'user');
$dbpass = bot_cfg('database', 'password');

$conn = mysqli_connect($dbhost, $dbuser, $dbpass) or die ('Error connecting to mysql');

$dbname = bot_cfg('database', 'name');
mysqli_select_db($conn, $dbname) or die("Error selecting DB");



/*::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::*/
/*::                                                                         :*/
/*::  This routine calculates the distance between two points (given the     :*/
/*::  latitude/longitude of those points). It is being used to calculate     :*/
/*::  the distance between two locations using GeoDataSource(TM) Products    :*/
/*::                                                                         :*/
/*::  Definitions:                                                           :*/
/*::    South latitudes are negative, east longitudes are positive           :*/
/*::                                                                         :*/
/*::  Passed to function:                                                    :*/
/*::    lat1, lon1 = Latitude and Longitude of point 1 (in decimal degrees)  :*/
/*::    lat2, lon2 = Latitude and Longitude of point 2 (in decimal degrees)  :*/
/*::    unit = the unit you desire for results                               :*/
/*::           where: 'M' is statute miles (default)                         :*/
/*::                  'K' is kilometers                                      :*/
/*::                  'N' is nautical miles                                  :*/
/*::  Worldwide cities and other features databases with latitude longitude  :*/
/*::  are available at https://www.geodatasource.com                          :*/
/*::                                                                         :*/
/*::  For enquiries, please contact sales@geodatasource.com                  :*/
/*::                                                                         :*/
/*::  Official Web site: https://www.geodatasource.com                        :*/
/*::                                                                         :*/
/*::         GeoDataSource.com (C) All Rights Reserved 2018                  :*/
/*::                                                                         :*/
/*::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::*/

function old_mastodon_post($post_text, $img)
{
	//$media_array is an array of filespec
	///var/www/html/mastodon/23423401-1.png
	///var/www/html/mastodon/23423401-2.png
	///var/www/html/mastodon/23423401-3.png 
	//Currently, it's only working with a single filespec

	$tootoPHP = new TootoPHP\TootoPHP('social.deltayeet.net');
	$app = $tootoPHP->registerApp('FCCSpace', 'http://deltayeet.net');

//	//Media loop here TODO
///	foreach($img_array as $key => $filespec)
//	{
//		$working_array[]	= $app->createAttachement($filespec);
//		$media_ids[]		= $working_array[$key]['id'];
//	}

	$img_id	= $app->createAttachement("$img");
	echo "Mastodon attach: $img\nMastodon ID: $img_id\n";

	//$media_ids	= array(

	echo "Posting...\n";
	$response	= $app->PostStatus($post_text, 'public', $img_id['id']);

	//TODO: Discord notification if post failure

	return $response['id'];
}

function mastodon_post($post_text, $img = null)
{
    // Build the command string
    $command = buildMastodonCommand($post_text, $img);
    if ($command === false) {
        // exec(false) is a fatal ValueError on PHP 8
        return false;
    }

    // Output the command for debugging
    echo "Executing command: $command\n";

    // Execute the command and capture the output and return status
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);

    // Check for errors
    if ($return_var !== 0) {
        echo "Command failed with status $return_var. Output:\n";
        print_r($output);
        return false;  // Indicate failure
    }

    echo "Command executed successfully:\n";
    print_r($output);

    return true;  // Indicate success
}

//2026-11-11: Post to Bluesky with optional image. Credentials live in bluesky.conf next to this file:
//   handle = "yourbot.bsky.social"
//   app_password = "xxxx-xxxx-xxxx-xxxx"
//If that file is missing or incomplete, this logs and returns null (not configured) without failing the run.
//Returns true on success, false on a failed post (worth retrying).
function bluesky_post($post_text, $img = null)
{
	$conf_file	= __DIR__.'/bluesky.conf';
	if(!is_readable($conf_file))
	{
		echo "Bluesky: $conf_file not found, skipping Bluesky post.\n";
		return null;
	}
	$conf	= parse_ini_file($conf_file);
	if(empty($conf['handle']) || empty($conf['app_password']))
	{
		echo "Bluesky: handle/app_password missing in $conf_file, skipping.\n";
		return null;
	}

	$pds	= 'https://bsky.social';

	//Log in
	$session	= bluesky_request("$pds/xrpc/com.atproto.server.createSession", 'application/json',
				json_encode(array('identifier' => $conf['handle'], 'password' => $conf['app_password'])));
	if(empty($session['accessJwt']))
	{
		echo "Bluesky: login failed: ".json_encode($session)."\n";
		return false;
	}
	$jwt	= $session['accessJwt'];

	//Bluesky caps posts at 300 characters. Keep the trailing URL line intact and trim the body.
	$limit	= 300;
	if(mb_strlen($post_text) > $limit)
	{
		$lines	= explode("\n", $post_text);
		$last	= end($lines);
		if(preg_match('#^https?://\S+$#', $last))
		{
			array_pop($lines);
			$body		= implode("\n", $lines);
			//-6 = 5 for "(...)" + 1 for the newline, so the URL survives the final cut below
			$body		= mb_substr($body, 0, $limit - mb_strlen($last) - 6)."(...)";
			$post_text	= "$body\n$last";
		}
		$post_text	= mb_substr($post_text, 0, $limit);
	}

	$record	= array(
		'$type'		=> 'app.bsky.feed.post',
		'text'		=> $post_text,
		'createdAt'	=> gmdate('Y-m-d\TH:i:s.000\Z'),
		'langs'		=> array('en'),
	);

	//Make URLs clickable (facets use byte offsets)
	if(preg_match_all('#https?://[^\s]+#', $post_text, $m, PREG_OFFSET_CAPTURE))
	{
		foreach($m[0] as $match)
		{
			$record['facets'][]	= array(
				'index'		=> array('byteStart' => $match[1], 'byteEnd' => $match[1] + strlen($match[0])),
				'features'	=> array(array('$type' => 'app.bsky.richtext.facet#link', 'uri' => $match[0])),
			);
		}
	}

	//Attach the image, re-encoding as JPEG if it's over Bluesky's ~1MB blob limit
	if($img && is_readable($img))
	{
		$bytes	= file_get_contents($img);
		$mime	= 'image/png';
		$info	= @getimagesize($img);
		if(strlen($bytes) > 950000 && $info)
		{
			$src	= @imagecreatefromstring($bytes);
			if($src)
			{
				foreach(array(85, 75, 60) as $q)
				{
					ob_start(); imagejpeg($src, null, $q); $bytes = ob_get_clean();
					if(strlen($bytes) <= 950000) break;
				}
				imagedestroy($src);
				$mime	= 'image/jpeg';
			}
		}
		if(strlen($bytes) <= 1000000)
		{
			$blob	= bluesky_request("$pds/xrpc/com.atproto.repo.uploadBlob", $mime, $bytes, $jwt);
			if(!empty($blob['blob']))
			{
				$image	= array('alt' => mb_substr($post_text, 0, 1000), 'image' => $blob['blob']);
				if($info)
				{
					$image['aspectRatio']	= array('width' => $info[0], 'height' => $info[1]);
				}
				$record['embed']	= array('$type' => 'app.bsky.embed.images', 'images' => array($image));
			} else
			{
				echo "Bluesky: image upload failed, posting text only: ".json_encode($blob)."\n";
			}
		} else
		{
			echo "Bluesky: image still too large after re-encode, posting text only.\n";
		}
	}

	$result	= bluesky_request("$pds/xrpc/com.atproto.repo.createRecord", 'application/json',
			json_encode(array('repo' => $session['did'], 'collection' => 'app.bsky.feed.post', 'record' => $record)), $jwt);
	if(empty($result['uri']))
	{
		echo "Bluesky: post failed: ".json_encode($result)."\n";
		return false;
	}
	echo "Bluesky: posted ".$result['uri']."\n";
	return true;
}

function bluesky_request($url, $content_type, $body, $jwt = null)
{
	$headers	= array("Content-Type: $content_type");
	if($jwt)
	{
		$headers[]	= "Authorization: Bearer $jwt";
	}
	$ch	= curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_POST		=> true,
		CURLOPT_POSTFIELDS	=> $body,
		CURLOPT_HTTPHEADER	=> $headers,
		CURLOPT_RETURNTRANSFER	=> true,
		CURLOPT_TIMEOUT		=> 60,
	));
	$response	= curl_exec($ch);
	$code		= curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$decoded	= json_decode((string)$response, true);
	if(!is_array($decoded))
	{
		$decoded	= array('error' => "HTTP $code", 'raw' => substr((string)$response, 0, 300));
	}
	return $decoded;
}

function buildMastodonCommand($post_text, $img = null)
{
    // Path to your shell script
    $script_path = '/home/chairboy/fccspacewatch/toot.sh';

    // Escape the post text for the shell command
    $escaped_text = escapeshellarg($post_text);

    // Check if an image path is provided and escape it
    if ($img && !is_readable($img)) {
        // A missing render shouldn't cost us the post: send it text-only
        echo "Warning: Image file $img does not exist, posting text only.\n";
        $img = null;
    }
    if ($img) {
        $escaped_img = escapeshellarg($img);
        $command = "$script_path $escaped_text $escaped_img";
    } else {
        $command = "$script_path $escaped_text";
    }

    return $command;
}

function distance($lat1, $lon1, $lat2, $lon2, $unit) {
  if (($lat1 == $lat2) && ($lon1 == $lon2)) {
    return 0;
  }
  else {
    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;
    $unit = strtoupper($unit);

    if ($unit == "K") {
      return ($miles * 1.609344);
    } else if ($unit == "N") {
      return ($miles * 0.8684);
    } else {
      return $miles;
    }
  }
}

function twute($conn, $tweet = '', $mediaArray = null, $parent_tweet)
{
	//Revision: 7/1/2023: Updated for v2.  Uses twute for upload, not acceptable for big media yet

	echo "Tweet function executing: $tweet\n";
	print_r($mediaArray);
	echo "\n";

	dy_discord("Attempting to tweet with twute code from FCCSpace");

	//This authorization model is defunct, now in ~/.twurlrc and specifying user in command

	//New method
	$acct_name	= "FccSpace";

	$media_string	= "";
	$reply_string	= "";

    	if(is_array($mediaArray) && !empty($mediaArray[0])) 
	{
        
        	$mediaIDS = array();
        
        	/*
            	Up to 4 media objects can be uploaded.
           	 https://developer.twitter.com/en/docs/media/upload-media/overview
        	*/
        
 		foreach($mediaArray AS $key => $media_path) 
		{
 			echo "In MediaArray: $media_path is file\n";
			if(substr($media_path, -1) == "/")
			{
				$media_path	= "";
			}

			/* Upload media to twitter API and get media ID back */
			if(strpos($media_path,'mp4'))
			{	
				echo "MP4 logic being used.\n";
//				$mediaOBJ = $connection->upload('media/upload', [
//				'media' => '/home/chairboy/nasaprocurement/rendered_opportunities/'.$media_path,
//				'media_type' => 'video/mp4',
//				'media_category' => 'tweet_video',
//				], true);
			} else
			{
				echo "Catch-all media logic being used for $media_path.\n";
				if(!empty($media_path))
				{
//					$mediaOBJ = $connection->upload('media/upload', [
//					'media' => '/home/chairboy/nasaprocurement/rendered_opportunities/'.$media_path
//					], true);
//					array_push($mediaIDS, $mediaOBJ->media_id_string);	
//					echo "Media String: ".$mediaOBJ->media_id_string."\n";
//					print_r($mediaOBJ);

					//Upload using Twurl
					//twurl -H "upload.twitter.com" -X POST "/1.1/media/upload.json" --file "/home/chairboy/tfrmonitor/rendered_maps/2213.png" --file-field "media"

					$twurl_cmd	= "twurl -H \"upload.twitter.com\" -X POST \"/1.1/media/upload.json\" --file \"$media_path\" --file-field \"media\" -u '$acct_name'";
					echo "Twurl_cmd: $twurl_cmd\n";

					$twurl_resp	= shell_exec($twurl_cmd);
					echo "$twurl_resp\n";
					$media_id = json_decode($twurl_resp)->media_id_string;
					array_push($mediaIDS, $media_id);

					echo "mediaIDS array: "; 
					print_r($mediaIDS);
				}
			}
			/* push uploaded media ID to array */	

		}

		if(!empty($mediaIDS))
		{
			//$media_string	= "-d 'media_ids=" . implode(',', $mediaIDS)."'";
			
				
			// Add double quotes around each element
			$quotedArray = array_map(function($element) 
			{
				return '"' . $element . '"';
		}, $mediaIDS);


	
		//Implode the array with commas
		$imploded_media_ids = implode(',', $quotedArray);

		$media_string	= <<<EOT
, "media": {"media_ids": [$imploded_media_ids]}
EOT;
		} else
		{
			$media_string 	= "";
		}

		echo $media_string."\n";
	}

	if(!empty($parent_tweet))
	{
		//$reply_string	= " -d 'in_reply_to_status_id=$parent_tweet'";

		$reply_string	= <<<EOT
, "reply": { "in_reply_to_tweet_id": "$parent_tweet"}
EOT;

	} else
	{
		$reply_string	= "";
	}

	//Replace ampersand with something ampersandy but less breaky
	//?? is new character
	$tweet = str_replace("&", "??", $tweet);

	dy_discord("$acct_name: $tweet");

//	$post_command = "twurl -X POST -H api.twitter.com '/1.1/statuses/update.json' -d 'status=$tweet' ".$media_string." ".$reply_string." -u '$acct_name'";

	$tweet	= str_replace("\n", "\\n", $tweet);

	//$tweet	= escapeshellcmd($tweet);

	$post_command	= <<<EOT
twurl -X POST -A "Content-type: application/json" -d '{"text": "$tweet" $reply_string $media_string}' "/2/tweets" -u '$acct_name'
EOT;

	echo "Twurl post: $post_command\n";

	$post_output = shell_exec($post_command);

//	echo "Twurl output: \n$post_output\n";

	if(!json_decode($post_output)->data->id)
	{
		dy_discord("FCCSpace:".var_dump($post_output));
	}
	return json_decode($post_output)->data->id;

}

function newer_tweet($conn, $str = '', $mediaArray = null, $parent_tweet) 
{
	/*
		Twitter OAuth keys
		Create your App and find your API keys here:
		https://developer.twitter.com/en/apps
	*/

	dy_discord("Attempting to tweet with new code from FCC Space");

//api key (in config.ini [twitter_unused] api_key)
//api secret (in config.ini [twitter_unused] api_secret)
//bearer token (in config.ini [twitter_unused] bearer_token)
//access token (in config.ini [twitter_unused] access_token)
//access token secret (in config.ini [twitter_unused] access_token_secret)


	echo "Tweet function executing: $str $mediaArray[0]\n";
	$consumer_key = bot_cfg('twitter_newer', 'consumer_key');
	$consumer_secret =bot_cfg('twitter_newer', 'consumer_secret');
	$access_token = bot_cfg('twitter_newer', 'access_token');
	$access_token_secret = bot_cfg('twitter_newer', 'access_token_secret');

//	include 'test_tweet.inc'; //Uncomment this when using test account for twitter dev
    
	/* init API */
	$connection = new TwitterOAuth($consumer_key, $consumer_secret, $access_token, $access_token_secret);
	$connection->setApiVersion('2');
	//$endpoint = 'https://api.twitter.com/2/tweets';
    	if(is_array($mediaArray) && !empty($mediaArray[0])) 
	{
        
        	$mediaIDS = array();
        
        	/*
            	Up to 4 media objects can be uploaded.
           	 https://developer.twitter.com/en/docs/media/upload-media/overview
        	*/

		print_r($mediaArray);
        
 		foreach($mediaArray AS $key => $media_path) 
		{
 			echo "In MediaArray: $media_path is file\n";
			/* Upload media to twitter API and get media ID back */
			if(strpos($media_path,'mp4'))
			{	
				echo "MP4 logic being used.\n";
				$mediaOBJ = $connection->upload('media/upload', [
				'media' => '/home/chairboy/fccspacewatch/license_renders/'.$media_path,
				'media_type' => 'video/mp4',
				'media_category' => 'tweet_video',
				], true);
			} else
			{
				echo "Catch-all media logic being used for $media_path.\n";
				if(!empty($media_path))
				{
					$mediaOBJ = $connection->upload('media/upload', [
					'media' => '/home/chairboy/fccspacewatch/license_renders/'.$media_path
					], true);
					array_push($mediaIDS, $mediaOBJ->media_id_string);	
					echo "Media String: ".$mediaOBJ->media_id_string."\n";
					print_r($mediaOBJ);
				}
			}
			/* push uploaded media ID to array */	

		}
        
		/* create comma delimited list of media ID:s */	
		$mediaIDstr = implode(',', $mediaIDS);	
	}
        
	/* API params */
	$arrayCfg['text'] = $str;
	if(!empty($mediaIDstr))
	{
		$arrayCfg['media_ids'] = $mediaIDstr;
	}
	if(!empty($parent_tweet))
	{
		$arrayCfg['reply']['in_reply_to_tweet_id'] = $parent_tweet;
	}
    
	/*
		Make POST request to Twitter API
		https://developer.twitter.com/en/docs/tweets/post-and-engage/api-reference/post-statuses-update
	*/
	echo "Tweeting now.\n";
	//sleep(5); //Added so videos can attach correctly
	$statuses = $connection->post("tweets", $arrayCfg, true);

	if ($connection->getLastHttpCode() == 200) {
	    // Tweet posted successfully
		echo "Tweet succeeded.\n";
	} else {
	    // Handle error case
		echo "Error during tweet:".$connection->getLastHttpCode()."\n";
		var_dump($statuses);
	}

	//Log it
	$tweet		= mysqli_real_escape_string($conn, $str);

	/* return payload */
	return $statuses;
}


function new_tweet($conn, $str = '', $mediaArray = null, $parent_tweet) 
{
	/*
		Twitter OAuth keys
		Create your App and find your API keys here:
		https://developer.twitter.com/en/apps
	*/

	//@CBDevTest
	$consumer_key = bot_cfg('twitter_cbdevtest', 'consumer_key');
	$consumer_secret =bot_cfg('twitter_cbdevtest', 'consumer_secret');
	$access_token = bot_cfg('twitter_cbdevtest', 'access_token');
	$access_token_secret = bot_cfg('twitter_cbdevtest', 'access_token_secret');


	$consumer_key = bot_cfg('twitter', 'consumer_key');
	$consumer_secret =bot_cfg('twitter', 'consumer_secret');
	$access_token = bot_cfg('twitter', 'access_token');
	$access_token_secret = bot_cfg('twitter', 'access_token_secret');

//	include 'test_tweet.inc'; //Uncomment this when using test account for twitter dev
    
	/* init API */
	$connection = new TwitterOAuth($consumer_key, $consumer_secret, $access_token, $access_token_secret);
    	if(is_array($mediaArray) && !empty($mediaArray[0])) 
	{
        
        	$mediaIDS = array();
        
        	/*
            	Up to 4 media objects can be uploaded.
           	 https://developer.twitter.com/en/docs/media/upload-media/overview
        	*/
        
 		foreach($mediaArray AS $key => $media_path) 
		{
 			echo "In MediaArray: $media_path is file\n";
			/* Upload media to twitter API and get media ID back */
			if(strpos($media_path,'mp4'))
			{	
				echo "MP4 logic being used.\n";
				$mediaOBJ = $connection->upload('media/upload', [
				'media' => '/home/chairboy/fccspacewatch/license_renders/'.$media_path,
				'media_type' => 'video/mp4',
				'media_category' => 'tweet_video',
				], true);
			} else
			{
				echo "Catch-all media logic being used.\n";
				$mediaOBJ = $connection->upload('media/upload', [
				'media' => '/home/chairboy/fccspacewatch/license_renders/'.$media_path
				], true);
			}
			/* push uploaded media ID to array */	
			array_push($mediaIDS, $mediaOBJ->media_id_string);	
		}
        
		/* create comma delimited list of media ID:s */	
		$mediaIDstr = implode(',', $mediaIDS);	
	}
        
	/* API params */
	$arrayCfg['status'] = $str;
	$arrayCfg['media_ids'] = $mediaIDstr;
	if(isset($parent_tweet))
	{
		$arrayCfg['in_reply_to_status_id'] = $parent_tweet;
	}
    
	/*
		Make POST request to Twitter API
		https://developer.twitter.com/en/docs/tweets/post-and-engage/api-reference/post-statuses-update
	*/
	echo "Tweeting now.\n";
	sleep(5); //Added so videos can attach correctly
	$statuses = $connection->post("statuses/update", $arrayCfg);

	if ($connection->getLastHttpCode() == 200) {
	    // Tweet posted successfully
		echo "Tweet succeeded.\n";
	} else {
	    // Handle error case
		echo "Error during tweet:".$connection->getLastHttpCode()."\n";
		var_dump($statuses);
	}

	//Log it
	$tweet		= mysqli_real_escape_string($conn, $str);

	/* return payload */
	return $statuses;
}

function dy_discord($msg)
{
	pushbullet($msg);
}

function pushbullet($msg)
{
	//escapeshellarg: messages can contain scraped text, and quotes/backticks/$() used to break or run in the shell
	exec("php /home/chairboy/dybot/manual_text.php 877218665328705579 ".escapeshellarg("<@433685325840252950> $msg"));
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}


function oldpushbullet($msg)
{
	$msg	= str_replace("&", "and",$msg);
	$msg	= escapeshellcmd($msg);
	$__cfg_pushbullet_token	= bot_cfg('pushbullet', 'token');
	$pushbullet	= <<<EOD
curl --silent -u """{$__cfg_pushbullet_token}"":" -d type="note" -d body="$msg" -d title="FCC/FAA Alert" 'https://api.pushbullet.com/v2/pushes'
EOD;
	$output		= shell_exec($pushbullet);
}


//echo distance(32.9697, -96.80322, 29.46786, -98.53506, "M") . " Miles<br>";
//echo distance(32.9697, -96.80322, 29.46786, -98.53506, "K") . " Kilometers<br>";
//echo distance(32.9697, -96.80322, 29.46786, -98.53506, "N") . " Nautical Miles<br>";
//echo "Loaded common.inc\n";
?>