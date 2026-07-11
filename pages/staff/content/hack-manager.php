<?php
// pages/staff/content/hack-manager.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();

$hackAddonActive = false;
try {
    $hackAddonActive = (bool) $db->query("SELECT setting_value FROM admin_settings WHERE setting_key = 'addon_hack_mdt' LIMIT 1")->fetchColumn();
} catch (Throwable $e) {
    $hackAddonActive = false;
}
if (!$hackAddonActive) {
    if (hasRole(['*', 'Administrateur', 'Développeur'])) {
        redirect(SITE_URL . '/pages/staff/extensions/addons.php');
    }
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}
mdt_require_product('addon_hack_mdt', 'Hack MDT');

$pageTitle  = 'Gestion Hack MDT';
$breadcrumb = [['label' => 'Sécurité'], ['label' => 'Hack MDT']];
$success = $error = '';

/* ── Assurer les tables ── */
try {
    $db->exec("CREATE TABLE IF NOT EXISTS mdt_hack_state (
        id INT DEFAULT 1, is_hacked TINYINT(1) DEFAULT 0,
        hack_title VARCHAR(200) DEFAULT NULL, hacked_by VARCHAR(100) DEFAULT NULL,
        hacked_by_user_id INT DEFAULT NULL, hacked_at DATETIME DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL, cancelled_by INT DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO mdt_hack_state (id, is_hacked) VALUES (1, 0)");

    $db->exec("CREATE TABLE IF NOT EXISTS mdt_hack_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hack_title VARCHAR(200),
        hacked_by VARCHAR(100),
        hacked_by_user_id INT,
        hacked_at DATETIME,
        expires_at DATETIME,
        ended_at DATETIME,
        end_reason ENUM('expired','cancelled') DEFAULT 'cancelled',
        cancelled_by_name VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Configuration globale du hack (ligne unique id=1)
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

    // Tentatives / cooldown par utilisateur
    $db->exec("CREATE TABLE IF NOT EXISTS mdt_hack_attempts (
        user_id INT PRIMARY KEY,
        lives_used INT DEFAULT 0,
        locked_until DATETIME DEFAULT NULL,
        last_fail_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/* ── Helpers config ── */
function mdt_hack_get_config(PDO $db): array {
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

/* ── POST : sauvegarder la configuration ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    try {
        $int = function($k, $min, $max, $def) {
            $v = isset($_POST[$k]) ? (int)$_POST[$k] : $def;
            return max($min, min($max, $v));
        };
        $bool = fn($k) => !empty($_POST[$k]) ? 1 : 0;
        $diff = in_array($_POST['difficulty'] ?? '', ['easy','medium','hard'], true) ? $_POST['difficulty'] : 'medium';

        $fields = [
            'hack_duration' => $int('hack_duration', 60, 86400, 3600),
            'global_lives'  => $int('global_lives', 1, 10, 3),
            'fail_cooldown' => $int('fail_cooldown', 30, 86400, 600),
            'sound_enabled' => $bool('sound_enabled'),
            'visual_effects'=> $bool('visual_effects'),
            'difficulty'    => $diff,
            'progressive_difficulty' => $bool('progressive_difficulty'),
            'random_events' => $bool('random_events'),
            'intro_enabled' => $bool('intro_enabled'),
            'matrix_bg'     => $bool('matrix_bg'),
            'm1_enabled'    => $bool('m1_enabled'),
            'm1_path_length'=> $int('m1_path_length', 3, 15, 6),
            'm1_show_time'  => $int('m1_show_time', 400, 6000, 1500),
            'm2_enabled'    => $bool('m2_enabled'),
            'm2_time_limit' => $int('m2_time_limit', 10, 300, 45),
            'm3_enabled'    => $bool('m3_enabled'),
            'm3_rounds'     => $int('m3_rounds', 1, 10, 3),
            'm3_base_length'=> $int('m3_base_length', 2, 12, 4),
            'm3_speed'      => $int('m3_speed', 150, 2000, 500),
            'm4_enabled'    => $bool('m4_enabled'),
            'm4_hold_time'  => $int('m4_hold_time', 1, 30, 5),
            'm4_threshold'  => $int('m4_threshold', 20, 99, 80),
            'm5_enabled'    => $bool('m5_enabled'),
            'm5_packets_needed' => $int('m5_packets_needed', 3, 40, 10),
            'm5_time_limit' => $int('m5_time_limit', 10, 180, 30),
        ];
        $sets = implode(',', array_map(fn($k)=>"$k=:$k", array_keys($fields)));
        $stmt = $db->prepare("UPDATE mdt_hack_config SET $sets WHERE id=1");
        $stmt->execute($fields);
        mdt_audit_log((int)$_SESSION['user_id'], 'STAFF', 'Config Hack mise à jour', 'Paramètres du système de hack modifiés', 'security', null);
        $success = 'Configuration sauvegardée avec succès.';
    } catch (Exception $e) { $error = 'Erreur config : '.$e->getMessage(); }
    header("Location: hack-manager.php?ok=".urlencode($success?:''));
    exit;
}

/* ── POST : réinitialiser cooldown utilisateurs ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_attempts') {
    try {
        $db->exec("DELETE FROM mdt_hack_attempts");
        mdt_audit_log((int)$_SESSION['user_id'], 'STAFF', 'Reset cooldowns hack', 'Tous les cooldowns de hack ont été réinitialisés', 'security', null);
        $success = 'Tous les cooldowns ont été réinitialisés.';
    } catch (Exception $e) { $error = 'Erreur : '.$e->getMessage(); }
    header("Location: hack-manager.php?ok=".urlencode($success?:''));
    exit;
}

/* ── POST : annuler le hack ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_hack') {
    try {
        // Archiver dans les logs avant de reset
        $hs = $db->query("SELECT * FROM mdt_hack_state WHERE id=1")->fetch();
        if ($hs && $hs['is_hacked']) {
            $db->prepare("INSERT INTO mdt_hack_log (hack_title,hacked_by,hacked_by_user_id,hacked_at,expires_at,ended_at,end_reason,cancelled_by_name) VALUES (?,?,?,?,?,NOW(),'cancelled',?)")
               ->execute([$hs['hack_title'],$hs['hacked_by'],$hs['hacked_by_user_id'],$hs['hacked_at'],$hs['expires_at'], $_SESSION['user_username']??'Staff']);
        }
        $db->prepare("UPDATE mdt_hack_state SET is_hacked=0, hack_title=NULL, cancelled_by=?, cancelled_at=NOW() WHERE id=1")
           ->execute([$_SESSION['user_id']]);
        mdt_audit_log(
            (int) $_SESSION['user_id'],
            'STAFF',
            'Hack MDT annulé',
            'MDT hack annulé par ' . ($_SESSION['user_username'] ?? ''),
            'security',
            null
        );
        $success = 'Hack annulé — MDT restauré avec succès.';
    } catch (Exception $e) { $error = 'Erreur : '.$e->getMessage(); }
    header("Location: hack-manager.php?ok=".urlencode($success?:''));
    exit;
}

if (!empty($_GET['ok'])) $success = $_GET['ok'];

/* ── Charger état actuel ── */
$hackState = null;
$hackActive = false;
$secondsLeft = 0;
try {
    $hackState = $db->query("SELECT * FROM mdt_hack_state WHERE id=1 LIMIT 1")->fetch();
    if ($hackState && $hackState['is_hacked'] && $hackState['expires_at'] && strtotime($hackState['expires_at']) > time()) {
        $hackActive  = true;
        $secondsLeft = strtotime($hackState['expires_at']) - time();
    } elseif ($hackState && $hackState['is_hacked']) {
        // Expiré → auto-reset + log
        $db->prepare("INSERT INTO mdt_hack_log (hack_title,hacked_by,hacked_by_user_id,hacked_at,expires_at,ended_at,end_reason) VALUES (?,?,?,?,?,NOW(),'expired')")
           ->execute([$hackState['hack_title'],$hackState['hacked_by'],$hackState['hacked_by_user_id'],$hackState['hacked_at'],$hackState['expires_at']]);
        $db->exec("UPDATE mdt_hack_state SET is_hacked=0 WHERE id=1");
        $hackState = $db->query("SELECT * FROM mdt_hack_state WHERE id=1 LIMIT 1")->fetch();
    }
} catch (Exception $e) {}

/* ── Historique des hacks ── */
$hackLog = [];
try {
    $hackLog = $db->query("SELECT * FROM mdt_hack_log ORDER BY created_at DESC LIMIT 20")->fetchAll();
} catch (Exception $e) {}

/* ── Configuration actuelle ── */
$cfg = mdt_hack_get_config($db);

/* ── Utilisateurs verrouillés ── */
$lockedUsers = [];
try {
    $lockedUsers = $db->query("
        SELECT a.*, u.username FROM mdt_hack_attempts a
        LEFT JOIN users u ON u.id=a.user_id
        WHERE a.locked_until IS NOT NULL AND a.locked_until > NOW()
        ORDER BY a.locked_until DESC
    ")->fetchAll();
} catch (Exception $e) {}

include __DIR__ . '/../includes/header.php';
?>

<style>
.hk-status-card {
    border-radius:16px;
    padding:32px;
    display:flex;
    align-items:center;
    gap:24px;
    flex-wrap:wrap;
}
.hk-status-card.active {
    background:rgba(255,23,68,.06);
    border:2px solid rgba(255,23,68,.3);
}
.hk-status-card.safe {
    background:rgba(16,185,129,.04);
    border:2px solid rgba(16,185,129,.15);
}
.hk-icon {
    width:72px;height:72px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:1.8rem;flex-shrink:0;
}
.hk-icon.active { background:rgba(255,23,68,.1);color:#ff1744; animation:hkPulse 2s ease-in-out infinite; }
.hk-icon.safe   { background:rgba(16,185,129,.1);color:#10b981; }
@keyframes hkPulse{50%{box-shadow:0 0 20px rgba(255,23,68,.3);}}

.hk-title  { font-size:1.4rem;font-weight:800;font-family:'Syne',sans-serif;margin-bottom:4px; }
.hk-sub    { font-size:.82rem;color:var(--text-secondary); }
.hk-meta   { display:flex;flex-wrap:wrap;gap:10px;margin-top:12px; }
.hk-chip   { padding:4px 12px;border-radius:6px;font-size:.72rem;font-weight:700;font-family:'DM Mono',monospace; }
.hk-chip.r { background:rgba(255,23,68,.1);border:1px solid rgba(255,23,68,.25);color:#ff1744; }
.hk-chip.g { background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#10b981; }
.hk-chip.b { background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);color:#3b82f6; }
.hk-chip.y { background:rgba(234,179,8,.1);border:1px solid rgba(234,179,8,.25);color:#eab308; }

.hk-countdown {
    font-family:'DM Mono',monospace;font-size:2rem;font-weight:800;
    color:#ff1744;letter-spacing:4px;
    text-shadow:0 0 12px rgba(255,23,68,.3);
}
.hk-countdown.warning { color:#f97316; }
.hk-countdown.safe    { color:#10b981;text-shadow:none; }

/* ── CONFIG UI ── */
.hk-cfg-tabs { display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border);overflow-x:auto; }
.hk-cfg-tab {
    padding:10px 16px;cursor:pointer;font-weight:600;font-size:.83rem;
    color:var(--text-secondary);border-bottom:2px solid transparent;
    transition:all .2s;white-space:nowrap;
}
.hk-cfg-tab:hover { color:var(--text-primary); }
.hk-cfg-tab.active { color:var(--blue);border-color:var(--blue); }
.hk-cfg-panel { display:none;animation:fadeIn .2s ease; }
.hk-cfg-panel.active { display:block; }
@keyframes fadeIn { from{opacity:0;transform:translateY(4px);} to{opacity:1;transform:none;} }
.hk-cfg-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px; }
.hk-field { display:flex;flex-direction:column;gap:6px; }
.hk-field label { font-size:.72rem;font-weight:700;letter-spacing:.5px;color:var(--text-secondary);text-transform:uppercase; }
.hk-field .hk-hint { font-size:.68rem;color:var(--text-muted); }
.hk-input, .hk-select {
    padding:10px 12px;background:var(--bg-elevated);border:1px solid var(--border);
    border-radius:8px;color:var(--text-primary);font-family:'DM Mono',monospace;font-size:.88rem;
    transition:border-color .15s;
}
.hk-input:focus, .hk-select:focus { outline:none;border-color:var(--blue); }
.hk-switch { display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none; }
.hk-switch input { display:none; }
.hk-switch-track {
    width:42px;height:24px;background:var(--bg-elevated);border:1px solid var(--border);
    border-radius:999px;position:relative;transition:all .2s;flex-shrink:0;
}
.hk-switch-track::after {
    content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;
    background:var(--text-secondary);border-radius:50%;transition:all .2s;
}
.hk-switch input:checked + .hk-switch-track { background:rgba(59,130,246,.2);border-color:var(--blue); }
.hk-switch input:checked + .hk-switch-track::after { transform:translateX(18px);background:var(--blue); }
.hk-switch-label { font-size:.85rem;font-weight:600; }
.hk-mission-card {
    border:1px solid var(--border);border-radius:12px;padding:18px;
    background:rgba(13,21,38,.5);margin-bottom:12px;
}
.hk-mission-card-header {
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--border);
}
.hk-mission-card-title {
    display:flex;align-items:center;gap:10px;font-weight:700;font-family:'Syne',sans-serif;
}
.hk-mission-card-title i {
    width:32px;height:32px;border-radius:8px;display:grid;place-items:center;
    background:var(--bg-active);color:var(--blue);font-size:.85rem;
}
.hk-diff-btns { display:flex;gap:6px; }
.hk-diff-btn {
    padding:8px 14px;border:1px solid var(--border);border-radius:6px;
    background:var(--bg-elevated);cursor:pointer;font-weight:600;font-size:.78rem;
    color:var(--text-secondary);transition:all .15s;text-transform:uppercase;letter-spacing:.5px;
}
.hk-diff-btn:hover { border-color:var(--border-hover); }
.hk-diff-btn.active {
    background:rgba(59,130,246,.15);border-color:var(--blue);color:var(--blue);
}
.hk-locked-row {
    display:flex;align-items:center;gap:12px;padding:8px 12px;
    background:rgba(255,23,68,.04);border:1px solid rgba(255,23,68,.15);
    border-radius:8px;font-size:.83rem;margin-bottom:6px;
}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fa-solid fa-skull-crossbones" style="color:var(--red);margin-right:10px;"></i>Gestionnaire de Hack MDT</h1>
        <p>Surveillez et contrôlez les intrusions sur le MDT de Lysoria</p>
    </div>
    <div class="page-header-right">
        <a href="<?php echo SITE_URL; ?>/pages/hack.php" target="_blank" class="btn btn-secondary">
            <i class="fa-solid fa-terminal"></i> Voir la page de hack
        </a>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="btn btn-secondary">
            <i class="fa-solid fa-house"></i> Dashboard MDT
        </a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- ── ÉTAT ACTUEL ── -->
<div class="hk-status-card <?php echo $hackActive ? 'active' : 'safe'; ?>" style="margin-bottom:24px;">
    <div class="hk-icon <?php echo $hackActive ? 'active' : 'safe'; ?>">
        <i class="fa-solid fa-<?php echo $hackActive ? 'skull-crossbones' : 'shield-check'; ?>"></i>
    </div>
    <div style="flex:1;min-width:200px;">
        <?php if ($hackActive): ?>
            <div class="hk-title" style="color:#ff1744;">⚠ MDT COMPROMIS</div>
            <div class="hk-sub">Une intrusion est en cours sur le système MDT de Lysoria.</div>
            <div class="hk-meta">
                <span class="hk-chip r"><i class="fa-solid fa-user-secret"></i> <?php echo htmlspecialchars(strtoupper($hackState['hack_title'])); ?></span>
                <span class="hk-chip b"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($hackState['hacked_by'] ?? '—'); ?></span>
                <span class="hk-chip y"><i class="fa-solid fa-clock"></i> Hacké le <?php echo date('d/m/Y H:i', strtotime($hackState['hacked_at'])); ?></span>
            </div>
        <?php else: ?>
            <div class="hk-title" style="color:var(--green);">✓ MDT SÉCURISÉ</div>
            <div class="hk-sub">Le système MDT fonctionne normalement. Aucune intrusion détectée.</div>
            <div class="hk-meta">
                <span class="hk-chip g"><i class="fa-solid fa-shield-check"></i> SYSTÈME NOMINAL</span>
                <?php if (!empty($hackState['cancelled_at'])): ?>
                <span class="hk-chip b"><i class="fa-solid fa-info"></i> Dernier hack annulé le <?php echo date('d/m H:i', strtotime($hackState['cancelled_at'])); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($hackActive): ?>
    <div style="display:flex;flex-direction:column;align-items:center;gap:8px;flex-shrink:0;">
        <div style="font-size:.6rem;letter-spacing:2px;color:rgba(255,23,68,.5);">EXPIRATION</div>
        <div class="hk-countdown" id="hackTimer"><?php echo gmdate('i:s', $secondsLeft); ?></div>
        <div style="width:140px;height:4px;background:rgba(255,23,68,.1);border-radius:2px;overflow:hidden;">
            <div id="hackBarStaff" style="height:100%;background:#ff1744;border-radius:2px;transition:width 1s linear;width:<?php echo ($secondsLeft/3600)*100; ?>%"></div>
        </div>
    </div>
    <form method="POST" style="flex-shrink:0;">
        <input type="hidden" name="action" value="cancel_hack">
        <button type="submit" class="btn btn-danger"
                onclick="return confirm('Êtes-vous sûr de vouloir annuler le hack et restaurer le MDT immédiatement ?')">
            <i class="fa-solid fa-shield-halved"></i> Annuler le hack & Restaurer
        </button>
    </form>
    <script>
    (function(){
        const exp=<?php echo strtotime($hackState['expires_at']); ?>;
        const total=3600;
        function t(){
            const l=exp-Math.floor(Date.now()/1000);
            if(l<=0){document.getElementById('hackTimer').textContent='EXPIRÉ';return;}
            const el=document.getElementById('hackTimer');
            el.textContent=Math.floor(l/60).toString().padStart(2,'0')+':'+((l%60).toString().padStart(2,'0'));
            if(l<=300) el.classList.add('warning');
            document.getElementById('hackBarStaff').style.width=(l/total*100)+'%';
        }
        t();setInterval(t,1000);
    })();
    </script>
    <?php endif; ?>
</div>

<!-- ── CONFIGURATION ── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div class="card-title"><i class="fa-solid fa-sliders"></i> Configuration du système de hack</div>
        <div style="font-size:.75rem;color:var(--text-secondary);">
            <i class="fa-solid fa-circle-info"></i>
            Les paramètres s'appliquent à la prochaine tentative.
        </div>
    </div>
    <div class="card-body">
        <form method="POST" id="hkConfigForm">
            <input type="hidden" name="action" value="save_config">

            <div class="hk-cfg-tabs">
                <div class="hk-cfg-tab active" data-tab="global"><i class="fa-solid fa-globe"></i> Global</div>
                <div class="hk-cfg-tab" data-tab="m1"><i class="fa-solid fa-network-wired"></i> M1 Firewall</div>
                <div class="hk-cfg-tab" data-tab="m2"><i class="fa-solid fa-key"></i> M2 Cipher</div>
                <div class="hk-cfg-tab" data-tab="m3"><i class="fa-solid fa-wave-square"></i> M3 Sequence</div>
                <div class="hk-cfg-tab" data-tab="m4"><i class="fa-solid fa-bolt"></i> M4 Power Surge</div>
                <div class="hk-cfg-tab" data-tab="m5"><i class="fa-solid fa-satellite-dish"></i> M5 Packets</div>
            </div>

            <!-- GLOBAL -->
            <div class="hk-cfg-panel active" data-panel="global">
                <div class="hk-cfg-grid">
                    <div class="hk-field">
                        <label>Durée du hack (MDT compromis)</label>
                        <input type="number" class="hk-input" name="hack_duration" min="60" max="86400" value="<?php echo (int)$cfg['hack_duration']; ?>" required>
                        <span class="hk-hint">En secondes — durée pendant laquelle le MDT reste compromis (défaut 3600 = 1h).</span>
                    </div>
                    <div class="hk-field">
                        <label>Nombre de vies</label>
                        <input type="number" class="hk-input" name="global_lives" min="1" max="10" value="<?php echo (int)$cfg['global_lives']; ?>" required>
                        <span class="hk-hint">Nombre d'erreurs tolérées avant blocage (défaut 3).</span>
                    </div>
                    <div class="hk-field">
                        <label>Cooldown après échec</label>
                        <input type="number" class="hk-input" name="fail_cooldown" min="30" max="86400" value="<?php echo (int)$cfg['fail_cooldown']; ?>" required>
                        <span class="hk-hint">En secondes — temps d'attente après avoir perdu toutes ses vies.</span>
                    </div>
                    <div class="hk-field">
                        <label>Niveau de difficulté</label>
                        <div class="hk-diff-btns" id="hkDiffBtns">
                            <?php foreach (['easy'=>'Facile','medium'=>'Normal','hard'=>'Difficile'] as $k=>$v): ?>
                            <div class="hk-diff-btn <?php echo $cfg['difficulty']===$k?'active':''; ?>" data-val="<?php echo $k; ?>"><?php echo $v; ?></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="difficulty" id="hkDiffInput" value="<?php echo htmlspecialchars($cfg['difficulty']); ?>">
                        <span class="hk-hint">Modifie timers et tolérance des erreurs.</span>
                    </div>
                </div>

                <div style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border);">
                    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;color:var(--text-secondary);letter-spacing:1px;margin-bottom:12px;">
                        <i class="fa-solid fa-magic-wand-sparkles"></i> Effets & Immersion
                    </div>
                    <div class="hk-cfg-grid">
                        <?php
                        $switches = [
                            ['sound_enabled','Effets sonores','Beeps, clics, succès, erreurs'],
                            ['visual_effects','Effets visuels','Glitch, scanlines, flashs'],
                            ['matrix_bg','Arrière-plan Matrix','Pluie de caractères animée'],
                            ['intro_enabled','Écran d\'intro','Séquence de démarrage'],
                            ['progressive_difficulty','Difficulté progressive','Augmente à chaque mission'],
                            ['random_events','Événements aléatoires','Surges, faux codes, perturbations'],
                        ];
                        foreach ($switches as [$k,$label,$hint]): ?>
                        <label class="hk-switch">
                            <input type="checkbox" name="<?php echo $k; ?>" <?php echo !empty($cfg[$k])?'checked':''; ?>>
                            <span class="hk-switch-track"></span>
                            <div>
                                <div class="hk-switch-label"><?php echo $label; ?></div>
                                <div class="hk-hint"><?php echo $hint; ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- M1: FIREWALL -->
            <div class="hk-cfg-panel" data-panel="m1">
                <div class="hk-mission-card-header" style="border:none;padding:0;margin:0 0 16px;">
                    <div class="hk-mission-card-title">
                        <i class="fa-solid fa-network-wired"></i>
                        Mission 1 — Firewall Bypass
                    </div>
                    <label class="hk-switch">
                        <input type="checkbox" name="m1_enabled" <?php echo !empty($cfg['m1_enabled'])?'checked':''; ?>>
                        <span class="hk-switch-track"></span>
                        <span class="hk-switch-label">Activée</span>
                    </label>
                </div>
                <div class="hk-cfg-grid">
                    <div class="hk-field">
                        <label>Longueur du chemin</label>
                        <input type="number" class="hk-input" name="m1_path_length" min="3" max="15" value="<?php echo (int)$cfg['m1_path_length']; ?>">
                        <span class="hk-hint">Nombre de nœuds à mémoriser (défaut 6).</span>
                    </div>
                    <div class="hk-field">
                        <label>Durée d'affichage (ms)</label>
                        <input type="number" class="hk-input" name="m1_show_time" min="400" max="6000" step="100" value="<?php echo (int)$cfg['m1_show_time']; ?>">
                        <span class="hk-hint">Temps avant extinction du chemin (défaut 1500).</span>
                    </div>
                </div>
            </div>

            <!-- M2: CIPHER -->
            <div class="hk-cfg-panel" data-panel="m2">
                <div class="hk-mission-card-header" style="border:none;padding:0;margin:0 0 16px;">
                    <div class="hk-mission-card-title">
                        <i class="fa-solid fa-key"></i>
                        Mission 2 — Déchiffrement
                    </div>
                    <label class="hk-switch">
                        <input type="checkbox" name="m2_enabled" <?php echo !empty($cfg['m2_enabled'])?'checked':''; ?>>
                        <span class="hk-switch-track"></span>
                        <span class="hk-switch-label">Activée</span>
                    </label>
                </div>
                <div class="hk-cfg-grid">
                    <div class="hk-field">
                        <label>Limite de temps (secondes)</label>
                        <input type="number" class="hk-input" name="m2_time_limit" min="10" max="300" value="<?php echo (int)$cfg['m2_time_limit']; ?>">
                        <span class="hk-hint">Temps pour décoder le mot (défaut 45).</span>
                    </div>
                </div>
            </div>

            <!-- M3: SEQUENCE -->
            <div class="hk-cfg-panel" data-panel="m3">
                <div class="hk-mission-card-header" style="border:none;padding:0;margin:0 0 16px;">
                    <div class="hk-mission-card-title">
                        <i class="fa-solid fa-wave-square"></i>
                        Mission 3 — Override Séquentiel
                    </div>
                    <label class="hk-switch">
                        <input type="checkbox" name="m3_enabled" <?php echo !empty($cfg['m3_enabled'])?'checked':''; ?>>
                        <span class="hk-switch-track"></span>
                        <span class="hk-switch-label">Activée</span>
                    </label>
                </div>
                <div class="hk-cfg-grid">
                    <div class="hk-field">
                        <label>Nombre de rondes</label>
                        <input type="number" class="hk-input" name="m3_rounds" min="1" max="10" value="<?php echo (int)$cfg['m3_rounds']; ?>">
                        <span class="hk-hint">Nombre de rondes successives (défaut 3).</span>
                    </div>
                    <div class="hk-field">
                        <label>Longueur de base</label>
                        <input type="number" class="hk-input" name="m3_base_length" min="2" max="12" value="<?php echo (int)$cfg['m3_base_length']; ?>">
                        <span class="hk-hint">Longueur initiale (+1 à chaque ronde).</span>
                    </div>
                    <div class="hk-field">
                        <label>Vitesse d'affichage (ms)</label>
                        <input type="number" class="hk-input" name="m3_speed" min="150" max="2000" step="50" value="<?php echo (int)$cfg['m3_speed']; ?>">
                        <span class="hk-hint">Délai entre chaque flash (défaut 500).</span>
                    </div>
                </div>
            </div>

            <!-- M4: POWER SURGE -->
            <div class="hk-cfg-panel" data-panel="m4">
                <div class="hk-mission-card-header" style="border:none;padding:0;margin:0 0 16px;">
                    <div class="hk-mission-card-title">
                        <i class="fa-solid fa-bolt"></i>
                        Mission 4 — Power Surge
                    </div>
                    <label class="hk-switch">
                        <input type="checkbox" name="m4_enabled" <?php echo !empty($cfg['m4_enabled'])?'checked':''; ?>>
                        <span class="hk-switch-track"></span>
                        <span class="hk-switch-label">Activée</span>
                    </label>
                </div>
                <div class="hk-cfg-grid">
                    <div class="hk-field">
                        <label>Temps de maintien (secondes)</label>
                        <input type="number" class="hk-input" name="m4_hold_time" min="1" max="30" value="<?php echo (int)$cfg['m4_hold_time']; ?>">
                        <span class="hk-hint">Durée au-dessus du seuil (défaut 5).</span>
                    </div>
                    <div class="hk-field">
                        <label>Seuil de puissance (%)</label>
                        <input type="number" class="hk-input" name="m4_threshold" min="20" max="99" value="<?php echo (int)$cfg['m4_threshold']; ?>">
                        <span class="hk-hint">% à maintenir (défaut 80).</span>
                    </div>
                </div>
            </div>

            <!-- M5: PACKET INTERCEPT -->
            <div class="hk-cfg-panel" data-panel="m5">
                <div class="hk-mission-card-header" style="border:none;padding:0;margin:0 0 16px;">
                    <div class="hk-mission-card-title">
                        <i class="fa-solid fa-satellite-dish"></i>
                        Mission 5 — Interception de Paquets <span style="font-size:.65rem;background:var(--blue);color:#fff;padding:2px 8px;border-radius:4px;margin-left:6px;">NOUVEAU</span>
                    </div>
                    <label class="hk-switch">
                        <input type="checkbox" name="m5_enabled" <?php echo !empty($cfg['m5_enabled'])?'checked':''; ?>>
                        <span class="hk-switch-track"></span>
                        <span class="hk-switch-label">Activée</span>
                    </label>
                </div>
                <div class="hk-cfg-grid">
                    <div class="hk-field">
                        <label>Paquets à intercepter</label>
                        <input type="number" class="hk-input" name="m5_packets_needed" min="3" max="40" value="<?php echo (int)$cfg['m5_packets_needed']; ?>">
                        <span class="hk-hint">Nombre de paquets à cliquer (défaut 10).</span>
                    </div>
                    <div class="hk-field">
                        <label>Limite de temps (secondes)</label>
                        <input type="number" class="hk-input" name="m5_time_limit" min="10" max="180" value="<?php echo (int)$cfg['m5_time_limit']; ?>">
                        <span class="hk-hint">Temps total (défaut 30).</span>
                    </div>
                </div>
                <div style="margin-top:16px;padding:12px 14px;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:8px;font-size:.78rem;color:var(--text-secondary);">
                    <i class="fa-solid fa-lightbulb" style="color:var(--blue);"></i>
                    Le joueur doit intercepter des paquets circulant dans un flux de données.
                    Les paquets <b style="color:var(--green);">verts</b> valident, les <b style="color:var(--red);">rouges</b> retirent un paquet validé.
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:16px;margin-top:16px;border-top:1px solid var(--border);flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary" onclick="hkResetConfig()">
                    <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Sauvegarder la configuration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── UTILISATEURS VERROUILLÉS ── -->
<?php if (!empty($lockedUsers)): ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <div class="card-title"><i class="fa-solid fa-lock" style="color:var(--orange);"></i> Utilisateurs en cooldown (<?php echo count($lockedUsers); ?>)</div>
        <form method="POST" onsubmit="return confirm('Réinitialiser tous les cooldowns et vies ?');">
            <input type="hidden" name="action" value="reset_attempts">
            <button type="submit" class="btn btn-secondary" style="font-size:.8rem;">
                <i class="fa-solid fa-rotate-right"></i> Reset tous les cooldowns
            </button>
        </form>
    </div>
    <div class="card-body">
        <?php foreach ($lockedUsers as $lu): 
            $remaining = strtotime($lu['locked_until']) - time();
            if ($remaining < 0) $remaining = 0;
        ?>
        <div class="hk-locked-row">
            <i class="fa-solid fa-user-lock" style="color:var(--orange);"></i>
            <div style="flex:1;">
                <b><?php echo htmlspecialchars($lu['username'] ?? 'Inconnu #'.$lu['user_id']); ?></b>
                <span style="color:var(--text-secondary);font-size:.78rem;">— Vies utilisées : <?php echo (int)$lu['lives_used']; ?></span>
            </div>
            <span class="hk-chip y" style="font-family:'DM Mono',monospace;">
                <i class="fa-solid fa-hourglass-half"></i>
                <?php echo gmdate($remaining>=3600?'H:i:s':'i:s', $remaining); ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── STATISTIQUES ── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
    <?php
    $totalHacks  = count($hackLog);
    $cancelHacks = count(array_filter($hackLog, fn($h)=>$h['end_reason']==='cancelled'));
    $expHacks    = count(array_filter($hackLog, fn($h)=>$h['end_reason']==='expired'));
    $lastHack    = !empty($hackLog) ? $hackLog[0]['hacked_by'] : '—';
    foreach ([
        ['icon'=>'skull-crossbones','label'=>'Total intrusions','val'=>$totalHacks,'color'=>'var(--red)'],
        ['icon'=>'shield-halved',   'label'=>'Annulées (staff)', 'val'=>$cancelHacks,'color'=>'var(--blue)'],
        ['icon'=>'clock',           'label'=>'Expirées',         'val'=>$expHacks,   'color'=>'var(--yellow)'],
        ['icon'=>'user-secret',     'label'=>'Dernier hackeur',  'val'=>$lastHack,   'color'=>'var(--purple)'],
    ] as $s):
    ?>
    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:12px;">
        <div style="width:36px;height:36px;border-radius:9px;background:<?php echo $s['color']; ?>18;display:grid;place-items:center;color:<?php echo $s['color']; ?>;flex-shrink:0;font-size:.85rem;">
            <i class="fa-solid fa-<?php echo $s['icon']; ?>"></i>
        </div>
        <div>
            <div style="font-size:.65rem;color:var(--text-secondary);margin-bottom:2px;"><?php echo $s['label']; ?></div>
            <div style="font-weight:800;font-family:'Syne',sans-serif;font-size:1.1rem;"><?php echo htmlspecialchars($s['val']); ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── HISTORIQUE ── -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Historique des intrusions</div>
    </div>
    <?php if (empty($hackLog)): ?>
    <div class="card-body">
        <div style="text-align:center;padding:32px;color:var(--text-secondary);">
            <i class="fa-solid fa-shield-check" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.3;color:var(--green);"></i>
            Aucune intrusion enregistrée
        </div>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Titre du hack</th>
                    <th>Hackeur</th>
                    <th>Début</th>
                    <th>Fin prévue</th>
                    <th>Terminé le</th>
                    <th>Raison</th>
                    <th>Annulé par</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hackLog as $log): ?>
                <tr>
                    <td>
                        <span style="font-family:'DM Mono',monospace;font-weight:700;color:var(--red);">
                            <?php echo htmlspecialchars(strtoupper($log['hack_title'] ?? '—')); ?>
                        </span>
                    </td>
                    <td style="font-size:.83rem;"><?php echo htmlspecialchars($log['hacked_by'] ?? '—'); ?></td>
                    <td style="font-size:.75rem;font-family:'DM Mono',monospace;color:var(--text-secondary);">
                        <?php echo $log['hacked_at'] ? date('d/m/Y H:i', strtotime($log['hacked_at'])) : '—'; ?>
                    </td>
                    <td style="font-size:.75rem;font-family:'DM Mono',monospace;color:var(--text-secondary);">
                        <?php echo $log['expires_at'] ? date('d/m/Y H:i', strtotime($log['expires_at'])) : '—'; ?>
                    </td>
                    <td style="font-size:.75rem;font-family:'DM Mono',monospace;color:var(--text-secondary);">
                        <?php echo $log['ended_at'] ? date('d/m/Y H:i', strtotime($log['ended_at'])) : '—'; ?>
                    </td>
                    <td>
                        <?php if ($log['end_reason'] === 'cancelled'): ?>
                            <span class="badge badge-blue"><i class="fa-solid fa-shield-halved"></i> Annulé staff</span>
                        <?php else: ?>
                            <span class="badge badge-yellow"><i class="fa-solid fa-clock"></i> Expiré</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.83rem;color:var(--text-secondary);">
                        <?php echo htmlspecialchars($log['cancelled_by_name'] ?? '—'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
/* ── Tabs config ── */
(function(){
    document.querySelectorAll('.hk-cfg-tab').forEach(t=>{
        t.addEventListener('click',()=>{
            const tab=t.dataset.tab;
            document.querySelectorAll('.hk-cfg-tab').forEach(x=>x.classList.toggle('active',x.dataset.tab===tab));
            document.querySelectorAll('.hk-cfg-panel').forEach(x=>x.classList.toggle('active',x.dataset.panel===tab));
        });
    });
    document.querySelectorAll('#hkDiffBtns .hk-diff-btn').forEach(b=>{
        b.addEventListener('click',()=>{
            document.querySelectorAll('#hkDiffBtns .hk-diff-btn').forEach(x=>x.classList.remove('active'));
            b.classList.add('active');
            document.getElementById('hkDiffInput').value=b.dataset.val;
        });
    });
})();

function hkResetConfig(){
    if(!confirm('Réinitialiser tous les paramètres à leurs valeurs par défaut ?')) return;
    const defaults={
        hack_duration:3600,global_lives:3,fail_cooldown:600,
        m1_path_length:6,m1_show_time:1500,
        m2_time_limit:45,
        m3_rounds:3,m3_base_length:4,m3_speed:500,
        m4_hold_time:5,m4_threshold:80,
        m5_packets_needed:10,m5_time_limit:30,
    };
    for(const [k,v] of Object.entries(defaults)){
        const el=document.querySelector(`[name="${k}"]`);
        if(el) el.value=v;
    }
    ['sound_enabled','visual_effects','matrix_bg','intro_enabled','progressive_difficulty','random_events',
     'm1_enabled','m2_enabled','m3_enabled','m4_enabled','m5_enabled'].forEach(k=>{
        const el=document.querySelector(`[name="${k}"]`);
        if(el) el.checked=true;
    });
    document.querySelectorAll('#hkDiffBtns .hk-diff-btn').forEach(x=>x.classList.toggle('active',x.dataset.val==='medium'));
    document.getElementById('hkDiffInput').value='medium';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>