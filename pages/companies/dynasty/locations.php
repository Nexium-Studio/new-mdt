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

if (!function_exists('sanitize')) {
    function sanitize(string $v): string { return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8'); }
}

/* ════ VUE CONTRAT BL ════ */
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $db->prepare("
        SELECT l.*, b.titre bien_titre, b.adresse bien_adresse, b.quartier bien_quartier,
               b.reference bien_ref, b.surface bien_surface, b.type_bien,
               u.username agent_nom
        FROM dynasty_locations l
        JOIN dynasty_biens b ON l.bien_id = b.id
        JOIN users u ON l.agent_id = u.id
        WHERE l.id = ?
    ");
    $stmt->execute([(int)$_GET['view']]);
    $loc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loc) { header('Location: locations.php'); exit; }

    $pageTitle = 'Contrat ' . $loc['reference'];
    include __DIR__ . '/includes/header.php';
    $typeBailLabel = ['semaine'=>'Semaine(s)','mois'=>'Mois','trimestre'=>'Trimestre(s)','annee'=>'Année(s)'];
    ?>
    <div style="margin-bottom:1.3rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;" class="no-print">
        <a href="locations.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Retour</a>
        <div style="display:flex;gap:.6rem;">
            <?php if($loc['statut']==='actif'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="change_statut">
                <input type="hidden" name="loc_id" value="<?php echo $loc['id']; ?>">
                <input type="hidden" name="new_statut" value="termine">
                <button type="submit" class="btn btn-primary" data-confirm="Terminer ce bail ?"><i class="fas fa-check-circle"></i> Terminer le bail</button>
            </form>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="change_statut">
                <input type="hidden" name="loc_id" value="<?php echo $loc['id']; ?>">
                <input type="hidden" name="new_statut" value="resilie">
                <button type="submit" class="btn btn-danger" data-confirm="Résilier ce contrat ?"><i class="fas fa-ban"></i> Résilier</button>
            </form>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Imprimer / PDF</button>
        </div>
    </div>

    <div style="max-width:820px;margin:0 auto;">
    <div style="background:linear-gradient(155deg,#040e07,#020804);border:1px solid rgba(34,197,94,.2);border-top:3px solid #22c55e;border-radius:20px;overflow:hidden;font-family:'Plus Jakarta Sans',sans-serif;">

        <!-- Header contrat -->
        <div style="padding:2rem 2.5rem;background:linear-gradient(135deg,rgba(34,197,94,.05),transparent);border-bottom:1px solid rgba(34,197,94,.15);display:flex;justify-content:space-between;align-items:flex-start;gap:1.5rem;">
            <div style="display:flex;align-items:center;gap:1rem;">
                <div style="width:52px;height:52px;background:linear-gradient(135deg,#22c55e,#15803d);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;flex-shrink:0;"><i class="fas fa-building"></i></div>
                <div>
                    <div style="font-size:1.25rem;font-weight:900;color:#e2f5e9;">Dynasty Immobilier</div>
                    <div style="font-size:.72rem;color:#5f9c75;">Agence de gestion de biens — Los Santos</div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.15em;color:#22c55e;margin-bottom:.3rem;">BON DE LOCATION</div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:1.5rem;font-weight:900;color:#e2f5e9;"><?php echo $loc['reference']; ?></div>
                <div style="font-size:.72rem;color:#5f9c75;margin-top:.22rem;">Émis le <?php echo date('d/m/Y',strtotime($loc['created_at'])); ?></div>
                <div style="margin-top:.5rem;"><span class="badge badge-<?php echo $loc['statut']; ?>"><?php echo ucfirst($loc['statut']); ?></span></div>
            </div>
        </div>

        <!-- Corps contrat -->
        <div style="padding:2rem 2.5rem;">

            <!-- Parties -->
            <div style="margin-bottom:1.75rem;padding-bottom:1.75rem;border-bottom:1px solid rgba(255,255,255,.065);">
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#22c55e;margin-bottom:1rem;"><i class="fas fa-users"></i> Les Parties</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                    <div style="background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.12);border-radius:12px;padding:1.1rem;">
                        <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:#5f9c75;margin-bottom:.6rem;">Bailleur</div>
                        <div style="font-weight:800;color:#e2f5e9;font-size:1rem;">Dynasty Immobilier</div>
                        <div style="font-size:.78rem;color:#5f9c75;margin-top:.25rem;">Agent : <?php echo htmlspecialchars($loc['agent_nom']); ?></div>
                    </div>
                    <div style="background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.12);border-radius:12px;padding:1.1rem;">
                        <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:#5f9c75;margin-bottom:.6rem;">Locataire</div>
                        <div style="font-weight:800;color:#e2f5e9;font-size:1rem;"><?php echo htmlspecialchars($loc['locataire_nom']); ?></div>
                        <?php if($loc['locataire_id_jeu']): ?><div style="font-size:.78rem;color:#5f9c75;">ID Citoyen : <?php echo htmlspecialchars($loc['locataire_id_jeu']); ?></div><?php endif; ?>
                        <?php if($loc['locataire_contact']): ?><div style="font-size:.78rem;color:#5f9c75;">Contact : <?php echo htmlspecialchars($loc['locataire_contact']); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bien loué -->
            <div style="margin-bottom:1.75rem;padding-bottom:1.75rem;border-bottom:1px solid rgba(255,255,255,.065);">
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#22c55e;margin-bottom:1rem;"><i class="fas fa-home"></i> Instance Louée</div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.85rem;">
                    <div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Type</div><div style="font-weight:700;color:#e2f5e9;"><?php echo ucfirst($loc['type_bien']); ?></div></div>
                    <div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Référence</div><div style="font-weight:700;color:#e2f5e9;font-family:'JetBrains Mono',monospace;"><?php echo $loc['bien_ref']; ?></div></div>
                    <?php if($loc['bien_surface']): ?><div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Surface</div><div style="font-weight:700;color:#e2f5e9;"><?php echo $loc['bien_surface']; ?> m²</div></div><?php endif; ?>
                    <div style="grid-column:1/-1"><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Adresse</div><div style="font-weight:700;color:#e2f5e9;"><?php echo htmlspecialchars($loc['bien_titre']); ?> — <?php echo htmlspecialchars($loc['bien_adresse']); ?></div></div>
                    <div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Début du bail</div><div style="font-weight:700;color:#e2f5e9;"><?php echo date('d/m/Y',strtotime($loc['date_debut'])); ?></div></div>
                    <div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Fin du bail</div><div style="font-weight:700;color:#e2f5e9;"><?php echo date('d/m/Y',strtotime($loc['date_fin'])); ?></div></div>
                    <div><div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;margin-bottom:.2rem;">Durée</div><div style="font-weight:700;color:#e2f5e9;"><?php echo $loc['nb_periodes']; ?> <?php echo $typeBailLabel[$loc['type_bail']]??$loc['type_bail']; ?></div></div>
                </div>
            </div>

            <!-- Conditions financières -->
            <div style="margin-bottom:1.75rem;padding-bottom:1.75rem;border-bottom:1px solid rgba(255,255,255,.065);">
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#22c55e;margin-bottom:1rem;"><i class="fas fa-dollar-sign"></i> Conditions Financières</div>
                <div style="background:linear-gradient(135deg,rgba(34,197,94,.06),transparent);border:1.5px solid rgba(34,197,94,.2);border-radius:13px;padding:1.3rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Loyer unitaire HT</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#e2f5e9;">$<?php echo number_format($loc['prix_unitaire'],2); ?></span></div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Taxe (10%)</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#fde68a;">$<?php echo number_format($loc['prix_unitaire']*0.10,2); ?></span></div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Loyer unitaire TTC</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#e2f5e9;">$<?php echo number_format($loc['prix_unitaire']*1.10,2); ?></span></div>
                    <?php if($loc['montant_charges']>0): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Charges</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#e2f5e9;">$<?php echo number_format($loc['montant_charges'],2); ?></span></div>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Durée</span><span style="font-weight:700;color:#e2f5e9;"><?php echo $loc['nb_periodes']; ?> × <?php echo $typeBailLabel[$loc['type_bail']]??$loc['type_bail']; ?></span></div>
                    <?php if($loc['caution_montant']>0): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Caution <?php echo $loc['caution_rendue']?'<span style="color:#22c55e;font-size:.72rem">(rendue)</span>':''; ?></span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#e2f5e9;">$<?php echo number_format($loc['caution_montant'],2); ?></span></div>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.86rem;"><span style="color:#5f9c75;">Commission agent (<?php echo number_format($loc['commission_pct'],1); ?>%)</span><span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:#22c55e;">$<?php echo number_format($loc['commission_amt'],2); ?></span></div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.8rem 0 0;margin-top:.4rem;">
                        <span style="font-weight:800;color:#e2f5e9;font-size:.95rem;">LOYER TOTAL TTC</span>
                        <span style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;color:#22c55e;text-shadow:0 0 18px rgba(34,197,94,.4);">$<?php echo number_format($loc['montant_loyer'],2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Clauses -->
            <div style="margin-bottom:1.75rem;padding-bottom:1.75rem;border-bottom:1px solid rgba(255,255,255,.065);">
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#22c55e;margin-bottom:.85rem;"><i class="fas fa-gavel"></i> Clauses & Conditions</div>
                <div style="font-size:.8rem;color:#5f9c75;line-height:1.75;">
                    <p>1. Le locataire s'engage à restituer le bien dans l'état initial à l'expiration du bail.</p>
                    <p>2. Tout retard de paiement entraîne des pénalités selon les dispositions en vigueur.</p>
                    <p>3. La caution est restituée dans un délai de 30 jours après l'état des lieux de sortie.</p>
                </div>
                <?php if($loc['notes']): ?>
                <div style="margin-top:.85rem;padding:.85rem 1rem;background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.12);border-radius:8px;font-size:.82rem;color:#5f9c75;"><?php echo nl2br(htmlspecialchars($loc['notes'])); ?></div>
                <?php endif; ?>
            </div>

            <!-- Signatures -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;margin-top:1.75rem;padding-top:1.5rem;border-top:1px dashed rgba(34,197,94,.2);">
                <div style="text-align:center;">
                    <div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3rem;">Signature du Bailleur<br>Dynasty Immobilier</div>
                    <div style="border-top:1px solid rgba(34,197,94,.2);padding-top:.5rem;font-size:.72rem;color:#5f9c75;">Agent : <?php echo htmlspecialchars($loc['agent_nom']); ?></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:.65rem;color:#5f9c75;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3rem;">Signature du Locataire</div>
                    <div style="border-top:1px solid rgba(34,197,94,.2);padding-top:.5rem;font-size:.72rem;color:#5f9c75;"><?php echo htmlspecialchars($loc['locataire_nom']); ?></div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="background:rgba(34,197,94,.03);border-top:1px solid rgba(34,197,94,.12);padding:1rem 2.5rem;font-size:.66rem;color:#5f9c75;text-align:center;line-height:1.75;">
            Dynasty Immobilier · Agence de gestion immobilière · Los Santos, San Andreas<br>
            Ce document constitue un contrat de bail. Toute reproduction ou modification non autorisée est interdite.<br>
            Référence interne : <?php echo $loc['reference']; ?> · Généré le <?php echo date('d/m/Y H:i'); ?>
        </div>
    </div>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; exit; ?>
<?php }

/* ════ TRAITEMENTS POST ════ */
$message = ''; $msgType = '';

function generateBL($db) {
    // Pas de beginTransaction ici — on est déjà dans une transaction appelante
    $r    = $db->query("SELECT * FROM dynasty_sequences WHERE type='BL' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    $year = (int)date('Y');
    $next = ($r && (int)$r['annee'] === $year) ? (int)$r['dernier'] + 1 : 1;
    $db->prepare("INSERT INTO dynasty_sequences(type,annee,dernier) VALUES('BL',?,?) ON DUPLICATE KEY UPDATE annee=VALUES(annee),dernier=VALUES(dernier)")
       ->execute([$year, $next]);
    return 'BL-'.$year.'-'.str_pad($next, 5, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Créer location / générer BL ── */
    if ($action === 'create_location') {
        try {
            $db->beginTransaction();
            $ref = generateBL($db);

            $bs = $db->prepare("SELECT * FROM dynasty_biens WHERE id=?");
            $bs->execute([(int)$_POST['bien_id']]);
            $bien = $bs->fetch(PDO::FETCH_ASSOC);
            if (!$bien) throw new Exception("Instance introuvable.");

            $type_bail      = $_POST['type_bail'] ?? 'semaine';
            $nb             = max(1,(int)($_POST['nb_periodes']??1));
            $prix_unit_ht   = (float)($_POST['prix_unitaire'] ?? $bien['prix_location_semaine'] ?? 0);
            $taxe           = $prix_unit_ht * 0.10;
            $prix_unit_ttc  = $prix_unit_ht + $taxe;
            $charges        = (float)($_POST['montant_charges']??0);
            $charges_inc    = isset($_POST['charges_incluses']) ? 1 : 0;
            $montant_loyer  = round(($prix_unit_ttc * $nb) + ($charges_inc ? 0 : $charges * $nb), 2);
            $caution        = (float)($_POST['caution_montant']??0);
            $comm_pct       = (float)($_POST['commission_pct'] ?? $bien['commission_location_pct'] ?? 10);
            $comm_amt       = round($montant_loyer * $comm_pct / 100, 2);
            $date_debut     = $_POST['date_debut'];
            $date_fin       = $_POST['date_fin'];

            $db->prepare("
                INSERT INTO dynasty_locations
                    (reference,bien_id,agent_id,locataire_nom,locataire_id_jeu,locataire_contact,
                     type_bail,date_debut,date_fin,nb_periodes,prix_unitaire,charges_incluses,
                     montant_charges,montant_loyer,caution_montant,commission_pct,commission_amt,statut,notes)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'actif',?)
            ")->execute([
                $ref,(int)$_POST['bien_id'],(int)$_POST['agent_id'],
                sanitize($_POST['locataire_nom']),
                sanitize($_POST['locataire_id_jeu']??''),
                sanitize($_POST['locataire_contact']??''),
                $type_bail,$date_debut,$date_fin,$nb,$prix_unit_ht,
                $charges_inc,$charges,$montant_loyer,$caution,
                $comm_pct,$comm_amt,
                sanitize($_POST['notes']??''),
            ]);
            $loc_id = $db->lastInsertId();

            // Facture
            $db->prepare("
                INSERT INTO company_invoices (faction,employee_id,customer_name,amount,final_amount,description,status)
                VALUES(?,(SELECT id FROM users WHERE username=? LIMIT 1),?,?,?,?,'paid')
            ")->execute([
                $companyFaction,
                sanitize($_POST['agent_username']??$_SESSION['user_username']),
                sanitize($_POST['locataire_nom']),
                $montant_loyer, $montant_loyer,
                "Bon de Location $ref — ".$bien['titre'],
            ]);
            $inv_id = $db->lastInsertId();

            $db->prepare("UPDATE dynasty_locations SET invoice_id=? WHERE id=?")->execute([$inv_id,$loc_id]);

            // Tracking CA individuel — robuste si la ligne company_employees n'existe pas encore
            try {
                $upd = $db->prepare("UPDATE company_employees SET individual_ca = individual_ca + ? WHERE user_id=? AND faction=?");
                $upd->execute([$comm_amt, (int)$_POST['agent_id'], $companyFaction]);
                if ($upd->rowCount() === 0) {
                    $agentRank = '';
                    $arq = $db->prepare("SELECT COALESCE(NULLIF(faction_rank,''),'Agent') FROM users WHERE id=? LIMIT 1");
                    $arq->execute([(int)$_POST['agent_id']]);
                    $agentRank = (string)($arq->fetchColumn() ?: 'Agent');
                    $db->prepare("INSERT INTO company_employees (user_id, faction, `rank`, salary_percentage, individual_ca, hired_date) VALUES (?, ?, ?, 70.00, ?, CURDATE())")
                       ->execute([(int)$_POST['agent_id'], $companyFaction, $agentRank, $comm_amt]);
                }
            } catch (Exception $eCE) { /* silencieux */ }

            $db->commit();
            $message = "✅ Location $ref créée. Facture générée.";
            $msgType = 'success';
        } catch(Exception $e) {
            $db->rollBack();
            $message = '❌ '.$e->getMessage(); $msgType = 'error';
        }
    }

    /* ── Changer statut location ── */
    if ($action === 'change_statut') {
        try {
            $lid = (int)$_POST['loc_id'];
            $ns  = $_POST['new_statut'];
            $db->prepare("UPDATE dynasty_locations SET statut=? WHERE id=?")->execute([$ns,$lid]);

            // Instance réutilisable à l'infini — pas de mise à jour statut

            if ($ns === 'termine' && isset($_POST['rendre_caution'])) {
                $db->prepare("UPDATE dynasty_locations SET caution_rendue=1 WHERE id=?")->execute([$lid]);
            }

            $message = "✅ Statut mis à jour."; $msgType = 'success';
        } catch(Exception $e) {
            $message = '❌ '.$e->getMessage(); $msgType = 'error';
        }
    }
}

