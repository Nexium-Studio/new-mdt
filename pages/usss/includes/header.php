<?php
require_once dirname(__DIR__, 3) . '/config/panel_pages.php';
require_once dirname(__DIR__, 3) . '/config/panel_theme_auto.php';
checkPageActive('USSS');

$__usssAllPages = [
    ['file' => 'dashboard',    'label' => 'Dashboard',        'icon' => 'fas fa-chart-line',      'section' => 'OPÉRATIONS'],
    ['file' => 'agents',       'label' => 'Agents',           'icon' => 'fas fa-id-badge',         'section' => null],
    ['file' => 'divisions',    'label' => 'Divisions',        'icon' => 'fas fa-sitemap',          'section' => null],
    ['file' => 'dossiers',     'label' => 'Dossiers',         'icon' => 'fas fa-folder-open',      'section' => 'ENQUÊTES', 'cls' => 'SECRET'],
    ['file' => 'mandats',      'label' => 'Mandats',          'icon' => 'fas fa-gavel',            'section' => null],
    ['file' => 'operations',   'label' => 'Opérations',       'icon' => 'fas fa-crosshairs',       'section' => null, 'cls' => 'TS'],
    ['file' => 'intelligence', 'label' => 'Intelligence',     'icon' => 'fas fa-eye',              'section' => 'RENSEIGNEMENT', 'cls' => 'TS'],
    ['file' => 'financier',    'label' => 'Crimes Financiers','icon' => 'fas fa-money-bill-wave',  'section' => null],
    ['file' => 'academie',     'label' => 'Académie',         'icon' => 'fas fa-graduation-cap',   'section' => 'FORMATION'],
    ['file' => 'materiel',     'label' => 'Matériel',         'icon' => 'fas fa-boxes',            'section' => null],
    ['file' => 'statistiques', 'label' => 'Statistiques',     'icon' => 'fas fa-chart-bar',        'section' => 'SYSTÈME'],
    ['file' => 'messages',     'label' => 'Messages',         'icon' => 'fas fa-envelope',         'section' => null],
];
$__usssNavPages = getActiveNavItems('USSS', $__usssAllPages);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle ?? 'USSS'); ?> · USSS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@300;400;500;600;700;800;900&family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════════
   USSS DESIGN SYSTEM — Classified Intelligence Interface
   ═══════════════════════════════════════════════════════════ */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

:root {
  /* Background layers */
  --bg:        #07090f;
  --bg-c:      #0b0e18;
  --bg-e:      #0f1422;
  --bg-ov:     #141929;
  --bg-h:      #1a2035;

  /* Borders */
  --bdr:       rgba(255,255,255,.07);
  --bdr-h:     rgba(255,255,255,.14);
  --bdr-b:     rgba(30,86,219,.3);

  /* Text */
  --txt:       #d8e0ef;
  --txt-s:     #7a8aa8;
  --txt-m:     #3d4d6a;

  /* Brand colors */
  --blue:      #1a56db;
  --blue-l:    #4d80f0;
  --blue-xl:   #93b4f8;
  --gold:      #c8a84b;
  --gold-l:    #e0c06e;
  --red:       #bf1c2e;
  --red-l:     #f56270;
  --green:     #15803d;
  --green-l:   #4ade80;
  --amber:     #b45309;
  --amber-l:   #fbbf24;

  /* Sidebar */
  --sb-w:      260px;
}

html{scroll-behavior:smooth}
body {
  font-family: 'IBM Plex Sans', system-ui, sans-serif;
  background: var(--bg);
  color: var(--txt);
  min-height: 100vh;
  overflow-x: hidden;
  font-size: 14px;
  line-height: 1.55;
}

/* Background grid texture */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.018) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.018) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
  z-index: 0;
}

/* Scrollbar */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.18)}

