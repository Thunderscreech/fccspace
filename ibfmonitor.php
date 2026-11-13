<?php
// Monitor FCC IBFS for Satellite Space Station entries using the daily FTP bulk download
error_reporting(E_ERROR | E_PARSE);
include 'common.inc';
use DG\Twitter\Twitter;
require_once 'vendor/dg/twitter-php/src/twitter.class.php';

//Check if I'm supposed to be running
$sqlstring      = "SELECT status_data FROM status WHERE status_item = 'active'";
$active         = mysqli_query($conn,$sqlstring)->fetch_object()->status_data;
if($active == '1')
{
        //No action required, we are on like kong
} else
{
        echo "According to status.status_item.active, I am supposed to be "
           . "asleep.  Exiting...\n";
        exit();
}

// ─── Download and extract IBFS.zip from FCC FTP ───────────────────────────────
$ftpUrl  = "ftp://ftp.fcc.gov/pub/Bureaus/International/databases/IBFS.zip";
$tempZip = tempnam(sys_get_temp_dir(), 'ibfs_zip_');

echo "Downloading IBFS.zip from FCC FTP...\n";
$zipData = file_get_contents($ftpUrl);

if ($zipData === false || empty(trim($zipData))) {
    error_log("Failed to download IBFS.zip from $ftpUrl");
    echo "ERROR: Failed to download IBFS.zip\n";
    exit;
}

file_put_contents($tempZip, $zipData);

// Extract the zip
$extractDir = sys_get_temp_dir() . '/ibfs_extract_' . uniqid();
mkdir($extractDir, 0755, true);

$zip = new ZipArchive();
if ($zip->open($tempZip) === true) {
    $zip->extractTo($extractDir);
    $fileCount = $zip->numFiles;
    $zip->close();
    echo "Extracted $fileCount files from IBFS.zip\n";
} else {
    error_log("Failed to open IBFS.zip");
    echo "ERROR: Failed to open IBFS.zip\n";
    unlink($tempZip);
    exit;
}
unlink($tempZip);

//Update last run
$sqlstring      = "update status set status_data = now() where status_item = "
                 . "'ibfs_lastrun'";
$result         = mysqli_query($conn,$sqlstring);

echo "Checking IBFS licenses for Space Station filings...\n";

// ─── Date filter: only process filings from the last 7 days ──────────────────
$oneWeekAgo = strtotime('-7 days');
echo "Filtering for filings dated on or after "
   . date('Y-m-d', $oneWeekAgo) . "\n";

// ─── Scan extracted files for Space Station filings ───────────────────────────
$filings = array();
$skippedOld = 0;
$skippedNoDate = 0;

$files = glob($extractDir . '/*');

// ═══ FIRST: Print a sample of each file to see what we're dealing with ═══
foreach ($files as $filePath) {
    $fileName = basename($filePath);
    if (!preg_match('/\.(txt|dat|csv)$/i', $fileName)) continue;

    $handle = fopen($filePath, 'r');
    if ($handle === false) continue;

    echo "\n=== $fileName (sample rows) ===\n";
    $count = 0;
    while (($line = fgets($handle)) !== false && $count < 3) {
        echo $line . "\n";
        $count++;
    }
    fclose($handle);
    echo "===============================\n";
}

