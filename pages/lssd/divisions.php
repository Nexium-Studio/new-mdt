<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/division_access.php';
requireLogin();
require_once __DIR__ . '/lssd_log.php';

if (!in_array($_SESSION['user_faction'] ?? '', ['LSPD','LSSD','DOJ'])
    && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Grades & Divisions';
$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);
$msg = ''; $msgType = '';

// ── Niveau hiérarchique depuis faction_grades (même logique que effectifs.php) ─
try { $__divGrS=$db->prepare('SELECT faction_rank FROM users WHERE id=? LIMIT 1');$__divGrS->execute([(int)($_SESSION['user_id']??0)]);$__divGrRow=$__divGrS->fetch();$myRankName = $__divGrRow['faction_rank'] ?? ''; } catch(Exception $e){ $myRankName = $_SESSION['user_faction_rank'] ?? ''; }
$isStaff = hasRole(['*','Administrateur','Développeur','Staff']);

// Récupérer l'id de la faction LSSD (on en aura besoin partout)
$lssdFactionId = 0;
try {
    $st = $db->prepare("SELECT id FROM factions WHERE faction_key = 'LSSD' LIMIT 1");
    $st->execute();
    $lssdFactionId = (int)$st->fetchColumn();
} catch (Exception $e) {}

// Fallback hardcodé si la DB est inaccessible
$__fallbackRanks = [
    'Sheriff','Undersheriff','Assistant Sheriff','Division Chief',
    'Area Commander','Captain','Lieutenant','Sergeant',
    'Deputy Sheriff','Deputy Sheriff Trainee',
];

$__divRanks = [];
try {
    $__drStmt = $db->prepare(
        "SELECT fg.name FROM faction_grades fg
         JOIN factions f ON f.id = fg.faction_id
         WHERE f.faction_key = 'LSSD' ORDER BY fg.rank_order ASC"
    );
    $__drStmt->execute();
    $__divRanks = array_column($__drStmt->fetchAll(), 'name');
} catch (Exception $e) {}
if (empty($__divRanks)) $__divRanks = $__fallbackRanks;

$__divRLevel = [];
foreach ($__divRanks as $__i => $__rn) {
    $__divRLevel[$__rn] = count($__divRanks) - $__i;
}
$__divMaxLvl = count($__divRanks);
$myLevel = $__divRLevel[$myRankName] ?? 0;
$__divThreshold = $__divMaxLvl > 0 ? (int)ceil($__divMaxLvl * 0.40) : 4;
$canManage = $isStaff || ($myLevel >= $__divThreshold);

