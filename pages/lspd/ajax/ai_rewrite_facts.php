<?php
/**
 * pages/lspd/ajax/ai_rewrite_facts.php
 * ─────────────────────────────────────────────────────────────────────────
 * Réécrit UNIQUEMENT la section « Faits » d'un rapport LSPD à partir d'un
 * brouillon + du contexte factuel du formulaire, via la couche IA swappable
 * (includes/ai_writer.php). Ne réécrit rien d'autre, n'invente rien.
 *
 * Entrée  : POST JSON — { faits, moyen, localisation, secteur, comp_arrestation,
 *           comp_poste, cell_in, cell_out, miranda, amende, medical, identite,
 *           phone, date_arrestation, heure_arrestation, objets[], officers[],
 *           suspects[], civils[], temoins[], vehicules[], infractions[],
 *           type, titre, lieu_incident, date_incident }
 * Sortie  : JSON — { success:bool, text?:string, error?:string }
 *
 * En cas d'échec (clé absente, IA indisponible…), renvoie success:false avec un
 * message : le front conserve alors le brouillon tel quel (aucune régression).
 */

// Bloquer toute sortie parasite qui corromprait le JSON (cf. api_claude.php).
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
ob_clean();

function ai_facts_fail(string $msg, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Authentification + périmètre (mêmes règles que reports.php) ──────────────
if (empty($_SESSION['user_id'])) {
    ai_facts_fail('Non authentifié', 401);
}
$uf = $_SESSION['user_faction'] ?? '';
$allowed = in_array($uf, ['LSPD', 'LSSD', 'DOJ'], true)
    || hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal'])
    || (function_exists('is_demo_mode') && is_demo_mode());
if (!$allowed) {
    ai_facts_fail('Accès refusé', 403);
}

// ── Lecture du contexte ──────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$ctx = json_decode((string) $raw, true);
if (!is_array($ctx)) {
    ai_facts_fail('Requête invalide');
}

$faits = trim((string) ($ctx['faits'] ?? ''));
if ($faits === '') {
    ai_facts_fail('Aucun texte de faits à réécrire');
}

require_once ROOT_PATH . '/includes/ai_writer.php';
if (!mdt_ai_is_configured()) {
    ai_facts_fail("IA non configurée : ajoutez define('MDT_AI_API_KEY','…') dans config/config.local.php");
}

// ── Construction du prompt (strict : aucune invention) ───────────────────────
$clean = static function ($v): string {
    if (is_array($v)) $v = implode(', ', array_map(static fn($x) => trim((string) $x), array_filter($v, static fn($x) => trim((string) $x) !== '')));
    return trim((string) $v);
};

$fields = [
    'Type de rapport'                  => $clean($ctx['type'] ?? ''),
    'Titre du rapport'                 => $clean($ctx['titre'] ?? ''),
    "Identité de l'individu"           => $clean($ctx['identite'] ?? ''),
    'Numéro de téléphone du suspect'   => $clean($ctx['phone'] ?? ''),
    "Date de l'arrestation"            => $clean($ctx['date_arrestation'] ?? ''),
    "Heure de l'arrestation"           => $clean($ctx['heure_arrestation'] ?? ''),
    'Lecture des droits Miranda'       => $clean($ctx['miranda'] ?? ''),
    "Moyen d'information"              => $clean($ctx['moyen'] ?? ''),
    "Lieu de l'incident"               => $clean($ctx['lieu_incident'] ?? ''),
    'Localisation exacte'              => $clean($ctx['localisation'] ?? ''),
    'Secteur'                          => $clean($ctx['secteur'] ?? ''),
    "Comportement lors de l'arrestation" => $clean($ctx['comp_arrestation'] ?? ''),
    'Comportement au poste'            => $clean($ctx['comp_poste'] ?? ''),
    "Heure d'entrée en cellule"        => $clean($ctx['cell_in'] ?? ''),
    'Heure de sortie de cellule'       => $clean($ctx['cell_out'] ?? ''),
    'Amende'                           => $clean($ctx['amende'] ?? ''),
    'Intervention médicale'            => $clean($ctx['medical'] ?? ''),
    'Officiers impliqués'              => $clean($ctx['officers'] ?? ''),
    'Suspects'                         => $clean($ctx['suspects'] ?? ''),
    'Civils impliqués'                 => $clean($ctx['civils'] ?? ''),
    'Témoins'                          => $clean($ctx['temoins'] ?? ''),
    'Véhicules impliqués'              => $clean($ctx['vehicules'] ?? ''),
    'Objets saisis'                    => $clean($ctx['objets'] ?? ''),
    'Infractions retenues'             => $clean($ctx['infractions'] ?? ''),
];

$contextLines = '';
foreach ($fields as $label => $value) {
    if ($value !== '') {
        $contextLines .= '- ' . $label . ' : ' . $value . "\n";
    }
}
if ($contextLines === '') {
    $contextLines = "(aucune donnée structurée fournie)\n";
}

$system =
"Tu es un assistant de rédaction pour un officier du LSPD (jeu de rôle GTA RP). "
. "Ta seule tâche est de réécrire la section « Faits » d'un rapport de police à partir d'un brouillon et de données factuelles.\n\n"
. "RÈGLES STRICTES :\n"
. "- N'invente JAMAIS aucune information : aucun nom, heure, lieu, action, motif, dialogue ni détail qui ne figure pas explicitement dans les données fournies.\n"
. "- Utilise UNIQUEMENT les informations du contexte. Si une donnée est absente, ne la mentionne pas et n'extrapole pas.\n"
. "- Ne contredis jamais les données fournies.\n"
. "- Rédige en français, à la 3e personne, au passé, dans un style professionnel, factuel, sobre et neutre, adapté à un rapport de police officiel.\n"
. "- Corrige l'orthographe, la grammaire et la syntaxe ; améliore la clarté, la structure et la fluidité (déroulé chronologique).\n"
. "- Développe le récit sans ajouter de faits nouveaux.\n"
. "- Ne produis QUE le texte reformulé des faits : pas de titre, pas de préambule, pas de liste à puces, pas de méta-commentaire, pas de guillemets englobants.";

$user =
"BROUILLON DES FAITS (à réécrire) :\n\"\"\"\n" . $faits . "\n\"\"\"\n\n"
. "CONTEXTE FACTUEL DISPONIBLE (ne rien inventer au-delà) :\n" . $contextLines . "\n"
. "Réécris maintenant uniquement la section « Faits », en respectant scrupuleusement les règles ci-dessus.";

// ── Appel IA + réponse ───────────────────────────────────────────────────────
$res = mdt_ai_complete($system, $user, ['max_tokens' => 1200, 'temperature' => 0.35]);

if (!$res['ok'] || trim($res['text']) === '') {
    ai_facts_fail($res['error'] !== '' ? $res['error'] : 'Service IA indisponible');
}

echo json_encode(['success' => true, 'text' => trim($res['text'])], JSON_UNESCAPED_UNICODE);
