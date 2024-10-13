<?php
$webhook_url = 'https://thankhat.s3-tastewp.com/wp-json/property-importer/v1/webhook';
$webhook_secret = '1d179a8b274d3ab408f9350aee73db85eaca8fbdb08659dd3ce4a1b39a4aef0c';

$payload = json_encode(['event' => 'test']);

$signature = hash_hmac('sha256', $payload, $webhook_secret);

$ch = curl_init($webhook_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Webhook-Signature: ' . $signature
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

echo "HTTP Kodu: " . $http_code . "\n";
echo "Yanıt: " . $response . "\n";

