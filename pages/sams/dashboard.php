<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Tableau de bord';
$db = Database::getInstance()->getConnection();

$stats = [];
$qs = [
    'patients'      => "SELECT COUNT(*) FROM profiles",
    'inter_actives' => "SELECT COUNT(*) FROM sams_interventions WHERE statut IN ('en_attente','en_cours')",
    'triage_att'    => "SELECT COUNT(*) FROM sams_triage WHERE statut='attente'",
    'ordo_actives'  => "SELECT COUNT(*) FROM medical_prescriptions WHERE status='active'",
    'rapports_today'=> "SELECT COUNT(*) FROM sams_reports WHERE DATE(created_at)=CURDATE()",
    'grossesses'    => "SELECT COUNT(*) FROM pregnancy_tracking WHERE status='ongoing'",
    'deces_mois'    => "SELECT COUNT(*) FROM death_certificates WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())",
    'staff'         => "SELECT COUNT(*) FROM users WHERE faction='SAMS' AND is_active=1",
    'stock_alerte'  => "SELECT COUNT(*) FROM sams_pharmacie WHERE stock_actuel<=stock_min AND actif=1",
];
foreach ($qs as $k => $q) { try { $stats[$k] = (int)$db->query($q)->fetchColumn(); } catch (Exception $e) { $stats[$k] = 0; } }

$revenuMois = 0;
try { $revenuMois = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM sams_invoices WHERE status='paid' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn(); } catch (Exception $e) {}

$triageActuel = [];
try { $triageActuel = $db->query("SELECT t.*,p.firstname,p.lastname FROM sams_triage t LEFT JOIN profiles p ON p.id=t.patient_id WHERE t.statut IN ('attente','en_traitement') ORDER BY FIELD(t.niveau,'P1','P2','P3','P4'),t.heure_arrivee LIMIT 8")->fetchAll(); } catch (Exception $e) {}

$interRecentes = [];
try { $interRecentes = $db->query("SELECT si.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cu.firstname,''),' ',COALESCE(cu.lastname,''))),'') AS createur FROM sams_interventions si LEFT JOIN profiles p ON p.id=si.patient_id LEFT JOIN users u ON u.id=si.created_by LEFT JOIN profiles cu ON cu.id=u.profile_id ORDER BY si.created_at DESC LIMIT 6")->fetchAll(); } catch (Exception $e) {}

$rapRecents = [];
try { $rapRecents = $db->query("SELECT sr.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cu.firstname,''),' ',COALESCE(cu.lastname,''))),'') AS medecin FROM sams_reports sr LEFT JOIN profiles p ON p.id=sr.patient_id LEFT JOIN users u ON u.id=sr.created_by LEFT JOIN profiles cu ON cu.id=u.profile_id ORDER BY sr.created_at DESC LIMIT 5")->fetchAll(); } catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>

<!-- ══ PAGE HEADER ══ -->
<div class="page-hdr">
    <div class="page-hdr-left">
        <div class="breadcrumb">
            <i class="fas fa-home"></i>
            <span class="breadcrumb-sep">/</span>
            <span>Tableau de bord</span>
        </div>
        <h1><i class="fas fa-heartbeat"></i> Vue d'ensemble</h1>
        <div class="page-hdr-sub">
            <?php echo date('l d F Y'); ?> &mdash; Statut des services en temps réel
        </div>
    </div>
    <div class="page-hdr-right">
        <?php if ($stats['triage_att'] > 0): ?>
        <a href="triage.php" class="btn btn-danger btn-sm" style="animation:pulse-btn .9s ease-in-out infinite alternate;">
            <i class="fas fa-hospital"></i>
            <?php echo $stats['triage_att']; ?> en attente triage
        </a>
        <?php endif; ?>
        <a href="interventions.php" class="btn btn-cyan btn-sm"><i class="fas fa-ambulance"></i> Nouvelle intervention</a>
    </div>
</div>