/* ════════════════ SIDEBAR ════════════════ */
.us-sb {
  position: fixed;
  left: 0; top: 0;
  width: var(--sb-w);
  height: 100vh;
  background: linear-gradient(180deg, #0a0d18 0%, var(--bg) 100%);
  border-right: 1px solid var(--bdr);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  z-index: 1000;
  transition: transform .3s cubic-bezier(.4,0,.2,1);
}

/* Top seal / brand */
.us-brand {
  padding: 1.5rem 1.25rem 1.1rem;
  border-bottom: 1px solid var(--bdr);
  flex-shrink: 0;
}
.us-brand-inner {
  display: flex;
  align-items: center;
  gap: .85rem;
}
.us-seal {
  width: 48px; height: 48px;
  border-radius: 50%;
  border: 2px solid var(--gold);
  background: radial-gradient(circle at 40% 40%, #1a2a5e, #0a0d18);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem;
  flex-shrink: 0;
  box-shadow: 0 0 12px rgba(200,168,75,.25), inset 0 0 8px rgba(200,168,75,.1);
}
.us-brand-text {
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 800;
  font-size: 1.05rem;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--txt);
  line-height: 1.1;
}
.us-brand-sub {
  font-size: .58rem;
  color: var(--txt-m);
  letter-spacing: .15em;
  text-transform: uppercase;
  margin-top: .18rem;
}

/* Classification bar */
.us-classif {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .5rem;
  padding: .4rem 1.25rem;
  background: rgba(191,28,46,.08);
  border-bottom: 1px solid rgba(191,28,46,.2);
  font-size: .58rem;
  font-weight: 700;
  letter-spacing: .2em;
  color: var(--red-l);
  font-family: 'IBM Plex Mono', monospace;
  flex-shrink: 0;
}
.us-classif::before,.us-classif::after {
  content: '//';
  opacity: .4;
}

/* Nav */
.us-nav {
  flex: 1;
  overflow-y: auto;
  padding: .65rem 0 1rem;
}
.us-nav::-webkit-scrollbar{width:3px}
.us-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.06)}

.us-sect {
  padding: .8rem 1.25rem .3rem;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: .65rem;
  font-weight: 700;
  letter-spacing: .2em;
  color: var(--txt-m);
  text-transform: uppercase;
}

.us-link {
  display: flex;
  align-items: center;
  gap: .65rem;
  padding: .62rem 1.25rem;
  color: var(--txt-s);
  text-decoration: none;
  font-size: .84rem;
  font-weight: 400;
  transition: all .18s;
  position: relative;
  border-left: 2px solid transparent;
}
.us-link i {
  width: 16px;
  text-align: center;
  font-size: .78rem;
  flex-shrink: 0;
}
.us-link:hover {
  color: var(--txt);
  background: rgba(255,255,255,.04);
  border-left-color: rgba(255,255,255,.1);
}
.us-link.active {
  color: var(--blue-xl);
  background: rgba(26,86,219,.1);
  border-left-color: var(--blue);
  font-weight: 500;
}
.us-link.active i { color: var(--blue-l); }

.us-cls-badge {
  margin-left: auto;
  font-family: 'IBM Plex Mono', monospace;
  font-size: .5rem;
  font-weight: 600;
  letter-spacing: .08em;
  padding: .15rem .4rem;
  border-radius: 2px;
  flex-shrink: 0;
}
.us-cls-badge.ts {
  background: rgba(191,28,46,.15);
  border: 1px solid rgba(191,28,46,.35);
  color: var(--red-l);
}
.us-cls-badge.secret {
  background: rgba(180,83,9,.12);
  border: 1px solid rgba(180,83,9,.3);
  color: var(--amber-l);
}

.us-divider {
  height: 1px;
  background: var(--bdr);
  margin: .6rem 1.25rem;
}

