<?php
/**
 * _qcm_engine.php
 * Moteur QCM public. Gate : l'utilisateur doit avoir une candidature
 * de la faction en statut 'en_examen' (v2 : refonte UX Academy).
 * Variable attendue : $__ACADEMY_FACTION (ex: 'LSPD').
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) {
    http_response_code(404);
    exit('Faction invalide');
}
academy_require_public();

$__fk    = $__ACADEMY_FACTION;
$__meta  = academy_faction_meta($__fk);
$db      = Database::getInstance()->getConnection();
$uid     = (int)$_SESSION['user_id'];
$pageTitle = 'QCM — ' . $__meta['name'];

if (!academy_is_staff_bypass() && !academy_user_can_take_qcm($uid, $__fk)) {
    $msg = 'Le QCM est débloqué uniquement lorsque votre candidature passe au statut « En examen ».';
    include __DIR__ . '/header.php';
    ?>
    <div class="page-hero">
      <div>
        <div class="page-eyebrow" style="color:<?php echo $__meta['color']; ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($__meta['icon']); ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
        <h1 class="page-title">QCM verrouillé</h1>
      </div>
      <a href="<?php echo SITE_URL; ?>/pages/academy/dashboard.php" class="ep-btn ep-btn-outline"><i class="fa-solid fa-arrow-left"></i> Retour</a>
    </div>
    <div class="ep-card"><div class="ep-card-bd" style="text-align:center;padding:2.5rem">
      <i class="fa-solid fa-lock" style="font-size:2rem;color:var(--muted-2);opacity:.5;display:block;margin-bottom:.75rem"></i>
      <div style="font-size:1rem;font-weight:700;margin-bottom:.4rem">Accès restreint</div>
      <div style="color:var(--muted-2);font-size:.85rem;max-width:480px;margin:0 auto"><?php echo htmlspecialchars($msg); ?></div>
      <a href="<?php echo SITE_URL; ?>/pages/academy/candidature_<?php echo $__meta['slug']; ?>.php" class="ep-btn ep-btn-purple" style="margin-top:1rem">
        <i class="fa-solid fa-file-signature"></i> Déposer ma candidature
      </a>
    </div></div>
    <?php include __DIR__ . '/footer.php'; return;
}

$cfg = academy_qcm_config($__fk);
$questions = academy_qcm_pick_questions($__fk, (int)$cfg['num_questions'], (bool)$cfg['randomize']);

include __DIR__ . '/header.php';
$csrf = academy_csrf_token();
?>
<div class="page-hero">
  <div>
    <div class="page-eyebrow" style="color:<?php echo $__meta['color']; ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($__meta['icon']); ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1 class="page-title">QCM de sélection</h1>
    <div class="page-sub"><?php echo (int)$cfg['num_questions']; ?> questions · <?php echo (int)$cfg['duration_minutes']; ?> min · Score min. <?php echo (int)$cfg['passing_score']; ?>%</div>
  </div>
  <a href="<?php echo SITE_URL; ?>/pages/academy/dashboard.php" class="ep-btn ep-btn-outline"><i class="fa-solid fa-arrow-left"></i> Quitter</a>
</div>

<?php if (empty($questions)): ?>
<div class="ep-card"><div class="ep-card-bd" style="text-align:center;padding:2.5rem">
  <i class="fa-solid fa-triangle-exclamation" style="font-size:2rem;color:var(--yellow);opacity:.6;display:block;margin-bottom:.75rem"></i>
  <div style="font-size:1rem;font-weight:700;margin-bottom:.4rem">Aucune question disponible</div>
  <div style="color:var(--muted-2);font-size:.85rem">La banque de questions est vide pour cette faction. Contactez l'Académie.</div>
</div></div>

<?php else: ?>
<div id="qcmScreen">

  <!-- Introduction -->
  <div class="ep-card" id="qcmIntro">
    <div class="ep-card-hd">
      <div class="ep-card-title"><i class="fa-solid fa-circle-info"></i> Consignes</div>
    </div>
    <div class="ep-card-bd">
      <ul style="padding-left:1.2rem;line-height:1.8;font-size:.88rem;color:var(--text)">
        <li>Vous disposez de <strong><?php echo (int)$cfg['duration_minutes']; ?> minutes</strong> pour répondre aux <strong><?php echo count($questions); ?> questions</strong>.</li>
        <li>Une seule réponse possible par question.</li>
        <li>Le score minimum pour réussir est de <strong><?php echo (int)$cfg['passing_score']; ?>%</strong>.</li>
        <li>Le résultat est visible par l'équipe de recrutement une fois soumis.</li>
      </ul>
      <button type="button" class="ep-btn ep-btn-purple" style="margin-top:1rem" onclick="qcmStart()">
        <i class="fa-solid fa-play"></i> Commencer le QCM
      </button>
    </div>
  </div>

  <!-- Progression + Timer -->
  <div id="qcmRun" style="display:none">
    <div class="qcm-progress">
      <div style="font-size:.88rem;font-weight:700"><span id="qcmQCur">1</span> / <?php echo count($questions); ?></div>
      <div class="qcm-bar"><div class="qcm-bar-fill" id="qcmProg" style="width:0%"></div></div>
      <div class="qcm-timer"><i class="fa-solid fa-clock"></i> <span id="qcmTime"><?php echo (int)$cfg['duration_minutes']; ?>:00</span></div>
    </div>

    <form id="qcmForm" onsubmit="return qcmSubmit(event)">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <?php foreach ($questions as $idx => $q):
        $ord = $idx + 1;
      ?>
      <div class="qcm-q" data-qidx="<?php echo $idx; ?>" style="<?php echo $idx > 0 ? 'display:none' : ''; ?>">
        <div class="qcm-q-title">Q<?php echo $ord; ?>. <?php echo htmlspecialchars($q['description']); ?></div>
        <div class="qcm-opts">
          <?php foreach (['A','B','C','D'] as $L):
            $opt = $q['option_' . strtolower($L)] ?? '';
            if ($opt === '' || $opt === null) continue;
          ?>
          <label class="qcm-opt">
            <input type="radio" name="q_<?php echo (int)$q['id']; ?>" value="<?php echo $L; ?>" onchange="qcmSel(this)">
            <span><strong><?php echo $L; ?>.</strong> <?php echo htmlspecialchars($opt); ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <div style="display:flex;justify-content:space-between;gap:.75rem;margin-top:1rem">
        <button type="button" class="ep-btn" onclick="qcmPrev()"><i class="fa-solid fa-chevron-left"></i> Précédent</button>
        <button type="button" class="ep-btn ep-btn-primary" id="qcmNextBtn" onclick="qcmNext()"><i class="fa-solid fa-chevron-right"></i> Suivant</button>
        <button type="submit" class="ep-btn ep-btn-purple" id="qcmSubmitBtn" style="display:none"><i class="fa-solid fa-paper-plane"></i> Soumettre</button>
      </div>
    </form>
  </div>

  <!-- Résultat -->
  <div id="qcmResult" style="display:none">
    <div class="ep-card">
      <div class="ep-card-bd" style="text-align:center;padding:2.5rem">
        <div id="qcmResIcon" style="font-size:3rem;margin-bottom:.75rem"></div>
        <div id="qcmResTitle" style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:700;margin-bottom:.4rem"></div>
        <div id="qcmResScore" style="font-size:2rem;font-weight:800;margin:1rem 0"></div>
        <div id="qcmResMsg" style="color:var(--muted-2);font-size:.9rem;max-width:520px;margin:0 auto"></div>
        <a href="<?php echo SITE_URL; ?>/pages/academy/dashboard.php" class="ep-btn ep-btn-primary" style="margin-top:1.25rem">
          <i class="fa-solid fa-arrow-left"></i> Retour à l'académie
        </a>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const total = <?php echo count($questions); ?>;
  const duration = <?php echo (int)$cfg['duration_minutes']; ?> * 60;
  const passing = <?php echo (int)$cfg['passing_score']; ?>;
  let cur = 0, timer = null, remain = duration;

  window.qcmStart = function(){
    document.getElementById('qcmIntro').style.display = 'none';
    document.getElementById('qcmRun').style.display = 'block';
    timer = setInterval(tick, 1000);
    tick();
    updNavBtns();
  };
  window.qcmSel = function(inp){
    const parent = inp.closest('.qcm-q');
    parent.querySelectorAll('.qcm-opt').forEach(o => o.classList.remove('selected'));
    inp.closest('.qcm-opt').classList.add('selected');
  };
  window.qcmNext = function(){
    if (cur < total - 1) { cur++; render(); }
  };
  window.qcmPrev = function(){
    if (cur > 0) { cur--; render(); }
  };
  function render(){
    document.querySelectorAll('.qcm-q').forEach((el, i) => el.style.display = (i === cur ? '' : 'none'));
    document.getElementById('qcmQCur').textContent = (cur + 1);
    document.getElementById('qcmProg').style.width = (((cur + 1) / total) * 100) + '%';
    updNavBtns();
  }
  function updNavBtns(){
    const isLast = cur === total - 1;
    document.getElementById('qcmNextBtn').style.display = isLast ? 'none' : '';
    document.getElementById('qcmSubmitBtn').style.display = isLast ? '' : 'none';
  }
  function tick(){
    remain--;
    if (remain <= 0) { clearInterval(timer); document.getElementById('qcmForm').requestSubmit(); return; }
    const m = Math.floor(remain / 60), s = remain % 60;
    document.getElementById('qcmTime').textContent = m + ':' + (s < 10 ? '0' + s : s);
  }
  window.qcmSubmit = function(ev){
    ev.preventDefault();
    if (timer) clearInterval(timer);
    const form = document.getElementById('qcmForm');
    const data = new FormData(form);
    fetch('<?php echo SITE_URL; ?>/pages/academy/ajax/qcm_submit.php?faction=<?php echo urlencode($__fk); ?>', {
      method: 'POST', body: data
    }).then(r => r.json()).then(res => {
      document.getElementById('qcmRun').style.display = 'none';
      document.getElementById('qcmResult').style.display = 'block';
      if (res && res.success) {
        const passed = res.passed;
        document.getElementById('qcmResIcon').innerHTML = passed
          ? '<i class="fa-solid fa-circle-check" style="color:#10b981"></i>'
          : '<i class="fa-solid fa-circle-xmark" style="color:#ef4444"></i>';
        document.getElementById('qcmResTitle').textContent = passed ? 'QCM réussi' : 'QCM non validé';
        document.getElementById('qcmResScore').textContent = res.score + ' / ' + res.total + ' (' + res.percentage + '%)';
        document.getElementById('qcmResMsg').textContent = passed
          ? 'Félicitations, vous avez atteint le seuil de ' + passing + '% requis. L\'équipe de recrutement sera informée.'
          : 'Score insuffisant (minimum ' + passing + '%). Le résultat est transmis à l\'équipe.';
      } else {
        document.getElementById('qcmResTitle').textContent = 'Erreur';
        document.getElementById('qcmResMsg').textContent = (res && res.error) || 'Une erreur est survenue.';
      }
    }).catch(() => {
      document.getElementById('qcmRun').style.display = 'none';
      document.getElementById('qcmResult').style.display = 'block';
      document.getElementById('qcmResTitle').textContent = 'Erreur réseau';
    });
    return false;
  };
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
