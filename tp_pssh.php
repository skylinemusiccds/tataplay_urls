<?php

// Retrieve the `id` query parameter from the URL
$id = $_GET['id'] ?? null;

// Validate the `id` parameter
if (!$id) {
    exit("Error: Invalid or missing `id` parameter.\n");
}

// Construct the URL to fetch data using the `id`
$fetchUrl = "http://localhost/pssh/url.php?id=$id";

// Fetch data from the constructed URL
$fetchedData = file_get_contents($fetchUrl);

if ($fetchedData === false) {
    exit("Error: Failed to fetch data from URL: $fetchUrl\n");
}

// Split the fetched plain text data by newline characters
$lines = explode("\n", $fetchedData);

// Check if the third line exists and extract the DASH URL
$dashLine = isset($lines[2]) ? trim($lines[2]) : null;

if (!$dashLine) {
    exit("Error: Failed to extract DASH URL from fetched data.\n");
}

// Remove 'MPD=' prefix from the dash line
$dashUrl = str_replace('MPD= ', '', $dashLine);

// Specify the path to the file containing the plain text cookie data
$cookieFilePath = '/home/hdntl_data.txt';

// Fetch the cookie data from the specified file path
function fetchCookieData($filePath) {
    $cookieData = file_get_contents($filePath);

    if ($cookieData === false) {
        return null;
    }

    return trim($cookieData);
}

// Function to fetch the MPD manifest file from the DASH URL using cURL
function fetchMPDManifest($url, $userAgent, $cookie) {
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'User-Agent: ' . $userAgent,
        'Cookie: ' . $cookie,
    ]);
    
    $manifestContent = curl_exec($curl);

    if ($manifestContent === false) {
        return null;
    }
    
    curl_close($curl);
    
    return $manifestContent;
}

// Function to extract video URL from MPD manifest and find Widevine PSSH
function extractVideoUrlFromManifest($manifestContent, $baseVideoUrl, $userAgent, $cookie) {
    $xml = simplexml_load_string($manifestContent);

    if ($xml === false) {
        return null;
    }

    foreach ($xml->Period->AdaptationSet as $adaptationSet) {
        if (isset($adaptationSet['contentType']) && (string)$adaptationSet['contentType'] === 'video') {
            foreach ($adaptationSet->Representation as $representation) {
                if (isset($representation->SegmentTemplate)) {
                    $media = (string)$representation->SegmentTemplate['media'];
                    $startNumber = isset($representation->SegmentTemplate['startNumber']) ? (int)$representation->SegmentTemplate['startNumber'] : 0;
                    $modifiedStartNumber = $startNumber + 1870;
                    $mediaFileName = str_replace(['$RepresentationID$', '$Number$'], [(string)$representation['id'], $modifiedStartNumber], $media);
                    $videoUrl = $baseVideoUrl . '/dash/' . $mediaFileName;

                    // Fetch the video file using the extracted video URL
                    $context = stream_context_create([
                        'http' => [
                            'method' => 'GET',
                            'header' => [
                                'User-Agent: ' . $userAgent,
                                'Cookie: ' . $cookie,
                            ],
                        ],
                    ]);

                    $videoContent = file_get_contents($videoUrl, false, $context);

                    if ($videoContent === false) {
                        return null;
                    }

                    // Convert video content to hexadecimal
                    $hexVideoContent = bin2hex($videoContent);

                    // Search for Widevine PSSH box marker and extract complete PSSH box
                    $psshMarker = "000000387073736800000000edef8ba979d64acea3c827dcd51d21ed000000"; // Hexadecimal representation of PSSH box header
                    $pos = strpos($hexVideoContent, $psshMarker);

                    if ($pos !== false) {
                        // Find the end of the PSSH box
                        $psshEnd = strpos($hexVideoContent, "0000", $pos + strlen($psshMarker));

                        if ($psshEnd !== false) {
                            $psshHex = substr($hexVideoContent, $pos, $psshEnd - $pos - 0);

                            // Return base64-encoded Widevine PSSH
                            return base64_encode(hex2bin($psshHex));
                        } else {
                            return null; // End of PSSH box not found
                        }
                    } else {
                        return null; // PSSH box marker not found
                    }
                }
            }
        }
    }

    return null; // Video URL extraction failed
}

// Fetch the cookie data
$cookie = fetchCookieData($cookieFilePath);

if ($cookie !== null) {
    // Specify the user agent to use for the HTTP request
    $userAgent = 'Mozilla/5.0';

    // Fetch the MPD manifest content
    $manifestContent = fetchMPDManifest($dashUrl, $userAgent, $cookie);

    if ($manifestContent !== null) {
        // Attempt to find Widevine PSSH using primary method
        $dom = new DOMDocument();
        $dom->loadXML($manifestContent);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cenc', 'urn:mpeg:dash:schema:mpd:2011');
        $psshNodes = $xpath->query('//cenc:pssh');

        if ($psshNodes->length > 0) {
            $widevinePssh = $psshNodes->item(0)->nodeValue;
            echo "\n$widevinePssh\n";
        } else {
            // Extract video URL and attempt to find Widevine PSSH using alternative method
            $baseUrl = dirname($dashUrl);
            $widevinePssh = extractVideoUrlFromManifest($manifestContent, $baseUrl, $userAgent, $cookie);

            if ($widevinePssh !== null) {
                echo "\n$widevinePssh\n";
            } else {
                echo "Error: Widevine PSSH not found in the video content.\n";
            }
        }
    } else {
        echo "Error: Failed to fetch MPD manifest.\n";
    }
} else {
    echo "Error: Failed to fetch cookie data.\n";
}