/* User card at bottom */
.us-user {
  padding: .85rem 1.25rem 1rem;
  border-top: 1px solid var(--bdr);
  flex-shrink: 0;
  background: rgba(0,0,0,.15);
}
.us-user-inner {
  display: flex;
  align-items: center;
  gap: .65rem;
  padding: .6rem .75rem;
  background: rgba(255,255,255,.03);
  border: 1px solid var(--bdr);
  border-radius: 8px;
}
.us-ava {
  width: 34px; height: 34px;
  border-radius: 6px;
  background: linear-gradient(135deg, #1e3a8a, #1a56db);
  display: flex; align-items: center; justify-content: center;
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 700;
  font-size: 1rem;
  color: #fff;
  border: 1px solid rgba(200,168,75,.3);
  flex-shrink: 0;
}
.us-uname {
  font-size: .82rem;
  font-weight: 500;
  color: var(--txt);
  line-height: 1.2;
}
.us-urole {
  font-size: .62rem;
  color: var(--txt-m);
  text-transform: uppercase;
  letter-spacing: .1em;
}

/* ════════════════ MAIN ════════════════ */
.us-main {
  margin-left: var(--sb-w);
  min-height: 100vh;
  position: relative;
  z-index: 1;
}

/* Topbar */
.us-top {
  position: sticky;
  top: 0;
  z-index: 100;
  background: rgba(7,9,15,.95);
  backdrop-filter: blur(16px);
  border-bottom: 1px solid var(--bdr);
  padding: .9rem 1.75rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.us-top::after {
  content: '';
  position: absolute;
  bottom: 0; left: 0; right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent 0%, var(--blue) 30%, var(--blue-l) 50%, var(--blue) 70%, transparent 100%);
  opacity: .3;
}
.us-ptitle {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 1.25rem;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--txt);
  display: flex;
  align-items: center;
  gap: .65rem;
}
.us-ptitle-sep {
  color: var(--txt-m);
  font-size: .9rem;
}
.us-top-right {
  display: flex;
  align-items: center;
  gap: .55rem;
}
.us-top-chip {
  padding: .3rem .75rem;
  border-radius: 4px;
  font-size: .65rem;
  font-weight: 700;
  letter-spacing: .1em;
  font-family: 'IBM Plex Mono', monospace;
}
.us-top-chip.red {
  background: rgba(191,28,46,.1);
  border: 1px solid rgba(191,28,46,.3);
  color: var(--red-l);
}
.us-top-chip.slate {
  background: rgba(255,255,255,.04);
  border: 1px solid var(--bdr);
  color: var(--txt-s);
}
.us-toggle {
  display: none;
  padding: .45rem .6rem;
  background: rgba(255,255,255,.05);
  border: 1px solid var(--bdr);
  border-radius: 6px;
  color: var(--txt-s);
  cursor: pointer;
}

.us-content { padding: 1.5rem 1.75rem; }

/* ════════════════ REUSABLE COMPONENTS ════════════════ */

/* Cards */
.card {
  background: var(--bg-c);
  border: 1px solid var(--bdr);
  border-radius: 10px;
  padding: 1.25rem;
  margin-bottom: 1.25rem;
  transition: border-color .2s;
}
.card:hover { border-color: var(--bdr-h); }

.card-title {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: .75rem;
  font-weight: 700;
  letter-spacing: .18em;
  text-transform: uppercase;
  color: var(--txt-s);
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  gap: .5rem;
  padding-bottom: .65rem;
  border-bottom: 1px solid var(--bdr);
}
.card-title i { color: var(--blue-l); font-size: .72rem; }

