<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Tableau de bord';
$db = Database::getInstance()->getConnection();
include __DIR__ . '/includes/header.php';

$uid = $_SESSION['user_id'];
$isPartner = $__isPartner;

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// Stats globales
$totalClients  = (int)$db->query("SELECT COUNT(*) FROM avocat_clients WHERE statut='actif'")->fetchColumn();
$dossiersActifs= (int)$db->query("SELECT COUNT(*) FROM avocat_dossiers WHERE statut IN ('ouvert','en_cours','tribunal')")->fetchColumn();
$procesAttente = (int)$db->query("SELECT COUNT(*) FROM avocat_proces WHERE statut='planifie'")->fetchColumn();
$factImpayees  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM avocat_invoices WHERE statut='pending'")->fetchColumn();

// Mes RDV du jour
$rdvAujourd = $db->prepare("
    SELECT r.*, p.firstname, p.lastname
    FROM avocat_rdv r
    LEFT JOIN avocat_clients ac ON ac.id=r.client_id
    LEFT JOIN profiles p ON p.id=ac.profile_id
    WHERE DATE(r.date_rdv)=CURDATE() AND r.statut='planifie'
    AND (r.avocat_id=? OR ?)
    ORDER BY r.date_rdv
");
$rdvAujourd->execute([$uid, (int)$isPartner]); $rdvAujourd=$rdvAujourd->fetchAll();

// Dossiers récents
$recentDossiers = $db->query("
    SELECT d.*, p.firstname, p.lastname
    FROM avocat_dossiers d
    LEFT JOIN avocat_clients ac ON ac.id=d.client_id
    LEFT JOIN profiles p ON p.id=ac.profile_id
    ORDER BY d.updated_at DESC LIMIT 6
")->fetchAll();

// Procès à venir (7 jours)
$procesProchains = $db->query("
    SELECT ap.*, ad.titre AS dossier_titre, p.firstname, p.lastname
    FROM avocat_proces ap
    LEFT JOIN avocat_dossiers ad ON ad.id=ap.dossier_id
    LEFT JOIN avocat_clients ac ON ac.id=ad.client_id
    LEFT JOIN profiles p ON p.id=ac.profile_id
    WHERE ap.statut='planifie' AND ap.date_audience <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY ap.date_audience ASC LIMIT 5
")->fetchAll();

// Revenus du cabinet
$revenusMois  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM avocat_invoices WHERE statut='paid' AND MONTH(paid_at)=MONTH(CURDATE()) AND YEAR(paid_at)=YEAR(CURDATE())")->fetchColumn();
$revenusTotal = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM avocat_invoices WHERE statut='paid'")->fetchColumn();
?>
<!-- KPIs -->
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-ico"><i class="fas fa-folder-open"></i></div>
        <div class="kpi-lbl">Dossiers actifs</div>
        <div class="kpi-val blue"><?php echo $dossiersActifs; ?></div>
        <div class="kpi-sub">en cours de traitement</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(139,92,246,.12);color:#c084fc"><i class="fas fa-gavel"></i></div>
        <div class="kpi-lbl">Procès en attente</div>
        <div class="kpi-val" style="color:#c084fc"><?php echo $procesAttente; ?></div>
        <div class="kpi-sub">audiences planifiées</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-users"></i></div>
        <div class="kpi-lbl">Clients actifs</div>
        <div class="kpi-val green"><?php echo $totalClients; ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="kpi-lbl">Factures impayées</div>
        <div class="kpi-val red">$<?php echo number_format($factImpayees,0,'.',','); ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(217,119,6,.12);color:var(--gold-l)"><i class="fas fa-chart-line"></i></div>
        <div class="kpi-lbl">CA ce mois</div>
        <div class="kpi-val gold">$<?php echo number_format($revenusMois,0,'.',','); ?></div>
        <div class="kpi-sub">Total: $<?php echo number_format($revenusTotal,0,'.',','); ?></div>
    </div>
</div>

<div class="grid2">
    <!-- RDV du jour -->
    <div class="card">
        <div class="card-title"><i class="fas fa-calendar-day"></i> Rendez-vous du jour (<?php echo date('d/m/Y'); ?>)</div>
        <?php if(empty($rdvAujourd)): ?>
        <p style="color:var(--txt-m);text-align:center;padding:1.5rem">Aucun rendez-vous aujourd'hui</p>
        <?php else: ?>
        <?php foreach($rdvAujourd as $r): ?>
        <div style="display:flex;align-items:center;gap:.9rem;padding:.75rem;background:var(--bg-ov);border-radius:9px;margin-bottom:.55rem;border-left:3px solid var(--gold)">
            <div style="text-align:center;flex-shrink:0">
                <div style="font-size:1.1rem;font-weight:700;color:var(--gold-l);font-family:'IBM Plex Mono',monospace"><?php echo date('H:i',strtotime($r['date_rdv'])); ?></div>
            </div>
            <div style="flex:1">
                <div style="font-weight:600;font-size:.845rem"><?php echo htmlspecialchars($r['titre']); ?></div>
                <div style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars(($r['firstname']??'').' '.($r['lastname']??'')); ?> — <?php echo htmlspecialchars($r['lieu']??''); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <a href="dossiers.php#rdv" class="btn btn-secondary btn-sm" style="margin-top:.75rem;width:100%;justify-content:center"><i class="fas fa-plus"></i> Planifier un RDV</a>
    </div>

    <!-- Procès à venir -->
    <div class="card">
        <div class="card-title"><i class="fas fa-gavel"></i> Procès cette semaine</div>
        <?php if(empty($procesProchains)): ?>
        <p style="color:var(--txt-m);text-align:center;padding:1.5rem">Aucune audience cette semaine</p>
        <?php else: ?>
        <?php foreach($procesProchains as $p): ?>
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:.75rem;background:var(--bg-ov);border-radius:9px;margin-bottom:.55rem">
            <div style="width:42px;height:42px;border-radius:9px;background:rgba(139,92,246,.12);color:#c084fc;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.75rem;text-align:center;line-height:1.2;font-weight:700">
                <?php echo date('d',strtotime($p['date_audience'])); ?><br><?php echo date('M',strtotime($p['date_audience'])); ?>
            </div>
            <div>
                <div style="font-weight:600;font-size:.845rem"><?php echo htmlspecialchars($p['titre']); ?></div>
                <div style="font-size:.72rem;color:var(--txt-m)">
                    <?php if($p['juge']): ?>Juge: <?php echo htmlspecialchars($p['juge']); ?> · <?php endif; ?>
                    <?php echo htmlspecialchars(($p['firstname']??'').' '.($p['lastname']??'')); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Dossiers récents -->
<div class="card">
    <div class="card-title"><i class="fas fa-folder-open"></i> Dossiers récents</div>
    <?php if(empty($recentDossiers)): ?>
    <p style="text-align:center;color:var(--txt-m);padding:2rem">Aucun dossier — <a href="dossiers.php" style="color:var(--gold)">Créer un dossier →</a></p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>N° Dossier</th><th>Client</th><th>Titre</th><th>Priorité</th><th>Statut</th><th>Màj</th></tr></thead>
        <tbody>
        <?php foreach($recentDossiers as $d):
            $stCls=['ouvert'=>'bg-blue','en_cours'=>'bg-yellow','tribunal'=>'bg-purple','ferme'=>'bg-gray','gagne'=>'bg-green','perdu'=>'bg-red'][$d['statut']]??'bg-gray';
            $stLbl=['ouvert'=>'Ouvert','en_cours'=>'En cours','tribunal'=>'Tribunal','ferme'=>'Fermé','gagne'=>'Gagné','perdu'=>'Perdu'][$d['statut']]??$d['statut'];
            $prCls=['normale'=>'bg-gray','urgente'=>'bg-yellow','critique'=>'bg-red'][$d['priorite']]??'bg-gray';
        ?>
        <tr>
            <td class="mono" style="color:var(--txt-m);font-size:.72rem"><?php echo htmlspecialchars($d['numero']??'—'); ?></td>
            <td style="font-weight:600"><?php echo htmlspecialchars(($d['firstname']??'').' '.($d['lastname']??'')); ?></td>
            <td><?php echo htmlspecialchars(mb_substr($d['titre'],0,55)); ?></td>
            <td><span class="badge <?php echo $prCls; ?>"><?php echo $d['priorite']; ?></span></td>
            <td><span class="badge <?php echo $stCls; ?>"><?php echo $stLbl; ?></span></td>
            <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/y',strtotime($d['updated_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>