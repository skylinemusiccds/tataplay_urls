<?php

$id = $_GET['id'] ?? null;

if (!$id) {
    exit("Error: Invalid or missing `id` parameter.\n");
}

$fetchUrl = "http://localhost/pssh/url.php?id=$id";

$fetchedData = file_get_contents($fetchUrl);

if ($fetchedData === false) {
    exit("Error: Failed to fetch data from URL: $fetchUrl\n");
}

$lines = explode("\n", $fetchedData);

$dashLine = isset($lines[2]) ? trim($lines[2]) : null;

if (!$dashLine) {
    exit("Error: Failed to extract DASH URL from fetched data.\n");
}

$dashUrl = str_replace('MPD= ', '', $dashLine);

$cookieFilePath = '/home/hdntl_data.txt';

function fetchCookieData($filePath) {
    $cookieData = file_get_contents($filePath);

    if ($cookieData === false) {
        return null;
    }

    return trim($cookieData);
}

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
                    $repeatCount = isset($representation->SegmentTemplate->SegmentTimeline->S['r']) ? (int)$representation->SegmentTemplate->SegmentTimeline->S['r'] : 0;
                    $modifiedStartNumber = $startNumber + $repeatCount;
                    $mediaFileName = str_replace(['$RepresentationID$', '$Number$'], [(string)$representation['id'], $modifiedStartNumber], $media);
                    $videoUrl = $baseVideoUrl . '/dash/' . $mediaFileName;

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

                    $hexVideoContent = bin2hex($videoContent);

                    $psshMarker = "000000387073736800000000edef8ba979d64acea3c827dcd51d21ed000000"; 
                    $pos = strpos($hexVideoContent, $psshMarker);

                    if ($pos !== false) {
                        $psshEnd = strpos($hexVideoContent, "0000", $pos + strlen($psshMarker));

                        if ($psshEnd !== false) {
                            $psshHex = substr($hexVideoContent, $pos, $psshEnd - $pos - 12);
                            $psshHex = str_replace("000000387073736800000000edef8ba979d64acea3c827dcd51d21ed00000018", "000000327073736800000000edef8ba979d64acea3c827dcd51d21ed00000012", $psshHex);

                            return base64_encode(hex2bin($psshHex));
                        } else {
                            return null; 
                        }
                    } else {
                        return null; 
                    }
                }
            }
        }
    }

    return null; 
}

$cookie = fetchCookieData($cookieFilePath);

if ($cookie !== null) {
    $userAgent = 'Mozilla/5.0';

    $manifestContent = fetchMPDManifest($dashUrl, $userAgent, $cookie);

    if ($manifestContent !== null) {
        $dom = new DOMDocument();
        $dom->loadXML($manifestContent);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cenc', 'urn:mpeg:dash:schema:mpd:2011');
        $psshNodes = $xpath->query('//cenc:pssh');

        if ($psshNodes->length > 0) {
            $widevinePssh = $psshNodes->item(0)->nodeValue;
            echo "\n$widevinePssh\n";
        } else {
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
