<?php
/**
 * _candidature_engine.php
 * Moteur partagé — rendu formulaire dynamique + submission.
 * Variables attendues : $__ACADEMY_FACTION (code faction, ex: 'LSPD').
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) {
    http_response_code(404);
    exit('Faction invalide');
}
academy_require_public();

$__fk    = $__ACADEMY_FACTION;
$__meta  = academy_faction_meta($__fk);
$__cfg   = academy_candidature_config($__fk);
$db      = Database::getInstance()->getConnection();
$uid     = (int)$_SESSION['user_id'];
$pageTitle = 'Candidature — ' . $__meta['name'];

$msg = ''; $msgType = '';
$csrf = academy_csrf_token();

// Candidature existante
$existing = null;
try {
    $s = $db->prepare('SELECT * FROM academy_candidatures WHERE user_id = ? AND faction_key = ? ORDER BY created_at DESC LIMIT 1');
    $s->execute([$uid, $__fk]);
    $existing = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$canPost = !$existing || in_array($existing['status'], ['refuse'], true);
$isActive = (int)($__cfg['is_active'] ?? 1) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canPost && $isActive) {
    if (!academy_csrf_check($_POST['csrf'] ?? null)) {
        $msg = 'Session expirée, rechargez la page.'; $msgType = 'err';
    } else {
        $data = [];
        foreach (($__cfg['fields'] ?? []) as $f) {
            $key = $f['key'] ?? '';
            if ($key === '') continue;
            $v = $_POST['f_' . $key] ?? '';
            if (is_array($v)) $v = '';
            $v = trim((string)$v);
            if (!empty($f['required']) && $v === '') {
                $msg = 'Le champ « ' . ($f['label'] ?? $key) . ' » est obligatoire.'; $msgType = 'err'; break;
            }
            $type = $f['type'] ?? 'texte_court';
            if ($type === 'nombre' && $v !== '' && !is_numeric($v)) {
                $msg = 'Le champ « ' . ($f['label'] ?? $key) . ' » doit être un nombre.'; $msgType = 'err'; break;
            }
            if ($type === 'date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $msg = 'Le champ « ' . ($f['label'] ?? $key) . ' » doit être une date valide.'; $msgType = 'err'; break;
            }
            if ($type === 'texte_court' && mb_strlen($v) > 255) $v = mb_substr($v, 0, 255);
            if ($type === 'texte_long'  && mb_strlen($v) > 5000) $v = mb_substr($v, 0, 5000);
            $data[$key] = $v;
        }
        if ($msgType !== 'err') {
            try {
                $ins = $db->prepare('INSERT INTO academy_candidatures (user_id, faction_key, status, form_data) VALUES (?,?,?,?)');
                $ins->execute([$uid, $__fk, 'en_attente', json_encode($data, JSON_UNESCAPED_UNICODE)]);
                $msg = 'Candidature envoyée avec succès.'; $msgType = 'ok';
                $existing = ['id' => $db->lastInsertId(), 'user_id' => $uid, 'faction_key' => $__fk, 'status' => 'en_attente', 'form_data' => json_encode($data), 'created_at' => date('Y-m-d H:i:s')];
                $canPost = false;
            } catch (Exception $e) {
                $msg = 'Erreur à l\'enregistrement : ' . $e->getMessage(); $msgType = 'err';
            }
        }
    }
}

include __DIR__ . '/header.php';
$statuses = academy_candidature_statuses();
$st = $existing ? ($statuses[$existing['status']] ?? null) : null;
?>

<div class="page-hero fade-in">
  <div>
    <div class="page-eyebrow" style="color:<?php echo $__meta['color']; ?>">
      <i class="fa-solid fa-<?php echo htmlspecialchars($__meta['icon']); ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?>
    </div>
    <h1 class="page-title"><?php echo htmlspecialchars($__cfg['name'] ?? ('Candidature ' . $__meta['name'])); ?></h1>
    <div class="page-sub"><?php echo htmlspecialchars($__cfg['description'] ?? ''); ?></div>
  </div>
  <a href="<?php echo SITE_URL; ?>/pages/academy/dashboard.php" class="ep-btn ep-btn-outline">
    <i class="fa-solid fa-arrow-left"></i> Retour
  </a>
</div>

<?php if ($msg): ?>
<div class="ep-alert <?php echo $msgType; ?>"><i class="fa-solid fa-<?php echo $msgType === 'ok' ? 'check-circle' : 'exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<?php if (!$isActive): ?>
<div class="ep-card"><div class="ep-card-bd" style="text-align:center;padding:2.5rem">
  <i class="fa-solid fa-lock" style="font-size:2rem;color:var(--muted-2);opacity:.5;display:block;margin-bottom:.75rem"></i>
  <div style="font-size:1rem;font-weight:700;margin-bottom:.4rem">Candidatures fermées</div>
  <div style="color:var(--muted-2);font-size:.85rem">Cette faction n'accepte pas de candidature pour le moment.</div>
</div></div>

<?php elseif ($existing && !$canPost): ?>
<div class="ep-card">
  <div class="ep-card-hd">
    <div class="ep-card-title"><i class="fa-solid fa-file-circle-check"></i> Votre candidature</div>
    <?php if ($st): ?>
    <span class="ep-badge" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>">
      <i class="fa-solid fa-<?php echo $st['icon']; ?>"></i> <?php echo $st['label']; ?>
    </span>
    <?php endif; ?>
  </div>
  <div class="ep-card-bd">
    <div style="font-size:.85rem;color:var(--muted-2);margin-bottom:.85rem">
      Déposée le <?php echo date('d/m/Y à H:i', strtotime($existing['created_at'])); ?>.
      <?php if ($existing['status'] === 'en_examen'): ?>
      Vous pouvez maintenant passer le QCM.
      <?php endif; ?>
    </div>
    <?php if (!empty($existing['decision_note'])): ?>
    <div class="ep-alert info"><i class="fa-solid fa-comment-dots"></i> <?php echo nl2br(htmlspecialchars($existing['decision_note'])); ?></div>
    <?php endif; ?>

    <?php
    $submitted = json_decode($existing['form_data'] ?? '[]', true) ?: [];
    if (!empty($submitted)): ?>
    <div style="background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;padding:1rem;margin-top:.75rem">
      <div class="ep-label" style="margin-bottom:.75rem">Réponses déposées</div>
      <?php foreach (($__cfg['fields'] ?? []) as $f):
        $v = $submitted[$f['key']] ?? '—'; ?>
        <div style="padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.85rem">
          <div class="ep-label"><?php echo htmlspecialchars($f['label'] ?? $f['key']); ?></div>
          <div style="white-space:pre-wrap"><?php echo htmlspecialchars((string)$v); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($existing['status'] === 'en_examen'): ?>
    <a href="<?php echo SITE_URL; ?>/pages/academy/qcm_<?php echo $__meta['slug']; ?>.php" class="ep-btn ep-btn-purple" style="margin-top:1rem">
      <i class="fa-solid fa-clipboard-question"></i> Passer le QCM
    </a>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="ep-card">
  <div class="ep-card-hd">
    <div class="ep-card-title"><i class="fa-solid fa-file-signature"></i> Formulaire de candidature</div>
  </div>
  <div class="ep-card-bd">
    <?php if (empty($__cfg['fields'])): ?>
      <div style="text-align:center;padding:2rem;color:var(--muted-2)">Aucun champ n'est configuré pour cette candidature.</div>
    <?php else: ?>
    <form method="POST" class="dyn-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <?php
      $fields = $__cfg['fields'];
      usort($fields, fn($a,$b) => ((int)($a['order']??0)) <=> ((int)($b['order']??0)));
      foreach ($fields as $f):
        $k = $f['key'] ?? ''; if ($k === '') continue;
        $label = $f['label'] ?? $k;
        $req = !empty($f['required']);
        $type = $f['type'] ?? 'texte_court';
        $ph = $f['placeholder'] ?? '';
      ?>
      <div class="dyn-field">
        <label for="f_<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?><?php if ($req): ?><span class="req">*</span><?php endif; ?></label>
        <?php if ($type === 'texte_long'): ?>
          <textarea id="f_<?php echo htmlspecialchars($k); ?>" name="f_<?php echo htmlspecialchars($k); ?>" class="ep-textarea" placeholder="<?php echo htmlspecialchars($ph); ?>" <?php if ($req) echo 'required'; ?>></textarea>
        <?php elseif ($type === 'nombre'): ?>
          <input id="f_<?php echo htmlspecialchars($k); ?>" name="f_<?php echo htmlspecialchars($k); ?>" type="number" class="ep-input" placeholder="<?php echo htmlspecialchars($ph); ?>" <?php if ($req) echo 'required'; ?>>
        <?php elseif ($type === 'date'): ?>
          <input id="f_<?php echo htmlspecialchars($k); ?>" name="f_<?php echo htmlspecialchars($k); ?>" type="date" class="ep-input" <?php if ($req) echo 'required'; ?>>
        <?php else: ?>
          <input id="f_<?php echo htmlspecialchars($k); ?>" name="f_<?php echo htmlspecialchars($k); ?>" type="text" class="ep-input" placeholder="<?php echo htmlspecialchars($ph); ?>" maxlength="255" <?php if ($req) echo 'required'; ?>>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div>
        <button type="submit" class="ep-btn ep-btn-purple"><i class="fa-solid fa-paper-plane"></i> Envoyer la candidature</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
