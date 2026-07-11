<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Documents';
$db  = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';
$uid = $_SESSION['user_id'];

// ── ACTIONS ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            // Récupération null-safe de tous les champs
            $dossier_id  = intval($_POST['dossier_id'] ?? 0) ?: null;
            $client_id   = intval($_POST['client_id']  ?? 0) ?: null;
            $type        = $_POST['type']        ?? 'autre';
            $titre       = trim($_POST['titre']   ?? '');
            $contenu     = trim($_POST['contenu'] ?? '');
            $confidentiel= isset($_POST['confidentiel']) ? 1 : 0;

            // Types autorisés
            $typesOk = ['preuve','contrat','temoignage','jugement','plaidoirie','autre'];
            if (!in_array($type, $typesOk)) $type = 'autre';

            if (empty($titre)) throw new Exception('Le titre est requis.');
            if (empty($contenu)) throw new Exception('Le contenu est requis.');

            $db->prepare("
                INSERT INTO avocat_documents
                    (dossier_id, client_id, type, titre, contenu, confidentiel, created_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$dossier_id, $client_id, $type, $titre, $contenu, $confidentiel, $uid]);

            $msg = 'Document créé avec succès.';
            $msgType = 'ok';
        } catch (Exception $e) {
            $msg = 'Erreur : ' . $e->getMessage();
            $msgType = 'err';
        }
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM avocat_documents WHERE id=?")
           ->execute([intval($_POST['id'] ?? 0)]);
        $msg = 'Document supprimé.';
        $msgType = 'ok';
    }
}

// ── REQUÊTES ──────────────────────────────────────────────────────────────────
$filterType = $_GET['type'] ?? 'all';
$search     = trim($_GET['q'] ?? '');

$q      = "SELECT d.*, p.firstname, p.lastname, u.username AS author, ad.numero AS dos_num
           FROM avocat_documents d
           LEFT JOIN avocat_clients  ac ON ac.id = d.client_id
           LEFT JOIN profiles        p  ON p.id  = ac.profile_id
           LEFT JOIN users           u  ON u.id  = d.created_by
           LEFT JOIN avocat_dossiers ad ON ad.id = d.dossier_id
           WHERE 1=1";
$params = [];
if ($filterType !== 'all') { $q .= " AND d.type=?";       $params[] = $filterType; }
if ($search)               { $q .= " AND d.titre LIKE ?"; $params[] = "%$search%"; }
$q .= " ORDER BY d.created_at DESC LIMIT 150";

$stmt = $db->prepare($q);
$stmt->execute($params);
$docs = $stmt->fetchAll();

$TYPES = [
    'preuve'     => 'Preuve',
    'contrat'    => 'Contrat',
    'temoignage' => 'Témoignage',
    'jugement'   => 'Jugement',
    'plaidoirie' => 'Plaidoirie',
    'autre'      => 'Autre',
];
$TYPE_COLORS = [
    'preuve'     => 'bg-red',
    'contrat'    => 'bg-blue',
    'temoignage' => 'bg-yellow',
    'jugement'   => 'bg-gold',
    'plaidoirie' => 'bg-purple',
    'autre'      => 'bg-gray',
];

