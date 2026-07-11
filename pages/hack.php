<?php
// pages/hack.php
require_once __DIR__ . '/../config/config.php';
requireLogin();
mdt_require_product('addon_hack_mdt', 'Hack MDT');

$db = Database::getInstance()->getConnection();

// Staff ne peut pas hacker
if (hasRole(['Modérateur', 'Helper'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

/* ══════════════════════════════════════════════════════
   TABLES & CONFIGURATION
══════════════════════════════════════════════════════ */
try {
    $db->exec("CREATE TABLE IF NOT EXISTS mdt_hack_state (
        id INT DEFAULT 1,
        is_hacked TINYINT(1) DEFAULT 0,
        hack_title VARCHAR(200) DEFAULT NULL,
        hacked_by VARCHAR(100) DEFAULT NULL,
        hacked_by_user_id INT DEFAULT NULL,
        hacked_at DATETIME DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        cancelled_by INT DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO mdt_hack_state (id, is_hacked) VALUES (1, 0)");

    $db->exec("CREATE TABLE IF NOT EXISTS mdt_hack_config (
        id INT DEFAULT 1,
        hack_duration INT DEFAULT 3600,
        global_lives INT DEFAULT 3,
        fail_cooldown INT DEFAULT 600,
        sound_enabled TINYINT(1) DEFAULT 1,
        visual_effects TINYINT(1) DEFAULT 1,
        difficulty ENUM('easy','medium','hard') DEFAULT 'medium',
        progressive_difficulty TINYINT(1) DEFAULT 1,
        random_events TINYINT(1) DEFAULT 1,
        intro_enabled TINYINT(1) DEFAULT 1,
        matrix_bg TINYINT(1) DEFAULT 1,
        m1_enabled TINYINT(1) DEFAULT 1,
        m1_path_length INT DEFAULT 6,
        m1_show_time INT DEFAULT 1500,
        m2_enabled TINYINT(1) DEFAULT 1,
        m2_time_limit INT DEFAULT 45,
        m3_enabled TINYINT(1) DEFAULT 1,
        m3_rounds INT DEFAULT 3,
        m3_base_length INT DEFAULT 4,
        m3_speed INT DEFAULT 500,
        m4_enabled TINYINT(1) DEFAULT 1,
        m4_hold_time INT DEFAULT 5,
        m4_threshold INT DEFAULT 80,
        m5_enabled TINYINT(1) DEFAULT 1,
        m5_packets_needed INT DEFAULT 10,
        m5_time_limit INT DEFAULT 30,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO mdt_hack_config (id) VALUES (1)");

    $db->exec("CREATE TABLE IF NOT EXISTS mdt_hack_attempts (
        user_id INT PRIMARY KEY,
        lives_used INT DEFAULT 0,
        locked_until DATETIME DEFAULT NULL,
        last_fail_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/* ── Charger la configuration ── */
function hack_load_config(PDO $db): array {
    try {
        $row = $db->query("SELECT * FROM mdt_hack_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Exception $e) {}
    return [
        'hack_duration'=>3600,'global_lives'=>3,'fail_cooldown'=>600,
        'sound_enabled'=>1,'visual_effects'=>1,'difficulty'=>'medium',
        'progressive_difficulty'=>1,'random_events'=>1,'intro_enabled'=>1,'matrix_bg'=>1,
        'm1_enabled'=>1,'m1_path_length'=>6,'m1_show_time'=>1500,
        'm2_enabled'=>1,'m2_time_limit'=>45,
        'm3_enabled'=>1,'m3_rounds'=>3,'m3_base_length'=>4,'m3_speed'=>500,
        'm4_enabled'=>1,'m4_hold_time'=>5,'m4_threshold'=>80,
        'm5_enabled'=>1,'m5_packets_needed'=>10,'m5_time_limit'=>30,
    ];
}
$CFG = hack_load_config($db);

/* ── Liste des missions activées ── */
function hack_enabled_missions(array $cfg): array {
    $out = [];
    foreach ([1,2,3,4,5] as $n) {
        if (!empty($cfg['m'.$n.'_enabled'])) $out[] = $n;
    }
    return $out ?: [1];
}

/* ── Charger les tentatives de l'utilisateur ── */
function hack_get_attempts(PDO $db, int $userId): array {
    try {
        $s = $db->prepare("SELECT * FROM mdt_hack_attempts WHERE user_id=?");
        $s->execute([$userId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Exception $e) {}
    return ['user_id'=>$userId,'lives_used'=>0,'locked_until'=>null,'last_fail_at'=>null];
}

function hack_reset_attempts(PDO $db, int $userId): void {
    try {
        $db->prepare("DELETE FROM mdt_hack_attempts WHERE user_id=?")->execute([$userId]);
    } catch (Exception $e) {}
}

function hack_register_fail(PDO $db, int $userId, array $cfg): array {
    try {
        $s = $db->prepare("SELECT lives_used, locked_until FROM mdt_hack_attempts WHERE user_id=?");
        $s->execute([$userId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $lives = $row ? (int)$row['lives_used'] : 0;
        $lives++;
        $locked = null;
        if ($lives >= (int)$cfg['global_lives']) {
            $locked = date('Y-m-d H:i:s', time() + (int)$cfg['fail_cooldown']);
        }
        if ($row) {
            $db->prepare("UPDATE mdt_hack_attempts SET lives_used=?, locked_until=?, last_fail_at=NOW() WHERE user_id=?")
               ->execute([$lives, $locked, $userId]);
        } else {
            $db->prepare("INSERT INTO mdt_hack_attempts (user_id, lives_used, locked_until, last_fail_at) VALUES (?,?,?,NOW())")
               ->execute([$userId, $lives, $locked]);
        }
        return ['lives_used'=>$lives, 'locked_until'=>$locked];
    } catch (Exception $e) {}
    return ['lives_used'=>0,'locked_until'=>null];
}

/* ══════════════════════════════════════════════════════
   AJAX HANDLERS
══════════════════════════════════════════════════════ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'lose_life') {
        $res = hack_register_fail($db, $userId, $CFG);
        $remaining = max(0, (int)$CFG['global_lives'] - (int)$res['lives_used']);
        $lockedUntil = null;
        if (!empty($res['locked_until'])) {
            $lockedUntil = strtotime($res['locked_until']);
        }
        echo json_encode([
            'success' => true,
            'remaining' => $remaining,
            'locked' => $remaining <= 0,
            'locked_until' => $lockedUntil,
            'seconds_left' => $lockedUntil ? max(0, $lockedUntil - time()) : 0,
        ]);
        exit;
    }

    if ($action === 'complete_mission') {
        $mission = (int)($_POST['mission'] ?? 0);
        if ($mission >= 1 && $mission <= 5) {
            if (!isset($_SESSION['hack_missions'])) $_SESSION['hack_missions'] = [];
            $_SESSION['hack_missions'][$mission] = true;
            echo json_encode(['success' => true, 'completed' => array_keys($_SESSION['hack_missions'])]);
        }
        exit;
    }

    if ($action === 'submit_hack') {
        $missions = $_SESSION['hack_missions'] ?? [];
        $required = hack_enabled_missions($CFG);
        foreach ($required as $m) {
            if (empty($missions[$m])) {
                echo json_encode(['success' => false, 'error' => 'INTRUSION INCOMPLETE — Toutes les missions doivent être terminées.']);
                exit;
            }
        }
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 60);
        if (empty($title)) {
            echo json_encode(['success' => false, 'error' => 'IDENTIFIANT REQUIS — Entrez un titre pour le hack.']);
            exit;
        }
        if (empty($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'SESSION PERDUE — Vous êtes déconnecté. Réessayez.']);
            exit;
        }
        try {
            $hs = $db->query("SELECT is_hacked, expires_at FROM mdt_hack_state WHERE id=1")->fetch();
            if ($hs && $hs['is_hacked'] && $hs['expires_at'] && strtotime($hs['expires_at']) > time()) {
                echo json_encode(['success' => false, 'error' => 'MDT DÉJÀ COMPROMIS — Une intrusion est déjà en cours.']);
                exit;
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'ERREUR CHECK HACK: '.$e->getMessage()]);
            exit;
        }
        $expires = date('Y-m-d H:i:s', time() + (int)$CFG['hack_duration']);
        try {
            $db->prepare("UPDATE mdt_hack_state SET is_hacked=1, hack_title=?, hacked_by=?, hacked_by_user_id=?, hacked_at=NOW(), expires_at=?, cancelled_by=NULL, cancelled_at=NULL WHERE id=1")
               ->execute([$title, $_SESSION['user_username'] ?? 'Inconnu', $_SESSION['user_id'] ?? 0, $expires]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'ERREUR DB: '.$e->getMessage()]);
            exit;
        }
        hack_reset_attempts($db, $userId);
        unset($_SESSION['hack_missions']);
        echo json_encode(['success' => true, 'redirect' => SITE_URL . '/pages/dashboard.php']);
        exit;
    }

    exit;
}

/* ══════════════════════════════════════════════════════
   ÉTAT ACTUEL (hack + cooldown)
══════════════════════════════════════════════════════ */
$isAlreadyHacked = false;
$currentHackTitle = '';
try {
    $hs = $db->query("SELECT * FROM mdt_hack_state WHERE id=1")->fetch();
    if ($hs && $hs['is_hacked'] && $hs['expires_at'] && strtotime($hs['expires_at']) > time()) {
        $isAlreadyHacked  = true;
        $currentHackTitle = $hs['hack_title'];
    }
} catch (Exception $e) {}

$userId = (int)($_SESSION['user_id'] ?? 0);
$attempts = hack_get_attempts($db, $userId);
$isLocked = false;
$lockSecondsLeft = 0;
if (!empty($attempts['locked_until'])) {
    $lockTs = strtotime($attempts['locked_until']);
    if ($lockTs > time()) {
        $isLocked = true;
        $lockSecondsLeft = $lockTs - time();
    } else {
        hack_reset_attempts($db, $userId);
        $attempts['lives_used'] = 0;
        $attempts['locked_until'] = null;
    }
}
$livesRemaining = max(0, (int)$CFG['global_lives'] - (int)$attempts['lives_used']);

/* Reset missions à chaque visite (sauf si verrouillé) */
if (!$isLocked) {
    $_SESSION['hack_missions'] = [];
}

$username = $_SESSION['user_username'] ?? 'ANONYMOUS';
$enabledMissions = hack_enabled_missions($CFG);

/* Préparer la config côté client */
$clientConfig = [
    'soundEnabled'         => (bool)$CFG['sound_enabled'],
    'visualEffects'        => (bool)$CFG['visual_effects'],
    'matrixBg'             => (bool)$CFG['matrix_bg'],
    'introEnabled'         => (bool)$CFG['intro_enabled'],
    'progressiveDifficulty'=> (bool)$CFG['progressive_difficulty'],
    'randomEvents'         => (bool)$CFG['random_events'],
    'difficulty'           => $CFG['difficulty'],
    'globalLives'          => (int)$CFG['global_lives'],
    'livesRemaining'       => $livesRemaining,
    'failCooldown'         => (int)$CFG['fail_cooldown'],
    'enabledMissions'      => $enabledMissions,
    'm1' => ['pathLength'=>(int)$CFG['m1_path_length'],'showTime'=>(int)$CFG['m1_show_time']],
    'm2' => ['timeLimit'=>(int)$CFG['m2_time_limit']],
    'm3' => ['rounds'=>(int)$CFG['m3_rounds'],'baseLength'=>(int)$CFG['m3_base_length'],'speed'=>(int)$CFG['m3_speed']],
    'm4' => ['holdTime'=>(int)$CFG['m4_hold_time'],'threshold'=>(int)$CFG['m4_threshold']],
    'm5' => ['packetsNeeded'=>(int)$CFG['m5_packets_needed'],'timeLimit'=>(int)$CFG['m5_time_limit']],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LYSORIA // BREACH TERMINAL</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700;800&family=Orbitron:wght@400;700;900&display=swap');

*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --g1:#00ff41; --g2:#00cc33; --g3:#004d14;
    --c1:#00e5ff; --c2:#0097a7;
    --r1:#ff1744; --r2:#b71c1c;
    --y1:#ffc107;
    --bg:#000a00; --bg2:#001100; --bg3:#001a00;
    --border:rgba(0,255,65,.18); --border-h:rgba(0,255,65,.5);
}
html,body{width:100%;height:100%;overflow:hidden}
body{
    font-family:'JetBrains Mono',monospace;
    background:var(--bg);
    color:var(--g1);
    display:flex;flex-direction:column;
    position:relative;
}

/* ── SCANLINES ── */
body::after{
    content:'';position:fixed;inset:0;z-index:9000;pointer-events:none;
    background:repeating-linear-gradient(0deg,rgba(0,0,0,.08) 0px,rgba(0,0,0,.08) 1px,transparent 1px,transparent 2px);
}
body.no-fx::after{display:none;}

/* ── VIGNETTE + CRT ── */
body::before{
    content:'';position:fixed;inset:0;z-index:8999;pointer-events:none;
    background:radial-gradient(ellipse at center, transparent 40%, rgba(0,0,0,.55) 100%);
}

/* ── MATRIX CANVAS ── */
#matrixBg{position:fixed;inset:0;z-index:0;opacity:.15;pointer-events:none;}

/* ── GLITCH OVERLAY ── */
.glitch-overlay{
    position:fixed;inset:0;z-index:9100;pointer-events:none;
    background:linear-gradient(180deg, transparent 49.8%, rgba(0,255,65,.08) 50%, transparent 50.2%);
    background-size:100% 4px;
    opacity:0;transition:opacity .1s;
}
.glitch-overlay.active{opacity:1;animation:glitchMove .12s linear infinite;}
@keyframes glitchMove{to{background-position-y:4px;}}

/* ── LAYOUT ── */
.terminal{position:relative;z-index:10;display:flex;flex-direction:column;height:100vh;}

/* ── TOPBAR ── */
.t-bar{
    flex-shrink:0;height:48px;
    background:rgba(0,20,0,.95);border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;
    padding:0 24px;gap:16px;
}
.t-bar-logo{
    font-family:'Orbitron',monospace;font-size:.75rem;font-weight:900;
    letter-spacing:3px;color:var(--g1);
    text-shadow:0 0 12px rgba(0,255,65,.6);
    display:flex;align-items:center;gap:10px;
}
.t-bar-logo::before{
    content:'▶';color:var(--r1);font-size:.6rem;animation:blink 1s step-end infinite;
}
@keyframes blink{50%{opacity:0}}
.t-bar-right{display:flex;align-items:center;gap:16px;font-size:.65rem;color:var(--g2);letter-spacing:1px;flex-wrap:wrap;justify-content:flex-end;}
.t-bar-user{color:var(--c1);}
.t-bar-status{
    display:flex;align-items:center;gap:5px;
    padding:3px 10px;border:1px solid var(--r1);border-radius:3px;
    color:var(--r1);font-size:.6rem;font-weight:700;letter-spacing:2px;
    animation:pulseRed 2s ease-in-out infinite;
}
@keyframes pulseRed{50%{box-shadow:0 0 10px rgba(255,23,68,.4);}}

.t-lives{
    display:flex;align-items:center;gap:8px;
    padding:3px 12px;border:1px solid var(--border);border-radius:3px;
    font-size:.65rem;letter-spacing:1px;
}
.t-lives-icons{display:flex;gap:3px;}
.t-life{
    color:var(--r1);font-size:.9rem;text-shadow:0 0 6px rgba(255,23,68,.6);
    transition:all .25s;
}
.t-life.lost{color:rgba(255,23,68,.2);text-shadow:none;animation:lifeDie .4s;}
@keyframes lifeDie{
    0%{transform:scale(1.5);color:#fff;}
    50%{transform:scale(.3) rotate(180deg);}
    100%{transform:scale(1);color:rgba(255,23,68,.2);}
}

/* ── MISSION PROGRESS ── */
.m-prog{
    flex-shrink:0;min-height:36px;
    background:rgba(0,10,0,.9);border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:center;gap:8px;
    padding:6px 24px;flex-wrap:wrap;
}
.m-step{
    display:flex;align-items:center;gap:6px;
    font-size:.6rem;letter-spacing:1px;color:rgba(0,255,65,.3);
    transition:all .3s;
}
.m-step.done{color:var(--g2);}
.m-step.active{color:var(--c1);text-shadow:0 0 8px var(--c1);}
.m-step-dot{
    width:8px;height:8px;border-radius:50%;
    border:1px solid currentColor;transition:all .3s;
}
.m-step.done .m-step-dot{background:var(--g2);}
.m-step.active .m-step-dot{background:var(--c1);box-shadow:0 0 8px var(--c1);}
.m-step-sep{color:rgba(0,255,65,.2);font-size:.6rem;margin:0 4px;}

/* ── MISSION CONTAINER ── */
.m-center{
    flex:1;overflow:auto;
    display:flex;align-items:center;justify-content:center;
    padding:20px;
}
.mission-wrap{
    width:100%;max-width:680px;
    display:flex;flex-direction:column;align-items:center;gap:20px;
    animation:fadeSlide .3s ease;
}
@keyframes fadeSlide{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

.m-title-bar{
    width:100%;display:flex;align-items:center;gap:12px;
    font-family:'Orbitron',monospace;font-size:.65rem;font-weight:700;
    letter-spacing:3px;color:var(--c1);
}
.m-title-bar::before,.m-title-bar::after{
    content:'';flex:1;height:1px;background:linear-gradient(90deg,transparent,var(--c2));
}
.m-title-bar::after{background:linear-gradient(90deg,var(--c2),transparent);}

.m-desc{
    font-size:.68rem;color:rgba(0,255,65,.6);text-align:center;
    letter-spacing:.5px;line-height:1.6;max-width:500px;
}

.m-timer-wrap{display:flex;align-items:center;gap:10px;}
.m-timer{
    font-family:'Orbitron',monospace;font-size:1.2rem;font-weight:900;
    color:var(--c1);min-width:60px;text-align:center;
    transition:color .3s;
}
.m-timer.warn{color:#ff9800;animation:pulseOrange .5s ease-in-out infinite;}
.m-timer.danger{color:var(--r1);animation:pulseRed .3s ease-in-out infinite;}
@keyframes pulseOrange{50%{opacity:.5}}
.m-timer-label{font-size:.55rem;letter-spacing:2px;color:rgba(0,255,65,.4);}

/* ── BUTTONS ── */
.btn-hack{
    padding:.65rem 2rem;border:1px solid var(--g1);border-radius:3px;
    background:transparent;color:var(--g1);
    font-family:'Orbitron',monospace;font-size:.7rem;font-weight:700;letter-spacing:2px;
    cursor:pointer;transition:all .18s;
    text-transform:uppercase;
}
.btn-hack:hover{background:rgba(0,255,65,.1);box-shadow:0 0 15px rgba(0,255,65,.2);}
.btn-hack:disabled{opacity:.3;cursor:not-allowed;}
.btn-hack.primary{border-color:var(--c1);color:var(--c1);}
.btn-hack.primary:hover{background:rgba(0,229,255,.1);box-shadow:0 0 15px rgba(0,229,255,.2);}
.btn-hack.danger{border-color:var(--r1);color:var(--r1);}

/* ── FEEDBACK ── */
.m-feedback{
    padding:.5rem 1.5rem;border-radius:3px;
    font-size:.68rem;letter-spacing:1px;font-weight:700;
    min-height:32px;text-align:center;transition:all .2s;
}
.m-feedback.ok{color:var(--g1);border:1px solid var(--g2);background:rgba(0,255,65,.05);}
.m-feedback.err{color:var(--r1);border:1px solid var(--r2);background:rgba(255,23,68,.05);}
.m-feedback.warn{color:var(--y1);border:1px solid var(--y1);background:rgba(255,193,7,.05);}

/* ══════════════════════════════════
   MISSION 1 — FIREWALL GRID
══════════════════════════════════ */
.fw-grid{
    display:grid;grid-template-columns:repeat(5,1fr);
    gap:6px;max-width:320px;width:100%;
}
.fw-node{
    aspect-ratio:1;border:1px solid var(--border);border-radius:4px;
    background:rgba(0,20,0,.8);
    cursor:pointer;transition:all .18s;
    display:flex;align-items:center;justify-content:center;
    font-size:.55rem;color:rgba(0,255,65,.2);
    position:relative;overflow:hidden;
}
.fw-node:hover{border-color:var(--g2);background:rgba(0,40,0,.8);}
.fw-node.path{
    border-color:var(--g1);background:rgba(0,255,65,.15);
    box-shadow:0 0 10px rgba(0,255,65,.3),inset 0 0 10px rgba(0,255,65,.1);
    color:var(--g1);font-weight:700;
}
.fw-node.trap{
    border-color:var(--r1);background:rgba(255,23,68,.2);
    color:var(--r1);animation:pulseRed .5s infinite;
}
.fw-node.selected{border-color:var(--c1);background:rgba(0,229,255,.15);color:var(--c1);}
.fw-node.correct{border-color:var(--g1);background:rgba(0,255,65,.2);}
.fw-node.wrong{border-color:var(--r1);background:rgba(255,23,68,.15);animation:shake .3s;}
.fw-node.disabled{cursor:not-allowed;opacity:.4;}
@keyframes shake{0%,100%{transform:none}25%{transform:translateX(-3px)}75%{transform:translateX(3px)}}

/* ══════════════════════════════════
   MISSION 2 — CIPHER DECODE
══════════════════════════════════ */
.cipher-wrap{width:100%;max-width:560px;}
.cipher-legend{
    display:flex;flex-wrap:wrap;gap:6px;
    padding:14px;border:1px solid var(--border);border-radius:4px;
    background:rgba(0,10,0,.8);margin-bottom:16px;justify-content:center;
}
.cipher-pair{
    display:flex;flex-direction:column;align-items:center;gap:2px;
    padding:6px 8px;border:1px solid var(--border);border-radius:3px;
    background:rgba(0,255,65,.03);min-width:38px;
}
.cipher-sym{font-size:1rem;color:var(--c1);}
.cipher-ltr{font-size:.6rem;color:var(--g2);letter-spacing:1px;}
.cipher-coded{
    font-size:2rem;letter-spacing:.5rem;text-align:center;
    color:var(--c1);padding:16px;
    border:1px solid var(--c2);border-radius:4px;
    background:rgba(0,150,180,.05);margin-bottom:16px;
    text-shadow:0 0 12px rgba(0,229,255,.4);
    font-weight:700;
}
.cipher-keyboard{
    display:flex;flex-wrap:wrap;gap:5px;justify-content:center;
    max-width:400px;margin:0 auto;
}
.cipher-key{
    width:38px;height:38px;border:1px solid var(--border);border-radius:3px;
    background:rgba(0,20,0,.8);color:var(--g1);
    font-family:'JetBrains Mono',monospace;font-size:.75rem;font-weight:700;
    cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;
}
.cipher-key:hover{border-color:var(--g1);background:rgba(0,255,65,.1);}
.cipher-key.used{opacity:.3;cursor:not-allowed;}
.cipher-key.backspace{width:60px;color:var(--r1);border-color:var(--r2);}
.cipher-input{
    font-size:1.6rem;letter-spacing:.5rem;text-align:center;
    color:var(--g1);padding:12px;min-height:58px;
    border:1px solid var(--border);border-radius:4px;
    background:rgba(0,10,0,.5);margin-bottom:12px;
    text-shadow:0 0 8px rgba(0,255,65,.3);
    font-weight:700;width:100%;
    display:flex;align-items:center;justify-content:center;
}
.cipher-input .cursor{animation:blink 1s step-end infinite;color:var(--c1);}

/* ══════════════════════════════════
   MISSION 3 — SEQUENCE
══════════════════════════════════ */
.seq-grid{
    display:grid;grid-template-columns:repeat(3,1fr);
    gap:10px;max-width:280px;width:100%;
}
.seq-btn{
    aspect-ratio:1;border:2px solid transparent;border-radius:8px;
    cursor:pointer;transition:all .18s;
    display:flex;align-items:center;justify-content:center;
    font-size:.55rem;letter-spacing:1px;color:rgba(255,255,255,.3);
    position:relative;overflow:hidden;
}
.seq-btn::after{content:'';position:absolute;inset:0;opacity:0;background:rgba(255,255,255,.3);transition:opacity .1s;}
.seq-btn.lit::after{opacity:1;}
.seq-btn-0{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.25);}
.seq-btn-1{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.25);}
.seq-btn-2{background:rgba(234,179,8,.12);border-color:rgba(234,179,8,.25);}
.seq-btn-3{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.25);}
.seq-btn-4{background:rgba(168,85,247,.12);border-color:rgba(168,85,247,.25);}
.seq-btn-5{background:rgba(6,182,212,.12);border-color:rgba(6,182,212,.25);}
.seq-btn-6{background:rgba(249,115,22,.12);border-color:rgba(249,115,22,.25);}
.seq-btn-7{background:rgba(236,72,153,.12);border-color:rgba(236,72,153,.25);}
.seq-btn-8{background:rgba(100,116,139,.12);border-color:rgba(100,116,139,.25);}
.seq-btn-0.lit{background:rgba(239,68,68,.5);border-color:rgba(239,68,68,.9);box-shadow:0 0 20px rgba(239,68,68,.4);}
.seq-btn-1.lit{background:rgba(59,130,246,.5);border-color:rgba(59,130,246,.9);box-shadow:0 0 20px rgba(59,130,246,.4);}
.seq-btn-2.lit{background:rgba(234,179,8,.5);border-color:rgba(234,179,8,.9);box-shadow:0 0 20px rgba(234,179,8,.4);}
.seq-btn-3.lit{background:rgba(16,185,129,.5);border-color:rgba(16,185,129,.9);box-shadow:0 0 20px rgba(16,185,129,.4);}
.seq-btn-4.lit{background:rgba(168,85,247,.5);border-color:rgba(168,85,247,.9);box-shadow:0 0 20px rgba(168,85,247,.4);}
.seq-btn-5.lit{background:rgba(6,182,212,.5);border-color:rgba(6,182,212,.9);box-shadow:0 0 20px rgba(6,182,212,.4);}
.seq-btn-6.lit{background:rgba(249,115,22,.5);border-color:rgba(249,115,22,.9);box-shadow:0 0 20px rgba(249,115,22,.4);}
.seq-btn-7.lit{background:rgba(236,72,153,.5);border-color:rgba(236,72,153,.9);box-shadow:0 0 20px rgba(236,72,153,.4);}
.seq-btn-8.lit{background:rgba(100,116,139,.5);border-color:rgba(100,116,139,.9);box-shadow:0 0 20px rgba(100,116,139,.4);}
.seq-info{font-size:.65rem;letter-spacing:2px;color:var(--g2);text-align:center;}

/* ══════════════════════════════════
   MISSION 4 — POWER SURGE
══════════════════════════════════ */
.surge-wrap{width:100%;max-width:500px;display:flex;flex-direction:column;align-items:center;gap:16px;}
.surge-gauge{
    width:100%;height:56px;
    border:2px solid var(--border);border-radius:4px;
    background:rgba(0,10,0,.9);position:relative;overflow:hidden;
}
.surge-fill{
    height:100%;transition:width .05s linear;border-radius:3px;
    background:linear-gradient(90deg,var(--g3),var(--g1));
    box-shadow:0 0 20px rgba(0,255,65,.3);
    position:relative;
}
.surge-fill::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(90deg,transparent 0%,rgba(255,255,255,.15) 50%,transparent 100%);
    animation:shimmer 1.5s infinite;
}
@keyframes shimmer{from{transform:translateX(-100%)}to{transform:translateX(100%)};}
.surge-fill.danger{background:linear-gradient(90deg,var(--r2),var(--r1));box-shadow:0 0 20px rgba(255,23,68,.3);}
.surge-pct{
    position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-family:'Orbitron',monospace;font-size:1.1rem;font-weight:900;
    color:#fff;text-shadow:0 0 10px rgba(0,0,0,.5);
    z-index:1;
}
.surge-hold{
    width:180px;height:180px;border-radius:50%;
    border:3px solid var(--g1);background:rgba(0,20,0,.8);
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    cursor:pointer;transition:all .1s;
    font-family:'Orbitron',monospace;font-size:.7rem;letter-spacing:2px;
    color:var(--g1);gap:8px;
    user-select:none;
    box-shadow:0 0 0 rgba(0,255,65,0);
}
.surge-hold:active,.surge-hold.pressing{
    background:rgba(0,50,0,.8);
    box-shadow:0 0 30px rgba(0,255,65,.4),inset 0 0 20px rgba(0,255,65,.1);
    transform:scale(.97);
}
.surge-hold i{font-size:2rem;}
.surge-threat{
    height:6px;width:100%;background:rgba(255,23,68,.1);border-radius:3px;overflow:hidden;
}
.surge-threat-fill{
    height:100%;background:var(--r1);border-radius:3px;
    transition:width .5s ease;
    box-shadow:0 0 8px rgba(255,23,68,.5);
}
.surge-status{font-size:.65rem;letter-spacing:2px;color:var(--g2);text-align:center;min-height:20px;}

/* ══════════════════════════════════
   MISSION 5 — PACKET INTERCEPT (NEW)
══════════════════════════════════ */
.pkt-wrap{width:100%;max-width:640px;display:flex;flex-direction:column;align-items:center;gap:14px;}
.pkt-hud{
    display:flex;justify-content:space-between;align-items:center;
    width:100%;padding:8px 14px;
    border:1px solid var(--border);border-radius:4px;
    background:rgba(0,10,0,.7);
    font-size:.65rem;letter-spacing:2px;
}
.pkt-hud-stat{display:flex;gap:8px;align-items:baseline;}
.pkt-hud-stat span:first-child{color:rgba(0,255,65,.4);}
.pkt-hud-stat span:last-child{
    font-family:'Orbitron',monospace;font-size:1rem;font-weight:900;color:var(--c1);
}
.pkt-field{
    width:100%;height:320px;position:relative;overflow:hidden;
    border:1px solid var(--border);border-radius:4px;
    background:
      linear-gradient(180deg,rgba(0,20,0,.8),rgba(0,5,0,.95)),
      repeating-linear-gradient(90deg,rgba(0,255,65,.04) 0,rgba(0,255,65,.04) 1px,transparent 1px,transparent 60px),
      repeating-linear-gradient(0deg,rgba(0,255,65,.04) 0,rgba(0,255,65,.04) 1px,transparent 1px,transparent 60px);
}
.pkt-field::before{
    content:'';position:absolute;inset:0;pointer-events:none;
    background:linear-gradient(180deg,transparent 0%,rgba(0,255,65,.08) 50%,transparent 100%);
    background-size:100% 8px;
    animation:pktScan 3s linear infinite;
}
@keyframes pktScan{from{background-position-y:0;}to{background-position-y:320px;}}
.pkt-lane{
    position:absolute;left:0;right:0;height:2px;background:rgba(0,255,65,.05);
}
.pkt-packet{
    position:absolute;
    padding:6px 14px;border-radius:4px;cursor:pointer;
    font-family:'JetBrains Mono',monospace;font-size:.7rem;font-weight:700;
    letter-spacing:1px;white-space:nowrap;
    user-select:none;transition:transform .08s;
    will-change:transform;
}
.pkt-packet:hover{transform:scale(1.1);}
.pkt-packet.good{
    background:rgba(0,255,65,.15);border:1px solid var(--g1);color:var(--g1);
    box-shadow:0 0 8px rgba(0,255,65,.4);
}
.pkt-packet.bad{
    background:rgba(255,23,68,.15);border:1px solid var(--r1);color:var(--r1);
    box-shadow:0 0 8px rgba(255,23,68,.4);
}
.pkt-packet.bonus{
    background:rgba(255,193,7,.15);border:1px solid var(--y1);color:var(--y1);
    box-shadow:0 0 12px rgba(255,193,7,.5);
    animation:pulseBonus .6s ease-in-out infinite;
}
@keyframes pulseBonus{50%{transform:scale(1.08);}}
.pkt-packet.clicked{animation:pktPop .3s forwards;pointer-events:none;}
@keyframes pktPop{
    0%{transform:scale(1);opacity:1;}
    100%{transform:scale(1.5);opacity:0;}
}
.pkt-bar{
    width:100%;height:8px;background:rgba(0,255,65,.1);border-radius:4px;overflow:hidden;
}
.pkt-bar-fill{
    height:100%;background:linear-gradient(90deg,var(--g3),var(--g1));
    transition:width .2s ease;box-shadow:0 0 8px rgba(0,255,65,.4);
}

/* ══════════════════════════════════
   ÉCRAN DE SUCCÈS
══════════════════════════════════ */
.success-screen{
    width:100%;max-width:560px;
    display:flex;flex-direction:column;align-items:center;gap:20px;
    animation:fadeSlide .4s ease;
}
.success-header{
    font-family:'Orbitron',monospace;font-size:1.4rem;font-weight:900;
    letter-spacing:4px;color:var(--g1);text-align:center;
    text-shadow:0 0 20px rgba(0,255,65,.6);
    animation:glitch 3s infinite;
}
@keyframes glitch{
    0%,100%{text-shadow:0 0 20px rgba(0,255,65,.6);}
    92%{text-shadow:0 0 20px rgba(0,255,65,.6);}
    93%{text-shadow:-2px 0 var(--r1),2px 0 var(--c1),0 0 20px rgba(0,255,65,.6);}
    94%{text-shadow:0 0 20px rgba(0,255,65,.6);}
    96%{text-shadow:2px 0 var(--r1),-2px 0 var(--c1);}
    97%{text-shadow:0 0 20px rgba(0,255,65,.6);}
}
.success-sub{font-size:.65rem;letter-spacing:2px;color:rgba(0,255,65,.5);text-align:center;}
.hack-title-input{
    width:100%;padding:14px 16px;
    background:rgba(0,10,0,.9);border:1px solid var(--g1);border-radius:3px;
    color:var(--g1);font-family:'JetBrains Mono',monospace;font-size:1rem;
    letter-spacing:2px;text-align:center;outline:none;
    transition:box-shadow .2s;
}
.hack-title-input:focus{box-shadow:0 0 15px rgba(0,255,65,.2);}
.hack-title-input::placeholder{color:rgba(0,255,65,.3);}
.hack-title-counter{font-size:.6rem;color:rgba(0,255,65,.4);text-align:right;align-self:flex-end;}
.success-warning{
    padding:10px 20px;border:1px solid var(--r1);border-radius:3px;
    background:rgba(255,23,68,.05);
    font-size:.65rem;letter-spacing:1px;color:var(--r1);text-align:center;
    line-height:1.6;
}

/* ══════════════════════════════════
   ÉCRAN DÉJÀ HACKÉ / LOCKED
══════════════════════════════════ */
.already-hacked, .locked-out{
    width:100%;max-width:500px;text-align:center;
    display:flex;flex-direction:column;align-items:center;gap:16px;
}
.ah-icon{font-size:3rem;color:var(--r1);animation:pulseRed 1s ease-in-out infinite;}
.ah-title{font-family:'Orbitron',monospace;font-size:1.1rem;font-weight:900;letter-spacing:3px;color:var(--r1);}
.ah-box{
    padding:16px 24px;border:1px solid var(--r2);border-radius:4px;
    background:rgba(255,23,68,.04);font-size:.7rem;color:rgba(255,100,100,.7);
    line-height:1.8;letter-spacing:.5px;
}
.lock-timer{
    font-family:'Orbitron',monospace;font-size:2.5rem;font-weight:900;
    color:var(--y1);letter-spacing:6px;padding:16px 32px;
    border:2px solid var(--y1);border-radius:6px;
    background:rgba(255,193,7,.05);
    text-shadow:0 0 20px rgba(255,193,7,.4);
}

/* ── OVERLAY D'INTRO ── */
.intro-overlay{
    position:fixed;inset:0;z-index:8000;
    background:var(--bg);display:flex;flex-direction:column;
    align-items:center;justify-content:center;gap:12px;
    transition:opacity .5s;
}
.intro-overlay.hide{opacity:0;pointer-events:none;}
.intro-line{
    font-size:.72rem;letter-spacing:2px;color:var(--g2);
    opacity:0;transition:opacity .3s;
}
.intro-line.show{opacity:1;}
.intro-logo{
    font-family:'Orbitron',monospace;font-size:1.6rem;font-weight:900;
    letter-spacing:6px;color:var(--g1);margin-bottom:20px;
    text-shadow:0 0 30px rgba(0,255,65,.5);
}

/* ── BANDEAU D'ÉVÉNEMENT ── */
.event-banner{
    position:fixed;top:64px;left:50%;transform:translateX(-50%) translateY(-150%);
    z-index:9050;padding:12px 28px;
    border:1px solid var(--y1);background:rgba(0,0,0,.92);
    color:var(--y1);font-family:'Orbitron',monospace;font-size:.72rem;
    font-weight:900;letter-spacing:3px;border-radius:4px;
    box-shadow:0 0 25px rgba(255,193,7,.3);
    transition:transform .35s cubic-bezier(.3,1.4,.5,1);
    pointer-events:none;
}
.event-banner.show{transform:translateX(-50%) translateY(0);}
.event-banner.danger{border-color:var(--r1);color:var(--r1);box-shadow:0 0 25px rgba(255,23,68,.4);}

/* ── FLASH SCREEN ── */
.screen-flash{
    position:fixed;inset:0;z-index:9080;pointer-events:none;
    background:var(--g1);opacity:0;transition:opacity .2s;
}
.screen-flash.red{background:var(--r1);}
.screen-flash.flash{opacity:.2;transition:opacity .05s;}
</style>
</head>
<body class="<?php echo empty($CFG['visual_effects']) ? 'no-fx' : ''; ?>">

<canvas id="matrixBg"></canvas>
<div class="glitch-overlay" id="glitchOverlay"></div>
<div class="screen-flash" id="screenFlash"></div>
<div class="event-banner" id="eventBanner"></div>

<?php if ($CFG['intro_enabled'] && !$isAlreadyHacked && !$isLocked): ?>
<!-- INTRO OVERLAY -->
<div class="intro-overlay" id="introOverlay">
    <div class="intro-logo">MDT//BREACH</div>
    <div class="intro-line" id="il1">▶ INITIALISATION DU PROTOCOLE D'INTRUSION...</div>
    <div class="intro-line" id="il2">▶ CONNEXION AU RÉSEAU LYSORIA SÉCURISÉ...</div>
    <div class="intro-line" id="il3">▶ IDENTIFICATION AGENT : <span style="color:var(--c1);"><?php echo htmlspecialchars(strtoupper($username)); ?></span></div>
    <div class="intro-line" id="il4">▶ CHARGEMENT DES MODULES DE BREACH...</div>
    <div class="intro-line" id="il5" style="color:var(--r1);">▶ ATTENTION — INTRUSION ILLÉGALE DÉTECTÉE PAR CONTREMESURES</div>
    <div class="intro-line" id="il6" style="margin-top:10px;">▶ DÉMARRAGE EN COURS<span id="dots">...</span></div>
</div>
<?php endif; ?>

<div class="terminal">
    <!-- TOPBAR -->
    <div class="t-bar">
        <div class="t-bar-logo">MDT // BREACH TERMINAL v3.0</div>
        <div class="t-bar-right">
            <span>AGENT: <span class="t-bar-user"><?php echo htmlspecialchars(strtoupper($username)); ?></span></span>
            <span>CIBLE: <span style="color:var(--g1);">LYSORIA MDT</span></span>
            <?php if (!$isAlreadyHacked && !$isLocked): ?>
            <div class="t-lives" id="tLives">
                <span>VIES</span>
                <div class="t-lives-icons" id="tLivesIcons">
                    <?php for ($i = 0; $i < (int)$CFG['global_lives']; $i++): ?>
                        <span class="t-life <?php echo $i >= $livesRemaining ? 'lost' : ''; ?>" data-idx="<?php echo $i; ?>">♥</span>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="t-bar-status"><span>⬤</span> INTRUSION ACTIVE</div>
        </div>
    </div>

    <?php if (!$isAlreadyHacked && !$isLocked): ?>
    <!-- MISSION PROGRESS -->
    <div class="m-prog" id="missionProg">
        <?php
        $missionLabels = [1=>'M1',2=>'M2',3=>'M3',4=>'M4',5=>'M5'];
        $steps = [];
        foreach ($enabledMissions as $m) $steps[] = $m;
        $stepsCount = count($steps);
        foreach ($steps as $i => $m):
        ?>
            <div class="m-step" id="step<?php echo $m; ?>" data-m="<?php echo $m; ?>">
                <div class="m-step-dot"></div>
                <span><?php echo $missionLabels[$m]; ?></span>
            </div>
            <?php if ($i < $stepsCount - 1): ?>
            <div class="m-step-sep">──</div>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="m-step-sep">──</div>
        <div class="m-step" id="stepEnd" data-m="99">
            <div class="m-step-dot"></div>
            <span>FIN</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- MISSION AREA -->
    <div class="m-center" id="mCenter">

        <?php if ($isAlreadyHacked): ?>
        <!-- DÉJÀ HACKÉ -->
        <div class="already-hacked">
            <div class="ah-icon"><i class="fa-solid fa-skull-crossbones"></i></div>
            <div class="ah-title">SYSTÈME DÉJÀ COMPROMIS</div>
            <div class="ah-box">
                Le MDT de Lysoria est actuellement sous contrôle de :<br>
                <span style="color:var(--r1);font-size:1.1rem;letter-spacing:2px;"><?php echo htmlspecialchars(strtoupper($currentHackTitle)); ?></span><br><br>
                Une nouvelle intrusion est impossible tant que le contrôle actuel est maintenu.<br>
                <span style="color:rgba(255,100,100,.5);font-size:.6rem;">Attendez l'expiration ou neutralisez le système adverse.</span>
            </div>
            <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="btn-hack danger">
                <i class="fa-solid fa-arrow-left"></i> RETOUR MDT
            </a>
        </div>

        <?php elseif ($isLocked): ?>
        <!-- VERROUILLÉ / COOLDOWN -->
        <div class="locked-out">
            <div class="ah-icon" style="color:var(--y1);"><i class="fa-solid fa-lock"></i></div>
            <div class="ah-title" style="color:var(--y1);">INTRUSION BLOQUÉE</div>
            <div class="ah-box" style="border-color:var(--y1);color:rgba(255,193,7,.8);background:rgba(255,193,7,.03);">
                Vous avez épuisé vos <b><?php echo (int)$CFG['global_lives']; ?> tentatives</b>.<br>
                Les contremesures ont détecté votre présence et tracent votre signal.<br><br>
                <span style="color:rgba(255,193,7,.5);font-size:.6rem;">Attendez la fin du cooldown pour réessayer.</span>
            </div>
            <div style="font-size:.6rem;letter-spacing:2px;color:rgba(255,193,7,.5);margin-top:8px;">RÉESSAYER DANS</div>
            <div class="lock-timer" id="lockTimer"><?php echo gmdate($lockSecondsLeft>=3600?'H:i:s':'i:s', $lockSecondsLeft); ?></div>
            <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="btn-hack danger">
                <i class="fa-solid fa-arrow-left"></i> RETOUR MDT
            </a>
        </div>
        <script>
        (function(){
            const end = <?php echo time() + $lockSecondsLeft; ?>;
            const el = document.getElementById('lockTimer');
            function tick(){
                const l = end - Math.floor(Date.now()/1000);
                if(l<=0){ location.reload(); return; }
                const h = Math.floor(l/3600), m = Math.floor((l%3600)/60), s = l%60;
                el.textContent = (h>0 ? String(h).padStart(2,'0')+':' : '') + String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
            }
            tick(); setInterval(tick, 1000);
        })();
        </script>

        <?php else: ?>
        <!-- MISSIONS -->
        <div id="mission1" class="mission-wrap" style="display:none;"></div>
        <div id="mission2" class="mission-wrap" style="display:none;"></div>
        <div id="mission3" class="mission-wrap" style="display:none;"></div>
        <div id="mission4" class="mission-wrap" style="display:none;"></div>
        <div id="mission5" class="mission-wrap" style="display:none;"></div>
        <div id="missionSuccess" class="mission-wrap" style="display:none;"></div>
        <?php endif; ?>

    </div>
</div>

<?php if (!$isAlreadyHacked && !$isLocked): ?>
<script>
/* ══════════════════════════════════════════════════════
   CONFIG GLOBALE (injectée depuis PHP)
══════════════════════════════════════════════════════ */
const HACK_CFG = <?php echo json_encode($clientConfig, JSON_UNESCAPED_UNICODE); ?>;
const SITE_URL = '<?php echo SITE_URL; ?>';

/* Modificateurs de difficulté */
const DIFF_MOD = {
    easy:   {timeMul:1.5, livesMul:1.2, speedMul:1.3, packetMul:.8},
    medium: {timeMul:1.0, livesMul:1.0, speedMul:1.0, packetMul:1.0},
    hard:   {timeMul:.7,  livesMul:.8,  speedMul:.75, packetMul:1.25},
}[HACK_CFG.difficulty] || {timeMul:1,livesMul:1,speedMul:1,packetMul:1};

let livesRemaining = HACK_CFG.livesRemaining;
let currentMission = 0;
let missionTimer = null;
let missionOrder = HACK_CFG.enabledMissions.slice();
let missionIndex = 0;
let randomEventTimer = null;

/* ══════════════════════════════════════════════════════
   MATRIX BACKGROUND
══════════════════════════════════════════════════════ */
if (HACK_CFG.matrixBg) (function(){
    const c = document.getElementById('matrixBg');
    const ctx = c.getContext('2d');
    function resize(){ c.width=window.innerWidth; c.height=window.innerHeight; }
    resize(); window.addEventListener('resize',resize);
    const chars = '01アイウエオカキクケコサシスセソタチツテトナニヌネノABCDEF0123456789';
    let cols = [];
    function init(){ cols=[]; const n=Math.floor(c.width/14); for(let i=0;i<n;i++) cols.push(Math.random()*c.height/14|0); }
    init();
    setInterval(()=>{
        ctx.fillStyle='rgba(0,0,0,.05)';ctx.fillRect(0,0,c.width,c.height);
        ctx.fillStyle='#00ff41';ctx.font='12px JetBrains Mono';
        cols.forEach((y,i)=>{
            ctx.fillText(chars[Math.random()*chars.length|0],i*14,y*14);
            if(y*14>c.height&&Math.random()>.975) cols[i]=0; else cols[i]++;
        });
    },50);
})();

/* ══════════════════════════════════════════════════════
   AUDIO — palette étendue
══════════════════════════════════════════════════════ */
let audioCtx = null;
function getAudio(){ if(!audioCtx) audioCtx=new(window.AudioContext||window.webkitAudioContext)(); return audioCtx; }
function beep(freq=440,dur=0.08,vol=0.05,type='square'){
    if (!HACK_CFG.soundEnabled) return;
    try{
        const ctx=getAudio();
        const o=ctx.createOscillator(); const g=ctx.createGain();
        o.connect(g);g.connect(ctx.destination);
        o.type=type;o.frequency.value=freq;
        g.gain.setValueAtTime(vol,ctx.currentTime);
        g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+dur);
        o.start();o.stop(ctx.currentTime+dur);
    }catch(e){}
}
function beepOk()    { beep(800,.12,.06,'square'); setTimeout(()=>beep(1200,.08,.04,'square'),100); }
function beepErr()   { beep(200,.3,.06,'sawtooth'); }
function beepTick()  { beep(600,.03,.03,'square'); }
function beepType()  { beep(800+Math.random()*400,.02,.025,'square'); }
function beepLife()  { beep(120,.4,.08,'sawtooth'); setTimeout(()=>beep(80,.5,.08,'sawtooth'),80); }
function beepGlitch(){ beep(90+Math.random()*60,.1,.04,'sawtooth'); }
function beepBonus() {
    beep(800,.06,.05,'sine'); setTimeout(()=>beep(1000,.06,.05,'sine'),60);
    setTimeout(()=>beep(1400,.1,.05,'sine'),120);
}
function beepVictory(){
    [523,659,783,1046].forEach((f,i)=>setTimeout(()=>beep(f,.15,.06,'triangle'), i*120));
}

/* ══════════════════════════════════════════════════════
   VISUAL EFFECTS
══════════════════════════════════════════════════════ */
function flashScreen(red=false){
    if (!HACK_CFG.visualEffects) return;
    const el = document.getElementById('screenFlash');
    el.classList.toggle('red', red);
    el.classList.add('flash');
    setTimeout(()=>el.classList.remove('flash'), 80);
}
function triggerGlitch(ms=400){
    if (!HACK_CFG.visualEffects) return;
    const el = document.getElementById('glitchOverlay');
    el.classList.add('active');
    setTimeout(()=>el.classList.remove('active'), ms);
}
function showEventBanner(text, danger=false){
    const el = document.getElementById('eventBanner');
    el.textContent = text;
    el.classList.toggle('danger', danger);
    el.classList.add('show');
    setTimeout(()=>el.classList.remove('show'), 2400);
}

/* ══════════════════════════════════════════════════════
   LIVES MANAGEMENT
══════════════════════════════════════════════════════ */
async function loseLife(){
    beepLife();
    flashScreen(true);
    triggerGlitch(500);
    const icons = document.querySelectorAll('#tLivesIcons .t-life');
    const idx = HACK_CFG.globalLives - livesRemaining;
    if (icons[idx]) icons[idx].classList.add('lost');
    livesRemaining--;
    // Sync serveur
    try {
        const fd = new FormData();
        fd.append('action','lose_life');
        const res = await fetch(location.href+'?ajax=1',{method:'POST',body:fd});
        const data = await res.json();
        if (data.locked) {
            // Redirect to locked screen
            setTimeout(()=>location.reload(), 1200);
            return true;
        }
    } catch(e){}
    return livesRemaining <= 0;
}

/* ══════════════════════════════════════════════════════
   RANDOM EVENTS (perturbations)
══════════════════════════════════════════════════════ */
const EVENTS = [
    { text: '⚡ SURGE ÉLECTROMAGNÉTIQUE', danger:false, fn: ()=>{ triggerGlitch(800); beepGlitch(); } },
    { text: '▼ DÉCALAGE DU SIGNAL', danger:false, fn: ()=>{
        const el = document.getElementById('mCenter');
        el.style.transition='transform .2s';
        el.style.transform='translateX(8px)';
        setTimeout(()=>{el.style.transform='translateX(-6px)';},150);
        setTimeout(()=>{el.style.transform='';},320);
        beepGlitch();
    }},
    { text: '◉ SCAN DE CONTREMESURE', danger:true, fn: ()=>{ flashScreen(true); beep(200,.3,.05,'sawtooth'); } },
    { text: '⚠ PAQUETS CORROMPUS', danger:false, fn: ()=>{ triggerGlitch(400); beepGlitch(); } },
    { text: '▶ PING DE DÉTECTION', danger:false, fn: ()=>{ beep(1200,.15,.04,'sine'); setTimeout(()=>beep(1200,.15,.04,'sine'),200); } },
];
function scheduleRandomEvent(){
    if (!HACK_CFG.randomEvents) return;
    clearTimeout(randomEventTimer);
    const delay = 8000 + Math.random()*14000;
    randomEventTimer = setTimeout(()=>{
        const ev = EVENTS[Math.floor(Math.random()*EVENTS.length)];
        showEventBanner(ev.text, ev.danger);
        ev.fn();
        scheduleRandomEvent();
    }, delay);
}

/* ══════════════════════════════════════════════════════
   INTRO SEQUENCE
══════════════════════════════════════════════════════ */
<?php if ($CFG['intro_enabled']): ?>
(function(){
    const lines=['il1','il2','il3','il4','il5','il6'];
    let i=0;
    const interval=setInterval(()=>{
        if(i<lines.length){ document.getElementById(lines[i])?.classList.add('show'); beepTick(); i++; }
        else clearInterval(interval);
    },400);
    let d=0; const dotEl=document.getElementById('dots');
    const di=setInterval(()=>{ d=(d+1)%4; if(dotEl)dotEl.textContent='.'.repeat(d); },300);
    setTimeout(()=>{
        clearInterval(di);
        const ov=document.getElementById('introOverlay');
        ov.classList.add('hide');
        setTimeout(()=>{ ov.remove(); startNextMission(); scheduleRandomEvent(); },500);
    },3200);
})();
<?php else: ?>
setTimeout(()=>{ startNextMission(); scheduleRandomEvent(); }, 150);
<?php endif; ?>

/* ══════════════════════════════════════════════════════
   UTILS & ORCHESTRATION
══════════════════════════════════════════════════════ */
function updateProgress(m){
    document.querySelectorAll('.m-step').forEach(s=>{
        const n=parseInt(s.dataset.m);
        s.classList.remove('active','done');
        if (m === 99) { s.classList.add('done'); return; }
        const idxOfN = missionOrder.indexOf(n);
        const idxOfM = missionOrder.indexOf(m);
        if (n === 99) return;
        if (idxOfN < idxOfM) s.classList.add('done');
        else if (n === m) s.classList.add('active');
    });
    if (m === 99) document.getElementById('stepEnd')?.classList.add('active');
}

function showMission(n){
    ['mission1','mission2','mission3','mission4','mission5','missionSuccess'].forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.style.display='none';
    });
    const el=document.getElementById('mission'+n);
    if(el){ el.style.display='flex'; el.style.animation='none'; void el.offsetWidth; el.style.animation=''; }
    updateProgress(n);
    currentMission=n;
}

function stopTimer(){ if(missionTimer){clearInterval(missionTimer);missionTimer=null;} }

async function completeMission(n){
    stopTimer();
    beepOk();
    flashScreen(false);
    const fd=new FormData();
    fd.append('action','complete_mission');
    fd.append('mission',n);
    try { await fetch(location.href+'?ajax=1',{method:'POST',body:fd}); } catch(e){}
    setTimeout(()=>startNextMission(), 800);
}

async function failMission(n){
    beepErr();
    stopTimer();
    const gameOver = await loseLife();
    if (gameOver) return;
    setTimeout(()=>startMission(n),1400);
}

function startNextMission(){
    missionIndex = Math.max(0, missionOrder.indexOf(currentMission)) + (currentMission ? 1 : 0);
    if (!currentMission) missionIndex = 0;
    if (missionIndex >= missionOrder.length) {
        showSuccess();
    } else {
        startMission(missionOrder[missionIndex]);
    }
}

function startMission(n){
    currentMission=n;
    updateProgress(n);
    const progressiveBoost = HACK_CFG.progressiveDifficulty ? missionOrder.indexOf(n) * 0.1 : 0;
    window.__diffBoost = progressiveBoost;
    if(n===1) initMission1();
    else if(n===2) initMission2();
    else if(n===3) initMission3();
    else if(n===4) initMission4();
    else if(n===5) initMission5();
}

function diffAdj(base, key){
    const mod = DIFF_MOD[key] || 1;
    const boost = (window.__diffBoost || 0);
    if (key === 'timeMul') return Math.max(5, Math.round(base * mod * (1 - boost)));
    if (key === 'speedMul') return Math.max(100, Math.round(base * mod * (1 - boost)));
    if (key === 'packetMul') return Math.max(1, Math.round(base * mod * (1 + boost)));
    return base;
}

/* ══════════════════════════════════════════════════════
   MISSION 1 — FIREWALL BYPASS
══════════════════════════════════════════════════════ */
let fw_path=[], fw_clicks=[], fw_traps=[];

function initMission1(){
    fw_path=[]; fw_clicks=[]; fw_traps=[];
    const PATH_LEN = Math.max(3, Math.round(HACK_CFG.m1.pathLength * (1 + (window.__diffBoost||0))));
    const GRID = 25;
    const SHOW_TIME = diffAdj(HACK_CFG.m1.showTime, 'speedMul');
    let used=new Set();
    while(fw_path.length<PATH_LEN){
        let n; do{ n=Math.floor(Math.random()*GRID); }while(used.has(n));
        used.add(n); fw_path.push(n);
    }
    // Traps en mode hard
    if (HACK_CFG.difficulty === 'hard') {
        let trapCount = 2;
        while (fw_traps.length < trapCount) {
            let n; do { n = Math.floor(Math.random()*GRID); } while (used.has(n));
            used.add(n); fw_traps.push(n);
        }
    }

    const container=document.getElementById('mission1');
    container.innerHTML=`
        <div class="m-title-bar">MISSION 1 — FIREWALL BYPASS</div>
        <div class="m-desc">Mémorisez les <span style="color:var(--c1);">${PATH_LEN} nœuds</span> du chemin sécurisé qui vont s'illuminer.<br>Reproduisez le chemin dans l'ordre exact.${fw_traps.length?' <span style="color:var(--r1);">⚠ Évitez les pièges rouges.</span>':''}</div>
        <div style="display:flex;align-items:center;gap:20px;">
            <div style="text-align:center;">
                <div class="m-timer-label">PROGRESSION</div>
                <div id="fw_prog" class="m-timer" style="font-size:.9rem;color:var(--g2);">0 / ${PATH_LEN}</div>
            </div>
        </div>
        <div class="fw-grid" id="fwGrid"></div>
        <div class="m-feedback" id="fw_fb"></div>
    `;
    showMission(1);
    const grid=document.getElementById('fwGrid');
    for(let i=0;i<25;i++){
        const n=document.createElement('div');
        n.className='fw-node';
        n.dataset.i=i;
        n.innerHTML=`<span>${String.fromCharCode(65+i)}</span>`;
        n.addEventListener('click',()=>fw_click(i));
        grid.appendChild(n);
    }
    setTimeout(()=>fw_flash(SHOW_TIME),400);
}

function fw_flash(showTime){
    setFb('fw_fb','MÉMORISEZ LE CHEMIN...','');
    const nodes=document.querySelectorAll('.fw-node');
    nodes.forEach(n=>n.classList.add('disabled'));
    let delay=0;
    // Flash traps briefly in red first
    fw_traps.forEach(idx=>{
        setTimeout(()=>{ nodes[idx].classList.add('trap'); beepGlitch(); },delay);
        delay+=200;
    });
    fw_path.forEach(idx=>{
        setTimeout(()=>{ nodes[idx].classList.add('path'); beepTick(); },delay);
        delay+=250;
    });
    setTimeout(()=>{
        nodes.forEach(n=>n.classList.remove('path','trap','disabled'));
        setFb('fw_fb','REPRODUISEZ LE CHEMIN !','ok');
    }, delay + showTime);
}

function fw_click(idx){
    if(fw_clicks.length>=fw_path.length) return;
    const node=document.querySelector(`.fw-node[data-i="${idx}"]`);
    if (fw_traps.includes(idx)) {
        node.classList.add('wrong');
        setFb('fw_fb','PIÈGE DÉCLENCHÉ — VIE PERDUE','err');
        document.querySelectorAll('.fw-node').forEach(n=>n.classList.add('disabled'));
        failMission(1);
        return;
    }
    const expected=fw_path[fw_clicks.length];
    if(idx===expected){
        fw_clicks.push(idx);
        node.classList.add('selected');
        beepTick();
        document.getElementById('fw_prog').textContent=fw_clicks.length+' / '+fw_path.length;
        if(fw_clicks.length===fw_path.length){
            setFb('fw_fb','CHEMIN VALIDÉ ✓','ok');
            document.querySelectorAll('.fw-node').forEach(n=>n.classList.add('disabled'));
            completeMission(1);
        }
    } else {
        node.classList.add('wrong');
        setFb('fw_fb','NŒUD INCORRECT — VIE PERDUE','err');
        document.querySelectorAll('.fw-node').forEach(n=>n.classList.add('disabled'));
        failMission(1);
    }
}

/* ══════════════════════════════════════════════════════
   MISSION 2 — CIPHER DECODE
══════════════════════════════════════════════════════ */
const CIPHER_SYMBOLS = ['✦','◈','⬡','⬢','◉','⊕','⊗','△','◯','▣','⬟','⬠','⊞','⊡','◬'];
let cipher_map={}, cipher_word='', cipher_encoded='', cipher_input='', cipher_seconds=45, cipher_int=null;

function initMission2(){
    cipher_input='';
    const TIME_LIMIT = diffAdj(HACK_CFG.m2.timeLimit, 'timeMul');
    const words=['ACCES','PROXY','VIRUS','TROIE','DELTA','NEXUS','OMEGA','SIGMA','CYBER','GHOST','BLADE','SNEAK','RECON','SHELL','FORKS','MATHS','ORION','PULSE','TRACE','KRNEL'];
    cipher_word=words[Math.floor(Math.random()*words.length)];
    const lettersUsed=[...new Set(cipher_word.split('').concat('ABCDEFGHIJ'.split('')))].slice(0,10);
    const shuffledSyms=[...CIPHER_SYMBOLS].sort(()=>Math.random()-.5).slice(0,10);
    cipher_map={};
    lettersUsed.forEach((l,i)=>{ cipher_map[l]=shuffledSyms[i]; });
    cipher_encoded=cipher_word.split('').map(l=>cipher_map[l]||'?').join('');

    const container=document.getElementById('mission2');
    container.innerHTML=`
        <div class="m-title-bar">MISSION 2 — DÉCHIFFREMENT CRYPTÉ</div>
        <div class="m-desc">Utilisez la table de déchiffrement pour décoder le mot crypté.<br>Cliquez les lettres dans l'ordre.</div>
        <div class="cipher-wrap">
            <div class="cipher-legend">${lettersUsed.map((l,i)=>`
                <div class="cipher-pair">
                    <div class="cipher-sym">${shuffledSyms[i]}</div>
                    <div class="cipher-ltr">${l}</div>
                </div>`).join('')}</div>
            <div class="cipher-coded">${cipher_encoded.split('').join(' ')}</div>
            <div class="cipher-input" id="cipherInput"><span class="cursor">_</span></div>
            <div class="cipher-keyboard" id="cipherKbd"></div>
        </div>
        <div style="display:flex;align-items:center;gap:20px;">
            <div style="text-align:center;">
                <div class="m-timer-label">TEMPS</div>
                <div id="cipher_timer" class="m-timer">${TIME_LIMIT}</div>
            </div>
            <div class="m-feedback" id="cipher_fb"></div>
        </div>
    `;
    showMission(2);
    const kbd=document.getElementById('cipherKbd');
    lettersUsed.forEach(l=>{
        const btn=document.createElement('div');
        btn.className='cipher-key';
        btn.textContent=l;
        btn.addEventListener('click',()=>cipher_type(l));
        kbd.appendChild(btn);
    });
    const bk=document.createElement('div');
    bk.className='cipher-key backspace';
    bk.innerHTML='<i class="fa-solid fa-delete-left"></i>';
    bk.addEventListener('click',cipher_back);
    kbd.appendChild(bk);
    cipher_seconds=TIME_LIMIT;
    cipher_int=setInterval(()=>{
        cipher_seconds--;
        const el=document.getElementById('cipher_timer');
        if(el) el.textContent=cipher_seconds;
        if(cipher_seconds<=10 && el) el.classList.add('warn');
        if(cipher_seconds<=5 && el){ el.classList.remove('warn'); el.classList.add('danger'); }
        if(cipher_seconds<=0){
            clearInterval(cipher_int);
            setFb('cipher_fb','TEMPS ÉCOULÉ — VIE PERDUE','err');
            failMission(2);
        }
    },1000);
    missionTimer = cipher_int;
}

function cipher_type(l){
    if(cipher_input.length>=cipher_word.length) return;
    cipher_input+=l;
    beepType();
    renderCipherInput();
    if(cipher_input.length===cipher_word.length){
        if(cipher_input===cipher_word){
            clearInterval(cipher_int);
            setFb('cipher_fb','CODE VALIDÉ ✓','ok');
            completeMission(2);
        } else {
            clearInterval(cipher_int);
            setFb('cipher_fb','CODE INCORRECT — VIE PERDUE','err');
            failMission(2);
        }
    }
}
function cipher_back(){
    if(cipher_input.length>0){ cipher_input=cipher_input.slice(0,-1); renderCipherInput(); beepTick(); }
}
function renderCipherInput(){
    const el=document.getElementById('cipherInput');
    if(!el) return;
    el.innerHTML=cipher_input.split('').map(l=>`<span style="margin:0 2px">${l}</span>`).join('')+'<span class="cursor">_</span>';
}

/* ══════════════════════════════════════════════════════
   MISSION 3 — SEQUENCE OVERRIDE (Simon Says)
══════════════════════════════════════════════════════ */
let seq_sequence=[], seq_player=[], seq_round=0, seq_playing=false;

function initMission3(){
    seq_sequence=[]; seq_player=[]; seq_round=0; seq_playing=false;
    const ROUNDS = HACK_CFG.m3.rounds;
    const BASE = HACK_CFG.m3.baseLength;
    const SPEED = diffAdj(HACK_CFG.m3.speed, 'speedMul');
    window.__seq_maxRounds = ROUNDS;
    window.__seq_base = BASE;
    window.__seq_speed = SPEED;
    const container=document.getElementById('mission3');
    container.innerHTML=`
        <div class="m-title-bar">MISSION 3 — OVERRIDE SÉQUENTIEL</div>
        <div class="m-desc">Observez la séquence des nœuds, puis reproduisez-la.<br>${ROUNDS} ronde(s) de difficulté croissante.</div>
        <div class="seq-info" id="seqInfo">PRÉPARATION...</div>
        <div class="seq-grid" id="seqGrid"></div>
        <div class="m-feedback" id="seq_fb"></div>
    `;
    showMission(3);
    const grid=document.getElementById('seqGrid');
    for(let i=0;i<9;i++){
        const btn=document.createElement('div');
        btn.className=`seq-btn seq-btn-${i}`;
        btn.dataset.i=i;
        btn.innerHTML=`<span style="font-size:.5rem;letter-spacing:1px;">N${i+1}</span>`;
        btn.addEventListener('click',()=>seq_click(i));
        grid.appendChild(btn);
    }
    setTimeout(()=>seq_start_round(),600);
}

function seq_start_round(){
    seq_round++;
    seq_player=[];
    seq_playing=true;
    const len = window.__seq_base + seq_round;
    const speed = window.__seq_speed;
    while(seq_sequence.length<len) seq_sequence.push(Math.floor(Math.random()*9));
    document.getElementById('seqInfo').textContent=`RONDE ${seq_round}/${window.__seq_maxRounds} — MÉMORISEZ (${seq_sequence.length} nœuds)`;
    document.querySelectorAll('.seq-btn').forEach(b=>b.style.pointerEvents='none');
    let delay=500;
    seq_sequence.forEach(i=>{
        setTimeout(()=>{
            const btn=document.querySelector(`.seq-btn[data-i="${i}"]`);
            btn.classList.add('lit'); beepTick();
            setTimeout(()=>btn.classList.remove('lit'),Math.min(300,speed-50));
        },delay);
        delay+=speed;
    });
    setTimeout(()=>{
        seq_playing=false;
        document.querySelectorAll('.seq-btn').forEach(b=>b.style.pointerEvents='');
        document.getElementById('seqInfo').textContent=`REPRODUISEZ LA SÉQUENCE (${seq_sequence.length} nœuds)`;
        setFb('seq_fb','À VOUS DE JOUER...','ok');
    },delay+200);
}

function seq_click(i){
    if(seq_playing) return;
    const btn=document.querySelector(`.seq-btn[data-i="${i}"]`);
    btn.classList.add('lit'); beepTick();
    setTimeout(()=>btn.classList.remove('lit'),200);
    seq_player.push(i);
    const pos=seq_player.length-1;
    if(seq_player[pos]!==seq_sequence[pos]){
        setFb('seq_fb','SÉQUENCE INCORRECTE — VIE PERDUE','err');
        seq_sequence=[]; seq_round=0;
        failMission(3);
        return;
    }
    if(seq_player.length===seq_sequence.length){
        setFb('seq_fb','RONDE VALIDÉE ✓','ok');
        if(seq_round>=window.__seq_maxRounds){
            completeMission(3);
        } else {
            setTimeout(()=>seq_start_round(),700);
        }
    }
}

/* ══════════════════════════════════════════════════════
   MISSION 4 — POWER SURGE
══════════════════════════════════════════════════════ */
let surge_power=0, surge_holding=false, surge_holdTime=0, surge_needed=5;
let surge_int=null, surge_threatInt=null, surge_keyDown=false;

function initMission4(){
    surge_power=0; surge_holding=false; surge_holdTime=0;
    const HOLD = HACK_CFG.m4.holdTime;
    const THRESHOLD = HACK_CFG.m4.threshold;
    surge_needed = HOLD;
    const container=document.getElementById('mission4');
    container.innerHTML=`
        <div class="m-title-bar">MISSION 4 — SYSTEM BREACH</div>
        <div class="m-desc">Maintenez la puissance au-dessus de <span style="color:var(--g1);">${THRESHOLD}%</span> pendant <span style="color:var(--c1);">${HOLD} secondes</span> consécutives.<br>Les surges de sécurité draineront votre énergie aléatoirement.</div>
        <div class="surge-wrap">
            <div style="width:100%;display:flex;justify-content:space-between;font-size:.6rem;letter-spacing:2px;color:rgba(0,255,65,.4);">
                <span>PUISSANCE SYSTÈME</span>
                <span id="surgeHoldDisplay">MAINTIEN: 0.0s / ${HOLD}.0s</span>
            </div>
            <div class="surge-gauge">
                <div class="surge-fill" id="surgeFill" style="width:0%"></div>
                <div class="surge-pct" id="surgePct">0%</div>
            </div>
            <div style="width:100%;">
                <div style="font-size:.58rem;letter-spacing:2px;color:rgba(255,23,68,.5);margin-bottom:4px;">MENACE SÉCURITÉ</div>
                <div class="surge-threat"><div class="surge-threat-fill" id="surgeThreat" style="width:0%"></div></div>
            </div>
            <div class="surge-hold" id="surgeBtn">
                <i class="fa-solid fa-bolt"></i>
                <span>APPUYER</span>
                <span style="font-size:.55rem;opacity:.6;">MAINTENIR</span>
            </div>
            <div class="surge-status" id="surgeStatus">APPUYEZ POUR COMMENCER</div>
        </div>
        <div class="m-feedback" id="surge_fb"></div>
    `;
    showMission(4);
    const btn=document.getElementById('surgeBtn');
    btn.addEventListener('mousedown',surge_start_press);
    btn.addEventListener('touchstart',surge_start_press,{passive:true});
    btn.addEventListener('mouseup',surge_end_press);
    btn.addEventListener('mouseleave',surge_end_press);
    btn.addEventListener('touchend',surge_end_press);
    const kd = e => { if(e.code==='Space'&&!e.repeat){ e.preventDefault(); surge_start_press(); } };
    const ku = e => { if(e.code==='Space') surge_end_press(); };
    document.addEventListener('keydown',kd);
    document.addEventListener('keyup',ku);
    window.__surge_keyHandlers = [kd, ku];

    surge_int=setInterval(()=>{
        if(!surge_holding && surge_power>0){ surge_power=Math.max(0,surge_power-1.5); }
        if(surge_holding){ surge_power=Math.min(100,surge_power+3); }
        if(surge_power>=THRESHOLD){
            surge_holdTime+=0.05;
            document.getElementById('surgeHoldDisplay').textContent=`MAINTIEN: ${surge_holdTime.toFixed(1)}s / ${HOLD}.0s`;
        } else {
            if(surge_holdTime>0) surge_holdTime=Math.max(0,surge_holdTime-0.1);
            document.getElementById('surgeHoldDisplay').textContent=`MAINTIEN: ${surge_holdTime.toFixed(1)}s / ${HOLD}.0s`;
        }
        renderSurge();
        if(surge_holdTime>=surge_needed){
            clearInterval(surge_int);
            clearInterval(surge_threatInt);
            document.removeEventListener('keydown', window.__surge_keyHandlers[0]);
            document.removeEventListener('keyup', window.__surge_keyHandlers[1]);
            setFb('surge_fb','BREACH RÉUSSI ✓','ok');
            document.getElementById('surgeStatus').textContent='INTRUSION COMPLÈTE';
            completeMission(4);
        }
    },50);

    const threatInterval = 2000 + Math.random()*2000;
    surge_threatInt=setInterval(()=>{
        const threat=20+Math.random()*40;
        surge_power=Math.max(0,surge_power-threat);
        document.getElementById('surgeThreat').style.width=threat+'%';
        document.getElementById('surgeStatus').textContent='⚡ SURGE SÉCURITÉ !';
        beep(180,.2,.08,'sawtooth');
        setTimeout(()=>{
            document.getElementById('surgeThreat').style.width='0%';
            document.getElementById('surgeStatus').textContent=surge_holding?'MAINTIEN...':'APPUYEZ !';
        },500);
    }, threatInterval);
}

function surge_start_press(){
    surge_holding=true;
    const btn=document.getElementById('surgeBtn');
    if(btn) btn.classList.add('pressing');
    const s=document.getElementById('surgeStatus');
    if(s) s.textContent='MAINTIEN...';
    beepTick();
}
function surge_end_press(){
    surge_holding=false;
    const btn=document.getElementById('surgeBtn');
    if(btn) btn.classList.remove('pressing');
    const s=document.getElementById('surgeStatus');
    if(s) s.textContent='APPUYEZ !';
}
function renderSurge(){
    const fill=document.getElementById('surgeFill');
    const pct=document.getElementById('surgePct');
    if(!fill||!pct) return;
    fill.style.width=surge_power+'%';
    pct.textContent=Math.round(surge_power)+'%';
    if(surge_power<40) fill.classList.add('danger'); else fill.classList.remove('danger');
}

/* ══════════════════════════════════════════════════════
   MISSION 5 — PACKET INTERCEPT (NEW)
══════════════════════════════════════════════════════ */
let pkt_captured=0, pkt_needed=10, pkt_seconds=30, pkt_timer=null, pkt_spawner=null, pkt_running=false;

function initMission5(){
    pkt_captured=0;
    pkt_needed = HACK_CFG.m5.packetsNeeded;
    pkt_seconds = diffAdj(HACK_CFG.m5.timeLimit, 'timeMul');
    pkt_running = true;

    const container=document.getElementById('mission5');
    container.innerHTML=`
        <div class="m-title-bar">MISSION 5 — INTERCEPTION DE PAQUETS</div>
        <div class="m-desc">
            Interceptez les paquets <span style="color:var(--g1);">verts</span> (données valides) avant qu'ils ne disparaissent.<br>
            Évitez les <span style="color:var(--r1);">rouges</span> (anti-virus) — ils retirent un paquet validé.
            Les <span style="color:var(--y1);">dorés</span> sont des bonus de 2 paquets.
        </div>
        <div class="pkt-wrap">
            <div class="pkt-hud">
                <div class="pkt-hud-stat"><span>INTERCEPTÉS :</span><span id="pktCaptured">0 / ${pkt_needed}</span></div>
                <div class="pkt-hud-stat"><span>TEMPS :</span><span id="pktTimer">${pkt_seconds}</span></div>
            </div>
            <div class="pkt-bar"><div class="pkt-bar-fill" id="pktBar" style="width:0%"></div></div>
            <div class="pkt-field" id="pktField"></div>
            <div class="m-feedback" id="pkt_fb"></div>
        </div>
    `;
    showMission(5);

    pkt_timer = setInterval(()=>{
        pkt_seconds--;
        const t = document.getElementById('pktTimer');
        if (t) t.textContent = pkt_seconds;
        if (pkt_seconds <= 10 && t) t.style.color = 'var(--y1)';
        if (pkt_seconds <= 5 && t) t.style.color = 'var(--r1)';
        if (pkt_seconds <= 0) {
            pkt_end(false);
            setFb('pkt_fb','TEMPS ÉCOULÉ — VIE PERDUE','err');
            failMission(5);
        }
    }, 1000);
    missionTimer = pkt_timer;

    const baseSpawnDelay = HACK_CFG.difficulty === 'hard' ? 550 : (HACK_CFG.difficulty === 'easy' ? 900 : 720);
    function spawn(){
        if (!pkt_running) return;
        pkt_spawn();
        pkt_spawner = setTimeout(spawn, baseSpawnDelay + Math.random()*350);
    }
    spawn();
}

const PKT_WORDS = ['0x4A2F','DATA','PKT','CRC','SYN','ACK','0xFF','DB01','0xA3','TCP','UDP','0x7E','PING','KEY','AUTH'];

function pkt_spawn(){
    const field = document.getElementById('pktField');
    if (!field) return;
    const w = field.clientWidth, h = field.clientHeight;
    const roll = Math.random();
    let type = 'good';
    if (roll < 0.7) type = 'good';
    else if (roll < 0.92) type = 'bad';
    else type = 'bonus';

    const el = document.createElement('div');
    el.className = 'pkt-packet ' + type;
    const label = type === 'bonus' ? '★ BONUS' : PKT_WORDS[Math.floor(Math.random()*PKT_WORDS.length)];
    el.textContent = label;
    const startY = 20 + Math.random() * (h - 60);
    const duration = 2400 + Math.random()*1600 - (window.__diffBoost||0)*1000;
    el.style.top = startY + 'px';
    el.style.left = '-100px';
    el.style.transition = `transform ${duration}ms linear, opacity .2s`;
    el.addEventListener('click', ()=>pkt_click(el, type));
    field.appendChild(el);
    requestAnimationFrame(()=>{
        el.style.transform = `translateX(${w+120}px)`;
    });
    setTimeout(()=>{ if(el.parentNode) el.remove(); }, duration+100);
}

function pkt_click(el, type){
    if (el.classList.contains('clicked')) return;
    el.classList.add('clicked');
    if (type === 'good') {
        pkt_captured++;
        beepTick();
    } else if (type === 'bonus') {
        pkt_captured += 2;
        beepBonus();
    } else {
        pkt_captured = Math.max(0, pkt_captured - 1);
        beepGlitch();
        flashScreen(true);
    }
    const cap = document.getElementById('pktCaptured');
    const bar = document.getElementById('pktBar');
    if (cap) cap.textContent = pkt_captured + ' / ' + pkt_needed;
    if (bar) bar.style.width = Math.min(100, (pkt_captured/pkt_needed)*100) + '%';

    if (pkt_captured >= pkt_needed) {
        pkt_end(true);
        setFb('pkt_fb','FLUX INTERCEPTÉ ✓','ok');
        completeMission(5);
    }
}

function pkt_end(success){
    pkt_running = false;
    clearInterval(pkt_timer);
    clearTimeout(pkt_spawner);
}

/* ══════════════════════════════════════════════════════
   ÉCRAN DE SUCCÈS
══════════════════════════════════════════════════════ */
function showSuccess(){
    updateProgress(99);
    clearTimeout(randomEventTimer);
    ['mission1','mission2','mission3','mission4','mission5'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.style.display='none';
    });
    const s=document.getElementById('missionSuccess');
    s.style.display='flex';
    s.innerHTML=`
        <div class="success-header">✓ INTRUSION COMPLÈTE</div>
        <div class="success-sub">ACCÈS TOTAL AU SYSTÈME LYSORIA MDT</div>
        <div style="width:100%;max-width:480px;display:flex;flex-direction:column;gap:12px;">
            <div style="font-size:.6rem;letter-spacing:2px;color:rgba(0,255,65,.5);">
                IDENTIFIANT DE L'INTRUSION (visible sur le MDT)
            </div>
            <input type="text" class="hack-title-input" id="hackTitleInput"
                   placeholder="ENTREZ VOTRE IDENTIFIANT..." maxlength="60"
                   oninput="document.getElementById('hackCharCount').textContent=this.value.length+'/60'">
            <div class="hack-title-counter" id="hackCharCount">0/60</div>
            <div class="success-warning">
                ⚠ Le MDT sera compromis pendant <span style="color:var(--r1);">${Math.round(<?php echo (int)$CFG['hack_duration']; ?>/60)} MINUTES</span><br>
                Cette action est tracée et peut avoir des conséquences RP.
            </div>
            <button class="btn-hack primary" id="submitHackBtn" onclick="submitHack()">
                <i class="fa-solid fa-terminal"></i> CONFIRMER L'INTRUSION
            </button>
            <div class="m-feedback" id="submit_fb"></div>
        </div>
    `;
    s.style.animation='none'; void s.offsetWidth; s.style.animation='';
    beepVictory();
}

async function submitHack(){
    const title=document.getElementById('hackTitleInput')?.value.trim();
    const btn=document.getElementById('submitHackBtn');
    const fb=document.getElementById('submit_fb');
    if(!title){ setFbEl(fb,'IDENTIFIANT REQUIS','err'); return; }
    btn.disabled=true; btn.textContent='TRANSMISSION EN COURS...';
    const fd=new FormData();
    fd.append('action','submit_hack');
    fd.append('title',title);
    try{
        const res=await fetch(location.href+'?ajax=1',{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){
            setFbEl(fb,'INTRUSION ENREGISTRÉE — REDIRECTION...','ok');
            setTimeout(()=>window.location.href=data.redirect,1200);
        } else {
            setFbEl(fb,data.error||'ERREUR','err');
            btn.disabled=false; btn.textContent='CONFIRMER L\'INTRUSION';
        }
    }catch(e){
        setFbEl(fb,'ERREUR RÉSEAU','err');
        btn.disabled=false;
    }
}

/* ── Helpers feedback ── */
function setFb(id,msg,type,styleStr=''){
    const el=document.getElementById(id);
    if(!el) return;
    el.textContent=msg;
    el.className='m-feedback'+(type?' '+type:'');
    if(styleStr) el.style.cssText=styleStr;
}
function setFbEl(el,msg,type){
    if(!el) return;
    el.textContent=msg;
    el.className='m-feedback'+(type?' '+type:'');
}
</script>
<?php endif; ?>

</body>
</html>
