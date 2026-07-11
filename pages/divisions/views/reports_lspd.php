<?php
/**
 * Lecture seule des rapports LSPD (table `reports`).
 * Accessible depuis les panels des divisions ayant la capabilité `view_lspd_reports`.
 *
 * Attend : $division, $canManage, $currentUserId, $panelKey, $baseUrl (via header).
 */
if (!isset($division)) exit;
if (!div_has_capability($division, 'view_lspd_reports')) {
    echo '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> Lecture des rapports LSPD non autorisée pour cette division.</div>';
    return;
}

$db = div_db();
$baseUrl = SITE_URL . '/pages/divisions/panel.php?key=' . urlencode($panelKey) . '&view=reports_lspd';

$fType   = $_GET['type'] ?? 'all';
$fDate   = $_GET['date'] ?? 'all';
$search  = trim((string)($_GET['q'] ?? ''));
$viewId  = (int)($_GET['id'] ?? 0);

$RTYPES  = [
    'RA'              => ["RA — Rapport d'Arrestation", 'bg-red'],
    'Shooting Report' => ['Rapport de Tirs',            'bg-orange'],
    'Traffic Ticket'  => ['Contravention',              'bg-yellow'],
    'Investigation'   => ['Investigation',              'bg-purple'],
    'Use of Force'    => ['Usage de la Force',          'bg-orange'],
    'Arrest Report'   => ["Rapport d'Arrestation",      'bg-blue'],
    'Missing Person'  => ['Personne Disparue',          'bg-gray'],
    'Incident Report' => ["Rapport d'Incident",         'bg-blue'],
];
$RSTATUS = [
    'draft'     => ['Brouillon','bg-gray'],
    'submitted' => ['Soumis',   'bg-blue'],
    'approved'  => ['Approuvé', 'bg-green'],
    'closed'    => ['Clôturé',  'bg-gray'],
];
$RPRIO   = [
    'low'=>['Basse','bg-green'], 'normal'=>['Normale','bg-blue'],
    'high'=>['Haute','bg-yellow'], 'critical'=>['Critique','bg-red'],
];

