<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

ob_clean();
if (!isset($_GET['id'])) { echo json_encode(['success'=>false,'message'=>'ID manquant']); exit; }

$db = Database::getInstance()->getConnection();
try {
    // Rédacteur affiché en Nom + Prénom (profil), jamais le pseudo (règle SAMS §5)
    $stmt=$db->prepare("
        SELECT sr.*,
               CONCAT(p.firstname,' ',p.lastname) AS patient_name,
               cp.firstname AS creator_fn, cp.lastname AS creator_ln
        FROM sams_reports sr
        LEFT JOIN profiles p ON p.id=sr.patient_id
        LEFT JOIN users u ON u.id=sr.created_by
        LEFT JOIN profiles cp ON cp.id=u.profile_id
        WHERE sr.id=?
    ");
    $stmt->execute([intval($_GET['id'])]);
    $report=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) { echo json_encode(['success'=>false,'message'=>'Rapport introuvable']); exit; }

    $creator = mdt_rp_display_name($report['creator_fn'] ?? null, $report['creator_ln'] ?? null, '');
    $report['creator_name'] = ($creator === '' || $creator === '—') ? '—' : $creator;
    unset($report['creator_fn'], $report['creator_ln']);

    // Résolution des médecins impliqués → Nom + Prénom
    $docIds = json_decode($report['doctors_involved'] ?? '[]', true) ?: [];
    $docNames = [];
    if ($docIds) {
        $in = implode(',', array_fill(0, count($docIds), '?'));
        $ds = $db->prepare("SELECT u.id, pr.firstname, pr.lastname FROM users u LEFT JOIN profiles pr ON pr.id=u.profile_id WHERE u.id IN ($in)");
        $ds->execute(array_map('intval', $docIds));
        foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $n = mdt_rp_display_name($d['firstname'] ?? null, $d['lastname'] ?? null, '');
            $docNames[] = ($n === '' || $n === '—') ? 'Profil non renseigné' : $n;
        }
    }
    $report['doctors_names'] = $docNames;

    // Civils impliqués (colonne optionnelle)
    $civNames = [];
    if (array_key_exists('civilians_involved', $report)) {
        $civIds = json_decode($report['civilians_involved'] ?? '[]', true) ?: [];
        if ($civIds) {
            $in = implode(',', array_fill(0, count($civIds), '?'));
            $cs = $db->prepare("SELECT CONCAT(firstname,' ',lastname) AS n FROM profiles WHERE id IN ($in)");
            $cs->execute(array_map('intval', $civIds));
            $civNames = array_column($cs->fetchAll(PDO::FETCH_ASSOC), 'n');
        }
    }
    $report['civilians_names'] = $civNames;

    // Véhicules impliqués (colonne optionnelle)
    $vehNames = [];
    if (array_key_exists('vehicles_involved', $report)) {
        $vehIds = json_decode($report['vehicles_involved'] ?? '[]', true) ?: [];
        if ($vehIds) {
            $in = implode(',', array_fill(0, count($vehIds), '?'));
            try {
                $vs = $db->prepare("SELECT CONCAT(model,' (',plate,')') AS n FROM vehicles WHERE id IN ($in)");
                $vs->execute(array_map('intval', $vehIds));
                $vehNames = array_column($vs->fetchAll(PDO::FETCH_ASSOC), 'n');
            } catch (Exception $e) {}
        }
    }
    $report['vehicles_names'] = $vehNames;

    echo json_encode(['success'=>true,'report'=>$report]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