// ═══ SECOND: Process each file using positional column access ═══
foreach ($files as $filePath) {
    $fileName = basename($filePath);

    if (preg_match('/\.(txt|dat|csv)$/i', $fileName)) {

        $handle = fopen($filePath, 'r');
        if ($handle === false) continue;

        $firstLine = fgets($handle);
        fclose($handle);

        // Detect delimiter
        $delimiter = '|';
        if (strpos($firstLine, '|') === false) {
            if (strpos($firstLine, "\t") !== false) {
                $delimiter = "\t";
            } elseif (strpos($firstLine, ',') !== false) {
                $delimiter = ',';
            }
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) continue;

        $lineNum = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNum++;

            // Skip empty lines
            if (count($row) < 2 || empty(trim(implode('', $row)))) {
                continue;
            }

            $allValues = implode(' ', array_map('trim', $row));
            if (strpos($allValues, 'Space Statio') === false) {
                continue;
            }

            // ═══ POSITIONAL field extraction ═══
            // NOTE: These indices are GUESSES until you see the sample output.
            // Adjust based on what the sample dump shows for each file.
            //
            // Common IBFS positional layout (from FCC docs):
            //   [0]  = file number / file_no
            //   [1]  = call sign
            //   [..] = various fields
            //   date field position varies by file

            $file_number = isset($row[0]) ? trim($row[0]) : '';
            $call_sign   = isset($row[1]) ? trim($row[1]) : '';

            // The date is usually in a field like YYYYMMDD or MM/DD/YYYY.
            // Scan ALL fields for a date-like pattern to find it.
            $last_update = '';
            foreach ($row as $col) {
                $col = trim($col);
                // Look for date patterns: YYYY-MM-DD, MM/DD/YYYY, YYYYMMDD, etc.
                if (preg_match('/^(\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}|\d{8}|'
                             . '\d{4}\/\d{2}\/\d{2})/', $col)
                && strlen($col) <= 12) {
                    $last_update = $col;
                    break;
                }
            }

            // Company/applicant — scan for a likely name field
            // (often a longer text field; may need empirical adjustment)
            $company = '';

            // ═══ Date filter ═══
            $filingTimestamp = parse_filing_date($last_update);

            if ($filingTimestamp === false) {
                $skippedNoDate++;
                continue;
            }

            if ($filingTimestamp < $oneWeekAgo) {
                $skippedOld++;
                continue;
            }

            $filingUrl = "https://fcc.report/IBFS/" . urlencode($file_number);

            $filings[] = array(
                'file_number' => $file_number,
                'call_sign'   => $call_sign,
                'filing_type' => '',
                'last_update' => $last_update,
                'company'     => $company,
                'url'         => $filingUrl,
            );
        }
        fclose($handle);
    }
}

echo "Found " . count($filings) . " Space Station filing(s) in the last week.\n";
echo "(Skipped $skippedOld older filing(s), $skippedNoDate filing(s) with unparseable dates.)\n";

// ─── Process each filing — tweet new ones ────────────────────────────────────
$newCount = 0;

foreach ($filings as $filing) {
    $file_number = $filing['file_number'];
    $last_update = $filing['last_update'];
    $company     = $filing['company'];
    $url         = $filing['url'];

    if (empty($file_number)) {
        continue; // skip entries without a file number
    }

    if (is_report_onfile($conn, $file_number)) {
        // Already in the database, no action required
        continue;
    }

    // ─── New filing — build and post the tweet ───────────────────────────────
    if (empty($company)) {
        $html      = @file_get_contents($url);
        if ($html !== false && !empty(trim($html))) {
            $company = get_company_name($html);
            $descHtml = $html;
        }
    } else {
        $html      = @file_get_contents($url);
        $descHtml  = ($html !== false && !empty(trim($html))) ? $html : '';
    }

    $description = '';
    if (!empty($descHtml)) {
        $description = trim(get_description($descHtml));
    }

    // Build the tweet, keeping it within Twitter's character limit
    $lendelta    = strlen($company) - 18;
    if ($lendelta > 0) {
        $description = substr($description, 0, 170 - $lendelta);
    } else {
        $description = substr($description, 0, 170);
    }
    if (strlen($description) >= 170) {
        $description = $description . "(...)";
    }

    $tweet = "Company:$company Filed:$last_update\n$description\n$url";

    $tweet = addslashes($tweet);

    // Insert into database
    $sqlstring = "INSERT INTO ibfs (file_number, last_update, company_name) "
               . "VALUES ('$file_number',str_to_date('$last_update','%Y-%m-%d'),"
               . "'$company')";
    $result = mysqli_query($conn, $sqlstring);

    // Get the ID for the render
    $sqlstring = "SELECT ibfs_id FROM ibfs ORDER BY ibfs_id DESC LIMIT 1";
    $ibfs_id   = mysqli_query($conn,$sqlstring)->fetch_object()->ibfs_id;

    echo "Tweeting: $tweet\n";

    // --- Post to Mastodon ---
    $mastodon_post = mastodon_post($tweet,
        "/home/chairboy/fccspacewatch/license_renders/ibf-$ibfs_id.png");
    sleep(5);

    $newCount++;
}

echo "Done. $newCount new filing(s) posted.\n";

// Clean up extracted files
array_map('unlink', glob($extractDir . '/*'));
rmdir($extractDir);


// ─── Helper Functions ────────────────────────────────────────────────────────

/**
 * Parse a date string from the IBFS bulk data into a Unix timestamp.
 * Tries multiple common date formats. Returns false if none match.
 */
