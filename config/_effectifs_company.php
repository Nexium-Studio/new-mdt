<?php
/**
 * config/_effectifs_company.php
 * Template générique pour toutes les pages effectifs des compagnies.
 * Variables attendues AVANT l'inclusion :
 *   $companyName    : string  — ex: 'LS Custom'
 *   $companyColor   : string  — ex: '#8b5cf6'
 *   $companyFaction : string  — ex: 'LS_CUSTOM'
 *   $companyIcon    : string  — classe Font Awesome partielle, ex: 'toolbox'
 *   $companyPath    : string  — ex: 'ls-custom'  (nom du dossier pages/companies/)
 *   $gradeFallback  : array   — grades hardcodés si la DB est vide
 */

if (!isset($companyName, $companyColor, $companyFaction)) {
    die('Variables compagnie manquantes.');
}

if (!isset($companyHeaderInclude)) {
    if (!isset($companyPath)) {
        die('Variables compagnie : définir $companyPath ou $companyHeaderInclude.');
    }
    $companyHeaderInclude = __DIR__ . '/../pages/companies/' . $companyPath . '/includes/header.php';
}
if (!isset($companyFooterInclude)) {
    if (!isset($companyPath)) {
        die('Variables compagnie : définir $companyPath ou $companyFooterInclude.');
    }
    $companyFooterInclude = __DIR__ . '/../pages/companies/' . $companyPath . '/includes/footer.php';
}

requireLogin();

if ($_SESSION['user_faction'] !== $companyFaction &&
    !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']) &&
    !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Effectifs';
$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);
$msg = ''; $msgType = '';

// ── Grades depuis faction_grades (DB) ────────────────────────────────────────
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
} catch(Exception $e) {}

// Fallback si DB vide
if (empty($GRADES) && !empty($gradeFallback)) {
    $GRADES = $gradeFallback;
}

$GRADE_NAMES = array_column($GRADES, 'name');
$RLEVEL      = [];
$GRADE_MAP   = [];
foreach ($GRADES as $idx => $g) {
    $RLEVEL[$g['name']]     = count($GRADES) - $idx;
    $GRADE_MAP[$g['name']]  = $g;
}

// ── Grade de l'utilisateur connecté ─────────────────────────────────────────
$myGrade = '';
try {
    $ms = $db->prepare("SELECT faction_rank FROM users WHERE id=? LIMIT 1");
    $ms->execute([$uid]);
    $myGrade = $ms->fetchColumn() ?: '';
} catch(Exception $e) {}
$myLevel     = $RLEVEL[$myGrade] ?? 0;
$isStaff     = hasRole(['*','Administrateur','Développeur','Staff']);
$myGradeData = $GRADE_MAP[$myGrade] ?? null;

// Permissions — système RBAC granulaire centralisé
require_once ROOT_PATH . '/includes/faction_acl.php';
if (!faction_acl_can($db, $uid, $companyFaction, 'effectifs.view')) faction_acl_deny();
$canRecruit = faction_acl_can($db, $uid, $companyFaction, 'effectifs.recruit');
$canFire    = faction_acl_can($db, $uid, $companyFaction, 'effectifs.fire');
$canEdit    = faction_acl_can($db, $uid, $companyFaction, 'effectifs.edit_grade');

// ── Helper ───────────────────────────────────────────────────────────────────
if (!function_exists('canActOnAgent')) {
    function canActOnAgent(int $targetLevel, int $myLevel, bool $isStaff): bool {
        return $isStaff || ($myLevel > $targetLevel);
    }
}

