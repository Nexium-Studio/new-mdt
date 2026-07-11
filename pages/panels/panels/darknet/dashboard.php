<?php
/**
 * DarkNet — Hub principal.
 * Accessible uniquement après authentification par code (darknet_require_auth).
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
require_once dirname(__DIR__, 3) . '/includes/darknet_api.php';

requireLogin();

$db = Database::getInstance()->getConnection();
$darknetSession = darknet_require_auth($db);

$pageTitle = 'Dashboard';

// Stats de la faction pour le user
$factionKey = $darknetSession['faction_key'] ?? '';
$stats = [
    'leaders'      => 0,
    'members'      => 0,
    'active_chats' => 0, // placeholder phase 3
];
try {
    $s = $db->prepare("SELECT COUNT(*) FROM darknet_illegal_accounts WHERE faction_key = ? AND is_active = 1");
    $s->execute([$factionKey]);
    $stats['leaders'] = (int) $s->fetchColumn();
    $s = $db->prepare("SELECT COUNT(*) FROM darknet_subcodes WHERE faction_key = ? AND is_active = 1");
    $s->execute([$factionKey]);
    $stats['members'] = (int) $s->fetchColumn();
} catch (Throwable $e) {}

// Last auth time
$lastAuth = null;
try {
    $s = $db->prepare("SELECT last_auth_at FROM darknet_sessions WHERE user_id = ? AND faction_key = ? LIMIT 1");
    $s->execute([(int) ($_SESSION['user_id'] ?? 0), $factionKey]);
    $lastAuth = $s->fetchColumn();
} catch (Throwable $e) {}

include __DIR__ . '/includes/header.php';
?>

<style>
.dn-hero{
    background:linear-gradient(135deg,rgba(124,58,237,.14),rgba(6,182,212,.08));
    border:1px solid rgba(167,139,250,.28);
    border-radius:18px;padding:28px 30px;margin-bottom:24px;position:relative;overflow:hidden;
}
.dn-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 80% 20%,rgba(167,139,250,.2),transparent 60%)}
.dn-hero > *{position:relative;z-index:1}
.dn-hero h1{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;letter-spacing:4px;text-transform:uppercase;
    background:linear-gradient(90deg,#fff,#a78bfa,#22d3ee);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:6px}
.dn-hero .tag{font-family:'Share Tech Mono',monospace;color:#64748b;font-size:.75rem;letter-spacing:2px;text-transform:uppercase}
.dn-hero p{color:#cbd5e1;font-size:.92rem;line-height:1.7;margin-top:14px;max-width:720px}

.dn-metrics{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin:24px 0}
.dn-metric{background:rgba(15,16,30,.75);border:1px solid rgba(148,163,184,.12);border-radius:14px;padding:18px 20px;position:relative;overflow:hidden;transition:all .2s}
.dn-metric:hover{border-color:rgba(167,139,250,.3);transform:translateY(-2px)}
.dn-metric .l{font-family:'Share Tech Mono',monospace;font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:2px}
.dn-metric .v{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#fff;line-height:1;margin-top:8px}
.dn-metric .v .u{font-size:.75rem;color:#64748b;margin-left:4px;font-weight:500}
.dn-metric .ic{position:absolute;top:18px;right:18px;width:32px;height:32px;border-radius:9px;background:rgba(124,58,237,.14);display:flex;align-items:center;justify-content:center;color:#a78bfa}

.dn-grid-modules{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.dn-module{
    display:flex;flex-direction:column;gap:12px;
    background:linear-gradient(160deg,rgba(13,17,33,.85),rgba(7,9,22,.95));
    border:1px solid rgba(148,163,184,.12);
    border-radius:14px;padding:20px;text-decoration:none;color:inherit;
    transition:all .25s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden;
}
.dn-module::before{
    content:'';position:absolute;top:0;left:0;right:0;height:2px;
    background:linear-gradient(90deg,transparent,var(--mcol,#a78bfa),transparent);opacity:0;transition:opacity .2s;
}
.dn-module:hover{transform:translateY(-4px);border-color:color-mix(in srgb,var(--mcol,#a78bfa) 40%,transparent);box-shadow:0 18px 40px rgba(0,0,0,.4),0 0 0 1px color-mix(in srgb,var(--mcol,#a78bfa) 18%,transparent)}
.dn-module:hover::before{opacity:1}
.dn-module .mhead{display:flex;align-items:center;gap:12px}
.dn-module .mic{width:42px;height:42px;border-radius:11px;background:color-mix(in srgb,var(--mcol,#a78bfa) 15%,transparent);border:1px solid color-mix(in srgb,var(--mcol,#a78bfa) 35%,transparent);display:flex;align-items:center;justify-content:center;color:var(--mcol,#a78bfa);font-size:1.05rem}
.dn-module h4{font-family:'Syne',sans-serif;font-size:.95rem;letter-spacing:1.5px;text-transform:uppercase;color:#e2e8f0}
.dn-module .mtag{font-family:'Share Tech Mono',monospace;font-size:.6rem;color:#64748b;letter-spacing:1.5px;text-transform:uppercase}
.dn-module .mdesc{font-size:.8rem;color:#94a3b8;line-height:1.55}
.dn-module .mfoot{display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:8px}
.dn-module .mfoot .arr{color:var(--mcol,#a78bfa);font-size:.8rem;opacity:.7;transition:transform .2s}
.dn-module:hover .mfoot .arr{transform:translateX(4px);opacity:1}
.dn-module.soon{opacity:.6;pointer-events:none}
.dn-module.soon::after{content:'Phase 2/3';position:absolute;top:12px;right:14px;font-size:.55rem;padding:3px 8px;background:rgba(251,191,36,.14);color:#fbbf24;border:1px solid rgba(251,191,36,.3);border-radius:20px;letter-spacing:1.5px;text-transform:uppercase;font-family:'Share Tech Mono',monospace}

.dn-section-head{display:flex;align-items:center;gap:14px;margin:24px 0 12px}
.dn-section-head h2{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:700;letter-spacing:3px;text-transform:uppercase;color:#fff}
.dn-section-head .line{flex:1;height:1px;background:linear-gradient(90deg,rgba(167,139,250,.3),transparent)}
.dn-section-head .tag{font-family:'Share Tech Mono',monospace;font-size:.68rem;color:#64748b;letter-spacing:2px;text-transform:uppercase}
</style>

<div class="dn-hero">
    <div class="tag">&gt;&gt; welcome operative</div>
    <h1>DarkNet // Active</h1>
    <p>
        Bienvenue sur le réseau. Toutes tes actions sont chiffrées et tracées anonymement.
        Navigue via les modules ci-dessous — <strong style="color:#a78bfa">zone privée</strong> réservée à ta faction,
        <strong style="color:#22d3ee">zone commune</strong> partagée avec tous les gangs.
    </p>
    <?php if (!empty($darknetSession['staff_bypass'])): ?>
    <p style="margin-top:8px;color:#fbbf24;font-size:.85rem">
        <i class="fa-solid fa-shield"></i> Mode staff : accès en modération — toutes les factions sont visibles.
    </p>
    <?php endif; ?>
</div>

<div class="dn-metrics">
    <div class="dn-metric">
        <div class="ic"><i class="fa-solid fa-crown"></i></div>
        <div class="l">Leaders actifs</div>
        <div class="v"><?= $stats['leaders']; ?></div>
    </div>
    <div class="dn-metric">
        <div class="ic" style="background:rgba(6,182,212,.14);color:#22d3ee"><i class="fa-solid fa-users"></i></div>
        <div class="l">Membres validés</div>
        <div class="v"><?= $stats['members']; ?></div>
    </div>
    <div class="dn-metric">
        <div class="ic" style="background:rgba(34,197,94,.14);color:#86efac"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="l">Ton rôle</div>
        <div class="v" style="font-size:1.3rem;letter-spacing:2px;text-transform:uppercase"><?= !empty($darknetSession['staff_bypass']) ? 'Staff' : ($darknetSession['role'] === 'leader' ? 'Leader' : 'Member'); ?></div>
    </div>
    <div class="dn-metric">
        <div class="ic" style="background:rgba(251,191,36,.14);color:#fbbf24"><i class="fa-solid fa-clock"></i></div>
        <div class="l">Dernière connexion</div>
        <div class="v" style="font-size:.95rem;font-family:'JetBrains Mono',monospace;color:#cbd5e1"><?= $lastAuth ? htmlspecialchars(date('d/m H:i', strtotime($lastAuth))) : 'Première fois'; ?></div>
    </div>
</div>

<div class="dn-section-head">
    <h2>Privé · Faction</h2>
    <span class="tag">restricted</span>
    <div class="line"></div>
</div>

<div class="dn-grid-modules">
    <a href="faction/effectif.php" class="dn-module" style="--mcol:#a78bfa">
        <div class="mhead">
            <div class="mic"><i class="fa-solid fa-users-rectangle"></i></div>
            <div>
                <h4>Effectif</h4>
                <div class="mtag">faction · roster</div>
            </div>
        </div>
        <div class="mdesc">Gère les membres, génère les sous-codes d'accès, attribue les grades.</div>
        <div class="mfoot"><span class="mtag">leader only</span><span class="arr">→</span></div>
    </a>

    <a href="faction/stock.php" class="dn-module" style="--mcol:#22d3ee">
        <div class="mhead">
            <div class="mic" style="color:#22d3ee;background:rgba(6,182,212,.14);border-color:rgba(6,182,212,.35)"><i class="fa-solid fa-box-archive"></i></div>
            <div>
                <h4>Stock</h4>
                <div class="mtag">inventory</div>
            </div>
        </div>
        <div class="mdesc">Dépose et récupère les items du groupe. Ajout / retrait réservés aux chefs.</div>
        <div class="mfoot"><span class="mtag">all members</span><span class="arr">→</span></div>
    </a>

    <a href="faction/bank.php" class="dn-module" style="--mcol:#86efac">
        <div class="mhead">
            <div class="mic" style="color:#86efac;background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.35)"><i class="fa-solid fa-sack-dollar"></i></div>
            <div>
                <h4>Bank</h4>
                <div class="mtag">finances</div>
            </div>
        </div>
        <div class="mdesc">Gestion de l'argent propre et sale. Dépôts membre, gestion leader.</div>
        <div class="mfoot"><span class="mtag">clean / dirty</span><span class="arr">→</span></div>
    </a>
</div>

<div class="dn-section-head">
    <h2>Commun · All factions</h2>
    <span class="tag">inter-gangs</span>
    <div class="line"></div>
</div>

<div class="dn-grid-modules">
    <a href="common/black-market.php" class="dn-module" style="--mcol:#f472b6">
        <div class="mhead">
            <div class="mic" style="color:#f472b6;background:rgba(244,114,182,.14);border-color:rgba(244,114,182,.35)"><i class="fa-solid fa-basket-shopping"></i></div>
            <div>
                <h4>Black Market</h4>
                <div class="mtag">flash · sat 21h</div>
            </div>
        </div>
        <div class="mdesc">Ventes flash, items rares, blanchiment d'argent.</div>
        <div class="mfoot"><span class="mtag">public</span><span class="arr">→</span></div>
    </a>

    <a href="common/contrats.php" class="dn-module" style="--mcol:#ef4444">
        <div class="mhead">
            <div class="mic" style="color:#fca5a5;background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.35)"><i class="fa-solid fa-crosshairs"></i></div>
            <div>
                <h4>Contrats & Hitman</h4>
                <div class="mtag">targets · payouts</div>
            </div>
        </div>
        <div class="mdesc">Mur des cibles, dépôt de contrats, validation par preuve.</div>
        <div class="mfoot"><span class="mtag">hitman</span><span class="arr">→</span></div>
    </a>

    <a href="common/leaks.php" class="dn-module" style="--mcol:#fbbf24">
        <div class="mhead">
            <div class="mic" style="color:#fbbf24;background:rgba(251,191,36,.14);border-color:rgba(251,191,36,.35)"><i class="fa-solid fa-user-ninja"></i></div>
            <div>
                <h4>Leaks Center</h4>
                <div class="mtag">intel · radio</div>
            </div>
        </div>
        <div class="mdesc">Publication de données sensibles, annuaire des indics, fréquences radio.</div>
        <div class="mfoot"><span class="mtag">intel</span><span class="arr">→</span></div>
    </a>

    <a href="common/laboratoire.php" class="dn-module" style="--mcol:#a3e635">
        <div class="mhead">
            <div class="mic" style="color:#a3e635;background:rgba(163,230,53,.14);border-color:rgba(163,230,53,.35)"><i class="fa-solid fa-flask"></i></div>
            <div>
                <h4>Laboratoire & R&D</h4>
                <div class="mtag">recherche</div>
            </div>
        </div>
        <div class="mdesc">Tracking de l'activité police, graphiques dynamiques.</div>
        <div class="mfoot"><span class="mtag">science</span><span class="arr">→</span></div>
    </a>

    <a href="common/chat.php" class="dn-module" style="--mcol:#22d3ee">
        <div class="mhead">
            <div class="mic" style="color:#22d3ee;background:rgba(6,182,212,.14);border-color:rgba(6,182,212,.35)"><i class="fa-solid fa-comments"></i></div>
            <div>
                <h4>Encrypted Chat</h4>
                <div class="mtag">comms · aes-256</div>
            </div>
        </div>
        <div class="mdesc">Messagerie chiffrée entre groupes. Conversations privées par faction.</div>
        <div class="mfoot"><span class="mtag">chat</span><span class="arr">→</span></div>
    </a>

    <a href="common/wanted.php" class="dn-module" style="--mcol:#e11d48">
        <div class="mhead">
            <div class="mic" style="color:#fb7185;background:rgba(225,29,72,.14);border-color:rgba(225,29,72,.35)"><i class="fa-solid fa-file-invoice"></i></div>
            <div>
                <h4>Wanted List</h4>
                <div class="mtag">bounties</div>
            </div>
        </div>
        <div class="mdesc">Liste des cibles recherchées et des primes associées.</div>
        <div class="mfoot"><span class="mtag">bounty</span><span class="arr">→</span></div>
    </a>

    <a href="common/reseau.php" class="dn-module" style="--mcol:#8b5cf6">
        <div class="mhead">
            <div class="mic" style="color:#c4b5fd;background:rgba(139,92,246,.14);border-color:rgba(139,92,246,.35)"><i class="fa-solid fa-diagram-project"></i></div>
            <div>
                <h4>Le Réseau</h4>
                <div class="mtag">connections</div>
            </div>
        </div>
        <div class="mdesc">Cartographie des alliances, trêves et conflits inter-gangs.</div>
        <div class="mfoot"><span class="mtag">network</span><span class="arr">→</span></div>
    </a>

    <a href="common/wiki.php" class="dn-module" style="--mcol:#94a3b8">
        <div class="mhead">
            <div class="mic" style="color:#cbd5e1;background:rgba(148,163,184,.14);border-color:rgba(148,163,184,.35)"><i class="fa-solid fa-book-skull"></i></div>
            <div>
                <h4>Wiki Criminel</h4>
                <div class="mtag">knowledge</div>
            </div>
        </div>
        <div class="mdesc">Base de connaissances communautaire : techniques, planques, modus operandi.</div>
        <div class="mfoot"><span class="mtag">archive</span><span class="arr">→</span></div>
    </a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
