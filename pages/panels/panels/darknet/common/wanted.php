<?php
/**
 * DarkNet — Wanted List : tire la liste depuis les dossiers LSPD (reports)
 * avec status "recherché/wanted" si présent, sinon affiche les contrats ouverts.
 */
require_once __DIR__ . '/_bootstrap.php';

$pageTitle = 'Wanted List';

// Vérifie dynamiquement si la table "wanted" existe (plus robuste que SELECT + catch)
$wanted = [];
try {
    $r = $db->query("SHOW TABLES LIKE 'wanted'");
    if ($r && $r->fetchColumn()) {
        $wanted = $db->query("SELECT * FROM wanted ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $wanted = [];
}

// Contrats actifs DarkNet
$contracts = [];
try {
    $contracts = $db->query("SELECT c.*, f.name AS faction_name FROM darknet_contracts c LEFT JOIN factions f ON f.faction_key = c.posted_by_faction WHERE c.status IN ('open','accepted','proof') ORDER BY c.created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $contracts = [];
}

include __DIR__ . '/../includes/header.php';
?>
<style>
.wl-hero{background:linear-gradient(135deg,rgba(239,68,68,.1),rgba(13,17,33,.9));border:1px solid rgba(239,68,68,.3);border-radius:14px;padding:20px 24px;margin-bottom:22px}
.wl-hero h1{font-family:'Syne',sans-serif;font-size:1.3rem;color:#fff;letter-spacing:3px;text-transform:uppercase;display:flex;align-items:center;gap:10px}
.wl-hero .sub{color:#94a3b8;font-size:.82rem;margin-top:5px}

.wl-sect{margin-bottom:26px}
.wl-sect-title{font-family:'Syne',sans-serif;font-size:1rem;letter-spacing:2px;text-transform:uppercase;color:#e2e8f0;margin-bottom:14px;display:flex;align-items:center;gap:10px}
.wl-sect-title .c{font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#64748b;padding:3px 10px;background:rgba(124,58,237,.1);border:1px solid rgba(167,139,250,.28);border-radius:20px;letter-spacing:1px}

.wl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.wl-card{background:rgba(13,17,33,.85);border:1px solid rgba(148,163,184,.1);border-left:3px solid #ef4444;border-radius:12px;padding:16px 18px;position:relative}
.wl-card .nm{font-family:'Syne',sans-serif;font-weight:700;color:#fff;font-size:1.05rem;margin-bottom:6px}
.wl-card .c{color:#fca5a5;font-size:.82rem;line-height:1.5}
.wl-card .meta{display:flex;gap:12px;margin-top:10px;font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#64748b;letter-spacing:1px}
.wl-card .rw{margin-top:10px;display:inline-flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;color:#fbbf24;font-weight:700;padding:4px 10px;background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.3);border-radius:16px;font-size:.85rem}

.wl-empty{text-align:center;padding:50px 20px;color:#64748b;background:rgba(13,17,33,.5);border:1px dashed rgba(148,163,184,.15);border-radius:12px}
</style>

<div class="wl-hero">
    <h1><i class="fa-solid fa-file-invoice" style="color:#fca5a5"></i> Wanted List</h1>
    <div class="sub">Cibles recherchées par les factions · sources croisées (LSPD + contrats DarkNet)</div>
</div>

<?php if ($wanted): ?>
<section class="wl-sect">
    <div class="wl-sect-title"><i class="fa-solid fa-star" style="color:#fbbf24"></i> Recherchés LSPD <span class="c"><?= count($wanted); ?></span></div>
    <div class="wl-grid">
        <?php foreach ($wanted as $w): ?>
            <div class="wl-card">
                <div class="nm"><?= htmlspecialchars($w['name'] ?? $w['target_name'] ?? '—'); ?></div>
                <?php if (!empty($w['description'])): ?><div class="c"><?= nl2br(htmlspecialchars($w['description'])); ?></div><?php endif; ?>
                <div class="meta">
                    <?php if (!empty($w['level'])): ?><span>Niveau <?= htmlspecialchars($w['level']); ?></span><?php endif; ?>
                    <?php if (!empty($w['created_at'])): ?><span><?= htmlspecialchars(date('d/m/Y', strtotime($w['created_at']))); ?></span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="wl-sect">
    <div class="wl-sect-title"><i class="fa-solid fa-crosshairs" style="color:#ef4444"></i> Contrats DarkNet actifs <span class="c"><?= count($contracts); ?></span></div>
    <?php if (!$contracts): ?>
        <div class="wl-empty"><i class="fa-solid fa-ghost" style="font-size:2.5rem;opacity:.3;margin-bottom:10px;display:block"></i><p>Aucun contrat actif.</p></div>
    <?php else: ?>
        <div class="wl-grid">
            <?php foreach ($contracts as $c): ?>
                <div class="wl-card">
                    <div class="nm"><?= htmlspecialchars($c['target_name']); ?></div>
                    <div class="c"><b><?= htmlspecialchars($c['title']); ?></b></div>
                    <?php if ($c['description']): ?><div class="c" style="margin-top:6px;color:#cbd5e1;font-size:.78rem"><?= nl2br(htmlspecialchars(mb_substr($c['description'], 0, 150))); ?></div><?php endif; ?>
                    <div class="meta">
                        <span><?= htmlspecialchars($c['faction_name'] ?? '—'); ?></span>
                        <span class="cstat"><?= htmlspecialchars($c['status']); ?></span>
                    </div>
                    <div class="rw"><i class="fa-solid fa-sack-dollar"></i> <?= number_format((int) $c['reward'], 0, ',', ' '); ?> $</div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div style="margin-top:14px;text-align:center">
        <a href="<?= SITE_URL; ?>/pages/panels/darknet/common/contrats.php" class="dn-btn cyan">Voir tous les contrats →</a>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
