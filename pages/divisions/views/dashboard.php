<?php
/** Dashboard de la division — vue d'ensemble. */
if (!isset($division)) exit;

$divId = (int)$division['id'];
$db    = div_db();

$members  = div_get_members($divId);
$grades   = div_get_grades($divId);

$nbMembers = count($members);
$nbGrades  = count($grades);

$nbAnnouncements = 0;
$recentAnn = [];
try {
    $nbAnnouncements = (int)$db->query("SELECT COUNT(*) FROM faction_division_announcements WHERE division_id=".$divId)->fetchColumn();
    $stmt = $db->prepare("
        SELECT a.*, u.username
          FROM faction_division_announcements a
     LEFT JOIN users u ON u.id = a.author_id
         WHERE a.division_id = ?
         ORDER BY a.pinned DESC, a.created_at DESC
         LIMIT 3
    ");
    $stmt->execute([$divId]);
    $recentAnn = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$recentLogs = [];
try {
    $stmt = $db->prepare("
        SELECT l.*, u.username
          FROM faction_division_logs l
     LEFT JOIN users u ON u.id = l.user_id
         WHERE l.division_id = ?
         ORDER BY l.created_at DESC
         LIMIT 5
    ");
    $stmt->execute([$divId]);
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Mon appartenance
$myMembership = null;
foreach ($members as $m) {
    if ((int)$m['user_id'] === $currentUserId) { $myMembership = $m; break; }
}
?>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-lbl">Membres</div>
        <div class="stat-val"><?php echo $nbMembers; ?></div>
        <div class="stat-sub">Officiers assignés</div>
    </div>
    <div class="stat">
        <div class="stat-lbl">Grades de division</div>
        <div class="stat-val"><?php echo $nbGrades; ?></div>
        <div class="stat-sub">Hiérarchie propre</div>
    </div>
    <div class="stat">
        <div class="stat-lbl">Annonces</div>
        <div class="stat-val"><?php echo $nbAnnouncements; ?></div>
        <div class="stat-sub">Publiées au total</div>
    </div>
    <div class="stat">
        <div class="stat-lbl">Mon statut</div>
        <div class="stat-val" style="font-size:1rem;padding-top:.55rem;">
            <?php if ($myMembership): ?>
                <?php echo htmlspecialchars($myMembership['grade_name'] ?? 'Membre'); ?>
            <?php elseif (div_is_staff_bypass()): ?>
                Observateur staff
            <?php else: ?>—<?php endif; ?>
        </div>
        <div class="stat-sub">
            <?php echo $myMembership ? 'Depuis '.date('d/m/Y', strtotime($myMembership['joined_date'])) : '—'; ?>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div class="d-card">
        <div class="d-card-hd">
            <div class="d-card-tt"><i class="fas fa-bullhorn"></i> Dernières annonces</div>
            <a href="?key=<?php echo urlencode($division['panel_key']); ?>&view=annonces" class="btn btn-sm">Tout voir</a>
        </div>
        <?php if (empty($recentAnn)): ?>
            <div class="empty-state"><i class="fas fa-bullhorn"></i>Aucune annonce pour le moment</div>
        <?php else: foreach ($recentAnn as $a): ?>
            <div style="padding:.65rem .75rem;border:1px solid var(--bdr);border-radius:8px;margin-bottom:.5rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
                    <strong style="color:#fff;font-size:.88rem;">
                        <?php if ($a['pinned']): ?><i class="fas fa-thumbtack" style="color:var(--accent);margin-right:.3rem;"></i><?php endif; ?>
                        <?php echo htmlspecialchars($a['title']); ?>
                    </strong>
                    <span class="chip"><?php echo date('d/m H:i', strtotime($a['created_at'])); ?></span>
                </div>
                <div style="color:var(--txt-s);font-size:.78rem;margin-top:.35rem;white-space:pre-line;">
                    <?php echo htmlspecialchars(mb_strimwidth($a['content'], 0, 180, '…')); ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="d-card">
        <div class="d-card-hd">
            <div class="d-card-tt"><i class="fas fa-users"></i> Membres récents</div>
            <a href="?key=<?php echo urlencode($division['panel_key']); ?>&view=effectifs" class="btn btn-sm">Voir tout</a>
        </div>
        <?php if (empty($members)): ?>
            <div class="empty-state"><i class="fas fa-users"></i>Aucun membre</div>
        <?php else:
            $recent = array_slice($members, 0, 6);
            foreach ($recent as $m): ?>
            <div style="display:flex;align-items:center;gap:.6rem;padding:.55rem 0;border-bottom:1px solid var(--bdr);">
                <div style="width:32px;height:32px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;">
                    <?php echo strtoupper(substr($m['username'], 0, 1)); ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;color:#fff;font-size:.85rem;"><?php echo htmlspecialchars($m['username']); ?></div>
                    <div style="font-size:.7rem;color:var(--txt-s);">
                        <?php echo htmlspecialchars($m['grade_name'] ?? $m['faction_rank'] ?? '—'); ?>
                        <?php if (!empty($m['role'])): ?> · <em><?php echo htmlspecialchars($m['role']); ?></em><?php endif; ?>
                    </div>
                </div>
                <?php if ((int)($m['grade_can_manage'] ?? 0) === 1): ?>
                    <span class="chip accent"><i class="fas fa-crown"></i> Chef</span>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-clock-rotate-left"></i> Activité récente</div>
        <?php if ($canManage): ?>
        <a href="?key=<?php echo urlencode($division['panel_key']); ?>&view=logs" class="btn btn-sm">Journal complet</a>
        <?php endif; ?>
    </div>
    <?php if (empty($recentLogs)): ?>
        <div class="empty-state"><i class="fas fa-clock-rotate-left"></i>Aucune activité enregistrée</div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Quand</th><th>Par</th><th>Action</th><th>Cible</th></tr></thead>
        <tbody>
        <?php foreach ($recentLogs as $l): ?>
            <tr>
                <td style="color:var(--txt-s);font-family:'IBM Plex Mono',monospace;font-size:.75rem;">
                    <?php echo date('d/m H:i', strtotime($l['created_at'])); ?>
                </td>
                <td><?php echo htmlspecialchars($l['username'] ?? 'système'); ?></td>
                <td><span class="chip accent"><?php echo htmlspecialchars($l['action']); ?></span></td>
                <td style="color:var(--txt-s);"><?php echo htmlspecialchars($l['target'] ?? '—'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if (!empty($division['description'])): ?>
<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-circle-info"></i> À propos de la division</div>
    </div>
    <p style="color:var(--txt-s);line-height:1.7;white-space:pre-line;">
        <?php echo htmlspecialchars($division['description']); ?>
    </p>
</div>
<?php endif; ?>
