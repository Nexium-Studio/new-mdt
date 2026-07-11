<?php
/** Documents — partage de liens / ressources. */
if (!isset($division)) exit;

$divId = (int)$division['id'];
$db    = div_db();

$documents = [];
try {
    $stmt = $db->prepare("
        SELECT d.*, u.username AS author_name
          FROM faction_division_documents d
     LEFT JOIN users u ON u.id = d.author_id
         WHERE d.division_id = ?
         ORDER BY d.created_at DESC
    ");
    $stmt->execute([$divId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<?php if ($canManage): ?>
<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-folder-plus"></i> Ajouter un document</div>
        <span class="d-card-sub">Les documents partagés sont visibles par tous les membres de la division.</span>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="add_document">
        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label class="form-label">Titre</label>
                <input type="text" name="title" class="form-input" required maxlength="200" placeholder="Ex. SOP : Intervention barricadé">
            </div>
            <div class="form-group" style="flex:3;">
                <label class="form-label">URL / Lien</label>
                <input type="url" name="url" class="form-input" placeholder="https://drive.google.com/…" maxlength="512">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-textarea" placeholder="Résumé ou notes…"></textarea>
        </div>
        <div style="text-align:right;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-folder-open"></i> Documents (<?php echo count($documents); ?>)</div>
    </div>
    <?php if (empty($documents)): ?>
        <div class="empty-state"><i class="fas fa-folder-open"></i>Aucun document partagé.</div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.75rem;">
        <?php foreach ($documents as $d): ?>
        <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:10px;padding:.9rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                <div style="display:flex;align-items:center;gap:.55rem;min-width:0;">
                    <i class="fas fa-file-lines" style="color:var(--accent);"></i>
                    <strong style="color:#fff;font-size:.88rem;"><?php echo htmlspecialchars($d['title']); ?></strong>
                </div>
                <?php if ($canManage): ?>
                <form method="POST" onsubmit="return confirm('Supprimer ce document ?');" style="margin:0;">
                    <input type="hidden" name="action" value="delete_document">
                    <input type="hidden" name="document_id" value="<?php echo (int)$d['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger" title="Supprimer"><i class="fas fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
            <?php if (!empty($d['description'])): ?>
            <div style="font-size:.78rem;color:var(--txt-s);margin-bottom:.5rem;line-height:1.4;">
                <?php echo nl2br(htmlspecialchars(mb_strimwidth($d['description'], 0, 200, '…'))); ?>
            </div>
            <?php endif; ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.3rem;">
                <span style="font-size:.7rem;color:var(--txt-m);">
                    <?php echo htmlspecialchars($d['author_name'] ?? '—'); ?>
                    · <?php echo date('d/m/Y', strtotime($d['created_at'])); ?>
                </span>
                <?php if (!empty($d['url'])): ?>
                <a href="<?php echo htmlspecialchars($d['url']); ?>" target="_blank" rel="noopener" class="btn btn-sm">
                    <i class="fas fa-arrow-up-right-from-square"></i> Ouvrir
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
