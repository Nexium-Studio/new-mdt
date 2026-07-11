<?php
/**
 * Helpers PHP du panel LSFD — chargés tôt (avant header.php) pour être
 * disponibles dans les handlers POST. Inclure après config/panel_access.
 */
if (!function_exists('lsfd_log')) {
    /** Journalise une action dans lsfd_activity_logs (silencieux si table absente). */
    function lsfd_log(string $actionType, string $resourceType = '', $resourceId = null, string $description = ''): void {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("INSERT INTO lsfd_activity_logs (user_id, action_type, resource_type, resource_id, description) VALUES (?,?,?,?,?)");
            $st->execute([$_SESSION['user_id'] ?? null, $actionType, $resourceType, $resourceId, mb_substr($description, 0, 500)]);
        } catch (Exception $e) { /* table absente : on ignore */ }
    }
}
if (!function_exists('lsfd_grades')) {
    /** Grades LSFD ordonnés (rank_order ASC = plus haut en premier). [] si DB absente. */
    function lsfd_grades(): array {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("SELECT fg.name, fg.rank_order FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id WHERE f.faction_key='LSFD' ORDER BY fg.rank_order ASC");
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { return []; }
    }
}
