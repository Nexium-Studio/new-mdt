<?php
/**
 * DarkNet — Effectif (privé / faction).
 * Liste chefs + membres, génération de sous-codes par le chef.
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Effectif';
$flashCode = null;
$success = '';
$error = '';

// ── POST ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée.';
    } elseif (!$dnIsLeader) {
        $error = 'Action réservée aux chefs.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_subcode') {
                // Le chef utilise son propre account_id (on cherche le compte leader de cette faction assigné à l'user)
                $accountId = null;
                if (!empty($darknetSession['account_id'])) {
                    $accountId = (int) $darknetSession['account_id'];
                } else {
                    // Staff bypass : on prend le premier compte actif de la faction
                    $st = $db->prepare("SELECT id FROM darknet_illegal_accounts WHERE faction_key = ? AND is_active = 1 ORDER BY id ASC LIMIT 1");
                    $st->execute([$dnFaction]);
                    $accountId = (int) $st->fetchColumn();
                }
                if ($accountId < 1) {
                    throw new RuntimeException("Aucun compte chef configuré pour cette faction. Demande au staff d'en créer un.");
                }
                $res = darknet_create_subcode(
                    $db,
                    $accountId,
                    ($_POST['member_label'] ?? '') !== '' ? (string) $_POST['member_label'] : null,
                    ($_POST['grade'] ?? '') !== '' ? (string) $_POST['grade'] : null,
                    null
                );
                $flashCode = ['code' => $res['code'], 'label' => 'Sous-code membre — à transmettre UNE SEULE FOIS.'];
                $success = 'Sous-code généré.';
            } elseif ($action === 'regen_subcode') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('ID invalide.'); }
                // Vérifier que le sub appartient bien à la faction
                $st = $db->prepare("SELECT faction_key FROM darknet_subcodes WHERE id = ?");
                $st->execute([$id]);
                if ($st->fetchColumn() !== $dnFaction) { throw new RuntimeException('Sous-code hors faction.'); }
                $plain = darknet_regenerate_subcode($db, $id);
                $flashCode = ['code' => $plain, 'label' => 'Nouveau sous-code membre.'];
                $success = 'Sous-code régénéré.';
            } elseif ($action === 'delete_subcode') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('ID invalide.'); }
                $st = $db->prepare("SELECT faction_key FROM darknet_subcodes WHERE id = ?");
                $st->execute([$id]);
                if ($st->fetchColumn() !== $dnFaction) { throw new RuntimeException('Sous-code hors faction.'); }
                darknet_delete_subcode($db, $id);
                $success = 'Sous-code supprimé.';
            } elseif ($action === 'update_grade') {
                $id = (int) ($_POST['id'] ?? 0);
                $grade = trim((string) ($_POST['grade'] ?? ''));
                if ($id < 1 || $grade === '') { throw new RuntimeException('Paramètres invalides.'); }
                $st = $db->prepare("UPDATE darknet_subcodes SET grade = ? WHERE id = ? AND faction_key = ?");
                $st->execute([$grade, $id, $dnFaction]);
                $success = 'Grade mis à jour.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($success !== '') {
            $_SESSION['darknet_csrf'] = bin2hex(random_bytes(16));
            $dnCsrf = $_SESSION['darknet_csrf'];
        }
    }
}

// ── Données ────────────────────────────────────────────────────────────────
$leaders = $db->prepare("
    SELECT a.*, u.username, p.firstname, p.lastname
    FROM darknet_illegal_accounts a
    LEFT JOIN users u ON u.id = a.assigned_user_id
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE a.faction_key = ? AND a.is_active = 1
    ORDER BY a.id ASC
");
$leaders->execute([$dnFaction]);
$leaders = $leaders->fetchAll(PDO::FETCH_ASSOC);

$members = $db->prepare("
    SELECT s.*, u.username, p.firstname, p.lastname, u.id AS uid
    FROM darknet_subcodes s
    LEFT JOIN users u ON u.id = s.assigned_user_id
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE s.faction_key = ?
    ORDER BY s.created_at DESC
");
$members->execute([$dnFaction]);
$members = $members->fetchAll(PDO::FETCH_ASSOC);

$grades = array_column(darknet_faction_grades($db, $dnFaction), 'name');

include __DIR__ . '/../includes/header.php';
?>

<style>
.eff-grid{display:grid;grid-template-columns:1fr 380px;gap:22px;align-items:start}
@media(max-width:1100px){.eff-grid{grid-template-columns:1fr}}
.eff-roster{display:flex;flex-direction:column;gap:18px}
.eff-sect-title{display:flex;align-items:center;gap:12px;margin-bottom:10px;font-family:'Syne',sans-serif;font-size:.92rem;letter-spacing:2px;text-transform:uppercase;color:#e2e8f0}
.eff-sect-title .c{font-family:'Share Tech Mono',monospace;font-size:.7rem;color:#64748b;letter-spacing:1.5px;padding:3px 10px;background:rgba(124,58,237,.1);border:1px solid rgba(167,139,250,.28);border-radius:20px}

.eff-card{display:flex;align-items:center;gap:14px;padding:14px 18px;background:rgba(13,17,33,.8);border:1px solid rgba(148,163,184,.1);border-radius:12px;transition:all .15s}
.eff-card:hover{border-color:rgba(167,139,250,.3)}
.eff-card.leader{border-color:rgba(251,191,36,.3);background:linear-gradient(135deg,rgba(251,191,36,.08),rgba(13,17,33,.85))}
.eff-avatar{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--dn-faction),#2a1050);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff}
.eff-avatar.leader{background:linear-gradient(135deg,#fbbf24,#d97706)}
.eff-info{flex:1;min-width:0}
.eff-name{color:#e2e8f0;font-weight:600;font-size:.92rem}
.eff-name .pseudo{color:#64748b;font-size:.75rem;font-weight:400;margin-left:5px}
.eff-meta{display:flex;gap:10px;margin-top:4px;font-size:.72rem;color:#94a3b8}
.eff-meta .g{color:#a78bfa;font-family:'Share Tech Mono',monospace;letter-spacing:1px;text-transform:uppercase}
.eff-meta .c{color:#64748b;font-family:'JetBrains Mono',monospace}
.eff-actions{display:flex;gap:6px}
.eff-inline-form{display:flex;gap:6px;align-items:center}
.eff-inline-form select{background:rgba(15,23,42,.7);border:1px solid rgba(148,163,184,.18);color:#cbd5e1;padding:5px 8px;border-radius:7px;font-size:.72rem}

.eff-generate{background:linear-gradient(160deg,rgba(124,58,237,.08),rgba(5,7,18,.7));border:1px solid rgba(167,139,250,.3);border-radius:14px;padding:20px 22px;position:sticky;top:92px}
.eff-generate h3{font-family:'Syne',sans-serif;color:#c4b5fd;font-size:.95rem;letter-spacing:2px;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:8px}
.eff-generate p{color:#94a3b8;font-size:.8rem;line-height:1.55;margin-bottom:16px}
.eff-gen-field{margin-bottom:12px}
.eff-gen-field label{display:block;font-family:'Share Tech Mono',monospace;font-size:.65rem;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:5px}
.eff-gen-field input,.eff-gen-field select{width:100%;background:rgba(2,4,12,.85);border:1px solid rgba(148,163,184,.18);color:#e2e8f0;padding:9px 12px;border-radius:9px;font-family:'JetBrains Mono',monospace;font-size:.82rem}
.eff-gen-field input:focus,.eff-gen-field select:focus{outline:none;border-color:#a78bfa;box-shadow:0 0 0 3px rgba(167,139,250,.15)}
.eff-gen-btn{width:100%;padding:12px;background:linear-gradient(135deg,#7c3aed,#22d3ee);border:none;border-radius:10px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:2px;text-transform:uppercase;cursor:pointer;font-size:.82rem;margin-top:6px;transition:all .2s}
.eff-gen-btn:hover{transform:translateY(-1px);box-shadow:0 12px 30px rgba(124,58,237,.4)}

.eff-flash{background:linear-gradient(135deg,rgba(34,197,94,.15),rgba(124,58,237,.08));border:1px solid rgba(167,139,250,.4);border-radius:12px;padding:16px 18px;margin-bottom:16px}
.eff-flash h4{color:#c4b5fd;font-family:'Share Tech Mono',monospace;font-size:.72rem;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px}
.eff-flash p{color:#cbd5e1;font-size:.78rem;margin-bottom:10px}
.eff-flash .val{display:flex;align-items:center;gap:10px;background:rgba(0,0,0,.45);padding:10px 14px;border-radius:10px}
.eff-flash .val code{flex:1;font-family:'JetBrains Mono',monospace;color:#fff;font-size:1.05rem;letter-spacing:2px}

.eff-alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:.82rem;display:flex;align-items:center;gap:9px}
.eff-alert.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.eff-alert.ko{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}

.eff-empty{text-align:center;padding:40px 20px;color:#64748b;background:rgba(13,17,33,.5);border:1px dashed rgba(148,163,184,.15);border-radius:12px}
.eff-empty i{font-size:2.2rem;opacity:.3;margin-bottom:10px;display:block}
</style>

<?php if ($flashCode): ?>
<div class="eff-flash">
    <h4><i class="fa-solid fa-key"></i> <?= htmlspecialchars($flashCode['label']); ?></h4>
    <p>Copie-le maintenant — il ne sera plus affiché.</p>
    <div class="val">
        <code id="fcVal"><?= htmlspecialchars($flashCode['code']); ?></code>
        <button type="button" class="dn-btn cyan" onclick="navigator.clipboard.writeText(document.getElementById('fcVal').textContent).then(()=>{this.innerHTML='<i class=\'fa-solid fa-check\'></i> Copié';});"><i class="fa-solid fa-copy"></i> Copier</button>
    </div>
</div>
<?php endif; ?>
<?php if ($success): ?><div class="eff-alert ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="eff-alert ko"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error); ?></div><?php endif; ?>

<div class="eff-grid">
    <div class="eff-roster">
        <!-- Leaders -->
        <div>
            <div class="eff-sect-title">
                <i class="fa-solid fa-crown" style="color:#fbbf24"></i>
                Chefs & Bras-droits
                <span class="c"><?= count($leaders); ?> actif<?= count($leaders) > 1 ? 's' : ''; ?></span>
            </div>
            <?php if (!$leaders): ?>
                <div class="eff-empty"><i class="fa-solid fa-user-slash"></i><p>Aucun chef configuré. Contact le staff.</p></div>
            <?php else: foreach ($leaders as $l):
                $name = trim(($l['firstname'] ?? '') . ' ' . ($l['lastname'] ?? '')) ?: ($l['username'] ?? 'Non assigné');
                $init = strtoupper(mb_substr($name, 0, 1));
            ?>
                <div class="eff-card leader">
                    <div class="eff-avatar leader"><?= htmlspecialchars($init); ?></div>
                    <div class="eff-info">
                        <div class="eff-name">
                            <?= htmlspecialchars($name); ?>
                            <?php if (!empty($l['username'])): ?><span class="pseudo">@<?= htmlspecialchars($l['username']); ?></span><?php endif; ?>
                        </div>
                        <div class="eff-meta">
                            <span class="g"><i class="fa-solid fa-crown"></i> <?= htmlspecialchars($l['grade']); ?></span>
                            <span class="c">…<?= htmlspecialchars($l['code_last4']); ?></span>
                            <?php if ($l['label']): ?><span style="color:#64748b">· <?= htmlspecialchars($l['label']); ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Members -->
        <div>
            <div class="eff-sect-title">
                <i class="fa-solid fa-users" style="color:#a78bfa"></i>
                Membres
                <span class="c"><?= count($members); ?> enregistré<?= count($members) > 1 ? 's' : ''; ?></span>
            </div>
            <?php if (!$members): ?>
                <div class="eff-empty"><i class="fa-solid fa-user-plus"></i><p>Aucun membre pour l'instant<?= $dnIsLeader ? '. Génère un sous-code →' : '.'; ?></p></div>
            <?php else: foreach ($members as $m):
                $name = trim(($m['firstname'] ?? '') . ' ' . ($m['lastname'] ?? ''));
                if ($name === '') { $name = $m['username'] ?? ($m['member_label'] ?? 'Membre anonyme'); }
                $init = strtoupper(mb_substr($name, 0, 1));
            ?>
                <div class="eff-card">
                    <div class="eff-avatar"><?= htmlspecialchars($init); ?></div>
                    <div class="eff-info">
                        <div class="eff-name">
                            <?= htmlspecialchars($name); ?>
                            <?php if (!empty($m['username'])): ?><span class="pseudo">@<?= htmlspecialchars($m['username']); ?></span><?php endif; ?>
                            <?php if (empty($m['assigned_user_id']) && !empty($m['member_label'])): ?><span class="pseudo">(non validé)</span><?php endif; ?>
                        </div>
                        <div class="eff-meta">
                            <span class="g"><?= htmlspecialchars($m['grade'] ?? 'Soldat'); ?></span>
                            <span class="c">…<?= htmlspecialchars($m['code_last4']); ?></span>
                            <?php if ($m['last_auth_at']): ?>
                                <span style="color:#64748b">· Dernière co <?= htmlspecialchars(date('d/m', strtotime($m['last_auth_at']))); ?></span>
                            <?php else: ?>
                                <span style="color:#64748b">· Jamais connecté</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($dnIsLeader): ?>
                    <div class="eff-actions">
                        <?php if ($grades): ?>
                        <form method="post" class="eff-inline-form">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="update_grade">
                            <input type="hidden" name="id" value="<?= (int) $m['id']; ?>">
                            <select name="grade" onchange="this.form.submit()">
                                <?php foreach ($grades as $g): ?>
                                    <option value="<?= htmlspecialchars($g); ?>" <?= ($m['grade'] ?? '') === $g ? 'selected' : ''; ?>><?= htmlspecialchars($g); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Régénérer le code de ce membre ?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="regen_subcode">
                            <input type="hidden" name="id" value="<?= (int) $m['id']; ?>">
                            <button type="submit" class="dn-btn" title="Régénérer"><i class="fa-solid fa-rotate"></i></button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Retirer ce membre ? Son accès sera révoqué.')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
                            <input type="hidden" name="action" value="delete_subcode">
                            <input type="hidden" name="id" value="<?= (int) $m['id']; ?>">
                            <button type="submit" class="dn-btn danger" title="Retirer"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <?php if ($dnIsLeader): ?>
    <!-- Generate sidebar -->
    <aside class="eff-generate">
        <h3><i class="fa-solid fa-key"></i> Générer un sous-code</h3>
        <p>Crée un nouvel accès DarkNet pour intégrer un membre. Tu pourras lui transmettre le code généré.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($dnCsrf); ?>">
            <input type="hidden" name="action" value="create_subcode">
            <div class="eff-gen-field">
                <label>Libellé (ex. Spider)</label>
                <input type="text" name="member_label" maxlength="120" placeholder="Pseudo ou surnom">
            </div>
            <div class="eff-gen-field">
                <label>Grade</label>
                <select name="grade">
                    <option value="">— Par défaut (Soldat) —</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= htmlspecialchars($g); ?>"><?= htmlspecialchars($g); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="eff-gen-btn"><i class="fa-solid fa-bolt"></i> Générer le code</button>
        </form>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid rgba(148,163,184,.08);font-family:'Share Tech Mono',monospace;font-size:.65rem;color:#64748b;letter-spacing:1px">
            [ sys ] codes permanents · aes-256 · un code = un membre
        </div>
    </aside>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
