<?php
/**
 * Styles de page partagés du panel LSFD
 * pages/panels/lsfd/includes/ui.php — inclus par includes/header.php (début de body).
 *
 * Fournit les classes réutilisables par tous les modules : cartes, KPI, tableaux,
 * formulaires, badges, en-têtes de section. Accent piloté par --panel-color (#dc2626).
 */
?>
<style id="lsfd-ui">
/* ── En-tête de page / section ───────────────────────────────────────────── */
.lf-pagehead { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.lf-pagehead .lf-ph-title { font-family:'Syne',sans-serif; font-size:1.3rem; font-weight:800; color:#e8edf5; display:flex; align-items:center; gap:.7rem; }
.lf-pagehead .lf-ph-title i { color:var(--panel-color,#dc2626); }
.lf-pagehead .lf-ph-sub { font-size:.82rem; color:#94a3b8; margin-top:.2rem; }
.lf-sec-title { font-family:'Syne',sans-serif; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:2px; color:#64748b; margin:1.75rem 0 .85rem; }

/* ── Cartes ──────────────────────────────────────────────────────────────── */
.lf-card { background:rgba(15,18,28,.6); border:1px solid rgba(100,116,139,.14); border-radius:14px; padding:1.5rem; margin-bottom:1.25rem; }
.lf-card-h { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.1rem; }
.lf-card-h h3 { font-family:'Syne',sans-serif; font-size:1rem; font-weight:700; color:#e8edf5; display:flex; align-items:center; gap:.6rem; }
.lf-card-h h3 i { color:var(--panel-color,#dc2626); }

/* ── Grille KPI ──────────────────────────────────────────────────────────── */
.lf-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.lf-kpi { background:rgba(15,18,28,.6); border:1px solid rgba(100,116,139,.14); border-radius:14px; padding:1.3rem 1.4rem; position:relative; overflow:hidden; }
.lf-kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:linear-gradient(180deg,var(--panel-color,#dc2626),transparent); }
.lf-kpi .lf-kpi-lbl { font-size:.72rem; text-transform:uppercase; letter-spacing:1px; color:#64748b; font-weight:600; }
.lf-kpi .lf-kpi-val { font-family:'Syne',sans-serif; font-size:1.9rem; font-weight:800; color:#e8edf5; margin-top:.35rem; line-height:1; }
.lf-kpi .lf-kpi-ico { position:absolute; right:1rem; top:1rem; font-size:1.4rem; color:rgba(var(--panel-color-rgb,220,38,38),.5); }

/* ── Tableaux ────────────────────────────────────────────────────────────── */
.lf-table { width:100%; border-collapse:collapse; font-size:.86rem; }
.lf-table thead th { text-align:left; padding:.7rem .9rem; font-family:'Syne',sans-serif; font-size:.66rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:#64748b; border-bottom:1px solid rgba(100,116,139,.18); }
.lf-table tbody td { padding:.8rem .9rem; color:#cbd5e1; border-bottom:1px solid rgba(100,116,139,.08); }
.lf-table tbody tr:hover { background:rgba(var(--panel-color-rgb,220,38,38),.05); }
.lf-table .lf-empty-row td { text-align:center; color:#64748b; padding:2.2rem 1rem; }

/* ── Badges ──────────────────────────────────────────────────────────────── */
.lf-badge { display:inline-flex; align-items:center; gap:.35rem; padding:.28rem .65rem; border-radius:20px; font-size:.72rem; font-weight:600; line-height:1;
  background:rgba(100,116,139,.14); color:#cbd5e1; border:1px solid rgba(100,116,139,.2); }
.lf-badge.red { background:rgba(220,38,38,.14); color:#fca5a5; border-color:rgba(220,38,38,.3); }
.lf-badge.orange { background:rgba(249,115,22,.14); color:#fdba74; border-color:rgba(249,115,22,.3); }
.lf-badge.yellow { background:rgba(234,179,8,.14); color:#fde047; border-color:rgba(234,179,8,.3); }
.lf-badge.green { background:rgba(34,197,94,.14); color:#86efac; border-color:rgba(34,197,94,.3); }
.lf-badge.blue { background:rgba(59,130,246,.14); color:#93c5fd; border-color:rgba(59,130,246,.3); }
.lf-badge.purple { background:rgba(139,92,246,.14); color:#c4b5fd; border-color:rgba(139,92,246,.3); }
.lf-badge.gray { background:rgba(100,116,139,.14); color:#cbd5e1; border-color:rgba(100,116,139,.25); }

/* ── Formulaires ─────────────────────────────────────────────────────────── */
.lf-field { margin-bottom:1rem; }
.lf-field label { display:block; font-size:.76rem; font-weight:600; color:#94a3b8; margin-bottom:.4rem; text-transform:uppercase; letter-spacing:.5px; }
.lf-input, .lf-textarea { width:100%; padding:.65rem .9rem; background:#0d1018; border:1px solid rgba(100,116,139,.2); border-radius:8px;
  color:#f1f5f9; font-family:'DM Sans',sans-serif; font-size:.9rem; outline:none; transition:all .15s; }
.lf-input:focus, .lf-textarea:focus { border-color:var(--panel-color,#dc2626); box-shadow:0 0 0 3px rgba(var(--panel-color-rgb,220,38,38),.12); }
.lf-textarea { resize:vertical; min-height:90px; line-height:1.5; }
.lf-grid-2 { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1rem; }

/* ── Overlay modal de page (formulaires create/edit) ─────────────────────── */
.lf-modal-ov { position:fixed; inset:0; background:rgba(5,6,12,.88); backdrop-filter:blur(8px); z-index:9000; display:none; align-items:flex-start; justify-content:center; padding:3rem 1.5rem; overflow-y:auto; }
.lf-modal-ov.open { display:flex; }
.lf-modal { background:linear-gradient(135deg,#141824,#0a0c14); border:1px solid rgba(var(--panel-color-rgb,220,38,38),.35); border-radius:16px; width:100%; max-width:680px; box-shadow:0 30px 90px rgba(0,0,0,.6); }
.lf-modal-h { padding:1.2rem 1.5rem; border-bottom:1px solid rgba(100,116,139,.14); display:flex; align-items:center; justify-content:space-between; }
.lf-modal-h h3 { font-family:'Syne',sans-serif; font-size:1.05rem; font-weight:800; color:#e8edf5; }
.lf-modal-b { padding:1.5rem; }
.lf-modal-f { padding:1.1rem 1.5rem; border-top:1px solid rgba(100,116,139,.14); display:flex; justify-content:flex-end; gap:.6rem; }
.lf-x { width:34px; height:34px; border-radius:9px; background:rgba(100,116,139,.1); border:1px solid rgba(100,116,139,.2); color:#94a3b8; cursor:pointer; display:grid; place-items:center; font-size:1.1rem; }
.lf-x:hover { color:#f1f5f9; }

/* ── Liste vide ──────────────────────────────────────────────────────────── */
.lf-empty { text-align:center; padding:3rem 1rem; color:#64748b; }
.lf-empty i { font-size:2.5rem; opacity:.3; display:block; margin-bottom:.8rem; }
</style>
<?php
/**
 * Helpers PHP partagés du panel LSFD (chargés une fois).
 */
if (!function_exists('lsfd_log')) {
    /** Journalise une action dans lsfd_activity_logs (silencieux si table absente). */
    function lsfd_log(string $actionType, string $resourceType = '', $resourceId = null, string $description = ''): void {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("INSERT INTO lsfd_activity_logs (user_id, action_type, resource_type, resource_id, description) VALUES (?,?,?,?,?)");
            $st->execute([$_SESSION['user_id'] ?? null, $actionType, $resourceType, $resourceId, mb_substr($description, 0, 500)]);
        } catch (Exception $e) { /* table absente : on ignore */ }
    }
}
if (!function_exists('lsfd_grades')) {
    /** Grades LSFD ordonnés (rank_order ASC = plus haut en premier). [] si DB absente. */
    function lsfd_grades(): array {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("SELECT fg.name, fg.rank_order FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id WHERE f.faction_key='LSFD' ORDER BY fg.rank_order ASC");
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { return []; }
    }
}