/* ════ DONNÉES ════ */
$pageTitle    = 'Locations';
$pageSubtitle = 'Bons de location & gestion locative';

$fStatut = $_GET['statut'] ?? 'all';
$fAgent  = $_GET['agent']  ?? 'all';
$search  = trim($_GET['q'] ?? '');

$where  = ['1=1']; $params = [];
if ($fStatut !== 'all') { $where[] = 'l.statut=?';   $params[] = $fStatut; }
if ($fAgent  !== 'all') { $where[] = 'l.agent_id=?'; $params[] = $fAgent; }
if ($search) {
    $where[]  = '(l.reference LIKE ? OR l.locataire_nom LIKE ? OR b.titre LIKE ?)';
    $params   = array_merge($params,["%$search%","%$search%","%$search%"]);
}

$locations = $db->prepare("
    SELECT l.*, b.titre bien_titre, b.quartier bien_quartier, b.reference bien_ref,
           u.username agent_nom
    FROM dynasty_locations l
    JOIN dynasty_biens b ON l.bien_id = b.id
    JOIN users u ON l.agent_id = u.id
    WHERE ".implode(' AND ',$where)."
    ORDER BY l.created_at DESC LIMIT 200
");
$locations->execute($params);
$locations = $locations->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $db->query("
    SELECT
        SUM(statut='actif')    nb_actif,
        SUM(statut='termine')  nb_termine,
        SUM(statut='resilie')  nb_resilie,
        SUM(statut='impaye')   nb_impaye,
        SUM(CASE WHEN statut='actif' THEN montant_loyer ELSE 0 END) ca_actif,
        SUM(commission_amt) comm_total
    FROM dynasty_locations
")->fetch(PDO::FETCH_ASSOC);

// Toutes les instances
$biens_loc = $db->query("
    SELECT id,titre,quartier,reference,prix_location_semaine,prix_location_mois,
           charges_mois,caution_mois,commission_location_pct
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

// Profils civils (locataires potentiels) — pour le picker dynamique
$civilProfiles = $db->query("
    SELECT id, firstname, lastname, phone, avatar
    FROM profiles
    WHERE TRIM(COALESCE(firstname,''))<>'' OR TRIM(COALESCE(lastname,''))<>''
    ORDER BY lastname, firstname
    LIMIT 2000
")->fetchAll(PDO::FETCH_ASSOC);

// Pré-sélection instance
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
        <div class="page-header-title"><i class="fas fa-key"></i> Locations</div>
        <div class="page-header-sub"><?php echo count($locations); ?> contrat<?php echo count($locations)>1?'s':''; ?> sur la sélection courante · Génération automatique des bons de location</div>
    </div>
    <div class="page-header-actions">
        <button onclick="Company.openModal('createLocModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle location</button>
    </div>
</div>

<!-- Stats -->
<div class="dy-stats">
    <div class="dy-stat">
        <div class="dy-stat-ico"><i class="fas fa-key"></i></div>
        <div><div class="dy-stat-val"><?php echo $stats['nb_actif']??0; ?></div><div class="dy-stat-lbl">Baux actifs</div></div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico ico-slate"><i class="fas fa-check-circle"></i></div>
        <div><div class="dy-stat-val"><?php echo $stats['nb_termine']??0; ?></div><div class="dy-stat-lbl">Terminés</div></div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico ico-red"><i class="fas fa-ban"></i></div>
        <div><div class="dy-stat-val"><?php echo $stats['nb_resilie']??0; ?></div><div class="dy-stat-lbl">Résiliés</div></div>
    </div>
    <div class="dy-stat">
        <div class="dy-stat-ico"><i class="fas fa-dollar-sign"></i></div>
        <div>
            <div class="dy-stat-val" style="font-size:1.15rem;">$<?php echo number_format($stats['ca_actif']??0,0); ?></div>
            <div class="dy-stat-lbl">CA actif</div>
            <div class="dy-stat-sub">Commissions $<?php echo number_format($stats['comm_total']??0,0); ?></div>
        </div>
    </div>
</div>

<!-- Filtres -->
<form method="GET">
<div class="filters-bar">
    <div class="filter-group" style="flex:2;">
        <label class="filter-lbl">Recherche</label>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-input" placeholder="Référence, locataire, instance...">
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Statut</label>
        <select name="statut" class="form-select">
            <option value="all">Tous</option>
            <option value="actif"   <?php echo $fStatut==='actif'?'selected':''; ?>>Actif</option>
            <option value="termine" <?php echo $fStatut==='termine'?'selected':''; ?>>Terminé</option>
            <option value="resilie" <?php echo $fStatut==='resilie'?'selected':''; ?>>Résilié</option>
            <option value="impaye"  <?php echo $fStatut==='impaye'?'selected':''; ?>>Impayé</option>
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
    <?php if($search||$fStatut!=='all'||$fAgent!=='all'): ?><a href="locations.php" class="btn btn-secondary btn-sm" style="align-self:flex-end;"><i class="fas fa-times"></i></a><?php endif; ?>
</div>
</form>

<!-- Table locations -->
<?php if(empty($locations)): ?>
<div class="card"><div class="empty-state"><i class="fas fa-key"></i><p>Aucune location trouvée.</p><button onclick="Company.openModal('createLocModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Créer la première</button></div></div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden;">
<table class="dy-table">
    <thead><tr>
        <th>Référence</th><th>Instance</th><th>Locataire</th>
        <th>Type bail</th><th>Période</th><th>Loyer TTC</th>
        <th>Date début</th><th>Agent</th><th>Statut</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php
    $bailLabel = ['semaine'=>'Sem.','mois'=>'Mois','trimestre'=>'Trim.','annee'=>'An'];
    foreach($locations as $l): ?>
    <tr>
        <td><a href="locations.php?view=<?php echo $l['id']; ?>" style="font-family:'JetBrains Mono',monospace;font-size:.78rem;color:#22c55e;font-weight:700;text-decoration:none;"><?php echo $l['reference']; ?></a></td>
        <td><div style="font-weight:600;font-size:.84rem;"><?php echo htmlspecialchars($l['bien_titre']); ?></div><div style="font-size:.7rem;color:var(--tx2);"><?php echo htmlspecialchars($l['bien_ref']); ?> · <?php echo htmlspecialchars($l['bien_quartier']??''); ?></div></td>
        <td>
            <div style="font-weight:600;font-size:.84rem;color:var(--tx);"><?php echo htmlspecialchars($l['locataire_nom']); ?></div>
            <?php if(!empty($l['locataire_contact'])): ?><div style="font-size:.7rem;color:var(--tx-3);"><i class="fas fa-phone" style="font-size:.6rem;color:var(--dy);margin-right:.2rem"></i><?php echo htmlspecialchars($l['locataire_contact']); ?></div><?php endif; ?>
        </td>
        <td style="font-size:.82rem;"><?php echo $bailLabel[$l['type_bail']]??$l['type_bail']; ?></td>
        <td style="font-size:.82rem;color:var(--tx2);"><?php echo $l['nb_periodes']; ?> × <?php echo $bailLabel[$l['type_bail']]??''; ?></td>
        <td style="font-weight:700;color:#22c55e;font-family:'Orbitron',monospace;font-size:.82rem;">$<?php echo number_format($l['montant_loyer'],0); ?></td>
        <td style="font-size:.78rem;"><?php echo date('d/m/Y',strtotime($l['date_debut'])); ?></td>
        <td style="font-size:.82rem;"><?php echo htmlspecialchars($l['agent_nom']); ?></td>
        <td><span class="badge badge-<?php echo $l['statut']; ?>"><?php echo ucfirst($l['statut']); ?></span></td>
        <td>
            <div style="display:flex;gap:.35rem;flex-wrap:nowrap;">
                <a href="locations.php?view=<?php echo $l['id']; ?>" class="btn btn-secondary btn-sm btn-icon" title="Voir BL"><i class="fas fa-eye"></i></a>
                <?php if($l['statut']==='actif'): ?>
                <button onclick="changeStatutLoc(<?php echo $l['id']; ?>,'termine')" class="btn btn-primary btn-sm btn-icon" title="Terminer" style="background:linear-gradient(135deg,#22c55e,#15803d);"><i class="fas fa-check"></i></button>
                <button onclick="changeStatutLoc(<?php echo $l['id']; ?>,'resilie')" class="btn btn-danger btn-sm btn-icon" title="Résilier"><i class="fas fa-ban"></i></button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<!-- ════ MODAL CRÉER LOCATION ════ -->
<div id="createLocModal" class="modal-overlay hidden">
<div class="modal modal-lg">
    <div class="modal-header"><h2><i class="fas fa-key" style="color:#22c55e !important;"></i> Nouvelle Location — BL Automatique</h2><button class="modal-close" onclick="Company.closeModal('createLocModal')">×</button></div>
    <form method="POST"><div class="modal-body"><input type="hidden" name="action" value="create_location">
        <div style="background:rgba(34,197,94,.07);border:1px solid rgba(34,197,94,.2);border-radius:10px;padding:.85rem 1rem;margin-bottom:1.2rem;font-size:.82rem;color:#22c55e;">
            <i class="fas fa-magic"></i> Un <strong>Bon de Location</strong> sera généré automatiquement. Les prix TTC incluent la taxe de 10%.
        </div>

        <div class="sec-title"><i class="fas fa-home"></i> Instance à louer</div>
        <div class="form-grid">
            <div class="form-group form-full">
                <label class="form-label">Instance *</label>
                <select name="bien_id" id="loc_bien" class="form-select" required onchange="loadInstanceInfo(this.value)">
                    <option value="">— Sélectionner une instance —</option>
                    <?php foreach($biens_loc as $b): ?>
                    <option value="<?php echo $b['id']; ?>"
                        data-ps="<?php echo $b['prix_location_semaine']??0; ?>"
                        data-pm="<?php echo $b['prix_location_mois']??0; ?>"
                        data-comm="<?php echo $b['commission_location_pct']??10; ?>"
                        data-caution="<?php echo $b['caution_mois']??1; ?>"
                        <?php echo ($preBien&&$preBien['id']==$b['id'])?'selected':''; ?>>
                        <?php echo $b['reference']; ?> — <?php echo htmlspecialchars($b['titre']); ?> (<?php echo htmlspecialchars($b['quartier']??''); ?>)
                        <?php if($b['prix_location_semaine']): ?> — $<?php echo number_format($b['prix_location_semaine'],0); ?>/sem.<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="instanceInfoBox" style="display:none;" class="form-full">
                <div style="background:rgba(34,197,94,.05);border:1px solid rgba(34,197,94,.15);border-radius:10px;padding:.9rem 1.1rem;font-size:.8rem;display:flex;gap:1.5rem;flex-wrap:wrap;">
                    <span><i class="fas fa-calendar-week" style="color:#22c55e;"></i> /semaine HT : <strong id="li_ps" style="color:#22c55e;">—</strong></span>
                    <span><i class="fas fa-calendar-alt" style="color:#22c55e;"></i> /mois HT : <strong id="li_pm" style="color:#22c55e;">—</strong></span>
                    <span><i class="fas fa-percentage" style="color:#22c55e;"></i> Comm. std : <strong id="li_comm">—</strong>%</span>
                </div>
            </div>
        </div>

        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-user"></i> Locataire</div>
        <div class="form-grid">
            <div class="form-group form-full">
                <label class="form-label form-label-req">Locataire</label>
                <div class="picker" data-picker
                     id="locataire_picker"
                     data-name="locataire_nom"
                     data-required
                     data-placeholder="Rechercher un citoyen par nom, prénom..."></div>
                <div class="form-hint"><i class="fas fa-info-circle" style="opacity:.6;margin-right:.2rem"></i> La recherche puise dans le registre civil (profiles) — le téléphone se pré-remplit automatiquement.</div>
            </div>
            <div class="form-group form-full">
                <label class="form-label">Contact (téléphone / email)</label>
                <input type="text" name="locataire_contact" class="form-input" id="locataire_contact_input" placeholder="Auto-rempli après sélection (modifiable)">
            </div>
        </div>

        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-dollar-sign"></i> Conditions Financières</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Type de bail *</label>
                <select name="type_bail" id="loc_type_bail" class="form-select" onchange="calcLoc()">
                    <option value="semaine">Semaine</option>
                    <option value="mois">Mois</option>
                    <option value="trimestre">Trimestre</option>
                    <option value="annee">Année</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Nombre de périodes *</label>
                <input type="number" name="nb_periodes" id="loc_nb" class="form-input" min="1" value="1" required oninput="calcLoc()">
            </div>
            <div class="form-group">
                <label class="form-label">Prix unitaire HT ($) *</label>
                <input type="number" name="prix_unitaire" id="loc_prix" class="form-input" step="0.01" min="0" required oninput="calcLoc()">
            </div>
            <div class="form-group">
                <label class="form-label">Caution ($)</label>
                <input type="number" name="caution_montant" id="loc_caution" class="form-input" step="0.01" min="0" value="0">
            </div>
            <div class="form-group">
                <label class="form-label">Charges ($)</label>
                <input type="number" name="montant_charges" id="loc_charges" class="form-input" step="0.01" min="0" value="0" oninput="calcLoc()">
            </div>
            <div class="form-group">
                <label class="form-label">Commission agent (%)</label>
                <input type="number" name="commission_pct" id="loc_comm" class="form-input" step="0.01" value="10" oninput="calcLoc()">
            </div>
            <div class="form-group">
                <label class="form-label">Début du bail *</label>
                <input type="date" name="date_debut" id="loc_debut" class="form-input" value="<?php echo date('Y-m-d'); ?>" required onchange="calcLoc()">
            </div>
            <div class="form-group">
                <label class="form-label">Fin du bail *</label>
                <input type="date" name="date_fin" id="loc_fin" class="form-input" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label form-label-req">Agent</label>
                <select name="agent_id" id="loc_agent" class="form-select" required>
                    <?php foreach($agents as $a):
                        $aDisp = mdt_rp_display_name($a['profile_firstname'] ?? null, $a['profile_lastname'] ?? null, (string)$a['username']);
                    ?><option value="<?php echo $a['id']; ?>" <?php echo $a['username']===$_SESSION['user_username']?'selected':''; ?>><?php echo htmlspecialchars($aDisp); ?> · <?php echo htmlspecialchars($a['rank']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" placeholder="Conditions particulières, état du bien..."></textarea></div>
        </div>
        <input type="hidden" name="agent_username" value="<?php echo htmlspecialchars($_SESSION['user_username']); ?>">

        <!-- Résumé financier -->
        <div id="resumeLoc" style="display:none;margin-top:1rem;background:linear-gradient(135deg,rgba(34,197,94,.06),transparent);border:1.5px solid rgba(34,197,94,.2);border-radius:13px;padding:1.2rem;">
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#22c55e;margin-bottom:.85rem;">Récapitulatif financier</div>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.65rem;text-align:center;">
                <div style="background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.1);border-radius:9px;padding:.8rem;"><div style="font-size:.58rem;color:var(--tx2);text-transform:uppercase;margin-bottom:.22rem;">Loyer HT</div><div id="rl_ht" style="font-family:'Orbitron',monospace;font-weight:900;color:var(--tx2);font-size:.88rem;">—</div></div>
                <div style="background:rgba(250,204,21,.04);border:1px solid rgba(250,204,21,.1);border-radius:9px;padding:.8rem;"><div style="font-size:.58rem;color:var(--tx2);text-transform:uppercase;margin-bottom:.22rem;">Taxe 10%</div><div id="rl_taxe" style="font-family:'Orbitron',monospace;font-weight:900;color:#fde68a;font-size:.88rem;">—</div></div>
                <div style="background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.1);border-radius:9px;padding:.8rem;"><div style="font-size:.58rem;color:var(--tx2);text-transform:uppercase;margin-bottom:.22rem;">Charges</div><div id="rl_charges" style="font-family:'Orbitron',monospace;font-weight:900;color:#22c55e;font-size:.88rem;">—</div></div>
                <div style="background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.1);border-radius:9px;padding:.8rem;"><div style="font-size:.58rem;color:var(--tx2);text-transform:uppercase;margin-bottom:.22rem;">Commission</div><div id="rl_comm" style="font-family:'Orbitron',monospace;font-weight:900;color:#22c55e;font-size:.88rem;">—</div></div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.85rem;padding-top:.75rem;border-top:1px solid rgba(34,197,94,.15);">
                <div></div>
                <div style="text-align:right;">
                    <div style="font-size:.62rem;color:var(--tx2);margin-bottom:.15rem;">LOYER TOTAL TTC</div>
                    <div id="rl_total" style="font-family:'Orbitron',monospace;font-weight:900;color:#22c55e;font-size:1.4rem;text-shadow:0 0 14px rgba(34,197,94,.35);">—</div>
                </div>
            </div>
        </div>

    </div><div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createLocModal')">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-magic"></i> Créer & Générer le BL</button>
    </div></form>
</div></div>

<!-- Form changement statut -->
<form id="locStatutForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="change_statut">
    <input type="hidden" name="loc_id"     id="lsf_id">
    <input type="hidden" name="new_statut" id="lsf_statut">
</form>

<script>
const BIENS_LOC = <?php echo json_encode(array_column($biens_loc,null,'id')); ?>;
const CIVIL_PROFILES = <?php echo json_encode($civilProfiles, JSON_UNESCAPED_UNICODE); ?>;

function initLocataireePicker() {
    const picker = document.getElementById('locataire_picker');
    if (!picker) return;
    if (!picker._picker) { setTimeout(initLocataireePicker, 30); return; }
    picker._picker.setData(CIVIL_PROFILES);
    picker.addEventListener('picker:change', e => {
        const item = e.detail || {};
        const contactInput = document.getElementById('locataire_contact_input');
        if (contactInput && item.phone) contactInput.value = item.phone;
    });
}
document.addEventListener('DOMContentLoaded', () => setTimeout(initLocataireePicker, 0));

function loadInstanceInfo(id) {
    const b = BIENS_LOC[id];
    if (!b) { document.getElementById('instanceInfoBox').style.display='none'; return; }
    document.getElementById('li_ps').textContent   = b.prix_location_semaine ? '$'+parseFloat(b.prix_location_semaine).toLocaleString('fr-FR',{minimumFractionDigits:0}) : '—';
    document.getElementById('li_pm').textContent   = b.prix_location_mois    ? '$'+parseFloat(b.prix_location_mois).toLocaleString('fr-FR',{minimumFractionDigits:0})    : '—';
    document.getElementById('li_comm').textContent = b.commission_location_pct || 10;
    // Pré-remplir prix selon type_bail
    const tb  = document.getElementById('loc_type_bail').value;
    const prix = (tb === 'semaine') ? (b.prix_location_semaine||0) : (b.prix_location_mois||0);
    document.getElementById('loc_prix').value  = prix;
    document.getElementById('loc_comm').value  = b.commission_location_pct || 10;
    document.getElementById('instanceInfoBox').style.display = '';
    calcLoc();
}

function calcLoc() {
    const ht      = parseFloat(document.getElementById('loc_prix').value)     || 0;
    const nb      = parseInt(document.getElementById('loc_nb').value)          || 1;
    const charges = parseFloat(document.getElementById('loc_charges').value)   || 0;
    const comm    = parseFloat(document.getElementById('loc_comm').value)      || 0;
    const taxe    = ht * 0.10;
    const ttcUnit = ht + taxe;
    const total   = ttcUnit * nb + charges * nb;
    const commAmt = total * comm / 100;
    const fmt     = v => '$'+v.toLocaleString('fr-FR',{minimumFractionDigits:0,maximumFractionDigits:0});

    document.getElementById('rl_ht').textContent      = fmt(ht * nb);
    document.getElementById('rl_taxe').textContent    = fmt(taxe * nb);
    document.getElementById('rl_charges').textContent = charges>0 ? fmt(charges*nb) : '—';
    document.getElementById('rl_comm').textContent    = fmt(commAmt);
    document.getElementById('rl_total').textContent   = fmt(total);
    document.getElementById('resumeLoc').style.display = '';
}

// Mettre à jour le prix quand on change le type de bail
document.getElementById('loc_type_bail').addEventListener('change', function() {
    const id = document.getElementById('loc_bien').value;
    const b  = BIENS_LOC[id]; if (!b) return;
    const prix = (this.value === 'semaine') ? (b.prix_location_semaine||0) : (b.prix_location_mois||0);
    document.getElementById('loc_prix').value = prix;
    calcLoc();
});

function changeStatutLoc(id, statut) {
    const labels = {
        termine: 'Terminer ce bail ?',
        resilie: 'Résilier ce contrat ? Action irréversible.',
        impaye:  'Marquer ce bail comme impayé ?'
    };
    (async () => {
        const C = window.Company || window.Pearl;
        if (!await C.confirm({ message: labels[statut] || 'Confirmer ?' })) return;
        document.getElementById('lsf_id').value     = id;
        document.getElementById('lsf_statut').value = statut;
        document.getElementById('locStatutForm').submit();
    })();
}

<?php if($preBien): ?>
document.getElementById('loc_bien').value = '<?php echo $preBien['id']; ?>';
loadInstanceInfo('<?php echo $preBien['id']; ?>');
<?php endif; ?>

if(new URLSearchParams(location.search).get('open')==='new') Company.openModal('createLocModal');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>