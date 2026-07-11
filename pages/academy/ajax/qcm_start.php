<?php
/**
 * Endpoint optionnel : renvoie les questions (sans les bonnes réponses)
 * pour un QCM donné. Sert si le front veut charger dynamiquement.
 */
require_once __DIR__ . '/../../../config/config.php';
academy_require_public();

$fk = $_GET['faction'] ?? '';
if (!academy_faction_exists($fk)) academy_json(['success' => false, 'error' => 'Faction invalide'], 400);

$uid = (int)$_SESSION['user_id'];
if (!academy_is_staff_bypass() && !academy_user_can_take_qcm($uid, $fk)) {
    academy_json(['success' => false, 'error' => 'QCM verrouillé : votre candidature doit être en statut « En examen ».'], 403);
}

$cfg = academy_qcm_config($fk);
$qs  = academy_qcm_pick_questions($fk, (int)$cfg['num_questions'], (bool)$cfg['randomize']);
$out = [];
foreach ($qs as $q) {
    $out[] = [
        'id'         => (int)$q['id'],
        'description'=> $q['description'],
        'options'    => array_values(array_filter([
            'A' => $q['option_a'] ?? null,
            'B' => $q['option_b'] ?? null,
            'C' => $q['option_c'] ?? null,
            'D' => $q['option_d'] ?? null,
        ], fn($v) => $v !== null && $v !== '')),
    ];
}
academy_json([
    'success'          => true,
    'questions'        => $out,
    'duration_minutes' => (int)$cfg['duration_minutes'],
    'passing_score'    => (int)$cfg['passing_score'],
]);
