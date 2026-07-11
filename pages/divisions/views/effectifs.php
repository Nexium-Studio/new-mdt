<?php
/** Effectifs — liste, ajout, édition, suppression de membres. */
if (!isset($division)) exit;

$divId    = (int)$division['id'];
$members  = div_get_members($divId);
$grades   = div_get_grades($divId);
$candidates = $canManage ? div_get_candidate_users($divId) : [];
?>

<?php if ($canManage): ?>
<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-user-plus"></i> Ajouter un membre</div>
        <span class="d-card-sub">Les officiers proposés appartiennent déjà à la faction <?php echo htmlspecialchars($division['f_name']); ?>.</span>
    </div>
    <?php if (empty($candidates)): ?>
        <div class="empty-state">
            <i class="fas fa-user-check"></i>
            Aucun officier disponible. Tous les membres de la faction sont déjà dans cette division.
        </div>
    <?php else: ?>
    <form method="POST" class="form-row" style="align-items:flex-end;">
        <input type="hidden" name="action" value="add_member">
        <input type="hidden" name="view_back" value="effectifs">

        <div class="form-group">
            <label class="form-label">Officier</label>
            <select name="user_id" class="form-select" required>
                <option value="">-- Sélectionner un officier --</option>
                <?php foreach ($candidates as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>">
                        <?php echo htmlspecialchars($u['username']); ?>
                        <?php if (!empty($u['faction_rank'])) echo ' — '.htmlspecialchars($u['faction_rank']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Grade de division</label>
            <select name="division_grade_id" class="form-select">
                <option value="">(aucun — accès observateur)</option>
                <?php foreach ($grades as $g): ?>
                    <option value="<?php echo (int)$g['id']; ?>">
                        #<?php echo (int)$g['rank_order']; ?> · <?php echo htmlspecialchars($g['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Rôle (optionnel)</label>
            <input type="text" name="role" class="form-input" placeholder="Ex. Instructeur, CO…" maxlength="120">
        </div>

        <div class="form-group" style="flex:0 0 auto;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-users"></i> Membres (<?php echo count($members); ?>)</div>
    </div>

    <?php if (empty($members)): ?>
        <div class="empty-state"><i class="fas fa-user-slash"></i>Aucun membre pour cette division.</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Officier</th>
                <th>Grade de faction</th>
                <th>Grade de division</th>
                <th>Rôle</th>
                <th>Depuis</th>
                <?php if ($canManage): ?><th style="text-align:right;">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($members as $m): ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:.55rem;">
                        <div style="width:28px;height:28px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.72rem;">
                            <?php echo strtoupper(substr($m['username'], 0, 1)); ?>
                        </div>
                        <strong style="color:#fff;"><?php echo htmlspecialchars($m['username']); ?></strong>
                        <?php if ((int)$m['user_id'] === $currentUserId): ?>
                            <span class="chip accent" style="font-size:.58rem;">Vous</span>
                        <?php endif; ?>
                        <?php if ((int)($m['grade_can_manage'] ?? 0) === 1): ?>
                            <span class="chip ok"><i class="fas fa-crown"></i> Chef</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td style="color:var(--txt-s);"><?php echo htmlspecialchars($m['faction_rank'] ?? '—'); ?></td>
                <td>
                    <?php if ($canManage): ?>
                    <form method="POST" style="display:flex;gap:.4rem;align-items:center;margin:0;">
                        <input type="hidden" name="action" value="update_member">
                        <input type="hidden" name="membership_id" value="<?php echo (int)$m['id']; ?>">
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($m['role'] ?? '', ENT_QUOTES); ?>">
                        <input type="hidden" name="view_back" value="effectifs">
                        <select name="division_grade_id" class="form-select" style="font-size:.75rem;padding:.3rem .45rem;" onchange="this.form.submit()">
                            <option value="">(aucun)</option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?php echo (int)$g['id']; ?>" <?php if ((int)$g['id'] === (int)($m['division_grade_id'] ?? 0)) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($g['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php else: ?>
                        <?php echo htmlspecialchars($m['grade_name'] ?? '—'); ?>
                    <?php endif; ?>
                </td>
                <td style="color:var(--txt-s);"><?php echo htmlspecialchars($m['role'] ?? '—'); ?></td>
                <td style="color:var(--txt-s);font-family:'IBM Plex Mono',monospace;font-size:.75rem;">
                    <?php echo date('d/m/Y', strtotime($m['joined_date'])); ?>
                </td>
                <?php if ($canManage): ?>
                <td style="text-align:right;">
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Retirer <?php echo htmlspecialchars(addslashes($m['username'])); ?> de la division ?');">
                        <input type="hidden" name="action" value="remove_member">
                        <input type="hidden" name="membership_id" value="<?php echo (int)$m['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Retirer">
                            <i class="fas fa-user-minus"></i>
                        </button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
