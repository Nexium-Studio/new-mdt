<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Gestion du Cabinet';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';
// NB: $__isPartner est défini dans includes/header.php, check effectué après

// Activité par avocat
$avocatsStats = $db->query("
    SELECT u.id, u.username, u.faction_rank,
           COUNT(DISTINCT d.id) AS nb_dossiers,
           COUNT(DISTINCT ap.id) AS nb_proces,
           COALESCE(SUM(CASE WHEN ai.statut='paid' THEN ai.amount ELSE 0 END),0) AS revenus,
           COALESCE(SUM(CASE WHEN ai.statut='pending' THEN ai.amount ELSE 0 END),0) AS impayees
    FROM users u
    LEFT JOIN avocat_dossiers d ON d.avocat_id=u.id
    LEFT JOIN avocat_proces ap ON ap.created_by=u.id
    LEFT JOIN avocat_invoices ai ON ai.avocat_id=u.id
    WHERE u.faction='AVOCATS' AND u.is_active=1
    GROUP BY u.id ORDER BY revenus DESC
")->fetchAll();

// KPIs globaux
$glob = $db->query("
    SELECT
        (SELECT COUNT(*) FROM avocat_dossiers WHERE statut IN ('ouvert','en_cours','tribunal')) AS dos_actifs,
        (SELECT COUNT(*) FROM avocat_dossiers WHERE statut='gagne') AS gagnes,
        (SELECT COUNT(*) FROM avocat_dossiers WHERE statut='perdu') AS perdus,
        (SELECT COALESCE(SUM(amount),0) FROM avocat_invoices WHERE statut='paid') AS ca_total,
        (SELECT COALESCE(SUM(amount),0) FROM avocat_invoices WHERE statut='pending') AS impayees,
        (SELECT COUNT(*) FROM avocat_clients WHERE statut='actif') AS clients_actifs
")->fetch();

$winRate = ($glob['gagnes']+$glob['perdus']) > 0 ? round($glob['gagnes']/($glob['gagnes']+$glob['perdus'])*100) : 0;

include __DIR__ . '/includes/header.php';

// Vérification permissions après définition des variables par header.php
if (!$__isPartner) {
    echo '<div class="alert alert-inf"><i class="fas fa-lock"></i> Accès réservé aux Avocats Associés et supérieurs.</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
?>
<div class="kpi-row">
    <div class="kpi"><div class="kpi-lbl">CA total</div><div class="kpi-val gold">$<?php echo number_format($glob['ca_total'],0,'.',',');?></div></div>
    <div class="kpi"><div class="kpi-lbl">Impayées</div><div class="kpi-val red">$<?php echo number_format($glob['impayees'],0,'.',',');?></div></div>
    <div class="kpi"><div class="kpi-lbl">Taux de réussite</div><div class="kpi-val <?php echo $winRate>=50?'green':'red';?>"><?php echo $winRate;?>%</div><div class="kpi-sub"><?php echo $glob['gagnes'];?> gagnés / <?php echo $glob['perdus'];?> perdus</div></div>
    <div class="kpi"><div class="kpi-lbl">Dossiers actifs</div><div class="kpi-val blue"><?php echo $glob['dos_actifs'];?></div></div>
    <div class="kpi"><div class="kpi-lbl">Clients actifs</div><div class="kpi-val"><?php echo $glob['clients_actifs'];?></div></div>
</div>

<div class="card">
    <div class="card-title"><i class="fas fa-chart-bar"></i> Performance par avocat</div>
    <?php if(empty($avocatsStats)):?>
    <p style="text-align:center;color:var(--txt-m);padding:2rem">Aucun avocat enregistré</p>
    <?php else:?>
    <table class="tbl">
        <thead><tr><th>Avocat</th><th>Grade</th><th>Dossiers</th><th>Procès</th><th>CA généré</th><th>Impayées</th></tr></thead>
        <tbody>
        <?php foreach($avocatsStats as $a):?>
        <tr>
            <td style="font-weight:700"><?php echo htmlspecialchars($a['username']);?></td>
            <td><span class="badge bg-gold" style="font-size:.65rem"><?php echo htmlspecialchars($a['faction_rank']??'—');?></span></td>
            <td style="text-align:center"><strong style="color:#93c5fd"><?php echo $a['nb_dossiers'];?></strong></td>
            <td style="text-align:center"><strong style="color:#c084fc"><?php echo $a['nb_proces'];?></strong></td>
            <td class="mono txt-green">$<?php echo number_format($a['revenus'],0,'.',',');?></td>
            <td class="mono txt-red">$<?php echo number_format($a['impayees'],0,'.',',');?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table>
    <?php endif;?>
</div>

<!-- Dossiers par statut -->
<div class="grid2">
    <div class="card">
        <div class="card-title"><i class="fas fa-trophy"></i> Historique des affaires</div>
        <?php $history=$db->query("SELECT statut,COUNT(*) as n FROM avocat_dossiers GROUP BY statut")->fetchAll(\PDO::FETCH_KEY_PAIR);?>
        <?php foreach(['ouvert'=>['bg-blue','Ouverts'],'en_cours'=>['bg-yellow','En cours'],'tribunal'=>['bg-purple','Au tribunal'],'ferme'=>['bg-gray','Fermés'],'gagne'=>['bg-green','Gagnés'],'perdu'=>['bg-red','Perdus']] as $s=>[$c,$l]):?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem .75rem;background:var(--bg-ov);border-radius:7px;margin-bottom:.4rem">
            <span class="badge <?php echo $c;?>"><?php echo $l;?></span>
            <strong style="color:#f5edd6;font-family:'IBM Plex Mono',monospace"><?php echo $history[$s]??0;?></strong>
        </div>
        <?php endforeach;?>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-file-invoice-dollar"></i> Finances du cabinet</div>
        <?php $monthCA=(float)$db->query("SELECT COALESCE(SUM(amount),0) FROM avocat_invoices WHERE statut='paid' AND MONTH(paid_at)=MONTH(CURDATE()) AND YEAR(paid_at)=YEAR(CURDATE())")->fetchColumn();?>
        <?php foreach([['CA total encaissé','$'.number_format($glob['ca_total'],0,'.',','),'#4ade80'],['CA ce mois','$'.number_format($monthCA,0,'.',','),'#fcd34d'],['Montant impayé','$'.number_format($glob['impayees'],0,'.',','),'#f87171']] as [$l,$v,$c]):?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem .75rem;background:var(--bg-ov);border-radius:7px;margin-bottom:.4rem">
            <span style="font-size:.82rem;color:var(--txt-s)"><?php echo $l;?></span>
            <strong class="mono" style="color:<?php echo $c;?>"><?php echo $v;?></strong>
        </div>
        <?php endforeach;?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php';?>