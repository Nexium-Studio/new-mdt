<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Harmony Repair';
$companyColor   = '#64748b';
$companyFaction = 'HARMONY_REPAIR';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Effectifs';
$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);
$msg = ''; $msgType = '';

// ── Grades depuis faction_grades (rank_order ASC = plus haut grade en premier) ─
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
} catch(Exception $e){}

// Fallback
if (empty($GRADES)) {
    $GRADES = [
        ['name'=>'PDG',              'rank_order'=>1,'can_recruit'=>1,'is_owner'=>1,'is_ceo'=>0,'can_manage_finances'=>1,'can_manage_stock'=>1],
        ['name'=>'Co-PDG',           'rank_order'=>2,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>1,'can_manage_finances'=>1,'can_manage_stock'=>1],
        ['name'=>'DRH',              'rank_order'=>3,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
        ['name'=>'Mécanicien Expert','rank_order'=>4,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
        ['name'=>'Mécanicien',       'rank_order'=>5,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
        ['name'=>'Novice',           'rank_order'=>6,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
    ];
}

// RLEVEL : rank_order=1 (PDG) = niveau le plus élevé
$GRADE_NAMES = array_column($GRADES, 'name');
$RLEVEL = [];
$GRADE_MAP = [];
foreach ($GRADES as $idx => $g) {
    $RLEVEL[$g['name']] = count($GRADES) - $idx; // PDG = max, Novice = 1
    $GRADE_MAP[$g['name']] = $g;
}

// ── Grade de l'utilisateur connecté (depuis DB) ────────────────────────────
$myGrade = '';
try {
    $ms = $db->prepare("SELECT faction_rank FROM users WHERE id=? LIMIT 1");
    $ms->execute([$uid]); $myGrade = $ms->fetchColumn() ?: '';
} catch(Exception $e){}
$myLevel = $RLEVEL[$myGrade] ?? 0;

$isStaff    = hasRole(['*','Administrateur','Développeur','Staff']);
$myGradeData = $GRADE_MAP[$myGrade] ?? null;

// Permissions
$canRecruit = $isStaff || (!empty($myGradeData) && $myGradeData['can_recruit']);
$canFire    = $canRecruit; // même seuil : DRH+
$canView    = $canRecruit || $myLevel > 0;

// ── Helper : peut-on agir sur un agent donné ? ────────────────────────────
function canActOnAgent(int $targetLevel, int $myLevel, bool $isStaff): bool {
    return $isStaff || ($myLevel > $targetLevel && $targetLevel > 0) || ($myLevel > $targetLevel);
}

// ══ POST ACTIONS ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─ Recruter ─────────────────────────────────────────────────────────────
    if ($action === 'recruit' && $canRecruit) {
        $targetId   = intval($_POST['user_id']);
        $initGrade  = trim($_POST['grade'] ?? ($GRADE_NAMES[count($GRADE_NAMES)-1] ?? 'Novice'));
        try {
            // Vérifier que l'utilisateur est citoyen (pas déjà dans une faction)
            $chk = $db->prepare("SELECT id, username, role FROM users WHERE id=? AND (faction='NONE' OR faction IS NULL OR faction='') AND role='Citoyen' LIMIT 1");
            $chk->execute([$targetId]);
            $target = $chk->fetch();
            if (!$target) throw new Exception("Utilisateur introuvable ou déjà dans une faction.");
            $db->prepare("UPDATE users SET faction=?, faction_rank=?, faction_grade=? WHERE id=?")
               ->execute([$companyFaction, $initGrade, $initGrade, $targetId]);
            $msg = htmlspecialchars($target['username']).' recruté(e) au grade '.htmlspecialchars($initGrade).'.';
            $msgType = 'success';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
    }

    // ─ Changer de grade ──────────────────────────────────────────────────────
    if ($action === 'change_grade' && $canRecruit) {
        $targetId  = intval($_POST['user_id']);
        $newGrade  = trim($_POST['new_grade'] ?? '');
        try {
            // Récupérer le grade actuel de la cible
            $gs2 = $db->prepare("SELECT faction_rank, username FROM users WHERE id=? AND faction=? LIMIT 1");
            $gs2->execute([$targetId, $companyFaction]); $tRow = $gs2->fetch();
            if (!$tRow) throw new Exception("Employé introuvable.");
            $targetLevel = $RLEVEL[$tRow['faction_rank'] ?? ''] ?? 0;
            if (!$isStaff && $myLevel <= $targetLevel) {
                throw new Exception("Vous ne pouvez pas modifier le grade d'un employé de grade supérieur ou égal au vôtre.");
            }
            if (!in_array($newGrade, $GRADE_NAMES)) throw new Exception("Grade invalide.");
            $db->prepare("UPDATE users SET faction_rank=?, faction_grade=? WHERE id=? AND faction=?")
               ->execute([$newGrade, $newGrade, $targetId, $companyFaction]);
            $msg = htmlspecialchars($tRow['username']).' → '.htmlspecialchars($newGrade).'.';
            $msgType = 'success';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
    }

    // ─ Licencier ────────────────────────────────────────────────────────────
    if ($action === 'fire' && $canFire) {
        $targetId = intval($_POST['user_id']);
        try {
            $gs3 = $db->prepare("SELECT faction_rank, username FROM users WHERE id=? AND faction=? LIMIT 1");
            $gs3->execute([$targetId, $companyFaction]); $tRow3 = $gs3->fetch();
            if (!$tRow3) throw new Exception("Employé introuvable.");
            $targetLevel3 = $RLEVEL[$tRow3['faction_rank'] ?? ''] ?? 0;
            if (!$isStaff && $myLevel <= $targetLevel3) {
                throw new Exception("Vous ne pouvez pas licencier un employé de grade supérieur ou égal au vôtre.");
            }
            $db->prepare("UPDATE users SET faction='NONE', faction_rank=NULL, faction_grade=NULL WHERE id=?")
               ->execute([$targetId]);
            $msg = htmlspecialchars($tRow3['username']).' licencié(e).';
            $msgType = 'success';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
    }
}

// ══ DATA ════════════════════════════════════════════════════════════════════
$employees = [];
try {
    $eq = $db->prepare(
        "SELECT id, username, faction_rank, faction_grade, avatar, email, created_at
         FROM users WHERE faction=? AND is_active=1
         ORDER BY COALESCE(NULLIF(faction_rank,''),'zzz'), username ASC"
    );
    $eq->execute([$companyFaction]);
    $employees = $eq->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// Citoyens recrutables (role=Citoyen, pas de faction active)
$citizens = [];
if ($canRecruit) {
    try {
        $cq = $db->prepare(
            "SELECT id, username FROM users
             WHERE role='Citoyen' AND (faction='NONE' OR faction IS NULL OR faction='')
             AND is_active=1 ORDER BY username ASC LIMIT 500"
        );
        $cq->execute(); $citizens = $cq->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
}

$totalEmp = count($employees);

include __DIR__ . '/includes/header.php';
?>
<style>
:root{--cc:<?php echo $companyColor;?>;}

/* ── Modal overlay fix ── */
.modal-ov {
    position: fixed;
    inset: 0;
    background: rgba(2, 2, 10, 0.93);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1.5rem;
}
.modal-ov:not(.hidden) { display: flex; }
.modal-ov .modal {
    background: linear-gradient(155deg, #0f0a1f 0%, #080612 60%, #0a0816 100%);
    border: 1px solid rgba(100,116,139,.3);
    border-radius: 18px;
    box-shadow:
        0 0 0 1px rgba(100,116,139,.08),
        0 25px 80px rgba(0,0,0,.85),
        0 0 120px rgba(100,116,139,.06);
    width: 100%;
    max-width: 680px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalIn .22s cubic-bezier(.34,1.4,.64,1);
}
@keyframes modalIn {
    from { opacity:0; transform: scale(.94) translateY(14px); }
    to   { opacity:1; transform: scale(1) translateY(0); }
}
/* Close button */
.modal-cl {
    width: 34px; height: 34px;
    border-radius: 9px;
    background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.2);
    color: #f87171;
    font-size: 1rem;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .18s;
    flex-shrink: 0;
}
.modal-cl:hover {
    background: rgba(239,68,68,.22);
    border-color: rgba(239,68,68,.5);
    transform: rotate(90deg) scale(1.05);
}
/* modal-hd / modal-bd / modal-ft from effectifs */
.modal-ov .modal-hd {
    padding: 1.25rem 1.5rem;
    display: flex; justify-content: space-between; align-items: center;
    flex-shrink: 0;
}
.modal-ov .modal-bd { flex: 1; overflow-y: auto; }
.modal-ov .modal-ft {
    display: flex; justify-content: flex-end;
    align-items: center; gap: .65rem;
    padding: 1rem 1.5rem;
    flex-shrink: 0;
}

.emp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:1rem}
.emp-card{background:var(--bg-c,#0c0810);border:1px solid rgba(100,116,139,.14);border-radius:13px;padding:1.3rem;transition:all .2s;position:relative;overflow:hidden}
.emp-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--cc);opacity:.5}
.emp-card:hover{border-color:rgba(100,116,139,.35);transform:translateY(-2px)}
.emp-avatar{width:46px;height:46px;border-radius:11px;background:rgba(100,116,139,.18);border:1px solid rgba(100,116,139,.3);display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;color:var(--cc);flex-shrink:0}
.emp-name{font-weight:700;font-size:.9rem;color:#f1f5f9}
.emp-grade{font-size:.72rem;color:#94a3b8;margin-top:.15rem}
.grade-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;border-radius:20px;font-size:.68rem;font-weight:700;border:1px solid rgba(100,116,139,.35);background:rgba(100,116,139,.12);color:#c4b5fd}
.grade-badge.top{border-color:rgba(245,158,11,.45);background:rgba(245,158,11,.12);color:#fde68a}
.locked-btn{opacity:.35;cursor:not-allowed !important}
.emp-actions{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.9rem;padding-top:.75rem;border-top:1px solid rgba(255,255,255,.05)}
</style>

<?php if($msg):?>
<div class="alert alert-<?php echo $msgType;?>" style="margin-bottom:1rem">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i>
    <?php echo $msg;?>
</div>
<?php endif;?>

<!-- Toolbar -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;align-items:center;gap:1rem">
        <div style="width:42px;height:42px;border-radius:10px;background:rgba(100,116,139,.18);display:flex;align-items:center;justify-content:center;color:var(--cc);font-size:1.1rem">
            <i class="fas fa-users-gear"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:1rem;color:#f9fafb">Effectifs — Harmony Repair</div>
            <div style="font-size:.72rem;color:#64748b">
                <strong style="color:var(--cc)"><?php echo $totalEmp;?></strong> employé<?php echo $totalEmp>1?'s':'';?>
                <?php if($myGrade):?> · Grade actuel : <strong style="color:#c4b5fd"><?php echo htmlspecialchars($myGrade);?></strong><?php endif;?>
            </div>
        </div>
    </div>
    <?php if($canRecruit):?>
    <button onclick="LSC.openModal('recruitModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-user-plus"></i> Recruter
    </button>
    <?php endif;?>
</div>

<!-- KPIs grades -->
<?php
$gradeCount = [];
foreach ($employees as $e) {
    $g = $e['faction_rank'] ?: '—';
    $gradeCount[$g] = ($gradeCount[$g] ?? 0) + 1;
}
?>
<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem">
    <?php foreach ($GRADES as $g):
        $cnt = $gradeCount[$g['name']] ?? 0;
        $isTop = $g['rank_order'] <= 2;
    ?>
    <span class="grade-badge <?php echo $isTop?'top':'';?>">
        <?php echo htmlspecialchars($g['name']);?>
        <span style="font-weight:400;opacity:.65;">(<?php echo $cnt;?>)</span>
    </span>
    <?php endforeach;?>
</div>

<!-- Grille employés -->
<?php if(empty($employees)):?>
<div style="text-align:center;padding:4rem;background:var(--bg-c,#0c0810);border:1px solid rgba(100,116,139,.12);border-radius:13px;color:#475569">
    <i class="fas fa-users-slash" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
    Aucun employé pour le moment.
</div>
<?php else:?>
<div class="emp-grid">
<?php foreach($employees as $emp):
    $empGrade  = $emp['faction_rank'] ?? '';
    $empLevel  = $RLEVEL[$empGrade] ?? 0;
    $empGData  = $GRADE_MAP[$empGrade] ?? null;
    $canActEmp = canActOnAgent($empLevel, $myLevel, $isStaff);
    $isMe      = ($emp['id'] == $uid);
    $initial   = strtoupper(substr($emp['username'], 0, 1));
    $isTopGrade= isset($empGData) && ($empGData['rank_order'] ?? 99) <= 2;
?>
<div class="emp-card<?php echo $isMe?' is-mine':'';?>">
    <div style="display:flex;align-items:center;gap:.9rem;margin-bottom:.8rem">
        <div class="emp-avatar">
            <?php if(!empty($emp['avatar'])):?>
            <img src="<?php echo htmlspecialchars($emp['avatar']);?>" style="width:100%;height:100%;object-fit:cover;border-radius:11px" onerror="this.parentNode.textContent='<?php echo $initial;?>'">
            <?php else: echo $initial; endif;?>
        </div>
        <div style="flex:1;min-width:0">
            <div class="emp-name">
                <?php echo htmlspecialchars($emp['username']);?>
                <?php if($isMe):?> <span style="font-size:.58rem;background:rgba(100,116,139,.2);color:#c4b5fd;padding:.1rem .4rem;border-radius:4px;font-weight:700">MOI</span><?php endif;?>
            </div>
            <div class="emp-grade">
                <?php if($empGrade):?>
                <span class="grade-badge <?php echo $isTopGrade?'top':'';?>" style="font-size:.62rem">
                    <?php echo htmlspecialchars($empGrade);?>
                </span>
                <?php else:?><span style="color:#475569;font-style:italic">Grade non défini</span><?php endif;?>
            </div>
        </div>
        <?php if(!$canActEmp && !$isMe):?>
        <span title="Grade supérieur ou égal au vôtre" style="color:#64748b;font-size:.75rem"><i class="fas fa-lock"></i></span>
        <?php endif;?>
    </div>

    <?php if($canRecruit || $canFire):?>
    <div class="emp-actions">
        <!-- Changer grade -->
        <?php if($canRecruit):?>
        <?php if($canActEmp && !$isMe):?>
        <button onclick="openGradeModal(<?php echo $emp['id'];?>,'<?php echo addslashes(htmlspecialchars($emp['username']));?>','<?php echo addslashes($empGrade);?>')"
                class="btn btn-secondary btn-xs">
            <i class="fas fa-arrow-up-down"></i> Grade
        </button>
        <?php else:?>
        <button class="btn btn-secondary btn-xs locked-btn" disabled title="<?php echo $isMe?'Vous ne pouvez pas modifier votre propre grade':'Grade supérieur ou égal au vôtre';?>">
            <i class="fas fa-lock"></i> Grade
        </button>
        <?php endif;?>
        <?php endif;?>

        <!-- Licencier -->
        <?php if($canFire && !$isMe):?>
        <?php if($canActEmp):?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Licencier <?php echo addslashes(htmlspecialchars($emp['username']));?> ?')">
            <input type="hidden" name="action" value="fire">
            <input type="hidden" name="user_id" value="<?php echo $emp['id'];?>">
            <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-user-slash"></i> Licencier</button>
        </form>
        <?php else:?>
        <button class="btn btn-danger btn-xs locked-btn" disabled title="Grade supérieur ou égal au vôtre">
            <i class="fas fa-lock"></i> Licencier
        </button>
        <?php endif;?>
        <?php endif;?>
    </div>
    <?php endif;?>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<!-- ─── MODAL RECRUTER (redesign) ───────────────────────────────────────────── -->
<?php if($canRecruit):?>
<div id="recruitModal" class="modal-ov hidden">
<div class="modal" style="max-width:680px;width:96vw">
    <div class="modal-hd" style="background:linear-gradient(135deg,rgba(100,116,139,.12),rgba(109,40,217,.06));border-bottom:1px solid rgba(100,116,139,.25);">
        <div style="display:flex;align-items:center;gap:.85rem">
            <div style="width:38px;height:38px;border-radius:10px;background:rgba(100,116,139,.2);border:1px solid rgba(100,116,139,.4);display:flex;align-items:center;justify-content:center;color:#c4b5fd;flex-shrink:0">
                <i class="fas fa-user-plus"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:.95rem;color:#f9fafb">Recruter un nouvel employé</div>
                <div style="font-size:.65rem;color:#64748b;margin-top:.06rem">Harmony Repair — <?php echo count($citizens);?> citoyen(s) disponible(s)</div>
            </div>
        </div>
        <button class="modal-cl" onclick="LSC.closeModal('recruitModal')" title="Fermer"><i class="fas fa-xmark"></i></button>
    </div>

    <form method="POST">
    <input type="hidden" name="action" value="recruit">
    <div class="modal-bd" style="padding:0;display:flex;flex-direction:column;max-height:75vh">

        <!-- Zone recherche + liste -->
        <div style="padding:1.1rem 1.4rem 0">
            <!-- Barre de recherche -->
            <div style="position:relative;margin-bottom:.85rem">
                <i class="fas fa-search" style="position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:#4c3d7c;font-size:.75rem;pointer-events:none;z-index:1"></i>
                <input type="text" id="rc_search" class="form-input"
                       placeholder="Rechercher par nom..."
                       oninput="rcFilter(this.value)"
                       autocomplete="off"
                       style="padding-left:2.4rem;background:rgba(100,116,139,.06);border-color:rgba(100,116,139,.2);font-size:.85rem">
            </div>
            <!-- Compteur -->
            <div id="rc_count" style="font-size:.7rem;color:#64748b;margin-bottom:.55rem">
                <i class="fas fa-users" style="margin-right:.3rem;opacity:.5"></i>
                <span id="rc_count_num"><?php echo count($citizens);?></span> citoyen(s) disponible(s)
            </div>
        </div>

        <!-- Liste des citoyens (scrollable) -->
        <div id="rc_list" style="overflow-y:auto;max-height:280px;padding:0 1.4rem;display:flex;flex-direction:column;gap:.3rem">
            <?php if(empty($citizens)):?>
            <div style="text-align:center;padding:2.5rem 1rem;color:#475569">
                <i class="fas fa-user-slash" style="font-size:1.8rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
                <div style="font-size:.82rem">Aucun citoyen disponible</div>
                <div style="font-size:.72rem;margin-top:.25rem;opacity:.6">Tous les utilisateurs ont déjà une faction ou ne sont pas citoyens.</div>
            </div>
            <?php else: foreach($citizens as $c2):
                $ci = strtoupper(substr($c2['username'],0,1));
            ?>
            <label class="rc-card" data-s="<?php echo strtolower(htmlspecialchars($c2['username']));?>"
                   style="display:flex;align-items:center;gap:.8rem;padding:.65rem .85rem;background:rgba(255,255,255,.02);border:1.5px solid rgba(255,255,255,.06);border-radius:10px;cursor:pointer;transition:all .15s;user-select:none"
                   onmouseover="this.style.background='rgba(100,116,139,.08)';this.style.borderColor='rgba(100,116,139,.25)'"
                   onmouseout="if(!this.querySelector('input').checked){this.style.background='rgba(255,255,255,.02)';this.style.borderColor='rgba(255,255,255,.06)'}"
                   onclick="rcSelect(this)">
                <input type="radio" name="user_id" value="<?php echo $c2['id'];?>" style="display:none" required>
                <!-- Avatar initiale -->
                <div class="rc-avatar" style="width:36px;height:36px;border-radius:9px;background:rgba(100,116,139,.15);border:1px solid rgba(100,116,139,.25);display:flex;align-items:center;justify-content:center;font-weight:700;color:#c4b5fd;font-size:.85rem;flex-shrink:0">
                    <?php echo $ci;?>
                </div>
                <!-- Nom + tag -->
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600;font-size:.84rem;color:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($c2['username']);?></div>
                    <div style="font-size:.63rem;color:#475569;margin-top:.1rem;display:flex;align-items:center;gap:.35rem">
                        <span style="width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block"></span>
                        Citoyen — Sans faction
                    </div>
                </div>
                <!-- Check indicator -->
                <div class="rc-check" style="width:20px;height:20px;border-radius:50%;border:2px solid rgba(100,116,139,.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .15s">
                    <i class="fas fa-check" style="font-size:.6rem;color:#c4b5fd;opacity:0;transition:opacity .15s"></i>
                </div>
            </label>
            <?php endforeach; endif;?>
        </div>

        <!-- Aperçu sélection + choix grade -->
        <div style="border-top:1px solid rgba(255,255,255,.06);margin-top:.85rem;padding:1.1rem 1.4rem">
            <!-- Aperçu employé sélectionné -->
            <div id="rc_preview" style="display:none;align-items:center;gap:.75rem;padding:.7rem .9rem;background:rgba(100,116,139,.07);border:1px solid rgba(100,116,139,.22);border-radius:9px;margin-bottom:.9rem">
                <div id="rc_prev_ava" style="width:34px;height:34px;border-radius:8px;background:rgba(100,116,139,.25);display:flex;align-items:center;justify-content:center;font-weight:700;color:#c4b5fd;font-size:.85rem;flex-shrink:0"></div>
                <div style="flex:1">
                    <div style="font-size:.78rem;font-weight:600;color:#f1f5f9" id="rc_prev_name">—</div>
                    <div style="font-size:.63rem;color:#64748b;margin-top:.08rem">Sélectionné pour recrutement</div>
                </div>
                <i class="fas fa-check-circle" style="color:#64748b;font-size:.9rem"></i>
            </div>
            <!-- Grade d'entrée -->
            <div class="form-group">
                <label class="form-label" style="margin-bottom:.5rem">
                    <i class="fas fa-medal" style="color:var(--cc);font-size:.7rem;margin-right:.3rem"></i>
                    Grade d'entrée
                </label>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem" id="rc_grade_btns">
                    <?php foreach(array_reverse($GRADES) as $gi => $g):
                        $isDefault = ($g['rank_order'] === max(array_column($GRADES,'rank_order')));
                    ?>
                    <label style="display:inline-flex;align-items:center;gap:.4rem;padding:.38rem .8rem;border-radius:7px;cursor:pointer;transition:all .15s;font-size:.76rem;font-weight:600;border:1.5px solid <?php echo $isDefault?'rgba(100,116,139,.6)':'rgba(255,255,255,.08)';?>;background:<?php echo $isDefault?'rgba(100,116,139,.18)':'rgba(255,255,255,.03)';?>;color:<?php echo $isDefault?'#c4b5fd':'#64748b';?>"
                          onclick="rcGradeSelect(this)">
                        <input type="radio" name="grade" value="<?php echo htmlspecialchars($g['name']);?>" <?php echo $isDefault?'checked':'';?> style="display:none">
                        <?php echo htmlspecialchars($g['name']);?>
                    </label>
                    <?php endforeach;?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-ft" style="border-top:1px solid rgba(100,116,139,.15);background:rgba(100,116,139,.04)">
        <button type="button" class="btn btn-ghost" onclick="LSC.closeModal('recruitModal')">Annuler</button>
        <button type="submit" id="rc_submit" class="btn btn-primary" disabled
                style="background:linear-gradient(135deg,#475569,#6d28d9);opacity:.5;cursor:not-allowed">
            <i class="fas fa-user-plus"></i> Confirmer le recrutement
        </button>
    </div>
    </form>
</div>
</div>

<!-- ─── MODAL CHANGER GRADE ───────────────────────────────────────────────── -->
<div id="gradeModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd">
    <h2><i class="fas fa-arrow-up-down" style="color:var(--cc)"></i> Modifier le grade</h2>
    <button class="modal-cl" onclick="LSC.closeModal('gradeModal')" title="Fermer"><i class="fas fa-xmark"></i></button>
</div>
<form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="change_grade">
    <input type="hidden" name="user_id" id="gm_uid">
    <div style="font-size:.9rem;font-weight:700;color:#f9fafb;margin-bottom:.9rem;padding:.55rem .85rem;background:rgba(100,116,139,.08);border:1px solid rgba(100,116,139,.25);border-radius:9px;display:flex;align-items:center;gap:.6rem">
        <i class="fas fa-user" style="color:#c4b5fd;font-size:.85rem"></i>
        <span id="gm_name">—</span>
        <span style="font-size:.7rem;color:#64748b;margin-left:.3rem">Grade actuel : </span>
        <span id="gm_current" style="font-size:.7rem;color:#c4b5fd;font-weight:600"></span>
    </div>
    <div class="form-group">
        <label class="form-label">Nouveau grade *</label>
        <select name="new_grade" id="gm_grade" class="form-select" required>
            <?php
            // Affiche seulement les grades INFÉRIEURS au mien (sécurité côté front)
            foreach (array_reverse($GRADES) as $g):
                $gLvl = $RLEVEL[$g['name']] ?? 0;
                $disabled = (!$isStaff && $gLvl >= $myLevel) ? 'disabled style="display:none"' : '';
            ?>
            <option value="<?php echo htmlspecialchars($g['name']);?>" <?php echo $disabled;?>>
                <?php echo htmlspecialchars($g['name']);?>
            </option>
            <?php endforeach;?>
        </select>
        <?php if(!$isStaff && $myLevel < count($GRADES)):?>
        <div style="font-size:.68rem;color:#64748b;margin-top:.3rem">
            <i class="fas fa-info-circle" style="margin-right:.2rem;opacity:.6"></i>
            Vous ne pouvez attribuer que des grades inférieurs au vôtre.
        </div>
        <?php endif;?>
    </div>
</div>
<div class="modal-ft">
    <button type="button" class="btn btn-ghost" onclick="LSC.closeModal('gradeModal')">Annuler</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
</div>
</form></div></div>
<?php endif;?>

<script>
const LSC = {
    openModal(id)  { const m=document.getElementById(id); if(m){m.classList.remove('hidden');document.body.style.overflow='hidden';} },
    closeModal(id) { const m=document.getElementById(id); if(m){m.classList.add('hidden');document.body.style.overflow='';} }
};
document.querySelectorAll('.modal-ov').forEach(ov=>ov.addEventListener('click',e=>{if(e.target===ov){ov.classList.add('hidden');document.body.style.overflow=''}}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-ov:not(.hidden)').forEach(m=>{m.classList.add('hidden');document.body.style.overflow=''});});

function rcFilter(q) {
    const v = q.toLowerCase().trim();
    let n = 0;
    document.querySelectorAll('.rc-card').forEach(card => {
        const m = !v || card.dataset.s.includes(v);
        card.style.display = m ? '' : 'none';
        if (m) n++;
    });
    document.getElementById('rc_count_num').textContent = n;
}

function rcSelect(lbl) {
    // Deselect all
    document.querySelectorAll('.rc-card').forEach(c => {
        c.style.background   = 'rgba(255,255,255,.02)';
        c.style.borderColor  = 'rgba(255,255,255,.06)';
        const chk = c.querySelector('.rc-check');
        if (chk) { chk.style.borderColor='rgba(100,116,139,.25)'; chk.style.background='transparent'; }
        const ico = c.querySelector('.rc-check i');
        if (ico) ico.style.opacity = '0';
    });
    // Select this one
    lbl.style.background  = 'rgba(100,116,139,.12)';
    lbl.style.borderColor = 'rgba(100,116,139,.5)';
    lbl.style.boxShadow   = '0 0 0 2px rgba(100,116,139,.15)';
    const chk = lbl.querySelector('.rc-check');
    if (chk) { chk.style.borderColor='#64748b'; chk.style.background='rgba(100,116,139,.2)'; }
    const ico = lbl.querySelector('.rc-check i');
    if (ico) ico.style.opacity = '1';

    const name = lbl.querySelector('div div').textContent.trim();
    const init = name.charAt(0).toUpperCase();
    // Preview
    const preview = document.getElementById('rc_preview');
    if (preview) {
        preview.style.display = 'flex';
        document.getElementById('rc_prev_ava').textContent  = init;
        document.getElementById('rc_prev_name').textContent = name;
    }
    // Enable submit
    const btn = document.getElementById('rc_submit');
    if (btn) { btn.disabled=false; btn.style.opacity='1'; btn.style.cursor='pointer'; }
}

function rcGradeSelect(lbl) {
    document.querySelectorAll('#rc_grade_btns label').forEach(l => {
        l.style.background   = 'rgba(255,255,255,.03)';
        l.style.borderColor  = 'rgba(255,255,255,.08)';
        l.style.color        = '#64748b';
    });
    lbl.style.background  = 'rgba(100,116,139,.18)';
    lbl.style.borderColor = 'rgba(100,116,139,.6)';
    lbl.style.color       = '#c4b5fd';
}

function openGradeModal(id, name, currentGrade) {
    document.getElementById('gm_uid').value = id;
    document.getElementById('gm_name').textContent = name;
    document.getElementById('gm_current').textContent = currentGrade || '—';
    const sel = document.getElementById('gm_grade');
    if (sel) sel.value = currentGrade || sel.options[0]?.value;
    LSC.openModal('gradeModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>