/* KPI row */
.kpi-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: .85rem;
  margin-bottom: 1.25rem;
}
.kpi {
  background: var(--bg-c);
  border: 1px solid var(--bdr);
  border-radius: 10px;
  padding: 1.1rem 1.25rem;
  position: relative;
  overflow: hidden;
  transition: border-color .2s, transform .15s;
}
.kpi:hover { border-color: var(--bdr-b); transform: translateY(-1px); }
.kpi::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: var(--kpi-color, var(--blue));
  opacity: .6;
}
.kpi-ico {
  width: 30px; height: 30px;
  border-radius: 7px;
  background: var(--kpi-color, var(--blue));
  opacity: .15;
  display: flex; align-items: center; justify-content: center;
  margin-bottom: .65rem;
}
.kpi-ico-inner {
  position: absolute;
  top: 1.1rem; left: 1.25rem;
  width: 30px; height: 30px;
  display: flex; align-items: center; justify-content: center;
  font-size: .82rem;
  color: var(--kpi-color, var(--blue));
}
.kpi-lbl {
  font-size: .62rem;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--txt-m);
  margin-bottom: .2rem;
  font-family: 'Barlow Condensed', sans-serif;
}
.kpi-val {
  font-family: 'IBM Plex Mono', monospace;
  font-size: 1.55rem;
  font-weight: 600;
  color: var(--txt);
  line-height: 1;
}
.kpi-sub {
  font-size: .68rem;
  color: var(--txt-m);
  margin-top: .2rem;
}

/* Table */
.tbl-wrap {
  background: var(--bg-c);
  border: 1px solid var(--bdr);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 1.25rem;
}
.tbl {
  width: 100%;
  border-collapse: collapse;
}
.tbl th {
  text-align: left;
  padding: .65rem 1rem;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: .65rem;
  font-weight: 700;
  letter-spacing: .15em;
  text-transform: uppercase;
  color: var(--txt-m);
  background: rgba(255,255,255,.025);
  border-bottom: 1px solid var(--bdr);
  white-space: nowrap;
}
.tbl td {
  padding: .65rem 1rem;
  font-size: .82rem;
  border-bottom: 1px solid rgba(255,255,255,.04);
  vertical-align: middle;
}
.tbl tr:last-child td { border-bottom: none; }
.tbl tr:hover td { background: rgba(255,255,255,.02); }
.tbl td a { color: var(--blue-xl); text-decoration: none; }
.tbl td a:hover { text-decoration: underline; }

