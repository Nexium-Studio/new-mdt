<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🚕 Taxi Los Santos';
$companyColor   = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Chauffeurs';
$pageSubtitle = 'Gestion du personnel roulant';
$db  = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'];
$isChef = hasRole(['*','Administrateur','Développeur','Staff','Directeur','Responsable']);

if (isset($_GET['_count'])) {
    $n = $db->query("SELECT COUNT(*) FROM taxi_courses WHERE statut='en_attente'")->fetchColumn();
    header('Content-Type:application/json'); echo json_encode(['attente'=>(int)$n]); exit;
}

$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isChef) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            // Vérifier que le user n'est pas déjà chauffeur
            $exists = $db->prepare("SELECT id FROM taxi_chauffeurs WHERE user_id=?");
            $exists->execute([intval($_POST['user_id'])]);
            if ($exists->fetch()) throw new Exception('Ce membre est déjà chauffeur.');

            $db->prepare("
                INSERT INTO taxi_chauffeurs (user_id, numero_badge, vehicule, date_embauche, statut, actif)
                VALUES (?,?,?,?,?,1)
            ")->execute([
                intval($_POST['user_id']),
                trim($_POST['numero_badge']),
                trim($_POST['vehicule'] ?? ''),
                $_POST['date_embauche'] ?: null,
                'hors_service',
            ]);
            $msg = 'Chauffeur créé.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'update_statut') {
        try {
            $db->prepare("UPDATE taxi_chauffeurs SET statut=? WHERE id=?")
               ->execute([$_POST['statut'], intval($_POST['chauffeur_id'])]);
            $msg = 'Statut mis à jour.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'toggle_actif') {
        try {
            $db->prepare("UPDATE taxi_chauffeurs SET actif=NOT actif WHERE id=?")->execute([intval($_POST['chauffeur_id'])]);
            $msg = 'Statut d\'activité modifié.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'edit') {
        try {
            $db->prepare("UPDATE taxi_chauffeurs SET numero_badge=?, vehicule=?, date_embauche=? WHERE id=?")
               ->execute([
                   trim($_POST['numero_badge']),
                   trim($_POST['vehicule']??''),
                   $_POST['date_embauche']?:null,
                   intval($_POST['chauffeur_id']),
               ]);
            $msg = 'Chauffeur mis à jour.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

// ─── DONNÉES ─────────────────────────────────────────────────────────────────
$fStatut = $_GET['statut'] ?? '';
$fActif  = $_GET['actif'] ?? '1';
$search  = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($fStatut) { $where[] = 'tc.statut=?'; $params[] = $fStatut; }
if ($fActif !== '')  { $where[] = 'tc.actif=?'; $params[] = intval($fActif); }
if ($search)  { $where[] = '(u.username LIKE ? OR tc.numero_badge LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereStr = implode(' AND ', $where);

$chauffeurs = $db->prepare("
    SELECT tc.*, u.username, u.faction_rank, u.id as uid,
           COUNT(DISTINCT CASE WHEN c.statut='terminee' THEN c.id END) as nb_courses_total,
           SUM(CASE WHEN c.statut='terminee' AND DATE(c.date_demande)=CURDATE() THEN c.montant_total ELSE 0 END) as ca_aujourd,
           SUM(CASE WHEN c.statut='terminee' THEN c.montant_total ELSE 0 END) as ca_total,
           AVG(CASE WHEN c.statut='terminee' THEN c.note_client END) as note_moy
    FROM taxi_chauffeurs tc
    JOIN users u ON tc.user_id=u.id
    LEFT JOIN taxi_courses c ON tc.id=c.chauffeur_id
    WHERE $whereStr
    GROUP BY tc.id, u.username, u.faction_rank, u.id
    ORDER BY FIELD(tc.statut,'disponible','en_course','pause','hors_service'), u.username
");
$chauffeurs->execute($params); $chauffeurs = $chauffeurs->fetchAll();

// Stats globales
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(actif=1) as actifs,
        SUM(statut='disponible') as disponibles,
        SUM(statut='en_course')  as en_course,
        SUM(statut='pause')      as en_pause,
        SUM(statut='hors_service') as hors_service
    FROM taxi_chauffeurs
")->fetch();

// Membres sans badge (pour création)
$membres = $db->query("
    SELECT u.id, u.username, u.faction_rank
    FROM users u
    WHERE u.faction='TAXI' AND u.is_active=1
      AND u.id NOT IN (SELECT user_id FROM taxi_chauffeurs)
    ORDER BY u.username
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════════════════════
   TAXI — URBAN NIGHT DISPATCH  ·  chauffeurs.php
   Police : Barlow Condensed (display) · Barlow (body) · JetBrains Mono (data)
══════════════════════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800;900&family=Barlow:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap');

:root {
    --tx-amber:        #f59e0b;
    --tx-amber-lt:     #fbbf24;
    --tx-amber-dim:    rgba(245,158,11,.14);
    --tx-amber-glow:   rgba(245,158,11,.07);
    --tx-surface:      rgba(11,13,18,.92);
    --tx-surface-2:    rgba(16,19,27,.95);
    --tx-border:       rgba(245,158,11,.11);
    --tx-border-2:     rgba(255,255,255,.06);
    --tx-green:        #22c55e;
    --tx-blue:         #60a5fa;
    --tx-gray:         #6b7280;
    --tx-red:          #ef4444;
    --tx-text:         #f1f5f9;
    --tx-muted:        #64748b;
    --tx-ff-d:         'Barlow Condensed', sans-serif;
    --tx-ff-b:         'Barlow', sans-serif;
    --tx-ff-m:         'JetBrains Mono', monospace;
}

/* ── STATS ────────────────────────────────────────────────────────────────── */
.tx-stats {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: .9rem;
    margin-bottom: 1.4rem;
}
@media(max-width:860px){.tx-stats{grid-template-columns:repeat(2,1fr);}}
@media(max-width:480px){.tx-stats{grid-template-columns:1fr;}}

.tx-stat {
    background: var(--tx-surface);
    border: 1px solid var(--tx-border-2);
    border-radius: 13px;
    padding: 1.1rem 1.25rem 1rem;
    position: relative;
    overflow: hidden;
    transition: border-color .2s, box-shadow .2s;
}
.tx-stat::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: var(--tx-s-col, var(--tx-amber));
    border-radius: 13px 13px 0 0;
}
.tx-stat:hover {
    border-color: var(--tx-s-col, var(--tx-amber));
    box-shadow: 0 0 28px rgba(0,0,0,.2), inset 0 0 0 1px rgba(255,255,255,.02);
}
.tx-stat-ico {
    width: 34px; height: 34px;
    border-radius: 9px;
    background: var(--tx-s-bg, var(--tx-amber-dim));
    color: var(--tx-s-col, var(--tx-amber));
    display: flex; align-items: center; justify-content: center;
    font-size: .82rem;
    margin-bottom: .85rem;
}
.tx-stat-val {
    font-family: var(--tx-ff-d);
    font-size: 2.5rem;
    font-weight: 900;
    line-height: 1;
    color: var(--tx-text);
}
.tx-stat-lbl {
    font-family: var(--tx-ff-b);
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--tx-muted);
    margin-top: .35rem;
}
.tx-stat-sub {
    font-size: .68rem;
    color: var(--tx-s-col, var(--tx-amber));
    margin-top: .18rem;
    font-weight: 600;
}

/* ── TOOLBAR ──────────────────────────────────────────────────────────────── */
.tx-toolbar {
    background: var(--tx-surface);
    border: 1px solid var(--tx-border-2);
    border-radius: 12px;
    padding: .8rem 1rem;
    margin-bottom: 1.2rem;
    display: flex;
    align-items: center;
    gap: .6rem;
    flex-wrap: wrap;
}
.tx-search {
    position: relative;
    flex: 1;
    min-width: 180px;
}
.tx-search i {
    position: absolute;
    left: .85rem; top: 50%;
    transform: translateY(-50%);
    color: var(--tx-muted);
    font-size: .76rem;
    pointer-events: none;
}
.tx-search input {
    width: 100%;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--tx-border-2);
    border-radius: 8px;
    padding: .55rem .85rem .55rem 2.2rem;
    color: var(--tx-text);
    font-family: var(--tx-ff-b);
    font-size: .84rem;
    outline: none;
    box-sizing: border-box;
    transition: border-color .2s, background .2s;
}
.tx-search input:focus { border-color: var(--tx-amber); background: rgba(245,158,11,.04); }
.tx-search input::placeholder { color: var(--tx-muted); }

.tx-filters { display: flex; gap: .38rem; flex-wrap: wrap; align-items: center; }

.tx-pill {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .36rem .82rem;
    border-radius: 20px;
    font-family: var(--tx-ff-b);
    font-size: .74rem;
    font-weight: 600;
    border: 1.5px solid var(--tx-border-2);
    background: rgba(255,255,255,.02);
    color: var(--tx-muted);
    cursor: pointer;
    transition: all .18s;
    text-decoration: none;
    white-space: nowrap;
    line-height: 1;
}
.tx-pill:hover { border-color: rgba(245,158,11,.3); color: var(--tx-text); background: var(--tx-amber-dim); }
.tx-pill.on  { border-color: var(--tx-pill-col, var(--tx-amber)); background: var(--tx-pill-bg, var(--tx-amber-dim)); color: var(--tx-pill-col, var(--tx-amber)); }
.tx-pill .dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
    display: inline-block;
}

.tx-divider { width: 1px; height: 20px; background: var(--tx-border-2); flex-shrink: 0; }

/* ── GRILLE CARDS ─────────────────────────────────────────────────────────── */
.tx-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(295px,1fr));
    gap: 1rem;
}

