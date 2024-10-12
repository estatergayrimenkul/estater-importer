<?php
$webhook_url = 'http://examplecom.local/wp-json/property-importer/v1/webhook';
$webhook_secret = 'fb2f126d56ec690384461808dbe1ea3de180010085ff57fa99739a54b3c1063a';

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