/* Mono ref codes */
.ref {
  font-family: 'IBM Plex Mono', monospace;
  font-size: .72rem;
  color: var(--blue-xl);
  letter-spacing: .04em;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  padding: .55rem 1rem;
  border-radius: 7px;
  font-weight: 500;
  font-size: .8rem;
  cursor: pointer;
  transition: all .15s;
  text-decoration: none;
  border: 1px solid transparent;
  font-family: 'IBM Plex Sans', sans-serif;
  white-space: nowrap;
}
.btn-primary {
  background: var(--blue);
  color: #fff;
  border-color: var(--blue);
  box-shadow: 0 2px 8px rgba(26,86,219,.3);
}
.btn-primary:hover { background: #1e64f5; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(26,86,219,.4); }
.btn-ghost {
  background: transparent;
  color: var(--txt-s);
  border-color: var(--bdr);
}
.btn-ghost:hover { background: rgba(255,255,255,.05); color: var(--txt); border-color: var(--bdr-h); }
.btn-danger {
  background: rgba(191,28,46,.12);
  color: var(--red-l);
  border-color: rgba(191,28,46,.3);
}
.btn-danger:hover { background: rgba(191,28,46,.22); }
.btn-success {
  background: rgba(21,128,61,.12);
  color: var(--green-l);
  border-color: rgba(21,128,61,.3);
}
.btn-success:hover { background: rgba(21,128,61,.22); }
.btn-amber {
  background: rgba(180,83,9,.12);
  color: var(--amber-l);
  border-color: rgba(180,83,9,.3);
}
.btn-sm { padding: .32rem .72rem; font-size: .74rem; }
.btn-xs { padding: .2rem .52rem; font-size: .68rem; }

/* Badges */
.badge {
  display: inline-flex;
  align-items: center;
  gap: .22rem;
  padding: .18rem .52rem;
  border-radius: 4px;
  font-size: .62rem;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  white-space: nowrap;
  font-family: 'Barlow Condensed', sans-serif;
}
.b-blue   { background: rgba(26,86,219,.12);  color: var(--blue-xl);  border: 1px solid rgba(26,86,219,.25); }
.b-red    { background: rgba(191,28,46,.12);   color: var(--red-l);    border: 1px solid rgba(191,28,46,.3); }
.b-green  { background: rgba(21,128,61,.1);    color: var(--green-l);  border: 1px solid rgba(21,128,61,.25); }
.b-amber  { background: rgba(180,83,9,.1);     color: var(--amber-l);  border: 1px solid rgba(180,83,9,.25); }
.b-gold   { background: rgba(200,168,75,.1);   color: var(--gold-l);   border: 1px solid rgba(200,168,75,.3); }
.b-slate  { background: rgba(255,255,255,.06); color: var(--txt-s);    border: 1px solid var(--bdr); }
.b-purple { background: rgba(124,58,237,.1);   color: #c4b5fd;         border: 1px solid rgba(124,58,237,.25); }

/* Status dot */
.sdot {
  display: inline-block;
  width: 7px; height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.sdot.active { background: var(--green-l); box-shadow: 0 0 6px var(--green-l); animation: sdotPulse 2s infinite; }
.sdot.inactive { background: var(--txt-m); }
.sdot.warn { background: var(--amber-l); box-shadow: 0 0 6px var(--amber-l); }
.sdot.danger { background: var(--red-l); box-shadow: 0 0 6px var(--red-l); animation: sdotPulse 1.5s infinite; }
@keyframes sdotPulse { 0%,100%{opacity:1} 50%{opacity:.45} }

/* Forms */
.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .85rem; }
.form-full { grid-column: 1/-1; }
.form-group { display: flex; flex-direction: column; gap: .28rem; }
.form-label {
  font-size: .64rem;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--txt-m);
  font-family: 'Barlow Condensed', sans-serif;
}
.form-input,.form-select,.form-textarea {
  width: 100%;
  padding: .6rem .85rem;
  background: rgba(0,0,0,.3);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 7px;
  color: var(--txt);
  font-size: .82rem;
  font-family: 'IBM Plex Sans', inherit;
  transition: border-color .15s, box-shadow .15s;
}
.form-input:focus,.form-select:focus,.form-textarea:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(26,86,219,.12);
}
.form-input::placeholder { color: var(--txt-m); }
.form-select option { background: #0f1422; }
.form-textarea { resize: vertical; min-height: 90px; }

/* Alert */
.alert {
  padding: .75rem 1rem;
  border-radius: 8px;
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  gap: .6rem;
  font-size: .82rem;
  font-weight: 500;
}
.alert-success { background: rgba(21,128,61,.08); border: 1px solid rgba(21,128,61,.22); color: var(--green-l); }
.alert-error   { background: rgba(191,28,46,.08); border: 1px solid rgba(191,28,46,.22); color: var(--red-l); }
.alert-info    { background: rgba(26,86,219,.08);  border: 1px solid rgba(26,86,219,.22); color: var(--blue-xl); }
.alert-amber   { background: rgba(180,83,9,.08);  border: 1px solid rgba(180,83,9,.22);  color: var(--amber-l); }

/* Modals */
.modal-ov {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.82);
  backdrop-filter: blur(8px);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
}
.modal-ov.hidden { display: none; }
.modal {
  background: linear-gradient(140deg, var(--bg-e), var(--bg));
  border: 1px solid rgba(26,86,219,.25);
  border-radius: 12px;
  box-shadow: 0 0 80px rgba(0,0,0,.7), 0 0 30px rgba(26,86,219,.06);
  width: 90%;
  max-width: 640px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.modal-sm { max-width: 440px; }
.modal-lg { max-width: 860px; }
.modal-xl { max-width: 1100px; }
.modal-hd {
  padding: 1.1rem 1.4rem;
  border-bottom: 1px solid var(--bdr);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: rgba(26,86,219,.04);
  flex-shrink: 0;
}
.modal-hd h2 {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: .95rem;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--txt);
  display: flex;
  align-items: center;
  gap: .55rem;
}
.modal-hd h2 i { color: var(--blue-l); font-size: .8rem; }
.modal-cl {
  width: 28px; height: 28px;
  border-radius: 6px;
  background: rgba(255,255,255,.05);
  border: 1px solid var(--bdr);
  color: var(--txt-s);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem;
  transition: all .15s;
}
.modal-cl:hover { background: rgba(191,28,46,.12); color: var(--red-l); }
.modal-bd { padding: 1.35rem 1.4rem; overflow-y: auto; flex: 1; }
.modal-ft {
  padding: .85rem 1.4rem;
  border-top: 1px solid var(--bdr);
  background: rgba(0,0,0,.15);
  display: flex;
  justify-content: flex-end;
  gap: .5rem;
  flex-shrink: 0;
}

