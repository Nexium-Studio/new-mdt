<?php
/**
 * Matricule helper — source unique de vérité : table `academy_matricules`.
 * Réutilisé par effectifs, profil agent, équipement, armes, temps_service.
 *
 * Table (créée par pages/academy-central/includes/_matricules_engine.php) :
 *   academy_matricules(faction_key, number, user_id, assigned_by, assigned_at)
 */

/**
 * Retourne le matricule (numéro) assigné à un utilisateur pour une faction,
 * ou null si aucun matricule n'est attribué.
 */
function lspd_matricule_for_user(PDO $db, int $userId, string $factionKey = 'LSPD'): ?string
{
    if ($userId <= 0) return null;
    try {
        $stmt = $db->prepare(
            "SELECT number FROM academy_matricules
             WHERE faction_key = ? AND user_id = ?
             ORDER BY number LIMIT 1"
        );
        $stmt->execute([$factionKey, $userId]);
        $num = $stmt->fetchColumn();
        return ($num === false || $num === null) ? null : (string)$num;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Charge les matricules de plusieurs utilisateurs en une requête.
 * Retourne ['<user_id>' => '<number>', ...] (uniquement les users ayant un matricule).
 */
function lspd_matricules_map(PDO $db, array $userIds, string $factionKey = 'LSPD'): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)));
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $db->prepare(
            "SELECT user_id, number FROM academy_matricules
             WHERE faction_key = ? AND user_id IN ($placeholders)
             ORDER BY number"
        );
        $stmt->execute(array_merge([$factionKey], $ids));
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int)$row['user_id'];
            if (!isset($map[$uid])) $map[$uid] = (string)$row['number'];
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}