$predefinedTags = [];
try {
    $predefinedTags = $db->query("SELECT label,color FROM report_tags ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Détail d'un rapport ─────────────────────────────────────────────────────
$viewReport = null;
if ($viewId > 0) {
    $stmt = $db->prepare("
        SELECT r.*, u.username AS auteur
          FROM reports r
          LEFT JOIN users u ON u.id = r.created_by
         WHERE r.id = ? AND r.faction = 'LSPD'
         LIMIT 1
    ");
    $stmt->execute([$viewId]);
    $viewReport = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Liste ──────────────────────────────────────────────────────────────────
$reports = [];
if (!$viewReport) {
    $q = "SELECT r.*, u.username AS auteur
            FROM reports r
       LEFT JOIN users u ON u.id = r.created_by
           WHERE r.faction = 'LSPD'";
    $params = [];
    if ($fType !== 'all') { $q .= " AND r.type = ?"; $params[] = $fType; }
    if ($fDate === 'today')  $q .= " AND DATE(r.created_at) = CURDATE()";
    if ($fDate === 'week')   $q .= " AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    if ($fDate === 'month')  $q .= " AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    if ($search !== '') {
        $q .= " AND (r.title LIKE ? OR r.description LIKE ?)";
        $s = '%' . $search . '%'; $params[] = $s; $params[] = $s;
    }
    $q .= " ORDER BY r.created_at DESC LIMIT 150";
    try {
        $st = $db->prepare($q); $st->execute($params);
        $reports = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $reports = []; }
}

// ── Résoudre joueurs impliqués (pour la vue détail) ────────────────────────
$resolve_profiles = function(?string $json) use ($db): array {
    $arr = $json ? json_decode($json, true) : [];
    if (!is_array($arr) || !$arr) return [];
    $ids = array_filter(array_map('intval', $arr));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $db->prepare("SELECT id, firstname, lastname FROM profiles WHERE id IN ($ph)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        }
        return $out;
    } catch (Throwable $e) { return []; }
};
$resolve_officers = function(?string $json) use ($db): array {
    $arr = $json ? json_decode($json, true) : [];
    if (!is_array($arr) || !$arr) return [];
    $ids = array_filter(array_map('intval', $arr));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $db->prepare("SELECT id, username FROM users WHERE id IN ($ph)");
        $st->execute($ids);
        return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'username');
    } catch (Throwable $e) { return []; }
};

?>
<style>
.drlp-layout{display:grid;grid-template-columns:230px 1fr;gap:1.1rem}
@media(max-width:1024px){.drlp-layout{grid-template-columns:1fr}}
.drlp-filter{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:.9rem;margin-bottom:.7rem}
.drlp-ftt{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-m);margin-bottom:.6rem}
.drlp-fb{display:flex;align-items:center;gap:.5rem;padding:.42rem .7rem;border-radius:7px;font-size:.76rem;color:var(--txt-s);text-decoration:none;transition:all .15s}
.drlp-fb:hover,.drlp-fb.active{background:var(--accent-soft);color:var(--accent)}
.drlp-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:.9rem 1rem;margin-bottom:.55rem;cursor:pointer;transition:all .18s;position:relative;overflow:hidden}
.drlp-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);opacity:0;transition:opacity .2s}
.drlp-card:hover{border-color:var(--bdr-h);transform:translateX(2px)}
.drlp-card:hover::before{opacity:1}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .55rem;border-radius:5px;font-size:.62rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.bg-red{background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.25)}
.bg-orange{background:rgba(249,115,22,.12);color:#fdba74;border:1px solid rgba(249,115,22,.25)}
.bg-yellow{background:rgba(245,158,11,.12);color:#fde68a;border:1px solid rgba(245,158,11,.25)}
.bg-green{background:rgba(16,185,129,.12);color:#6ee7b7;border:1px solid rgba(16,185,129,.25)}
.bg-blue{background:rgba(59,130,246,.12);color:#93c5fd;border:1px solid rgba(59,130,246,.25)}
.bg-purple{background:rgba(168,85,247,.12);color:#c4b5fd;border:1px solid rgba(168,85,247,.25)}
.bg-gray{background:rgba(100,116,139,.12);color:#cbd5e1;border:1px solid rgba(100,116,139,.25)}
.ro-banner{display:flex;align-items:center;gap:.55rem;padding:.55rem .85rem;border-radius:8px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);color:#93c5fd;font-size:.76rem;margin-bottom:.9rem}
.ro-banner i{font-size:.85rem}
</style>

<div class="ro-banner">
    <i class="fas fa-eye"></i>
    <strong>Consultation en lecture seule</strong> — les rapports LSPD ne peuvent être ni modifiés ni supprimés depuis ce panel.
</div>

<?php if ($viewReport):
    $rtags   = json_decode($viewReport['tags']     ?? '[]', true) ?: [];
    $revid   = json_decode($viewReport['evidence'] ?? '[]', true) ?: [];
    [$rtl, $rtb] = $RTYPES[$viewReport['type'] ?? ''] ?? [$viewReport['type'] ?? '—', 'bg-gray'];
    $sOfc = $resolve_officers($viewReport['officers']  ?? $viewReport['officers_involved'] ?? null);
    $sCiv = $resolve_profiles($viewReport['civilians'] ?? null);
    $sWit = $resolve_profiles($viewReport['witnesses'] ?? null);
    $sSus = $resolve_profiles($viewReport['suspects']  ?? null);
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.7rem">
    <a href="<?php echo htmlspecialchars($baseUrl); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
    <button onclick="window.print()" class="btn btn-ghost btn-sm"><i class="fas fa-print"></i> Imprimer</button>
</div>

<div class="d-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.7rem">
        <div>
            <span class="badge <?php echo $rtb; ?>" style="margin-bottom:.45rem"><?php echo htmlspecialchars($rtl); ?></span>
            <div style="font-family:'Syne',sans-serif;font-size:1.18rem;font-weight:800;color:#fff"><?php echo htmlspecialchars($viewReport['title']); ?></div>
            <div style="font-size:.7rem;color:var(--txt-s);margin-top:.25rem">
                #<?php echo str_pad((string)$viewReport['id'],6,'0',STR_PAD_LEFT); ?>
            </div>
        </div>
        <div style="text-align:right">
            <div style="font-size:.72rem;color:var(--txt-m)">Par <strong style="color:var(--txt)"><?php echo htmlspecialchars($viewReport['auteur'] ?? '—'); ?></strong></div>
            <div style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y H:i', strtotime($viewReport['created_at'])); ?></div>
            <?php $sc = $RSTATUS[$viewReport['status'] ?? 'draft'] ?? ['—','bg-gray']; ?>
            <span class="badge <?php echo $sc[1]; ?>" style="margin-top:.35rem"><?php echo $sc[0]; ?></span>
            <?php if (!empty($viewReport['priority'])):
                $pp = $RPRIO[$viewReport['priority']] ?? null;
                if ($pp): ?><span class="badge <?php echo $pp[1]; ?>" style="margin-top:.35rem;margin-left:.25rem"><?php echo $pp[0]; ?></span><?php endif;
            endif; ?>
        </div>
    </div>

    <?php if (!empty($rtags)): ?>
    <div style="margin-bottom:.75rem">
        <?php foreach ($rtags as $t):
            $tc = '#94a3b8';
            foreach ($predefinedTags as $pt) { if ($pt['label'] === $t) { $tc = $pt['color']; break; } } ?>
        <span style="padding:.2rem .55rem;border-radius:5px;font-size:.64rem;font-weight:700;border:1px solid <?php echo $tc; ?>33;background:<?php echo $tc; ?>1a;color:<?php echo $tc; ?>;margin-right:.18rem;display:inline-flex"><?php echo htmlspecialchars($t); ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($viewReport['location'])): ?>
    <div style="font-size:.78rem;color:var(--txt-s);margin-bottom:.4rem"><i class="fas fa-location-dot" style="color:var(--accent);margin-right:.4rem"></i><?php echo htmlspecialchars($viewReport['location']); ?></div>
    <?php endif; ?>
    <?php if (!empty($viewReport['incident_at'])): ?>
    <div style="font-size:.78rem;color:var(--txt-s)"><i class="fas fa-clock" style="color:var(--accent);margin-right:.4rem"></i>Incident : <?php echo date('d/m/Y H:i', strtotime($viewReport['incident_at'])); ?></div>
    <?php endif; ?>
</div>

<?php if (!empty($viewReport['description_rich']) || !empty($viewReport['description'])): ?>
<div class="d-card">
    <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-file-lines"></i> Description</div></div>
    <div style="font-size:.85rem;line-height:1.7;color:var(--txt)">
        <?php echo $viewReport['description_rich'] ?: nl2br(htmlspecialchars((string)$viewReport['description'])); ?>
    </div>
</div>
<?php endif; ?>

<?php if ($sOfc || $sCiv || $sWit || $sSus): ?>
<div class="d-card">
    <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-users"></i> Personnes impliquées</div></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;font-size:.8rem">
        <?php if ($sOfc): ?><div><div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.25rem">Officiers</div><?php foreach ($sOfc as $n): ?><div>• <?php echo htmlspecialchars($n); ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($sCiv): ?><div><div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.25rem">Civils</div><?php foreach ($sCiv as $n): ?><div>• <?php echo htmlspecialchars($n); ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($sWit): ?><div><div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.25rem">Témoins</div><?php foreach ($sWit as $n): ?><div>• <?php echo htmlspecialchars($n); ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($sSus): ?><div><div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.25rem">Suspects</div><?php foreach ($sSus as $n): ?><div>• <?php echo htmlspecialchars($n); ?></div><?php endforeach; ?></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($revid)): ?>
<div class="d-card">
    <div class="d-card-hd"><div class="d-card-tt"><i class="fas fa-camera"></i> Preuves (<?php echo count($revid); ?>)</div></div>
    <div style="display:flex;flex-direction:column;gap:.3rem">
        <?php foreach ($revid as $url): ?>
        <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener" style="font-size:.78rem;color:var(--accent);word-break:break-all;text-decoration:none"><i class="fas fa-link" style="margin-right:.35rem;opacity:.7"></i><?php echo htmlspecialchars($url); ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php else: /* ═══════ LISTE ═══════ */ ?>

<div class="drlp-layout">
    <aside>
        <div class="drlp-filter">
            <div class="drlp-ftt"><i class="fas fa-tag"></i> Type</div>
            <a href="<?php echo htmlspecialchars($baseUrl . '&type=all&date=' . urlencode($fDate)); ?>" class="drlp-fb <?php echo $fType==='all'?'active':''; ?>"><i class="fas fa-list"></i> Tous</a>
            <?php foreach ($RTYPES as $k => [$l, $b]): ?>
            <a href="<?php echo htmlspecialchars($baseUrl . '&type=' . urlencode($k) . '&date=' . urlencode($fDate)); ?>" class="drlp-fb <?php echo $fType===$k?'active':''; ?>"><i class="fas fa-file"></i> <?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
        <div class="drlp-filter">
            <div class="drlp-ftt"><i class="fas fa-calendar"></i> Période</div>
            <?php foreach (['all'=>'Tout','today'=>"Aujourd'hui",'week'=>'7 jours','month'=>'30 jours'] as $k => $l): ?>
            <a href="<?php echo htmlspecialchars($baseUrl . '&type=' . urlencode($fType) . '&date=' . urlencode($k)); ?>" class="drlp-fb <?php echo $fDate===$k?'active':''; ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
    </aside>
    <div>
        <form method="GET" style="display:flex;gap:.45rem;margin-bottom:.9rem">
            <input type="hidden" name="key" value="<?php echo htmlspecialchars($panelKey); ?>">
            <input type="hidden" name="view" value="reports_lspd">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($fType); ?>">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($fDate); ?>">
            <input type="text" name="q" class="form-input" placeholder="Titre, contenu..." value="<?php echo htmlspecialchars($search); ?>" style="flex:1">
            <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars($baseUrl . '&type=' . urlencode($fType) . '&date=' . urlencode($fDate)); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
        </form>

        <?php if (empty($reports)): ?>
        <div class="empty-state"><i class="fas fa-file-lines"></i>Aucun rapport LSPD ne correspond à ces filtres.</div>
        <?php else: foreach ($reports as $r):
            $rtags = json_decode($r['tags'] ?? '[]', true) ?: [];
            $evid  = json_decode($r['evidence'] ?? '[]', true) ?: [];
            [$rtl, $rtb] = $RTYPES[$r['type'] ?? ''] ?? [$r['type'] ?? '—', 'bg-gray'];
            $sc = $RSTATUS[$r['status'] ?? 'draft'] ?? ['—','bg-gray'];
            $detailUrl = $baseUrl . '&id=' . (int)$r['id'];
        ?>
        <div class="drlp-card" onclick="location.href='<?php echo htmlspecialchars($detailUrl); ?>'">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.35rem;gap:.5rem">
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
                    foreach ($predefinedTags as $pt) { if ($pt['label']===$t) { $tc=$pt['color']; break; } } ?>
                <span style="padding:.16rem .48rem;border-radius:4px;font-size:.62rem;font-weight:700;border:1px solid <?php echo $tc; ?>33;background:<?php echo $tc; ?>1a;color:<?php echo $tc; ?>"><?php echo htmlspecialchars($t); ?></span>
                <?php endforeach; ?>
                <?php if ($evid): ?><span class="badge bg-blue"><i class="fas fa-camera"></i> <?php echo count($evid); ?></span><?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php endif; ?>
