<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🏠 Dynasty';
$companyColor   = '#22c55e';
$companyFaction = 'DYNASTY';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Vue d\'ensemble';
$pageSubtitle = 'Pilotage Dynasty Immobilier';
$db        = Database::getInstance()->getConnection();
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'dashboard.view')) faction_acl_deny();
$canStats = faction_acl_can($db, $uid, $companyFaction, 'dashboard.stats');
$message   = ''; $msgType = '';

/* ══════════════════════════════════════════════════════════
   RECALCUL FINANCES — auto à chaque chargement
   Lit directement dynasty_locations + dynasty_ventes
   pour refléter immédiatement chaque nouvelle opération.
   ══════════════════════════════════════════════════════════ */
function doRecalculate(PDO $db, string $faction): array {
    // ── CA Brut ─────────────────────────────────────────────
    // Option A : factures payées (company_invoices)
    $s = $db->prepare("SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE faction=? AND status='paid'");
    $s->execute([$faction]); $ca_invoices = (float)$s->fetchColumn();

    // Option B : somme directe des contrats dynasty (TTC)
    $s = $db->prepare("SELECT COALESCE(SUM(montant_loyer),0) FROM dynasty_locations WHERE statut IN('actif','termine')");
    $s->execute(); $ca_locations = (float)$s->fetchColumn();

    $s = $db->prepare("SELECT COALESCE(SUM(prix_negocie),0) FROM dynasty_ventes WHERE statut IN('signe','acte_final')");
    $s->execute(); $ca_ventes = (float)$s->fetchColumn();

    // On prend le max des deux méthodes (évite le double-comptage si déjà facturé)
    $ca_brut = max($ca_invoices, $ca_locations + $ca_ventes);

    // ── Charges ─────────────────────────────────────────────
    $s = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM company_expenses WHERE faction=? AND is_tax_deductible=1");
    $s->execute([$faction]); $charges = (float)$s->fetchColumn();

    // ── Calcul bénéfice & impôts ─────────────────────────────
    $benefice_brut = $ca_brut - $charges;
    $impot_du = 0;
    if      ($benefice_brut > 500000) $impot_du = 65000 + ($benefice_brut - 500000) * 0.20;
    elseif  ($benefice_brut > 100000) $impot_du = 5000  + ($benefice_brut - 100000) * 0.15;
    elseif  ($benefice_brut > 50000)  $impot_du = ($benefice_brut - 50000) * 0.10;

    $benefice_net = $benefice_brut - $impot_du;
    $tresorerie   = $benefice_net * 0.30;

    // ── Persistance ─────────────────────────────────────────
    $db->prepare("
        INSERT INTO company_finances
            (faction,ca_brut,charges,benefice_brut,impot_du,benefice_net,tresorerie)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            ca_brut       = VALUES(ca_brut),
            charges       = VALUES(charges),
            benefice_brut = VALUES(benefice_brut),
            impot_du      = VALUES(impot_du),
            benefice_net  = VALUES(benefice_net),
            tresorerie    = VALUES(tresorerie)
    ")->execute([$faction,$ca_brut,$charges,$benefice_brut,$impot_du,$benefice_net,$tresorerie]);

    return compact('ca_brut','charges','benefice_brut','impot_du','benefice_net','tresorerie',
                   'ca_locations','ca_ventes');
}

/* ── POST : bouton manuel ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'recalculate_finances') {
    try {
        $finCalc = doRecalculate($db, $companyFaction);
        $message = 'Finances recalculées avec succès !'; $msgType = 'success';
    } catch(Exception $e) {
        $message = 'Erreur : '.$e->getMessage(); $msgType = 'error';
    }
} else {
    // Auto-recalcul silencieux à chaque chargement de la page
    try { doRecalculate($db, $companyFaction); } catch(Exception $e) {}
}

/* ── DONNÉES ── */
$s = $db->prepare("SELECT * FROM company_finances WHERE faction=?");
$s->execute([$companyFaction]);
$finances = $s->fetch(PDO::FETCH_ASSOC) ?: [
    'ca_brut'=>0,'charges'=>0,'benefice_brut'=>0,
    'impot_du'=>0,'benefice_net'=>0,'tresorerie'=>0
];

// Détail du CA pour le widget finances
$s = $db->prepare("SELECT COALESCE(SUM(montant_loyer),0) FROM dynasty_locations WHERE statut IN('actif','termine')");
$s->execute(); $ca_locations_total = (float)$s->fetchColumn();

$s = $db->prepare("SELECT COALESCE(SUM(prix_negocie),0) FROM dynasty_ventes WHERE statut IN('signe','acte_final')");
$s->execute(); $ca_ventes_total = (float)$s->fetchColumn();

$s = $db->prepare("SELECT COALESCE(SUM(commission_amt),0) FROM dynasty_locations");
$s->execute(); $comm_locations = (float)$s->fetchColumn();

$s = $db->prepare("SELECT COALESCE(SUM(commission_amt),0) FROM dynasty_ventes");
$s->execute(); $comm_ventes = (float)$s->fetchColumn();

// Stats instances
$biens_stats = $db->query("
    SELECT COUNT(*) total,
        SUM(statut='disponible') disponible,
        SUM(statut='loue')       loue,
        SUM(statut='vendu')      vendu,
        SUM(statut='reserve')    reserve
    FROM dynasty_biens
")->fetch(PDO::FETCH_ASSOC);

// Locations actives
$loc_actives = $db->query("
    SELECT COUNT(*) total, COALESCE(SUM(montant_loyer),0) ca
    FROM dynasty_locations WHERE statut='actif'
")->fetch(PDO::FETCH_ASSOC);

// Ventes du mois
$ventes_mois = $db->prepare("
    SELECT COUNT(*) nb,
           COALESCE(SUM(prix_negocie),0)   ca,
           COALESCE(SUM(commission_amt),0) commissions
    FROM dynasty_ventes
    WHERE statut IN ('signe','acte_final')
      AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
");
$ventes_mois->execute(); $vm = $ventes_mois->fetch(PDO::FETCH_ASSOC);

// Locations du mois
$loc_mois = $db->query("
    SELECT COUNT(*) nb, COALESCE(SUM(montant_loyer),0) ca, COALESCE(SUM(commission_amt),0) commissions
    FROM dynasty_locations
    WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
")->fetch(PDO::FETCH_ASSOC);

// Agents — source unifiée : users.faction (synchronisé avec effectifs.php)
$s = $db->prepare("SELECT COUNT(*) FROM users WHERE faction=? AND is_active=1");
$s->execute([$companyFaction]); $nbAgents = $s->fetchColumn();

$s = $db->prepare("SELECT COUNT(*) FROM company_shifts WHERE faction=? AND DATE(shift_start)=CURDATE() AND shift_end IS NULL");
$s->execute([$companyFaction]); $activeShifts = $s->fetchColumn();

// Locations expirant dans 7 jours
$expiry = $db->query("
    SELECT l.*, b.titre bien_titre, b.quartier
    FROM dynasty_locations l
    JOIN dynasty_biens b ON l.bien_id=b.id
    WHERE l.statut='actif'
      AND l.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
    ORDER BY l.date_fin ASC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Top agents du mois — source unifiée : users.faction
$top_agents = $db->query("
    SELECT u.id, u.username,
           COALESCE(NULLIF(u.faction_rank,''), 'Agent') AS rank,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname,
           u.avatar,
           COUNT(DISTINCT v.id)  nb_ventes,
           COUNT(DISTINCT lo.id) nb_locations,
           COALESCE(SUM(v.commission_amt),0)+COALESCE(SUM(lo.commission_amt),0) commission_totale
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    LEFT JOIN dynasty_ventes v    ON v.agent_id=u.id
        AND MONTH(v.created_at)=MONTH(CURDATE()) AND YEAR(v.created_at)=YEAR(CURDATE())
    LEFT JOIN dynasty_locations lo ON lo.agent_id=u.id
        AND MONTH(lo.created_at)=MONTH(CURDATE()) AND YEAR(lo.created_at)=YEAR(CURDATE())
    WHERE u.faction='DYNASTY' AND u.is_active=1
    GROUP BY u.id, u.username, u.faction_rank, p.firstname, p.lastname, u.avatar
    ORDER BY commission_totale DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Derniers contrats (union BL+BV)
$last_contrats = $db->query("
    (SELECT 'BL' type_doc, reference, locataire_nom nom, montant_loyer montant, statut, created_at
     FROM dynasty_locations ORDER BY created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'BV' type_doc, reference, acheteur_nom nom, prix_negocie montant, statut, created_at
     FROM dynasty_ventes ORDER BY created_at DESC LIMIT 5)
    ORDER BY created_at DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Instances récentes
$new_biens = $db->query("
    SELECT b.*, u.username agent_nom FROM dynasty_biens b
    LEFT JOIN users u ON b.agent_id=u.id
    ORDER BY b.created_at DESC LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

$typeIcons = ['appartement'=>'fa-building','maison'=>'fa-home','villa'=>'fa-landmark',
    'bureau'=>'fa-briefcase','local_commercial'=>'fa-store','terrain'=>'fa-map',
    'parking'=>'fa-parking','studio'=>'fa-door-open'];

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<!-- ── EN-TÊTE ── -->
<div class="page-header">
    <div>
        <div class="page-header-title"><i class="fas fa-chart-line"></i> Vue d'ensemble</div>
        <div class="page-header-sub">Pilotage en temps réel · Finances recalculées à chaque chargement</div>
    </div>
    <div class="page-header-actions">
        <form method="POST" style="display:contents;" data-confirm="Forcer le recalcul des finances ?">
            <input type="hidden" name="action" value="recalculate_finances">
            <button type="submit" class="btn btn-ghost" data-tip="Recalculer manuellement les finances">
                <i class="fas fa-sync-alt"></i> Recalculer
            </button>
        </form>
        <a href="biens.php?open=new" class="btn btn-secondary"><i class="fas fa-plus"></i> Nouveau bien</a>
        <a href="locations.php?open=new" class="btn btn-primary"><i class="fas fa-key"></i> Nouvelle location</a>
    </div>
</div>

<!-- ── QUICK ACTIONS ── -->
<div class="quick-actions">
    <a href="biens.php?open=new" class="qa-btn">
        <div class="qa-ico"><i class="fas fa-building"></i></div>
        <div><div class="qa-lbl">Nouveau bien</div><div class="qa-sub">Ajouter au catalogue</div></div>
    </a>
    <a href="locations.php?open=new" class="qa-btn">
        <div class="qa-ico qa-blue"><i class="fas fa-key"></i></div>
        <div><div class="qa-lbl">Nouvelle location</div><div class="qa-sub">Générer un BL</div></div>
    </a>
    <a href="ventes.php?open=new" class="qa-btn">
        <div class="qa-ico qa-amber"><i class="fas fa-handshake"></i></div>
        <div><div class="qa-lbl">Nouvelle vente</div><div class="qa-sub">Générer un BV</div></div>
    </a>
    <a href="contrats.php" class="qa-btn">
        <div class="qa-ico qa-violet"><i class="fas fa-file-signature"></i></div>
        <div><div class="qa-lbl">Contrats</div><div class="qa-sub">Historique BV / BL</div></div>
    </a>
    <a href="effectifs.php" class="qa-btn">
        <div class="qa-ico qa-cyan"><i class="fas fa-users"></i></div>
        <div><div class="qa-lbl">Équipe</div><div class="qa-sub">Gestion des agents</div></div>
    </a>
</div>

<!-- ── KPIs ── -->
<div class="dy-stats">
    <div class="dy-stat">
        <div class="dy-stat-ico"><i class="fas fa-building"></i></div>
        <div>
            <div class="dy-stat-val"><?php echo $biens_stats['total']??0; ?></div>
            <div class="dy-stat-lbl">Biens au catalogue</div>
            <div class="dy-stat-sub"><?php echo $biens_stats['disponible']??0; ?> disponibles · <?php echo $biens_stats['loue']??0; ?> loués</div>
        </div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico ico-blue"><i class="fas fa-key"></i></div>
        <div>
            <div class="dy-stat-val"><?php echo $loc_actives['total']??0; ?></div>
            <div class="dy-stat-lbl">Locations actives</div>
            <div class="dy-stat-sub up">$<?php echo number_format($loc_actives['ca']??0,0); ?> en cours</div>
        </div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico ico-amber"><i class="fas fa-handshake"></i></div>
        <div>
            <div class="dy-stat-val"><?php echo $vm['nb']??0; ?></div>
            <div class="dy-stat-lbl">Ventes ce mois</div>
            <div class="dy-stat-sub">$<?php echo number_format($vm['ca']??0,0); ?> · comm. $<?php echo number_format($vm['commissions']??0,0); ?></div>
        </div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico"><i class="fas fa-dollar-sign"></i></div>
        <div>
            <div class="dy-stat-val">$<?php echo number_format($finances['benefice_net'],0); ?></div>
            <div class="dy-stat-lbl">Bénéfice net</div>
            <div class="dy-stat-sub">CA brut $<?php echo number_format($finances['ca_brut'],0); ?></div>
        </div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico ico-violet"><i class="fas fa-users"></i></div>
        <div>
            <div class="dy-stat-val"><?php echo $nbAgents; ?></div>
            <div class="dy-stat-lbl">Agents</div>
            <div class="dy-stat-sub"><?php echo $activeShifts; ?> en service</div>
        </div>
    </div>
</div>

<!-- ── GRILLE PRINCIPAL ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.3rem;margin-bottom:1.3rem;">

    <!-- Finances -->
    <div class="dy-widget">
        <div class="dy-widget-title">
            <i class="fas fa-landmark"></i> Tableau financier
            <span class="tag"><i class="fas fa-sync" style="font-size:.6rem;color:var(--dy);margin-right:.2rem"></i> Synchronisé automatiquement</span>
        </div>

        <!-- Détail sources CA -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:1rem">
            <div style="background:var(--bg-2);border:1px solid rgba(59,130,246,.18);border-radius:10px;padding:.85rem 1rem">
                <div style="font-size:.62rem;color:var(--tx-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem;font-weight:600"><i class="fas fa-key" style="color:#60a5fa"></i> CA Locations</div>
                <div style="font-variant-numeric:tabular-nums;font-weight:700;font-size:1rem;color:#60a5fa;letter-spacing:-.01em">$<?php echo number_format($ca_locations_total,0); ?></div>
                <div style="font-size:.66rem;color:var(--tx-mute);margin-top:.18rem">Commissions $<?php echo number_format($comm_locations,0); ?></div>
            </div>
            <div style="background:var(--bg-2);border:1px solid rgba(245,158,11,.18);border-radius:10px;padding:.85rem 1rem">
                <div style="font-size:.62rem;color:var(--tx-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem;font-weight:600"><i class="fas fa-handshake" style="color:#fbbf24"></i> CA Ventes</div>
                <div style="font-variant-numeric:tabular-nums;font-weight:700;font-size:1rem;color:#fbbf24;letter-spacing:-.01em">$<?php echo number_format($ca_ventes_total,0); ?></div>
                <div style="font-size:.66rem;color:var(--tx-mute);margin-top:.18rem">Commissions $<?php echo number_format($comm_ventes,0); ?></div>
            </div>
        </div>

        <div class="fin-row"><span class="fin-lbl"><i class="fas fa-cash-register"></i>CA brut total</span><span class="fin-val fin-neu">$<?php echo number_format($finances['ca_brut'],2); ?></span></div>
        <div class="fin-row"><span class="fin-lbl"><i class="fas fa-minus-circle"></i>Charges</span><span class="fin-val fin-neg">-$<?php echo number_format($finances['charges'],2); ?></span></div>
        <div class="fin-row"><span class="fin-lbl"><i class="fas fa-equals"></i>Bénéfice brut</span><span class="fin-val fin-pos">$<?php echo number_format($finances['benefice_brut'],2); ?></span></div>
        <div class="fin-row"><span class="fin-lbl"><i class="fas fa-landmark"></i>Impôts</span><span class="fin-val fin-neg">-$<?php echo number_format($finances['impot_du'],2); ?></span></div>
        <div class="fin-row fin-row-hi">
            <span class="fin-lbl" style="font-weight:700;color:var(--tx);font-size:.92rem"><i class="fas fa-trophy" style="color:var(--dy)!important"></i>Bénéfice net</span>
            <span class="fin-val fin-big">$<?php echo number_format($finances['benefice_net'],2); ?></span>
        </div>
        <div class="fin-row"><span class="fin-lbl"><i class="fas fa-piggy-bank"></i>Trésorerie (30%)</span><span class="fin-val fin-neu">$<?php echo number_format($finances['tresorerie'],2); ?></span></div>
        <div class="fin-row"><span class="fin-lbl"><i class="fas fa-gift"></i>Pool primes (30%)</span><span class="fin-val fin-pos">$<?php echo number_format($finances['benefice_net']*.30,2); ?></span></div>
        <div class="fin-row"><span class="fin-lbl"><i class="fas fa-crown"></i>Dividendes PDG (40%)</span><span class="fin-val fin-pos">$<?php echo number_format($finances['benefice_brut']*.40,2); ?></span></div>
    </div>

    <!-- Alertes expiration -->
    <div class="dy-widget">
        <div class="dy-widget-title">
            <i class="fas fa-clock" style="color:#fbbf24!important"></i>
            Échéances proches
            <?php if(!empty($expiry)): ?>
            <span class="tag" style="background:rgba(245,158,11,.12);color:#fbbf24;padding:.15rem .55rem;border-radius:999px;font-size:.65rem;font-weight:600;border:1px solid rgba(245,158,11,.22)"><?php echo count($expiry); ?></span>
            <?php endif; ?>
        </div>
        <?php if(empty($expiry)): ?>
        <div class="empty-state" style="padding:2.5rem 1rem">
            <i class="fas fa-check-circle" style="color:var(--dy);opacity:.5"></i>
            <h3>Aucune échéance imminente</h3>
            <p>Aucune location n'arrive à terme dans les 7 prochains jours.</p>
        </div>
        <?php else: ?>
        <div class="loc-rows">
            <?php foreach($expiry as $l):
                $jours = round((strtotime($l['date_fin'])-time())/86400);
                $cls   = $jours<=2?'exp2':($jours<=5?'exp7':'');
            ?>
            <div class="loc-row <?php echo $cls; ?>">
                <div class="lr-bien">
                    <div class="lr-t"><?php echo htmlspecialchars($l['bien_titre']); ?></div>
                    <div class="lr-sub"><?php echo htmlspecialchars($l['locataire_nom']); ?> · <?php echo htmlspecialchars($l['reference']); ?></div>
                </div>
                <div class="lr-dates">
                    <strong style="color:<?php echo $jours<=2?'#f87171':'#fbbf24'; ?>">J-<?php echo max(0,$jours); ?></strong>
                    <?php echo date('d/m/Y',strtotime($l['date_fin'])); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.3rem;margin-bottom:1.3rem;">

    <!-- Top Agents -->
    <div class="dy-widget">
        <div class="dy-widget-title"><i class="fas fa-trophy"></i> Performance — agents du mois</div>
        <?php if(empty($top_agents)): ?>
        <div class="empty-state" style="padding:2.5rem 1rem">
            <i class="fas fa-users"></i>
            <h3>Aucune performance ce mois</h3>
            <p>Les agents apparaîtront ici dès leurs premières transactions.</p>
        </div>
        <?php else: ?>
        <?php foreach($top_agents as $i=>$a):
            $rankCls = $i===0?'rk-gold':($i===1?'rk-silver':($i===2?'rk-bronze':'rk-other'));
            $aDisp   = mdt_rp_display_name($a['profile_firstname'] ?? null, $a['profile_lastname'] ?? null, (string)$a['username']);
        ?>
        <div class="agent-row">
            <div class="agent-rank <?php echo $rankCls; ?>"><?php echo $i+1; ?></div>
            <div class="agent-info">
                <div class="agent-name"><?php echo htmlspecialchars($aDisp); ?></div>
                <div class="agent-role"><?php echo htmlspecialchars($a['rank']); ?> · <?php echo $a['nb_ventes']; ?> vente<?php echo $a['nb_ventes']>1?'s':''; ?> · <?php echo $a['nb_locations']; ?> location<?php echo $a['nb_locations']>1?'s':''; ?></div>
            </div>
            <div class="agent-ca">$<?php echo number_format($a['commission_totale'],0); ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Derniers Contrats -->
    <div class="dy-widget">
        <div class="dy-widget-title"><i class="fas fa-file-signature"></i> Activité récente — contrats</div>
        <?php if(empty($last_contrats)): ?>
        <div class="empty-state" style="padding:2.5rem 1rem">
            <i class="fas fa-folder-open"></i>
            <h3>Aucun contrat</h3>
            <p>Les BV et BL apparaîtront ici dès leur génération.</p>
        </div>
        <?php else: ?>
        <table class="dy-table">
            <thead><tr><th>Référence</th><th>Client</th><th class="col-num">Montant</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach($last_contrats as $c): ?>
            <tr>
                <td>
                    <span class="badge <?php echo $c['type_doc']==='BV'?'badge-en_cours':'badge-loue'; ?>" style="font-size:.6rem;padding:.13rem .42rem;margin-right:.3rem"><?php echo $c['type_doc']; ?></span>
                    <span class="col-mono" style="color:var(--tx-3)"><?php echo htmlspecialchars($c['reference']); ?></span>
                </td>
                <td class="col-strong"><?php echo htmlspecialchars($c['nom']); ?></td>
                <td class="col-num" style="color:var(--dy)">$<?php echo number_format($c['montant'],0); ?></td>
                <td><span class="badge badge-<?php echo $c['statut']; ?>"><?php echo $c['statut']; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="text-align:right;margin-top:.85rem">
            <a href="contrats.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-right"></i> Voir tout</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Biens récemment ajoutés -->
<div class="dy-widget">
    <div class="dy-widget-title"><i class="fas fa-building"></i> Catalogue — derniers biens ajoutés</div>
    <?php if(empty($new_biens)): ?>
    <div class="empty-state" style="padding:2.5rem 1rem">
        <i class="fas fa-home"></i>
        <h3>Catalogue vide</h3>
        <p>Aucun bien n'a encore été ajouté au portefeuille.</p>
        <a href="biens.php?open=new" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter un bien</a>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(265px,1fr));gap:.9rem">
    <?php foreach($new_biens as $b):
        $ico = $typeIcons[$b['type_bien']]??'fa-home';
    ?>
    <a href="biens.php?id=<?php echo $b['id']; ?>" style="text-decoration:none;color:inherit">
        <div style="background:var(--bg-2);border:1px solid var(--bd);border-radius:11px;padding:1rem;cursor:pointer;transition:border-color .15s,transform .12s;" onmouseover="this.style.borderColor='var(--bd-h)'" onmouseout="this.style.borderColor='var(--bd)'">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.7rem">
                <div style="width:36px;height:36px;background:var(--dy-soft);border:1px solid var(--dy-bd);border-radius:9px;display:flex;align-items:center;justify-content:center;color:var(--dy)"><i class="fas <?php echo $ico; ?>"></i></div>
                <span class="badge badge-<?php echo $b['statut']; ?>"><?php echo $b['statut']; ?></span>
            </div>
            <div style="font-weight:700;font-size:.88rem;margin-bottom:.18rem;color:var(--tx);letter-spacing:-.01em"><?php echo htmlspecialchars($b['titre']); ?></div>
            <div style="font-size:.72rem;color:var(--tx-3);margin-bottom:.7rem"><i class="fas fa-map-marker-alt" style="font-size:.6rem;color:var(--dy);margin-right:.2rem"></i><?php echo htmlspecialchars($b['quartier']??$b['adresse']); ?></div>
            <div style="display:flex;gap:.7rem;font-size:.72rem;color:var(--tx-3);flex-wrap:wrap">
                <?php if($b['surface']): ?><span><i class="fas fa-ruler-combined" style="color:var(--dy)"></i> <?php echo $b['surface']; ?>m²</span><?php endif; ?>
                <?php if($b['pieces']): ?><span><i class="fas fa-door-open" style="color:var(--dy)"></i> <?php echo $b['pieces']; ?>p</span><?php endif; ?>
                <?php if($b['prix_vente']): ?><span style="color:var(--dy);font-weight:700">$<?php echo number_format($b['prix_vente'],0); ?></span><?php endif; ?>
                <?php if($b['prix_location_semaine']): ?><span style="color:#60a5fa;font-weight:700">$<?php echo number_format($b['prix_location_semaine'],0); ?>/sem.</span><?php endif; ?>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
    </div>
    <div style="text-align:right;margin-top:.95rem">
        <a href="biens.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-right"></i> Tout le catalogue</a>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>