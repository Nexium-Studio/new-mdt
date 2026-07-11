<?php
/**
 * pages/companies/bennys/fourriere.php
 * Système de fourrière — Tuner's Shop (BENNYS)
 *
 * ── TABLES SQL REQUISES ────────────────────────────────────────────────────
 *
 * CREATE TABLE `bennys_fourriere` (
 *   `id`              int(11)          NOT NULL AUTO_INCREMENT,
 *   `profile_id`      int(11)          DEFAULT NULL COMMENT 'FK → profiles.id',
 *   `plaque`          varchar(20)      NOT NULL,
 *   `modele`          varchar(100)     NOT NULL,
 *   `heure_entree`    datetime         NOT NULL,
 *   `statut`          enum('en_fourriere','sorti') NOT NULL DEFAULT 'en_fourriere',
 *   `heure_sortie`    datetime         DEFAULT NULL,
 *   `montant_facture` decimal(10,2)    DEFAULT NULL,
 *   `sorti_par`       int(11)          DEFAULT NULL COMMENT 'FK → users.id',
 *   `added_by`        int(11)          DEFAULT NULL COMMENT 'FK → users.id',
 *   `created_at`      timestamp        NOT NULL DEFAULT current_timestamp(),
 *   PRIMARY KEY (`id`),
 *   KEY `idx_statut` (`statut`),
 *   KEY `idx_plaque` (`plaque`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * CREATE TABLE `bennys_fourriere_tarif` (
 *   `id`               int(11)         NOT NULL AUTO_INCREMENT,
 *   `prix_par_minute`  decimal(10,2)   NOT NULL DEFAULT 10.00,
 *   `prix_par_jour`    decimal(10,2)   NOT NULL DEFAULT 14400.00 COMMENT 'Forfait / jour si durée ≥ 24 h',
 *   `updated_by`       int(11)         DEFAULT NULL COMMENT 'FK → users.id',
 *   `updated_at`       timestamp       NOT NULL DEFAULT current_timestamp()
 *                                      ON UPDATE current_timestamp(),
 *   PRIMARY KEY (`id`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * INSERT INTO `bennys_fourriere_tarif` (`prix_par_minute`) VALUES (10.00);
 * ──────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/fourriere_pricing.php';
requireLogin();

$companyName    = '🔧 Bennys';
$companyColor   = '#f59e0b';
$companyFaction = 'BENNYS';

if ($_SESSION['user_faction'] !== $companyFaction &&
    !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Fourrière';
$db        = Database::getInstance()->getConnection();
$uid       = (int)($_SESSION['user_id'] ?? 0);
$msg = ''; $msgType = '';

// ── Permissions via faction_grades ────────────────────────────────────────
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'fourriere.view')) faction_acl_deny();
$canManage      = faction_acl_can($db, $uid, $companyFaction, 'fourriere.add');
$canEdit        = faction_acl_can($db, $uid, $companyFaction, 'fourriere.edit');
$canDelete      = faction_acl_can($db, $uid, $companyFaction, 'fourriere.delete');
$canManageTarif = faction_acl_can($db, $uid, $companyFaction, 'fourriere.edit_tarif');
$canOperate     = $canManage || $canEdit;

// Tous les employés peuvent ajouter/sortir un véhicule
$canOperate = true;

// ── Tarif actuel ──────────────────────────────────────────────────────────
$tarif = 10.00;
$prixParJour = 14400.00;
try {
    $t = $db->query("SELECT prix_par_minute, prix_par_jour FROM bennys_fourriere_tarif ORDER BY id DESC LIMIT 1");
    $row = $t->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $tarif = (float) $row['prix_par_minute'];
        $prixParJour = isset($row['prix_par_jour']) ? (float) $row['prix_par_jour'] : round(1440 * $tarif, 2);
    }
} catch (Exception $e) {
    try {
        $t = $db->query("SELECT prix_par_minute FROM bennys_fourriere_tarif ORDER BY id DESC LIMIT 1");
        $row = $t->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tarif = (float) $row['prix_par_minute'];
            $prixParJour = round(1440 * $tarif, 2);
        }
    } catch (Exception $e2) {
    }
}

// ══ POST ACTIONS ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Ajouter un véhicule ───────────────────────────────────────────────
    if ($action === 'add' && $canOperate) {
        try {
            $profileId  = intval($_POST['profile_id']) ?: null;
            $plaque     = strtoupper(trim($_POST['plaque']));
            $modele     = trim($_POST['modele']);
            $heureEntree = trim($_POST['heure_entree']);
            if (!$plaque || !$modele || !$heureEntree) throw new Exception('Champs obligatoires manquants.');
            // Véhicule déjà en fourrière avec cette plaque ?
            $chk = $db->prepare("SELECT id FROM bennys_fourriere WHERE plaque=? AND statut='en_fourriere' LIMIT 1");
            $chk->execute([$plaque]);
            if ($chk->fetch()) throw new Exception("La plaque $plaque est déjà en fourrière.");
            $db->prepare("
                INSERT INTO bennys_fourriere (profile_id, plaque, modele, heure_entree, statut, added_by)
                VALUES (?,?,?,?,?,?)
            ")->execute([$profileId, $plaque, $modele, $heureEntree, 'en_fourriere', $uid]);
            $msg = "Véhicule $plaque ajouté en fourrière."; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ── Sortir un véhicule ────────────────────────────────────────────────
    if ($action === 'release' && $canOperate) {
        try {
            $vid = intval($_POST['vehicle_id']);
            $heureSortie = date('Y-m-d H:i:s');
            // Récupérer le véhicule
            $veh = $db->prepare("SELECT * FROM bennys_fourriere WHERE id=? AND statut='en_fourriere' LIMIT 1");
            $veh->execute([$vid]); $veh = $veh->fetch();
            if (!$veh) throw new Exception('Véhicule introuvable ou déjà sorti.');
            $entree = new DateTime($veh['heure_entree']);
            $sortie = new DateTime($heureSortie);
            $montant = fourriere_compute_amount($tarif, $prixParJour, $entree->getTimestamp(), $sortie->getTimestamp());
            $db->prepare("
                UPDATE bennys_fourriere
                SET statut='sorti', heure_sortie=?, montant_facture=?, sorti_par=?
                WHERE id=?
            ")->execute([$heureSortie, $montant, $uid, $vid]);
            $msg = "Véhicule {$veh['plaque']} sorti — Facture : \$$montant."; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ── Modifier le tarif ─────────────────────────────────────────────────
    if ($action === 'update_tarif' && $canManageTarif) {
        try {
            $newTarif = floatval($_POST['prix_par_minute']);
            $newJour  = floatval($_POST['prix_par_jour'] ?? 0);
            if ($newTarif <= 0) {
                throw new Exception('Le tarif à la minute doit être supérieur à 0.');
            }
            if ($newJour <= 0) {
                throw new Exception('Le forfait journalier doit être supérieur à 0.');
            }
            $db->exec("DELETE FROM bennys_fourriere_tarif WHERE id > 1");
            $chkT = $db->query("SELECT COUNT(*) FROM bennys_fourriere_tarif")->fetchColumn();
            if ($chkT) {
                try {
                    $db->prepare("UPDATE bennys_fourriere_tarif SET prix_par_minute=?, prix_par_jour=?, updated_by=? WHERE 1")
                       ->execute([$newTarif, $newJour, $uid]);
                } catch (Exception $eCol) {
                    $db->prepare("UPDATE bennys_fourriere_tarif SET prix_par_minute=?, updated_by=? WHERE 1")
                       ->execute([$newTarif, $uid]);
                }
            } else {
                try {
                    $db->prepare("INSERT INTO bennys_fourriere_tarif (prix_par_minute, prix_par_jour, updated_by) VALUES (?,?,?)")
                       ->execute([$newTarif, $newJour, $uid]);
                } catch (Exception $eCol) {
                    $db->prepare("INSERT INTO bennys_fourriere_tarif (prix_par_minute, updated_by) VALUES (?,?)")
                       ->execute([$newTarif, $uid]);
                }
            }
            $tarif = $newTarif;
            $prixParJour = $newJour;
            $msg = "Tarifs mis à jour : \${$newTarif}/min · \${$newJour}/jour (≥24h).";
            $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────
// Véhicules actuellement en fourrière
$enFourriere = [];
try {
    $q = $db->query("
        SELECT f.*,
               CONCAT(p.firstname,' ',p.lastname) AS civil_name,
               p.avatar AS civil_avatar,
               u.username AS added_by_name
        FROM bennys_fourriere f
        LEFT JOIN profiles p ON f.profile_id = p.id
        LEFT JOIN users u ON f.added_by = u.id
        WHERE f.statut = 'en_fourriere'
        ORDER BY f.heure_entree ASC
    ");
    $enFourriere = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Historique (10 derniers sortis)
$historique = [];
try {
    $q = $db->query("
        SELECT f.*,
               CONCAT(p.firstname,' ',p.lastname) AS civil_name,
               us.username AS sorti_par_name
        FROM bennys_fourriere f
        LEFT JOIN profiles p ON f.profile_id = p.id
        LEFT JOIN users us ON f.sorti_par = us.id
        WHERE f.statut = 'sorti'
        ORDER BY f.heure_sortie DESC
        LIMIT 15
    ");
    $historique = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Stats globales
$stats = ['en_cours' => count($enFourriere), 'sortis_today' => 0, 'ca_today' => 0, 'total' => 0];
try {
    $s = $db->query("
        SELECT
            COUNT(*) total,
            SUM(statut='sorti' AND DATE(heure_sortie)=CURDATE()) sortis_today,
            COALESCE(SUM(CASE WHEN statut='sorti' AND DATE(heure_sortie)=CURDATE() THEN montant_facture END),0) ca_today
        FROM bennys_fourriere
    ")->fetch();
    if ($s) { $stats['sortis_today']=(int)$s['sortis_today']; $stats['ca_today']=(float)$s['ca_today']; $stats['total']=(int)$s['total']; }
} catch (Exception $e) {}

// Profiles pour le formulaire d'ajout
$profiles = [];
try {
    $profiles = $db->query("
        SELECT id, firstname, lastname, avatar FROM profiles
        ORDER BY lastname, firstname
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════════════════
   FOURRIÈRE — TOWING YARD OPS  ·  bennys/fourriere.php
   Typo : Oswald (display condensé) · DM Sans (body) · JetBrains Mono (data)
══════════════════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap');

:root {
    --fy-amber:     #f59e0b;
    --fy-amber-lt:  #fbbf24;
    --fy-amber-dim: rgba(245,158,11,.13);
    --fy-amber-glow:rgba(245,158,11,.06);
    --fy-red:       #ef4444;
    --fy-green:     #22c55e;
    --fy-surface:   rgba(10,12,17,.94);
    --fy-surface-2: rgba(14,16,24,.96);
    --fy-border:    rgba(255,255,255,.06);
    --fy-border-a:  rgba(245,158,11,.18);
    --fy-text:      #f1f5f9;
    --fy-muted:     #64748b;
    --fy-ff-d:      'Oswald', sans-serif;
    --fy-ff-b:      'DM Sans', sans-serif;
    --fy-ff-m:      'JetBrains Mono', monospace;
}

/* ── ALERT ──────────────────────────────────────────────────────────────── */
.fy-alert {
    display: flex; align-items: center; gap: .7rem;
    padding: .9rem 1.2rem; border-radius: 10px; margin-bottom: 1.3rem;
    font-family: var(--fy-ff-b); font-size: .84rem; font-weight: 600;
    border: 1px solid transparent;
}
.fy-alert.success { background: rgba(34,197,94,.09); border-color: rgba(34,197,94,.25); color: #4ade80; }
.fy-alert.error   { background: rgba(239,68,68,.09); border-color: rgba(239,68,68,.25); color: #f87171; }

/* ── STATS ──────────────────────────────────────────────────────────────── */
.fy-stats {
    display: grid; grid-template-columns: repeat(4,1fr); gap: .9rem;
    margin-bottom: 1.5rem;
}
@media(max-width:800px){ .fy-stats{ grid-template-columns: repeat(2,1fr); } }
@media(max-width:420px){ .fy-stats{ grid-template-columns: 1fr; } }

.fy-stat {
    background: var(--fy-surface);
    border: 1px solid var(--fy-border);
    border-radius: 12px; padding: 1.1rem 1.2rem 1rem;
    position: relative; overflow: hidden;
    transition: border-color .2s;
}
/* Texture diagonale subtle */
.fy-stat::before {
    content: '';
    position: absolute; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16'%3E%3Cline x1='0' y1='0' x2='16' y2='16' stroke='%23f59e0b' stroke-width='.5' stroke-opacity='.05'/%3E%3C/svg%3E");
    background-size: 16px 16px;
    pointer-events: none;
}
.fy-stat::after {
    content: ''; position: absolute;
    top: 0; left: 0; right: 0; height: 2px;
    background: var(--fy-s-col, var(--fy-amber));
}
.fy-stat:hover { border-color: var(--fy-s-col, var(--fy-amber)); }
.fy-stat-ico {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--fy-s-bg, var(--fy-amber-dim));
    color: var(--fy-s-col, var(--fy-amber));
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem; margin-bottom: .75rem; position: relative; z-index: 1;
}
.fy-stat-val {
    font-family: var(--fy-ff-d); font-size: 2.4rem; font-weight: 700;
    color: var(--fy-text); line-height: 1; position: relative; z-index: 1;
}
.fy-stat-lbl {
    font-family: var(--fy-ff-b); font-size: .65rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .08em;
    color: var(--fy-muted); margin-top: .3rem; position: relative; z-index: 1;
}
.fy-stat-sub {
    font-size: .67rem; color: var(--fy-s-col, var(--fy-amber));
    font-weight: 600; margin-top: .12rem; position: relative; z-index: 1;
}

/* ── TOOLBAR ────────────────────────────────────────────────────────────── */
.fy-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: .65rem; margin-bottom: 1.3rem;
}
.fy-toolbar-left { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
.fy-toolbar-right { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }

/* ── BUTTONS ────────────────────────────────────────────────────────────── */
.fy-btn {
    display: inline-flex; align-items: center; gap: .42rem;
    padding: .54rem 1.05rem; border-radius: 9px;
    font-family: var(--fy-ff-b); font-size: .82rem; font-weight: 700;
    cursor: pointer; border: 1px solid transparent;
    transition: all .17s; text-decoration: none; white-space: nowrap;
    line-height: 1;
}
.fy-btn-sm { padding: .42rem .82rem; font-size: .76rem; }
.fy-btn-primary { background: var(--fy-amber); color: #000; border-color: var(--fy-amber); }
.fy-btn-primary:hover { background: var(--fy-amber-lt); box-shadow: 0 0 18px rgba(245,158,11,.3); }
.fy-btn-ghost { background: rgba(255,255,255,.04); color: var(--fy-muted); border-color: var(--fy-border); }
.fy-btn-ghost:hover { background: rgba(255,255,255,.08); color: var(--fy-text); border-color: rgba(255,255,255,.14); }
.fy-btn-green { background: rgba(34,197,94,.15); color: #4ade80; border-color: rgba(34,197,94,.3); }
.fy-btn-green:hover { background: rgba(34,197,94,.25); border-color: rgba(34,197,94,.55); box-shadow: 0 0 14px rgba(34,197,94,.18); }
.fy-btn-tarif { background: rgba(245,158,11,.1); color: var(--fy-amber); border-color: var(--fy-border-a); }
.fy-btn-tarif:hover { background: var(--fy-amber-dim); box-shadow: 0 0 14px rgba(245,158,11,.15); }

/* ── SECTION TITLE ──────────────────────────────────────────────────────── */
.fy-section-title {
    display: flex; align-items: center; gap: .65rem;
    margin-bottom: 1rem;
}
.fy-section-title-text {
    font-family: var(--fy-ff-d); font-size: 1.05rem; font-weight: 600;
    color: var(--fy-text); letter-spacing: .05em; text-transform: uppercase;
}
.fy-section-count {
    font-family: var(--fy-ff-m); font-size: .65rem; font-weight: 700;
    color: var(--fy-amber); background: var(--fy-amber-dim);
    border: 1px solid var(--fy-border-a); padding: .13rem .5rem; border-radius: 5px;
}
.fy-section-line {
    flex: 1; height: 1px; background: var(--fy-border);
}

/* ── GRILLE VÉHICULES ───────────────────────────────────────────────────── */
.fy-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

/* ── CARD VÉHICULE ──────────────────────────────────────────────────────── */
.fy-card {
    background: var(--fy-surface);
    border: 1px solid var(--fy-border);
    border-radius: 14px;
    overflow: hidden;
    position: relative;
    transition: transform .2s, border-color .22s, box-shadow .22s;
    animation: fyCardIn .35s ease both;
}
@keyframes fyCardIn {
    from { opacity:0; transform:translateY(10px); }
    to   { opacity:1; transform:translateY(0); }
}
/* Bande ambre + texture danger à gauche */
.fy-card::before {
    content: '';
    position: absolute; top:0; left:0; bottom:0; width:4px;
    background: repeating-linear-gradient(
        45deg,
        var(--fy-amber) 0px, var(--fy-amber) 6px,
        #000 6px, #000 12px
    );
    z-index: 1;
}
.fy-card:hover {
    transform: translateY(-2px);
    border-color: rgba(245,158,11,.3);
    box-shadow: 0 10px 34px rgba(0,0,0,.35), 0 0 0 1px rgba(245,158,11,.07);
}

/* Plaque */
.fy-card-plate {
    font-family: var(--fy-ff-m); font-size: 1.25rem; font-weight: 700;
    color: #fff; letter-spacing: .12em;
    background: rgba(245,158,11,.1);
    border: 1.5px solid rgba(245,158,11,.28);
    padding: .3rem .85rem .3rem 1.25rem;
    border-radius: 7px;
    display: inline-block;
    position: relative;
}
/* Vis de plaque */
.fy-card-plate::before,
.fy-card-plate::after {
    content: '●';
    position: absolute; top:50%; transform:translateY(-50%);
    font-size: .35rem; color: rgba(245,158,11,.6);
    line-height:1;
}
.fy-card-plate::before { left:.28rem; }
.fy-card-plate::after  { right:.28rem; }

/* Timer */
.fy-timer {
    font-family: var(--fy-ff-m); font-size: .88rem; font-weight: 700;
    color: var(--fy-amber); background: rgba(245,158,11,.07);
    border: 1px solid rgba(245,158,11,.15);
    padding: .25rem .65rem; border-radius: 7px;
    white-space: nowrap;
}
.fy-timer.urgent { color: var(--fy-red); background: rgba(239,68,68,.07); border-color: rgba(239,68,68,.2); animation: timerBlink 1.2s ease infinite; }
@keyframes timerBlink { 0%,100%{opacity:1} 50%{opacity:.55} }

.fy-card-head {
    padding: 1rem 1.1rem 1rem 1.5rem;
    display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem;
    border-bottom: 1px solid rgba(255,255,255,.04);
}
.fy-card-body {
    padding: .85rem 1.1rem .85rem 1.5rem;
    border-bottom: 1px solid rgba(255,255,255,.04);
}
.fy-card-row {
    display: flex; align-items: center; gap: .55rem;
    font-family: var(--fy-ff-b); font-size: .78rem; color: var(--fy-muted);
    margin-bottom: .38rem;
}
.fy-card-row:last-child { margin-bottom: 0; }
.fy-card-row i { color: var(--fy-amber); font-size: .7rem; width: 14px; flex-shrink:0; }
.fy-card-row strong { color: var(--fy-text); font-weight: 600; }
.fy-card-foot {
    padding: .75rem 1.1rem .75rem 1.5rem;
    display: flex; align-items: center; justify-content: space-between; gap: .5rem;
}
.fy-card-modele {
    font-family: var(--fy-ff-d); font-size: 1rem; font-weight: 600;
    letter-spacing: .04em; color: var(--fy-text); text-transform: uppercase;
}

/* Civil avatar */
.fy-civil {
    display: flex; align-items: center; gap: .5rem;
}
.fy-civil-av {
    width: 26px; height: 26px; border-radius: 6px;
    background: rgba(245,158,11,.15); border: 1px solid rgba(245,158,11,.25);
    display: flex; align-items: center; justify-content: center;
    font-size: .62rem; font-weight: 700; color: var(--fy-amber); flex-shrink:0; overflow:hidden;
}
.fy-civil-av img { width:100%; height:100%; object-fit:cover; border-radius:5px; }
.fy-civil-name { font-size: .76rem; color: var(--fy-text); font-weight:600; font-family: var(--fy-ff-b); }

/* ── EMPTY ──────────────────────────────────────────────────────────────── */
.fy-empty {
    background: var(--fy-surface); border: 1px solid var(--fy-border);
    border-radius: 14px; padding: 3.5rem 2rem; text-align: center;
    margin-bottom: 2rem;
}
.fy-empty i { font-size: 2.8rem; opacity:.18; color: var(--fy-amber); display:block; margin-bottom:.85rem; }
.fy-empty p { color: var(--fy-muted); font-size: .88rem; font-family: var(--fy-ff-b); }

/* ── HISTORIQUE TABLE ───────────────────────────────────────────────────── */
.fy-table-wrap {
    background: var(--fy-surface); border: 1px solid var(--fy-border);
    border-radius: 14px; overflow: hidden; margin-bottom: 1.5rem;
}
.fy-table { width:100%; border-collapse:collapse; }
.fy-table th {
    padding: .75rem 1rem; text-align:left;
    background: rgba(245,158,11,.06); border-bottom: 1px solid rgba(245,158,11,.1);
    font-family: var(--fy-ff-b); font-size: .65rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.08em; color: var(--fy-muted);
    white-space: nowrap;
}
.fy-table td {
    padding: .75rem 1rem; border-bottom: 1px solid rgba(255,255,255,.035);
    font-family: var(--fy-ff-b); font-size: .81rem; color: var(--fy-text);
    vertical-align: middle;
}
.fy-table tr:last-child td { border-bottom:none; }
.fy-table tr:hover td { background: rgba(255,255,255,.015); }
.fy-table-plate { font-family: var(--fy-ff-m); font-weight:700; color: var(--fy-amber); letter-spacing:.08em; }
.fy-table-amount { font-family: var(--fy-ff-m); font-weight:700; color: var(--fy-green); }
.fy-table-muted  { color: var(--fy-muted); font-size: .72rem; }

/* Badge sorti */
.fy-badge-sorti {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.2rem .55rem; border-radius:20px; font-size:.62rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em;
    background:rgba(34,197,94,.1); color:#4ade80; border:1px solid rgba(34,197,94,.25);
}

/* ── MODALS ─────────────────────────────────────────────────────────────── */
.fy-ov {
    position:fixed; inset:0;
    background:rgba(0,0,0,.75); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px);
    z-index:1000; display:flex; align-items:center; justify-content:center; padding:1rem;
    opacity:0; pointer-events:none; transition:opacity .22s;
}
.fy-ov.open { opacity:1; pointer-events:all; }

.fy-modal {
    background: #090c12;
    border: 1px solid var(--fy-border-a);
    border-radius: 16px; width:100%; max-width:480px;
    max-height: 90vh; overflow-y:auto;
    box-shadow: 0 32px 80px rgba(0,0,0,.7), inset 0 1px 0 rgba(255,255,255,.04);
    transform: translateY(16px) scale(.97);
    transition: transform .26s cubic-bezier(.34,1.56,.64,1);
    scrollbar-width: thin; scrollbar-color: rgba(245,158,11,.3) transparent;
}
.fy-ov.open .fy-modal { transform:translateY(0) scale(1); }
.fy-modal::-webkit-scrollbar { width:4px; }
.fy-modal::-webkit-scrollbar-thumb { background:rgba(245,158,11,.25); border-radius:2px; }

/* Modal release : plus large pour la facture */
.fy-modal.fy-modal-release { max-width:440px; }

.fy-modal-hd {
    padding:1.25rem 1.4rem 1rem;
    border-bottom:1px solid rgba(245,158,11,.1);
    display:flex; align-items:center; justify-content:space-between;
}
.fy-modal-hd h2 {
    font-family:var(--fy-ff-d); font-size:1.05rem; font-weight:600;
    color:var(--fy-text); letter-spacing:.06em; text-transform:uppercase;
    display:flex; align-items:center; gap:.6rem; margin:0;
}
.fy-modal-hd h2 i { color:var(--fy-amber); font-size:.88rem; }
.fy-modal-cl {
    width:30px; height:30px; border-radius:7px;
    background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08);
    color:var(--fy-muted); display:flex; align-items:center; justify-content:center;
    cursor:pointer; font-size:.82rem; transition:all .15s;
}
.fy-modal-cl:hover { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.3); color:var(--fy-red); }

