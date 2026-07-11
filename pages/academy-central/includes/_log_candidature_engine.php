<?php
/**
 * _log_candidature_engine.php — table des candidatures d'une faction
 * + modal de changement de statut avec note.
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) {
    http_response_code(404); exit('Faction invalide');
}
$__fk = $__ACADEMY_FACTION;
$__meta = academy_faction_meta($__fk);
$__slug = $__meta['slug'];
academy_require_access('log_candidature_' . $__slug);

$pageTitle  = 'Candidatures — ' . $__meta['name'];
$breadcrumb = [['label' => $__meta['name']], ['label' => 'Candidatures']];
$db   = Database::getInstance()->getConnection();
$uid  = (int)$_SESSION['user_id'];
$canEdit = academy_user_can($uid, 'log_candidature_' . $__slug, 'edit') || academy_is_staff_bypass();
$csrf = academy_csrf_token();

$filter = $_GET['status'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT c.*, u.username, u.avatar FROM academy_candidatures c LEFT JOIN users u ON u.id = c.user_id WHERE c.faction_key = ?";
$params = [$__fk];
$statuses = academy_candidature_statuses();
if ($filter !== '' && array_key_exists($filter, $statuses)) {
    $sql .= ' AND c.status = ?'; $params[] = $filter;
}
if ($q !== '') {
    $sql .= ' AND u.username LIKE ?'; $params[] = '%' . $q . '%';
}
$sql .= ' ORDER BY c.created_at DESC LIMIT 300';
try { $stmt = $db->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $rows = []; }

// KPIs : compte par statut (sur l'ensemble, pas filtré)
$counts = ['en_attente' => 0, 'en_cours' => 0, 'en_examen' => 0, 'valide' => 0, 'refuse' => 0, 'all' => 0];
try {
    $kpi = $db->prepare('SELECT status, COUNT(*) AS n FROM academy_candidatures WHERE faction_key = ? GROUP BY status');
    $kpi->execute([$__fk]);
    foreach ($kpi->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[$r['status']] = (int)$r['n'];
        $counts['all'] += (int)$r['n'];
    }
} catch (Exception $e) {}

$cfg = academy_candidature_config($__fk);

[$ar,$ag,$ab] = sscanf($__meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$__meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";

include __DIR__ . '/header.php';
?>

<style><?php echo $facCss; ?></style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-file-signature"></i></span> Candidatures</h1>
    <p>Traitez les candidatures reçues : changez le statut et ajoutez une note de décision.</p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/config_candidature_<?php echo $__slug; ?>.php" class="btn btn-secondary"><i class="fa-solid fa-sliders"></i> Configurer le formulaire</a>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-inbox"></i></div><div class="stat-info">
    <div class="stat-label">Total</div><div class="stat-value"><?php echo $counts['all']; ?></div>
    <div class="stat-meta">candidatures reçues</div>
  </div></div>
  <div class="stat-card stat-yellow"><div class="stat-ic"><i class="fa-solid fa-hourglass-half"></i></div><div class="stat-info">
    <div class="stat-label">En attente</div><div class="stat-value"><?php echo $counts['en_attente']; ?></div>
    <div class="stat-meta">à examiner</div>
  </div></div>
  <div class="stat-card stat-orange"><div class="stat-ic"><i class="fa-solid fa-spinner"></i></div><div class="stat-info">
    <div class="stat-label">En cours</div><div class="stat-value"><?php echo $counts['en_cours']; ?></div>
    <div class="stat-meta">en revue</div>
  </div></div>
  <div class="stat-card stat-cyan"><div class="stat-ic"><i class="fa-solid fa-clipboard-question"></i></div><div class="stat-info">
    <div class="stat-label">En examen</div><div class="stat-value"><?php echo $counts['en_examen']; ?></div>
    <div class="stat-meta">QCM débloqué</div>
  </div></div>
  <div class="stat-card stat-green"><div class="stat-ic"><i class="fa-solid fa-circle-check"></i></div><div class="stat-info">
    <div class="stat-label">Validées</div><div class="stat-value"><?php echo $counts['valide']; ?></div>
    <div class="stat-meta">acceptées</div>
  </div></div>
  <div class="stat-card stat-red"><div class="stat-ic"><i class="fa-solid fa-circle-xmark"></i></div><div class="stat-info">
    <div class="stat-label">Refusées</div><div class="stat-value"><?php echo $counts['refuse']; ?></div>
    <div class="stat-meta">rejetées</div>
  </div></div>
</div>

<form method="GET" class="toolbar">
  <div class="grow search-input">
    <i class="fa-solid fa-search"></i>
    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control" placeholder="Rechercher un candidat…">
  </div>
  <select name="status" class="form-control" style="max-width:200px;height:36px" onchange="this.form.submit()">
    <option value="">Tous les statuts</option>
    <?php foreach ($statuses as $sk => $sm): ?>
    <option value="<?php echo $sk; ?>" <?php echo $filter === $sk ? 'selected' : ''; ?>><?php echo $sm['label']; ?> (<?php echo $counts[$sk] ?? 0; ?>)</option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Filtrer</button>
  <?php if ($filter || $q): ?>
  <a href="?" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Réinitialiser</a>
  <?php endif; ?>
</form>

<div class="card">
  <?php if (empty($rows)): ?>
  <div class="empty-state">
    <i class="fa-solid fa-inbox"></i>
    <div class="es-title">Aucune candidature</div>
    <p>Les candidatures soumises sur la page publique apparaîtront ici en temps réel.</p>
  </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr><th>#</th><th>Candidat</th><th>Statut</th><th>Déposée le</th><th>Décidée le</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $st = $statuses[$r['status']] ?? null;
        $fd = json_decode($r['form_data'] ?? '[]', true) ?: [];
        $initial = strtoupper(mb_substr($r['username'] ?? 'U', 0, 1));
      ?>
      <tr>
        <td><span class="row-muted mono">#<?php echo str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT); ?></span></td>
        <td>
          <div class="usr">
            <div class="usr-ava">
              <?php if (!empty($r['avatar'])): ?>
                <img src="<?php echo htmlspecialchars($r['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:7px">
              <?php else: echo $initial; endif; ?>
            </div>
            <div>
              <div class="row-strong"><?php echo htmlspecialchars($r['username'] ?? '—'); ?></div>
              <div class="row-muted">user #<?php echo (int)$r['user_id']; ?></div>
            </div>
          </div>
        </td>
        <td><?php if ($st): ?><span class="badge" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>"><i class="fa-solid fa-<?php echo $st['icon']; ?>"></i> <?php echo $st['label']; ?></span><?php endif; ?></td>
        <td><span class="row-muted"><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></span></td>
        <td><span class="row-muted"><?php echo $r['decided_at'] ? date('d/m/Y H:i', strtotime($r['decided_at'])) : '—'; ?></span></td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick='openCandDetail(<?php echo json_encode([
              'id' => (int)$r['id'],
              'status' => $r['status'],
              'form' => $fd,
              'note' => $r['decision_note'] ?? '',
              'fields' => $cfg['fields'] ?? [],
              'username' => $r['username'] ?? '—',
              'createdAt' => $r['created_at'],
              'decidedAt' => $r['decided_at'] ?? null,
              'canEdit' => $canEdit,
          ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fa-solid fa-eye"></i> Voir</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- MODAL DETAIL -->
<div class="modal-overlay" id="candModal">
  <div class="modal" style="max-width:680px">
    <div class="modal-header">
      <h3 id="candTitle">Candidature</h3>
      <button class="modal-close" onclick="document.getElementById('candModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="candMeta" style="margin-bottom:16px;padding:10px 14px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:9px;font-size:.78rem;color:var(--text-secondary);display:flex;gap:18px;flex-wrap:wrap"></div>
    <div id="candBody" style="max-height:48vh;overflow-y:auto;padding-right:6px"></div>
    <?php if ($canEdit): ?>
    <div style="margin-top:18px;border-top:1px solid var(--border);padding-top:18px">
      <div class="grid-2">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Nouveau statut</label>
          <select id="candStatus" class="form-control">
            <?php foreach ($statuses as $sk => $sm): ?>
            <option value="<?php echo $sk; ?>"><?php echo $sm['label']; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Décidé par</label>
          <input class="form-control" value="<?php echo htmlspecialchars($_SESSION['user_username'] ?? ''); ?>" disabled>
        </div>
      </div>
      <div class="form-group" style="margin-top:14px">
        <label class="form-label">Note de décision (visible par le candidat)</label>
        <textarea id="candNote" class="form-control" rows="3" placeholder="Motivez votre décision, donnez une consigne au candidat…"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" onclick="document.getElementById('candModal').classList.remove('open')">Annuler</button>
        <button class="btn btn-purple" onclick="saveCandStatus()"><i class="fa-solid fa-check"></i> Enregistrer</button>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
let __curCand = null;
function openCandDetail(c){
  __curCand = c;
  document.getElementById('candTitle').textContent = 'Candidature de ' + c.username;
  // Méta
  const meta = document.getElementById('candMeta');
  meta.innerHTML =
    '<span><i class="fa-solid fa-calendar-plus"></i> Déposée : <strong style="color:#fff">' + new Date(c.createdAt.replace(' ','T')).toLocaleString('fr-FR') + '</strong></span>' +
    (c.decidedAt ? '<span><i class="fa-solid fa-gavel"></i> Décidée : <strong style="color:#fff">' + new Date(c.decidedAt.replace(' ','T')).toLocaleString('fr-FR') + '</strong></span>' : '');
  // Réponses
  let html = '<div style="display:flex;flex-direction:column;gap:12px">';
  if (!c.fields || c.fields.length === 0) {
    html += '<div style="text-align:center;color:var(--text-secondary);font-size:.85rem;padding:14px 0">Aucun champ configuré pour ce formulaire.</div>';
  } else {
    c.fields.forEach(f => {
      const v = (c.form && c.form[f.key] !== undefined && c.form[f.key] !== '') ? c.form[f.key] : '—';
      html += '<div style="background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;padding:10px 12px">' +
              '<div style="font-size:.68rem;text-transform:uppercase;color:var(--text-secondary);font-weight:700;letter-spacing:.06em;margin-bottom:5px">' + escapeHtml(f.label || f.key) + '</div>' +
              '<div style="white-space:pre-wrap;font-size:.88rem;color:#cbd5e1">' + escapeHtml(String(v)) + '</div></div>';
    });
  }
  if (c.note) {
    html += '<div style="background:rgba(139,92,246,.06);border:1px solid rgba(139,92,246,.2);border-radius:10px;padding:10px 12px;margin-top:6px">' +
            '<div style="font-size:.68rem;text-transform:uppercase;color:#c4b5fd;font-weight:700;letter-spacing:.06em;margin-bottom:5px"><i class="fa-solid fa-comment-dots"></i> Note actuelle</div>' +
            '<div style="white-space:pre-wrap;font-size:.85rem;color:#cbd5e1">' + escapeHtml(c.note) + '</div></div>';
  }
  html += '</div>';
  document.getElementById('candBody').innerHTML = html;
  const sel = document.getElementById('candStatus'); if (sel) sel.value = c.status;
  const note = document.getElementById('candNote'); if (note) note.value = c.note || '';
  document.getElementById('candModal').classList.add('open');
}
async function saveCandStatus(){
  if (!__curCand) return;
  const fd = new FormData();
  fd.append('csrf', '<?php echo htmlspecialchars($csrf); ?>');
  fd.append('id', __curCand.id);
  fd.append('status', document.getElementById('candStatus').value);
  fd.append('note', document.getElementById('candNote').value);
  try {
    const r = await fetch('<?php echo SITE_URL; ?>/pages/academy-central/ajax/candidature_update_status.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (j && j.success) { acToast('Candidature mise à jour'); setTimeout(() => location.reload(), 500); }
    else acToast((j && j.error) || 'Erreur', true);
  } catch(e) { acToast('Erreur réseau', true); }
}
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
</script>

<?php include __DIR__ . '/footer.php'; ?>