/* ═══════════════════════════════════════════════════════════════════════════
 * ACTIONS POST
 * Les actions "grade" continuent d'écrire dans lssd_ranks + faction_grades.
 * Les actions "division" passent désormais sur faction_divisions
 * et faction_division_members (nouvelle infrastructure).
 * ═════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    // ─── Modifier le salaire / permissions d'un grade ───────────────────────
    if ($action === 'update_salary') {
        $rank_id = intval($_POST['rank_id'] ?? 0);
        // Salaire désormais saisi en HORAIRE ($/h). On dérive un mensuel
        // indicatif (160h) uniquement pour compat avec l'ancien champ base_salary.
        $hourly  = floatval($_POST['salary_hourly'] ?? 0);
        $salary  = round($hourly * 160, 2);
        $canRec  = isset($_POST['can_recruit'])         ? 1 : 0;
        $canSanc = isset($_POST['can_give_sanctions'])  ? 1 : 0;
        $canDiv  = isset($_POST['can_manage_divisions']) ? 1 : 0;
        $canFin  = isset($_POST['can_manage_finances'])  ? 1 : 0;
        try {
            $db->prepare("UPDATE lssd_ranks
                          SET base_salary=?, salary_hourly=?,
                              can_recruit=?, can_give_sanctions=?, can_manage_divisions=?
                          WHERE id=?")
               ->execute([$salary, $hourly, $canRec, $canSanc, $canDiv, $rank_id]);
            try {
                $db->prepare("UPDATE faction_grades SET can_manage_finances=?, can_recruit=?
                              WHERE faction_id=(SELECT id FROM factions WHERE faction_key='LSSD' LIMIT 1)
                                AND name=(SELECT rank_name FROM lssd_ranks WHERE id=? LIMIT 1)")
                   ->execute([$canFin, $canRec, $rank_id]);
            } catch(Exception $e2){}
            $msg = 'Grade mis à jour.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ─── Créer une division ──────────────────────────────────────────────────
    if ($action === 'add_division') {
        try {
            $name = trim($_POST['name']);
            $abbr = strtoupper(trim($_POST['abbr']));
            $desc = trim($_POST['desc'] ?? '');
            $minR = trim($_POST['min_rank'] ?? '');
            $color= trim($_POST['color'] ?? '#10b981');
            $icon = trim($_POST['icon'] ?? 'layer-group');

            if ($name === '' || $abbr === '') throw new Exception('Nom et abréviation requis');
            if ($lssdFactionId <= 0) throw new Exception('Faction LSSD introuvable');

            // Génère une clé stable : DIV_LSSD_<ABBR> (suffixée si collision)
            $base = 'DIV_LSSD_' . preg_replace('/[^A-Z0-9_]/', '', $abbr);
            $panelKey = $base;
            $i = 0;
            while (true) {
                $st = $db->prepare("SELECT COUNT(*) FROM faction_divisions WHERE panel_key = ?");
                $st->execute([$panelKey]);
                if ((int)$st->fetchColumn() === 0) break;
                $i++; $panelKey = $base . '_' . $i;
            }

            $divKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($abbr));
            $targetUrl = '/pages/divisions/panel.php?key=' . $panelKey;

            // Description enrichie avec min_rank pour compat legacy
            $descFull = $desc;
            if ($minR !== '') $descFull .= ($descFull ? "\n\n" : '') . "Grade minimum : {$minR}";

            $db->prepare("INSERT INTO faction_divisions
                          (faction_id, division_key, panel_key, name, abbreviation,
                           description, icon, color, target_url, sort_order,
                           is_active, show_on_dashboard)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 99, 1, 1)")
               ->execute([$lssdFactionId, $divKey, $panelKey, $name, $abbr, $descFull, $icon, $color, $targetUrl]);

            // Enregistre le panel_key dans admin_panels (pour activation/désactivation staff)
            try {
                $db->prepare("INSERT IGNORE INTO admin_panels (panel_key, is_active, updated_at)
                              VALUES (?, 1, NOW())")->execute([$panelKey]);
            } catch (Exception $e2) {}

            $msg = 'Division créée. Un panel dédié est accessible.'; $msgType = 'success';
            lssd_log($db,$uid,'add_division','faction_divisions',(int)$db->lastInsertId(),$name);
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ─── Modifier une division ──────────────────────────────────────────────
    if ($action === 'edit_division') {
        $div_id = intval($_POST['div_id'] ?? 0);
        try {
            $name = trim($_POST['name']);
            $abbr = strtoupper(trim($_POST['abbr']));
            $desc = trim($_POST['desc'] ?? '');
            $minR = trim($_POST['min_rank'] ?? '');
            $color= trim($_POST['color'] ?? '#10b981');
            $icon = trim($_POST['icon'] ?? 'layer-group');

            $descFull = $desc;
            if ($minR !== '') $descFull .= ($descFull ? "\n\n" : '') . "Grade minimum : {$minR}";

            $db->prepare("UPDATE faction_divisions
                          SET name=?, abbreviation=?, description=?, icon=?, color=?
                          WHERE id=? AND faction_id=?")
               ->execute([$name, $abbr, $descFull, $icon, $color, $div_id, $lssdFactionId]);

            $msg = 'Division mise à jour.'; $msgType = 'success';
            lssd_log($db,$uid,'edit_division','faction_divisions',$div_id,$name);
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ─── Supprimer une division ─────────────────────────────────────────────
    if ($action === 'delete_division') {
        $div_id = intval($_POST['div_id'] ?? 0);
        try {
            // CASCADE supprime automatiquement les membres + grades + annonces + logs
            $db->prepare("DELETE FROM faction_divisions WHERE id=? AND faction_id=?")
               ->execute([$div_id, $lssdFactionId]);

            $msg = 'Division supprimée.'; $msgType = 'success';
            lssd_log($db,$uid,'delete_division','faction_divisions',$div_id,'Division #'.$div_id);
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ─── Ajouter un membre à une division ───────────────────────────────────
    if ($action === 'add_member') {
        try {
            $divId  = intval($_POST['div_id']);
            $userId = intval($_POST['user_id']);
            $role   = trim($_POST['role'] ?? '');
            $gradeId = (int)($_POST['division_grade_id'] ?? 0) ?: null;
            if ($divId <= 0 || $userId <= 0) throw new Exception('Paramètres invalides');

            $db->prepare("INSERT IGNORE INTO faction_division_members
                          (division_id, user_id, division_grade_id, role, joined_date)
                          VALUES (?, ?, ?, ?, CURDATE())")
               ->execute([$divId, $userId, $gradeId, $role]);

            $msg = 'Membre ajouté.'; $msgType = 'success';
            lssd_log($db,$uid,'add_member','faction_division_members',$divId,'Div #'.$divId,'User #'.$userId);
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ─── Retirer un membre ──────────────────────────────────────────────────
    if ($action === 'remove_member') {
        try {
            $mid = intval($_POST['id']);
            $db->prepare("DELETE FROM faction_division_members WHERE id=?")->execute([$mid]);
            $msg = 'Membre retiré.'; $msgType = 'success';
            lssd_log($db,$uid,'remove_member','faction_division_members',null,'Membre #'.$mid);
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * DONNÉES — GRADES LSSD
 * (inchangé, continue de lire faction_grades + lssd_ranks)
 * ═════════════════════════════════════════════════════════════════════════ */

$lssdGrades  = [];
$queryError  = '';

