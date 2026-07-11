<?php
/** Grades de division — gestion CRUD. */
if (!isset($division)) exit;

$divId  = (int)$division['id'];
$grades = div_get_grades($divId);
?>

<?php if ($canManage): ?>
<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-plus"></i> Créer un grade de division</div>
        <span class="d-card-sub">Ces grades sont propres à <?php echo htmlspecialchars($division['name']); ?>.</span>
    </div>
    <form method="POST" class="form-row" style="align-items:flex-end;">
        <input type="hidden" name="action" value="add_grade">

        <div class="form-group" style="flex:2;">
            <label class="form-label">Nom du grade</label>
            <input type="text" name="name" class="form-input" required placeholder="Ex. Commanding Officer, Instructor, Element…" maxlength="160">
        </div>

        <div class="form-group" style="flex:0 0 120px;">
            <label class="form-label">Ordre (1 = +haut)</label>
            <input type="number" name="rank_order" class="form-input" min="1" max="99" value="<?php echo count($grades) + 1; ?>">
        </div>

        <div class="form-group" style="flex:0 0 auto;">
            <label class="form-label">&nbsp;</label>
            <label style="display:flex;align-items:center;gap:.4rem;padding:.55rem .8rem;border:1px solid var(--bdr);border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="can_manage" value="1" style="accent-color:var(--accent);">
                <span style="font-size:.78rem;">Peut gérer la division</span>
            </label>
        </div>

        <div class="form-group" style="flex:0 0 auto;">
            <label class="form-label">&nbsp;</label>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Créer</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-medal"></i> Hiérarchie (<?php echo count($grades); ?>)</div>
    </div>
    <?php if (empty($grades)): ?>
        <div class="empty-state">
            <i class="fas fa-medal"></i>
            Aucun grade de division défini.
            <?php if ($canManage): ?><br><small>Utilisez le formulaire ci-dessus pour créer le premier.</small><?php endif; ?>
        </div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th style="width:70px;">#</th>
                <th>Nom du grade</th>
                <th style="width:120px;">Permissions</th>
                <?php if ($canManage): ?><th style="text-align:right;">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($grades as $g): ?>
            <tr>
                <?php if ($canManage): ?>
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="action" value="update_grade">
                    <input type="hidden" name="grade_id" value="<?php echo (int)$g['id']; ?>">
                    <td>
                        <input type="number" name="rank_order" class="form-input" style="width:60px;padding:.35rem .4rem;font-size:.8rem;" min="1" max="99" value="<?php echo (int)$g['rank_order']; ?>">
                    </td>
                    <td>
                        <input type="text" name="name" class="form-input" style="padding:.35rem .5rem;font-size:.85rem;" value="<?php echo htmlspecialchars($g['name']); ?>">
                    </td>
                    <td>
                        <label style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;color:var(--txt-s);cursor:pointer;">
                            <input type="checkbox" name="can_manage" value="1" <?php if ((int)$g['can_manage'] === 1) echo 'checked'; ?> style="accent-color:var(--accent);">
                            Gère la div.
                        </label>
                    </td>
                    <td style="text-align:right;">
                        <button type="submit" class="btn btn-sm" title="Enregistrer"><i class="fas fa-save"></i></button>
                </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer le grade « <?php echo htmlspecialchars(addslashes($g['name'])); ?> » ?');">
                            <input type="hidden" name="action" value="delete_grade">
                            <input type="hidden" name="grade_id" value="<?php echo (int)$g['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Supprimer"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                <?php else: ?>
                    <td><strong>#<?php echo (int)$g['rank_order']; ?></strong></td>
                    <td><?php echo htmlspecialchars($g['name']); ?></td>
                    <td>
                        <?php if ((int)$g['can_manage'] === 1): ?>
                            <span class="chip accent"><i class="fas fa-crown"></i> Chef</span>
                        <?php else: ?>
                            <span class="chip">Membre</span>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
