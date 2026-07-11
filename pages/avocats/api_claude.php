<?php
// Bloquer TOUTE sortie parasite (notices, warnings) qui corrompt le JSON
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/config.php';

// Headers de réponse JSON
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Vider tout ce que config.php aurait pu émettre
ob_clean();

// Auth session
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => ['type' => 'auth', 'message' => 'Non authentifié']]);
    exit;
}

// Clé API (à définir dans config.php)
$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['error' => ['type' => 'config', 'message' => "Clé API manquante. Ajoutez define('ANTHROPIC_API_KEY','sk-ant-...') dans config.php"]]);
    exit;
}

// Lire le body
$raw = file_get_contents('php://input');
if (empty($raw)) {
    http_response_code(400);
    echo json_encode(['error' => ['type' => 'bad_request', 'message' => 'Body vide']]);
    exit;
}

$payload = json_decode($raw, true);
if (!$payload || !isset($payload['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => ['type' => 'bad_request', 'message' => 'JSON invalide ou champ messages manquant']]);
    exit;
}

// Forcer les bons paramètres
$payload['model']      = 'claude-sonnet-4-20250514';
$payload['max_tokens'] = min((int)($payload['max_tokens'] ?? 2048), 4096);

$jsonPayload = json_encode($payload);

// Appel cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://api.anthropic.com/v1/messages',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $jsonPayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonPayload),
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_ENCODING       => '',
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// Erreur cURL
if ($curlErrno !== 0 || $response === false) {
    http_response_code(502);
    echo json_encode(['error' => [
        'type'    => 'curl_error',
        'message' => "cURL #$curlErrno : $curlError"
    ]]);
    exit;
}

// Réponse vide
if (empty($response)) {
    http_response_code(502);
    echo json_encode(['error' => [
        'type'    => 'empty_response',
        'message' => "L'API a retourné une réponse vide (HTTP $httpCode)"
    ]]);
    exit;
}

// Vérifier que c'est du JSON valide avant de transmettre
$decoded = json_decode($response, true);
if ($decoded === null) {
    http_response_code(502);
    echo json_encode(['error' => [
        'type'    => 'invalid_json',
        'message' => 'Réponse API non-JSON : ' . substr($response, 0, 200)
    ]]);
    exit;
}

// Tout est bon — transmettre la réponse Anthropic
http_response_code($httpCode);
echo $response;