<style>
@keyframes pulse-btn {
    from { box-shadow: 0 3px 14px rgba(225,29,72,.3); }
    to   { box-shadow: 0 3px 24px rgba(225,29,72,.6); }
}
.inter-status {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .18rem .5rem; border-radius: 4px;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: .62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
}
.status-att    { background: rgba(245,158,11,.1);  color: var(--yellow-l); border: 1px solid rgba(245,158,11,.2); }
.status-cours  { background: rgba(6,182,212,.1);   color: var(--cyan-l);   border: 1px solid rgba(6,182,212,.2); }
.status-fin    { background: rgba(16,185,129,.1);  color: var(--green-l);  border: 1px solid rgba(16,185,129,.2); }
.status-annule { background: rgba(100,116,139,.1); color: #94a3b8;         border: 1px solid rgba(100,116,139,.2); }
.urgence-critique { --c: var(--red);    background: rgba(225,29,72,.1);  color: var(--red-l);   border: 1px solid rgba(225,29,72,.25); }
.urgence-elevee   { --c: var(--yellow); background: rgba(245,158,11,.1); color: var(--yellow-l);border: 1px solid rgba(245,158,11,.25); }
.urgence-moderee  { --c: var(--blue);   background: rgba(59,130,246,.1); color: #93c5fd;        border: 1px solid rgba(59,130,246,.25); }
.urgence-faible   { --c: var(--green);  background: rgba(16,185,129,.1); color: var(--green-l); border: 1px solid rgba(16,185,129,.25); }

/* EKG mini chart */
.ekg-line {
    height: 40px; overflow: hidden;
    display: flex; align-items: center;
}
.ekg-line svg { width: 100%; height: 100%; }

/* Stock alert ring */
.stock-ring {
    position: absolute; top: .8rem; right: .8rem;
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--red);
    box-shadow: 0 0 8px var(--red);
    animation: pulse-red 1s ease-in-out infinite;
}
</style>

<!-- ══ KPI GRID ══ -->
<div class="kpi-grid">
    <div class="kpi" data-color="cyan">
        <div class="kpi-ico"><i class="fas fa-users"></i></div>
        <div class="kpi-lbl">Patients enregistrés</div>
        <div class="kpi-val"><?php echo number_format($stats['patients']); ?></div>
        <div class="kpi-sub">dans la base citoyens</div>
    </div>
    <div class="kpi" data-color="red">
        <div class="kpi-ico"><i class="fas fa-ambulance"></i></div>
        <div class="kpi-lbl">Interventions actives</div>
        <div class="kpi-val"><?php echo $stats['inter_actives']; ?></div>
        <div class="kpi-sub">en cours ou en attente</div>
        <?php if ($stats['inter_actives'] > 0): ?><div class="stock-ring"></div><?php endif; ?>
    </div>
    <div class="kpi" data-color="<?php echo $stats['triage_att'] > 0 ? 'red' : 'green'; ?>">
        <div class="kpi-ico"><i class="fas fa-hospital"></i></div>
        <div class="kpi-lbl">File de triage</div>
        <div class="kpi-val"><?php echo $stats['triage_att']; ?></div>
        <div class="kpi-sub">patients en attente</div>
        <?php if ($stats['triage_att'] > 3): ?><div class="stock-ring"></div><?php endif; ?>
    </div>
    <div class="kpi" data-color="purple">
        <div class="kpi-ico"><i class="fas fa-prescription"></i></div>
        <div class="kpi-lbl">Ordonnances actives</div>
        <div class="kpi-val"><?php echo $stats['ordo_actives']; ?></div>
        <div class="kpi-sub">en cours de traitement</div>
    </div>
    <div class="kpi" data-color="yellow">
        <div class="kpi-ico"><i class="fas fa-file-medical"></i></div>
        <div class="kpi-lbl">Rapports aujourd'hui</div>
        <div class="kpi-val"><?php echo $stats['rapports_today']; ?></div>
        <div class="kpi-sub"><?php echo date('d/m/Y'); ?></div>
    </div>
    <div class="kpi" data-color="green">
        <div class="kpi-ico"><i class="fas fa-baby"></i></div>
        <div class="kpi-lbl">Grossesses suivies</div>
        <div class="kpi-val"><?php echo $stats['grossesses']; ?></div>
        <div class="kpi-sub">en cours</div>
    </div>
    <div class="kpi" data-color="cyan">
        <div class="kpi-ico"><i class="fas fa-user-md"></i></div>
        <div class="kpi-lbl">Staff actif</div>
        <div class="kpi-val"><?php echo $stats['staff']; ?></div>
        <div class="kpi-sub">membres SAMS</div>
    </div>
    <div class="kpi" data-color="<?php echo $stats['stock_alerte'] > 0 ? 'red' : 'green'; ?>">
        <div class="kpi-ico"><i class="fas fa-pills"></i></div>
        <div class="kpi-lbl">Alertes stock</div>
        <div class="kpi-val"><?php echo $stats['stock_alerte']; ?></div>
        <div class="kpi-sub">médicaments sous seuil</div>
        <?php if ($stats['stock_alerte'] > 0): ?><div class="stock-ring"></div><?php endif; ?>
    </div>
</div>

<!-- ══ TRIAGE + INTERVENTIONS ══ -->
<div class="grid2">

    <!-- Triage en cours -->
    <div>
        <div class="card card-red">
            <div class="card-hdr">
                <div class="card-title red">
                    <i class="fas fa-hospital"></i>
                    Triage — File d'attente
                </div>
                <a href="triage.php" class="btn btn-ghost btn-xs"><i class="fas fa-arrow-right"></i> Voir tout</a>
            </div>

            <?php if (empty($triageActuel)): ?>
            <div class="empty-state" style="padding:2rem;">
                <i class="fas fa-check-circle empty-icon" style="color:var(--green);"></i>
                <p>Aucun patient en attente</p>
            </div>
            <?php else: ?>
            <?php foreach ($triageActuel as $t):
                $niveauClass = 'triage-' . ($t['niveau'] ?? 'P4');
                $niveauColors = ['P1'=>'badge-red','P2'=>'badge-yellow','P3'=>'badge-cyan','P4'=>'badge-green'];
                $nc = $niveauColors[$t['niveau'] ?? 'P4'] ?? 'badge-gray';
                $nom = $t['firstname'] && $t['lastname']
                    ? htmlspecialchars($t['firstname'].' '.$t['lastname'])
                    : htmlspecialchars($t['patient_nom'] ?? 'Inconnu');
            ?>
            <div class="triage-row <?php echo $niveauClass; ?>"
                 style="background:var(--bg-e);border:1px solid var(--bdr);border-radius:9px;padding:.75rem 1rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.8rem;transition:border-color .2s;">
                <span class="badge <?php echo $nc; ?>"><?php echo htmlspecialchars($t['niveau'] ?? 'P4'); ?></span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.82rem;font-weight:600;color:var(--txt);"><?php echo $nom; ?></div>
                    <div style="font-size:.67rem;color:var(--txt-m);margin-top:.1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo htmlspecialchars(substr($t['motif'] ?? '—', 0, 60)); ?>
                    </div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--txt-s);">
                        <?php echo $t['heure_arrivee'] ? date('H:i', strtotime($t['heure_arrivee'])) : '—'; ?>
                    </div>
                    <div class="inter-status <?php echo $t['statut']==='attente' ? 'status-att' : 'status-cours'; ?>" style="margin-top:.2rem;">
                        <?php echo $t['statut'] === 'attente' ? 'Attente' : 'En traitement'; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Revenue card -->
        <div class="card">
            <div class="card-hdr">
                <div class="card-title">
                    <i class="fas fa-receipt"></i>
                    Revenus ce mois
                </div>
                <a href="facturation.php" class="btn btn-ghost btn-xs"><i class="fas fa-arrow-right"></i></a>
            </div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:2rem;font-weight:700;color:var(--green-l);">
                $<?php echo number_format($revenuMois, 0, ',', ' '); ?>
            </div>
            <div style="font-size:.7rem;color:var(--txt-m);margin-top:.4rem;">
                Factures payées — <?php echo date('F Y'); ?>
            </div>
        </div>
    </div>

    <!-- Interventions récentes -->
    <div>
        <div class="card">
            <div class="card-hdr">
                <div class="card-title">
                    <i class="fas fa-ambulance"></i>
                    Interventions récentes
                </div>
                <a href="interventions.php" class="btn btn-ghost btn-xs"><i class="fas fa-arrow-right"></i> Voir tout</a>
            </div>

            <?php if (empty($interRecentes)): ?>
            <div class="empty-state" style="padding:1.5rem;">
                <i class="fas fa-ambulance empty-icon"></i>
                <p>Aucune intervention récente</p>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:.5rem;">
            <?php foreach ($interRecentes as $inter):
                $urgenceClass = match($inter['urgence'] ?? '') {
                    'critique' => 'urgence-critique',
                    'elevee'   => 'urgence-elevee',
                    'moderee'  => 'urgence-moderee',
                    default    => 'urgence-faible'
                };
                $statusClass = match($inter['statut'] ?? '') {
                    'en_attente' => 'status-att',
                    'en_cours'   => 'status-cours',
                    'termine'    => 'status-fin',
                    default      => 'status-annule'
                };
                $statusLabel = match($inter['statut'] ?? '') {
                    'en_attente' => 'En attente',
                    'en_cours'   => 'En cours',
                    'termine'    => 'Terminée',
                    default      => 'Annulée'
                };
                $patientNom = $inter['firstname'] && $inter['lastname']
                    ? $inter['firstname'].' '.$inter['lastname']
                    : 'Patient inconnu';
            ?>
            <div style="background:var(--bg-e);border:1px solid var(--bdr);border-radius:9px;padding:.7rem .9rem;display:flex;align-items:center;gap:.75rem;transition:border-color .2s;"
                 onmouseover="this.style.borderColor='var(--bdr-h)'" onmouseout="this.style.borderColor='var(--bdr)'">
                <div style="flex-shrink:0;">
                    <span class="inter-status <?php echo $urgenceClass; ?>">
                        <?php echo htmlspecialchars($inter['urgence'] ?? 'N/A'); ?>
                    </span>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.81rem;font-weight:600;color:var(--txt);display:flex;align-items:center;gap:.5rem;">
                        <span class="mono" style="font-size:.7rem;color:var(--cyan-l);"><?php echo htmlspecialchars($inter['numero'] ?? '—'); ?></span>
                        <?php echo htmlspecialchars($inter['type'] ?? '—'); ?>
                    </div>
                    <div style="font-size:.68rem;color:var(--txt-m);margin-top:.1rem;">
                        <?php echo htmlspecialchars($patientNom); ?> &middot;
                        <?php echo htmlspecialchars($inter['createur'] ?? '—'); ?>
                    </div>
                </div>
                <div style="flex-shrink:0;text-align:right;">
                    <div class="inter-status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></div>
                    <div style="font-size:.66rem;color:var(--txt-m);margin-top:.2rem;font-family:'JetBrains Mono',monospace;">
                        <?php echo date('H:i', strtotime($inter['created_at'])); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ RAPPORTS RÉCENTS ══ -->
