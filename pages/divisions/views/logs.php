<?php
/** Journal d'activité de la division. */
if (!isset($division)) exit;

$divId = (int)$division['id'];
$db    = div_db();

// Pagination simple
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$offset  = ($page - 1) * $perPage;

$logs = [];
$total = 0;
try {
    $total = (int)$db->query("SELECT COUNT(*) FROM faction_division_logs WHERE division_id=".$divId)->fetchColumn();
    $stmt = $db->prepare("
        SELECT l.*, u.username
          FROM faction_division_logs l
     LEFT JOIN users u ON u.id = l.user_id
         WHERE l.division_id = ?
         ORDER BY l.created_at DESC
         LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute([$divId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$totalPages = max(1, (int)ceil($total / $perPage));

$actionLabels = [
    'add_member'          => ['Membre ajouté',      'fa-user-plus',   'ok'],
    'remove_member'       => ['Membre retiré',      'fa-user-minus',  'danger'],
    'update_member'       => ['Membre modifié',     'fa-user-pen',    'accent'],
    'add_grade'           => ['Grade créé',         'fa-medal',       'ok'],
    'update_grade'        => ['Grade modifié',      'fa-pen',         'accent'],
    'delete_grade'        => ['Grade supprimé',     'fa-trash',       'danger'],
    'add_announcement'    => ['Annonce publiée',    'fa-bullhorn',    'accent'],
    'delete_announcement' => ['Annonce supprimée',  'fa-trash',       'danger'],
    'add_document'        => ['Document ajouté',    'fa-folder-plus', 'ok'],
    'delete_document'     => ['Document supprimé',  'fa-trash',       'danger'],
];
?>

<div class="d-card">
    <div class="d-card-hd">
        <div class="d-card-tt"><i class="fas fa-clipboard-list"></i> Journal d'activité (<?php echo $total; ?>)</div>
        <span class="d-card-sub">Toutes les actions effectuées dans cette division.</span>
    </div>

    <?php if (empty($logs)): ?>
        <div class="empty-state"><i class="fas fa-clipboard-list"></i>Aucune activité enregistrée.</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th style="width:150px;">Date</th>
                <th style="width:140px;">Auteur</th>
                <th style="width:180px;">Action</th>
                <th>Cible</th>
                <th>Détails</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $l):
            $info = $actionLabels[$l['action']] ?? [$l['action'], 'fa-circle-info', ''];
        ?>
            <tr>
                <td style="color:var(--txt-s);font-family:'IBM Plex Mono',monospace;font-size:.75rem;">
                    <?php echo date('d/m/Y H:i', strtotime($l['created_at'])); ?>
                </td>
                <td><?php echo htmlspecialchars($l['username'] ?? 'système'); ?></td>
                <td>
                    <span class="chip <?php echo $info[2]; ?>">
                        <i class="fas <?php echo $info[1]; ?>"></i> <?php echo $info[0]; ?>
                    </span>
                </td>
                <td style="color:var(--txt);"><?php echo htmlspecialchars($l['target'] ?? '—'); ?></td>
                <td style="color:var(--txt-s);font-size:.8rem;"><?php echo htmlspecialchars($l['meta'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:.3rem;align-items:center;justify-content:center;margin-top:1rem;">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?key=<?php echo urlencode($panelKey); ?>&view=logs&page=<?php echo $p; ?>"
               class="btn btn-sm <?php echo $p === $page ? 'btn-primary' : ''; ?>">
                <?php echo $p; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
