<?php
/** Annonces — visible par les membres, CRUD pour les gestionnaires. */
if (!isset($division)) exit;

$divId = (int)$division['id'];
$db    = div_db();

$announcements = [];
try {
    $stmt = $db->prepare("
        SELECT a.*, u.username AS author_name, u.avatar AS author_avatar
          FROM faction_division_announcements a
     LEFT JOIN users u ON u.id = a.author_id
         WHERE a.division_id = ?
         ORDER BY a.pinned DESC, a.created_at DESC
    ");
    $stmt->execute([$divId]);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<?php if ($canManage): ?>
<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-bullhorn"></i> Publier une annonce</div>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="add_announcement">
        <div class="form-group">
            <label class="form-label">Titre</label>
            <input type="text" name="title" class="form-input" required maxlength="200" placeholder="Ex. Briefing opérationnel du 15/04">
        </div>
        <div class="form-group">
            <label class="form-label">Contenu</label>
            <textarea name="content" class="form-textarea" required placeholder="Détails de l'annonce…"></textarea>
        </div>
        <div style="display:flex;gap:.6rem;align-items:center;">
            <label style="display:flex;align-items:center;gap:.35rem;font-size:.78rem;color:var(--txt-s);cursor:pointer;">
                <input type="checkbox" name="pinned" value="1" style="accent-color:var(--accent);">
                <i class="fas fa-thumbtack"></i> Épingler en tête
            </label>
            <div style="flex:1;"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Publier</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (empty($announcements)): ?>
<div class="d-card">
    <div class="empty-state"><i class="fas fa-bullhorn"></i>Aucune annonce publiée pour cette division.</div>
</div>
<?php else: foreach ($announcements as $a): ?>
<div class="d-card" style="<?php if ($a['pinned']): ?>border-left:3px solid var(--accent);<?php endif; ?>">
    <div class="d-card-hd">
        <div>
            <div class="d-card-tt">
                <?php if ($a['pinned']): ?><i class="fas fa-thumbtack"></i><?php else: ?><i class="fas fa-bullhorn"></i><?php endif; ?>
                <?php echo htmlspecialchars($a['title']); ?>
            </div>
            <div class="d-card-sub">
                <i class="fas fa-user" style="opacity:.5;"></i>
                <?php echo htmlspecialchars($a['author_name'] ?? 'Système'); ?>
                · <?php echo date('d/m/Y H:i', strtotime($a['created_at'])); ?>
            </div>
        </div>
        <?php if ($canManage): ?>
        <form method="POST" onsubmit="return confirm('Supprimer cette annonce ?');" style="margin:0;">
            <input type="hidden" name="action" value="delete_announcement">
            <input type="hidden" name="announcement_id" value="<?php echo (int)$a['id']; ?>">
            <button type="submit" class="btn btn-sm btn-danger" title="Supprimer"><i class="fas fa-trash"></i></button>
        </form>
        <?php endif; ?>
    </div>
    <div style="color:var(--txt);line-height:1.6;white-space:pre-line;font-size:.88rem;">
        <?php echo nl2br(htmlspecialchars($a['content'])); ?>
    </div>
</div>
<?php endforeach; endif; ?>
