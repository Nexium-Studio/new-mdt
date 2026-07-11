<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🏠 Dynasty';
$companyColor   = '#22c55e';
$companyFaction = 'DYNASTY';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();

/* ════ VUE CONTRAT BV ════ */
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $db->prepare("
        SELECT v.*, b.titre bien_titre, b.adresse bien_adresse, b.quartier bien_quartier,
               b.reference bien_ref, b.surface bien_surface, b.type_bien,
               u.username agent_nom
        FROM dynasty_ventes v
        JOIN dynasty_biens b ON v.bien_id = b.id
        JOIN users u ON v.agent_id = u.id
        WHERE v.id = ?
    ");
    $stmt->execute([(int)$_GET['view']]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vente) { header('Location: ventes.php'); exit; }

    $pageTitle = 'Contrat ' . $vente['reference'];
    include __DIR__ . '/includes/header.php';
    $modePaieLabel = ['especes'=>'Espèces','virement'=>'Virement bancaire','cheque'=>'Chèque','financement'=>'Financement'];
    ?>
    <div style="margin-bottom:1.3rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;" class="no-print">
        <a href="ventes.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Retour</a>
        <div style="display:flex;gap:.6rem;">
            <?php if($vente['statut']==='en_cours'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="change_statut_vente">
                <input type="hidden" name="vente_id" value="<?php echo $vente['id']; ?>">
                <input type="hidden" name="new_statut" value="signe">
                <button type="submit" class="btn btn-primary" data-confirm="Marquer ce contrat comme signé ?"><i class="fas fa-signature"></i> Marquer Signé</button>
            </form>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="change_statut_vente">
                <input type="hidden" name="vente_id" value="<?php echo $vente['id']; ?>">
                <input type="hidden" name="new_statut" value="annule">
                <button type="submit" class="btn btn-danger" data-confirm="Annuler cette vente ?"><i class="fas fa-times"></i> Annuler</button>
            </form>
            <?php elseif($vente['statut']==='signe'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="change_statut_vente">
                <input type="hidden" name="vente_id" value="<?php echo $vente['id']; ?>">
                <input type="hidden" name="new_statut" value="acte_final">
                <button type="submit" class="btn btn-primary" data-confirm="Passer en acte final ? Le bien sera marqué vendu."><i class="fas fa-gavel"></i> Acte Final</button>
            </form>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Imprimer / PDF</button>
        </div>
    </div>

    <div style="max-width:820px;margin:0 auto;">
    <div style="background:linear-gradient(155deg,#040e07,#020804);border:1px solid rgba(245,158,11,.2);border-top:3px solid #f59e0b;border-radius:20px;overflow:hidden;font-family:'Plus Jakarta Sans',sans-serif;">

        <!-- Header contrat -->
        <div style="padding:2rem 2.5rem;background:linear-gradient(135deg,rgba(245,158,11,.05),transparent);border-bottom:1px solid rgba(245,158,11,.15);display:flex;justify-content:space-between;align-items:flex-start;gap:1.5rem;">
            <div style="display:flex;align-items:center;gap:1rem;">
                <div style="width:52px;height:52px;background:linear-gradient(135deg,#22c55e,#15803d);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;flex-shrink:0;"><i class="fas fa-building"></i></div>
                <div>
                    <div style="font-size:1.25rem;font-weight:900;color:#e2f5e9;">Dynasty Immobilier</div>
                    <div style="font-size:.72rem;color:#5f9c75;">Agence de gestion de biens — Los Santos</div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.15em;color:#f59e0b;margin-bottom:.3rem;">BON DE VENTE</div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:1.5rem;font-weight:900;color:#e2f5e9;"><?php echo $vente['reference']; ?></div>
                <div style="font-size:.72rem;color:#5f9c75;margin-top:.22rem;">Émis le <?php echo date('d/m/Y',strtotime($vente['created_at'])); ?></div>
                <div style="margin-top:.5rem;"><span class="badge badge-<?php echo $vente['statut']; ?>"><?php echo ucfirst(str_replace('_',' ',$vente['statut'])); ?></span></div>
            </div>
        </div>

        <!-- Corps contrat -->
        <div style="padding:2rem 2.5rem;">

            <!-- Parties -->
            <div style="margin-bottom:1.75rem;padding-bottom:1.75rem;border-bottom:1px solid rgba(255,255,255,.065);">
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#f59e0b;margin-bottom:1rem;"><i class="fas fa-users"></i> Les Parties</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                    <div style="background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.12);border-radius:12px;padding:1.1rem;">
                        <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:#5f9c75;margin-bottom:.6rem;">Vendeur</div>
                        <div style="font-weight:800;color:#e2f5e9;font-size:1rem;">Dynasty Immobilier</div>
                        <div style="font-size:.78rem;color:#5f9c75;margin-top:.25rem;">Agent : <?php echo htmlspecialchars($vente['agent_nom']); ?></div>
                    </div>
                    <div style="background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.12);border-radius:12px;padding:1.1rem;">
                        <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:#5f9c75;margin-bottom:.6rem;">Acquéreur</div>
                        <div style="font-weight:800;color:#e2f5e9;font-size:1rem;"><?php echo htmlspecialchars($vente['acheteur_nom']); ?></div>
                        <?php if($vente['acheteur_id_jeu']): ?><div style="font-size:.78rem;color:#5f9c75;">ID Citoyen : <?php echo htmlspecialchars($vente['acheteur_id_jeu']); ?></div><?php endif; ?>
                        <?php if($vente['acheteur_contact']): ?><div style="font-size:.78rem;color:#5f9c75;">Contact : <?php echo htmlspecialchars($vente['acheteur_contact']); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bien cédé -->
            <div style="margin-bottom:1.75rem;padding-bottom:1.75rem;border-bottom:1px solid rgba(255,255,255,.065);">
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#f59e0b;margin-bottom:1rem;"><i class="fas fa-home"></i> Bien Cédé</div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.85rem;">
                    <div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Type</div><div style="font-weight:700;color:#e2f5e9;"><?php echo ucfirst($vente['type_bien']); ?></div></div>
                    <div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Référence</div><div style="font-weight:700;color:#e2f5e9;font-family:'JetBrains Mono',monospace;"><?php echo $vente['bien_ref']; ?></div></div>
                    <?php if($vente['bien_surface']): ?><div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Surface</div><div style="font-weight:700;color:#e2f5e9;"><?php echo $vente['bien_surface']; ?> m²</div></div><?php endif; ?>
                    <div style="grid-column:1/-1"><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Adresse</div><div style="font-weight:700;color:#e2f5e9;"><?php echo htmlspecialchars($vente['bien_titre']); ?> — <?php echo htmlspecialchars($vente['bien_adresse']); ?></div></div>
                    <div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Date de vente</div><div style="font-weight:700;color:#e2f5e9;"><?php echo date('d/m/Y',strtotime($vente['date_vente'])); ?></div></div>
                </div>
            </div>

            <!-- Conditions financières -->
            <div style="margin-bottom:1.75rem;padding-bottom:1.75rem;border-bottom:1px solid rgba(255,255,255,.065);">
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#f59e0b;margin-bottom:1rem;"><i class="fas fa-dollar-sign"></i> Conditions Financières</div>
                <div style="background:linear-gradient(135deg,rgba(245,158,11,.06),transparent);border:1.5px solid rgba(245,158,11,.2);border-radius:13px;padding:1.3rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Prix affiché</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#e2f5e9;">$<?php echo number_format($vente['prix_initial'],2); ?></span></div>
                    <?php if($vente['remise']>0): ?><div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Remise accordée</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#ef4444;">- $<?php echo number_format($vente['remise'],2); ?></span></div><?php endif; ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Mode de paiement</span><span style="font-weight:700;color:#e2f5e9;"><?php echo $modePaieLabel[$vente['mode_paiement']]??$vente['mode_paiement']; ?></span></div>
                    <?php if($vente['frais_agence']>0): ?><div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Frais d'agence</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#e2f5e9;">$<?php echo number_format($vente['frais_agence'],2); ?></span></div><?php endif; ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Commission agent (<?php echo number_format($vente['commission_pct'],1); ?>%)</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#22c55e;">$<?php echo number_format($vente['commission_amt'],2); ?></span></div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.8rem 0 0;margin-top:.4rem;">
                        <span style="font-weight:800;color:#e2f5e9;font-size:.95rem;">PRIX DE CESSION</span>
                        <span style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;color:#f59e0b;text-shadow:0 0 18px rgba(245,158,11,.4);">$<?php echo number_format($vente['prix_negocie'],2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Clauses -->
            <div style="margin-bottom:1.75rem;padding-bottom:1.75rem;border-bottom:1px solid rgba(255,255,255,.065);">
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#f59e0b;margin-bottom:.85rem;"><i class="fas fa-gavel"></i> Clauses & Conditions</div>
                <div style="font-size:.8rem;color:#5f9c75;line-height:1.75;">
                    <p>1. Le transfert de propriété s'effectue à la date de signature de l'acte final et sous réserve du paiement intégral du prix de cession.</p>
                    <p>2. Le bien est cédé en l'état, l'acquéreur reconnaissant avoir procédé à une visite et une inspection complète.</p>
                    <p>3. Les vices cachés inconnus au moment de la vente ne sauraient engager la responsabilité de Dynasty Immobilier.</p>
                </div>
                <?php if($vente['notes']): ?>
                <div style="margin-top:.85rem;padding:.85rem 1rem;background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.12);border-radius:8px;font-size:.82rem;color:#5f9c75;"><?php echo nl2br(htmlspecialchars($vente['notes'])); ?></div>
                <?php endif; ?>
            </div>

            <!-- Signatures -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;margin-top:1.75rem;padding-top:1.5rem;border-top:1px dashed rgba(245,158,11,.2);">
                <div style="text-align:center;">
                    <div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3rem;">Signature du Vendeur<br>Dynasty Immobilier</div>
                    <div style="border-top:1px solid rgba(245,158,11,.2);padding-top:.5rem;font-size:.72rem;color:#5f9c75;">Agent : <?php echo htmlspecialchars($vente['agent_nom']); ?></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3rem;">Signature de l'Acquéreur</div>
                    <div style="border-top:1px solid rgba(245,158,11,.2);padding-top:.5rem;font-size:.72rem;color:#5f9c75;"><?php echo htmlspecialchars($vente['acheteur_nom']); ?></div>
                </div>
            </div>
        </div>

        <!-- Footer contrat -->
        <div style="background:rgba(245,158,11,.03);border-top:1px solid rgba(245,158,11,.12);padding:1rem 2.5rem;font-size:.66rem;color:#5f9c75;text-align:center;line-height:1.75;">
            Dynasty Immobilier · Agence de gestion immobilière · Los Santos, San Andreas<br>
            Ce document constitue un compromis/acte de vente. Toute reproduction ou modification non autorisée est interdite.<br>
            Référence interne : <?php echo $vente['reference']; ?> · Généré le <?php echo date('d/m/Y H:i'); ?>
        </div>
    </div>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; exit; ?>
<?php }

/* ════ TRAITEMENTS POST ════ */
$message = ''; $msgType = '';

function generateBV($db) {
    $db->beginTransaction();
    $r    = $db->query("SELECT * FROM dynasty_sequences WHERE type='BV' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    $year = (int)date('Y');
    $next = ($r && (int)$r['annee'] === $year) ? (int)$r['dernier'] + 1 : 1;
    $db->prepare("INSERT INTO dynasty_sequences(type,annee,dernier) VALUES('BV',?,?) ON DUPLICATE KEY UPDATE annee=VALUES(annee),dernier=VALUES(dernier)")
       ->execute([$year, $next]);
    $db->commit();
    return 'BV-'.$year.'-'.str_pad($next, 5, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Créer vente / générer BV ── */
    if ($action === 'create_vente') {
        try {
            $db->beginTransaction();
            $ref = generateBV($db);

            $bs = $db->prepare("SELECT * FROM dynasty_biens WHERE id=?");
            $bs->execute([(int)$_POST['bien_id']]);
            $bien = $bs->fetch(PDO::FETCH_ASSOC);
            if (!$bien) throw new Exception("Bien introuvable.");

            $prix_initial = (float)$bien['prix_vente'];
            $prix_negocie = (float)$_POST['prix_negocie'];
            $remise       = max(0, $prix_initial - $prix_negocie);
            $comm_pct     = (float)($_POST['commission_pct'] ?? $bien['commission_vente_pct'] ?? 5);
            $comm_amt     = $prix_negocie * $comm_pct / 100;
            $frais        = (float)($_POST['frais_agence'] ?? 0);

            $db->prepare("
                INSERT INTO dynasty_ventes
                    (reference,bien_id,agent_id,acheteur_nom,acheteur_id_jeu,acheteur_contact,
                     prix_initial,prix_negocie,remise,mode_paiement,date_vente,
                     commission_pct,commission_amt,frais_agence,statut,notes)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'en_cours',?)
            ")->execute([
                $ref,(int)$_POST['bien_id'],(int)$_POST['agent_id'],
                sanitize($_POST['acheteur_nom']),
                sanitize($_POST['acheteur_id_jeu']??''),
                sanitize($_POST['acheteur_contact']??''),
                $prix_initial,$prix_negocie,$remise,
                $_POST['mode_paiement'],
                $_POST['date_vente'],
                $comm_pct,$comm_amt,$frais,
                sanitize($_POST['notes']??''),
            ]);
            $vente_id = $db->lastInsertId();

            // Marquer bien réservé
            // Statut instance non modifié — réutilisable

            // Facture
            $db->prepare("
                INSERT INTO company_invoices (faction,employee_id,customer_name,description,amount,final_amount,status)
                VALUES(?,(SELECT id FROM users WHERE username=? LIMIT 1),?,?,?,?,'paid')
            ")->execute([
                $companyFaction,
                sanitize($_POST['agent_username']??$_SESSION['user_username']),
                sanitize($_POST['acheteur_nom']),
                "Bon de Vente $ref — ".$bien['titre'],
                $comm_amt + $frais, $comm_amt + $frais,
            ]);
            $inv_id = $db->lastInsertId();

            $db->prepare("UPDATE dynasty_ventes SET invoice_id=? WHERE id=?")->execute([$inv_id,$vente_id]);

            // Tracking CA individuel — robuste si la ligne company_employees n'existe pas encore
            try {
                $upd = $db->prepare("UPDATE company_employees SET individual_ca = individual_ca + ? WHERE user_id=? AND faction=?");
                $upd->execute([$comm_amt, (int)$_POST['agent_id'], $companyFaction]);
                if ($upd->rowCount() === 0) {
                    $arq = $db->prepare("SELECT COALESCE(NULLIF(faction_rank,''),'Agent') FROM users WHERE id=? LIMIT 1");
                    $arq->execute([(int)$_POST['agent_id']]);
                    $agentRank = (string)($arq->fetchColumn() ?: 'Agent');
                    $db->prepare("INSERT INTO company_employees (user_id, faction, `rank`, salary_percentage, individual_ca, hired_date) VALUES (?, ?, ?, 70.00, ?, CURDATE())")
                       ->execute([(int)$_POST['agent_id'], $companyFaction, $agentRank, $comm_amt]);
                }
            } catch (Exception $eCE) { /* silencieux */ }

            $db->commit();
            $message = "✅ Vente $ref créée. Bien marqué réservé. Facture générée.";
            $msgType = 'success';
        } catch(Exception $e) {
            $db->rollBack();
            $message = '❌ '.$e->getMessage(); $msgType = 'error';
        }
    }

    /* ── Changer statut vente ── */
    if ($action === 'change_statut_vente') {
        try {
            $vid = (int)$_POST['vente_id'];
            $ns  = $_POST['new_statut'];
            $db->prepare("UPDATE dynasty_ventes SET statut=? WHERE id=?")->execute([$ns,$vid]);

            if ($ns === 'acte_final') {
                $v = $db->prepare("SELECT bien_id FROM dynasty_ventes WHERE id=?");
                $v->execute([$vid]); $bid = $v->fetchColumn();
                // Pas de modification statut instance
            } elseif ($ns === 'annule') {
                $v = $db->prepare("SELECT bien_id FROM dynasty_ventes WHERE id=?");
                $v->execute([$vid]); $bid = $v->fetchColumn();
                // Pas de modification statut instance
            }

            $message = "✅ Statut mis à jour."; $msgType = 'success';
        } catch(Exception $e) {
            $message = '❌ '.$e->getMessage(); $msgType = 'error';
        }
    }
}

/* ════ DONNÉES ════ */
$pageTitle    = 'Ventes';
$pageSubtitle = 'Bons de vente & transactions immobilières';

$fStatut = $_GET['statut'] ?? 'all';
$fAgent  = $_GET['agent']  ?? 'all';
$search  = trim($_GET['q'] ?? '');

$where  = ['1=1']; $params = [];
if ($fStatut !== 'all')  { $where[] = 'v.statut=?';   $params[] = $fStatut; }
if ($fAgent  !== 'all')  { $where[] = 'v.agent_id=?'; $params[] = $fAgent; }
if ($search) {
    $where[]  = '(v.reference LIKE ? OR v.acheteur_nom LIKE ? OR b.titre LIKE ?)';
    $params   = array_merge($params,["%$search%","%$search%","%$search%"]);
}

$ventes = $db->prepare("
    SELECT v.*, b.titre bien_titre, b.quartier bien_quartier, b.reference bien_ref,
           u.username agent_nom
    FROM dynasty_ventes v
    JOIN dynasty_biens b ON v.bien_id = b.id
    JOIN users u ON v.agent_id = u.id
    WHERE ".implode(' AND ',$where)."
    ORDER BY v.created_at DESC LIMIT 200
");
$ventes->execute($params);
$ventes = $ventes->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $db->query("
    SELECT
        SUM(statut='en_cours')  nb_en_cours,
        SUM(statut='signe')     nb_signe,
        SUM(statut='acte_final') nb_acte,
        SUM(statut='annule')    nb_annule,
        SUM(CASE WHEN statut IN('signe','acte_final') THEN prix_negocie ELSE 0 END) ca_ventes,
        SUM(CASE WHEN statut IN('signe','acte_final') THEN commission_amt ELSE 0 END) comm_total
    FROM dynasty_ventes
")->fetch(PDO::FETCH_ASSOC);

// Toutes les instances (réutilisables à l'infini)
$biens_vente = $db->query("
    SELECT id,titre,quartier,reference,prix_vente,commission_vente_pct
    FROM dynasty_biens
    ORDER BY titre
")->fetchAll(PDO::FETCH_ASSOC);

// Agents — source unifiée : users.faction (synchronisé avec effectifs.php)
$agents = $db->query("
    SELECT u.id, u.username,
           COALESCE(NULLIF(u.faction_rank,''), 'Agent') AS rank,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname,
           u.avatar
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE u.faction='DYNASTY' AND u.is_active=1
    ORDER BY COALESCE(p.lastname,''), COALESCE(p.firstname,''), u.username
")->fetchAll(PDO::FETCH_ASSOC);

// Profils civils (acheteurs potentiels) — pour le picker dynamique
$civilProfiles = $db->query("
    SELECT id, firstname, lastname, phone, avatar
    FROM profiles
    WHERE TRIM(COALESCE(firstname,''))<>'' OR TRIM(COALESCE(lastname,''))<>''
    ORDER BY lastname, firstname
    LIMIT 2000
")->fetchAll(PDO::FETCH_ASSOC);

// Pré-sélection bien
$preBien = null;
if (!empty($_GET['bien_id'])) {
    $ps = $db->prepare("SELECT * FROM dynasty_biens WHERE id=?");
    $ps->execute([(int)$_GET['bien_id']]);
    $preBien = $ps->fetch(PDO::FETCH_ASSOC);
}

include __DIR__ . '/includes/header.php';
?>

<?php if($message): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo $message; ?></div>
<?php endif; ?>

<!-- Header page -->
<div class="page-header">
    <div>
        <div class="page-header-title"><i class="fas fa-handshake"></i> Ventes</div>
        <div class="page-header-sub"><?php echo count($ventes); ?> dossier<?php echo count($ventes)>1?'s':''; ?> sur la sélection courante · Génération automatique des bons de vente</div>
    </div>
    <div class="page-header-actions">
        <button onclick="Company.openModal('createVenteModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle vente</button>
    </div>
</div>

<!-- Stats -->
<div class="dy-stats">
    <div class="dy-stat">
        <div class="dy-stat-ico ico-amber"><i class="fas fa-folder-open"></i></div>
        <div><div class="dy-stat-val"><?php echo $stats['nb_en_cours']??0; ?></div><div class="dy-stat-lbl">En cours</div></div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico"><i class="fas fa-signature"></i></div>
        <div><div class="dy-stat-val"><?php echo $stats['nb_signe']??0; ?></div><div class="dy-stat-lbl">Signées</div></div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico"><i class="fas fa-gavel"></i></div>
        <div><div class="dy-stat-val"><?php echo $stats['nb_acte']??0; ?></div><div class="dy-stat-lbl">Actes finaux</div></div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico ico-amber"><i class="fas fa-dollar-sign"></i></div>
        <div>
            <div class="dy-stat-val" style="font-size:1.15rem;">$<?php echo number_format($stats['ca_ventes']??0,0); ?></div>
            <div class="dy-stat-lbl">CA des ventes</div>
            <div class="dy-stat-sub">Commissions $<?php echo number_format($stats['comm_total']??0,0); ?></div>
        </div>
    </div>
</div>

<!-- Filtres -->
<form method="GET">
<div class="filters-bar">
    <div class="filter-group" style="flex:2;">
        <label class="filter-lbl">Recherche</label>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-input" placeholder="Référence, acheteur, bien...">
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Statut</label>
        <select name="statut" class="form-select">
            <option value="all">Tous</option>
            <option value="en_cours"  <?php echo $fStatut==='en_cours'?'selected':''; ?>>En cours</option>
            <option value="signe"     <?php echo $fStatut==='signe'?'selected':''; ?>>Signé</option>
            <option value="acte_final"<?php echo $fStatut==='acte_final'?'selected':''; ?>>Acte Final</option>
            <option value="annule"    <?php echo $fStatut==='annule'?'selected':''; ?>>Annulé</option>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Agent</label>
        <select name="agent" class="form-select">
            <option value="all">Tous les agents</option>
            <?php foreach($agents as $a):
                $aDisp = mdt_rp_display_name($a['profile_firstname'] ?? null, $a['profile_lastname'] ?? null, (string)$a['username']);
            ?><option value="<?php echo $a['id']; ?>" <?php echo $fAgent==$a['id']?'selected':''; ?>><?php echo htmlspecialchars($aDisp); ?></option><?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;"><i class="fas fa-search"></i></button>
    <?php if($search||$fStatut!=='all'||$fAgent!=='all'): ?><a href="ventes.php" class="btn btn-secondary btn-sm" style="align-self:flex-end;"><i class="fas fa-times"></i></a><?php endif; ?>
</div>
</form>

<!-- Table ventes -->
<?php if(empty($ventes)): ?>
<div class="card"><div class="empty-state"><i class="fas fa-handshake"></i><p>Aucune vente trouvée.</p><button onclick="Company.openModal('createVenteModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Créer la première</button></div></div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden;">
<table class="dy-table">
    <thead><tr>
        <th>Référence</th><th>Bien</th><th>Acheteur</th>
        <th>Prix Initial</th><th>Prix Négocié</th><th>Remise</th>
        <th>Mode Paiement</th><th>Date</th><th>Agent</th><th>Statut</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php
    $modeLabels = ['especes'=>'Espèces','virement'=>'Virement','cheque'=>'Chèque','financement'=>'Financement'];
    foreach($ventes as $v): ?>
    <tr>
        <td><a href="ventes.php?view=<?php echo $v['id']; ?>" style="font-family:'JetBrains Mono',monospace;font-size:.78rem;color:#f59e0b;font-weight:700;text-decoration:none;"><?php echo $v['reference']; ?></a></td>
        <td><div style="font-weight:600;font-size:.84rem;"><?php echo htmlspecialchars($v['bien_titre']); ?></div><div style="font-size:.7rem;color:var(--tx2);"><?php echo htmlspecialchars($v['bien_ref']); ?> · <?php echo htmlspecialchars($v['bien_quartier']??''); ?></div></td>
        <td>
            <div style="font-weight:600;font-size:.84rem;color:var(--tx);"><?php echo htmlspecialchars($v['acheteur_nom']); ?></div>
            <?php if(!empty($v['acheteur_contact'])): ?><div style="font-size:.7rem;color:var(--tx-3);"><i class="fas fa-phone" style="font-size:.6rem;color:var(--dy);margin-right:.2rem"></i><?php echo htmlspecialchars($v['acheteur_contact']); ?></div><?php endif; ?>
        </td>
        <td style="font-size:.82rem;color:var(--tx2);">$<?php echo number_format($v['prix_initial'],0); ?></td>
        <td style="font-weight:700;color:#f59e0b;font-family:'Orbitron',monospace;font-size:.82rem;">$<?php echo number_format($v['prix_negocie'],0); ?></td>
        <td style="font-size:.78rem;<?php echo $v['remise']>0?'color:#ef4444;font-weight:700;':'color:var(--tx2);'; ?>"><?php echo $v['remise']>0?'-$'.number_format($v['remise'],0):'—'; ?></td>
        <td style="font-size:.78rem;"><?php echo $modeLabels[$v['mode_paiement']]??$v['mode_paiement']; ?></td>
        <td style="font-size:.78rem;"><?php echo date('d/m/Y',strtotime($v['date_vente'])); ?></td>
        <td style="font-size:.82rem;"><?php echo htmlspecialchars($v['agent_nom']); ?></td>
        <td><span class="badge badge-<?php echo $v['statut']; ?>"><?php echo str_replace('_',' ',ucfirst($v['statut'])); ?></span></td>
        <td>
            <div style="display:flex;gap:.35rem;flex-wrap:nowrap;">
                <a href="ventes.php?view=<?php echo $v['id']; ?>" class="btn btn-secondary btn-sm btn-icon" title="Voir BV"><i class="fas fa-eye"></i></a>
                <?php if($v['statut']==='en_cours'): ?>
                <button onclick="changeStatutVente(<?php echo $v['id']; ?>,'signe')" class="btn btn-primary btn-sm btn-icon" title="Marquer signé" style="background:linear-gradient(135deg,#22c55e,#15803d);"><i class="fas fa-check"></i></button>
                <button onclick="changeStatutVente(<?php echo $v['id']; ?>,'annule')" class="btn btn-danger btn-sm btn-icon" title="Annuler"><i class="fas fa-times"></i></button>
                <?php elseif($v['statut']==='signe'): ?>
                <button onclick="changeStatutVente(<?php echo $v['id']; ?>,'acte_final')" class="btn btn-primary btn-sm btn-icon" title="Acte Final" style="background:linear-gradient(135deg,#10b981,#059669);"><i class="fas fa-gavel"></i></button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<!-- ════ MODAL CRÉER VENTE ════ -->
<div id="createVenteModal" class="modal-overlay hidden">
<div class="modal modal-lg">
    <div class="modal-header"><h2><i class="fas fa-handshake" style="color:#f59e0b !important;"></i> Nouvelle Vente — BV Automatique</h2><button class="modal-close" onclick="Company.closeModal('createVenteModal')">×</button></div>
    <form method="POST"><div class="modal-body"><input type="hidden" name="action" value="create_vente">
        <div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:10px;padding:.85rem 1rem;margin-bottom:1.2rem;font-size:.82rem;color:#f59e0b;">
            <i class="fas fa-magic"></i> Un <strong>Bon de Vente</strong> sera généré automatiquement. Le bien sera marqué réservé jusqu'à l'acte final.
        </div>

        <div class="sec-title"><i class="fas fa-home"></i> Bien à vendre</div>
        <div class="form-grid">
            <div class="form-group form-full">
                <label class="form-label">Bien *</label>
                <select name="bien_id" id="vente_bien" class="form-select" required onchange="loadBienInfoVente(this.value)">
                    <option value="">— Sélectionner une instance —</option>
                    <?php foreach($biens_vente as $b): ?>
                    <option value="<?php echo $b['id']; ?>"
                        data-pv="<?php echo $b['prix_vente']??0; ?>"
                        data-cv="<?php echo $b['commission_vente_pct']??5; ?>"
                        <?php echo ($preBien&&$preBien['id']==$b['id'])?'selected':''; ?>>
                        <?php echo $b['reference']; ?> — <?php echo htmlspecialchars($b['titre']); ?> (<?php echo htmlspecialchars($b['quartier']??''); ?>) — $<?php echo number_format($b['prix_vente']??0,0); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="bienVenteInfoBox" style="display:none;" class="form-full">
                <div style="background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.15);border-radius:10px;padding:.9rem 1.1rem;font-size:.8rem;display:flex;gap:1.5rem;flex-wrap:wrap;">
                    <span><i class="fas fa-tag" style="color:#f59e0b;"></i> Prix affiché : <strong id="bv_pv" style="color:#f59e0b;">—</strong></span>
                    <span><i class="fas fa-percentage" style="color:#f59e0b;"></i> Comm. std : <strong id="bv_cv">—</strong>%</span>
                </div>
            </div>
        </div>

        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-user"></i> Acquéreur</div>
        <div class="form-grid">
            <div class="form-group form-full">
                <label class="form-label form-label-req">Acquéreur</label>
                <div class="picker" data-picker
                     id="acheteur_picker"
                     data-name="acheteur_nom"
                     data-required
                     data-placeholder="Rechercher un citoyen par nom, prénom..."></div>
                <div class="form-hint"><i class="fas fa-info-circle" style="opacity:.6;margin-right:.2rem"></i> La recherche puise dans le registre civil (profiles) — le téléphone se pré-remplit automatiquement.</div>
            </div>
            <div class="form-group form-full">
                <label class="form-label">Contact (téléphone / email)</label>
                <input type="text" name="acheteur_contact" id="acheteur_contact_input" class="form-input" placeholder="Auto-rempli après sélection (modifiable)">
            </div>
        </div>

        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-dollar-sign"></i> Conditions Financières</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Prix négocié HT ($) * <span style="font-size:.65rem;color:#94a3b8;">TTC = HT × 1.10</span></label>
                <input type="number" name="prix_negocie" id="vente_negocie" class="form-input" step="0.01" min="0" required oninput="calcVente()">
            </div>
            <div class="form-group">
                <label class="form-label">Mode de paiement *</label>
                <select name="mode_paiement" class="form-select">
                    <option value="especes">Espèces</option>
                    <option value="virement">Virement bancaire</option>
                    <option value="cheque">Chèque</option>
                    <option value="financement">Financement</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date de vente *</label>
                <input type="date" name="date_vente" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Commission agent (%)</label>
                <input type="number" name="commission_pct" id="vente_comm" class="form-input" step="0.01" value="5" oninput="calcVente()">
            </div>
            <div class="form-group">
                <label class="form-label">Frais d'agence ($)</label>
                <input type="number" name="frais_agence" id="vente_frais" class="form-input" step="0.01" value="0" oninput="calcVente()">
            </div>
            <div class="form-group">
                <label class="form-label form-label-req">Agent</label>
                <select name="agent_id" id="vente_agent" class="form-select" required>
                    <?php foreach($agents as $a):
                        $aDisp = mdt_rp_display_name($a['profile_firstname'] ?? null, $a['profile_lastname'] ?? null, (string)$a['username']);
                    ?><option value="<?php echo $a['id']; ?>" <?php echo $a['username']===$_SESSION['user_username']?'selected':''; ?>><?php echo htmlspecialchars($aDisp); ?> · <?php echo htmlspecialchars($a['rank']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" placeholder="Conditions particulières, observations..."></textarea></div>
        </div>
        <input type="hidden" name="agent_username" value="<?php echo htmlspecialchars($_SESSION['user_username']); ?>">

        <!-- Résumé financier -->
        <div id="resumeVente" style="display:none;margin-top:1rem;background:linear-gradient(135deg,rgba(245,158,11,.06),transparent);border:1.5px solid rgba(245,158,11,.2);border-radius:13px;padding:1.2rem;">
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#f59e0b;margin-bottom:.85rem;">Récapitulatif financier</div>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.65rem;text-align:center;">
                <div style="background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.1);border-radius:9px;padding:.8rem;"><div style="font-size:.58rem;color:var(--tx2);text-transform:uppercase;margin-bottom:.22rem;">Prix affiché</div><div id="rv_initial" style="font-family:'Orbitron',monospace;font-weight:900;color:var(--tx2);font-size:.88rem;">—</div></div>
                <div style="background:rgba(239,68,68,.04);border:1px solid rgba(239,68,68,.1);border-radius:9px;padding:.8rem;"><div style="font-size:.58rem;color:var(--tx2);text-transform:uppercase;margin-bottom:.22rem;">Remise</div><div id="rv_remise" style="font-family:'Orbitron',monospace;font-weight:900;color:#ef4444;font-size:.88rem;">—</div></div>
                <div style="background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.1);border-radius:9px;padding:.8rem;"><div style="font-size:.58rem;color:var(--tx2);text-transform:uppercase;margin-bottom:.22rem;">Frais agence</div><div id="rv_frais" style="font-family:'Orbitron',monospace;font-weight:900;color:#22c55e;font-size:.88rem;">—</div></div>
                <div style="background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.1);border-radius:9px;padding:.8rem;"><div style="font-size:.58rem;color:var(--tx2);text-transform:uppercase;margin-bottom:.22rem;">Commission</div><div id="rv_comm" style="font-family:'Orbitron',monospace;font-weight:900;color:#22c55e;font-size:.88rem;">—</div></div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.85rem;padding-top:.75rem;border-top:1px solid rgba(245,158,11,.15);">
                <div></div>
                <div style="text-align:right;">
                    <div style="font-size:.62rem;color:var(--tx2);margin-bottom:.15rem;">PRIX DE CESSION FINAL</div>
                    <div id="rv_final" style="font-family:'Orbitron',monospace;font-weight:900;color:#f59e0b;font-size:1.4rem;text-shadow:0 0 14px rgba(245,158,11,.35);">—</div>
                </div>
            </div>
        </div>

    </div><div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createVenteModal')">Annuler</button>
        <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 4px 15px rgba(245,158,11,.3);"><i class="fas fa-magic"></i> Créer & Générer le BV</button>
    </div></form>
</div></div>

<!-- Form changement statut -->
<form id="venteStatutForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="change_statut_vente">
    <input type="hidden" name="vente_id"   id="vsf_id">
    <input type="hidden" name="new_statut" id="vsf_statut">
</form>

<script>
const BIENS_VENTE = <?php echo json_encode(array_column($biens_vente,null,'id')); ?>;
const CIVIL_PROFILES = <?php echo json_encode($civilProfiles, JSON_UNESCAPED_UNICODE); ?>;

function initAcheteurPicker() {
    const picker = document.getElementById('acheteur_picker');
    if (!picker) return;
    if (!picker._picker) { setTimeout(initAcheteurPicker, 30); return; }
    picker._picker.setData(CIVIL_PROFILES);
    picker.addEventListener('picker:change', e => {
        const item = e.detail || {};
        const contactInput = document.getElementById('acheteur_contact_input');
        if (contactInput && item.phone) contactInput.value = item.phone;
    });
}
document.addEventListener('DOMContentLoaded', () => setTimeout(initAcheteurPicker, 0));

function loadBienInfoVente(id) {
    const b = BIENS_VENTE[id]; if(!b) { document.getElementById('bienVenteInfoBox').style.display='none'; return; }
    document.getElementById('bv_pv').textContent = '$'+parseFloat(b.prix_vente||0).toLocaleString('fr-FR',{minimumFractionDigits:0});
    document.getElementById('bv_cv').textContent = b.commission_vente_pct||5;
    document.getElementById('vente_negocie').value = b.prix_vente||0;
    document.getElementById('vente_comm').value    = b.commission_vente_pct||5;
    document.getElementById('bienVenteInfoBox').style.display='';
    calcVente();
}

function calcVente() {
    const bien_id = document.getElementById('vente_bien').value;
    const b       = BIENS_VENTE[bien_id]; if(!b) return;
    const negocie = parseFloat(document.getElementById('vente_negocie').value)||0;
    const comm    = parseFloat(document.getElementById('vente_comm').value)||0;
    const frais   = parseFloat(document.getElementById('vente_frais').value)||0;
    const initial = parseFloat(b.prix_vente||0);
    const remise  = Math.max(0, initial - negocie);
    const comm_amt = negocie * comm / 100;

    document.getElementById('rv_initial').textContent = '$'+initial.toLocaleString('fr-FR',{minimumFractionDigits:0});
    document.getElementById('rv_remise').textContent  = remise>0?'-$'+remise.toLocaleString('fr-FR',{minimumFractionDigits:0}):'Aucune';
    document.getElementById('rv_frais').textContent   = '$'+frais.toLocaleString('fr-FR',{minimumFractionDigits:2});
    document.getElementById('rv_comm').textContent    = '$'+comm_amt.toLocaleString('fr-FR',{minimumFractionDigits:2});
    document.getElementById('rv_final').textContent   = '$'+negocie.toLocaleString('fr-FR',{minimumFractionDigits:0});
    document.getElementById('resumeVente').style.display='';
}

function changeStatutVente(id, statut) {
    const labels = {
        signe:'Marquer cette vente comme signée ?',
        acte_final:'Passer en acte final ? Le bien sera définitivement vendu.',
        annule:'Annuler cette vente ? Le bien sera remis disponible.'
    };
    (async () => {
        const C = window.Company || window.Pearl;
        if (!await C.confirm({ message: labels[statut] || 'Confirmer ?' })) return;
        document.getElementById('vsf_id').value     = id;
        document.getElementById('vsf_statut').value = statut;
        document.getElementById('venteStatutForm').submit();
    })();
}

<?php if($preBien): ?>
document.getElementById('vente_bien').value = '<?php echo $preBien['id']; ?>';
loadBienInfoVente('<?php echo $preBien['id']; ?>');
<?php endif; ?>

if(new URLSearchParams(location.search).get('open')==='new') Company.openModal('createVenteModal');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>