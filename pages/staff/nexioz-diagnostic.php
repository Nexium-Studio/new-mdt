<?php
/**
 * Diagnostic de la liaison NexioZ — page autonome à ouvrir dans le navigateur.
 *
 *   https://mdt.nexium-studio.com/pages/staff/nexioz-diagnostic.php
 *
 * Vérifie, dans l'ordre : la clé enregistrée, son décodage, la résolution DNS,
 * la connexion TCP au bot, puis l'authentification (envoi `dryRun` qui ne
 * publie rien sur Discord). Réservé aux administrateurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/includes/mdt_proposals.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    exit("Non authentifie : connectez-vous au MDT puis rechargez cette page.\n");
}
if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
    http_response_code(403);
    exit("Acces refuse : reserve aux administrateurs.\n");
}

$db = Database::getInstance()->getConnection();

function line(string $label, string $value): void
{
    echo str_pad($label, 34, '.') . ' ' . $value . "\n";
}

echo "=== DIAGNOSTIC LIAISON NexioZ =========================\n\n";

// ── 1. Environnement PHP ─────────────────────────────────────
line('Extension cURL', function_exists('curl_init') ? 'OK' : 'ABSENTE (bloquant)');
line('allow_url_fopen', ini_get('allow_url_fopen') ? 'on' : 'off');
line('SITE_URL', defined('SITE_URL') ? SITE_URL : '(non defini)');
line('NEXIOZ_API_BASE', (defined('NEXIOZ_API_BASE') && NEXIOZ_API_BASE !== '') ? NEXIOZ_API_BASE : '(vide - on utilise la cle)');
echo "\n";

// ── 2. Clé de liaison ────────────────────────────────────────
$token = mdt_proposals_liaison_token($db);
line('Cle enregistree', $token !== '' ? 'OUI (' . strlen($token) . ' caracteres)' : 'NON (bloquant)');
if ($token === '') {
    exit("\n>> Aucune cle enregistree. Collez la cle du dashboard NexioZ dans le MDT.\n");
}

$parsed = mdt_proposals_parse_token($token);
line('Cle decodable', $parsed !== null ? 'OUI' : 'NON (bloquant)');
if ($parsed === null) {
    exit("\n>> Cle illisible. Regenerez-la depuis le dashboard NexioZ et recollez-la.\n");
}

$base = (defined('NEXIOZ_API_BASE') && NEXIOZ_API_BASE !== '')
    ? rtrim((string) NEXIOZ_API_BASE, '/')
    : $parsed['url'];
$endpoint = $base . '/api/mdt/proposals';

line('URL du bot (dans la cle)', $parsed['url']);
line('Secret (debut)', substr($parsed['secret'], 0, 8) . '...');
line('Endpoint appele', $endpoint);
echo "\n";

// ── 3. Résolution DNS ────────────────────────────────────────
$host = (string) parse_url($base, PHP_URL_HOST);
$ip   = $host !== '' ? gethostbyname($host) : '';
line('Hote', $host !== '' ? $host : '(inconnu)');
line('Resolution DNS', ($ip !== '' && $ip !== $host) ? $ip : 'ECHEC (bloquant)');
echo "\n";

// ── 4. Connexion TCP ─────────────────────────────────────────
$scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
$port   = (int) (parse_url($base, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
$errno  = 0;
$errstr = '';
$sock   = @fsockopen(($scheme === 'https' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 6);
if ($sock) {
    line("Connexion TCP :{$port}", 'OK');
    fclose($sock);
} else {
    // Indicatif seulement : de nombreux hebergements desactivent fsockopen en
    // SSL alors que cURL fonctionne. Seul le test cURL ci-dessous fait foi.
    line("Connexion TCP :{$port}", 'indisponible (fsockopen bloque) - non bloquant');
}

// ── 5. Authentification (dryRun : ne publie rien) ────────────
if (!function_exists('curl_init')) {
    exit(">> cURL absent : impossible de tester l'authentification.\n");
}

$payload = json_encode([
    'secret'     => $parsed['secret'],
    'proposalId' => 'diagnostic',
    'author'     => 'Diagnostic',
    'content'    => '',
    'images'     => [],
    'site'       => defined('SITE_URL') ? SITE_URL : '',
    'dryRun'     => true,
], JSON_UNESCAPED_SLASHES);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

line('Requete HTTP', $err !== '' ? 'ECHEC' : 'HTTP ' . $code);
if ($err !== '') {
    echo "Erreur cURL : {$err}\n\n";
    echo ">> Le bot n'est pas joignable depuis le serveur MDT (voir etape 4).\n";
    exit;
}

echo "Reponse du bot : " . substr($body, 0, 600) . "\n\n";

if ($code >= 200 && $code < 300) {
    echo ">> LIAISON OPERATIONNELLE : la cle est acceptee par le bot.\n\n";

    // ── 6. Test de publication reelle (optionnel) ────────────
    // Utilise la VRAIE fonction de production, donc reproduit exactement le
    // parcours d'une suggestion envoyee depuis le dashboard staff.
    if (isset($_GET['publish'])) {
        echo "=== TEST DE PUBLICATION REELLE ========================\n\n";
        $ok = mdt_proposals_send_to_nexioz($db, [
            'id'       => 'test-' . date('His'),
            'username' => 'Diagnostic NexioZ',
            'content'  => "Message de test envoye depuis la page de diagnostic.\n"
                        . 'Si vous voyez cet embed sur Discord, la chaine complete fonctionne.',
            'images'   => [],
        ]);
        line('Envoi via la fonction reelle', $ok ? 'OK' : 'ECHEC');
        echo "\n";
        if ($ok) {
            echo ">> Un embed doit apparaitre dans le salon de publication.\n";
            echo "   - S'il apparait : la liaison fonctionne de bout en bout. Le probleme\n";
            echo "     se situe alors dans l'envoi du formulaire (pages/staff/dashboard.php).\n";
            echo "   - S'il n'apparait PAS : consultez la console du bot (Pterodactyl),\n";
            echo "     le motif exact y est desormais journalise.\n";
        } else {
            echo ">> Echec de l'envoi : le detail est dans error_log.txt (prefixe [NexioZ]).\n";
        }
    } else {
        echo "   Pour tester une publication reelle sur Discord, rechargez avec :\n";
        echo "   " . (defined('SITE_URL') ? SITE_URL : '') . "/pages/staff/nexioz-diagnostic.php?publish=1\n";
    }
} elseif ($code === 401) {
    echo ">> CLE REFUSEE : le bot ne reconnait pas ce secret.\n";
    echo "   Verifiez que le module 'Liaison MDT' ET la section 'Propositions'\n";
    echo "   sont actives et SAUVEGARDES sur le dashboard, puis recollez la cle.\n";
} elseif ($code === 400) {
    echo ">> REQUETE INVALIDE : voir le detail ci-dessus.\n";
} elseif ($code === 404) {
    echo ">> ENDPOINT INTROUVABLE : l'URL du bot est jointe mais /api/mdt/proposals\n";
    echo "   n'existe pas (version du bot non deployee ?).\n";
} else {
    echo ">> Reponse inattendue du bot (HTTP {$code}).\n";
}