/* Classification stamp */
.cls-stamp {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  padding: .22rem .7rem;
  font-family: 'IBM Plex Mono', monospace;
  font-size: .62rem;
  font-weight: 600;
  letter-spacing: .15em;
  border-radius: 3px;
  text-transform: uppercase;
}
.cls-ts     { background: rgba(191,28,46,.1);   border: 1px solid rgba(191,28,46,.4);   color: var(--red-l); }
.cls-secret { background: rgba(180,83,9,.1);    border: 1px solid rgba(180,83,9,.4);    color: var(--amber-l); }
.cls-conf   { background: rgba(26,86,219,.1);   border: 1px solid rgba(26,86,219,.35);  color: var(--blue-xl); }
.cls-unrestr{ background: rgba(21,128,61,.08);  border: 1px solid rgba(21,128,61,.3);   color: var(--green-l); }

/* Alert level banner */
.alerte-banner {
  padding: .85rem 1.25rem;
  border-radius: 9px;
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1.35rem;
  border: 1px solid var(--ab-color, rgba(21,128,61,.3));
  background: rgba(var(--ab-rgb, 21,128,61), .08);
}
.alerte-banner .ab-dot {
  width: 12px; height: 12px;
  border-radius: 50%;
  background: var(--ab-color, var(--green-l));
  box-shadow: 0 0 10px var(--ab-color, var(--green-l));
  flex-shrink: 0;
  animation: sdotPulse 2s infinite;
}
.alerte-banner .ab-level {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: .75rem;
  font-weight: 700;
  letter-spacing: .18em;
  color: var(--ab-color, var(--green-l));
  text-transform: uppercase;
}
.alerte-banner .ab-desc { font-size: .8rem; color: var(--txt-s); }

/* Grid layouts */
.grid2 { display: grid; grid-template-columns: repeat(2,1fr); gap: 1.25rem; }
.grid3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }

/* Section header */
.sec-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
  padding-bottom: .65rem;
  border-bottom: 1px solid var(--bdr);
}
.sec-title {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: .72rem;
  font-weight: 700;
  letter-spacing: .18em;
  text-transform: uppercase;
  color: var(--txt-s);
}

/* Progress bar */
.prog-bar {
  height: 4px;
  background: rgba(255,255,255,.06);
  border-radius: 2px;
  overflow: hidden;
  margin-top: .4rem;
}
.prog-fill {
  height: 100%;
  border-radius: 2px;
  background: var(--blue);
  transition: width .4s ease;
}

