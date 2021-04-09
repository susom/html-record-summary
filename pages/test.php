<?php
namespace Stanford\HtmlRecordSummary;
/** @var HtmlRecordSummary $module */


Echo "Hello";

// $config = [
//     "keyFile" => $module->getSystemSetting['gcp-function-key']
// ];

//
// $result = new \Google\Cloud\Storage\StorageClient($config);
//
//
// echo "Here";
//
// echo "<pre>".print_r($result,true)."</pre>";

$module->getPdf("https://www.google.com");

exit();

use Google\Auth\CredentialsLoader;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$jsonKey = json_decode($module->getSystemSetting('gcp-function-key'), true);
$url = $module->getSystemSetting('gcp-function-url');
$targetAudience = "https://us-west2-som-rit-redcap-dev.cloudfunctions.net/html2pdf";

print "<pre>";

// WORKS!!!!

$c = CredentialsLoader::makeCredentials($targetAudience, $jsonKey);
print_r($c);
print "<hr>";
print_r($c->fetchAuthToken());

//
// $client = CredentialsLoader::makeCredentials($scope, $jsonKey);
// print_r($client);
// print "<hr>";
// print_r($client->fetchAuthToken());

// \Credentials\ServiceAccountCredentials($targetAudience,$jsonKey,[
//     'keyFile' => $jsonKey
// ]);


exit();

$client = new Client([
    'base_uri' => $url
]);

$headers = [
    "Authorization" => "Bearer " . $token,
    'Accept'        => 'application/json'
];

$response = $client->request('GET', '?url=www.google.com',
[
    'headers' => $headers
]);

print_r($response);