.fy-modal-bd { padding:1.25rem 1.4rem; }
.fy-modal-ft {
    padding:1rem 1.4rem 1.25rem;
    border-top:1px solid rgba(255,255,255,.05);
    display:flex; justify-content:flex-end; gap:.55rem;
}

/* Form fields */
.fy-fg { margin-bottom:.95rem; }
.fy-fg:last-child { margin-bottom:0; }
.fy-lbl {
    display:block; font-family:var(--fy-ff-b); font-size:.68rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.07em; color:var(--fy-muted); margin-bottom:.38rem;
}
.fy-inp, .fy-sel {
    width:100%; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09);
    border-radius:9px; padding:.62rem .9rem; color:var(--fy-text);
    font-family:var(--fy-ff-b); font-size:.84rem; outline:none;
    transition:border-color .2s, background .2s; box-sizing:border-box;
}
.fy-inp:focus, .fy-sel:focus { border-color:var(--fy-amber); background:rgba(245,158,11,.04); }
.fy-inp::placeholder { color:var(--fy-muted); }
.fy-sel option { background:#101318; }

/* Formulaire grille 2 colonnes */
.fy-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.fy-form-full { grid-column:1/-1; }

/* Facture release */
.fy-invoice {
    background: rgba(245,158,11,.05);
    border: 1px solid rgba(245,158,11,.15);
    border-radius: 12px; overflow:hidden;
    margin-bottom: 1rem;
}
.fy-invoice-header {
    background: rgba(245,158,11,.1);
    padding: .75rem 1.1rem;
    display:flex; align-items:center; gap:.6rem;
    border-bottom:1px solid rgba(245,158,11,.12);
}
.fy-invoice-header i { color:var(--fy-amber); }
.fy-invoice-header span {
    font-family:var(--fy-ff-d); font-size:.85rem; font-weight:600;
    color:var(--fy-text); text-transform:uppercase; letter-spacing:.06em;
}
.fy-invoice-body { padding:.9rem 1.1rem; }
.fy-invoice-row {
    display:flex; justify-content:space-between; align-items:center;
    font-family:var(--fy-ff-b); font-size:.8rem; color:var(--fy-muted);
    margin-bottom:.5rem; padding-bottom:.5rem;
    border-bottom:1px solid rgba(255,255,255,.04);
}
.fy-invoice-row:last-child { border-bottom:none; margin-bottom:0; padding-bottom:0; }
.fy-invoice-row strong { color:var(--fy-text); font-weight:600; }
.fy-invoice-total {
    display:flex; justify-content:space-between; align-items:center;
    font-family:var(--fy-ff-d); font-size:1.1rem; font-weight:600;
    color:var(--fy-text); text-transform:uppercase; letter-spacing:.04em;
    padding:.85rem 1.1rem;
    background:rgba(245,158,11,.06);
    border-top:2px solid rgba(245,158,11,.2);
}
.fy-invoice-total-amount {
    font-family:var(--fy-ff-m); font-size:1.5rem; font-weight:700; color:var(--fy-green);
}

/* Tarif badge dans toolbar */
.fy-tarif-badge {
    display:inline-flex; align-items:center; gap:.4rem;
    font-family:var(--fy-ff-m); font-size:.72rem; font-weight:700;
    color:var(--fy-amber); background:var(--fy-amber-dim);
    border:1px solid var(--fy-border-a); padding:.3rem .7rem; border-radius:7px;
}
</style>

<?php if ($msg): ?>
<div class="fy-alert <?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- ══ STATS ═══════════════════════════════════════════════════════════════ -->
<div class="fy-stats">
    <div class="fy-stat">
        <div class="fy-stat-ico"><i class="fas fa-warehouse"></i></div>
        <div class="fy-stat-val"><?php echo $stats['en_cours']; ?></div>
        <div class="fy-stat-lbl">En Fourrière</div>
        <div class="fy-stat-sub">actuellement</div>
    </div>
    <div class="fy-stat" style="--fy-s-col:var(--fy-green);--fy-s-bg:rgba(34,197,94,.1);">
        <div class="fy-stat-ico"><i class="fas fa-car-on"></i></div>
        <div class="fy-stat-val"><?php echo $stats['sortis_today']; ?></div>
        <div class="fy-stat-lbl">Sortis Aujourd'hui</div>
        <div class="fy-stat-sub">véhicules libérés</div>
    </div>
    <div class="fy-stat" style="--fy-s-col:var(--fy-amber);--fy-s-bg:var(--fy-amber-dim);">
        <div class="fy-stat-ico"><i class="fas fa-dollar-sign"></i></div>
        <div class="fy-stat-val">$<?php echo number_format($stats['ca_today'], 0); ?></div>
        <div class="fy-stat-lbl">CA Aujourd'hui</div>
        <div class="fy-stat-sub">revenus journée</div>
    </div>
    <div class="fy-stat" style="--fy-s-col:#60a5fa;--fy-s-bg:rgba(96,165,250,.1);">
        <div class="fy-stat-ico"><i class="fas fa-clock-rotate-left"></i></div>
        <div class="fy-stat-val"><?php echo $stats['total']; ?></div>
        <div class="fy-stat-lbl">Total Dossiers</div>
        <div class="fy-stat-sub">depuis ouverture</div>
    </div>
</div>

<!-- ══ TOOLBAR ════════════════════════════════════════════════════════════ -->
<div class="fy-toolbar">
    <div class="fy-toolbar-left">
        <button onclick="FY.open('fy-modal-add')" class="fy-btn fy-btn-primary">
            <i class="fas fa-plus"></i> Mettre en Fourrière
        </button>
        <?php if ($canManageTarif): ?>
        <button onclick="FY.open('fy-modal-tarif')" class="fy-btn fy-btn-tarif">
            <i class="fas fa-gear"></i> Tarif
        </button>
        <?php endif; ?>
    </div>
    <div class="fy-toolbar-right">
        <div class="fy-tarif-badge" title="&lt; 24 h : minute · ≥ 24 h : forfait journalier">
            <i class="fas fa-tag"></i>
            <?php echo number_format($tarif, 2); ?>$/min
            <span style="opacity:.65;">·</span>
            <?php echo number_format($prixParJour, 0); ?>$/j
        </div>
    </div>
</div>

<!-- ══ VÉHICULES EN FOURRIÈRE ════════════════════════════════════════════ -->
<div class="fy-section-title">
    <span class="fy-section-title-text"><i class="fas fa-warehouse" style="color:var(--fy-amber);font-size:.85rem;"></i> En Fourrière</span>
    <span class="fy-section-count"><?php echo count($enFourriere); ?></span>
    <span class="fy-section-line"></span>
</div>

<?php if (empty($enFourriere)): ?>
<div class="fy-empty">
    <i class="fas fa-car-burst"></i>
    <p>Aucun véhicule en fourrière pour le moment</p>
</div>
<?php else: ?>
<div class="fy-grid">
    <?php foreach ($enFourriere as $veh):
        $entreeTs  = strtotime($veh['heure_entree']);
        $entreeStr = date('d/m/Y H:i', $entreeTs);
        $initiale  = $veh['civil_name'] ? strtoupper(substr($veh['civil_name'], 0, 1)) : '?';
        // Données JSON pour le modal release
        $vehJson   = htmlspecialchars(json_encode([
            'id'           => $veh['id'],
            'plaque'       => $veh['plaque'],
            'modele'       => $veh['modele'],
            'heure_entree' => $veh['heure_entree'],
            'civil_name'   => $veh['civil_name'] ?? 'Inconnu',
        ]), ENT_QUOTES);
    ?>
    <div class="fy-card">
        <div class="fy-card-head">
            <div>
                <div class="fy-card-plate"><?php echo htmlspecialchars($veh['plaque']); ?></div>
                <div class="fy-card-modele" style="margin-top:.45rem;"><?php echo htmlspecialchars($veh['modele']); ?></div>
            </div>
            <span class="fy-timer"
                  data-entree="<?php echo $entreeTs; ?>"
                  data-tarif="<?php echo htmlspecialchars((string)$tarif); ?>"
                  data-prix-jour="<?php echo htmlspecialchars((string)$prixParJour); ?>">
                --:--:--
            </span>
        </div>
        <div class="fy-card-body">
            <div class="fy-card-row">
                <i class="fas fa-clock"></i>
                Entré le <strong><?php echo $entreeStr; ?></strong>
            </div>
            <?php if ($veh['civil_name']): ?>
            <div class="fy-card-row">
                <i class="fas fa-user"></i>
                <div class="fy-civil">
                    <div class="fy-civil-av">
                        <?php if ($veh['civil_avatar']): ?>
                        <img src="<?php echo htmlspecialchars($veh['civil_avatar']); ?>"
                             onerror="this.parentNode.textContent='<?php echo $initiale; ?>'">
                        <?php else: echo $initiale; endif; ?>
                    </div>
                    <span class="fy-civil-name"><?php echo htmlspecialchars($veh['civil_name']); ?></span>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($veh['added_by_name']): ?>
            <div class="fy-card-row">
                <i class="fas fa-id-badge"></i>
                Mis en fourrière par <strong><?php echo htmlspecialchars($veh['added_by_name']); ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <div class="fy-card-foot">
            <span style="font-family:var(--fy-ff-b);font-size:.68rem;color:var(--fy-muted);font-style:italic;">
                <i class="fas fa-calculator" style="margin-right:.3rem;color:var(--fy-amber);"></i>
                <span class="fy-running-cost" data-entree="<?php echo $entreeTs; ?>" data-tarif="<?php echo htmlspecialchars((string)$tarif); ?>" data-prix-jour="<?php echo htmlspecialchars((string)$prixParJour); ?>">calcul…</span>
            </span>
            <button onclick="FY.openRelease(<?php echo $vehJson; ?>)"
                    class="fy-btn fy-btn-green fy-btn-sm">
                <i class="fas fa-door-open"></i> Sortir
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ HISTORIQUE ════════════════════════════════════════════════════════ -->
<?php if (!empty($historique)): ?>
<div class="fy-section-title">
    <span class="fy-section-title-text"><i class="fas fa-clock-rotate-left" style="color:var(--fy-amber);font-size:.85rem;"></i> Historique Récent</span>
    <span class="fy-section-count"><?php echo count($historique); ?></span>
    <span class="fy-section-line"></span>
</div>
<div class="fy-table-wrap">
    <table class="fy-table">
        <thead>
            <tr>
                <th>Plaque</th>
                <th>Modèle</th>
                <th>Civil</th>
                <th>Entrée</th>
                <th>Durée</th>
                <th>Facture</th>
                <th>Sorti par</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($historique as $h):
                $dureeMin = 0;
                if ($h['heure_sortie'] && $h['heure_entree']) {
                    $dureeMin = max(0, (int)((strtotime($h['heure_sortie']) - strtotime($h['heure_entree'])) / 60));
                }
                $dureeStr = $dureeMin >= 60
                    ? floor($dureeMin/60).'h '.($dureeMin%60).'min'
                    : $dureeMin.'min';
            ?>
            <tr>
                <td><span class="fy-table-plate"><?php echo htmlspecialchars($h['plaque']); ?></span></td>
                <td><?php echo htmlspecialchars($h['modele']); ?></td>
                <td>
                    <?php if ($h['civil_name']): ?>
                    <?php echo htmlspecialchars($h['civil_name']); ?>
                    <?php else: ?><span class="fy-table-muted">—</span><?php endif; ?>
                </td>
                <td class="fy-table-muted"><?php echo date('d/m H:i', strtotime($h['heure_entree'])); ?></td>
                <td class="fy-table-muted"><?php echo $dureeStr; ?></td>
                <td><span class="fy-table-amount">$<?php echo number_format($h['montant_facture'] ?? 0, 2); ?></span></td>
                <td class="fy-table-muted"><?php echo htmlspecialchars($h['sorti_par_name'] ?? '—'); ?></td>
                <td><span class="fy-badge-sorti"><i class="fas fa-check"></i> Sorti</span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ══ MODAL — Ajouter en fourrière ══════════════════════════════════════ -->
<div id="fy-modal-add" class="fy-ov">
<div class="fy-modal">
    <div class="fy-modal-hd">
        <h2><i class="fas fa-truck-ramp-box"></i> Mise en Fourrière</h2>
        <button class="fy-modal-cl" onclick="FY.close('fy-modal-add')"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="fy-modal-bd">
        <input type="hidden" name="action" value="add">
        <div class="fy-form-grid">
            <!-- Civil -->
            <div class="fy-fg fy-form-full">
                <label class="fy-lbl">Civil propriétaire</label>
                <select name="profile_id" class="fy-sel" id="fy-profile-sel" onchange="fyUpdateCivil(this)">
                    <option value="">— Inconnu / Non sélectionné —</option>
                    <?php foreach ($profiles as $p): ?>
                    <option value="<?php echo $p['id']; ?>"
                            data-avatar="<?php echo htmlspecialchars($p['avatar'] ?? ''); ?>">
                        <?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <!-- Preview civil sélectionné -->
                <div id="fy-civil-preview" style="display:none;align-items:center;gap:.65rem;margin-top:.55rem;padding:.55rem .8rem;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);border-radius:8px;">
                    <div id="fy-civil-av" style="width:30px;height:30px;border-radius:7px;background:rgba(245,158,11,.15);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:var(--fy-amber);flex-shrink:0;overflow:hidden;"></div>
                    <span id="fy-civil-name" style="font-size:.8rem;font-weight:600;color:var(--fy-text);font-family:var(--fy-ff-b);"></span>
                </div>
            </div>
            <!-- Plaque -->
            <div class="fy-fg">
                <label class="fy-lbl">Plaque *</label>
                <input type="text" name="plaque" class="fy-inp"
                       placeholder="ex : AB-123-CD" required maxlength="20"
                       style="text-transform:uppercase;font-family:var(--fy-ff-m);letter-spacing:.08em;">
            </div>
            <!-- Modèle -->
            <div class="fy-fg">
                <label class="fy-lbl">Modèle *</label>
                <input type="text" name="modele" class="fy-inp" placeholder="ex : Sultan RS" required maxlength="100">
            </div>
            <!-- Heure d'entrée -->
            <div class="fy-fg fy-form-full">
                <label class="fy-lbl">Heure d'entrée *</label>
                <input type="datetime-local" name="heure_entree" class="fy-inp"
                       id="fy-heure-entree" required>
            </div>
        </div>
        <!-- Note tarif -->
        <div style="margin-top:.8rem;padding:.65rem .85rem;background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.12);border-radius:8px;font-size:.72rem;color:var(--fy-muted);font-family:var(--fy-ff-b);">
            <i class="fas fa-info-circle" style="color:var(--fy-amber);margin-right:.3rem;"></i>
            Tarif : <strong style="color:var(--fy-amber);"><?php echo number_format($tarif, 2); ?>$/min</strong> si &lt; 24 h,
            sinon <strong style="color:var(--fy-amber);"><?php echo number_format($prixParJour, 2); ?>$/jour</strong> (jours entamés).
            Facture à la sortie.
        </div>
    </div>
    <div class="fy-modal-ft">
        <button type="button" onclick="FY.close('fy-modal-add')" class="fy-btn fy-btn-ghost">Annuler</button>
        <button type="submit" class="fy-btn fy-btn-primary">
            <i class="fas fa-check"></i> Confirmer
        </button>
    </div>
    </form>
</div>
</div>

<!-- ══ MODAL — Sortie fourrière ══════════════════════════════════════════ -->
<div id="fy-modal-release" class="fy-ov">
<div class="fy-modal fy-modal-release">
    <div class="fy-modal-hd">
        <h2><i class="fas fa-door-open"></i> Sortie de Fourrière</h2>
        <button class="fy-modal-cl" onclick="FY.close('fy-modal-release')"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="fy-modal-bd">
        <input type="hidden" name="action" value="release">
        <input type="hidden" name="vehicle_id" id="rel-vid">
        <!-- Facture -->
        <div class="fy-invoice">
            <div class="fy-invoice-header">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Facture de Fourrière</span>
            </div>
            <div class="fy-invoice-body">
                <div class="fy-invoice-row">
                    <span>Plaque</span>
                    <strong id="rel-plaque">—</strong>
                </div>
                <div class="fy-invoice-row">
                    <span>Véhicule</span>
                    <strong id="rel-modele">—</strong>
                </div>
                <div class="fy-invoice-row">
                    <span>Propriétaire</span>
                    <strong id="rel-civil">—</strong>
                </div>
                <div class="fy-invoice-row">
                    <span>Entrée le</span>
                    <strong id="rel-entree">—</strong>
                </div>
                <div class="fy-invoice-row">
                    <span>Durée</span>
                    <strong id="rel-duree" style="color:var(--fy-amber);">—</strong>
                </div>
                <div class="fy-invoice-row">
                    <span>Mode de facturation</span>
                    <strong id="rel-tarif-label" style="font-size:.72rem;text-align:right;max-width:60%;">—</strong>
                </div>
            </div>
            <div class="fy-invoice-total">
                <span>Total à régler</span>
                <span class="fy-invoice-total-amount" id="rel-montant">$0.00</span>
            </div>
        </div>
        <p style="font-family:var(--fy-ff-b);font-size:.78rem;color:var(--fy-muted);margin:0;">
            <i class="fas fa-info-circle" style="color:var(--fy-amber);margin-right:.3rem;"></i>
            La sortie sera horodatée à l'instant présent. L'action sera enregistrée à votre nom.
        </p>
    </div>
    <div class="fy-modal-ft">
        <button type="button" onclick="FY.close('fy-modal-release')" class="fy-btn fy-btn-ghost">Annuler</button>
        <button type="submit" class="fy-btn fy-btn-green">
            <i class="fas fa-check"></i> Valider la sortie
        </button>
    </div>
    </form>
</div>
</div>

<!-- ══ MODAL — Modifier le tarif (DRH+) ══════════════════════════════════ -->
<?php if ($canManageTarif): ?>
<div id="fy-modal-tarif" class="fy-ov">
<div class="fy-modal" style="max-width:380px;">
    <div class="fy-modal-hd">
        <h2><i class="fas fa-gear"></i> Tarif Fourrière</h2>
        <button class="fy-modal-cl" onclick="FY.close('fy-modal-tarif')"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST">
    <div class="fy-modal-bd">
        <input type="hidden" name="action" value="update_tarif">
        <div class="fy-fg">
            <label class="fy-lbl">Prix par minute ($) *</label>
            <input type="number" name="prix_par_minute" id="fy-inp-pm" class="fy-inp"
                   step="0.01" min="0.01" value="<?php echo number_format($tarif, 2, '.', ''); ?>"
                   required style="font-family:var(--fy-ff-m);font-size:1.1rem;text-align:center;">
        </div>
        <div class="fy-fg">
            <label class="fy-lbl">Forfait journalier ($) * — si durée ≥ 24 h</label>
            <input type="number" name="prix_par_jour" id="fy-inp-pj" class="fy-inp"
                   step="0.01" min="0.01" value="<?php echo number_format($prixParJour, 2, '.', ''); ?>"
                   required style="font-family:var(--fy-ff-m);font-size:1.1rem;text-align:center;">
        </div>
        <div style="font-size:.72rem;color:var(--fy-muted);font-family:var(--fy-ff-b);margin-top:.5rem;">
            <i class="fas fa-info-circle" style="color:var(--fy-amber);margin-right:.3rem;"></i>
            &lt; 24 h : facturation à la minute. À partir de 24 h : nombre de jours entamés × forfait jour.
        </div>
        <!-- Simulateur rapide -->
        <div style="margin-top:1rem;padding:.75rem .9rem;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.12);border-radius:9px;">
            <div style="font-family:var(--fy-ff-b);font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:var(--fy-muted);margin-bottom:.55rem;font-weight:700;">Simulation</div>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem;text-align:center;margin-bottom:.4rem;" id="tarif-sim-short">
                <div style="padding:.5rem;background:rgba(255,255,255,.03);border-radius:7px;">
                    <div style="font-family:var(--fy-ff-m);font-size:.9rem;color:var(--fy-green);" id="sim-30">—</div>
                    <div style="font-size:.6rem;color:var(--fy-muted);margin-top:.15rem;">30 min</div>
                </div>
                <div style="padding:.5rem;background:rgba(255,255,255,.03);border-radius:7px;">
                    <div style="font-family:var(--fy-ff-m);font-size:.9rem;color:var(--fy-green);" id="sim-60">—</div>
                    <div style="font-size:.6rem;color:var(--fy-muted);margin-top:.15rem;">12 h</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem;text-align:center;" id="tarif-sim-day">
                <div style="padding:.5rem;background:rgba(255,255,255,.03);border-radius:7px;">
                    <div style="font-family:var(--fy-ff-m);font-size:.9rem;color:var(--fy-green);" id="sim-1j">—</div>
                    <div style="font-size:.6rem;color:var(--fy-muted);margin-top:.15rem;">24–48 h (1 j.)</div>
                </div>
                <div style="padding:.5rem;background:rgba(255,255,255,.03);border-radius:7px;">
                    <div style="font-family:var(--fy-ff-m);font-size:.9rem;color:var(--fy-green);" id="sim-2j">—</div>
                    <div style="font-size:.6rem;color:var(--fy-muted);margin-top:.15rem;">48–72 h (2 j.)</div>
                </div>
            </div>
        </div>
    </div>
    <div class="fy-modal-ft">
        <button type="button" onclick="FY.close('fy-modal-tarif')" class="fy-btn fy-btn-ghost">Annuler</button>
        <button type="submit" class="fy-btn fy-btn-primary">
            <i class="fas fa-floppy-disk"></i> Enregistrer
        </button>
    </div>
    </form>
</div>
</div>
<?php endif; ?>

<script>
const FY_TARIF_MIN = <?php echo json_encode((float) $tarif); ?>;
const FY_TARIF_JOUR = <?php echo json_encode((float) $prixParJour); ?>;

function fourriereComputeAmount(prixMin, prixJour, entreeMs, nowMs) {
    const elapsed = Math.max(0, Math.floor((nowMs - entreeMs) / 1000));
    const DAY = 86400;
    if (elapsed < DAY) {
        const minutes = Math.max(1, Math.floor(elapsed / 60));
        return Math.round(minutes * prixMin * 100) / 100;
    }
    const days = Math.ceil(elapsed / DAY);
    return Math.round(days * prixJour * 100) / 100;
}

const FY = {
    open(id)  { const e=document.getElementById(id); if(e){ e.classList.add('open'); document.body.style.overflow='hidden'; } },
    close(id) { const e=document.getElementById(id); if(e){ e.classList.remove('open'); document.body.style.overflow=''; } },

    openRelease(v) {
        document.getElementById('rel-vid').value    = v.id;
        document.getElementById('rel-plaque').textContent  = v.plaque;
        document.getElementById('rel-modele').textContent  = v.modele;
        document.getElementById('rel-civil').textContent   = v.civil_name || '—';
        // Format date entrée
        const d = new Date(v.heure_entree.replace(' ','T'));
        document.getElementById('rel-entree').textContent  =
            d.toLocaleDateString('fr-FR') + ' ' + d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
        // Calcul durée & montant live
        fyUpdateInvoice(v.heure_entree);
        // Mise à jour continue
        if(window._relInterval) clearInterval(window._relInterval);
        window._relInterval = setInterval(() => fyUpdateInvoice(v.heure_entree), 10000);
        this.open('fy-modal-release');
    }
};

function fyUpdateInvoice(heureEntreeStr) {
    const prixMin = FY_TARIF_MIN;
    const prixJour = FY_TARIF_JOUR;
    const entree  = new Date(heureEntreeStr.replace(' ','T'));
    const now     = new Date();
    const elapsed = Math.max(0, Math.floor((now - entree) / 1000));
    const minutes = Math.max(1, Math.floor(elapsed / 60));
    const hours   = Math.floor(minutes/60);
    const mins    = minutes % 60;
    const montant = fourriereComputeAmount(prixMin, prixJour, entree.getTime(), now.getTime());
    const dureeStr = hours > 0 ? `${hours}h ${mins}min` : `${mins} min`;
    const dureeEl   = document.getElementById('rel-duree');
    const montantEl = document.getElementById('rel-montant');
    const tarifEl   = document.getElementById('rel-tarif-label');
    if(dureeEl)   dureeEl.textContent   = dureeStr;
    if(montantEl) montantEl.textContent = '$' + montant.toLocaleString('fr-FR',{minimumFractionDigits:2});
    if(tarifEl) {
        tarifEl.textContent = elapsed < 86400
            ? (prixMin.toFixed(2) + '$/min (< 24 h)')
            : (Math.ceil(elapsed / 86400) + ' j. × ' + prixJour.toLocaleString('fr-FR',{minimumFractionDigits:0}) + '$');
    }
}

// Fermer modals au clic overlay ou Escape
document.querySelectorAll('.fy-ov').forEach(ov => {
    ov.addEventListener('click', e => { if(e.target===ov) FY.close(ov.id); });
});
document.addEventListener('keydown', e => {
    if(e.key==='Escape') {
        document.querySelectorAll('.fy-ov.open').forEach(m => FY.close(m.id));
        if(window._relInterval) { clearInterval(window._relInterval); window._relInterval=null; }
    }
});

// ── Initialiser datetime local à maintenant ─────────────────────────────
(function() {
    const inp = document.getElementById('fy-heure-entree');
    if (!inp) return;
    const now = new Date();
    now.setSeconds(0, 0);
    const pad = n => String(n).padStart(2, '0');
    inp.value = now.getFullYear() + '-'
        + pad(now.getMonth() + 1) + '-'
        + pad(now.getDate()) + 'T'
        + pad(now.getHours()) + ':'
        + pad(now.getMinutes());
})();

// ── Preview civil sélectionné ────────────────────────────────────────────
function fyUpdateCivil(sel) {
    const preview = document.getElementById('fy-civil-preview');
    const avEl    = document.getElementById('fy-civil-av');
    const nameEl  = document.getElementById('fy-civil-name');
    if(!sel.value) { preview.style.display='none'; return; }
    const opt     = sel.options[sel.selectedIndex];
    const name    = opt.text.trim();
    const avatar  = opt.dataset.avatar;
    const init    = name.charAt(0).toUpperCase();
    avEl.innerHTML = avatar
        ? `<img src="${avatar}" alt="${init}" onerror="this.parentNode.textContent='${init}'">`
        : init;
    nameEl.textContent = name;
    preview.style.display = 'flex';
}

// ── Timers live des cards ─────────────────────────────────────────────────
function fyFormatTimer(seconds) {
    const h = Math.floor(seconds/3600);
    const m = Math.floor((seconds%3600)/60);
    const s = seconds%60;
    return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
}
function fyUpdateTimers() {
    const now = Math.floor(Date.now()/1000);
    document.querySelectorAll('.fy-timer').forEach(el => {
        const entree = parseInt(el.dataset.entree);
        const tarif  = parseFloat(el.dataset.tarif);
        const secs   = Math.max(0, now - entree);
        el.textContent = fyFormatTimer(secs);
        el.className = secs > 7200 ? 'fy-timer urgent' : 'fy-timer';
    });
    document.querySelectorAll('.fy-running-cost').forEach(el => {
        const entreeTs = parseInt(el.dataset.entree, 10);
        const pm  = parseFloat(el.dataset.tarif);
        const pj  = parseFloat(el.dataset.prixJour || '') || FY_TARIF_JOUR;
        const cout = fourriereComputeAmount(pm, pj, entreeTs * 1000, Date.now());
        el.textContent = '$' + cout.toLocaleString('fr-FR',{minimumFractionDigits:2}) + ' estimé';
    });
}
fyUpdateTimers();
setInterval(fyUpdateTimers, 1000);

<?php if ($canManageTarif): ?>
// ── Simulateur tarif ─────────────────────────────────────────────────────
(function() {
    const inpM = document.getElementById('fy-inp-pm');
    const inpJ = document.getElementById('fy-inp-pj');
    if (!inpM || !inpJ) return;
    function updateSim() {
        const pm = parseFloat(inpM.value) || 0;
        const pj = parseFloat(inpJ.value) || 0;
        const fmt = (n) => '$' + n.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        document.getElementById('sim-30').textContent = fmt(Math.round(30 * pm));
        document.getElementById('sim-60').textContent = fmt(Math.round(12 * 60 * pm));
        document.getElementById('sim-1j').textContent = fmt(Math.round(pj));
        document.getElementById('sim-2j').textContent = fmt(Math.round(2 * pj));
    }
    inpM.addEventListener('input', updateSim);
    inpJ.addEventListener('input', updateSim);
    updateSim();
})();
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>