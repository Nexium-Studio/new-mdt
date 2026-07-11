<?php
require_once __DIR__ . '/../config/config.php';

$params = [
    'client_id' => DISCORD_CLIENT_ID,
    'redirect_uri' => DISCORD_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'identify email'
];

$url = "https://discord.com/api/oauth2/authorize?" . http_build_query($params);

header('Location: ' . $url);
exit;