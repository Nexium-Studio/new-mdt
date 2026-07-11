<?php
/**
 * lspd_log.php — Helper de journalisation des actions LSPD
 * 
 * Usage :
 *   require_once __DIR__ . '/lspd_log.php';
 *   lspd_log($db, $uid, 'promote', 'effectifs', $targetId, 'John Doe', 'Rookie → Cadet');
 *
 * @param PDO    $db           Connexion DB
 * @param int    $userId       ID de l'auteur de l'action
 * @param string $action       Clé d'action (ex: 'recruit', 'fire', 'add_report'…)
 * @param string $page         Page source (ex: 'effectifs', 'reports'…)
 * @param int|null $targetId   ID de la ressource affectée (optionnel)
 * @param string|null $targetLabel Libellé humain de la cible (optionnel)
 * @param string|null $details Texte libre ou JSON pour infos supplémentaires (optionnel)
 */
function lspd_log(
    PDO $db,
    int $userId,
    string $action,
    string $page,
    ?int $targetId    = null,
    ?string $targetLabel = null,
    ?string $details  = null
): void {
    try {
        // Récupérer username + grade si pas déjà en session
        $username    = function_exists('mdt_session_display_name') ? mdt_session_display_name() : ($_SESSION['user_username'] ?? '');
        $factionRank = $_SESSION['user_faction_rank']?? '';

        $db->prepare(
            "INSERT INTO lspd_activity_logs
                (user_id, username, faction_rank, action, page, target_id, target_label, details)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $userId,
            mb_substr($username,    0, 100),
            mb_substr($factionRank, 0, 100),
            mb_substr($action,      0, 80),
            mb_substr($page,        0, 60),
            $targetId,
            $targetLabel ? mb_substr($targetLabel, 0, 200) : null,
            $details,
        ]);
    } catch (Exception $e) {
        // Silencieux — ne pas bloquer l'action principale si le log échoue
        error_log('lspd_log error: ' . $e->getMessage());
    }
}