try {
    $stmt = $db->prepare("
        SELECT fg.* FROM faction_grades fg
        INNER JOIN factions f ON f.id = fg.faction_id
        WHERE f.faction_key = ? ORDER BY fg.rank_order ASC
    ");
    $stmt->execute(['LSSD']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $lssdGrades[] = [
            'fg_id' => $r['id'], 'rank_name' => $r['name'],
            'rank_order' => (int)($r['rank_order'] ?? 99),
            'can_recruit' => (bool)($r['can_recruit'] ?? 0),
            'can_manage_finances' => (bool)($r['can_manage_finances'] ?? 0),
            'is_owner' => (bool)($r['is_owner'] ?? 0),
            'is_ceo' => (bool)($r['is_ceo'] ?? 0),
            'member_count' => 0,
            'lr_id' => null, 'hierarchy_level' => null,
            'base_salary' => 0, 'salary_hourly' => 0,
            'can_manage_divisions' => false, 'can_give_sanctions' => false,
        ];
    }
} catch (Exception $e) { $queryError = $e->getMessage(); }

if (empty($lssdGrades)) {
    try {
        $rows = $db->query("SELECT id, rank_name, hierarchy_level AS rank_order, base_salary, salary_hourly,
                            can_recruit, can_manage_divisions, can_give_sanctions
                            FROM lssd_ranks ORDER BY hierarchy_level ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $lssdGrades[] = [
                'fg_id'=>null, 'rank_name'=>$r['rank_name'], 'rank_order'=>(int)$r['rank_order'],
                'can_recruit'=>(bool)($r['can_recruit']??0), 'can_manage_finances'=>false,
                'is_owner'=>false,'is_ceo'=>false,'member_count'=>0,
                'lr_id'=>$r['id'],'hierarchy_level'=>(int)$r['rank_order'],
                'base_salary'=>floatval($r['base_salary']??0),'salary_hourly'=>floatval($r['salary_hourly']??0),
                'can_manage_divisions'=>(bool)($r['can_manage_divisions']??0),
                'can_give_sanctions'=>(bool)($r['can_give_sanctions']??0),
            ];
        }
    } catch (Exception $e2) {}
}

$salaryMap = [];
try {
    $rows = $db->query("SELECT id, rank_name, hierarchy_level, base_salary, salary_hourly,
                        can_manage_divisions, can_give_sanctions, can_recruit
                        FROM lssd_ranks ORDER BY hierarchy_level ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $salaryMap[$r['rank_name']] = $r;
} catch (Exception $e) {}

if (!empty($lssdGrades)) {
    try {
        $__syncStmt = $db->prepare(
            "INSERT IGNORE INTO lssd_ranks
                (rank_name, hierarchy_level, base_salary, salary_hourly,
                 can_recruit, can_manage_divisions, can_give_sanctions)
             VALUES (?, ?, 0, 0, 0, 0, 0)"
        );
        $__syncDone = false;
        foreach ($lssdGrades as $__g) {
            if (!isset($salaryMap[$__g['rank_name']])) {
                $__syncStmt->execute([$__g['rank_name'], $__g['rank_order'] ?? 99]);
                $__syncDone = true;
            }
        }
        if ($__syncDone) {
            $salaryMap = [];
            $__rows2 = $db->query("SELECT id, rank_name, hierarchy_level, base_salary, salary_hourly,
                                   can_manage_divisions, can_give_sanctions, can_recruit
                                   FROM lssd_ranks ORDER BY hierarchy_level ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($__rows2 as $__r2) $salaryMap[$__r2['rank_name']] = $__r2;
        }
    } catch (Exception $e) { error_log('divisions.php auto-sync lssd_ranks: ' . $e->getMessage()); }
}

$memberCounts = [];
try {
    $rows = $db->query("SELECT COALESCE(NULLIF(faction_rank,''), faction_grade) AS grade_name, COUNT(*) AS cnt
                        FROM users WHERE faction = 'LSSD' AND (faction_rank != '' OR faction_grade != '')
                        GROUP BY grade_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) if ($r['grade_name']) $memberCounts[$r['grade_name']] = (int)$r['cnt'];
} catch (Exception $e) {}

foreach ($lssdGrades as &$g) {
    if ($g['lr_id'] === null && isset($salaryMap[$g['rank_name']])) {
        $lr = $salaryMap[$g['rank_name']];
        $g['lr_id']=$lr['id']; $g['hierarchy_level']=(int)$lr['hierarchy_level'];
        $g['base_salary']=floatval($lr['base_salary']); $g['salary_hourly']=floatval($lr['salary_hourly']);
        $g['can_manage_divisions']=(bool)$lr['can_manage_divisions'];
        $g['can_give_sanctions']=(bool)$lr['can_give_sanctions'];
        if (!$g['can_recruit']) $g['can_recruit'] = (bool)$lr['can_recruit'];
    }
    $g['member_count'] = $memberCounts[$g['rank_name']] ?? 0;
}
unset($g);

/* ═══════════════════════════════════════════════════════════════════════════
 * DONNÉES — DIVISIONS (nouvelle source : faction_divisions)
 * ═════════════════════════════════════════════════════════════════════════ */
$divisions = [];
try {
    if ($lssdFactionId > 0) {
        $divStmt = $db->prepare("
            SELECT fd.*,
                   (SELECT COUNT(*) FROM faction_division_members WHERE division_id = fd.id) AS nb_members
              FROM faction_divisions fd
             WHERE fd.faction_id = ? AND fd.is_active = 1
             ORDER BY fd.sort_order ASC, fd.name ASC
        ");
        $divStmt->execute([$lssdFactionId]);
        $divRes = $divStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($divRes as $d) {
            $mStmt = $db->prepare("
                SELECT m.*,
                       u.username, u.faction_rank, u.avatar,
                       fdg.name AS grade_name, fdg.rank_order AS grade_order,
                       fdg.can_manage AS grade_can_manage
                  FROM faction_division_members m
                  JOIN users u ON u.id = m.user_id
             LEFT JOIN faction_division_grades fdg ON fdg.id = m.division_grade_id
                 WHERE m.division_id = ?
                 ORDER BY COALESCE(fdg.rank_order, 999) ASC, u.username ASC
            ");
            $mStmt->execute([$d['id']]);
            $d['members'] = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            $gStmt = $db->prepare("SELECT id, name, rank_order FROM faction_division_grades
                                   WHERE division_id = ? ORDER BY rank_order ASC");
            $gStmt->execute([$d['id']]);
            $d['div_grades'] = $gStmt->fetchAll(PDO::FETCH_ASSOC);

            // Extraire min_rank depuis description (compat affichage)
            $d['min_rank'] = '';
            if (!empty($d['description']) && preg_match('/Grade minimum\s*:\s*(.+?)(\n|$)/i', $d['description'], $mm)) {
                $d['min_rank'] = trim($mm[1]);
                $d['description_clean'] = trim(str_replace($mm[0], '', $d['description']));
            } else {
                $d['description_clean'] = $d['description'] ?? '';
            }

            $divisions[] = $d;
        }
    }
} catch (Exception $e) { $queryError = $queryError ?: $e->getMessage(); }

// Officiers disponibles (pour ajout dans une div)
$allOfficers = [];
try {
    $allOfficers = $db->query("SELECT u.id, u.username, u.faction_rank, u.avatar
                               FROM users u
                               WHERE u.faction = 'LSSD' AND u.is_active = 1
                               ORDER BY u.username ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$rankNames = array_column($lssdGrades, 'rank_name');

$totalMembers = array_sum(array_column($lssdGrades, 'member_count'));
$totalDivs    = count($divisions);
$totalGrades  = count($lssdGrades);

include __DIR__ . '/includes/header.php';
?>

<style>
/* ══ GRADES & DIVISIONS — page-specific styles ══ */
.grade-pyramid { display: flex; flex-direction: column; gap: .4rem; }
.grade-row { display: flex; align-items: center; gap: .75rem; padding: .65rem .85rem;
    background: var(--bg-ov); border: 1px solid var(--bdr); border-radius: 10px;
    transition: all .18s; cursor: default; position: relative; overflow: hidden; }
.grade-row::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    background: var(--gr-color, var(--blue)); opacity: .7; }
.grade-row:hover { border-color: var(--gr-color, var(--blue)); background: var(--bg-e); }
.grade-row.is-mine { border-color: rgba(16,185,129,.5); background: rgba(16,185,129,.06); }
.rank-num { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center;
    justify-content: center; font-family: 'IBM Plex Mono', monospace; font-weight: 700; font-size: .72rem;
    flex-shrink: 0; background: rgba(16,185,129,.14); color: var(--blue-l, #6ee7b7); border: 1px solid rgba(16,185,129,.2); }
.grade-row[data-hi="true"] .rank-num { background: rgba(245,158,11,.14); color: #fde68a; border-color: rgba(245,158,11,.2); }
.grade-name-main { font-weight: 700; font-size: .87rem; color: var(--txt);
    display: flex; align-items: center; gap: .4rem; flex: 1; }
.perm-badge { display: inline-flex; align-items: center; gap: .22rem; padding: .15rem .45rem;
    border-radius: 4px; font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; white-space: nowrap; }
.perm-badge.r  { background: rgba(34,197,94,.1);  color: #6ee7b7; border: 1px solid rgba(34,197,94,.2); }
.perm-badge.s  { background: rgba(245,158,11,.1); color: #fde68a; border: 1px solid rgba(245,158,11,.2); }
.perm-badge.d  { background: rgba(16,185,129,.1); color: #6ee7b7; border: 1px solid rgba(16,185,129,.2); }
.perm-badge.c  { background: rgba(239,68,68,.1);  color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }
.salary-display { text-align: right; flex-shrink: 0; }
.salary-main { font-family: 'IBM Plex Mono', monospace; font-size: .82rem; font-weight: 700; color: #6ee7b7; }
.salary-hourly { font-family: 'IBM Plex Mono', monospace; font-size: .66rem; color: #4ade80; margin-top: .06rem; }
.salary-none { font-size: .72rem; color: var(--txt-m); }
.btn-edit-salary { width: 26px; height: 26px; background: transparent; border: 1px solid transparent;
    border-radius: 6px; cursor: pointer; color: var(--txt-m); display: flex; align-items: center;
    justify-content: center; font-size: .68rem; transition: all .14s; opacity: 0; flex-shrink: 0; }
.grade-row:hover .btn-edit-salary { opacity: 1; }
.btn-edit-salary:hover { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.25); color: #fde68a; }

/* Divisions — nouvelle mise en forme avec bouton panel */
.div-card { background: var(--bg-c); border: 1px solid var(--bdr); border-radius: 12px;
    margin-bottom: .9rem; overflow: hidden; transition: border-color .18s; }
.div-card:hover { border-color: var(--bdr-h); }
.div-card-hdr { display: flex; align-items: flex-start; justify-content: space-between;
    gap: .75rem; padding: .9rem 1.1rem .7rem; border-bottom: 1px solid rgba(255,255,255,.04); }
.div-abbr { font-family: 'IBM Plex Mono', monospace; font-size: .9rem; font-weight: 800; letter-spacing: .08em; }
.div-name-main { font-weight: 700; font-size: .9rem; color: var(--txt); }
.div-desc { font-size: .74rem; color: var(--txt-s); margin-top: .2rem; }
.div-min-rank { display: inline-flex; align-items: center; gap: .3rem; font-size: .68rem;
    color: var(--txt-m); margin-top: .2rem; }
.member-tag { display: inline-flex; align-items: center; gap: .3rem; padding: .22rem .55rem;
    background: var(--bg-ov); border: 1px solid var(--bdr); border-radius: 20px; font-size: .72rem;
    transition: border-color .12s; }
.member-tag:hover { border-color: var(--bdr-h); }
.member-tag.is-chief { border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.05); }
.member-ava { width: 18px; height: 18px; border-radius: 50%; background: rgba(16,185,129,.2);
    display: flex; align-items: center; justify-content: center; font-size: .55rem; font-weight: 800;
    color: #6ee7b7; flex-shrink: 0; }
.div-actions { display: flex; gap: .35rem; align-items: center; flex-shrink: 0; }
.div-actions button, .div-actions .btn-div { width: 28px; height: 28px; border-radius: 7px;
    background: transparent; border: 1px solid var(--bdr); cursor: pointer; display: flex;
    align-items: center; justify-content: center; font-size: .72rem; color: var(--txt-s); transition: all .14s; }
.div-actions .btn-edit:hover { background: rgba(16,185,129,.12); border-color: rgba(16,185,129,.3); color: var(--blue); }
.div-actions .btn-del:hover  { background: rgba(239,68,68,.12);  border-color: rgba(239,68,68,.3); color: #f87171; }
.add-member-form { display: flex; gap: .4rem; align-items: center; padding: .6rem 1.1rem;
    background: rgba(0,0,0,.12); border-top: 1px solid rgba(255,255,255,.03); flex-wrap:wrap; }
.delete-confirm-wrap { display: none; padding: .75rem 1.1rem; background: rgba(239,68,68,.08);
    border-top: 1px solid rgba(239,68,68,.2); align-items: center; gap: .7rem; flex-wrap: wrap; }
.delete-confirm-wrap.open { display: flex; }
.salary-calc-row { background: rgba(16,185,129,.06); border: 1px solid rgba(16,185,129,.15);
    border-radius: 8px; padding: .75rem 1rem; margin-top: .6rem;
    display: flex; justify-content: space-between; align-items: center; }
.panel-access-cta { display: flex; align-items: center; gap: .5rem; padding: .65rem 1rem;
    background: linear-gradient(90deg, rgba(16,185,129,.12), rgba(16,185,129,.04));
    border-top: 1px solid rgba(16,185,129,.25); color: #6ee7b7; font-size: .78rem; font-weight: 600; }
.panel-access-cta a { margin-left: auto; display: inline-flex; align-items: center; gap: .4rem;
    padding: .4rem .75rem; background: var(--blue); color: #fff; border-radius: 7px; text-decoration: none;
    font-size: .72rem; font-weight: 700; transition: filter .15s; }
.panel-access-cta a:hover { filter: brightness(1.15); }
</style>

<!-- ══ PAGE HEADER ══ -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.4rem;gap:1rem;flex-wrap:wrap;">
    <div>
        <h1 style="font-family:'Syne',serif;font-size:1.4rem;font-weight:800;color:#fff;margin:0 0 .3rem;display:flex;align-items:center;gap:.55rem;">
            <i class="fas fa-shield-halved" style="color:var(--blue);"></i>
            Grades & Divisions — LSSD
        </h1>
        <div style="font-size:.75rem;color:var(--txt-s);">
            <?php echo $totalGrades; ?> grades &nbsp;·&nbsp; <?php echo $totalDivs; ?> divisions &nbsp;·&nbsp; <?php echo $totalMembers; ?> officiers gradés
            <?php if ($canManage): ?>
            &nbsp;·&nbsp; <span style="color:#6ee7b7;"><i class="fas fa-shield-check" style="margin-right:.25rem;"></i>Accès Capitaine+</span>
            <?php else: ?>
            &nbsp;·&nbsp; <span style="color:var(--txt-m);">Lecture seule</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canManage): ?>
    <button onclick="LSSD.openModal('newDivModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Nouvelle division
    </button>
    <?php endif; ?>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:1rem;">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php if ($queryError): ?>
<div class="alert alert-error" style="margin-bottom:1rem;font-family:monospace;font-size:.78rem;">
    <i class="fas fa-bug"></i>
    <strong>Debug SQL :</strong> <?php echo htmlspecialchars($queryError); ?>
</div>
<?php endif; ?>

<div class="grid2">

<!-- ════════════════ COL 1 — GRADES ════════════════ -->
<div>
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <div class="card-title" style="margin:0;">
                <i class="fas fa-medal" style="color:var(--yellow);"></i> Hiérarchie des grades
            </div>
            <span style="font-size:.7rem;color:var(--txt-m);font-style:italic;">Click → modifier salaire</span>
        </div>

        <div class="grade-pyramid">
        <?php foreach ($lssdGrades as $idx => $g):
            $isHigh = ($g['rank_order'] ?? 99) <= 4;
            $isMyRank = ($g['rank_name'] === $myRankName);
            $salary = floatval($g['base_salary'] ?? 0);
            $hourly = floatval($g['salary_hourly'] ?? ($salary > 0 ? round($salary/160,2) : 0));
        ?>
        <div class="grade-row <?php echo $isMyRank ? 'is-mine' : ''; ?>"
             data-hi="<?php echo $isHigh ? 'true' : 'false'; ?>"
             <?php if ($canManage && $g['lr_id']): ?>
             onclick="openEditSalary(<?php echo $g['lr_id']; ?>,'<?php echo addslashes($g['rank_name']); ?>',<?php echo $hourly; ?>,<?php echo (int)$g['can_recruit']; ?>,<?php echo (int)($g['can_give_sanctions']??0); ?>,<?php echo (int)($g['can_manage_divisions']??0); ?>,<?php echo (int)$g['can_manage_finances']; ?>)"
             style="cursor:pointer;"
             <?php endif; ?>>

            <div class="rank-num"><?php echo $g['rank_order'] ?? '—'; ?></div>

            <div style="flex:1;min-width:0;">
                <div class="grade-name-main">
                    <?php echo htmlspecialchars($g['rank_name']); ?>
                    <?php if ($isMyRank): ?>
                    <span style="font-size:.6rem;background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);padding:.1rem .4rem;border-radius:4px;font-weight:700;letter-spacing:.05em;">MON GRADE</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.3rem;">
                    <?php if ($g['can_recruit']): ?><span class="perm-badge r"><i class="fas fa-user-plus"></i> Recrute</span><?php endif; ?>
                    <?php if ($g['can_give_sanctions'] ?? false): ?><span class="perm-badge s"><i class="fas fa-gavel"></i> Sanctionne</span><?php endif; ?>
                    <?php if ($g['can_manage_divisions'] ?? false): ?><span class="perm-badge d"><i class="fas fa-layer-group"></i> Divisions</span><?php endif; ?>
                    <?php if ($g['can_manage_finances']): ?><span class="perm-badge c"><i class="fas fa-coins"></i> Finances</span><?php endif; ?>
                    <?php if ($g['member_count'] > 0): ?>
                    <span style="font-size:.68rem;color:var(--txt-m);">
                        <i class="fas fa-users" style="margin-right:.25rem;opacity:.5;"></i><?php echo $g['member_count']; ?> membre<?php echo $g['member_count']>1?'s':''; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="salary-display">
                <?php if ($hourly > 0): ?>
                <div class="salary-main">$<?php echo number_format($hourly, 2, ',', ' '); ?>/h</div>
                <div class="salary-hourly">≈ $<?php echo number_format($hourly/60, 3, ',', ' '); ?>/min</div>
                <?php else: ?>
                <div class="salary-none">—</div>
                <?php endif; ?>
            </div>

            <?php if ($canManage && $g['lr_id']): ?>
            <button class="btn-edit-salary" title="Modifier le grade"
                    onclick="event.stopPropagation();openEditSalary(<?php echo $g['lr_id']; ?>,'<?php echo addslashes($g['rank_name']); ?>',<?php echo $hourly; ?>,<?php echo (int)$g['can_recruit']; ?>,<?php echo (int)($g['can_give_sanctions']??0); ?>,<?php echo (int)($g['can_manage_divisions']??0); ?>,<?php echo (int)$g['can_manage_finances']; ?>)">
                <i class="fas fa-pen"></i>
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($lssdGrades)): ?>
        <div style="text-align:center;padding:2.5rem;color:var(--txt-m);">
            <i class="fas fa-inbox" style="font-size:1.8rem;display:block;margin-bottom:.6rem;opacity:.25;"></i>
            Aucun grade configuré dans la table faction_grades
        </div>
        <?php endif; ?>
        </div>

        <div style="margin-top:.9rem;padding:.65rem .85rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;display:flex;gap:.7rem;flex-wrap:wrap;align-items:center;">
            <span style="font-size:.62rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-right:.2rem;">Permissions :</span>
            <span class="perm-badge r"><i class="fas fa-user-plus"></i> Recrute</span>
            <span class="perm-badge s"><i class="fas fa-gavel"></i> Sanctionne</span>
            <span class="perm-badge d"><i class="fas fa-layer-group"></i> Divisions</span>
            <span class="perm-badge c"><i class="fas fa-coins"></i> Finances</span>
        </div>
    </div>
</div>

<!-- ════════════════ COL 2 — DIVISIONS ════════════════ -->
<div>
    <?php if (empty($divisions)): ?>
    <div class="card" style="text-align:center;padding:3rem;color:var(--txt-m);">
        <i class="fas fa-layer-group" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.75rem;"></i>
        Aucune division active
        <?php if ($canManage): ?>
        <div style="margin-top:.75rem;">
            <button onclick="LSSD.openModal('newDivModal')" class="btn btn-secondary btn-sm">
                <i class="fas fa-plus"></i> Créer la première
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($divisions as $div):
        $divColor = htmlspecialchars($div['color'] ?? '#10b981');
        $panelUrl = SITE_URL . '/pages/divisions/panel.php?key=' . urlencode($div['panel_key']);
    ?>
    <div class="div-card" id="div-<?php echo (int)$div['id']; ?>">

        <!-- Header -->
        <div class="div-card-hdr" style="border-left:4px solid <?php echo $divColor; ?>;">
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.2rem;">
                    <span class="div-abbr" style="color:<?php echo $divColor; ?>">
                        <?php echo htmlspecialchars($div['abbreviation']); ?>
                    </span>
                    <span class="div-name-main"><?php echo htmlspecialchars($div['name']); ?></span>
                    <span class="badge bg-blue" style="margin-left:auto;"><?php echo (int)$div['nb_members']; ?> membre(s)</span>
                </div>
                <?php if (!empty($div['description_clean'])): ?>
                <div class="div-desc"><?php echo htmlspecialchars($div['description_clean']); ?></div>
                <?php endif; ?>
                <?php if (!empty($div['min_rank'])): ?>
                <div class="div-min-rank">
                    <i class="fas fa-lock" style="opacity:.5;"></i>
                    Grade min : <strong style="color:var(--txt-s);"><?php echo htmlspecialchars($div['min_rank']); ?></strong>
                </div>
                <?php endif; ?>
                <?php if (!empty($div['div_grades'])): ?>
                <div style="margin-top:.4rem;font-size:.68rem;color:var(--txt-m);">
                    <i class="fas fa-medal" style="opacity:.5;margin-right:.2rem;"></i>
                    <?php echo count($div['div_grades']); ?> grade<?php echo count($div['div_grades'])>1?'s':''; ?> propre<?php echo count($div['div_grades'])>1?'s':''; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($canManage): ?>
            <div class="div-actions">
                <button class="btn-edit" title="Modifier"
                        onclick="openEditDiv(<?php echo (int)$div['id']; ?>,<?php echo htmlspecialchars(json_encode([
                            'name'     => $div['name'],
                            'abbr'     => $div['abbreviation'],
                            'desc'     => $div['description_clean'] ?? '',
                            'min_rank' => $div['min_rank'] ?? '',
                            'color'    => $div['color'] ?? '#10b981',
                            'icon'     => $div['icon'] ?? 'layer-group',
                        ]), ENT_QUOTES); ?>)">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="btn-del" title="Supprimer"
                        onclick="toggleDeleteConfirm(<?php echo (int)$div['id']; ?>)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Membres -->
        <div style="padding:.6rem 1.1rem .5rem;">
            <?php if (!empty($div['members'])): ?>
            <div style="display:flex;flex-wrap:wrap;gap:.35rem;">
                <?php foreach ($div['members'] as $m):
                    $isChief = (int)($m['grade_can_manage'] ?? 0) === 1;
                ?>
                <div class="member-tag <?php echo $isChief ? 'is-chief' : ''; ?>">
                    <div class="member-ava"><?php echo strtoupper(substr($m['username'],0,1)); ?></div>
                    <span style="font-weight:600;"><?php echo htmlspecialchars($m['username']); ?></span>
                    <?php if (!empty($m['grade_name'])): ?>
                        <span style="color:#fde68a;font-size:.66rem;font-weight:700;"><?php echo htmlspecialchars($m['grade_name']); ?></span>
                    <?php elseif (!empty($m['faction_rank'])): ?>
                        <span style="color:var(--txt-m);font-size:.66rem;"><?php echo htmlspecialchars($m['faction_rank']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($m['role'])): ?>
                        <span style="color:var(--blue-l,#6ee7b7);font-size:.66rem;">(<?php echo htmlspecialchars($m['role']); ?>)</span>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                    <form method="POST" style="margin:0;display:inline;">
                        <input type="hidden" name="action" value="remove_member">
                        <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                        <button type="submit" style="background:none;border:none;cursor:pointer;color:var(--txt-m);font-size:.62rem;padding:0;line-height:1;opacity:.6;transition:opacity .12s;"
                                onmouseover="this.style.opacity=1;this.style.color='#f87171'"
                                onmouseout="this.style.opacity=.6;this.style.color='var(--txt-m)'"
                                title="Retirer">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="font-size:.75rem;color:var(--txt-m);font-style:italic;">Aucun membre assigné</div>
            <?php endif; ?>
        </div>

        <!-- Ajouter un membre -->
        <?php if ($canManage): ?>
        <form method="POST" class="add-member-form">
            <input type="hidden" name="action" value="add_member">
            <input type="hidden" name="div_id" value="<?php echo (int)$div['id']; ?>">
            <select name="user_id" class="form-select" style="flex:1;min-width:180px;font-size:.76rem;">
                <option value="">Ajouter un officier…</option>
                <?php foreach ($allOfficers as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>">
                    <?php echo htmlspecialchars($o['username'] . ($o['faction_rank'] ? ' — '.$o['faction_rank'] : '')); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($div['div_grades'])): ?>
            <select name="division_grade_id" class="form-select" style="flex:0 0 150px;font-size:.75rem;">
                <option value="">(sans grade)</option>
                <?php foreach ($div['div_grades'] as $g): ?>
                    <option value="<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="text" name="role" class="form-input" style="width:110px;font-size:.74rem;" placeholder="Rôle…">
            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-plus"></i></button>
        </form>
        <?php endif; ?>

        <!-- Accès au panel dédié -->
        <div class="panel-access-cta">
            <i class="fas fa-arrow-up-right-from-square"></i>
            Panel dédié de la division
            <a href="<?php echo htmlspecialchars($panelUrl); ?>">
                <i class="fas fa-door-open"></i> Accéder au panel
            </a>
        </div>

        <!-- Confirmation suppression -->
        <?php if ($canManage): ?>
        <div class="delete-confirm-wrap" id="del-confirm-<?php echo (int)$div['id']; ?>">
            <i class="fas fa-triangle-exclamation" style="color:#f87171;font-size:1rem;"></i>
            <div style="flex:1;font-size:.82rem;">
                <strong style="color:#f87171;">Supprimer «<?php echo htmlspecialchars($div['name']); ?>» ?</strong>
                <div style="color:var(--txt-s);font-size:.75rem;margin-top:.15rem;">
                    Membres, grades, annonces, documents et logs de la division seront supprimés. Action irréversible.
                </div>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="toggleDeleteConfirm(<?php echo (int)$div['id']; ?>)">Annuler</button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="delete_division">
                <input type="hidden" name="div_id" value="<?php echo (int)$div['id']; ?>">
                <button type="submit" class="btn btn-sm" style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3);">
                    <i class="fas fa-trash"></i> Supprimer définitivement
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

</div><!-- /.grid2 -->

<!-- ═══════════════════════════════════ MODAL — MODIFIER SALAIRE ═══════════════════════════════════ -->
<?php if ($canManage): ?>
<div id="editSalaryModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-pen-to-square"></i> Modifier le grade</h2>
            <button class="modal-cl" onclick="LSSD.closeModal('editSalaryModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="update_salary">
                <input type="hidden" name="rank_id" id="es_rid">

                <div style="font-size:.9rem;font-weight:700;color:#f9fafb;margin-bottom:1rem;padding:.5rem .75rem;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:8px;display:flex;align-items:center;gap:.5rem;">
                    <i class="fas fa-medal" style="color:#6ee7b7;"></i>
                    <span id="es_name">—</span>
                </div>

                <div class="form-group" style="margin-bottom:.75rem;">
                    <label class="form-label">Salaire horaire ($/h)</label>
                    <input type="number" name="salary_hourly" id="es_salary" class="form-input" min="0" step="0.5" placeholder="Ex: 10" oninput="updateHourlyCalc(this.value)">
                </div>
                <div class="salary-calc-row" style="margin-bottom:1.1rem;">
                    <span style="font-size:.8rem;color:var(--txt-s);">
                        <i class="fas fa-clock" style="margin-right:.3rem;opacity:.5;"></i>
                        Tarif à la minute (paie temps de service)
                    </span>
                    <span class="mono" style="color:#4ade80;font-weight:700;" id="es_hourly">$0.000/min</span>
                </div>

                <div style="font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-m);margin-bottom:.6rem;">
                    <i class="fas fa-shield-halved" style="color:var(--blue-l);margin-right:.3rem;"></i>
                    Permissions
                </div>
                <div style="display:flex;flex-direction:column;gap:.45rem;">
                    <label style="display:flex;align-items:center;gap:.75rem;padding:.6rem .85rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;cursor:pointer;">
                        <input type="checkbox" name="can_recruit" id="es_recruit" style="width:15px;height:15px;accent-color:#22c55e;cursor:pointer;flex-shrink:0;">
                        <span class="perm-badge r" style="pointer-events:none;"><i class="fas fa-user-plus"></i> Recrute</span>
                        <span style="font-size:.78rem;color:var(--txt-s);flex:1;">Peut recruter de nouveaux membres</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:.75rem;padding:.6rem .85rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;cursor:pointer;">
                        <input type="checkbox" name="can_give_sanctions" id="es_sanction" style="width:15px;height:15px;accent-color:#f59e0b;cursor:pointer;flex-shrink:0;">
                        <span class="perm-badge s" style="pointer-events:none;"><i class="fas fa-gavel"></i> Sanctionne</span>
                        <span style="font-size:.78rem;color:var(--txt-s);flex:1;">Peut émettre des sanctions</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:.75rem;padding:.6rem .85rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;cursor:pointer;">
                        <input type="checkbox" name="can_manage_divisions" id="es_divisions" style="width:15px;height:15px;accent-color:#10b981;cursor:pointer;flex-shrink:0;">
                        <span class="perm-badge d" style="pointer-events:none;"><i class="fas fa-layer-group"></i> Divisions</span>
                        <span style="font-size:.78rem;color:var(--txt-s);flex:1;">Peut gérer les divisions</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:.75rem;padding:.6rem .85rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;cursor:pointer;">
                        <input type="checkbox" name="can_manage_finances" id="es_finances" style="width:15px;height:15px;accent-color:#ef4444;cursor:pointer;flex-shrink:0;">
                        <span class="perm-badge c" style="pointer-events:none;"><i class="fas fa-coins"></i> Finances</span>
                        <span style="font-size:.78rem;color:var(--txt-s);flex:1;">Peut gérer les finances</span>
                    </label>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('editSalaryModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════ MODAL — NOUVELLE DIVISION ═══════════════════════════════════ -->
<div id="newDivModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-plus"></i> Nouvelle division</h2>
            <button class="modal-cl" onclick="LSSD.closeModal('newDivModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="add_division">
                <?php echo renderDivisionFormFields($rankNames); ?>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('newDivModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════ MODAL — MODIFIER DIVISION ═══════════════════════════════════ -->
<div id="editDivModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-pen"></i> Modifier la division</h2>
            <button class="modal-cl" onclick="LSSD.closeModal('editDivModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="edit_division">
                <input type="hidden" name="div_id" id="ed_id">
                <?php echo renderDivisionFormFields($rankNames, 'ed_'); ?>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('editDivModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<?php
function renderDivisionFormFields(array $rankNames, string $prefix = ''): string {
    $pfx = htmlspecialchars($prefix);
    $rankOpts = '<option value="">Aucun grade minimum</option>';
    foreach ($rankNames as $rn) {
        $rankOpts .= '<option value="'.htmlspecialchars($rn).'">'.htmlspecialchars($rn).'</option>';
    }
    return <<<HTML
    <div class="form-grid">
        <div class="form-group">
            <label class="form-label">Nom <span style="color:#ef4444;">*</span></label>
            <input type="text" name="name" id="{$pfx}name" class="form-input" required placeholder="Ex: SWAT, K9…">
        </div>
        <div class="form-group">
            <label class="form-label">Abréviation <span style="color:#ef4444;">*</span></label>
            <input type="text" name="abbr" id="{$pfx}abbr" class="form-input" required placeholder="SWAT" maxlength="10" style="text-transform:uppercase;">
        </div>
        <div class="form-group">
            <label class="form-label">Couleur</label>
            <input type="color" name="color" id="{$pfx}color" class="form-input" value="#10b981" style="height:42px;padding:4px;">
        </div>
        <div class="form-group">
            <label class="form-label">Icône (Font Awesome, sans "fa-")</label>
            <input type="text" name="icon" id="{$pfx}icon" class="form-input" value="layer-group" placeholder="layer-group, star, shield-halved…">
        </div>
        <div class="form-group">
            <label class="form-label">Grade minimum</label>
            <select name="min_rank" id="{$pfx}min_rank" class="form-select">
                {$rankOpts}
            </select>
        </div>
        <div class="form-group form-full">
            <label class="form-label">Description</label>
            <textarea name="desc" id="{$pfx}desc" class="form-textarea" style="min-height:70px;" placeholder="Description de la division…"></textarea>
        </div>
    </div>
HTML;
}
?>

<script>
const LSSD = {
    openModal(id){const m=document.getElementById(id);if(m){m.classList.remove('hidden');document.body.style.overflow='hidden'}},
    closeModal(id){const m=document.getElementById(id);if(m){m.classList.add('hidden');document.body.style.overflow=''}}
};
document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.modal-ov:not(.hidden)').forEach(m => { m.classList.add('hidden'); document.body.style.overflow=''; });
});
document.querySelectorAll('.modal-ov').forEach(ov => ov.addEventListener('click', e => {
    if (e.target === ov) { ov.classList.add('hidden'); document.body.style.overflow=''; }
}));

function openEditSalary(id, name, hourly, canRecruit, canSanction, canDivisions, canFinances) {
    document.getElementById('es_rid').value = id;
    document.getElementById('es_name').textContent = name;
    document.getElementById('es_salary').value = hourly;
    document.getElementById('es_recruit').checked = !!canRecruit;
    document.getElementById('es_sanction').checked = !!canSanction;
    document.getElementById('es_divisions').checked = !!canDivisions;
    document.getElementById('es_finances').checked = !!canFinances;
    updateHourlyCalc(hourly);
    LSSD.openModal('editSalaryModal');
}
function updateHourlyCalc(v) {
    const perMin = (parseFloat(v) || 0) / 60;
    const el = document.getElementById('es_hourly');
    if (el) el.textContent = '$' + perMin.toFixed(3) + '/min';
}

function openEditDiv(id, data) {
    document.getElementById('ed_id').value    = id;
    document.getElementById('ed_name').value  = data.name  || '';
    document.getElementById('ed_abbr').value  = data.abbr  || '';
    document.getElementById('ed_desc').value  = data.desc  || '';
    document.getElementById('ed_color').value = data.color || '#10b981';
    document.getElementById('ed_icon').value  = data.icon  || 'layer-group';
    const sel = document.getElementById('ed_min_rank');
    if (sel) Array.from(sel.options).forEach(o => { o.selected = (o.value === (data.min_rank || '')); });
    LSSD.openModal('editDivModal');
}

function toggleDeleteConfirm(divId) {
    const el = document.getElementById('del-confirm-' + divId);
    if (!el) return;
    el.classList.toggle('open');
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
