<?php
/**
 * Rapports propres à la division (CRUD).
 * Accessible depuis les panels ayant la capabilité `create_own_reports`.
 *
 * Attend : $division, $canManage, $currentUserId, $panelKey.
 */
if (!isset($division)) exit;
if (!div_has_capability($division, 'create_own_reports')) {
    echo '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> La création de rapports n\'est pas activée pour cette division.</div>';
    return;
}

$db    = div_db();
$divId = (int)$division['id'];
$base  = SITE_URL . '/pages/divisions/panel.php?key=' . urlencode($panelKey) . '&view=reports';

$RTYPES  = div_report_types();
$RSTATUS = div_report_statuses();
$RPRIO   = div_report_priorities();
$TAGS    = div_report_tags($divId);

// Modes : list | new | view(id) | edit(id)
$viewId = (int)($_GET['id']   ?? 0);
$editId = (int)($_GET['edit'] ?? 0);
$isNew  = isset($_GET['new']);

// Filtres
$fType   = $_GET['type']   ?? 'all';
$fStatus = $_GET['status'] ?? 'all';
$fDate   = $_GET['date']   ?? 'all';
$search  = trim((string)($_GET['q'] ?? ''));

// ── Charger rapport détail / édition ───────────────────────────────────────
$current = null;
if ($viewId > 0 || $editId > 0) {
    $rid = $viewId ?: $editId;
    $stmt = $db->prepare("
        SELECT dr.*, u.username AS auteur
          FROM division_reports dr
          LEFT JOIN users u ON u.id = dr.created_by
         WHERE dr.id = ? AND dr.division_id = ?
         LIMIT 1
    ");
    $stmt->execute([$rid, $divId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$isEdit = ($editId > 0 && $current);
$isView = ($viewId > 0 && $current && !$isEdit);
if ($editId > 0 && !$current) { $isNew = false; /* redirigé vers liste */ }

// Droit de modifier/supprimer = auteur ou manager
$canEditThis = function($row) use ($currentUserId, $canManage): bool {
    if (!$row) return false;
    return $canManage || (int)$row['created_by'] === $currentUserId;
};

// ── Listes pour le formulaire ──────────────────────────────────────────────
$factionKey = strtoupper((string)$division['f_key']);
$officers = [];
try {
    $stmt = $db->prepare("SELECT id, username, faction_rank FROM users WHERE faction=? AND is_active=1 ORDER BY username");
    $stmt->execute([$factionKey]);
    $officers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$profiles = [];
try {
    $profiles = $db->query("SELECT id, firstname, lastname FROM profiles ORDER BY lastname, firstname LIMIT 300")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ── Liste ──────────────────────────────────────────────────────────────────
$reports = [];
if (!$isNew && !$isEdit && !$isView) {
    $q = "SELECT dr.*, u.username AS auteur
            FROM division_reports dr
            LEFT JOIN users u ON u.id = dr.created_by
           WHERE dr.division_id = ?";
    $params = [$divId];
    if ($fType   !== 'all') { $q .= " AND dr.type = ?";   $params[] = $fType; }
    if ($fStatus !== 'all') { $q .= " AND dr.status = ?"; $params[] = $fStatus; }
    if ($fDate === 'today')  $q .= " AND DATE(dr.created_at) = CURDATE()";
    if ($fDate === 'week')   $q .= " AND dr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    if ($fDate === 'month')  $q .= " AND dr.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    if ($search !== '') {
        $q .= " AND (dr.title LIKE ? OR dr.description LIKE ?)";
        $s = '%' . $search . '%'; $params[] = $s; $params[] = $s;
    }
    $q .= " ORDER BY dr.created_at DESC LIMIT 150";
    try {
        $st = $db->prepare($q); $st->execute($params);
        $reports = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

// ── Résolveurs ─────────────────────────────────────────────────────────────
$resolve_profiles = function(?string $json) use ($profiles): array {
    $arr = $json ? json_decode($json, true) : [];
    if (!is_array($arr) || !$arr) return [];
    $ids = array_flip(array_map('intval', $arr));
    $out = [];
    foreach ($profiles as $p) {
        if (isset($ids[(int)$p['id']])) {
            $out[] = trim(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? ''));
        }
    }
    return $out;
};
$resolve_officers = function(?string $json) use ($officers): array {
    $arr = $json ? json_decode($json, true) : [];
    if (!is_array($arr) || !$arr) return [];
    $ids = array_flip(array_map('intval', $arr));
    $out = [];
    foreach ($officers as $o) {
        if (isset($ids[(int)$o['id']])) $out[] = $o['username'];
    }
    return $out;
};
?>

<style>
.dr-layout{display:grid;grid-template-columns:230px 1fr;gap:1.1rem}
@media(max-width:1024px){.dr-layout{grid-template-columns:1fr}}
.dr-filter{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:.9rem;margin-bottom:.7rem}
.dr-ftt{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-m);margin-bottom:.6rem}
.dr-fb{display:flex;align-items:center;gap:.5rem;padding:.42rem .7rem;border-radius:7px;font-size:.76rem;color:var(--txt-s);text-decoration:none;transition:all .15s}
.dr-fb:hover,.dr-fb.active{background:var(--accent-soft);color:var(--accent)}
.dr-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:.9rem 1rem;margin-bottom:.55rem;transition:all .18s;position:relative;overflow:hidden;cursor:pointer}
.dr-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);opacity:0;transition:opacity .2s}
.dr-card:hover{border-color:var(--bdr-h);transform:translateX(2px)}
.dr-card:hover::before{opacity:1}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .55rem;border-radius:5px;font-size:.62rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.bg-red{background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.25)}
.bg-orange{background:rgba(249,115,22,.12);color:#fdba74;border:1px solid rgba(249,115,22,.25)}
.bg-yellow{background:rgba(245,158,11,.12);color:#fde68a;border:1px solid rgba(245,158,11,.25)}
.bg-green{background:rgba(16,185,129,.12);color:#6ee7b7;border:1px solid rgba(16,185,129,.25)}
.bg-blue{background:rgba(59,130,246,.12);color:#93c5fd;border:1px solid rgba(59,130,246,.25)}
.bg-purple{background:rgba(168,85,247,.12);color:#c4b5fd;border:1px solid rgba(168,85,247,.25)}
.bg-gray{background:rgba(100,116,139,.12);color:#cbd5e1;border:1px solid rgba(100,116,139,.25)}

.dr-editor{border:1px solid var(--bdr);border-radius:9px;overflow:hidden;background:rgba(0,0,0,.1)}
.dr-editor-tb{display:flex;flex-wrap:wrap;gap:2px;padding:.45rem .55rem;background:rgba(0,0,0,.2);border-bottom:1px solid var(--bdr)}
.dr-ebtn{width:28px;height:28px;border-radius:5px;background:transparent;border:1px solid transparent;color:var(--txt-s);cursor:pointer;font-size:.78rem;transition:all .12s}
.dr-ebtn:hover{background:var(--accent-soft);border-color:var(--accent-fade);color:var(--accent)}
.dr-esel{background:rgba(0,0,0,.3);border:1px solid var(--bdr);border-radius:5px;color:var(--txt-s);font-size:.72rem;padding:3px 6px}
.dr-esel option{background:#0a1020}
.dr-re{min-height:180px;padding:.95rem 1rem;color:var(--txt);font-family:inherit;font-size:.85rem;line-height:1.7;outline:none}
.dr-re:empty::before{content:attr(data-ph);color:var(--txt-m);font-style:italic}

.dr-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .85rem;border-radius:7px;font-size:.72rem;font-weight:600;cursor:pointer;border:1px solid var(--bdr);background:var(--bg-ov);color:var(--txt-s);transition:all .15s;margin:.18rem 0}
.dr-chip:hover{border-color:var(--accent-fade);color:var(--accent)}
.dr-chip input{display:none}
.dr-chip.sel{background:var(--accent-soft);border-color:var(--accent);color:var(--accent)}
.dr-chip.sel::before{content:'\f00c';font-family:'Font Awesome 5 Free';font-weight:900;font-size:.6rem}

.dr-tagchip{padding:.35rem .8rem;border-radius:7px;font-size:.68rem;font-weight:700;cursor:pointer;border:1px solid;transition:all .15s;display:inline-flex;align-items:center;gap:.3rem;margin:.18rem}
.dr-tagchip input{display:none}

.dr-msb{max-height:180px;overflow-y:auto;background:rgba(0,0,0,.15);border:1px solid var(--bdr);border-radius:8px}
.dr-msi{display:flex;align-items:center;gap:.6rem;padding:.4rem .7rem;cursor:pointer;font-size:.78rem}
.dr-msi:hover{background:var(--accent-soft)}
.dr-msi input{accent-color:var(--accent);width:13px;height:13px;cursor:pointer}
.dr-msi label{cursor:pointer;flex:1;color:var(--txt-s)}

.ro-banner-note{display:flex;align-items:center;gap:.55rem;padding:.55rem .85rem;border-radius:8px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);color:#fde68a;font-size:.76rem;margin-bottom:.9rem}
</style>

<?php if ($isNew || $isEdit): ?>

<?php
// Valeurs par défaut
$v_type     = $current['type']     ?? 'incident';
$v_title    = $current['title']    ?? '';
$v_status   = $current['status']   ?? 'submitted';
$v_priority = $current['priority'] ?? 'normal';
$v_location = $current['location'] ?? '';
$v_incident = $current['incident_at'] ?? '';
$v_descR    = $current['description_rich'] ?? '';
$v_notes    = $current['internal_notes']  ?? '';
$v_evidence = '';
if (!empty($current['evidence'])) {
    $ev = json_decode($current['evidence'], true) ?: [];
    $v_evidence = implode("\n", $ev);
}
$v_tags      = $current ? (json_decode($current['tags']      ?? '[]', true) ?: []) : [];
$v_officers  = $current ? (json_decode($current['officers']  ?? '[]', true) ?: []) : [];
$v_civilians = $current ? (json_decode($current['civilians'] ?? '[]', true) ?: []) : [];
$v_suspects  = $current ? (json_decode($current['suspects']  ?? '[]', true) ?: []) : [];
$v_witnesses = $current ? (json_decode($current['witnesses'] ?? '[]', true) ?: []) : [];
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.7rem">
    <a href="<?php echo htmlspecialchars($base); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
    <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#fff"><i class="fas fa-<?php echo $isEdit ? 'pen-to-square' : 'plus'; ?>" style="color:var(--accent);margin-right:.4rem"></i><?php echo $isEdit ? 'Modifier le rapport' : 'Nouveau rapport'; ?></div>
    <span></span>
</div>

<form method="POST" id="drForm">
    <input type="hidden" name="action" value="<?php echo $isEdit ? 'report_update' : 'report_submit'; ?>">
    <input type="hidden" name="view_back" value="reports">
    <?php if ($isEdit): ?><input type="hidden" name="report_id" value="<?php echo (int)$current['id']; ?>"><?php endif; ?>
    <input type="hidden" name="description_rich" id="drDescHidden">

    <!-- 1. Type + Statut + Priorité -->
    <div class="d-card">
        <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-tag"></i> Type & Classification</div></div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Type de rapport *</label>
            <div style="display:flex;flex-wrap:wrap;gap:.35rem">
                <?php foreach ($RTYPES as $k => [$l, $b]): ?>
                <label class="dr-chip <?php echo $v_type===$k?'sel':''; ?>">
                    <input type="radio" name="type" value="<?php echo $k; ?>" <?php echo $v_type===$k?'checked':''; ?> onchange="drSelChip(this)">
                    <?php echo $l; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Statut</label>
                <select name="status" class="form-select">
                    <?php foreach ($RSTATUS as $k => [$l, $b]): ?>
                    <option value="<?php echo $k; ?>" <?php echo $v_status===$k?'selected':''; ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Priorité</label>
                <select name="priority" class="form-select">
                    <?php foreach ($RPRIO as $k => [$l, $b]): ?>
                    <option value="<?php echo $k; ?>" <?php echo $v_priority===$k?'selected':''; ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- 2. Infos -->
    <div class="d-card">
        <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-circle-info"></i> Informations</div></div>
        <div class="form-group">
            <label class="form-label">Titre *</label>
            <input type="text" name="title" class="form-input" required value="<?php echo htmlspecialchars($v_title); ?>" placeholder="Titre concis du rapport">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Lieu</label>
                <input type="text" name="location" class="form-input" value="<?php echo htmlspecialchars($v_location); ?>" placeholder="Adresse, zone...">
            </div>
            <div class="form-group">
                <label class="form-label">Date de l'incident</label>
                <input type="datetime-local" name="incident_at" class="form-input" value="<?php echo htmlspecialchars($v_incident ? date('Y-m-d\TH:i', strtotime($v_incident)) : ''); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Tags</label>
            <div style="display:flex;flex-wrap:wrap">
                <?php foreach ($TAGS as $t):
                    $tc  = $t['color'] ?: '#64748b';
                    $sel = in_array($t['label'], $v_tags, true);
                ?>
                <label class="dr-tagchip" data-label="<?php echo htmlspecialchars($t['label']); ?>"
                    style="color:<?php echo $tc; ?>;border-color:<?php echo $tc; ?>33;background:<?php echo $sel?($tc.'22'):($tc.'0a'); ?>;<?php echo $sel?'font-weight:700':''; ?>">
                    <input type="checkbox" <?php echo $sel?'checked':''; ?> onchange="drToggleTag(this)">
                    <?php echo htmlspecialchars($t['label']); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 3. Personnes -->
    <div class="d-card">
        <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-users"></i> Personnes impliquées</div></div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Officiers (<?php echo htmlspecialchars($factionKey); ?>)</label>
                <div class="dr-msb">
                <?php foreach ($officers as $o):
                    $sel = in_array((int)$o['id'], array_map('intval', $v_officers), true); ?>
                <div class="dr-msi">
                    <input type="checkbox" name="officers[]" value="<?php echo (int)$o['id']; ?>" id="ofc_<?php echo (int)$o['id']; ?>" <?php echo $sel?'checked':''; ?>>
                    <label for="ofc_<?php echo (int)$o['id']; ?>"><?php echo htmlspecialchars($o['username']); ?><?php if ($o['faction_rank']): ?> <span style="color:var(--txt-m);font-size:.7rem">· <?php echo htmlspecialchars($o['faction_rank']); ?></span><?php endif; ?></label>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Civils</label>
                <div class="dr-msb">
                <?php foreach ($profiles as $p):
                    $sel = in_array((int)$p['id'], array_map('intval', $v_civilians), true); ?>
                <div class="dr-msi">
                    <input type="checkbox" name="civilians[]" value="<?php echo (int)$p['id']; ?>" id="civ_<?php echo (int)$p['id']; ?>" <?php echo $sel?'checked':''; ?>>
                    <label for="civ_<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? '')); ?></label>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Suspects</label>
                <div class="dr-msb">
                <?php foreach ($profiles as $p):
                    $sel = in_array((int)$p['id'], array_map('intval', $v_suspects), true); ?>
                <div class="dr-msi">
                    <input type="checkbox" name="suspects[]" value="<?php echo (int)$p['id']; ?>" id="sus_<?php echo (int)$p['id']; ?>" <?php echo $sel?'checked':''; ?>>
                    <label for="sus_<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? '')); ?></label>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Témoins</label>
                <div class="dr-msb">
                <?php foreach ($profiles as $p):
                    $sel = in_array((int)$p['id'], array_map('intval', $v_witnesses), true); ?>
                <div class="dr-msi">
                    <input type="checkbox" name="witnesses[]" value="<?php echo (int)$p['id']; ?>" id="wit_<?php echo (int)$p['id']; ?>" <?php echo $sel?'checked':''; ?>>
                    <label for="wit_<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? '')); ?></label>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Description -->
    <div class="d-card">
        <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-pen-fancy"></i> Description *</div></div>
        <div class="dr-editor">
            <div class="dr-editor-tb">
                <select class="dr-esel" onchange="drFmtBlock(this.value);this.selectedIndex=0">
                    <option value="">Paragraphe</option>
                    <option value="h2">Titre</option>
                    <option value="h3">Sous-titre</option>
                </select>
                <button type="button" class="dr-ebtn" onclick="drEc('bold')" title="Gras"><i class="fas fa-bold"></i></button>
                <button type="button" class="dr-ebtn" onclick="drEc('italic')"><i class="fas fa-italic"></i></button>
                <button type="button" class="dr-ebtn" onclick="drEc('underline')"><i class="fas fa-underline"></i></button>
                <span style="width:1px;background:var(--bdr);margin:4px 2px"></span>
                <button type="button" class="dr-ebtn" onclick="drEc('insertUnorderedList')"><i class="fas fa-list-ul"></i></button>
                <button type="button" class="dr-ebtn" onclick="drEc('insertOrderedList')"><i class="fas fa-list-ol"></i></button>
                <span style="width:1px;background:var(--bdr);margin:4px 2px"></span>
                <button type="button" class="dr-ebtn" onclick="drEc('removeFormat')"><i class="fas fa-eraser"></i></button>
            </div>
            <div id="drEditor" class="dr-re" contenteditable="true" data-ph="Décrivez les faits, observations et actions entreprises..."><?php echo $v_descR; ?></div>
        </div>
    </div>

    <!-- 5. Preuves -->
    <div class="d-card">
        <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-camera"></i> Preuves & pièces jointes</div></div>
        <textarea name="evidence_urls" class="form-textarea" rows="3" placeholder="Une URL par ligne&#10;https://exemple.com/photo.jpg"><?php echo htmlspecialchars($v_evidence); ?></textarea>
    </div>

    <?php if ($canManage): ?>
    <div class="d-card">
        <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-lock"></i> Notes internes <span style="font-size:.65rem;font-weight:400;color:var(--txt-m)">(gestion)</span></div></div>
        <textarea name="internal_notes" class="form-textarea" rows="3" placeholder="Notes confidentielles, remarques de supervision..."><?php echo htmlspecialchars($v_notes); ?></textarea>
    </div>
    <?php endif; ?>

    <div style="display:flex;justify-content:flex-end;gap:.55rem;padding:.85rem 0;flex-wrap:wrap">
        <a href="<?php echo htmlspecialchars($base); ?>" class="btn btn-ghost"><i class="fas fa-times"></i> Annuler</a>
        <button type="submit" class="btn btn-primary" onclick="drPrepSubmit()">
            <i class="fas fa-<?php echo $isEdit ? 'floppy-disk' : 'paper-plane'; ?>"></i>
            <?php echo $isEdit ? 'Enregistrer' : 'Soumettre'; ?>
        </button>
    </div>
</form>

<script>
function drSelChip(inp){
    const form = inp.closest('.d-card');
    form.querySelectorAll('.dr-chip').forEach(c => c.classList.remove('sel'));
    inp.closest('.dr-chip').classList.add('sel');
}
function drToggleTag(cb){
    const chip = cb.closest('.dr-tagchip');
    chip.classList.toggle('sel', cb.checked);
    chip.style.fontWeight = cb.checked ? '700' : '';
    const tc = chip.style.color;
    chip.style.background = cb.checked ? tc+'22' : tc+'0a';
}
function drEc(cmd,val=null){document.execCommand(cmd,false,val);document.getElementById('drEditor').focus();}
function drFmtBlock(tag){if(tag)document.execCommand('formatBlock',false,'<'+tag+'>');document.getElementById('drEditor').focus();}
function drPrepSubmit(){
    document.getElementById('drDescHidden').value = document.getElementById('drEditor').innerHTML;
    // Nettoyer d'éventuels anciens inputs tags[]
    const form = document.getElementById('drForm');
    form.querySelectorAll('input[name="tags[]"][data-dyn="1"]').forEach(el => el.remove());
    document.querySelectorAll('.dr-tagchip input:checked').forEach(cb => {
        const i = document.createElement('input');
        i.type='hidden'; i.name='tags[]';
        i.value = cb.closest('.dr-tagchip').dataset.label;
        i.setAttribute('data-dyn','1');
        form.appendChild(i);
    });
}
</script>

<?php elseif ($isView):
    $rtags = json_decode($current['tags']     ?? '[]', true) ?: [];
    $revid = json_decode($current['evidence'] ?? '[]', true) ?: [];
    [$rtl, $rtb] = $RTYPES[$current['type'] ?? ''] ?? [$current['type'] ?? '—', 'bg-gray'];
    $sc = $RSTATUS[$current['status'] ?? 'draft'] ?? ['—','bg-gray'];
    $pp = $RPRIO[$current['priority'] ?? 'normal'] ?? null;
    $sOfc = $resolve_officers($current['officers']  ?? null);
    $sCiv = $resolve_profiles($current['civilians'] ?? null);
    $sWit = $resolve_profiles($current['witnesses'] ?? null);
    $sSus = $resolve_profiles($current['suspects']  ?? null);
    $editable = $canEditThis($current);
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.7rem">
    <a href="<?php echo htmlspecialchars($base); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
    <div style="display:flex;gap:.45rem">
        <?php if ($editable): ?>
        <a href="<?php echo htmlspecialchars($base . '&edit=' . (int)$current['id']); ?>" class="btn btn-sm"><i class="fas fa-pen-to-square"></i> Modifier</a>
        <form method="POST" onsubmit="return confirm('Supprimer ce rapport ?');" style="margin:0">
            <input type="hidden" name="action" value="report_delete">
            <input type="hidden" name="view_back" value="reports">
            <input type="hidden" name="report_id" value="<?php echo (int)$current['id']; ?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-ghost btn-sm"><i class="fas fa-print"></i></button>
    </div>
</div>

<div class="d-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.7rem;flex-wrap:wrap;margin-bottom:.7rem">
        <div>
            <span class="badge <?php echo $rtb; ?>" style="margin-bottom:.4rem"><?php echo htmlspecialchars($rtl); ?></span>
            <div style="font-family:'Syne',sans-serif;font-size:1.18rem;font-weight:800;color:#fff"><?php echo htmlspecialchars($current['title']); ?></div>
            <div style="font-size:.7rem;color:var(--txt-s);margin-top:.25rem">#<?php echo str_pad((string)$current['id'],6,'0',STR_PAD_LEFT); ?></div>
        </div>
        <div style="text-align:right">
            <div style="font-size:.72rem;color:var(--txt-m)">Par <strong style="color:var(--txt)"><?php echo htmlspecialchars($current['auteur'] ?? '—'); ?></strong></div>
            <div style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y H:i', strtotime($current['created_at'])); ?></div>
            <span class="badge <?php echo $sc[1]; ?>" style="margin-top:.35rem"><?php echo $sc[0]; ?></span>
            <?php if ($pp): ?><span class="badge <?php echo $pp[1]; ?>" style="margin-top:.35rem;margin-left:.25rem"><?php echo $pp[0]; ?></span><?php endif; ?>
        </div>
    </div>

    <?php if (!empty($rtags)): ?>
    <div style="margin-bottom:.6rem">
        <?php foreach ($rtags as $t):
            $tc = '#94a3b8';
            foreach ($TAGS as $pt) { if ($pt['label'] === $t) { $tc = $pt['color']; break; } } ?>
        <span style="padding:.2rem .55rem;border-radius:5px;font-size:.64rem;font-weight:700;border:1px solid <?php echo $tc; ?>33;background:<?php echo $tc; ?>1a;color:<?php echo $tc; ?>;margin-right:.18rem;display:inline-flex"><?php echo htmlspecialchars($t); ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($current['location'])): ?>
    <div style="font-size:.78rem;color:var(--txt-s);margin-bottom:.35rem"><i class="fas fa-location-dot" style="color:var(--accent);margin-right:.4rem"></i><?php echo htmlspecialchars($current['location']); ?></div>
    <?php endif; ?>
    <?php if (!empty($current['incident_at'])): ?>
    <div style="font-size:.78rem;color:var(--txt-s)"><i class="fas fa-clock" style="color:var(--accent);margin-right:.4rem"></i>Incident : <?php echo date('d/m/Y H:i', strtotime($current['incident_at'])); ?></div>
    <?php endif; ?>
</div>

<?php if (!empty($current['description_rich']) || !empty($current['description'])): ?>
<div class="d-card">
    <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-file-lines"></i> Description</div></div>
    <div style="font-size:.85rem;line-height:1.7"><?php echo $current['description_rich'] ?: nl2br(htmlspecialchars((string)$current['description'])); ?></div>
</div>
<?php endif; ?>

<?php if ($sOfc || $sCiv || $sWit || $sSus): ?>
<div class="d-card">
    <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-users"></i> Personnes impliquées</div></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;font-size:.8rem">
        <?php if ($sOfc): ?><div><div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.25rem">Officiers</div><?php foreach ($sOfc as $n): ?><div>• <?php echo htmlspecialchars($n); ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($sCiv): ?><div><div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.25rem">Civils</div><?php foreach ($sCiv as $n): ?><div>• <?php echo htmlspecialchars($n); ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($sSus): ?><div><div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.25rem">Suspects</div><?php foreach ($sSus as $n): ?><div>• <?php echo htmlspecialchars($n); ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($sWit): ?><div><div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.25rem">Témoins</div><?php foreach ($sWit as $n): ?><div>• <?php echo htmlspecialchars($n); ?></div><?php endforeach; ?></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($revid)): ?>
<div class="d-card">
    <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-camera"></i> Preuves (<?php echo count($revid); ?>)</div></div>
    <div style="display:flex;flex-direction:column;gap:.3rem">
        <?php foreach ($revid as $url): ?>
        <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener" style="font-size:.78rem;color:var(--accent);word-break:break-all"><i class="fas fa-link" style="margin-right:.35rem;opacity:.7"></i><?php echo htmlspecialchars($url); ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($current['internal_notes']) && $canManage): ?>
<div class="d-card" style="border-color:rgba(245,158,11,.2);background:rgba(245,158,11,.04)">
    <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-lock" style="color:#fde68a"></i> Notes internes</div></div>
    <div style="font-size:.82rem;line-height:1.6;color:#fde68a;white-space:pre-wrap"><?php echo htmlspecialchars((string)$current['internal_notes']); ?></div>
</div>
<?php endif; ?>

<?php else: /* ═══════ LISTE ═══════ */ ?>

<div class="dr-layout">
    <aside>
        <div class="dr-filter">
            <div class="dr-ftt"><i class="fas fa-tag"></i> Type</div>
            <a href="<?php echo htmlspecialchars($base . '&type=all&status=' . urlencode($fStatus) . '&date=' . urlencode($fDate)); ?>" class="dr-fb <?php echo $fType==='all'?'active':''; ?>"><i class="fas fa-list"></i> Tous</a>
            <?php foreach ($RTYPES as $k => [$l, $b]): ?>
            <a href="<?php echo htmlspecialchars($base . '&type=' . urlencode($k) . '&status=' . urlencode($fStatus) . '&date=' . urlencode($fDate)); ?>" class="dr-fb <?php echo $fType===$k?'active':''; ?>"><?php echo htmlspecialchars($l); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="dr-filter">
            <div class="dr-ftt"><i class="fas fa-circle-check"></i> Statut</div>
            <a href="<?php echo htmlspecialchars($base . '&type=' . urlencode($fType) . '&status=all&date=' . urlencode($fDate)); ?>" class="dr-fb <?php echo $fStatus==='all'?'active':''; ?>">Tous</a>
            <?php foreach ($RSTATUS as $k => [$l, $b]): ?>
            <a href="<?php echo htmlspecialchars($base . '&type=' . urlencode($fType) . '&status=' . urlencode($k) . '&date=' . urlencode($fDate)); ?>" class="dr-fb <?php echo $fStatus===$k?'active':''; ?>"><?php echo htmlspecialchars($l); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="dr-filter">
            <div class="dr-ftt"><i class="fas fa-calendar"></i> Période</div>
            <?php foreach (['all'=>'Tout','today'=>"Aujourd'hui",'week'=>'7 jours','month'=>'30 jours'] as $k => $l): ?>
            <a href="<?php echo htmlspecialchars($base . '&type=' . urlencode($fType) . '&status=' . urlencode($fStatus) . '&date=' . urlencode($k)); ?>" class="dr-fb <?php echo $fDate===$k?'active':''; ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
        <a href="<?php echo htmlspecialchars($base . '&new=1'); ?>" class="btn btn-primary" style="width:100%;justify-content:center"><i class="fas fa-plus"></i> Nouveau rapport</a>
    </aside>
    <div>
        <form method="GET" style="display:flex;gap:.45rem;margin-bottom:.9rem">
            <input type="hidden" name="key" value="<?php echo htmlspecialchars($panelKey); ?>">
            <input type="hidden" name="view" value="reports">
            <input type="hidden" name="type"   value="<?php echo htmlspecialchars($fType); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($fStatus); ?>">
            <input type="hidden" name="date"   value="<?php echo htmlspecialchars($fDate); ?>">
            <input type="text" name="q" class="form-input" placeholder="Titre, contenu..." value="<?php echo htmlspecialchars($search); ?>" style="flex:1">
            <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars($base . '&type=' . urlencode($fType) . '&status=' . urlencode($fStatus) . '&date=' . urlencode($fDate)); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
        </form>

        <?php if (empty($reports)): ?>
        <div class="empty-state"><i class="fas fa-file-lines"></i>Aucun rapport pour cette division.<br><a href="<?php echo htmlspecialchars($base . '&new=1'); ?>" class="btn btn-primary btn-sm" style="margin-top:.7rem"><i class="fas fa-plus"></i> Créer le premier rapport</a></div>
        <?php else: foreach ($reports as $r):
            $rtags = json_decode($r['tags'] ?? '[]', true) ?: [];
            $evid  = json_decode($r['evidence'] ?? '[]', true) ?: [];
            [$rtl, $rtb] = $RTYPES[$r['type'] ?? ''] ?? [$r['type'] ?? '—', 'bg-gray'];
            $sc = $RSTATUS[$r['status'] ?? 'draft'] ?? ['—','bg-gray'];
            $detailUrl = $base . '&id=' . (int)$r['id'];
            $editable = $canEditThis($r);
        ?>
        <div class="dr-card" onclick="location.href='<?php echo htmlspecialchars($detailUrl); ?>'">
            <?php if ($editable): ?>
            <a href="<?php echo htmlspecialchars($base . '&edit=' . (int)$r['id']); ?>" onclick="event.stopPropagation()"
               style="position:absolute;top:.55rem;right:.55rem;width:28px;height:28px;border-radius:7px;background:var(--accent-soft);border:1px solid var(--accent-fade);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:.7rem;text-decoration:none;z-index:2">
                <i class="fas fa-pen"></i>
            </a>
            <?php endif; ?>
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.35rem;gap:.5rem;padding-right:<?php echo $editable?'2.2rem':'0'; ?>">
                <div style="flex:1;min-width:0">
                    <span class="badge <?php echo $rtb; ?>" style="margin-bottom:.3rem"><?php echo htmlspecialchars($rtl); ?></span>
                    <div style="font-weight:700;font-size:.88rem;color:#fff">#<?php echo str_pad((string)$r['id'],6,'0',STR_PAD_LEFT); ?> — <?php echo htmlspecialchars($r['title'] ?? '—'); ?></div>
                    <div style="font-size:.72rem;color:var(--txt-s);margin-top:.15rem">Par <?php echo htmlspecialchars($r['auteur'] ?? '—'); ?> · <?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></div>
                </div>
                <span class="badge <?php echo $sc[1]; ?>" style="flex-shrink:0"><?php echo $sc[0]; ?></span>
            </div>
            <?php if (!empty($r['description_rich']) || !empty($r['description'])): ?>
            <div style="font-size:.78rem;color:var(--txt-s);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;margin-bottom:.4rem"><?php echo htmlspecialchars(mb_substr(strip_tags((string)($r['description_rich'] ?: $r['description'])),0,180)); ?></div>
            <?php endif; ?>
            <div style="display:flex;gap:.28rem;flex-wrap:wrap;align-items:center">
                <?php foreach (array_slice($rtags,0,5) as $t):
                    $tc = '#94a3b8';
                    foreach ($TAGS as $pt) { if ($pt['label']===$t) { $tc=$pt['color']; break; } } ?>
                <span style="padding:.16rem .48rem;border-radius:4px;font-size:.62rem;font-weight:700;border:1px solid <?php echo $tc; ?>33;background:<?php echo $tc; ?>1a;color:<?php echo $tc; ?>"><?php echo htmlspecialchars($t); ?></span>
                <?php endforeach; ?>
                <?php if ($evid): ?><span class="badge bg-blue"><i class="fas fa-camera"></i> <?php echo count($evid); ?></span><?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php endif; ?>