.tx-card {
    background: var(--tx-surface);
    border: 1px solid var(--tx-border-2);
    border-radius: 14px;
    overflow: hidden;
    transition: transform .2s, border-color .22s, box-shadow .22s;
    position: relative;
}
.tx-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; bottom: 0;
    width: 3px;
    background: var(--tx-c-col, var(--tx-gray));
    z-index: 1;
}
.tx-card.disponible   { --tx-c-col: var(--tx-green); }
.tx-card.en_course    { --tx-c-col: var(--tx-amber); }
.tx-card.pause        { --tx-c-col: var(--tx-blue); }
.tx-card.hors_service { --tx-c-col: var(--tx-gray); }
.tx-card:hover {
    transform: translateY(-2px);
    border-color: color-mix(in srgb, var(--tx-c-col) 40%, transparent);
    box-shadow: 0 10px 36px rgba(0,0,0,.3), 0 0 0 1px color-mix(in srgb, var(--tx-c-col) 8%, transparent);
}
.tx-card.inactive { opacity: .52; }
.tx-card.inactive:hover { opacity: .72; }

/* Head */
.tx-card-head {
    padding: 1rem 1.1rem 1rem 1.4rem;
    display: flex;
    align-items: flex-start;
    gap: .85rem;
}
.tx-avatar {
    width: 46px; height: 46px;
    border-radius: 11px;
    background: linear-gradient(135deg,
        var(--tx-c-col, var(--tx-amber)),
        color-mix(in srgb, var(--tx-c-col, var(--tx-amber)) 55%, #000));
    color: #000;
    font-family: var(--tx-ff-d);
    font-size: 1.3rem;
    font-weight: 900;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    letter-spacing: -.02em;
    text-shadow: none;
}
.tx-card-meta { flex: 1; min-width: 0; }
.tx-card-name {
    font-family: var(--tx-ff-d);
    font-size: 1.02rem;
    font-weight: 800;
    color: var(--tx-text);
    letter-spacing: .015em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}
.tx-card-rank {
    font-size: .68rem;
    color: var(--tx-muted);
    margin-top: .1rem;
    font-family: var(--tx-ff-b);
}
.tx-card-badge {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    font-family: var(--tx-ff-m);
    font-size: .66rem;
    font-weight: 700;
    color: var(--tx-amber);
    background: var(--tx-amber-dim);
    padding: .14rem .5rem .14rem .4rem;
    border-radius: 5px;
    margin-top: .3rem;
    letter-spacing: .04em;
}
.tx-card-badge::before {
    content: '#';
    opacity: .6;
    font-size: .6rem;
}

/* Status pill */
.tx-status {
    display: inline-flex;
    align-items: center;
    gap: .28rem;
    padding: .25rem .6rem;
    border-radius: 20px;
    font-size: .64rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .055em;
    font-family: var(--tx-ff-b);
    flex-shrink: 0;
    white-space: nowrap;
    line-height: 1;
}
.tx-status .pulse {
    width: 5px; height: 5px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}
.tx-status.disponible   { background: rgba(34,197,94,.12);   color: #4ade80; }
.tx-status.en_course    { background: rgba(245,158,11,.13);  color: #fbbf24; }
.tx-status.pause        { background: rgba(96,165,250,.12);  color: #93c5fd; }
.tx-status.hors_service { background: rgba(107,114,128,.12); color: #9ca3af; }
.tx-status.disponible .pulse,
.tx-status.en_course .pulse { animation: tx-pulse 2s ease infinite; }
@keyframes tx-pulse { 0%,100%{opacity:1} 50%{opacity:.25} }

/* Stats strip */
.tx-card-stats {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    border-top: 1px solid rgba(255,255,255,.045);
    border-bottom: 1px solid rgba(255,255,255,.045);
}
.tx-card-stat {
    padding: .72rem .5rem;
    text-align: center;
    position: relative;
}
.tx-card-stat + .tx-card-stat::before {
    content: '';
    position: absolute;
    left: 0; top: 22%; bottom: 22%;
    width: 1px;
    background: rgba(255,255,255,.045);
}
.tx-card-stat-val {
    font-family: var(--tx-ff-d);
    font-size: 1.15rem;
    font-weight: 900;
    color: var(--tx-text);
    line-height: 1;
}
.tx-card-stat-lbl {
    font-size: .58rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--tx-muted);
    margin-top: .22rem;
    font-family: var(--tx-ff-b);
}
.tx-stars { color: #fbbf24; font-size: .76rem; letter-spacing: .04em; }

/* Vehicle */
.tx-card-vehicle {
    padding: .48rem 1.1rem .48rem 1.4rem;
    font-size: .74rem;
    color: var(--tx-muted);
    display: flex;
    align-items: center;
    gap: .45rem;
    font-family: var(--tx-ff-b);
    border-bottom: 1px solid rgba(255,255,255,.03);
}
.tx-card-vehicle i { color: var(--tx-amber); font-size: .68rem; }

/* Footer */
.tx-card-foot {
    padding: .72rem 1.1rem .72rem 1.4rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    flex-wrap: wrap;
}

/* Quick select statut */
.tx-qs {
    background: rgba(255,255,255,.04);
    border: 1px solid var(--tx-border-2);
    border-radius: 7px;
    padding: .33rem .6rem;
    color: var(--tx-text);
    font-family: var(--tx-ff-b);
    font-size: .74rem;
    font-weight: 600;
    outline: none;
    cursor: pointer;
    transition: border-color .18s;
    width: 138px;
}
.tx-qs:focus { border-color: var(--tx-amber); }
.tx-qs option { background: #131720; }

/* Icon buttons */
.tx-btn-ico {
    width: 30px; height: 30px;
    border-radius: 7px;
    border: 1px solid var(--tx-border-2);
    background: rgba(255,255,255,.03);
    color: var(--tx-muted);
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem;
    cursor: pointer;
    transition: all .17s;
    flex-shrink: 0;
}
.tx-btn-ico:hover { border-color: var(--tx-amber); color: var(--tx-amber); background: var(--tx-amber-dim); }
.tx-btn-ico.x-red:hover  { border-color: var(--tx-red);  color: var(--tx-red);  background: rgba(239,68,68,.12); }
.tx-btn-ico.x-grn:hover  { border-color: var(--tx-green);color: var(--tx-green);background: rgba(34,197,94,.12); }

/* ── VIDE ─────────────────────────────────────────────────────────────────── */
.tx-empty {
    padding: 4.5rem 2rem;
    text-align: center;
    background: var(--tx-surface);
    border: 1px solid var(--tx-border-2);
    border-radius: 14px;
}
.tx-empty i { font-size: 2.4rem; color: var(--tx-muted); opacity: .3; display: block; margin-bottom: 1rem; }
.tx-empty p { color: var(--tx-muted); font-size: .88rem; }

/* ── ALERT ────────────────────────────────────────────────────────────────── */
.tx-alert {
    display: flex; align-items: center; gap: .7rem;
    padding: .9rem 1.15rem;
    border-radius: 11px;
    font-size: .85rem;
    font-weight: 500;
    margin-bottom: 1.2rem;
    border: 1px solid transparent;
    font-family: var(--tx-ff-b);
}
.tx-alert.success { background: rgba(34,197,94,.1);  border-color: rgba(34,197,94,.22);  color: #4ade80; }
.tx-alert.error   { background: rgba(239,68,68,.1);  border-color: rgba(239,68,68,.22);  color: #f87171; }

/* ── MODALS ───────────────────────────────────────────────────────────────── */
.tx-ov {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.72);
    backdrop-filter: blur(7px);
    -webkit-backdrop-filter: blur(7px);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    opacity: 0;
    pointer-events: none;
    transition: opacity .22s;
}
.tx-ov.open { opacity: 1; pointer-events: all; }

.tx-modal {
    background: #0d1017;
    border: 1px solid rgba(245,158,11,.18);
    border-radius: 16px;
    width: 100%;
    max-width: 440px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 32px 80px rgba(0,0,0,.65),
                0 0 0 1px rgba(245,158,11,.05),
                inset 0 1px 0 rgba(255,255,255,.04);
    transform: translateY(14px) scale(.97);
    transition: transform .24s cubic-bezier(.34,1.56,.64,1);
    scrollbar-width: thin;
    scrollbar-color: rgba(245,158,11,.3) transparent;
}
.tx-ov.open .tx-modal { transform: translateY(0) scale(1); }
.tx-modal::-webkit-scrollbar { width: 4px; }
.tx-modal::-webkit-scrollbar-thumb { background: rgba(245,158,11,.28); border-radius: 2px; }

.tx-modal-hd {
    padding: 1.3rem 1.4rem 1rem;
    border-bottom: 1px solid rgba(245,158,11,.1);
    display: flex; align-items: center; justify-content: space-between;
}
.tx-modal-hd h2 {
    font-family: var(--tx-ff-d);
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--tx-text);
    letter-spacing: .025em;
    display: flex; align-items: center; gap: .55rem;
    margin: 0;
}
.tx-modal-hd h2 i { color: var(--tx-amber); font-size: .88rem; }

.tx-modal-cl {
    width: 30px; height: 30px;
    border-radius: 8px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    color: var(--tx-muted);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: .85rem;
    transition: all .15s;
}
.tx-modal-cl:hover { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.3); color: var(--tx-red); }

.tx-modal-bd { padding: 1.3rem 1.4rem; }
.tx-modal-ft {
    padding: 1rem 1.4rem 1.3rem;
    border-top: 1px solid rgba(255,255,255,.05);
    display: flex; justify-content: flex-end; gap: .55rem;
}

/* Modal form */
.tx-fg { margin-bottom: 1rem; }
.tx-fg:last-child { margin-bottom: 0; }
.tx-lbl {
    display: block;
    font-family: var(--tx-ff-b);
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--tx-muted);
    margin-bottom: .42rem;
}
.tx-inp, .tx-sel {
    width: 100%;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 9px;
    padding: .65rem .95rem;
    color: var(--tx-text);
    font-family: var(--tx-ff-b);
    font-size: .86rem;
    outline: none;
    transition: border-color .2s, background .2s;
    box-sizing: border-box;
}
.tx-inp:focus, .tx-sel:focus { border-color: var(--tx-amber); background: rgba(245,158,11,.05); }
.tx-inp::placeholder { color: var(--tx-muted); }
.tx-sel option { background: #131720; }

/* Modal stat summary */
.tx-summary {
    display: flex; gap: 0;
    background: rgba(245,158,11,.04);
    border: 1px solid rgba(245,158,11,.12);
    border-radius: 11px;
    overflow: hidden;
    margin-top: 1rem;
}
.tx-summary-item {
    flex: 1;
    padding: .85rem .9rem;
    text-align: center;
    position: relative;
}
.tx-summary-item + .tx-summary-item::before {
    content: '';
    position: absolute; left: 0; top: 18%; bottom: 18%;
    width: 1px;
    background: rgba(245,158,11,.1);
}
.tx-summary-item strong {
    display: block;
    font-family: var(--tx-ff-d);
    font-size: 1.15rem;
    font-weight: 900;
    color: var(--tx-amber);
    line-height: 1;
}
.tx-summary-item span {
    display: block;
    font-size: .62rem;
    color: var(--tx-muted);
    text-transform: uppercase;
    letter-spacing: .07em;
    font-weight: 700;
    margin-top: .25rem;
    font-family: var(--tx-ff-b);
}

/* Buttons */
.tx-btn {
    display: inline-flex; align-items: center; gap: .42rem;
    padding: .56rem 1.1rem;
    border-radius: 9px;
    font-family: var(--tx-ff-b);
    font-size: .84rem; font-weight: 700;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .18s;
    text-decoration: none; white-space: nowrap;
    line-height: 1;
}
.tx-btn-sm { padding: .46rem .85rem; font-size: .8rem; }
.tx-btn-primary { background: var(--tx-amber); color: #000; border-color: var(--tx-amber); }
.tx-btn-primary:hover { background: var(--tx-amber-lt); border-color: var(--tx-amber-lt); box-shadow: 0 0 22px rgba(245,158,11,.28); }
.tx-btn-ghost { background: rgba(255,255,255,.04); color: var(--tx-muted); border-color: rgba(255,255,255,.09); }
.tx-btn-ghost:hover { background: rgba(255,255,255,.08); color: var(--tx-text); border-color: rgba(255,255,255,.14); }

/* ══════════════════════════════════════════════════════════════════════════
   ░░ TEXTURES PERSONNALISÉES ░░
══════════════════════════════════════════════════════════════════════════ */

/* ── STATUTS — badges texturés ────────────────────────────────────────── */
.tx-status { position: relative; overflow: hidden; }
.tx-status::before {
    content: '';
    position: absolute; inset: 0;
    background-repeat: repeat;
    pointer-events: none;
    z-index: 0;
}
.tx-status > * { position: relative; z-index: 1; }

/* DISPONIBLE — radar sonar : cercles concentriques qui rayonnent */
.tx-status.disponible {
    border: 1px solid rgba(34,197,94,.32);
    box-shadow: 0 0 11px rgba(34,197,94,.1), inset 0 0 0 1px rgba(34,197,94,.06);
}
.tx-status.disponible::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='28'%3E%3Ccircle cx='14' cy='14' r='11' fill='none' stroke='%2322c55e' stroke-width='.7' stroke-opacity='.3'/%3E%3Ccircle cx='14' cy='14' r='7' fill='none' stroke='%2322c55e' stroke-width='.7' stroke-opacity='.2'/%3E%3Ccircle cx='14' cy='14' r='3' fill='none' stroke='%2322c55e' stroke-width='.7' stroke-opacity='.12'/%3E%3C/svg%3E");
    background-size: 28px 28px;
    animation: tx-radar 3s linear infinite;
}
@keyframes tx-radar {
    0%   { background-position: 0 0; opacity: 1; }
    50%  { opacity: .6; }
    100% { background-position: 28px 28px; opacity: 1; }
}

/* EN COURSE — lignes de vitesse animées */
.tx-status.en_course {
    border: 1px solid rgba(245,158,11,.35);
    box-shadow: 0 0 12px rgba(245,158,11,.12), inset 0 0 0 1px rgba(245,158,11,.07);
}
.tx-status.en_course::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='14'%3E%3Cline x1='0' y1='7' x2='60' y2='7' stroke='%23f59e0b' stroke-width='1' stroke-opacity='.22'/%3E%3Cline x1='5' y1='3' x2='42' y2='3' stroke='%23f59e0b' stroke-width='.5' stroke-opacity='.14'/%3E%3Cline x1='8' y1='11' x2='50' y2='11' stroke='%23f59e0b' stroke-width='.5' stroke-opacity='.14'/%3E%3C/svg%3E");
    background-size: 60px 14px;
    animation: tx-speedlines .65s linear infinite;
}
@keyframes tx-speedlines {
    from { background-position: 0 0; }
    to   { background-position: 60px 0; }
}

/* PAUSE — tirets alternés */
.tx-status.pause {
    border: 1px solid rgba(96,165,250,.28);
    box-shadow: 0 0 10px rgba(96,165,250,.08), inset 0 0 0 1px rgba(96,165,250,.05);
}
.tx-status.pause::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='10'%3E%3Crect x='0' y='4' width='7' height='2' rx='1' fill='%2360a5fa' fill-opacity='.26'/%3E%3Crect x='13' y='4' width='7' height='2' rx='1' fill='%2360a5fa' fill-opacity='.26'/%3E%3C/svg%3E");
    background-size: 20px 10px;
    animation: tx-dashes 1.4s linear infinite;
}
@keyframes tx-dashes {
    from { background-position: 0 0; }
    to   { background-position: 20px 0; }
}

/* HORS SERVICE — hachures diagonales statiques */
.tx-status.hors_service {
    border: 1px solid rgba(107,114,128,.22);
}
.tx-status.hors_service::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10'%3E%3Cline x1='0' y1='0' x2='10' y2='10' stroke='%236b7280' stroke-width='.9' stroke-opacity='.22'/%3E%3Cline x1='5' y1='0' x2='10' y2='5' stroke='%236b7280' stroke-width='.9' stroke-opacity='.13'/%3E%3Cline x1='0' y1='5' x2='5' y2='10' stroke='%236b7280' stroke-width='.9' stroke-opacity='.13'/%3E%3C/svg%3E");
    background-size: 10px 10px;
}

/* ── FOND DES CARDS — texture très subtile par statut ─────────────────── */
.tx-card.disponible {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='25' fill='none' stroke='%2322c55e' stroke-width='.5' stroke-opacity='.05'/%3E%3Ccircle cx='30' cy='30' r='15' fill='none' stroke='%2322c55e' stroke-width='.5' stroke-opacity='.035'/%3E%3Ccircle cx='30' cy='30' r='6' fill='none' stroke='%2322c55e' stroke-width='.5' stroke-opacity='.02'/%3E%3C/svg%3E");
    background-size: 60px 60px;
    background-position: top right;
    background-repeat: repeat;
}
.tx-card.en_course {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='30' height='30'%3E%3Cline x1='0' y1='30' x2='30' y2='0' stroke='%23f59e0b' stroke-width='.6' stroke-opacity='.055'/%3E%3Cline x1='-10' y1='30' x2='20' y2='0' stroke='%23f59e0b' stroke-width='.6' stroke-opacity='.035'/%3E%3Cline x1='10' y1='30' x2='40' y2='0' stroke='%23f59e0b' stroke-width='.6' stroke-opacity='.035'/%3E%3C/svg%3E");
    background-size: 30px 30px;
    background-repeat: repeat;
}
.tx-card.pause {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='22' height='22'%3E%3Crect x='4' y='10' width='5' height='2' rx='1' fill='%2360a5fa' fill-opacity='.07'/%3E%3Crect x='13' y='10' width='5' height='2' rx='1' fill='%2360a5fa' fill-opacity='.07'/%3E%3C/svg%3E");
    background-size: 22px 22px;
    background-repeat: repeat;
}
.tx-card.hors_service {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14'%3E%3Cline x1='0' y1='0' x2='14' y2='14' stroke='%236b7280' stroke-width='.6' stroke-opacity='.07'/%3E%3C/svg%3E");
    background-size: 14px 14px;
    background-repeat: repeat;
}

/* ── MODAL CRÉER CHAUFFEUR — hero textured ────────────────────────────── */
.tx-modal.tx-modal-create { max-width: 460px; }

.tx-modal-cl-float {
    position: absolute;
    top: .9rem; right: .9rem;
    z-index: 10;
}

/* Hero : damier taxi + grille urbaine + halo ambre */
.tx-modal-hero {
    position: relative;
    overflow: hidden;
    padding: 2rem 1.6rem 1.5rem;
    /* Damier taxi (checker livery) */
    background-color: #0b0d12;
    background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24'%3E%3Crect width='12' height='12' fill='%23f59e0b' fill-opacity='.14'/%3E%3Crect x='12' y='0' width='12' height='12' fill='%23000' fill-opacity='.09'/%3E%3Crect x='0' y='12' width='12' height='12' fill='%23000' fill-opacity='.09'/%3E%3Crect x='12' y='12' width='12' height='12' fill='%23f59e0b' fill-opacity='.14'/%3E%3C/svg%3E"),
        linear-gradient(155deg, rgba(245,158,11,.1) 0%, rgba(0,0,0,0) 60%);
    background-size: 24px 24px, auto;
    border-bottom: 1px solid rgba(245,158,11,.16);
}
/* Grille route urbaine (overlay) */
.tx-modal-hero::before {
    content: '';
    position: absolute; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40'%3E%3Cline x1='40' y1='0' x2='40' y2='40' stroke='%23f59e0b' stroke-width='.4' stroke-opacity='.06'/%3E%3Cline x1='0' y1='40' x2='40' y2='40' stroke='%23f59e0b' stroke-width='.4' stroke-opacity='.06'/%3E%3C/svg%3E");
    background-size: 40px 40px;
    pointer-events: none;
    z-index: 0;
}
/* Halo radial ambre en haut à gauche */
.tx-modal-hero::after {
    content: '';
    position: absolute;
    top: -50px; left: -50px;
    width: 220px; height: 220px;
    background: radial-gradient(circle, rgba(245,158,11,.2) 0%, transparent 62%);
    pointer-events: none;
    z-index: 0;
}
.tx-modal-hero-body {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 1.1rem;
}
/* Icône taxi */
.tx-hero-cab {
    width: 58px; height: 58px;
    border-radius: 16px;
    background: linear-gradient(135deg, #fbbf24 0%, #d97706 100%);
    color: #1a0800;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.65rem;
    box-shadow:
        0 6px 24px rgba(245,158,11,.42),
        inset 0 1px 0 rgba(255,255,255,.28),
        inset 0 -2px 0 rgba(0,0,0,.22);
    flex-shrink: 0;
}
.tx-hero-label { flex: 1; min-width: 0; }
.tx-hero-label-tag {
    font-family: var(--tx-ff-m);
    font-size: .58rem;
    font-weight: 700;
    letter-spacing: .13em;
    text-transform: uppercase;
    color: var(--tx-amber);
    background: rgba(245,158,11,.1);
    border: 1px solid rgba(245,158,11,.2);
    padding: .13rem .48rem;
    border-radius: 4px;
    display: inline-block;
    margin-bottom: .4rem;
}
.tx-hero-label h2 {
    font-family: var(--tx-ff-d);
    font-size: 1.48rem;
    font-weight: 900;
    color: #fff;
    letter-spacing: .04em;
    text-transform: uppercase;
    margin: 0;
    line-height: 1.1;
    text-shadow: 0 2px 18px rgba(0,0,0,.65);
}
.tx-hero-label p {
    font-size: .72rem;
    color: var(--tx-muted);
    margin: .3rem 0 0;
    font-family: var(--tx-ff-b);
}
/* Bande damier en bas du hero */
.tx-hero-checker {
    position: relative; z-index: 1;
    height: 7px;
    margin-top: 1rem;
    border-radius: 3px;
    overflow: hidden;
    background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='7'%3E%3Crect width='7' height='7' fill='%23f59e0b' fill-opacity='.65'/%3E%3Crect x='7' y='0' width='7' height='7' fill='%23000' fill-opacity='.55'/%3E%3C/svg%3E");
    background-size: 14px 7px;
    opacity: .55;
}
</style>

<?php if ($msg): ?>
<div class="tx-alert <?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- ══ STATS ══════════════════════════════════════════════════════════════════ -->
<div class="tx-stats">
    <div class="tx-stat">
        <div class="tx-stat-ico"><i class="fas fa-id-card"></i></div>
        <div class="tx-stat-val"><?php echo $stats['total']; ?></div>
        <div class="tx-stat-lbl">Total Chauffeurs</div>
        <div class="tx-stat-sub"><?php echo $stats['actifs']; ?> actifs</div>
    </div>
    <div class="tx-stat" style="--tx-s-col:var(--tx-green);--tx-s-bg:rgba(34,197,94,.12);">
        <div class="tx-stat-ico"><i class="fas fa-circle-check"></i></div>
        <div class="tx-stat-val"><?php echo $stats['disponibles']; ?></div>
        <div class="tx-stat-lbl">Disponibles</div>
        <div class="tx-stat-sub">prêts à l'envoi</div>
    </div>
    <div class="tx-stat" style="--tx-s-col:var(--tx-amber);--tx-s-bg:var(--tx-amber-dim);">
        <div class="tx-stat-ico"><i class="fas fa-car-side"></i></div>
        <div class="tx-stat-val"><?php echo $stats['en_course']; ?></div>
        <div class="tx-stat-lbl">En Course</div>
        <div class="tx-stat-sub"><?php echo $stats['en_pause']; ?> en pause</div>
    </div>
    <div class="tx-stat" style="--tx-s-col:var(--tx-gray);--tx-s-bg:rgba(107,114,128,.1);">
        <div class="tx-stat-ico"><i class="fas fa-power-off"></i></div>
        <div class="tx-stat-val"><?php echo $stats['hors_service']; ?></div>
        <div class="tx-stat-lbl">Hors Service</div>
        <div class="tx-stat-sub">non actifs</div>
    </div>
</div>

<!-- ══ TOOLBAR ════════════════════════════════════════════════════════════════ -->
<div class="tx-toolbar">
    <form method="GET" style="display:contents;">
        <div class="tx-search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Nom, badge…" value="<?php echo htmlspecialchars($search); ?>">
        </div>

        <div class="tx-divider"></div>

        <!-- Filtre statut -->
        <div class="tx-filters">
            <?php
            $statuts = [
                '' => ['label'=>'Tous', 'col'=>null, 'bg'=>null],
                'disponible'   => ['label'=>'Disponible',   'col'=>'var(--tx-green)', 'bg'=>'rgba(34,197,94,.12)'],
                'en_course'    => ['label'=>'En course',    'col'=>'var(--tx-amber)', 'bg'=>'var(--tx-amber-dim)'],
                'pause'        => ['label'=>'Pause',        'col'=>'var(--tx-blue)',  'bg'=>'rgba(96,165,250,.12)'],
                'hors_service' => ['label'=>'Hors service', 'col'=>'var(--tx-gray)',  'bg'=>'rgba(107,114,128,.11)'],
            ];
            foreach ($statuts as $val => $info):
                $active = ($fStatut === $val) ? 'on' : '';
                $style  = $active && $info['col']
                    ? "style=\"--tx-pill-col:{$info['col']};--tx-pill-bg:{$info['bg']}\""
                    : '';
            ?>
            <a href="?q=<?php echo urlencode($search); ?>&statut=<?php echo urlencode($val); ?>&actif=<?php echo urlencode($fActif); ?>"
               class="tx-pill <?php echo $active; ?>" <?php echo $style; ?>>
                <?php if ($val): ?><span class="dot"></span><?php endif; ?>
                <?php echo $info['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="tx-divider"></div>

        <!-- Filtre actif/inactif -->
        <div class="tx-filters">
            <?php foreach (['1'=>'Actifs','0'=>'Inactifs',''=>'Tous'] as $val => $lbl): ?>
            <a href="?q=<?php echo urlencode($search); ?>&statut=<?php echo urlencode($fStatut); ?>&actif=<?php echo urlencode($val); ?>"
               class="tx-pill <?php echo ($fActif === $val) ? 'on' : ''; ?>">
               <?php echo $lbl; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </form>

    <?php if ($isChef && !empty($membres)): ?>
    <div class="tx-divider" style="margin-left:auto;"></div>
    <button onclick="TxModal.open('tx-modal-create')" class="tx-btn tx-btn-primary tx-btn-sm">
        <i class="fas fa-plus"></i> Nouveau chauffeur
    </button>
    <?php endif; ?>
</div>

<!-- ══ GRILLE CHAUFFEURS ═══════════════════════════════════════════════════════ -->
<?php if (empty($chauffeurs)): ?>
<div class="tx-empty">
    <i class="fas fa-users"></i>
    <p>Aucun chauffeur trouvé pour ces critères</p>
</div>
<?php else: ?>
<div class="tx-grid">
    <?php foreach ($chauffeurs as $ch): ?>
    <?php
        $statusLabels = ['disponible'=>'Disponible','en_course'=>'En course','pause'=>'Pause','hors_service'=>'Hors service'];
        $statusLabel  = $statusLabels[$ch['statut']] ?? ucfirst(str_replace('_',' ',$ch['statut']));
    ?>

    <div class="tx-card <?php echo htmlspecialchars($ch['statut']); ?> <?php echo !$ch['actif'] ? 'inactive' : ''; ?>">

        <!-- Tête de carte -->
        <div class="tx-card-head">
            <div class="tx-avatar"><?php echo strtoupper(mb_substr($ch['username'],0,1)); ?></div>
            <div class="tx-card-meta">
                <div class="tx-card-name"><?php echo htmlspecialchars($ch['username']); ?></div>
                <div class="tx-card-rank"><?php echo htmlspecialchars($ch['faction_rank'] ?? 'Chauffeur'); ?></div>
                <div class="tx-card-badge"><?php echo htmlspecialchars($ch['numero_badge']); ?></div>
            </div>
            <span class="tx-status <?php echo htmlspecialchars($ch['statut']); ?>">
                <span class="pulse"></span>
                <?php echo $statusLabel; ?>
            </span>
        </div>

        <!-- Stats -->
        <div class="tx-card-stats">
            <div class="tx-card-stat">
                <div class="tx-card-stat-val"><?php echo (int)$ch['nb_courses_total']; ?></div>
                <div class="tx-card-stat-lbl">Courses</div>
            </div>
            <div class="tx-card-stat">
                <div class="tx-card-stat-val">$<?php echo number_format($ch['ca_aujourd'] ?? 0, 0, ',', ' '); ?></div>
                <div class="tx-card-stat-lbl">CA Auj.</div>
            </div>
            <div class="tx-card-stat">
                <div class="tx-card-stat-val">
                    <?php if ($ch['note_moy']): ?>
                    <span class="tx-stars"><?php echo str_repeat('★', (int)round($ch['note_moy'])); ?></span>
                    <?php else: ?><span style="color:var(--tx-muted)">—</span><?php endif; ?>
                </div>
                <div class="tx-card-stat-lbl">Note</div>
            </div>
        </div>

        <!-- Véhicule -->
        <?php if ($ch['vehicule']): ?>
        <div class="tx-card-vehicle">
            <i class="fas fa-car"></i>
            <?php echo htmlspecialchars($ch['vehicule']); ?>
        </div>
        <?php endif; ?>

        <!-- Actions / Pied -->
        <div class="tx-card-foot">
            <?php if ($isChef): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="update_statut">
                <input type="hidden" name="chauffeur_id" value="<?php echo $ch['id']; ?>">
                <select name="statut" class="tx-qs" onchange="this.form.submit()">
                    <?php foreach (['disponible'=>'Disponible','pause'=>'Pause','hors_service'=>'Hors service'] as $sv => $sl): ?>
                    <option value="<?php echo $sv; ?>" <?php echo $ch['statut']===$sv?'selected':''; ?>><?php echo $sl; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div style="display:flex;gap:.35rem;align-items:center;">
                <button type="button"
                        onclick="TxModal.open('tx-edit-<?php echo $ch['id']; ?>')"
                        class="tx-btn-ico" title="Éditer">
                    <i class="fas fa-pen"></i>
                </button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_actif">
                    <input type="hidden" name="chauffeur_id" value="<?php echo $ch['id']; ?>">
                    <button type="submit"
                            class="tx-btn-ico <?php echo $ch['actif']?'x-red':'x-grn'; ?>"
                            data-confirm="<?php echo htmlspecialchars(($ch['actif'] ? 'Désactiver' : 'Activer') . ' ce chauffeur ?', ENT_QUOTES, 'UTF-8'); ?>"
                            title="<?php echo $ch['actif']?'Désactiver':'Activer'; ?>">
                        <i class="fas fa-<?php echo $ch['actif']?'ban':'circle-check'; ?>"></i>
                    </button>
                </form>
            </div>
            <?php else: ?>
            <span class="tx-status <?php echo htmlspecialchars($ch['statut']); ?>">
                <span class="pulse"></span><?php echo $statusLabel; ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Modal éditer ────────────────────────────────────────────────────── -->
    <div id="tx-edit-<?php echo $ch['id']; ?>" class="tx-ov">
        <div class="tx-modal">
            <div class="tx-modal-hd">
                <h2><i class="fas fa-pen"></i> Éditer — <?php echo htmlspecialchars($ch['username']); ?></h2>
                <button class="tx-modal-cl" onclick="TxModal.close('tx-edit-<?php echo $ch['id']; ?>')">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="chauffeur_id" value="<?php echo $ch['id']; ?>">
                <div class="tx-modal-bd">
                    <div class="tx-fg">
                        <label class="tx-lbl">Numéro de badge *</label>
                        <input type="text" name="numero_badge" class="tx-inp"
                               value="<?php echo htmlspecialchars($ch['numero_badge']); ?>" required>
                    </div>
                    <div class="tx-fg">
                        <label class="tx-lbl">Véhicule</label>
                        <input type="text" name="vehicule" class="tx-inp"
                               value="<?php echo htmlspecialchars($ch['vehicule'] ?? ''); ?>"
                               placeholder="Modèle, immatriculation…">
                    </div>
                    <div class="tx-fg">
                        <label class="tx-lbl">Date d'embauche</label>
                        <input type="date" name="date_embauche" class="tx-inp"
                               value="<?php echo htmlspecialchars($ch['date_embauche'] ?? ''); ?>">
                    </div>
                    <div class="tx-summary">
                        <div class="tx-summary-item">
                            <strong>$<?php echo number_format($ch['ca_total'] ?? 0, 0, ',', ' '); ?></strong>
                            <span>CA Total</span>
                        </div>
                        <div class="tx-summary-item">
                            <strong><?php echo (int)$ch['nb_courses_total']; ?></strong>
                            <span>Courses</span>
                        </div>
                        <?php if ($ch['note_moy']): ?>
                        <div class="tx-summary-item">
                            <strong><?php echo number_format($ch['note_moy'], 1); ?></strong>
                            <span>Note /5</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tx-modal-ft">
                    <button type="button" onclick="TxModal.close('tx-edit-<?php echo $ch['id']; ?>')"
                            class="tx-btn tx-btn-ghost tx-btn-sm">Annuler</button>
                    <button type="submit" class="tx-btn tx-btn-primary tx-btn-sm">
                        <i class="fas fa-floppy-disk"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ Modal créer chauffeur ══════════════════════════════════════════════════ -->
<?php if ($isChef && !empty($membres)): ?>
<div id="tx-modal-create" class="tx-ov">
    <div class="tx-modal tx-modal-create" style="position:relative;">

        <!-- Bouton fermer flottant -->
        <button class="tx-modal-cl tx-modal-cl-float" onclick="TxModal.close('tx-modal-create')">
            <i class="fas fa-xmark"></i>
        </button>

        <!-- ── Hero textured ── -->
        <div class="tx-modal-hero">
            <div class="tx-modal-hero-body">
                <div class="tx-hero-cab"><i class="fas fa-taxi"></i></div>
                <div class="tx-hero-label">
                    <div class="tx-hero-label-tag">Los Santos Taxi Co.</div>
                    <h2>Nouveau Chauffeur</h2>
                    <p>Enregistrement du personnel roulant</p>
                </div>
            </div>
            <div class="tx-hero-checker"></div>
        </div>

        <!-- ── Formulaire ── -->
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="tx-modal-bd">
                <div class="tx-fg">
                    <label class="tx-lbl">Membre *</label>
                    <select name="user_id" class="tx-sel" required>
                        <option value="">— Sélectionner un membre —</option>
                        <?php foreach ($membres as $m): ?>
                        <option value="<?php echo $m['id']; ?>">
                            <?php echo htmlspecialchars($m['username']); ?>
                            (<?php echo htmlspecialchars($m['faction_rank'] ?? '—'); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tx-fg">
                    <label class="tx-lbl">Numéro de badge *</label>
                    <input type="text" name="numero_badge" class="tx-inp" placeholder="ex : TX-001" required>
                </div>
                <div class="tx-fg">
                    <label class="tx-lbl">Véhicule</label>
                    <input type="text" name="vehicule" class="tx-inp" placeholder="Modèle, couleur…">
                </div>
                <div class="tx-fg">
                    <label class="tx-lbl">Date d'embauche</label>
                    <input type="date" name="date_embauche" class="tx-inp" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="tx-modal-ft">
                <button type="button" onclick="TxModal.close('tx-modal-create')"
                        class="tx-btn tx-btn-ghost tx-btn-sm">Annuler</button>
                <button type="submit" class="tx-btn tx-btn-primary tx-btn-sm">
                    <i class="fas fa-check"></i> Créer le chauffeur
                </button>
            </div>
        </form>

    </div>
</div>
<?php endif; ?>

<script>
const TxModal = {
    open(id)  {
        const el = document.getElementById(id);
        if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
    },
    close(id) {
        const el = document.getElementById(id);
        if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
    }
};
// Fermer au clic sur l'overlay
document.querySelectorAll('.tx-ov').forEach(ov =>
    ov.addEventListener('click', e => { if (e.target === ov) TxModal.close(ov.id); })
);
// Fermer avec Échap
document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.tx-ov.open').forEach(m => TxModal.close(m.id));
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>