// ══ POST ACTIONS ═════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Recruter ──────────────────────────────────────────────────────────────
    if ($action === 'recruit' && $canRecruit) {
        $targetId  = intval($_POST['user_id'] ?? 0);
        $initGrade = trim($_POST['grade'] ?? ($GRADE_NAMES[count($GRADE_NAMES)-1] ?? ''));
        try {
            // Uniquement citoyens sans faction
            $chk = $db->prepare(
                "SELECT u.id, u.username, p.firstname AS profile_firstname, p.lastname AS profile_lastname
                 FROM users u
                 LEFT JOIN profiles p ON p.id = u.profile_id
                 WHERE u.id=? AND u.role='Citoyen'
                 AND (u.faction='NONE' OR u.faction IS NULL OR u.faction='')
                 AND u.is_active=1 LIMIT 1"
            );
            $chk->execute([$targetId]);
            $target = $chk->fetch();
            if (!$target) throw new Exception("Utilisateur introuvable, déjà en faction, ou pas Citoyen.");
            if (!in_array($initGrade, $GRADE_NAMES)) throw new Exception("Grade invalide.");
            $db->prepare("UPDATE users SET faction=?, faction_rank=?, faction_grade=? WHERE id=?")
               ->execute([$companyFaction, $initGrade, $initGrade, $targetId]);
            $tDisp = mdt_rp_display_name($target['profile_firstname'] ?? null, $target['profile_lastname'] ?? null, (string) $target['username']);
            $msg     = htmlspecialchars($tDisp) . ' recruté(e) au grade ' . htmlspecialchars($initGrade) . '.';
            $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
    }

    // ── Changer de grade ──────────────────────────────────────────────────────
    if ($action === 'change_grade' && $canRecruit) {
        $targetId = intval($_POST['user_id'] ?? 0);
        $newGrade = trim($_POST['new_grade'] ?? '');
        try {
            $gs2 = $db->prepare(
                "SELECT u.faction_rank, u.username, p.firstname AS profile_firstname, p.lastname AS profile_lastname
                 FROM users u
                 LEFT JOIN profiles p ON p.id = u.profile_id
                 WHERE u.id=? AND u.faction=? LIMIT 1"
            );
            $gs2->execute([$targetId, $companyFaction]);
            $tRow = $gs2->fetch();
            if (!$tRow) throw new Exception("Employé introuvable.");
            $targetLevel = $RLEVEL[$tRow['faction_rank'] ?? ''] ?? 0;
            if (!$isStaff && $myLevel <= $targetLevel) {
                throw new Exception("Vous ne pouvez pas modifier le grade d'un employé de grade supérieur ou égal au vôtre.");
            }
            if (!in_array($newGrade, $GRADE_NAMES)) throw new Exception("Grade invalide.");
            $db->prepare("UPDATE users SET faction_rank=?, faction_grade=? WHERE id=? AND faction=?")
               ->execute([$newGrade, $newGrade, $targetId, $companyFaction]);
            $tDisp2 = mdt_rp_display_name($tRow['profile_firstname'] ?? null, $tRow['profile_lastname'] ?? null, (string) $tRow['username']);
            $msg     = htmlspecialchars($tDisp2) . ' → ' . htmlspecialchars($newGrade) . '.';
            $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
    }

    // ── Licencier ─────────────────────────────────────────────────────────────
    if ($action === 'fire' && $canFire) {
        $targetId = intval($_POST['user_id'] ?? 0);
        try {
            $gs3 = $db->prepare(
                "SELECT u.faction_rank, u.username, p.firstname AS profile_firstname, p.lastname AS profile_lastname
                 FROM users u
                 LEFT JOIN profiles p ON p.id = u.profile_id
                 WHERE u.id=? AND u.faction=? LIMIT 1"
            );
            $gs3->execute([$targetId, $companyFaction]);
            $tRow3 = $gs3->fetch();
            if (!$tRow3) throw new Exception("Employé introuvable.");
            $targetLevel3 = $RLEVEL[$tRow3['faction_rank'] ?? ''] ?? 0;
            if (!$isStaff && $myLevel <= $targetLevel3) {
                throw new Exception("Vous ne pouvez pas licencier un employé de grade supérieur ou égal au vôtre.");
            }
            $db->prepare("UPDATE users SET faction='NONE', faction_rank=NULL, faction_grade=NULL WHERE id=?")
               ->execute([$targetId]);
            $tDisp3 = mdt_rp_display_name($tRow3['profile_firstname'] ?? null, $tRow3['profile_lastname'] ?? null, (string) $tRow3['username']);
            $msg     = htmlspecialchars($tDisp3) . ' licencié(e).';
            $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
    }
}

// ══ DATA ═════════════════════════════════════════════════════════════════════

$employees = [];
try {
    $eq = $db->prepare(
        "SELECT u.id, u.username, u.faction_rank, u.faction_grade, u.avatar, u.created_at,
                p.firstname AS profile_firstname, p.lastname AS profile_lastname
         FROM users u
         LEFT JOIN profiles p ON p.id = u.profile_id
         WHERE u.faction=? AND u.is_active=1
         ORDER BY COALESCE(NULLIF(u.faction_rank,''),'zzz'), u.username ASC"
    );
    $eq->execute([$companyFaction]);
    $employees = $eq->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$citizens = [];
if ($canRecruit) {
    try {
        $cq = $db->prepare(
            "SELECT u.id, u.username, p.firstname AS profile_firstname, p.lastname AS profile_lastname
             FROM users u
             LEFT JOIN profiles p ON p.id = u.profile_id
             WHERE u.role='Citoyen'
             AND (u.faction='NONE' OR u.faction IS NULL OR u.faction='')
             AND u.is_active=1
             ORDER BY u.username ASC LIMIT 500"
        );
        $cq->execute();
        $citizens = $cq->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

$totalEmp = count($employees);

include $companyHeaderInclude;
?>
<style>
:root { --cc: <?php echo $companyColor; ?>; }

.modal-ov {
    position: fixed; inset: 0;
    background: rgba(2,2,10,.93);
    backdrop-filter: blur(14px);
    display: none; align-items: center; justify-content: center;
    z-index: 9999; padding: 1.5rem;
}
.modal-ov:not(.hidden) { display: flex; }
.modal-ov .modal {
    background: linear-gradient(155deg, #0a0a18 0%, #08080f 60%, #0a0810 100%);
    border: 1px solid color-mix(in srgb, var(--cc) 35%, transparent);
    border-radius: 18px;
    box-shadow: 0 25px 80px rgba(0,0,0,.85), 0 0 120px color-mix(in srgb, var(--cc) 8%, transparent);
    width: 100%; max-width: 680px; max-height: 88vh;
    display: flex; flex-direction: column; overflow: hidden;
    animation: modalIn .22s cubic-bezier(.34,1.4,.64,1);
}
@keyframes modalIn {
    from { opacity:0; transform: scale(.94) translateY(14px); }
    to   { opacity:1; transform: scale(1) translateY(0); }
}
.modal-cl {
    width:34px; height:34px; border-radius:9px;
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2);
    color:#f87171; font-size:1rem; cursor:pointer;
    display:flex; align-items:center; justify-content:center; transition:all .18s; flex-shrink:0;
}
.modal-cl:hover { background:rgba(239,68,68,.22); border-color:rgba(239,68,68,.5); transform:rotate(90deg) scale(1.05); }
.modal-ov .modal-hd { padding:1.25rem 1.5rem; display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
.modal-ov .modal-bd { flex:1; overflow-y:auto; }
.modal-ov .modal-ft { display:flex; justify-content:flex-end; align-items:center; gap:.65rem; padding:1rem 1.5rem; flex-shrink:0; }

.emp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(290px,1fr)); gap:1rem; }
.emp-card {
    background: color-mix(in srgb, var(--cc) 4%, #0a0a14);
    border: 1px solid color-mix(in srgb, var(--cc) 14%, transparent);
    border-radius:13px; padding:1.3rem; transition:all .2s; position:relative; overflow:hidden;
}
.emp-card::before {
    content:''; position:absolute; left:0; top:0; bottom:0; width:3px;
    background: var(--cc); opacity:.55;
}
.emp-card:hover { border-color: color-mix(in srgb, var(--cc) 40%, transparent); transform:translateY(-2px); }
.emp-avatar {
    width:46px; height:46px; border-radius:11px;
    background: color-mix(in srgb, var(--cc) 18%, transparent);
    border: 1px solid color-mix(in srgb, var(--cc) 30%, transparent);
    display:flex; align-items:center; justify-content:center;
    font-size:1.1rem; font-weight:700; color:var(--cc); flex-shrink:0; overflow:hidden;
}
.emp-name { font-weight:700; font-size:.9rem; color:#f1f5f9; }
.emp-grade-text { font-size:.72rem; color:#94a3b8; margin-top:.15rem; }
.grade-badge {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.22rem .65rem; border-radius:20px; font-size:.68rem; font-weight:700;
    border: 1px solid color-mix(in srgb, var(--cc) 35%, transparent);
    background: color-mix(in srgb, var(--cc) 12%, transparent);
    color: color-mix(in srgb, var(--cc) 80%, #fff);
}
.grade-badge.top {
    border-color:rgba(245,158,11,.45); background:rgba(245,158,11,.12); color:#fde68a;
}
.locked-btn { opacity:.35; cursor:not-allowed !important; }
.emp-actions { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.9rem; padding-top:.75rem; border-top:1px solid rgba(255,255,255,.05); }

.form-input, .form-select {
    width:100%; padding:.58rem .85rem;
    background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1);
    border-radius:8px; color:#f1f5f9; font-size:.85rem; outline:none; transition:all .17s;
}
.form-input:focus, .form-select:focus {
    border-color: color-mix(in srgb, var(--cc) 60%, transparent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--cc) 12%, transparent);
}
.form-input::placeholder { color:#475569; }
.form-label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#64748b; margin-bottom:.45rem; }
.btn { display:inline-flex; align-items:center; gap:.4rem; padding:.52rem .95rem; border-radius:8px; font-size:.8rem; font-weight:600; border:none; cursor:pointer; transition:all .17s; text-decoration:none; white-space:nowrap; }
.btn-primary { background: var(--cc); color:#fff; }
.btn-primary:hover { filter:brightness(1.1); transform:translateY(-1px); }
.btn-secondary { background:rgba(255,255,255,.07); color:#94a3b8; border:1px solid rgba(255,255,255,.1); }
.btn-secondary:hover { background:rgba(255,255,255,.12); color:#f1f5f9; }
.btn-danger { background:rgba(239,68,68,.12); color:#fca5a5; border:1px solid rgba(239,68,68,.2); }
.btn-danger:hover { background:rgba(239,68,68,.2); }
.btn-ghost { background:transparent; color:#64748b; border:1px solid rgba(255,255,255,.1); }
.btn-ghost:hover { background:rgba(255,255,255,.06); color:#94a3b8; }
.btn-xs { padding:.22rem .55rem; font-size:.72rem; border-radius:6px; }
.btn-sm { padding:.38rem .75rem; font-size:.78rem; }
.alert { padding:.78rem 1.05rem; border-radius:9px; margin-bottom:1.2rem; display:flex; align-items:center; gap:.6rem; font-size:.82rem; font-weight:500; }
.alert-success { background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.22); color:#6ee7b7; }
.alert-error { background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.22); color:#fca5a5; }
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:1rem">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- ── Toolbar ──────────────────────────────────────────────────────────────── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;align-items:center;gap:1rem">
        <div style="width:42px;height:42px;border-radius:10px;background:color-mix(in srgb,var(--cc) 18%,transparent);display:flex;align-items:center;justify-content:center;color:var(--cc);font-size:1.1rem">
            <i class="fas fa-users-gear"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:1rem;color:#f9fafb">Effectifs — <?php echo htmlspecialchars($companyName); ?></div>
            <div style="font-size:.72rem;color:#64748b">
                <strong style="color:var(--cc)"><?php echo $totalEmp; ?></strong> employé<?php echo $totalEmp > 1 ? 's' : ''; ?>
                <?php if ($myGrade): ?> · Grade actuel : <strong style="color:color-mix(in srgb,var(--cc) 80%,#fff)"><?php echo htmlspecialchars($myGrade); ?></strong><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($canRecruit): ?>
    <button onclick="CO.openModal('recruitModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-user-plus"></i> Recruter
    </button>
    <?php endif; ?>
</div>

<!-- ── Compteur par grade ───────────────────────────────────────────────────── -->
<?php
$gradeCount = [];
foreach ($employees as $e) {
    $g = $e['faction_rank'] ?: '—';
    $gradeCount[$g] = ($gradeCount[$g] ?? 0) + 1;
}
?>
<div style="display:flex;flex-wrap:wrap;gap:.45rem;margin-bottom:1.5rem">
    <?php foreach ($GRADES as $g):
        $cnt   = $gradeCount[$g['name']] ?? 0;
        $isTop = ($g['rank_order'] ?? 99) <= 2;
    ?>
    <span class="grade-badge <?php echo $isTop ? 'top' : ''; ?>">
        <?php echo htmlspecialchars($g['name']); ?>
        <span style="font-weight:400;opacity:.65;">(<?php echo $cnt; ?>)</span>
    </span>
    <?php endforeach; ?>
</div>

<!-- ── Grille employés ─────────────────────────────────────────────────────── -->
<?php if (empty($employees)): ?>
<div style="text-align:center;padding:4rem;background:color-mix(in srgb,var(--cc) 4%,#0a0a14);border:1px solid color-mix(in srgb,var(--cc) 12%,transparent);border-radius:13px;color:#475569">
    <i class="fas fa-users-slash" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
    Aucun employé pour le moment.
</div>
<?php else: ?>
<div class="emp-grid">
<?php foreach ($employees as $emp):
    $empGrade  = $emp['faction_rank'] ?? '';
    $empLevel  = $RLEVEL[$empGrade] ?? 0;
    $empGData  = $GRADE_MAP[$empGrade] ?? null;
    $canActEmp = canActOnAgent($empLevel, $myLevel, $isStaff);
    $isMe      = ((int)$emp['id'] === $uid);
    $empDisp   = mdt_rp_display_name($emp['profile_firstname'] ?? null, $emp['profile_lastname'] ?? null, (string) $emp['username']);
    $initial   = mdt_rp_initials($emp['profile_firstname'] ?? null, $emp['profile_lastname'] ?? null, (string) $emp['username']);
    $isTopGrade= isset($empGData) && ($empGData['rank_order'] ?? 99) <= 2;
?>
<div class="emp-card">
    <div style="display:flex;align-items:center;gap:.9rem;margin-bottom:.8rem">
        <div class="emp-avatar">
            <?php if (!empty($emp['avatar'])): ?>
            <img src="<?php echo htmlspecialchars($emp['avatar']); ?>"
                 style="width:100%;height:100%;object-fit:cover;border-radius:11px"
                 onerror="this.parentNode.textContent='<?php echo $initial; ?>'">
            <?php else: echo $initial; endif; ?>
        </div>
        <div style="flex:1;min-width:0">
            <div class="emp-name">
                <?php echo htmlspecialchars($empDisp); ?>
                <?php if ($isMe): ?>
                <span style="font-size:.58rem;background:color-mix(in srgb,var(--cc) 20%,transparent);color:color-mix(in srgb,var(--cc) 80%,#fff);padding:.1rem .4rem;border-radius:4px;font-weight:700">MOI</span>
                <?php endif; ?>
            </div>
            <div class="emp-grade-text">
                <?php if ($empGrade): ?>
                <span class="grade-badge <?php echo $isTopGrade ? 'top' : ''; ?>" style="font-size:.62rem">
                    <?php echo htmlspecialchars($empGrade); ?>
                </span>
                <?php else: ?><span style="color:#475569;font-style:italic">Grade non défini</span><?php endif; ?>
            </div>
        </div>
        <?php if (!$canActEmp && !$isMe): ?>
        <span title="Grade supérieur ou égal au vôtre" style="color:#64748b;font-size:.75rem"><i class="fas fa-lock"></i></span>
        <?php endif; ?>
    </div>

    <?php if ($canRecruit || $canFire): ?>
    <div class="emp-actions">
        <?php if ($canRecruit): ?>
        <?php if ($canActEmp && !$isMe): ?>
        <button onclick="openGradeModal(<?php echo (int)$emp['id']; ?>,<?php echo htmlspecialchars(json_encode($empDisp, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>,'<?php echo addslashes($empGrade); ?>')"
                class="btn btn-secondary btn-xs">
            <i class="fas fa-arrow-up-down"></i> Grade
        </button>
        <?php else: ?>
        <button class="btn btn-secondary btn-xs locked-btn" disabled
                title="<?php echo $isMe ? 'Vous ne pouvez pas modifier votre propre grade' : 'Grade supérieur au vôtre'; ?>">
            <i class="fas fa-lock"></i> Grade
        </button>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($canFire && !$isMe): ?>
        <?php if ($canActEmp): ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Licencier <?php echo addslashes(htmlspecialchars($empDisp)); ?> ?')">
            <input type="hidden" name="action"  value="fire">
            <input type="hidden" name="user_id" value="<?php echo (int)$emp['id']; ?>">
            <button type="submit" class="btn btn-danger btn-xs">
                <i class="fas fa-user-slash"></i> Licencier
            </button>
        </form>
        <?php else: ?>
        <button class="btn btn-danger btn-xs locked-btn" disabled title="Grade supérieur au vôtre">
            <i class="fas fa-lock"></i> Licencier
        </button>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── MODAL RECRUTER ──────────────────────────────────────────────────────── -->
<?php if ($canRecruit): ?>
<div id="recruitModal" class="modal-ov hidden">
<div class="modal" style="max-width:680px;width:96vw">
    <div class="modal-hd" style="background:color-mix(in srgb,var(--cc) 8%,transparent);border-bottom:1px solid color-mix(in srgb,var(--cc) 20%,transparent);">
        <div style="display:flex;align-items:center;gap:.85rem">
            <div style="width:38px;height:38px;border-radius:10px;background:color-mix(in srgb,var(--cc) 20%,transparent);border:1px solid color-mix(in srgb,var(--cc) 40%,transparent);display:flex;align-items:center;justify-content:center;color:var(--cc);flex-shrink:0">
                <i class="fas fa-user-plus"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:.95rem;color:#f9fafb">Recruter un nouvel employé</div>
                <div style="font-size:.65rem;color:#64748b;margin-top:.06rem"><?php echo htmlspecialchars($companyName); ?> — <?php echo count($citizens); ?> citoyen(s) disponible(s)</div>
            </div>
        </div>
        <button class="modal-cl" onclick="CO.closeModal('recruitModal')"><i class="fas fa-xmark"></i></button>
    </div>

    <form method="POST">
    <input type="hidden" name="action" value="recruit">
    <div class="modal-bd" style="padding:0;display:flex;flex-direction:column;max-height:75vh">

        <!-- Recherche -->
        <div style="padding:1.1rem 1.4rem 0">
            <div style="position:relative;margin-bottom:.85rem">
                <i class="fas fa-search" style="position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:#4c4c6b;font-size:.75rem;pointer-events:none;z-index:1"></i>
                <input type="text" id="rc_search" class="form-input"
                       placeholder="Rechercher par nom..."
                       oninput="rcFilter(this.value)" autocomplete="off"
                       style="padding-left:2.4rem;background:color-mix(in srgb,var(--cc) 6%,transparent);border-color:color-mix(in srgb,var(--cc) 20%,transparent);font-size:.85rem">
            </div>
            <div style="font-size:.7rem;color:#64748b;margin-bottom:.55rem">
                <i class="fas fa-users" style="margin-right:.3rem;opacity:.5"></i>
                <span id="rc_count_num"><?php echo count($citizens); ?></span> citoyen(s) disponible(s)
            </div>
        </div>

        <!-- Liste citoyens -->
        <div id="rc_list" style="overflow-y:auto;max-height:280px;padding:0 1.4rem;display:flex;flex-direction:column;gap:.3rem">
            <?php if (empty($citizens)): ?>
            <div style="text-align:center;padding:2.5rem 1rem;color:#475569">
                <i class="fas fa-user-slash" style="font-size:1.8rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
                <div style="font-size:.82rem">Aucun citoyen disponible</div>
                <div style="font-size:.72rem;margin-top:.25rem;opacity:.6">Tous les utilisateurs ont déjà une faction ou ne sont pas Citoyens.</div>
            </div>
            <?php else: foreach ($citizens as $c2):
                $c2Disp = mdt_rp_display_name($c2['profile_firstname'] ?? null, $c2['profile_lastname'] ?? null, (string) $c2['username']);
                $ci = mdt_rp_initials($c2['profile_firstname'] ?? null, $c2['profile_lastname'] ?? null, (string) $c2['username']);
                $c2Search = strtolower($c2['username'] . ' ' . $c2Disp);
            ?>
            <label class="rc-card" data-s="<?php echo htmlspecialchars($c2Search, ENT_QUOTES, 'UTF-8'); ?>"
                   style="display:flex;align-items:center;gap:.8rem;padding:.65rem .85rem;background:rgba(255,255,255,.02);border:1.5px solid rgba(255,255,255,.06);border-radius:10px;cursor:pointer;transition:all .15s;user-select:none"
                   onmouseover="this.style.background='color-mix(in srgb,var(--cc) 8%,transparent)';this.style.borderColor='color-mix(in srgb,var(--cc) 30%,transparent)'"
                   onmouseout="if(!this.querySelector('input').checked){this.style.background='rgba(255,255,255,.02)';this.style.borderColor='rgba(255,255,255,.06)'}"
                   onclick="rcSelect(this)">
                <input type="radio" name="user_id" value="<?php echo (int)$c2['id']; ?>" style="display:none" required>
                <div class="rc-avatar" style="width:36px;height:36px;border-radius:9px;background:color-mix(in srgb,var(--cc) 15%,transparent);border:1px solid color-mix(in srgb,var(--cc) 25%,transparent);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--cc);font-size:.85rem;flex-shrink:0">
                    <?php echo $ci; ?>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600;font-size:.84rem;color:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($c2Disp); ?></div>
                    <div style="font-size:.63rem;color:#475569;margin-top:.1rem;display:flex;align-items:center;gap:.35rem">
                        <span style="width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block"></span>
                        Citoyen — Sans faction
                    </div>
                </div>
                <div class="rc-check" style="width:20px;height:20px;border-radius:50%;border:2px solid color-mix(in srgb,var(--cc) 25%,transparent);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .15s">
                    <i class="fas fa-check" style="font-size:.6rem;color:var(--cc);opacity:0;transition:opacity .15s"></i>
                </div>
            </label>
            <?php endforeach; endif; ?>
        </div>

        <!-- Aperçu + choix grade -->
        <div style="border-top:1px solid rgba(255,255,255,.06);margin-top:.85rem;padding:1.1rem 1.4rem">
            <div id="rc_preview" style="display:none;align-items:center;gap:.75rem;padding:.7rem .9rem;background:color-mix(in srgb,var(--cc) 7%,transparent);border:1px solid color-mix(in srgb,var(--cc) 22%,transparent);border-radius:9px;margin-bottom:.9rem">
                <div id="rc_prev_ava" style="width:34px;height:34px;border-radius:8px;background:color-mix(in srgb,var(--cc) 25%,transparent);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--cc);font-size:.85rem;flex-shrink:0"></div>
                <div style="flex:1">
                    <div style="font-size:.78rem;font-weight:600;color:#f1f5f9" id="rc_prev_name">—</div>
                    <div style="font-size:.63rem;color:var(--cc);margin-top:.08rem">Sélectionné pour recrutement</div>
                </div>
                <i class="fas fa-check-circle" style="color:var(--cc);font-size:.9rem"></i>
            </div>
            <!-- Grade d'entrée -->
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-medal" style="color:var(--cc);font-size:.7rem;margin-right:.3rem"></i>
                    Grade d'entrée
                </label>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem" id="rc_grade_btns">
                    <?php foreach (array_reverse($GRADES) as $g):
                        $isDefault = ($g['rank_order'] === max(array_column($GRADES,'rank_order')));
                    ?>
                    <label style="display:inline-flex;align-items:center;gap:.4rem;padding:.38rem .8rem;border-radius:7px;cursor:pointer;transition:all .15s;font-size:.76rem;font-weight:600;border:1.5px solid <?php echo $isDefault ? 'color-mix(in srgb,var(--cc) 60%,transparent)' : 'rgba(255,255,255,.08)'; ?>;background:<?php echo $isDefault ? 'color-mix(in srgb,var(--cc) 18%,transparent)' : 'rgba(255,255,255,.03)'; ?>;color:<?php echo $isDefault ? 'color-mix(in srgb,var(--cc) 80%,#fff)' : '#64748b'; ?>"
                          onclick="rcGradeSelect(this)">
                        <input type="radio" name="grade" value="<?php echo htmlspecialchars($g['name']); ?>" <?php echo $isDefault ? 'checked' : ''; ?> style="display:none">
                        <?php echo htmlspecialchars($g['name']); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-ft" style="border-top:1px solid color-mix(in srgb,var(--cc) 15%,transparent);background:color-mix(in srgb,var(--cc) 4%,transparent)">
        <button type="button" class="btn btn-ghost" onclick="CO.closeModal('recruitModal')">Annuler</button>
        <button type="submit" id="rc_submit" class="btn btn-primary" disabled style="opacity:.5;cursor:not-allowed">
            <i class="fas fa-user-plus"></i> Confirmer le recrutement
        </button>
    </div>
    </form>
</div>
</div>

<!-- ── MODAL CHANGER GRADE ─────────────────────────────────────────────────── -->
<?php if ($canRecruit): ?>
<div id="gradeModal" class="modal-ov hidden">
<div class="modal modal-sm" style="max-width:440px">
    <div class="modal-hd">
        <h2 style="font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.6rem">
            <i class="fas fa-arrow-up-down" style="color:var(--cc)"></i> Modifier le grade
        </h2>
        <button class="modal-cl" onclick="CO.closeModal('gradeModal')"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="modal-bd" style="padding:1.4rem">
        <input type="hidden" name="action"  value="change_grade">
        <input type="hidden" name="user_id" id="gm_uid">
        <div style="font-size:.9rem;font-weight:700;color:#f9fafb;margin-bottom:.9rem;padding:.55rem .85rem;background:color-mix(in srgb,var(--cc) 8%,transparent);border:1px solid color-mix(in srgb,var(--cc) 25%,transparent);border-radius:9px;display:flex;align-items:center;gap:.6rem">
            <i class="fas fa-user" style="color:color-mix(in srgb,var(--cc) 80%,#fff);font-size:.85rem"></i>
            <span id="gm_name">—</span>
            <span style="font-size:.7rem;color:#64748b;margin-left:.3rem">Grade actuel :</span>
            <span id="gm_current" style="font-size:.7rem;color:var(--cc);font-weight:600"></span>
        </div>
        <div class="form-group">
            <label class="form-label">Nouveau grade *</label>
            <select name="new_grade" id="gm_grade" class="form-select" required>
                <?php foreach (array_reverse($GRADES) as $g):
                    $gLvl = $RLEVEL[$g['name']] ?? 0;
                    $disabled = (!$isStaff && $gLvl >= $myLevel) ? 'disabled style="display:none"' : '';
                ?>
                <option value="<?php echo htmlspecialchars($g['name']); ?>" <?php echo $disabled; ?>>
                    <?php echo htmlspecialchars($g['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isStaff && $myLevel < count($GRADES)): ?>
            <div style="font-size:.68rem;color:#64748b;margin-top:.3rem">
                <i class="fas fa-info-circle" style="margin-right:.2rem;opacity:.6"></i>
                Vous ne pouvez attribuer que des grades inférieurs au vôtre.
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="modal-ft">
        <button type="button" class="btn btn-ghost" onclick="CO.closeModal('gradeModal')">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
    </div>
    </form>
</div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
const CO = {
    openModal(id)  { const m=document.getElementById(id); if(m){m.classList.remove('hidden');document.body.style.overflow='hidden';} },
    closeModal(id) { const m=document.getElementById(id); if(m){m.classList.add('hidden');document.body.style.overflow='';} }
};
document.querySelectorAll('.modal-ov').forEach(ov => ov.addEventListener('click', e => {
    if (e.target === ov) { ov.classList.add('hidden'); document.body.style.overflow = ''; }
}));
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-ov:not(.hidden)').forEach(m => { m.classList.add('hidden'); document.body.style.overflow = ''; });
});

function rcFilter(q) {
    const v = q.toLowerCase().trim(); let n = 0;
    document.querySelectorAll('.rc-card').forEach(card => {
        const m = !v || card.dataset.s.includes(v);
        card.style.display = m ? '' : 'none'; if (m) n++;
    });
    document.getElementById('rc_count_num').textContent = n;
}

function rcSelect(lbl) {
    document.querySelectorAll('.rc-card').forEach(c => {
        c.style.background = 'rgba(255,255,255,.02)'; c.style.borderColor = 'rgba(255,255,255,.06)'; c.style.boxShadow = '';
        const chk = c.querySelector('.rc-check'); if (chk) { chk.style.borderColor = ''; chk.style.background = 'transparent'; }
        const ico = c.querySelector('.rc-check i'); if (ico) ico.style.opacity = '0';
    });
    const ccVal = getComputedStyle(document.documentElement).getPropertyValue('--cc').trim();
    lbl.style.background  = `color-mix(in srgb, ${ccVal} 12%, transparent)`;
    lbl.style.borderColor = `color-mix(in srgb, ${ccVal} 50%, transparent)`;
    lbl.style.boxShadow   = `0 0 0 2px color-mix(in srgb, ${ccVal} 15%, transparent)`;
    const chk = lbl.querySelector('.rc-check'); if (chk) { chk.style.borderColor = ccVal; chk.style.background = `color-mix(in srgb, ${ccVal} 20%, transparent)`; }
    const ico = lbl.querySelector('.rc-check i'); if (ico) ico.style.opacity = '1';

    const name = lbl.querySelector('div:nth-child(2) div').textContent.trim();
    const preview = document.getElementById('rc_preview');
    if (preview) {
        preview.style.display = 'flex';
        document.getElementById('rc_prev_ava').textContent  = name.charAt(0).toUpperCase();
        document.getElementById('rc_prev_name').textContent = name;
    }
    const btn = document.getElementById('rc_submit');
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; }
}

function rcGradeSelect(lbl) {
    document.querySelectorAll('#rc_grade_btns label').forEach(l => {
        l.style.background = 'rgba(255,255,255,.03)'; l.style.borderColor = 'rgba(255,255,255,.08)'; l.style.color = '#64748b';
    });
    const ccVal = getComputedStyle(document.documentElement).getPropertyValue('--cc').trim();
    lbl.style.background  = `color-mix(in srgb, ${ccVal} 18%, transparent)`;
    lbl.style.borderColor = `color-mix(in srgb, ${ccVal} 60%, transparent)`;
    lbl.style.color       = `color-mix(in srgb, ${ccVal} 80%, #fff)`;
}

function openGradeModal(id, name, currentGrade) {
    document.getElementById('gm_uid').value = id;
    document.getElementById('gm_name').textContent = name;
    document.getElementById('gm_current').textContent = currentGrade || '—';
    const sel = document.getElementById('gm_grade');
    if (sel && currentGrade) sel.value = currentGrade;
    CO.openModal('gradeModal');
}
</script>
<?php include $companyFooterInclude; ?>