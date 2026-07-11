<?php
require_once __DIR__ . '/../../../config/config.php';
academy_require_public();

$fk = $_GET['faction'] ?? '';
if (!academy_faction_exists($fk)) academy_json(['success' => false, 'error' => 'Faction invalide'], 400);
if (!academy_csrf_check($_POST['csrf'] ?? null)) academy_json(['success' => false, 'error' => 'CSRF'], 403);

$uid = (int)$_SESSION['user_id'];
if (!academy_is_staff_bypass() && !academy_user_can_take_qcm($uid, $fk)) {
    academy_json(['success' => false, 'error' => 'QCM verrouillé : votre candidature doit être en statut « En examen ».'], 403);
}

$db = Database::getInstance()->getConnection();

$chosen = [];
foreach ($_POST as $k => $v) {
    if (strpos($k, 'q_') === 0) {
        $qid = (int)substr($k, 2);
        if ($qid > 0 && in_array($v, ['A','B','C','D'], true)) {
            $chosen[$qid] = $v;
        }
    }
}
if (empty($chosen)) academy_json(['success' => false, 'error' => 'Aucune réponse'], 400);

$ids = array_keys($chosen);
$ph  = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT id, correct FROM academy_qcm_questions WHERE id IN ($ph) AND faction_key = ?");
$stmt->execute([...$ids, $fk]);
$correct = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $correct[(int)$r['id']] = $r['correct'];

$score = 0; $total = count($chosen); $answers = [];
foreach ($chosen as $qid => $ans) {
    $ok = isset($correct[$qid]) && $correct[$qid] === $ans;
    if ($ok) $score++;
    $answers[] = ['question_id' => $qid, 'chosen' => $ans, 'correct' => $correct[$qid] ?? null];
}
$pct = $total > 0 ? (int)round($score * 100 / $total) : 0;
$cfg = academy_qcm_config($fk);
$passed = $pct >= (int)$cfg['passing_score'] ? 1 : 0;

try {
    $ins = $db->prepare('INSERT INTO academy_qcm_attempts (user_id, faction_key, score, total, percentage, passed, answers, completed_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $ins->execute([$uid, $fk, $score, $total, $pct, $passed, json_encode($answers, JSON_UNESCAPED_UNICODE)]);
} catch (Exception $e) {
    academy_json(['success' => false, 'error' => 'Erreur enregistrement : ' . $e->getMessage()], 500);
}

academy_json([
    'success'    => true,
    'score'      => $score,
    'total'      => $total,
    'percentage' => $pct,
    'passed'     => (bool)$passed,
]);