<div class="card">
    <div class="card-hdr">
        <div class="card-title">
            <i class="fas fa-file-medical"></i>
            Rapports médicaux récents
        </div>
        <a href="rapports.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-plus"></i> Nouveau rapport
        </a>
    </div>

    <?php if (empty($rapRecents)): ?>
    <div class="empty-state">
        <i class="fas fa-file-medical empty-icon"></i>
        <p>Aucun rapport récent</p>
    </div>
    <?php else: ?>
    <div class="tbl-wrap" style="margin:0;border:none;">
        <table class="tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Titre</th>
                    <th>Patient</th>
                    <th>Médecin</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rapRecents as $r):
                    $typeColors = [
                        'intervention'=>'badge-red','consultation'=>'badge-cyan',
                        'urgence'=>'badge-red','suivi'=>'badge-cyan',
                        'psychiatrie'=>'badge-purple','default'=>'badge-gray'
                    ];
                    $tc = $typeColors[$r['type'] ?? 'default'] ?? 'badge-gray';
                ?>
                <tr>
                    <td class="mono" style="font-size:.72rem;color:var(--txt-s);">#<?php echo $r['id']; ?></td>
                    <td><span class="badge <?php echo $tc; ?>"><?php echo htmlspecialchars($r['type'] ?? '—'); ?></span></td>
                    <td style="font-weight:500;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo htmlspecialchars($r['title'] ?? '—'); ?>
                    </td>
                    <td style="font-size:.78rem;color:var(--txt-s);">
                        <?php echo $r['firstname'] ? htmlspecialchars($r['firstname'].' '.$r['lastname']) : '—'; ?>
                    </td>
                    <td style="font-size:.78rem;color:var(--txt-s);"><?php echo htmlspecialchars($r['medecin'] ?? '—'); ?></td>
                    <td class="mono" style="font-size:.72rem;color:var(--txt-m);">
                        <?php echo date('d/m H:i', strtotime($r['created_at'])); ?>
                    </td>
                    <td>
                        <button class="btn btn-ghost btn-xs" onclick="viewReport(<?php echo $r['id']; ?>)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
