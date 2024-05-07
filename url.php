<?php
// Validate and sanitize the 'id' parameter from the URL
$id = isset($_GET['id']) ? $_GET['id'] : '';
if (!ctype_digit($id)) {
    die('Invalid id provided');
}

// Fetch JSON data from the URL
$url = 'https://raw.githubusercontent.com/ttoor5/tataplay_urls/main/tplay.txt';

// Use cURL for fetching data
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$jsonData = curl_exec($ch);

if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
    die('Failed to fetch data from ' . $url);
}

curl_close($ch);

// Process the fetched JSON data
$lines = explode("\n", $jsonData);
$targetLines = [];
$foundId = false;

foreach ($lines as $index => $line) {
    // Check if the line contains the specified 'id'
    if (strpos($line, 'id="' . $id . '"') !== false) {
        $foundId = true;
        // Capture the line containing the 'id'
        $targetLines[] = $line;

        // Capture the next two lines after the 'id' match
        for ($i = $index + 1; $i <= $index + 2 && $i < count($lines); $i++) {
            $targetLines[] = $lines[$i];
        }

        break; // Stop processing after capturing the lines
    }
}

// Output the extracted lines as plain text
if (!empty($targetLines)) {
    header('Content-Type: text/plain');
    foreach ($targetLines as $line) {
        echo $line . PHP_EOL;
    }
} else {
    echo "Object with id = $id not found";
}
?>