$allClients  = $db->query("
    SELECT ac.id, p.firstname, p.lastname
    FROM avocat_clients ac JOIN profiles p ON p.id=ac.profile_id
    WHERE ac.statut='actif' ORDER BY p.lastname
")->fetchAll();

$allDossiers = $db->query("
    SELECT id, numero, titre FROM avocat_dossiers
    ORDER BY updated_at DESC LIMIT 50
")->fetchAll();

// Vue détail d'un document
$viewDoc = null;
if (isset($_GET['id'])) {
    $stmt = $db->prepare("
        SELECT d.*, p.firstname, p.lastname, u.username AS author, ad.numero AS dos_num
        FROM avocat_documents d
        LEFT JOIN avocat_clients  ac ON ac.id = d.client_id
        LEFT JOIN profiles        p  ON p.id  = ac.profile_id
        LEFT JOIN users           u  ON u.id  = d.created_by
        LEFT JOIN avocat_dossiers ad ON ad.id = d.dossier_id
        WHERE d.id = ?
    ");
    $stmt->execute([intval($_GET['id'])]);
    $viewDoc = $stmt->fetch();
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='ok' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php if ($viewDoc): ?>
<!-- ── VUE DÉTAIL ──────────────────────────────────────────────────── -->
<div style="margin-bottom:1.1rem;display:flex;gap:.75rem">
    <a href="documents.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Documents</a>
    <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ce document définitivement ?')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id"     value="<?php echo $viewDoc['id']; ?>">
        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Supprimer</button>
    </form>
</div>

<?php
$tc = $TYPE_COLORS[$viewDoc['type']] ?? 'bg-gray';
$tl = $TYPES[$viewDoc['type']]       ?? $viewDoc['type'];
?>
<div class="card" style="<?php echo $viewDoc['confidentiel'] ? 'border-color:rgba(217,119,6,.4)' : ''; ?>">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <span class="badge <?php echo $tc; ?>"><?php echo $tl; ?></span>
            <?php if ($viewDoc['confidentiel']): ?>
            <span class="badge bg-gold"><i class="fas fa-lock"></i> Confidentiel</span>
            <?php endif; ?>
            <?php if (!empty($viewDoc['dos_num'])): ?>
            <span class="badge bg-gray"><i class="fas fa-folder"></i> <?php echo htmlspecialchars($viewDoc['dos_num']); ?></span>
            <?php endif; ?>
        </div>
        <div style="font-size:.72rem;color:var(--txt-m)">
            Par <strong><?php echo htmlspecialchars($viewDoc['author'] ?? '—'); ?></strong>
            · <?php echo date('d/m/Y H:i', strtotime($viewDoc['created_at'])); ?>
        </div>
    </div>

    <div style="font-family:'Playfair Display',serif;font-size:1.15rem;font-weight:700;color:#f5edd6;margin-bottom:.75rem">
        <?php echo htmlspecialchars($viewDoc['titre']); ?>
    </div>

    <?php if (!empty($viewDoc['firstname'])): ?>
    <div style="font-size:.78rem;color:var(--txt-m);margin-bottom:1rem">
        <i class="fas fa-user" style="color:var(--gold)"></i>
        <?php echo htmlspecialchars($viewDoc['firstname'] . ' ' . $viewDoc['lastname']); ?>
    </div>
    <?php endif; ?>

    <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:10px;padding:1.25rem;font-size:.875rem;line-height:1.85;color:var(--txt-s);white-space:pre-wrap">
        <?php
        $c = $viewDoc['contenu'] ?? '';
        echo empty($c) ? '<em style="color:var(--txt-m)">Aucun contenu.</em>' : htmlspecialchars($c);
        ?>
    </div>
</div>

<?php else: ?>
<!-- ── LISTE ────────────────────────────────────────────────────────── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.6rem;flex:1;flex-wrap:wrap">
        <input type="text" name="q" class="form-input"
               placeholder="Titre du document…"
               value="<?php echo htmlspecialchars($search); ?>"
               style="flex:1;min-width:160px">
        <select name="type" class="form-select" style="width:150px" onchange="this.form.submit()">
            <option value="all">Tous types</option>
            <?php foreach ($TYPES as $k => $v): ?>
            <option value="<?php echo $k; ?>" <?php echo $filterType===$k ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <a href="documents.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
    </form>
    <button onclick="Cabinet.openModal('newDocModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Nouveau document
    </button>
</div>

<div class="tbl-wrap">
    <table class="tbl">
        <thead>
            <tr><th>Type</th><th>Titre</th><th>Client</th><th>Dossier</th><th>Auteur</th><th>Date</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (empty($docs)): ?>
        <tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucun document</td></tr>
        <?php else: ?>
        <?php foreach ($docs as $d):
            $tc = $TYPE_COLORS[$d['type']] ?? 'bg-gray';
            $tl = $TYPES[$d['type']]       ?? $d['type'];
        ?>
        <tr style="cursor:pointer" onclick="location.href='documents.php?id=<?php echo $d['id']; ?>'">
            <td>
                <span class="badge <?php echo $tc; ?>"><?php echo $tl; ?></span>
                <?php if ($d['confidentiel']): ?>
                <span class="badge bg-gold" style="margin-left:.3rem;font-size:.6rem"><i class="fas fa-lock"></i></span>
                <?php endif; ?>
            </td>
            <td style="font-weight:600"><?php echo htmlspecialchars($d['titre']); ?></td>
            <td style="font-size:.78rem">
                <?php echo htmlspecialchars(trim(($d['firstname'] ?? '') . ' ' . ($d['lastname'] ?? ''))); ?>
            </td>
            <td style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($d['dos_num'] ?? '—'); ?></td>
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($d['author'] ?? '—'); ?></td>
            <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/y', strtotime($d['created_at'])); ?></td>
            <td>
                <a href="documents.php?id=<?php echo $d['id']; ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-eye"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL NOUVEAU DOCUMENT (manuel) -->
<div id="newDocModal" class="modal-ov hidden">
    <div class="modal modal-lg">
        <div class="modal-hd">
            <h2><i class="fas fa-paperclip"></i> Nouveau document</h2>
            <button class="modal-cl" onclick="Cabinet.closeModal('newDocModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Type *</label>
                        <select name="type" class="form-select" required>
                            <?php foreach ($TYPES as $k => $v): ?>
                            <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Client lié</label>
                        <select name="client_id" class="form-select">
                            <option value="">— Aucun —</option>
                            <?php foreach ($allClients as $c): ?>
                            <option value="<?php echo $c['id']; ?>">
                                <?php echo htmlspecialchars($c['firstname'] . ' ' . $c['lastname']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Titre *</label>
                        <input type="text" name="titre" class="form-input" required placeholder="Titre du document…">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Dossier lié</label>
                        <select name="dossier_id" class="form-select">
                            <option value="">— Aucun —</option>
                            <?php foreach ($allDossiers as $d): ?>
                            <option value="<?php echo $d['id']; ?>">
                                <?php echo htmlspecialchars($d['numero'] . ' — ' . mb_substr($d['titre'], 0, 45)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Contenu *</label>
                        <textarea name="contenu" class="form-textarea" required
                                  style="min-height:220px"
                                  placeholder="Rédigez ici le contenu du document…"></textarea>
                    </div>
                    <div class="form-group form-full">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;color:var(--txt-s);font-size:.875rem">
                            <input type="checkbox" name="confidentiel" value="1" style="accent-color:var(--gold)">
                            Document confidentiel
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="Cabinet.closeModal('newDocModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>