function viewReport(id) {
    fetch(`get_report.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { showToast('Rapport introuvable', 'error'); return; }
            const r = data.report;
            document.getElementById('rp-num').textContent   = '#' + r.id;
            document.getElementById('rp-type').textContent  = r.type || '—';
            document.getElementById('rp-title').textContent = r.title || '—';
            document.getElementById('rp-patient').textContent = r.patient_name || '—';
            document.getElementById('rp-medecin').textContent = r.creator_name || '—';
            document.getElementById('rp-date').textContent  = new Date(r.created_at).toLocaleDateString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
            document.getElementById('rp-body').innerHTML    = r.description_rich || '<em style="color:var(--txt-m)">Aucune description</em>';
            openModal('modal-view-report');
        });
}
</script>

<!-- Modal view report -->
<div class="modal-ov hidden" id="modal-view-report">
    <div class="modal modal-lg">
        <div class="modal-hd">
            <h2><i class="fas fa-file-medical"></i>
                <span>Rapport</span>
                <span class="mono" id="rp-num" style="color:var(--cyan-l);font-size:.85rem;"></span>
                — <span id="rp-title" style="font-weight:400;text-transform:none;letter-spacing:0;"></span>
            </h2>
            <button class="modal-close" onclick="closeModal('modal-view-report')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-bd">
            <div style="display:flex;gap:.6rem;margin-bottom:1rem;flex-wrap:wrap;">
                <span class="badge badge-cyan" id="rp-type"></span>
            </div>
            <div class="grid2" style="gap:.75rem;margin-bottom:1rem;">
                <div style="background:var(--bg-e);border:1px solid var(--bdr);border-radius:8px;padding:.7rem .9rem;">
                    <div class="form-label">Patient</div>
                    <div style="font-size:.84rem;font-weight:600;margin-top:.2rem;" id="rp-patient"></div>
                </div>
                <div style="background:var(--bg-e);border:1px solid var(--bdr);border-radius:8px;padding:.7rem .9rem;">
                    <div class="form-label">Médecin rédacteur</div>
                    <div style="font-size:.84rem;font-weight:600;margin-top:.2rem;" id="rp-medecin"></div>
                </div>
            </div>
            <div style="background:var(--bg-e);border:1px solid var(--bdr);border-radius:8px;padding:1rem;line-height:1.65;font-size:.83rem;" id="rp-body"></div>
            <div style="margin-top:.75rem;font-size:.68rem;color:var(--txt-m);font-family:'JetBrains Mono',monospace;" id="rp-date"></div>
        </div>
        <div class="modal-ft">
            <a href="rapports.php" class="btn btn-secondary btn-sm"><i class="fas fa-list"></i> Tous les rapports</a>
            <button class="btn btn-ghost btn-sm" onclick="closeModal('modal-view-report')">Fermer</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>