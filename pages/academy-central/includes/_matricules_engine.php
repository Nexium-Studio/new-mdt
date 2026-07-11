<?php
/**
 * _matricules_engine.php — gestion des matricules d'une faction.
 * Refonte v2 : design moderne (KPI, filtres, grille, modale citoyen+grade).
 * Variable attendue : $__ACADEMY_FACTION (ex: 'LSPD').
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) {
    http_response_code(404); exit('Faction invalide');
}
$__fk    = $__ACADEMY_FACTION;
$__meta  = academy_faction_meta($__fk);
$__slug  = $__meta['slug'];
academy_require_access('matricules_' . $__slug);
$canEdit = academy_user_can((int)$_SESSION['user_id'], 'matricules_' . $__slug, 'edit') || academy_is_staff_bypass();

$pageTitle  = 'Matricules — ' . $__meta['name'];
$breadcrumb = [
    ['label' => $__meta['name']],
    ['label' => 'Matricules'],
];
$db   = Database::getInstance()->getConnection();
$csrf = academy_csrf_token();

// ── 1. SYNC slots si ranges configurés ────────────────────────────────────
$ranges = academy_matricule_ranges($__fk);
academy_matricule_sync_slots($__fk);

// ── 2. Force-resync explicite (bouton staff) ──────────────────────────────
if (isset($_GET['resync']) && $canEdit) {
    if (!empty($ranges)) {
        // Insertion défensive en cas de slots manquants
        try {
            $existing = $db->prepare('SELECT number FROM academy_matricules WHERE faction_key = ?');
            $existing->execute([$__fk]);
            $set = array_flip(array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN)));
            $ins = $db->prepare('INSERT IGNORE INTO academy_matricules (faction_key, number) VALUES (?, ?)');
            $added = 0;
            foreach ($ranges as $r) {
                for ($i = (int)$r['range_min']; $i <= (int)$r['range_max']; $i++) {
                    if (!isset($set[$i])) { $ins->execute([$__fk, $i]); $added++; }
                }
            }
        } catch (Exception $e) {}
    }
    header('Location: ' . SITE_URL . '/pages/academy-central/matricules_' . $__slug . '.php?synced=1');
    exit;
}
$justSynced = isset($_GET['synced']);

// ── 3. Récupération données ───────────────────────────────────────────────
try {
    $s = $db->prepare(
        "SELECT m.*, u.username,
                ag.username AS assigned_by_name
         FROM academy_matricules m
         LEFT JOIN users u  ON u.id  = m.user_id
         LEFT JOIN users ag ON ag.id = m.assigned_by
         WHERE m.faction_key = ?
         ORDER BY m.number"
    );
    $s->execute([$__fk]);
    $mats = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $mats = []; }

// Liste des utilisateurs (faction = celle-ci OU citoyens libres)
try {
    $stmt = $db->prepare(
        "SELECT id, username, faction
         FROM users
         WHERE faction = ? OR faction IS NULL OR faction = '' OR faction = 'NONE'
         ORDER BY username
         LIMIT 1000"
    );
    $stmt->execute([$__fk]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $users = []; }

// Grades académie de la faction (et inter-factions)
try {
    $stmt = $db->prepare(
        "SELECT id, grade_key, name, color, icon, faction_key
         FROM academy_grades
         WHERE is_active = 1 AND (faction_key = ? OR faction_key IS NULL)
         ORDER BY faction_key IS NULL, sort_order, name"
    );
    $stmt->execute([$__fk]);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $grades = []; }

$padding   = !empty($ranges) ? (int)$ranges[0]['padding'] : 3;
$total     = count($mats);
$busy      = count(array_filter($mats, fn($m) => !empty($m['user_id'])));
$free      = $total - $busy;
$occupRate = $total > 0 ? (int)round($busy * 100 / $total) : 0;

[$ar, $ag, $ab] = sscanf($__meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$__meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);--fac-c-35:rgba($ar,$ag,$ab,.35);";

$extraCss = '
.mat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(78px,1fr));gap:7px}
.mat-card{position:relative;padding:9px 4px;border-radius:9px;text-align:center;font-family:"DM Mono",monospace;font-weight:700;cursor:pointer;transition:transform .15s,box-shadow .15s,border-color .15s;border:1px solid;background:rgba(255,255,255,.02);min-height:62px;display:flex;flex-direction:column;justify-content:center;gap:2px}
.mat-card:hover{transform:translateY(-2px);box-shadow:0 8px 18px -10px rgba(0,0,0,.5)}
.mat-card.free{border-color:rgba(16,185,129,.30);color:#86efac;background:linear-gradient(160deg,rgba(16,185,129,.08),rgba(16,185,129,.02))}
.mat-card.free:hover{border-color:rgba(16,185,129,.55);background:linear-gradient(160deg,rgba(16,185,129,.18),rgba(16,185,129,.05))}
.mat-card.busy{border-color:rgba(239,68,68,.30);color:#fca5a5;background:linear-gradient(160deg,rgba(239,68,68,.08),rgba(239,68,68,.02))}
.mat-card.busy:hover{border-color:rgba(239,68,68,.55);background:linear-gradient(160deg,rgba(239,68,68,.18),rgba(239,68,68,.05))}
.mat-card .num{font-size:.95rem;letter-spacing:.04em;color:#fff}
.mat-card .nm{font-size:.58rem;font-weight:600;opacity:.85;text-transform:none;line-height:1.1;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:0 3px}
.mat-card .stbar{position:absolute;top:0;left:0;right:0;height:2px;border-radius:9px 9px 0 0}
.mat-card.free .stbar{background:#10b981}
.mat-card.busy .stbar{background:#ef4444}
.mat-card.hide{display:none}

.mat-legend{display:inline-flex;align-items:center;gap:14px;font-size:.75rem;color:var(--text-secondary);margin-left:auto}
.mat-legend span{display:inline-flex;align-items:center;gap:6px}
.mat-legend i{width:10px;height:10px;border-radius:50%;display:inline-block}

.mat-rangebar{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.mat-rangechip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:50px;font-size:.74rem;background:rgba(139,92,246,.12);border:1px solid rgba(139,92,246,.25);color:#c4b5fd;font-family:"DM Mono",monospace;font-weight:600}
.mat-rangechip .rk-label{color:var(--text-secondary);font-family:"DM Sans",sans-serif;font-weight:500}

.assign-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:5px;background:var(--gr-bg,rgba(139,92,246,.14));color:var(--gr-c,#c4b5fd);font-size:.7rem;font-weight:600;line-height:1.4}
.assign-badge i{font-size:.65rem}
';
include __DIR__ . '/header.php';
?>

<style><?php echo $facCss; ?></style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-id-card"></i></span> Matricules</h1>
    <p>Gérez les matricules attribués aux membres. Cliquez sur une carte pour attribuer ou modifier.</p>
  </div>
  <div class="page-header-right">
    <?php if ($canEdit && !empty($ranges)): ?>
    <a href="?resync=1" class="btn btn-secondary" title="Régénère les slots manquants à partir des plages configurées">
      <i class="fa-solid fa-rotate"></i> Resynchroniser
    </a>
    <?php endif; ?>
    <?php if (academy_is_staff_bypass()): ?>
    <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/matricules.php" class="btn btn-secondary" title="Configurer les plages">
      <i class="fa-solid fa-sliders"></i> Plages
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="fac-strip">
  <div class="fs-ic"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i></div>
  <div class="fs-info">
    <div class="fs-name"><?php echo htmlspecialchars($__meta['name']); ?></div>
    <div class="fs-sub"><?php echo htmlspecialchars($__meta['subtitle'] ?? ''); ?></div>
  </div>
  <?php if (!empty($ranges)): ?>
  <div class="mat-rangebar">
    <?php foreach ($ranges as $r): ?>
    <span class="mat-rangechip">
      <span class="rk-label">Plage</span>
      <?php echo academy_matricule_pad((int)$r['range_min'], (int)$r['padding']); ?>
      → <?php echo academy_matricule_pad((int)$r['range_max'], (int)$r['padding']); ?>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($justSynced): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Synchronisation effectuée. Les matricules manquants ont été créés.</div>
<?php endif; ?>

<!-- KPIs -->
<div class="stats-grid">
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-hashtag"></i></div><div class="stat-info">
    <div class="stat-label">Matricules totaux</div>
    <div class="stat-value"><?php echo $total; ?></div>
    <div class="stat-meta"><?php echo count($ranges); ?> plage(s) configurée(s)</div>
  </div></div>
  <div class="stat-card stat-green"><div class="stat-ic"><i class="fa-solid fa-circle-check"></i></div><div class="stat-info">
    <div class="stat-label">Disponibles</div>
    <div class="stat-value"><?php echo $free; ?></div>
    <div class="stat-meta">prêts à être attribués</div>
  </div></div>
  <div class="stat-card stat-red"><div class="stat-ic"><i class="fa-solid fa-user-shield"></i></div><div class="stat-info">
    <div class="stat-label">Occupés</div>
    <div class="stat-value"><?php echo $busy; ?></div>
    <div class="stat-meta">attribués à un membre</div>
  </div></div>
  <div class="stat-card stat-orange"><div class="stat-ic"><i class="fa-solid fa-chart-pie"></i></div><div class="stat-info">
    <div class="stat-label">Taux d'occupation</div>
    <div class="stat-value"><?php echo $occupRate; ?>%</div>
    <div style="height:5px;background:rgba(255,255,255,.05);border-radius:3px;margin-top:6px;overflow:hidden">
      <div style="width:<?php echo $occupRate; ?>%;height:100%;background:linear-gradient(90deg,#10b981,#f59e0b,#ef4444);transition:width .4s"></div>
    </div>
  </div></div>
</div>

<?php if (empty($ranges)): ?>
<div class="card">
  <div class="card-body">
    <div class="empty-state">
      <i class="fa-solid fa-id-card"></i>
      <div class="es-title">Aucune plage de matricules configurée</div>
      <p>Le staff doit définir au moins une plage (ex : 001 → 099) avant que des matricules apparaissent ici.</p>
      <?php if (academy_is_staff_bypass()): ?>
      <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/matricules.php" class="btn btn-purple">
        <i class="fa-solid fa-sliders"></i> Configurer les plages
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php elseif (empty($mats)): ?>
<div class="card">
  <div class="card-body">
    <div class="empty-state">
      <i class="fa-solid fa-circle-exclamation" style="color:#f59e0b"></i>
      <div class="es-title">Plages configurées mais aucun matricule généré</div>
      <p>
        <?php echo count($ranges); ?> plage(s) trouvée(s) mais la table des matricules est vide. C'est probablement une désynchronisation.
      </p>
      <?php if ($canEdit): ?>
      <a href="?resync=1" class="btn btn-purple"><i class="fa-solid fa-rotate"></i> Régénérer les matricules</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php else: ?>

<!-- Toolbar : recherche & filtres -->
<div class="toolbar">
  <div class="grow search-input">
    <i class="fa-solid fa-search"></i>
    <input type="text" class="form-control" id="matSearch" placeholder="Rechercher un numéro ou un nom…">
  </div>
  <div class="seg" id="matFilter">
    <button class="on" data-f="all">Tous <span style="opacity:.6;margin-left:3px"><?php echo $total; ?></span></button>
    <button data-f="free"><i class="fa-solid fa-circle" style="color:#10b981;font-size:.5rem"></i> Libres <span style="opacity:.6;margin-left:3px"><?php echo $free; ?></span></button>
    <button data-f="busy"><i class="fa-solid fa-circle" style="color:#ef4444;font-size:.5rem"></i> Occupés <span style="opacity:.6;margin-left:3px"><?php echo $busy; ?></span></button>
  </div>
  <div class="mat-legend">
    <span><i style="background:#10b981"></i> Libre</span>
    <span><i style="background:#ef4444"></i> Occupé</span>
  </div>
</div>

<!-- Grille -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-grip"></i> Grille des matricules</div>
    <div style="font-size:.75rem;color:var(--text-secondary)" id="matVisCount"><?php echo $total; ?> visible(s)</div>
  </div>
  <div class="card-body">
    <div class="mat-grid" id="matGrid">
    <?php foreach ($mats as $m):
      $occ = !empty($m['user_id']);
      $cls = $occ ? 'busy' : 'free';
      $payload = json_encode([
          'id' => (int)$m['id'], 'number' => (int)$m['number'],
          'user_id' => $m['user_id'] ? (int)$m['user_id'] : null,
          'name' => $m['name_label'] ?? '', 'rank' => $m['rank_label'] ?? '',
          'note' => $m['note'] ?? '', 'username' => $m['username'] ?? '',
          'canEdit' => $canEdit,
          'assignedAt' => $m['assigned_at'] ?? null,
          'assignedByName' => $m['assigned_by_name'] ?? null,
      ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
      $label = $occ ? mb_substr($m['name_label'] ?: ($m['username'] ?? '—'), 0, 12) : 'Libre';
    ?>
      <div class="mat-card <?php echo $cls; ?>"
           data-num="<?php echo (int)$m['number']; ?>"
           data-state="<?php echo $cls; ?>"
           data-search="<?php echo htmlspecialchars(strtolower(($m['name_label'] ?? '') . ' ' . ($m['username'] ?? '') . ' ' . str_pad((string)$m['number'], $padding, '0', STR_PAD_LEFT))); ?>"
           onclick='openMat(<?php echo $payload; ?>)'>
        <div class="stbar"></div>
        <div class="num"><?php echo academy_matricule_pad((int)$m['number'], $padding); ?></div>
        <div class="nm"><?php echo htmlspecialchars($label); ?></div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Récapitulatif -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-table-list"></i> Matricules attribués</div>
    <span class="badge badge-purple"><?php echo $busy; ?></span>
  </div>
  <?php $busyList = array_filter($mats, fn($m) => !empty($m['user_id']));
  if (empty($busyList)): ?>
  <div class="empty-state" style="padding:32px 20px">
    <i class="fa-solid fa-id-card"></i>
    <div class="es-title">Aucun matricule attribué</div>
    <p>Cliquez sur une carte verte de la grille ci-dessus pour attribuer un matricule.</p>
  </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table>
      <thead><tr><th>N°</th><th>Titulaire</th><th>Grade</th><th>Note</th><th>Attribué par</th><th>Date</th><?php if ($canEdit) echo '<th></th>'; ?></tr></thead>
      <tbody>
      <?php foreach ($busyList as $m):
        $payload = json_encode([
          'id' => (int)$m['id'], 'number' => (int)$m['number'],
          'user_id' => (int)$m['user_id'], 'name' => $m['name_label'] ?? '',
          'rank' => $m['rank_label'] ?? '', 'note' => $m['note'] ?? '',
          'username' => $m['username'] ?? '', 'canEdit' => $canEdit,
          'assignedAt' => $m['assigned_at'] ?? null, 'assignedByName' => $m['assigned_by_name'] ?? null,
        ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
        $initials = strtoupper(mb_substr(($m['name_label'] ?: ($m['username'] ?? 'U')), 0, 1));
      ?>
      <tr>
        <td><span class="badge" style="background:var(--fac-c-15);color:var(--fac-c);font-family:'DM Mono',monospace"><?php echo academy_matricule_pad((int)$m['number'], $padding); ?></span></td>
        <td>
          <div class="usr">
            <div class="usr-ava"><?php echo $initials; ?></div>
            <div>
              <div class="row-strong"><?php echo htmlspecialchars($m['name_label'] ?: ($m['username'] ?? '—')); ?></div>
              <?php if ($m['name_label'] && $m['username']): ?><div class="row-muted">@<?php echo htmlspecialchars($m['username']); ?></div><?php endif; ?>
            </div>
          </div>
        </td>
        <td><?php echo $m['rank_label'] ? '<span class="badge badge-purple">' . htmlspecialchars($m['rank_label']) . '</span>' : '<span class="row-muted">—</span>'; ?></td>
        <td><span class="row-muted"><?php echo htmlspecialchars(mb_strimwidth($m['note'] ?: '—', 0, 80, '…')); ?></span></td>
        <td><span class="row-muted"><?php echo htmlspecialchars($m['assigned_by_name'] ?: '—'); ?></span></td>
        <td><span class="row-muted"><?php echo $m['assigned_at'] ? date('d/m/Y H:i', strtotime($m['assigned_at'])) : '—'; ?></span></td>
        <?php if ($canEdit): ?>
        <td><button class="btn btn-secondary btn-sm" onclick='openMat(<?php echo $payload; ?>)'><i class="fa-solid fa-pen"></i></button></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- ─────────────── MODAL ATTRIBUTION ─────────────── -->
<div class="modal-overlay" id="matModal">
  <div class="modal" style="max-width:580px">
    <div class="modal-header">
      <h3 style="display:flex;align-items:center;gap:10px"><span style="width:32px;height:32px;border-radius:9px;background:var(--fac-c-15);color:var(--fac-c);display:grid;place-items:center"><i class="fa-solid fa-id-card"></i></span> Matricule <span id="matTitle" class="mono" style="color:var(--fac-c)"></span></h3>
      <button class="modal-close" onclick="document.getElementById('matModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div id="matInfo" style="margin-bottom:14px;padding:10px 12px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:9px;font-size:.78rem;color:var(--text-secondary);display:none">
      <i class="fa-solid fa-clock-rotate-left"></i> Attribué <span id="matInfoDate"></span> par <span id="matInfoBy" class="row-strong"></span>
    </div>

    <input type="hidden" id="matId">

    <div class="form-group">
      <label class="form-label"><i class="fa-solid fa-user"></i> Titulaire (citoyen)</label>
      <select id="matUserId" class="form-control">
        <option value="">— Libre (aucun titulaire) —</option>
        <?php foreach ($users as $u): ?>
        <option value="<?php echo (int)$u['id']; ?>" data-username="<?php echo htmlspecialchars($u['username']); ?>"><?php echo htmlspecialchars($u['username']); ?> (#<?php echo (int)$u['id']; ?>)<?php echo $u['faction'] && $u['faction'] !== $__fk ? ' — ' . htmlspecialchars($u['faction']) : ''; ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="grid-2" style="gap:14px">
      <div class="form-group">
        <label class="form-label"><i class="fa-solid fa-id-badge"></i> Nom RP affiché</label>
        <input type="text" id="matName" class="form-control" maxlength="128" placeholder="Ex: John Doe">
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fa-solid fa-medal"></i> Grade</label>
        <?php if (!empty($grades)): ?>
        <select id="matRankSel" class="form-control">
          <option value="">— Aucun grade —</option>
          <?php foreach ($grades as $g): ?>
          <option value="<?php echo htmlspecialchars($g['name']); ?>"<?php echo $g['faction_key'] === null ? ' style="color:#94a3b8"' : ''; ?>>
            <?php echo htmlspecialchars($g['name']); ?><?php echo $g['faction_key'] === null ? ' (transverse)' : ''; ?>
          </option>
          <?php endforeach; ?>
          <option value="__custom__">— Saisir manuellement —</option>
        </select>
        <input type="text" id="matRank" class="form-control" maxlength="128" placeholder="Grade libre" style="display:none;margin-top:6px">
        <?php else: ?>
        <input type="text" id="matRank" class="form-control" maxlength="128" placeholder="Grade libre">
        <?php endif; ?>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label"><i class="fa-solid fa-note-sticky"></i> Note interne (optionnel)</label>
      <textarea id="matNote" class="form-control" rows="2" placeholder="Remarque, contexte d'attribution…"></textarea>
    </div>

    <div class="modal-footer">
      <?php if ($canEdit): ?>
      <button class="btn btn-danger" id="matRelease" onclick="releaseMat()" style="display:none"><i class="fa-solid fa-circle-xmark"></i> Libérer</button>
      <button class="btn btn-secondary" onclick="document.getElementById('matModal').classList.remove('open')">Annuler</button>
      <button class="btn btn-purple" onclick="assignMat()"><i class="fa-solid fa-check"></i> Enregistrer</button>
      <?php else: ?>
      <button class="btn btn-secondary" onclick="document.getElementById('matModal').classList.remove('open')">Fermer</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const __CSRF    = '<?php echo htmlspecialchars($csrf); ?>';
const __PADDING = <?php echo $padding; ?>;
const __SLUG    = '<?php echo $__slug; ?>';

function pad(n){ return String(n).padStart(__PADDING, '0'); }

function openMat(m){
  document.getElementById('matTitle').textContent = pad(m.number);
  document.getElementById('matId').value = m.id;
  document.getElementById('matUserId').value = m.user_id || '';
  document.getElementById('matName').value = m.name || m.username || '';
  document.getElementById('matNote').value = m.note || '';
  const sel = document.getElementById('matRankSel');
  const inp = document.getElementById('matRank');
  if (sel) {
    const found = [...sel.options].some(o => o.value === m.rank);
    if (m.rank && !found) {
      sel.value = '__custom__';
      inp.style.display = 'block';
      inp.value = m.rank;
    } else {
      sel.value = m.rank || '';
      inp.style.display = 'none';
      inp.value = '';
    }
  } else {
    inp.value = m.rank || '';
  }
  // Info bandeau
  const info = document.getElementById('matInfo');
  if (m.user_id && m.assignedAt) {
    info.style.display = 'block';
    document.getElementById('matInfoDate').textContent = 'le ' + new Date(m.assignedAt.replace(' ', 'T')).toLocaleString('fr-FR', { dateStyle:'short', timeStyle:'short' });
    document.getElementById('matInfoBy').textContent = m.assignedByName || '—';
  } else {
    info.style.display = 'none';
  }
  // Bouton libérer
  const rel = document.getElementById('matRelease');
  if (rel) rel.style.display = m.user_id ? '' : 'none';

  document.getElementById('matModal').classList.add('open');
}

// Switch grade dropdown ↔ saisie libre
const __selRank = document.getElementById('matRankSel');
if (__selRank) __selRank.addEventListener('change', e => {
  const inp = document.getElementById('matRank');
  if (e.target.value === '__custom__') { inp.style.display = 'block'; inp.focus(); }
  else { inp.style.display = 'none'; inp.value = ''; }
});

// Auto-remplir le nom à partir du username sélectionné si vide
document.getElementById('matUserId').addEventListener('change', e => {
  const opt = e.target.selectedOptions[0];
  const nameField = document.getElementById('matName');
  if (opt && opt.dataset.username && !nameField.value.trim()) {
    nameField.value = opt.dataset.username;
  }
});

async function assignMat(){
  const sel = document.getElementById('matRankSel');
  const rank = (sel && sel.value && sel.value !== '__custom__') ? sel.value : document.getElementById('matRank').value;
  const fd = new FormData();
  fd.append('csrf', __CSRF);
  fd.append('id', document.getElementById('matId').value);
  fd.append('user_id', document.getElementById('matUserId').value);
  fd.append('name', document.getElementById('matName').value);
  fd.append('rank', rank);
  fd.append('note', document.getElementById('matNote').value);
  try {
    const r = await fetch('<?php echo SITE_URL; ?>/pages/academy-central/ajax/matricule_assign.php', { method:'POST', body:fd });
    const j = await r.json();
    if (j && j.success) { acToast('Matricule enregistré'); setTimeout(() => location.reload(), 500); }
    else acToast((j && j.error) || 'Erreur', true);
  } catch(e) { acToast('Erreur réseau', true); }
}

async function releaseMat(){
  if (!confirm('Libérer ce matricule ? Le titulaire actuel sera retiré.')) return;
  const fd = new FormData();
  fd.append('csrf', __CSRF);
  fd.append('id', document.getElementById('matId').value);
  try {
    const r = await fetch('<?php echo SITE_URL; ?>/pages/academy-central/ajax/matricule_release.php', { method:'POST', body:fd });
    const j = await r.json();
    if (j && j.success) { acToast('Matricule libéré'); setTimeout(() => location.reload(), 500); }
    else acToast((j && j.error) || 'Erreur', true);
  } catch(e) { acToast('Erreur réseau', true); }
}

// Filtres et recherche
const matSearch = document.getElementById('matSearch');
const matFilter = document.getElementById('matFilter');
const matGrid   = document.getElementById('matGrid');
const matVis    = document.getElementById('matVisCount');
let curFilter = 'all';

function applyFilters(){
  if (!matGrid) return;
  const q = (matSearch?.value || '').trim().toLowerCase();
  let visible = 0;
  matGrid.querySelectorAll('.mat-card').forEach(c => {
    const okState = curFilter === 'all' || c.dataset.state === curFilter;
    const okQuery = !q || c.dataset.search.includes(q);
    const show = okState && okQuery;
    c.classList.toggle('hide', !show);
    if (show) visible++;
  });
  if (matVis) matVis.textContent = visible + ' visible' + (visible > 1 ? 's' : '');
}

if (matSearch) matSearch.addEventListener('input', applyFilters);
if (matFilter) matFilter.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
  matFilter.querySelectorAll('button').forEach(x => x.classList.remove('on'));
  b.classList.add('on');
  curFilter = b.dataset.f;
  applyFilters();
}));
</script>

<?php include __DIR__ . '/footer.php'; ?>
