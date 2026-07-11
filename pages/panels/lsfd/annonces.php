<?php
/**
 * §12 — Messagerie & annonces LSFD
 * Annonces officielles du commandement (CRUD) + accès à la messagerie globale MDT.
 * Les notifications automatiques (rapport en attente, stock en rupture, candidature,
 * alerte risque) et la notification Discord passent par le bot MDT existant.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$selfUrl = SITE_URL . '/pages/panels/lsfd/annonces.php';

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $db->prepare("INSERT INTO lsfd_announcements (title, body, created_by) VALUES (?,?,?)")
               ->execute([trim($_POST['title']??''), trim($_POST['body']??''), $_SESSION['user_id']??null]);
            lsfd_log('create','announcement',(int)$db->lastInsertId(),'Annonce publiée');
            $_SESSION['lsfd_flash']='Annonce publiée.'; header("Location: $selfUrl"); exit;
        }
        if ($action === 'delete') { $db->prepare("DELETE FROM lsfd_announcements WHERE id=?")->execute([(int)($_POST['id']??0)]); $_SESSION['lsfd_flash']='Annonce supprimée.'; header("Location: $selfUrl"); exit; }
    } catch (Exception $e) { $_SESSION['lsfd_flash']="Erreur (migration ?) : ".$e->getMessage(); header("Location: $selfUrl"); exit; }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash=$_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$rows = [];
try { $rows = $db->query("SELECT a.*, NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS author
    FROM lsfd_announcements a LEFT JOIN users u ON u.id=a.created_by LEFT JOIN profiles p ON p.id=u.profile
    ORDER BY a.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$pageTitle = 'Annonces';
include __DIR__ . '/includes/header.php';
?>
<?php if ($flash): ?><div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="lf-pagehead">
  <div><div class="lf-ph-title"><i class="fas fa-bullhorn"></i> Messagerie & annonces</div><div class="lf-ph-sub">Commandement LSFD</div></div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="<?php echo SITE_URL; ?>/pages/mail/" class="lf-btn lf-btn-ghost"><i class="fas fa-envelope"></i> Messagerie MDT</a>
    <button class="lf-btn lf-btn-primary" onclick="document.getElementById('newAnnModal').classList.add('open')"><i class="fas fa-plus"></i> Publier une annonce</button>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;align-items:start">
  <div class="lf-card">
    <div class="lf-card-h"><h3><i class="fas fa-bullhorn"></i> Annonces officielles</h3></div>
    <?php if (empty($rows)): ?>
      <div class="lf-empty"><i class="fas fa-comment-slash"></i><p>Aucune annonce publiée</p></div>
    <?php else: foreach ($rows as $a): ?>
      <div style="padding:1rem 0;border-bottom:1px solid rgba(100,116,139,.1)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem">
          <div style="font-weight:700;color:#e8edf5;font-size:1rem"><?php echo htmlspecialchars($a['title']); ?></div>
          <form method="POST" style="margin:0" data-lsfd-confirm="Supprimer cette annonce ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form>
        </div>
        <div style="color:#cbd5e1;font-size:.9rem;line-height:1.6;margin:.5rem 0"><?php echo nl2br(htmlspecialchars($a['body'])); ?></div>
        <div style="color:#64748b;font-size:.75rem"><i class="fas fa-user"></i> <?php echo htmlspecialchars($a['author'] ?: '—'); ?> · <?php echo date('d/m/Y H:i', strtotime($a['created_at'])); ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="lf-card">
    <div class="lf-card-h"><h3><i class="fas fa-bell"></i> Notifications automatiques</h3></div>
    <p style="color:#94a3b8;line-height:1.7;font-size:.88rem">Le système MDT déclenche automatiquement (et notifie sur Discord via le bot) :</p>
    <ul style="color:#cbd5e1;font-size:.86rem;line-height:1.9;padding-left:1.1rem;margin-top:.5rem">
      <li>Nouveau rapport en attente de validation</li>
      <li>Stock d'équipement en rupture</li>
      <li>Nouvelle candidature reçue</li>
      <li>Alerte risque incendie élevé</li>
    </ul>
    <div style="margin-top:1rem;display:flex;flex-direction:column;gap:.5rem">
      <a href="<?php echo SITE_URL; ?>/pages/mail/" class="lf-btn lf-btn-ghost"><i class="fas fa-envelope-open-text"></i> Boîte de réception</a>
      <a href="<?php echo SITE_URL; ?>/pages/academy/candidature_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-user-plus"></i> Candidatures</a>
    </div>
  </div>
</div>

<div class="lf-modal-ov" id="newAnnModal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-bullhorn"></i> Nouvelle annonce</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="create">
      <div class="lf-modal-b">
        <div class="lf-field"><label>Titre</label><input class="lf-input" name="title" required></div>
        <div class="lf-field"><label>Message</label><textarea class="lf-textarea" name="body" style="min-height:140px" required></textarea></div>
      </div>
      <div class="lf-modal-f"><button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button><button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-paper-plane"></i> Publier</button></div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php';
