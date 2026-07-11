<?php
/**
 * Séquences LSM (VEH / VTE / PLQ) scopées par dealership_key.
 * Utilisé par LS Motors et panels concessionnaires basés sur le même schéma.
 */
function lsm_sequence_next(PDO $db, string $dealershipKey, string $type, string $prefix): string
{
    $allowed = ['VEH' => true, 'VTE' => true, 'PLQ' => true];
    if (!isset($allowed[$type])) {
        throw new InvalidArgumentException('Type séquence invalide');
    }
    $ownTransaction = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $ownTransaction = true;
    }
    try {
        $st = $db->prepare('SELECT * FROM lsm_sequences WHERE dealership_key=? AND type=? AND annee=? FOR UPDATE');
        $year = (int) date('Y');
        $st->execute([$dealershipKey, $type, $year]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $next = ($r && (int) $r['annee'] === $year) ? (int) $r['dernier'] + 1 : 1;
        $db->prepare(
            'INSERT INTO lsm_sequences (dealership_key, type, annee, dernier) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE annee=VALUES(annee), dernier=VALUES(dernier)'
        )->execute([$dealershipKey, $type, $year, $next]);
        if ($ownTransaction) {
            $db->commit();
        }
        return $prefix . $year . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        if ($ownTransaction) {
            $db->rollBack();
        }
        throw $e;
    }
}