function parse_filing_date($dateStr) {
    $dateStr = trim($dateStr);
echo $dateStr."\n";
    if (empty($dateStr)) {
        return false;
    }

    // Try a series of common date formats
    $formats = array(
        'Y-m-d',       // 2024-01-15
        'Y-m-d H:i:s', // 2024-01-15 14:30:00
        'm/d/Y',       // 01/15/2024
        'm/d/Y H:i:s', // 01/15/2024 14:30:00
        'n/j/Y',       // 1/5/2024
        'Y/m/d',       // 2024/01/15
        'd-m-Y',       // 15-01-2024
        'F j, Y',      // January 15, 2024
        'M j, Y',      // Jan 15, 2024
        'j-M-Y',       // 15-Jan-2024
    );

    foreach ($formats as $fmt) {
        $ts = DateTime::createFromFormat($fmt, $dateStr);
        if ($ts !== false) {
            return $ts->getTimestamp();
        }
    }

    // Last resort: try strtotime
    $ts = strtotime($dateStr);
    if ($ts !== false) {
        return $ts;
    }

    return false;
}

/**
 * Get a field value from a row by trying header names, then positional fallback.
 */
function get_field($rowAssoc, $headers, $possibleNames) {
    // Try by header name
    if (is_array($headers)) {
        foreach ($possibleNames as $name) {
            $key = array_search(strtolower(trim($name)), $headers);
            if ($key !== false && isset($rowAssoc[$headers[$key]])) {
                return $rowAssoc[$headers[$key]];
            }
        }
    }

    // If associative array, try direct key access
    if (is_array($rowAssoc)) {
        foreach ($possibleNames as $name) {
            $name = strtolower(trim($name));
            foreach ($rowAssoc as $key => $val) {
                if (strtolower(trim($key)) === $name) {
                    return $val;
                }
            }
        }
    }

    return '';
}


//Do we have this report
function is_report_onfile($conn,$file_number)
{
        $sqlstring      = "SELECT count(*) as report_count FROM ibfs WHERE "
                        . "file_number = '$file_number'";
        if(mysqli_query($conn,$sqlstring)->fetch_object()->report_count >= 1)
        {
                //This is already in the system and no action is requires
                $return = true;
        } else
        {
                $return = false;
        }

        return $return;
}

//Get the company name from an HTML filing page (used as fallback)
function get_company_name($html)
{
        if (empty(trim($html))) {
            return false;
        }

        //Find h2 and read for title
        $dom    = new domDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        $dom->preserveWhiteSpace = false;

        foreach($dom->getElementsByTagName('h2') as $header)
        {
                $company_name   = $header->nodeValue;
        }

        $application_titles     = array(
        'Application for Earth Exploration Satellite Service Mobile Satellite Service '
        . 'Other by ',
        'Application for Fixed Satellite Service by ',
        'Application for Direct to Home Fixed Satellite Fixed Satellite Service by ',
        'Satellite Space Stations ',
        'Application for Fixed Satellite Service Other by ',
        'Application for Earth Exploration Satellite Service Other by',
        'Application for Earth Exploration Satellite Service by');

        foreach($application_titles as $filter)
        {
                $company_name = str_replace($filter,"",$company_name);
        }


        if(isset($company_name))
        {
                return $company_name;
        } else
        {
                return false;
        }
}

function get_description($html)
{
        if (empty(trim($html))) {
            return false;
        }

        //Find the right DIV and <p> inside of it for the description
        $dom    = new domDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        $dom->preserveWhiteSpace = false;
        $div    = $dom->getElementsByTagName('div');

        foreach($div as $tag)
        {
                if($tag->getAttribute('class') === 'jumbotron')
                {
                        $description    = explode("[GOV]",$tag->nodeValue,2)[1];
                        $description    = explode("Filing:",$description,2)[0];
                        $description    = explode("See attach",$description,2)[0];
                        $description    = explode("See exhibi",$description,2)[0];
                        $description    = explode("See Attach",$description,2)[0];
                        $description    = explode("See Exhibi",$description,2)[0];
                        $description    = explode("Please see attached",$description,2)[0];
                        $description    = explode("See narrative",$description,2)[0];
                }
        }

        if(isset($description))
        {
                return $description;
        } else
        {
                return false;
        }
}


function tweet($tweet_text)
{
        //Post a tweet as FCCSpace
        //Oauth keys

        $consumerKey = bot_cfg('twitter', 'consumer_key');
        $consumerSecret =bot_cfg('twitter', 'consumer_secret');
        $accessToken = bot_cfg('twitter', 'access_token');
        $accessTokenSecret = bot_cfg('twitter', 'access_token_secret');

        $twitter = new Twitter($consumerKey, $consumerSecret, $accessToken,
        $accessTokenSecret);
        echo "Tweeting: $tweet_text\n";
        try {
                $twitter->send("$tweet_text");
        } catch (DG\Twitter\TwitterException $e)
        {
                echo 'Error: ' . $e->getMessage();
        }
}


?>
