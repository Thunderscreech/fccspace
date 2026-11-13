<?php

// URL for the POST request
$url = "https://explore.dot.gov/vizql/t/FAA/w/CommercialSpaceTransportation/v/Main/sessions/E8539B2449E0402982349D289FDAD577-3:0/commands/tabdoc/goto-sheet";

// Request headers
$headers = array(
    "Accept: text/javascript",
    "Accept-Encoding: gzip, deflate, br",
    "Accept-Language: en-US,en;q=0.9",
    "Content-Type: multipart/form-data; boundary=wDCEoUXF",
    "Cookie: tableau_locale=en",
    "Origin: https://explore.dot.gov",
    "Referer: https://explore.dot.gov/t/FAA/views/CommercialSpaceTransportation/LicensedLaunches?%3Aembed=y&%3Aiid=1&%3AisGuestRedirectFromVizportal=y",
    "Sec-Ch-Ua: \"Microsoft Edge\";v=\"119\", \"Chromium\";v=\"119\", \"Not?A_Brand\";v=\"24\"",
    "Sec-Ch-Ua-Mobile: ?1",
    "Sec-Ch-Ua-Platform: \"Android\"",
    "Sec-Fetch-Dest: empty",
    "Sec-Fetch-Mode: cors",
    "Sec-Fetch-Site: same-origin",
    "User-Agent: Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36 Edg/119.0.0.0",
    "X-Requested-With: XMLHttpRequest",
    "X-Tableau-Version: 2023.1",
    "X-Tsi-Active-Tab: Licensed%20Launches%20"
);

// Request data
$data = "--wDCEoUXF\r\nContent-Disposition: form-data; name=\"param1\"\r\n\r\nvalue1\r\n--wDCEoUXF\r\nContent-Disposition: form-data; name=\"param2\"\r\n\r\nvalue2\r\n--wDCEoUXF--";

// Initialize cURL session
$ch = curl_init($url);

// Set cURL options
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Execute cURL session and get the response
$response = curl_exec($ch);

// Check for cURL errors
if (curl_errno($ch)) {
    echo 'Curl error: ' . curl_error($ch);
} else {
    // Get HTTP status code
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Display HTTP status code and response
    echo 'HTTP Status Code: ' . $httpCode . PHP_EOL;
    echo 'Response: ' . $response . PHP_EOL;
}

// Close cURL session
curl_close($ch);
?>
