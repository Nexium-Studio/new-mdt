<?php
/**
 * POST handlers for division panel.
 * Used by panel.php BEFORE rendering any view.
 *
 * Requires (from caller) : $division, $canManage, $currentUserId, $panelKey.
 */
if (!isset($division, $canManage, $currentUserId, $panelKey)) exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

$db = div_db();
$divId  = (int)$division['id'];
$action = $_POST['action'] ?? '';

// Flash helper
$flash = function(string $type, string $msg) {
    $_SESSION['div_flash'] = ['type' => $type, 'msg' => $msg];
};

$redirectBack = function(string $view = '') use ($panelKey) {
    $url = SITE_URL . '/pages/divisions/panel.php?key=' . urlencode($panelKey);
    if ($view !== '') $url .= '&view=' . urlencode($view);
    redirect($url);
};

try {
    /* ───────── MEMBRES ───────── */
    if ($action === 'add_member') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $userId = (int)($_POST['user_id'] ?? 0);
        $gradeId = (int)($_POST['division_grade_id'] ?? 0) ?: null;
        $role    = trim((string)($_POST['role'] ?? ''));
        if ($userId <= 0) throw new RuntimeException('Utilisateur invalide');

        $db->prepare("INSERT IGNORE INTO faction_division_members
                      (division_id, user_id, division_grade_id, role, joined_date)
                      VALUES (?, ?, ?, ?, CURDATE())")
           ->execute([$divId, $userId, $gradeId, $role]);

        $uname = (string)($db->query("SELECT username FROM users WHERE id=".(int)$userId)->fetchColumn() ?: '#'.$userId);
        div_log($divId, $currentUserId, 'add_member', $uname);
        $flash('success', 'Membre ajouté à la division.');
        $redirectBack('effectifs');
    }

    if ($action === 'remove_member') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $mid = (int)($_POST['membership_id'] ?? 0);
        if ($mid <= 0) throw new RuntimeException('ID invalide');

        $st = $db->prepare("SELECT m.user_id, u.username FROM faction_division_members m
                            JOIN users u ON u.id=m.user_id WHERE m.id=? AND m.division_id=?");
        $st->execute([$mid, $divId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $db->prepare("DELETE FROM faction_division_members WHERE id=? AND division_id=?")
           ->execute([$mid, $divId]);

        if ($row) div_log($divId, $currentUserId, 'remove_member', $row['username']);
        $flash('success', 'Membre retiré de la division.');
        $redirectBack('effectifs');
    }

    if ($action === 'update_member') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $mid     = (int)($_POST['membership_id'] ?? 0);
        $gradeId = (int)($_POST['division_grade_id'] ?? 0) ?: null;
        $role    = trim((string)($_POST['role'] ?? ''));
        if ($mid <= 0) throw new RuntimeException('ID invalide');

        $db->prepare("UPDATE faction_division_members
                      SET division_grade_id = ?, role = ?
                      WHERE id = ? AND division_id = ?")
           ->execute([$gradeId, $role, $mid, $divId]);

        div_log($divId, $currentUserId, 'update_member', 'membership #'.$mid);
        $flash('success', 'Membre mis à jour.');
        $redirectBack('effectifs');
    }

    /* ───────── GRADES ───────── */
    if ($action === 'add_grade') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $name       = trim((string)($_POST['name'] ?? ''));
        $rankOrder  = (int)($_POST['rank_order'] ?? 99);
        $canMng     = !empty($_POST['can_manage']) ? 1 : 0;
        if ($name === '') throw new RuntimeException('Nom requis');

        $db->prepare("INSERT INTO faction_division_grades (division_id, name, rank_order, can_manage) VALUES (?,?,?,?)")
           ->execute([$divId, $name, $rankOrder, $canMng]);
        div_log($divId, $currentUserId, 'add_grade', $name);
        $flash('success', 'Grade créé.');
        $redirectBack('grades');
    }

    if ($action === 'update_grade') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $gid       = (int)($_POST['grade_id'] ?? 0);
        $name      = trim((string)($_POST['name'] ?? ''));
        $rankOrder = (int)($_POST['rank_order'] ?? 99);
        $canMng    = !empty($_POST['can_manage']) ? 1 : 0;
        if ($gid <= 0 || $name === '') throw new RuntimeException('Paramètres invalides');

        $db->prepare("UPDATE faction_division_grades
                      SET name=?, rank_order=?, can_manage=?
                      WHERE id=? AND division_id=?")
           ->execute([$name, $rankOrder, $canMng, $gid, $divId]);

        div_log($divId, $currentUserId, 'update_grade', $name);
        $flash('success', 'Grade mis à jour.');
        $redirectBack('grades');
    }

    if ($action === 'delete_grade') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $gid = (int)($_POST['grade_id'] ?? 0);
        if ($gid <= 0) throw new RuntimeException('ID invalide');

        $st = $db->prepare("SELECT name FROM faction_division_grades WHERE id=? AND division_id=?");
        $st->execute([$gid, $divId]);
        $gname = $st->fetchColumn();

        $db->prepare("DELETE FROM faction_division_grades WHERE id=? AND division_id=?")
           ->execute([$gid, $divId]);

        if ($gname) div_log($divId, $currentUserId, 'delete_grade', (string)$gname);
        $flash('success', 'Grade supprimé.');
        $redirectBack('grades');
    }

    /* ───────── ANNONCES ───────── */
    if ($action === 'add_announcement') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $title   = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $pinned  = !empty($_POST['pinned']) ? 1 : 0;
        if ($title === '' || $content === '') throw new RuntimeException('Titre et contenu requis');

        $db->prepare("INSERT INTO faction_division_announcements
                      (division_id, author_id, title, content, pinned)
                      VALUES (?,?,?,?,?)")
           ->execute([$divId, $currentUserId, $title, $content, $pinned]);

        div_log($divId, $currentUserId, 'add_announcement', $title);
        $flash('success', 'Annonce publiée.');
        $redirectBack('annonces');
    }

    if ($action === 'delete_announcement') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $aid = (int)($_POST['announcement_id'] ?? 0);
        if ($aid <= 0) throw new RuntimeException('ID invalide');

        $db->prepare("DELETE FROM faction_division_announcements WHERE id=? AND division_id=?")
           ->execute([$aid, $divId]);

        div_log($divId, $currentUserId, 'delete_announcement', '#'.$aid);
        $flash('success', 'Annonce supprimée.');
        $redirectBack('annonces');
    }

    /* ───────── DOCUMENTS ───────── */
    if ($action === 'add_document') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $url   = trim((string)($_POST['url'] ?? ''));
        if ($title === '') throw new RuntimeException('Titre requis');

        $db->prepare("INSERT INTO faction_division_documents
                      (division_id, author_id, title, description, url)
                      VALUES (?,?,?,?,?)")
           ->execute([$divId, $currentUserId, $title, $desc, $url]);

        div_log($divId, $currentUserId, 'add_document', $title);
        $flash('success', 'Document ajouté.');
        $redirectBack('documents');
    }

    if ($action === 'delete_document') {
        if (!$canManage) throw new RuntimeException('Non autorisé');
        $did = (int)($_POST['document_id'] ?? 0);
        if ($did <= 0) throw new RuntimeException('ID invalide');

        $db->prepare("DELETE FROM faction_division_documents WHERE id=? AND division_id=?")
           ->execute([$did, $divId]);

        div_log($divId, $currentUserId, 'delete_document', '#'.$did);
        $flash('success', 'Document supprimé.');
        $redirectBack('documents');
    }

    /* ───────── RAPPORTS DE DIVISION ───────── */
    if ($action === 'report_submit' || $action === 'report_update') {
        if (!div_has_capability($division, 'create_own_reports')) {
            throw new RuntimeException('Rapports non disponibles pour cette division');
        }

        $type     = trim((string)($_POST['type'] ?? ''));
        $title    = trim((string)($_POST['title'] ?? ''));
        $status   = trim((string)($_POST['status'] ?? 'submitted'));
        $priority = trim((string)($_POST['priority'] ?? 'normal'));
        $location = trim((string)($_POST['location'] ?? ''));
        $incidentAt = !empty($_POST['incident_at']) ? $_POST['incident_at'] : null;
        if ($type  === '') throw new RuntimeException('Type obligatoire');
        if ($title === '') throw new RuntimeException('Titre obligatoire');

        $enc = function($k){ return !empty($_POST[$k]) ? json_encode($_POST[$k]) : null; };
        $tags      = $enc('tags');
        $officers  = $enc('officers');
        $civilians = $enc('civilians');
        $witnesses = $enc('witnesses');
        $suspects  = $enc('suspects');
        $vehicles  = $enc('vehicles');

        $descRich      = (string)($_POST['description_rich'] ?? '');
        $descPlain     = strip_tags($descRich);
        $internalNotes = trim((string)($_POST['internal_notes'] ?? ''));

        $evidenceUrls = [];
        if (!empty($_POST['evidence_urls'])) {
            foreach (preg_split('/[\r\n]+/', trim((string)$_POST['evidence_urls'])) as $line) {
                $u = trim($line);
                if ($u !== '') $evidenceUrls[] = $u;
            }
        }
        $evidence = $evidenceUrls ? json_encode($evidenceUrls) : null;

        if ($action === 'report_submit') {
            $stmt = $db->prepare("INSERT INTO division_reports
                (division_id, type, title, status, priority, location, incident_at,
                 tags, evidence, officers, civilians, witnesses, suspects, vehicles,
                 description, description_rich, internal_notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$divId, $type, $title, $status, $priority, $location ?: null, $incidentAt,
                $tags, $evidence, $officers, $civilians, $witnesses, $suspects, $vehicles,
                $descPlain, $descRich, $internalNotes ?: null, $currentUserId]);
            $newId = (int)$db->lastInsertId();
            div_log($divId, $currentUserId, 'report_submit', '#'.$newId, $type.' — '.$title);
            $flash('success', 'Rapport #'.str_pad((string)$newId,6,'0',STR_PAD_LEFT).' créé.');
            $redirectBack('reports');
        } else { // report_update
            $rid = (int)($_POST['report_id'] ?? 0);
            $owner = $db->prepare("SELECT created_by FROM division_reports WHERE id=? AND division_id=?");
            $owner->execute([$rid, $divId]);
            $ownerRow = $owner->fetch(PDO::FETCH_ASSOC);
            if (!$ownerRow) throw new RuntimeException('Rapport introuvable');
            $isAuthor = (int)$ownerRow['created_by'] === $currentUserId;
            if (!$isAuthor && !$canManage) throw new RuntimeException('Non autorisé à modifier');

            $db->prepare("UPDATE division_reports SET
                    type=?, title=?, status=?, priority=?, location=?, incident_at=?,
                    tags=?, evidence=?, officers=?, civilians=?, witnesses=?, suspects=?, vehicles=?,
                    description=?, description_rich=?, internal_notes=?
                WHERE id=? AND division_id=?")
               ->execute([$type, $title, $status, $priority, $location ?: null, $incidentAt,
                    $tags, $evidence, $officers, $civilians, $witnesses, $suspects, $vehicles,
                    $descPlain, $descRich, $internalNotes ?: null, $rid, $divId]);

            div_log($divId, $currentUserId, 'report_update', '#'.$rid, $title);
            $flash('success', 'Rapport mis à jour.');
            redirect(SITE_URL.'/pages/divisions/panel.php?key='.urlencode($panelKey).'&view=reports&id='.$rid);
        }
    }

    if ($action === 'report_delete') {
        if (!div_has_capability($division, 'create_own_reports')) {
            throw new RuntimeException('Rapports non disponibles');
        }
        $rid = (int)($_POST['report_id'] ?? 0);
        if ($rid <= 0) throw new RuntimeException('ID invalide');

        $r = $db->prepare("SELECT created_by, title FROM division_reports WHERE id=? AND division_id=?");
        $r->execute([$rid, $divId]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Rapport introuvable');
        $isAuthor = (int)$row['created_by'] === $currentUserId;
        if (!$isAuthor && !$canManage) throw new RuntimeException('Non autorisé');

        $db->prepare("DELETE FROM division_reports WHERE id=? AND division_id=?")
           ->execute([$rid, $divId]);

        div_log($divId, $currentUserId, 'report_delete', '#'.$rid, (string)$row['title']);
        $flash('success', 'Rapport supprimé.');
        $redirectBack('reports');
    }

} catch (Throwable $e) {
    $flash('error', $e->getMessage());
    $redirectBack($_POST['view_back'] ?? '');
}
