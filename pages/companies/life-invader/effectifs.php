<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Life Invader';
$companyColor   = '#4f7cff';
$companyFaction = 'LIFE_INVADER';
$companyIcon    = 'newspaper';
$companyPath    = 'life-invader';

// Grades fallback (sécurité si la DB est vide) — hiérarchie newsroom
$gradeFallback = [
    ['name'=>'Directeur de Publication','rank_order'=>1,'can_recruit'=>1,'is_owner'=>1,'is_ceo'=>0,'can_manage_finances'=>1,'can_manage_stock'=>1],
    ['name'=>'Rédacteur en Chef',      'rank_order'=>2,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>1,'can_manage_finances'=>1,'can_manage_stock'=>1],
    ['name'=>'Chef de Rubrique',       'rank_order'=>3,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
    ['name'=>'Rédacteur Senior',       'rank_order'=>4,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
    ['name'=>'Éditeur',                'rank_order'=>5,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
    ['name'=>'Journaliste',            'rank_order'=>6,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
    ['name'=>'Présentateur',           'rank_order'=>7,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
    ['name'=>'Stagiaire',              'rank_order'=>8,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
];

if ($_SESSION['user_faction'] !== $companyFaction
    && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Équipe Éditoriale';
$pageSubtitle = 'Gestion des journalistes et rédacteurs Life Invader';

$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);
$msg = ''; $msgType = '';

// ── Grades de la faction ────────────────────────────────────────────────────
$GRADES = [];
try {
    $gs = $db->prepare(
        "SELECT fg.name, fg.rank_order, fg.can_recruit, fg.can_manage_stock,
                fg.can_manage_finances, fg.is_owner, fg.is_ceo
         FROM faction_grades fg
         JOIN factions f ON f.id = fg.faction_id
         WHERE f.faction_key = ?
         ORDER BY fg.rank_order ASC"
    );
    $gs->execute([$companyFaction]);
    $GRADES = $gs->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (empty($GRADES)) $GRADES = $gradeFallback;

$GRADE_NAMES = array_column($GRADES, 'name');
$RLEVEL      = [];
$GRADE_MAP   = [];
foreach ($GRADES as $idx => $g) {
    $RLEVEL[$g['name']]    = count($GRADES) - $idx;
    $GRADE_MAP[$g['name']] = $g;
}

// ── Rôles système disponibles ──────────────────────────────────────────────
$AVAILABLE_ROLES = [
    'Citoyen'         => ['lbl'=>'Citoyen',         'icon'=>'fa-user',          'tone'=>'slate'],
    'Helper'          => ['lbl'=>'Helper',          'icon'=>'fa-hands-helping', 'tone'=>'cyan'],
    'Recruteur'       => ['lbl'=>'Recruteur',       'icon'=>'fa-user-plus',     'tone'=>'green'],
    'Modérateur'      => ['lbl'=>'Modérateur',      'icon'=>'fa-shield-alt',    'tone'=>'blue'],
    'Staff'           => ['lbl'=>'Staff',           'icon'=>'fa-user-tie',      'tone'=>'violet'],
    'Développeur'     => ['lbl'=>'Développeur',     'icon'=>'fa-code',          'tone'=>'amber'],
    'Administrateur'  => ['lbl'=>'Administrateur',  'icon'=>'fa-crown',         'tone'=>'red'],
];

// ── Permissions de l'utilisateur connecté ──────────────────────────────────
$myGrade = '';
try {
    $ms = $db->prepare("SELECT faction_rank FROM users WHERE id=? LIMIT 1");
    $ms->execute([$uid]);
    $myGrade = $ms->fetchColumn() ?: '';
} catch (Exception $e) {}
$myLevel     = $RLEVEL[$myGrade] ?? 0;
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
if (!faction_acl_can($db, $uid, $companyFaction, 'effectifs.view')) faction_acl_deny();
$canRecruit  = faction_acl_can($db, $uid, $companyFaction, 'effectifs.recruit');
$canFire     = faction_acl_can($db, $uid, $companyFaction, 'effectifs.fire');
$canEdit     = faction_acl_can($db, $uid, $companyFaction, 'effectifs.edit_grade');
$isStaff     = faction_acl_staff_bypass($db, $uid, $companyFaction);
$canSetRole  = $isStaff;

if (!function_exists('canActOnAgent')) {
    function canActOnAgent(int $targetLevel, int $myLevel, bool $isStaff): bool {
        return $isStaff || ($myLevel > $targetLevel);
    }
}

// ── Helper : (re)créer la ligne company_employees pour cohérence stats ────
function ensureCompanyEmployeeRow(PDO $db, int $userId, string $faction, string $rank): void {
    try {
        $chk = $db->prepare("SELECT id FROM company_employees WHERE user_id=? AND faction=? LIMIT 1");
        $chk->execute([$userId, $faction]);
        $exists = $chk->fetchColumn();
        if ($exists) {
            $db->prepare("UPDATE company_employees SET `rank`=? WHERE id=?")->execute([$rank, $exists]);
        } else {
            $db->prepare("
                INSERT INTO company_employees (user_id, faction, `rank`, salary_percentage, hired_date)
                VALUES (?, ?, ?, 70.00, CURDATE())
            ")->execute([$userId, $faction, $rank]);
        }
    } catch (Exception $e) { /* table absente : on ignore */ }
}

// ══ POST ACTIONS ═════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'recruit' && $canRecruit) {
        $targetId  = intval($_POST['user_id'] ?? 0);
        $initGrade = trim($_POST['grade'] ?? ($GRADE_NAMES[count($GRADE_NAMES)-1] ?? ''));
        try {
            $chk = $db->prepare(
                "SELECT u.id, u.username, u.role,
                        p.firstname AS profile_firstname, p.lastname AS profile_lastname
                 FROM users u
                 LEFT JOIN profiles p ON p.id = u.profile_id
                 WHERE u.id=?
                   AND (u.faction='NONE' OR u.faction IS NULL OR u.faction='' OR u.faction != ?)
                   AND u.is_active=1 LIMIT 1"
            );
            $chk->execute([$targetId, $companyFaction]);
            $target = $chk->fetch();
            if (!$target) throw new Exception("Utilisateur introuvable ou déjà chez Life Invader.");
            if (!in_array($initGrade, $GRADE_NAMES, true)) throw new Exception("Grade invalide.");

            $db->prepare("UPDATE users SET faction=?, faction_rank=?, faction_grade=? WHERE id=?")
               ->execute([$companyFaction, $initGrade, $initGrade, $targetId]);

            ensureCompanyEmployeeRow($db, $targetId, $companyFaction, $initGrade);

            $tDisp = mdt_rp_display_name(
                $target['profile_firstname'] ?? null,
                $target['profile_lastname'] ?? null,
                (string)$target['username']
            );
            $msg     = htmlspecialchars($tDisp) . ' recruté(e) au grade ' . htmlspecialchars($initGrade) . '.';
            $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
        }
    }

    if ($action === 'change_grade' && $canRecruit) {
        $targetId = intval($_POST['user_id'] ?? 0);
        $newGrade = trim($_POST['new_grade'] ?? '');
        try {
            $gs2 = $db->prepare(
                "SELECT u.faction_rank, u.username,
                        p.firstname AS profile_firstname, p.lastname AS profile_lastname
                 FROM users u
                 LEFT JOIN profiles p ON p.id = u.profile_id
                 WHERE u.id=? AND u.faction=? LIMIT 1"
            );
            $gs2->execute([$targetId, $companyFaction]);
            $tRow = $gs2->fetch();
            if (!$tRow) throw new Exception("Employé introuvable.");
            $targetLevel = $RLEVEL[$tRow['faction_rank'] ?? ''] ?? 0;
            if ((int)$targetId === $uid) throw new Exception("Vous ne pouvez pas modifier votre propre grade.");
            if (!$isStaff && $myLevel <= $targetLevel) {
                throw new Exception("Vous ne pouvez pas modifier le grade d'un agent de grade supérieur ou égal au vôtre.");
            }
            if (!in_array($newGrade, $GRADE_NAMES, true)) throw new Exception("Grade invalide.");
            $newLevel = $RLEVEL[$newGrade] ?? 0;
            if (!$isStaff && $newLevel >= $myLevel) {
                throw new Exception("Vous ne pouvez attribuer que des grades strictement inférieurs au vôtre.");
            }

            $db->prepare("UPDATE users SET faction_rank=?, faction_grade=? WHERE id=? AND faction=?")
               ->execute([$newGrade, $newGrade, $targetId, $companyFaction]);
            ensureCompanyEmployeeRow($db, $targetId, $companyFaction, $newGrade);

            $tDisp2 = mdt_rp_display_name(
                $tRow['profile_firstname'] ?? null,
                $tRow['profile_lastname'] ?? null,
                (string)$tRow['username']
            );
            $msg     = htmlspecialchars($tDisp2) . ' → ' . htmlspecialchars($newGrade) . '.';
            $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
        }
    }

    if ($action === 'change_role' && $canSetRole) {
        $targetId = intval($_POST['user_id'] ?? 0);
        $newRole  = trim($_POST['new_role'] ?? '');
        try {
            if (!array_key_exists($newRole, $AVAILABLE_ROLES)) throw new Exception("Rôle invalide.");
            if ((int)$targetId === $uid && !hasRole(['*','Administrateur'])) {
                throw new Exception("Vous ne pouvez pas modifier votre propre rôle.");
            }
            $exists = $db->prepare("SELECT id FROM users WHERE id=? AND faction=? LIMIT 1");
            $exists->execute([$targetId, $companyFaction]);
            if (!$exists->fetchColumn()) throw new Exception("Employé introuvable.");

            $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$newRole, $targetId]);
            $msg = 'Rôle mis à jour vers ' . htmlspecialchars($newRole) . '.';
            $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
        }
    }

    if ($action === 'fire' && $canFire) {
        $targetId = intval($_POST['user_id'] ?? 0);
        try {
            if ((int)$targetId === $uid) throw new Exception("Vous ne pouvez pas vous licencier vous-même.");
            $gs3 = $db->prepare(
                "SELECT u.faction_rank, u.username,
                        p.firstname AS profile_firstname, p.lastname AS profile_lastname
                 FROM users u
                 LEFT JOIN profiles p ON p.id = u.profile_id
                 WHERE u.id=? AND u.faction=? LIMIT 1"
            );
            $gs3->execute([$targetId, $companyFaction]);
            $tRow3 = $gs3->fetch();
            if (!$tRow3) throw new Exception("Employé introuvable.");
            $targetLevel3 = $RLEVEL[$tRow3['faction_rank'] ?? ''] ?? 0;
            if (!$isStaff && $myLevel <= $targetLevel3) {
                throw new Exception("Vous ne pouvez pas licencier un agent de grade supérieur ou égal au vôtre.");
            }
            $db->prepare("UPDATE users SET faction='NONE', faction_rank=NULL, faction_grade=NULL WHERE id=?")
               ->execute([$targetId]);
            try {
                $db->prepare("DELETE FROM company_employees WHERE user_id=? AND faction=?")
                   ->execute([$targetId, $companyFaction]);
            } catch (Exception $e) {}

            $tDisp3 = mdt_rp_display_name(
                $tRow3['profile_firstname'] ?? null,
                $tRow3['profile_lastname'] ?? null,
                (string)$tRow3['username']
            );
            $msg     = htmlspecialchars($tDisp3) . ' licencié(e).';
            $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
        }
    }
}

// ══ DATA ═════════════════════════════════════════════════════════════════════
$employees = [];
try {
    $eq = $db->prepare("
        SELECT u.id, u.username, u.faction_rank, u.faction_grade, u.avatar, u.role, u.created_at,
               p.firstname AS profile_firstname, p.lastname AS profile_lastname, p.phone AS profile_phone
        FROM users u
        LEFT JOIN profiles p ON p.id = u.profile_id
        WHERE u.faction=? AND u.is_active=1
        ORDER BY
          CASE WHEN u.faction_rank IS NULL OR u.faction_rank='' THEN 1 ELSE 0 END,
          u.faction_rank, u.username ASC
    ");
    $eq->execute([$companyFaction]);
    $employees = $eq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

usort($employees, function($a,$b) use ($RLEVEL) {
    $la = $RLEVEL[$a['faction_rank'] ?? ''] ?? 0;
    $lb = $RLEVEL[$b['faction_rank'] ?? ''] ?? 0;
    if ($la !== $lb) return $lb - $la;
    return strcmp(strtolower($a['username']), strtolower($b['username']));
});

$candidates = [];
if ($canRecruit) {
    try {
        $cq = $db->prepare("
            SELECT u.id, u.username, u.role, u.avatar,
                   p.firstname AS profile_firstname, p.lastname AS profile_lastname, p.phone
            FROM users u
            LEFT JOIN profiles p ON p.id = u.profile_id
            WHERE u.is_active=1
              AND (u.faction='NONE' OR u.faction IS NULL OR u.faction='')
            ORDER BY COALESCE(p.lastname,''), COALESCE(p.firstname,''), u.username ASC
            LIMIT 1000
        ");
        $cq->execute();
        $candidates = $cq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$totalEmp = count($employees);
$gradeCount = [];
foreach ($employees as $e) {
    $g = $e['faction_rank'] ?: '—';
    $gradeCount[$g] = ($gradeCount[$g] ?? 0) + 1;
}

$roleCount = [];
foreach ($employees as $e) {
    $r = $e['role'] ?: 'Citoyen';
    $roleCount[$r] = ($roleCount[$r] ?? 0) + 1;
}

include __DIR__ . '/includes/header.php';
?>
<style>
.emp-card { padding: 1.1rem; display: flex; flex-direction: column; gap: .85rem; }
.h-sub { font-size: .72rem; color: var(--tx-3); margin-top: .15rem; }
.btn-xs { padding: .3rem .6rem; font-size: .7rem; border-radius: 7px; }
.search-input { position: relative; }
.search-input i.fa-search { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--tx-3); font-size: .82rem; }
.search-input input { padding-left: 2.2rem; }
.rc-item { transition: all .12s; }
.rc-item:hover { background: var(--li-softer) !important; border-color: var(--li-bd) !important; }
.rc-grade-btn.on { background: var(--li-soft) !important; border-color: var(--li) !important; color: var(--li) !important; }
.form-label-req::after { content: ' *'; color: #ef4444; }
[data-tip] { position: relative; }
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <div class="page-header-title"><i class="fas fa-user-edit"></i> Équipe Éditoriale</div>
        <div class="page-header-sub">
            <strong style="color:var(--tx-2)"><?php echo $totalEmp; ?></strong> journaliste<?php echo $totalEmp>1?'s':''; ?> actif<?php echo $totalEmp>1?'s':''; ?>
            <?php if ($myGrade): ?> · Votre grade : <strong style="color:var(--li)"><?php echo htmlspecialchars($myGrade); ?></strong><?php endif; ?>
            <?php if (!$canRecruit && !$isStaff): ?> · <span style="color:var(--tx-mute)">Lecture seule (grade RH requis)</span><?php endif; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?php if ($canRecruit): ?>
        <button onclick="Company.openModal('recruitModal')" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Recruter
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Stats / KPIs -->
<div class="li-stats">
    <div class="li-stat">
        <div class="li-stat-ico"><i class="fas fa-users"></i></div>
        <div>
            <div class="li-stat-val"><?php echo $totalEmp; ?></div>
            <div class="li-stat-lbl">Effectif total</div>
            <div class="li-stat-sub"><?php echo count(array_filter($GRADES, function($g) use ($gradeCount){ return ($gradeCount[$g['name']] ?? 0) > 0; })); ?> grades pourvus</div>
        </div>
    </div>
    <div class="li-stat">
        <div class="li-stat-ico ico-amber"><i class="fas fa-crown"></i></div>
        <div>
            <div class="li-stat-val"><?php echo array_sum(array_map(fn($g)=>($gradeCount[$g['name']]??0), array_filter($GRADES,fn($g)=>($g['rank_order']??99)<=2))); ?></div>
            <div class="li-stat-lbl">Direction</div>
            <div class="li-stat-sub">DP · Rédac chef</div>
        </div>
    </div>
    <div class="li-stat">
        <div class="li-stat-ico ico-cyan"><i class="fas fa-pen-fancy"></i></div>
        <div>
            <div class="li-stat-val"><?php echo array_sum(array_map(fn($g)=>($gradeCount[$g['name']]??0), array_filter($GRADES,fn($g)=>in_array($g['name'],['Rédacteur Senior','Éditeur','Chef de Rubrique'])))); ?></div>
            <div class="li-stat-lbl">Rédaction</div>
            <div class="li-stat-sub">Senior · Éditeurs</div>
        </div>
    </div>
    <div class="li-stat">
        <div class="li-stat-ico ico-violet"><i class="fas fa-shield-alt"></i></div>
        <div>
            <div class="li-stat-val"><?php echo ($roleCount['Staff'] ?? 0) + ($roleCount['Modérateur'] ?? 0) + ($roleCount['Administrateur'] ?? 0) + ($roleCount['Développeur'] ?? 0); ?></div>
            <div class="li-stat-lbl">Rôles privilégiés</div>
            <div class="li-stat-sub">Staff · Mod · Admin · Dev</div>
        </div>
    </div>
</div>

<!-- Hiérarchie -->
<div class="card card-tight" style="margin-bottom:1.3rem">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem">
        <i class="fas fa-layer-group" style="color:var(--li);font-size:.85rem"></i>
        <span style="font-size:.74rem;font-weight:700;color:var(--tx-2);text-transform:uppercase;letter-spacing:.06em">Hiérarchie</span>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
    <?php foreach ($GRADES as $g):
        $cnt   = $gradeCount[$g['name']] ?? 0;
        $isTop = ($g['rank_order'] ?? 99) <= 2;
        $cls   = $isTop ? 'badge-acte_final' : ($cnt>0 ? 'badge-actif' : 'badge-soft');
    ?>
    <span class="badge <?php echo $cls; ?>" style="font-size:.7rem;padding:.3rem .72rem">
        <?php if ($isTop): ?><i class="fas fa-crown"></i><?php endif; ?>
        <?php echo htmlspecialchars($g['name']); ?>
        <span style="font-weight:500;opacity:.7;margin-left:.1rem">· <?php echo $cnt; ?></span>
    </span>
    <?php endforeach; ?>
    </div>
</div>

<!-- Filtres -->
<div class="filters-bar">
    <div class="filter-group" style="flex:2">
        <label class="filter-lbl">Rechercher</label>
        <div class="search-input">
            <i class="fas fa-search"></i>
            <input type="text" id="empSearch" oninput="filterEmployees()" class="form-input" placeholder="Nom, prénom ou pseudo...">
        </div>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Grade</label>
        <select id="filterGrade" onchange="filterEmployees()" class="form-select">
            <option value="">Tous les grades</option>
            <?php foreach ($GRADES as $g): ?>
            <option value="<?php echo htmlspecialchars($g['name']); ?>"><?php echo htmlspecialchars($g['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Rôle</label>
        <select id="filterRole" onchange="filterEmployees()" class="form-select">
            <option value="">Tous les rôles</option>
            <?php foreach ($AVAILABLE_ROLES as $rk=>$rv): ?>
            <option value="<?php echo htmlspecialchars($rk); ?>"><?php echo htmlspecialchars($rv['lbl']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Grille employés -->
<?php if (empty($employees)): ?>
<div class="card">
    <div class="empty-state">
        <i class="fas fa-users-slash"></i>
        <h3>Aucun journaliste</h3>
        <p>Aucun agent n'est rattaché à Life Invader pour le moment.</p>
        <?php if ($canRecruit): ?>
        <button onclick="Company.openModal('recruitModal')" class="btn btn-primary"><i class="fas fa-user-plus"></i> Recruter le premier journaliste</button>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div id="empGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:1rem">
<?php foreach ($employees as $emp):
    $empGrade  = $emp['faction_rank'] ?? '';
    $empLevel  = $RLEVEL[$empGrade] ?? 0;
    $empGData  = $GRADE_MAP[$empGrade] ?? null;
    $canActEmp = canActOnAgent($empLevel, $myLevel, $isStaff);
    $isMe      = ((int)$emp['id'] === $uid);
    $empDisp   = mdt_rp_display_name($emp['profile_firstname'] ?? null, $emp['profile_lastname'] ?? null, (string)$emp['username']);
    $initial   = mdt_rp_initials($emp['profile_firstname'] ?? null, $emp['profile_lastname'] ?? null, (string)$emp['username']);
    $isTopGrade= isset($empGData) && ($empGData['rank_order'] ?? 99) <= 2;
    $empRole   = $emp['role'] ?: 'Citoyen';
    $roleInfo  = $AVAILABLE_ROLES[$empRole] ?? ['lbl'=>$empRole,'icon'=>'fa-user','tone'=>'slate'];
?>
<div class="emp-card card"
     data-name="<?php echo htmlspecialchars(strtolower($empDisp . ' ' . ($emp['username'] ?? '')), ENT_QUOTES); ?>"
     data-grade="<?php echo htmlspecialchars($empGrade); ?>"
     data-role="<?php echo htmlspecialchars($empRole); ?>">
    <div style="display:flex;align-items:flex-start;gap:.85rem">
        <div style="width:46px;height:46px;border-radius:11px;background:var(--li-soft);border:1px solid var(--li-bd);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.02rem;color:var(--li);flex-shrink:0;overflow:hidden">
            <?php if (!empty($emp['avatar'])): ?>
            <img src="<?php echo htmlspecialchars($emp['avatar']); ?>" style="width:100%;height:100%;object-fit:cover;border-radius:10px" onerror="this.parentNode.textContent='<?php echo $initial; ?>'">
            <?php else: echo $initial; endif; ?>
        </div>
        <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
                <span style="font-weight:700;font-size:.92rem;color:var(--tx);letter-spacing:-.01em"><?php echo htmlspecialchars($empDisp); ?></span>
                <?php if ($isMe): ?>
                <span class="badge badge-actif" style="font-size:.58rem;padding:.13rem .42rem">VOUS</span>
                <?php endif; ?>
                <?php if (!$canActEmp && !$isMe): ?>
                <i class="fas fa-lock" title="Grade supérieur ou égal au vôtre" style="color:var(--tx-mute);font-size:.7rem;margin-left:auto"></i>
                <?php endif; ?>
            </div>
            <div style="font-size:.72rem;color:var(--tx-mute);margin-top:.15rem">@<?php echo htmlspecialchars($emp['username']); ?></div>
            <?php if (!empty($emp['profile_phone'])): ?>
            <div style="font-size:.7rem;color:var(--tx-3);margin-top:.18rem"><i class="fas fa-phone" style="font-size:.6rem;margin-right:.25rem;color:var(--li)"></i><?php echo htmlspecialchars($emp['profile_phone']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:.35rem">
        <?php if ($empGrade): ?>
        <span class="badge <?php echo $isTopGrade ? 'badge-acte_final' : 'badge-actif'; ?>" style="font-size:.66rem">
            <?php if ($isTopGrade): ?><i class="fas fa-crown"></i><?php endif; ?>
            <?php echo htmlspecialchars($empGrade); ?>
        </span>
        <?php else: ?>
        <span class="badge badge-soft" style="font-size:.66rem;font-style:italic">Grade non défini</span>
        <?php endif; ?>

        <span class="badge badge-soft" style="font-size:.66rem">
            <i class="fas <?php echo $roleInfo['icon']; ?>" style="font-size:.58rem"></i>
            <?php echo htmlspecialchars($roleInfo['lbl']); ?>
        </span>
    </div>

    <?php if (($canRecruit || $canFire || $canSetRole) && !$isMe): ?>
    <div style="display:flex;flex-wrap:wrap;gap:.35rem;padding-top:.7rem;border-top:1px solid var(--bd);margin-top:auto">
        <?php if ($canRecruit && $canActEmp): ?>
        <button onclick="openGradeModal(<?php echo (int)$emp['id']; ?>,<?php echo htmlspecialchars(json_encode($empDisp, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>,'<?php echo addslashes($empGrade); ?>')"
                class="btn btn-secondary btn-xs">
            <i class="fas fa-sliders-h"></i> Grade
        </button>
        <?php elseif ($canRecruit): ?>
        <button class="btn btn-secondary btn-xs" disabled style="opacity:.5;cursor:not-allowed"><i class="fas fa-lock"></i> Grade</button>
        <?php endif; ?>

        <?php if ($canSetRole && $canActEmp): ?>
        <button onclick="openRoleModal(<?php echo (int)$emp['id']; ?>,<?php echo htmlspecialchars(json_encode($empDisp, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>,'<?php echo addslashes($empRole); ?>')"
                class="btn btn-secondary btn-xs">
            <i class="fas fa-user-shield"></i> Rôle
        </button>
        <?php endif; ?>

        <?php if ($canFire && $canActEmp): ?>
        <form method="POST" style="margin:0;margin-left:auto"
              data-confirm="%t quittera définitivement la rédaction Life Invader. L'historique des publications est conservé."
              data-confirm-title="Licencier ce journaliste ?"
              data-confirm-tone="danger"
              data-confirm-icon="fa-user-slash"
              data-confirm-ok="Licencier"
              data-confirm-target="<?php echo htmlspecialchars($empDisp); ?>">
            <input type="hidden" name="action" value="fire">
            <input type="hidden" name="user_id" value="<?php echo (int)$emp['id']; ?>">
            <button type="submit" class="btn btn-danger btn-xs">
                <i class="fas fa-user-slash"></i> Licencier
            </button>
        </form>
        <?php elseif ($canFire): ?>
        <button class="btn btn-danger btn-xs" disabled style="opacity:.5;cursor:not-allowed;margin-left:auto"><i class="fas fa-lock"></i> Licencier</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<div id="emptyFilter" class="card" style="display:none;margin-top:.9rem">
    <div class="empty-state" style="padding:2rem"><i class="fas fa-search"></i><p>Aucun journaliste ne correspond aux critères.</p></div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════
     MODAL — RECRUTER
═════════════════════════════════════════════════════════════════ -->
<?php if ($canRecruit): ?>
<div id="recruitModal" class="modal-overlay hidden">
<div class="modal modal-lg">
    <div class="modal-header">
        <div>
            <h2><i class="fas fa-user-plus"></i> Recruter un journaliste</h2>
            <div class="h-sub"><?php echo count($candidates); ?> candidat<?php echo count($candidates)>1?'s':''; ?> disponible<?php echo count($candidates)>1?'s':''; ?></div>
        </div>
        <button class="modal-close" onclick="Company.closeModal('recruitModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="recruitForm">
    <input type="hidden" name="action" value="recruit">
    <div class="modal-body">
        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Étape 1 — Sélectionner un candidat</label>
            <div class="search-input">
                <i class="fas fa-search"></i>
                <input type="text" id="rc_search" class="form-input" placeholder="Rechercher par nom, prénom ou pseudo..." oninput="rcFilter(this.value)" autocomplete="off">
            </div>
            <div class="form-hint"><i class="fas fa-users" style="opacity:.6"></i> <span id="rc_count"><?php echo count($candidates); ?></span> candidat(s) sans faction</div>
        </div>

        <div id="rc_list" style="max-height:340px;overflow-y:auto;display:flex;flex-direction:column;gap:.4rem;margin-bottom:1rem">
            <?php if (empty($candidates)): ?>
            <div class="empty-state" style="padding:2rem 1rem"><i class="fas fa-user-slash"></i><h3>Aucun candidat</h3><p>Tous les utilisateurs actifs ont déjà une faction.</p></div>
            <?php else: foreach ($candidates as $c):
                $cDisp = mdt_rp_display_name($c['profile_firstname'] ?? null, $c['profile_lastname'] ?? null, (string)$c['username']);
                $cInit = mdt_rp_initials($c['profile_firstname'] ?? null, $c['profile_lastname'] ?? null, (string)$c['username']);
                $cSearch = strtolower(($c['username'] ?? '') . ' ' . $cDisp . ' ' . ($c['phone'] ?? ''));
                $cRole = $c['role'] ?? 'Citoyen';
                $cRoleInfo = $AVAILABLE_ROLES[$cRole] ?? ['lbl'=>$cRole,'icon'=>'fa-user'];
            ?>
            <label class="rc-item" data-s="<?php echo htmlspecialchars($cSearch, ENT_QUOTES); ?>"
                   style="display:flex;align-items:center;gap:.75rem;padding:.65rem .85rem;background:var(--surface-2);border:1px solid var(--bd);border-radius:10px;cursor:pointer"
                   onclick="rcSelect(this)">
                <input type="radio" name="user_id" value="<?php echo (int)$c['id']; ?>" style="display:none" required>
                <div style="width:36px;height:36px;border-radius:9px;background:var(--li-soft);border:1px solid var(--li-bd);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--li);font-size:.82rem;flex-shrink:0;overflow:hidden">
                    <?php if (!empty($c['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($c['avatar']); ?>" style="width:100%;height:100%;object-fit:cover;border-radius:8px" onerror="this.parentNode.textContent='<?php echo $cInit; ?>'">
                    <?php else: echo $cInit; endif; ?>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600;font-size:.85rem;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($cDisp); ?></div>
                    <div style="font-size:.7rem;color:var(--tx-3);margin-top:.1rem">
                        @<?php echo htmlspecialchars($c['username']); ?>
                        <?php if (!empty($c['phone'])): ?> · <i class="fas fa-phone" style="font-size:.55rem;color:var(--li)"></i> <?php echo htmlspecialchars($c['phone']); ?><?php endif; ?>
                    </div>
                </div>
                <span class="badge badge-soft" style="font-size:.62rem;padding:.16rem .5rem"><i class="fas <?php echo $cRoleInfo['icon']; ?>" style="font-size:.52rem"></i> <?php echo htmlspecialchars($cRoleInfo['lbl']); ?></span>
                <div class="rc-check" style="width:18px;height:18px;border-radius:50%;border:2px solid var(--bd-h);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fas fa-check" style="font-size:.55rem;color:#fff;opacity:0"></i>
                </div>
            </label>
            <?php endforeach; endif; ?>
        </div>

        <div id="rc_preview" style="display:none;margin-bottom:1rem;padding:.85rem 1rem;background:var(--li-soft);border:1px solid var(--li-bd);border-radius:10px;align-items:center;gap:.75rem">
            <div id="rc_prev_av" style="width:36px;height:36px;border-radius:9px;background:var(--li);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0"></div>
            <div style="flex:1">
                <div id="rc_prev_name" style="font-weight:600;font-size:.86rem;color:var(--tx)"></div>
                <div style="font-size:.7rem;color:var(--li);margin-top:.1rem">Candidat sélectionné</div>
            </div>
            <i class="fas fa-check-circle" style="color:var(--li);font-size:1rem"></i>
        </div>

        <div class="form-group">
            <label class="form-label">Étape 2 — Grade d'entrée</label>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem" id="rc_grade_btns">
                <?php foreach (array_reverse($GRADES) as $g):
                    $gLvl     = $RLEVEL[$g['name']] ?? 0;
                    $blocked  = !$isStaff && $gLvl >= $myLevel;
                    $isDefault= ($g['rank_order'] === max(array_column($GRADES,'rank_order')));
                ?>
                <label class="rc-grade-btn <?php echo $blocked?'blocked':''; ?> <?php echo $isDefault?'on':''; ?>"
                       style="cursor:<?php echo $blocked?'not-allowed':'pointer'; ?>;display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:8px;font-size:.78rem;font-weight:600;border:1px solid var(--bd);background:var(--surface-2);color:var(--tx-3);<?php echo $blocked?'opacity:.4':''; ?>"
                       onclick="rcGradeSelect(this)" data-blocked="<?php echo $blocked?1:0; ?>">
                    <input type="radio" name="grade" value="<?php echo htmlspecialchars($g['name']); ?>" <?php echo $isDefault?'checked':''; ?> <?php echo $blocked?'disabled':''; ?> style="display:none">
                    <?php if ($blocked): ?><i class="fas fa-lock" style="font-size:.6rem"></i><?php endif; ?>
                    <?php echo htmlspecialchars($g['name']); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php if (!$isStaff && $myLevel < count($GRADES)): ?>
            <div class="form-hint"><i class="fas fa-info-circle"></i> Vous ne pouvez attribuer que des grades strictement inférieurs au vôtre.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="Company.closeModal('recruitModal')">Annuler</button>
        <button type="submit" id="rc_submit" class="btn btn-primary" disabled style="opacity:.5;cursor:not-allowed">
            <i class="fas fa-user-plus"></i> Confirmer le recrutement
        </button>
    </div>
    </form>
</div>
</div>

<!-- MODAL — CHANGER LE GRADE -->
<div id="gradeModal" class="modal-overlay hidden">
<div class="modal modal-sm">
    <div class="modal-header">
        <h2><i class="fas fa-sliders-h"></i> Modifier le grade</h2>
        <button class="modal-close" onclick="Company.closeModal('gradeModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="action"  value="change_grade">
        <input type="hidden" name="user_id" id="gm_uid">
        <div style="padding:.7rem .95rem;background:var(--surface-2);border:1px solid var(--bd);border-radius:9px;margin-bottom:1rem;display:flex;align-items:center;gap:.6rem">
            <i class="fas fa-user" style="color:var(--li);font-size:.85rem"></i>
            <div style="flex:1;font-weight:600;color:var(--tx);font-size:.86rem" id="gm_name">—</div>
            <span class="badge badge-soft" style="font-size:.62rem"><span id="gm_current">—</span></span>
        </div>
        <div class="form-group">
            <label class="form-label form-label-req">Nouveau grade</label>
            <select name="new_grade" id="gm_grade" class="form-select" required>
                <?php foreach (array_reverse($GRADES) as $g):
                    $gLvl = $RLEVEL[$g['name']] ?? 0;
                    $blocked = !$isStaff && $gLvl >= $myLevel;
                ?>
                <option value="<?php echo htmlspecialchars($g['name']); ?>" <?php echo $blocked?'disabled':''; ?>>
                    <?php echo htmlspecialchars($g['name']); ?><?php echo $blocked?' (interdit)':''; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isStaff && $myLevel < count($GRADES)): ?>
            <div class="form-hint"><i class="fas fa-info-circle"></i> Vous ne pouvez attribuer que des grades inférieurs au vôtre.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="Company.closeModal('gradeModal')">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
    </div>
    </form>
</div>
</div>
<?php endif; ?>

<!-- MODAL — CHANGER LE RÔLE (staff only) -->
<?php if ($canSetRole): ?>
<div id="roleModal" class="modal-overlay hidden">
<div class="modal modal-sm">
    <div class="modal-header">
        <h2><i class="fas fa-user-shield"></i> Modifier le rôle système</h2>
        <button class="modal-close" onclick="Company.closeModal('roleModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="action" value="change_role">
        <input type="hidden" name="user_id" id="rm_uid">
        <div style="padding:.7rem .95rem;background:var(--surface-2);border:1px solid var(--bd);border-radius:9px;margin-bottom:1rem;display:flex;align-items:center;gap:.6rem">
            <i class="fas fa-user" style="color:var(--li);font-size:.85rem"></i>
            <div style="flex:1;font-weight:600;color:var(--tx);font-size:.86rem" id="rm_name">—</div>
            <span class="badge badge-soft" style="font-size:.62rem"><span id="rm_current">—</span></span>
        </div>
        <div class="form-group">
            <label class="form-label form-label-req">Nouveau rôle système</label>
            <select name="new_role" id="rm_role" class="form-select" required>
                <?php foreach ($AVAILABLE_ROLES as $rk=>$rv): ?>
                <option value="<?php echo htmlspecialchars($rk); ?>"><?php echo htmlspecialchars($rv['lbl']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-hint"><i class="fas fa-shield-alt"></i> Le rôle système contrôle l'accès aux fonctionnalités globales (helper, staff, etc.).</div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="Company.closeModal('roleModal')">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
    </div>
    </form>
</div>
</div>
<?php endif; ?>

<script>
function filterEmployees() {
    const q = (document.getElementById('empSearch')?.value || '').toLowerCase().trim();
    const fg = document.getElementById('filterGrade')?.value || '';
    const fr = document.getElementById('filterRole')?.value || '';
    const cards = document.querySelectorAll('#empGrid .emp-card');
    let visible = 0;
    cards.forEach(c => {
        const okQ = !q || c.dataset.name.includes(q);
        const okG = !fg || c.dataset.grade === fg;
        const okR = !fr || c.dataset.role === fr;
        const show = okQ && okG && okR;
        c.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const empty = document.getElementById('emptyFilter');
    if (empty) empty.style.display = (visible === 0 && cards.length > 0) ? '' : 'none';
}

function rcFilter(q) {
    const v = q.toLowerCase().trim();
    let n = 0;
    document.querySelectorAll('.rc-item').forEach(card => {
        const m = !v || card.dataset.s.includes(v);
        card.style.display = m ? 'flex' : 'none';
        if (m) n++;
    });
    const c = document.getElementById('rc_count');
    if (c) c.textContent = n;
}

function rcSelect(lbl) {
    document.querySelectorAll('.rc-item').forEach(c => {
        c.style.background = 'var(--surface-2)';
        c.style.borderColor = 'var(--bd)';
        c.style.boxShadow = '';
        const chk = c.querySelector('.rc-check');
        if (chk) { chk.style.borderColor = 'var(--bd-h)'; chk.style.background = 'transparent'; }
        const ico = c.querySelector('.rc-check i');
        if (ico) ico.style.opacity = '0';
    });
    lbl.style.background = 'var(--li-soft)';
    lbl.style.borderColor = 'var(--li)';
    lbl.style.boxShadow = '0 0 0 2px var(--li-soft)';
    const chk = lbl.querySelector('.rc-check');
    if (chk) { chk.style.borderColor = 'var(--li)'; chk.style.background = 'var(--li)'; }
    const ico = lbl.querySelector('.rc-check i');
    if (ico) ico.style.opacity = '1';
    const radio = lbl.querySelector('input[type=radio]');
    if (radio) radio.checked = true;

    const nameEl = lbl.querySelector('div > div:first-child');
    const name = nameEl ? nameEl.textContent.trim() : '';
    const initial = name.charAt(0).toUpperCase();

    const preview = document.getElementById('rc_preview');
    if (preview) {
        preview.style.display = 'flex';
        document.getElementById('rc_prev_av').textContent = initial;
        document.getElementById('rc_prev_name').textContent = name;
    }
    const btn = document.getElementById('rc_submit');
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; }
}

function rcGradeSelect(lbl) {
    if (lbl.dataset.blocked == '1') return;
    document.querySelectorAll('#rc_grade_btns .rc-grade-btn').forEach(l => {
        l.classList.remove('on');
        l.style.background = 'var(--surface-2)';
        l.style.borderColor = 'var(--bd)';
        l.style.color = 'var(--tx-3)';
    });
    lbl.classList.add('on');
    lbl.style.background = 'var(--li-soft)';
    lbl.style.borderColor = 'var(--li)';
    lbl.style.color = 'var(--li)';
    const radio = lbl.querySelector('input[type=radio]');
    if (radio) radio.checked = true;
}

function openGradeModal(id, name, currentGrade) {
    document.getElementById('gm_uid').value = id;
    document.getElementById('gm_name').textContent = name;
    document.getElementById('gm_current').textContent = currentGrade || '—';
    const sel = document.getElementById('gm_grade');
    if (sel && currentGrade) {
        const opt = Array.from(sel.options).find(o => o.value === currentGrade && !o.disabled);
        if (opt) sel.value = currentGrade;
    }
    Company.openModal('gradeModal');
}

function openRoleModal(id, name, currentRole) {
    const uid = document.getElementById('rm_uid');
    if (!uid) return;
    uid.value = id;
    document.getElementById('rm_name').textContent = name;
    document.getElementById('rm_current').textContent = currentRole || '—';
    const sel = document.getElementById('rm_role');
    if (sel && currentRole) sel.value = currentRole;
    Company.openModal('roleModal');
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#rc_grade_btns .rc-grade-btn.on').forEach(l => {
        l.style.background = 'var(--li-soft)';
        l.style.borderColor = 'var(--li)';
        l.style.color = 'var(--li)';
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