/* Pagination */
.pag { display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; margin-top: .65rem; }
.pag a, .pag span {
  padding: .32rem .65rem;
  border-radius: 6px;
  font-size: .75rem;
  border: 1px solid var(--bdr);
  background: var(--bg-c);
  color: var(--txt-s);
  text-decoration: none;
  transition: all .14s;
}
.pag a:hover { background: var(--bg-h); color: var(--txt); }
.pag .pag-cur { background: var(--blue); border-color: var(--blue); color: #fff; }

/* mono utility */
.mono { font-family: 'IBM Plex Mono', monospace; }
.hidden { display: none !important; }

@media (max-width: 1024px) {
  .us-sb { transform: translateX(-100%); }
  .us-sb.show { transform: translateX(0); }
  .us-main { margin-left: 0; }
  .us-toggle { display: flex; }
  .form-grid { grid-template-columns: 1fr; }
  .grid2,.grid3 { grid-template-columns: 1fr; }
}
</style>
<?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body>
<?php
$__currentFile   = basename($_SERVER['PHP_SELF'], '.php');
$__shownSections = [];
?>

<!-- ══ SIDEBAR ══ -->
<aside class="us-sb" id="usSidebar">
  <div class="us-brand">
    <div class="us-brand-inner">
      <div class="us-seal">🛡️</div>
      <div>
        <div class="us-brand-text"><?php echo htmlspecialchars($__panelName ?? 'USSS'); ?></div>
        <div class="us-brand-sub"><?php echo htmlspecialchars($__panelSubtitle ?? 'United States Secret Service'); ?></div>
      </div>
    </div>
  </div>
  <div class="us-classif">TOP SECRET // SCI</div>

  <nav class="us-nav">
    <?php foreach ($__usssNavPages as $p):
      $active = ($__currentFile === $p['file']) ? 'active' : '';
      $sect   = $p['section'] ?? null;
      if ($sect && !in_array($sect, $__shownSections)) {
        $__shownSections[] = $sect;
    ?>
    <div class="us-sect"><?php echo htmlspecialchars($sect); ?></div>
    <?php } ?>
    <a href="<?php echo SITE_URL; ?>/pages/usss/<?php echo $p['file']; ?>.php" class="us-link <?php echo $active; ?>">
      <i class="<?php echo htmlspecialchars($p['icon']); ?>"></i>
      <span><?php echo htmlspecialchars($p['label']); ?></span>
      <?php if (!empty($p['cls'])): ?>
        <span class="us-cls-badge <?php echo strtolower($p['cls'])==='ts'?'ts':'secret'; ?>">
          <?php echo $p['cls']; ?>
        </span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="us-divider"></div>
    <?php if (hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])): ?>
    <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php" class="us-link">
      <i class="fas fa-layer-group"></i><span>Panel Staff</span>
    </a>
    <?php endif; ?>
    <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="us-link">
      <i class="fas fa-home"></i><span>Accueil MDT</span>
    </a>
    <a href="<?php echo SITE_URL; ?>/logout.php" class="us-link">
      <i class="fas fa-sign-out-alt"></i><span>Déconnexion</span>
    </a>
  </nav>

  <div class="us-user">
    <div class="us-user-inner">
      <div class="us-ava"><?php echo htmlspecialchars(mdt_session_display_initial()); ?></div>
      <div>
        <div class="us-uname"><?php echo htmlspecialchars(mdt_session_display_name() ?: 'Agent'); ?></div>
        <div class="us-urole">USSS · Agent</div>
      </div>
    </div>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<main class="us-main">
  <div class="us-top">
    <div style="display:flex;align-items:center;gap:.75rem">
      <button class="us-toggle" onclick="document.getElementById('usSidebar').classList.toggle('show')">
        <i class="fas fa-bars"></i>
      </button>
      <h1 class="us-ptitle">
        <i class="fas fa-shield-halved" style="color:var(--blue-l);font-size:.9rem"></i>
        <span class="us-ptitle-sep">USSS</span>
        <span style="color:var(--txt-s);font-weight:300">—</span>
        <?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?>
      </h1>
    </div>
    <div class="us-top-right">
      <span class="us-top-chip slate"><?php echo date('d.m.Y · H:i'); ?></span>
      <span class="us-top-chip red">🔒 TOP SECRET</span>
    </div>
  </div>
  <div class="us-content">