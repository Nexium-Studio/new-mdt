<?php
/**
 * LSSD — Prises & fins de service
 *
 * Interface complète de gestion des services :
 *  - Prise de service (modale avec véhicule, zone, notes)
 *  - Affichage des agents en service sous forme de cartes
 *  - Gestion dynamique des binômes (unicité par agent)
 *  - Fin de service (modale notes)
 *  - Historique personnel
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
requireLogin();

require_once __DIR__ . '/includes/service_api.php';

if (!lssd_service_access_ok()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Prises de Service';
$db = Database::getInstance()->getConnection();
lssd_service_ensure_schema($db);

$uid = (int) ($_SESSION['user_id'] ?? 0);
$csrf = lssd_service_ensure_csrf();
$canStart = lssd_service_can_start();

$zones = lssd_service_zones();

// Véhicules de patrouille depuis la table lssd_dispatch_patrol_vehicles.
$vehicles = [];
try {
    $vehicles = $db->query('SELECT id, name FROM lssd_dispatch_patrol_vehicles ORDER BY position ASC, name ASC')
                   ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $vehicles = [];
}

$officers = lssd_service_list_active($db);
$myShift = null;
foreach ($officers as $o) {
    if ((int) $o['user_id'] === $uid) {
        $myShift = $o;
        break;
    }
}

$history = lssd_service_history($db, $uid, 20);
$stats   = lssd_service_stats($db, $uid);
$season  = lssd_service_season($db, $uid); // saison en cours vs total global
$nowTs   = lssd_service_now_ts($db);

/** Formate une durée en minutes en libellé lisible "Xh YYm" ou "YYmin". */
$fmtDur = static function (int $m): string {
    $m = max(0, $m);
    if ($m < 60) {
        return $m . ' min';
    }
    return intdiv($m, 60) . 'h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
};

/** Libellé "Mois Année" en français (indépendant de la locale du serveur). */
$fmtMonthFr = static function (int $ts): string {
    static $months = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars',       4 => 'Avril',
        5 => 'Mai',     6 => 'Juin',    7 => 'Juillet',    8 => 'Août',
        9 => 'Septembre',10 => 'Octobre',11 => 'Novembre',12 => 'Décembre',
    ];
    $m = (int) date('n', $ts);
    $y = date('Y', $ts);
    return ($months[$m] ?? '') . ' ' . $y;
};

include __DIR__ . '/includes/header.php';
?>
<style>
.sv-hero{background:linear-gradient(135deg,rgba(16,185,129,.08),rgba(139,92,246,.05));border:1px solid rgba(16,185,129,.22);border-radius:14px;padding:1.4rem 1.5rem;margin-bottom:1.35rem;display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap}
.sv-hero.on{background:linear-gradient(135deg,rgba(16,185,129,.08),rgba(16,185,129,.04));border-color:rgba(16,185,129,.3)}
.sv-hero-ico{width:58px;height:58px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;background:rgba(16,185,129,.12);color:var(--blue-l);border:2px solid rgba(16,185,129,.25);flex-shrink:0}
.sv-hero.on .sv-hero-ico{background:rgba(16,185,129,.12);color:#6ee7b7;border-color:rgba(16,185,129,.35);box-shadow:0 0 0 6px rgba(16,185,129,.07)}
.sv-hero-body{flex:1;min-width:240px}
.sv-hero-title{font-family:'Syne',serif;font-size:1.05rem;font-weight:800;color:#fff;margin-bottom:.18rem}
.sv-hero-sub{font-size:.79rem;color:var(--txt-s)}
.sv-hero-timer{font-family:'IBM Plex Mono',monospace;font-size:1.85rem;font-weight:800;color:#6ee7b7;letter-spacing:.04em;line-height:1}
.sv-hero-actions{display:flex;gap:.55rem;flex-wrap:wrap}

.agent-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1rem}
.agent-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:13px;padding:1rem 1.05rem 1.05rem;position:relative;transition:border-color .18s,transform .18s}
.agent-card:hover{border-color:var(--bdr-h);transform:translateY(-1px)}
.agent-card.is-me{border-color:rgba(16,185,129,.35);box-shadow:0 0 0 1px rgba(16,185,129,.15)}
.agent-card-head{display:flex;align-items:center;gap:.7rem;margin-bottom:.7rem}
.agent-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,rgba(16,185,129,.22),rgba(16,185,129,.08));color:var(--blue-xl);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem;flex-shrink:0;border:1px solid rgba(16,185,129,.3)}
.agent-card.is-me .agent-avatar{background:linear-gradient(135deg,rgba(16,185,129,.28),rgba(16,185,129,.08));color:#6ee7b7;border-color:rgba(16,185,129,.35)}
.agent-id{flex:1;min-width:0}
.agent-name{font-weight:700;font-size:.92rem;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.agent-rank{font-size:.67rem;color:var(--txt-s);text-transform:uppercase;letter-spacing:.08em;margin-top:.12rem}
.agent-pulse{width:9px;height:9px;border-radius:50%;background:#4ade80;box-shadow:0 0 10px rgba(74,222,128,.6);animation:sv-pulse 1.8s infinite ease-in-out;flex-shrink:0}
@keyframes sv-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.55;transform:scale(1.25)}}
.agent-meta{display:flex;flex-direction:column;gap:.35rem;margin-bottom:.7rem;font-size:.77rem}
.agent-meta-row{display:flex;align-items:center;gap:.5rem;color:var(--txt-s)}
.agent-meta-row i{width:14px;color:var(--blue-l);font-size:.76rem;text-align:center}
.agent-meta-row strong{color:var(--txt);font-weight:600}
.agent-notes{background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;padding:.5rem .65rem;font-size:.75rem;color:var(--txt-s);margin-bottom:.7rem;line-height:1.45;white-space:pre-wrap;word-wrap:break-word;max-height:6.5em;overflow:auto}
.agent-partners-lbl{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.35rem;display:flex;align-items:center;gap:.35rem}
.agent-partners-lbl i{color:var(--blue-l);font-size:.7rem}
.agent-partners{display:flex;flex-wrap:wrap;gap:.3rem;margin-bottom:.75rem;min-height:1.9rem}
.agent-partner-chip{display:inline-flex;align-items:center;gap:.32rem;padding:.22rem .55rem;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.22);border-radius:14px;font-size:.7rem;color:var(--blue-xl);font-weight:600}
.agent-partner-chip i{font-size:.6rem;opacity:.8}
.agent-partners-empty{font-size:.72rem;color:var(--txt-m);font-style:italic}
.agent-actions{display:flex;gap:.4rem;flex-wrap:wrap}
.agent-timer{font-family:'IBM Plex Mono',monospace;font-size:.78rem;font-weight:700;color:#6ee7b7;background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:6px;padding:.16rem .55rem;margin-left:auto}

.empty-panel{text-align:center;padding:3.2rem 1.4rem;color:var(--txt-m)}
.empty-panel i{font-size:3.2rem;display:block;margin-bottom:.9rem;opacity:.22}
.empty-panel .ep-title{font-size:.92rem;font-weight:700;color:var(--txt-s);margin-bottom:.2rem}
.empty-panel .ep-sub{font-size:.78rem}

.hist-list{display:flex;flex-direction:column;gap:.5rem}
.hist-item{background:var(--bg-c);border:1px solid var(--bdr);border-radius:10px;padding:.75rem .9rem;transition:border-color .18s}
.hist-item:hover{border-color:var(--bdr-h)}
.hist-head{display:flex;align-items:center;gap:.55rem;margin-bottom:.35rem}
.hist-date{font-weight:700;font-size:.82rem;color:#fff}
.hist-time{font-size:.68rem;color:var(--txt-s);font-family:'IBM Plex Mono',monospace}
.hist-dur{margin-left:auto;font-family:'IBM Plex Mono',monospace;font-size:.75rem;font-weight:700;color:#6ee7b7;background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:6px;padding:.14rem .45rem}
.hist-meta{font-size:.72rem;color:var(--txt-s);display:flex;flex-wrap:wrap;gap:.7rem;margin-bottom:.3rem}
.hist-meta span{display:inline-flex;align-items:center;gap:.3rem}
.hist-meta i{color:var(--blue-l);font-size:.7rem}
.hist-notes{font-size:.72rem;color:var(--txt-m);line-height:1.45;margin-top:.25rem;white-space:pre-wrap;max-height:4.5em;overflow:auto}
.hist-partners{display:flex;flex-wrap:wrap;gap:.22rem;margin-top:.3rem}
.hist-partner-chip{padding:.1rem .42rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:10px;font-size:.64rem;color:var(--txt-s)}

.sv-columns{display:grid;grid-template-columns:1.65fr 1fr;gap:1.35rem}
@media(max-width:1100px){.sv-columns{grid-template-columns:1fr}}

.partner-picker{display:flex;flex-direction:column;gap:.5rem;max-height:46vh;overflow:auto;padding-right:.25rem}
.partner-option{display:flex;align-items:center;gap:.65rem;padding:.65rem .8rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:10px;cursor:pointer;transition:all .15s;user-select:none}
.partner-option:hover{border-color:var(--bdr-h);background:rgba(16,185,129,.05)}
.partner-option.selected{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.35)}
.partner-option input{accent-color:#10b981}
.partner-option .po-name{flex:1;font-weight:600;font-size:.85rem}
.partner-option .po-rank{font-size:.66rem;color:var(--txt-s);text-transform:uppercase;letter-spacing:.08em;margin-top:.1rem}
.partner-option .po-busy{font-size:.62rem;color:#fde68a;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.22);padding:.13rem .42rem;border-radius:9px;white-space:nowrap}
.partner-search{margin-bottom:.8rem}
.partner-summary{margin-top:.55rem;padding:.55rem .7rem;border:1px dashed var(--bdr);border-radius:9px;font-size:.73rem;color:var(--txt-s);background:var(--bg-ov)}

/* ── Stats ──────────────────────────────────────────────────────── */
.sv-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.8rem;margin-bottom:1.35rem}
@media(max-width:900px){.sv-stats{grid-template-columns:repeat(2,1fr)}}
.sv-stat{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1rem 1.1rem;position:relative;overflow:hidden;transition:border-color .2s,transform .2s}
.sv-stat:hover{border-color:var(--bdr-h);transform:translateY(-1px)}
.sv-stat::after{content:'';position:absolute;top:-20px;right:-20px;width:70px;height:70px;border-radius:50%;background:radial-gradient(circle,rgba(16,185,129,.1) 0%,transparent 70%);pointer-events:none}
.sv-stat.accent-green::after{background:radial-gradient(circle,rgba(16,185,129,.12) 0%,transparent 70%)}
.sv-stat.accent-purple::after{background:radial-gradient(circle,rgba(139,92,246,.12) 0%,transparent 70%)}
.sv-stat.accent-yellow::after{background:radial-gradient(circle,rgba(245,158,11,.12) 0%,transparent 70%)}
.sv-stat-ico{width:32px;height:32px;border-radius:9px;background:rgba(16,185,129,.12);color:var(--blue-l);display:flex;align-items:center;justify-content:center;font-size:.85rem;margin-bottom:.7rem}
.sv-stat.accent-green .sv-stat-ico{background:rgba(16,185,129,.12);color:#6ee7b7}
.sv-stat.accent-purple .sv-stat-ico{background:rgba(139,92,246,.12);color:#c4b5fd}
.sv-stat.accent-yellow .sv-stat-ico{background:rgba(245,158,11,.12);color:#fde68a}
.sv-stat-lbl{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.25rem}
.sv-stat-val{font-family:'IBM Plex Mono',monospace;font-size:1.35rem;font-weight:800;color:#f9fafb;line-height:1;letter-spacing:.02em}
.sv-stat-sub{font-size:.67rem;color:var(--txt-m);margin-top:.35rem}
.sv-stat-live{display:inline-flex;align-items:center;gap:.3rem;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6ee7b7;margin-top:.45rem}
.sv-stat-live::before{content:'';width:6px;height:6px;border-radius:50%;background:#4ade80;box-shadow:0 0 6px #4ade80;animation:sv-pulse 1.6s infinite ease-in-out}

.sv-zones{display:flex;flex-direction:column;gap:.5rem}
.sv-zone-row{display:flex;align-items:center;gap:.7rem;font-size:.78rem}
.sv-zone-lbl{width:120px;flex-shrink:0;color:var(--txt);font-weight:600}
.sv-zone-bar{flex:1;height:6px;background:rgba(16,185,129,.08);border-radius:4px;overflow:hidden}
.sv-zone-bar-fill{height:100%;background:linear-gradient(90deg,var(--blue),var(--blue-l));border-radius:4px;transition:width .35s}
.sv-zone-val{font-family:'IBM Plex Mono',monospace;font-size:.74rem;color:var(--txt-s);font-weight:600;min-width:60px;text-align:right}
</style>

<?php if (!empty($myShift)):
    // Toujours un Unix timestamp fiable calculé côté MySQL.
    $shiftStartTs = (int) $myShift['shift_start_ts'];
?>
<div class="sv-hero on" id="svHero">
    <div class="sv-hero-ico"><i class="fas fa-shield-halved"></i></div>
    <div class="sv-hero-body">
        <div class="sv-hero-title">En service depuis <?php echo date('H:i', $shiftStartTs); ?></div>
        <div class="sv-hero-sub" id="svHeroSub">
            <?php echo htmlspecialchars((string) $myShift['patrol_zone']); ?>
            · <?php echo htmlspecialchars((string) $myShift['patrol_vehicle']); ?>
            · Binômes : <strong id="svHeroPartners"><?php echo count($myShift['partners']); ?></strong>
        </div>
    </div>
    <div class="sv-hero-timer" id="svTimer" data-start="<?php echo $shiftStartTs; ?>">00:00:00</div>
    <div class="sv-hero-actions">
        <button type="button" class="btn btn-secondary" onclick="SV.openPartnerModal()">
            <i class="fas fa-user-group"></i> Gérer mes binômes
        </button>
        <button type="button" class="btn btn-danger" onclick="SV.openEndModal()">
            <i class="fas fa-stop-circle"></i> Fin de service
        </button>
    </div>
</div>
<?php else: ?>
<div class="sv-hero" id="svHero">
    <div class="sv-hero-ico"><i class="fas fa-moon"></i></div>
    <div class="sv-hero-body">
        <div class="sv-hero-title">Vous n'êtes pas en service</div>
        <div class="sv-hero-sub">Prenez votre service pour apparaître dans la liste des agents actifs et gérer vos binômes.</div>
    </div>
    <div class="sv-hero-actions">
        <?php if ($canStart): ?>
        <button type="button" class="btn btn-success" onclick="SV.openStartModal()">
            <i class="fas fa-play-circle"></i> Prendre le service
        </button>
        <?php else: ?>
        <span class="badge bg-gray"><i class="fas fa-ban"></i> Réservé aux membres LSSD</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══ STATISTIQUES PERSONNELLES ═════════════════════════════════════ -->
<div class="sv-stats" id="svStats">
    <div class="sv-stat">
        <div class="sv-stat-ico"><i class="fas fa-hourglass-half"></i></div>
        <div class="sv-stat-lbl">Saison en cours</div>
        <div class="sv-stat-val" id="svStatSeason"><?php echo htmlspecialchars($fmtDur($season['season'])); ?></div>
        <div class="sv-stat-sub">Depuis le dernier reset</div>
        <?php if (!empty($myShift)): ?><div class="sv-stat-live">Service en cours</div><?php endif; ?>
    </div>
    <div class="sv-stat accent-green">
        <div class="sv-stat-ico"><i class="fas fa-calendar-day"></i></div>
        <div class="sv-stat-lbl">Ce mois-ci</div>
        <div class="sv-stat-val" id="svStatMonth"><?php echo htmlspecialchars($fmtDur($stats['month'])); ?></div>
        <div class="sv-stat-sub"><?php echo htmlspecialchars($fmtMonthFr($nowTs)); ?></div>
    </div>
    <div class="sv-stat accent-purple">
        <div class="sv-stat-ico"><i class="fas fa-calendar-minus"></i></div>
        <div class="sv-stat-lbl">Mois précédent</div>
        <div class="sv-stat-val" id="svStatPrev"><?php echo htmlspecialchars($fmtDur($stats['prev_month'])); ?></div>
        <div class="sv-stat-sub"><?php echo htmlspecialchars($fmtMonthFr((int) strtotime('-1 month', $nowTs))); ?></div>
    </div>
    <div class="sv-stat accent-yellow">
        <div class="sv-stat-ico"><i class="fas fa-infinity"></i></div>
        <div class="sv-stat-lbl">Total global</div>
        <div class="sv-stat-val" id="svStatTotal"><?php echo htmlspecialchars($fmtDur($stats['total'])); ?></div>
        <div class="sv-stat-sub">Depuis votre inscription</div>
    </div>
</div>

<?php if (!empty($stats['by_zone'])):
    $zoneMax = max(array_map(static fn ($z) => (int) $z['minutes'], $stats['by_zone']));
    if ($zoneMax <= 0) { $zoneMax = 1; }
?>
<div class="card">
    <div class="card-title"><i class="fas fa-chart-column"></i> Répartition par secteur</div>
    <div class="sv-zones">
        <?php foreach ($stats['by_zone'] as $z):
            $w = (int) round(((int) $z['minutes'] * 100) / $zoneMax);
        ?>
        <div class="sv-zone-row">
            <div class="sv-zone-lbl"><?php echo htmlspecialchars((string) $z['zone']); ?></div>
            <div class="sv-zone-bar"><div class="sv-zone-bar-fill" style="width:<?php echo $w; ?>%"></div></div>
            <div class="sv-zone-val"><?php echo htmlspecialchars($fmtDur((int) $z['minutes'])); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="sv-columns">
    <!-- ── Agents en service ─────────────────────────────────── -->
    <div>
        <div class="card">
            <div class="card-title">
                <i class="fas fa-users"></i>
                Agents en service
                <span class="badge bg-blue" style="margin-left:auto" id="svCount"><?php echo count($officers); ?></span>
            </div>
            <div id="svAgentGrid" class="agent-grid"></div>
        </div>
    </div>

    <!-- ── Historique personnel ──────────────────────────────── -->
    <div>
        <div class="card">
            <div class="card-title"><i class="fas fa-history"></i> Mes derniers services</div>
            <?php if (empty($history)): ?>
                <div class="empty-panel">
                    <i class="fas fa-clock-rotate-left"></i>
                    <div class="ep-title">Aucun service archivé</div>
                    <div class="ep-sub">Vos prises de service terminées apparaîtront ici.</div>
                </div>
            <?php else: ?>
                <div class="hist-list">
                <?php foreach ($history as $h):
                    $startTs = strtotime((string) $h['shift_start']);
                    $endTs   = strtotime((string) $h['shift_end']);
                    $dur     = (int) $h['duration_minutes'];
                    $durLbl  = $dur >= 60 ? (intdiv($dur, 60) . 'h ' . str_pad((string) ($dur % 60), 2, '0', STR_PAD_LEFT)) : ($dur . ' min');
                ?>
                <div class="hist-item">
                    <div class="hist-head">
                        <i class="fas fa-clock" style="color:var(--blue-l);font-size:.8rem"></i>
                        <div>
                            <div class="hist-date"><?php echo date('d/m/Y', $startTs); ?></div>
                            <div class="hist-time"><?php echo date('H:i', $startTs) . ' → ' . date('H:i', $endTs); ?></div>
                        </div>
                        <span class="hist-dur"><?php echo htmlspecialchars($durLbl); ?></span>
                    </div>
                    <div class="hist-meta">
                        <?php if (!empty($h['patrol_zone'])): ?>
                            <span><i class="fas fa-location-dot"></i><?php echo htmlspecialchars((string) $h['patrol_zone']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($h['patrol_vehicle'])): ?>
                            <span><i class="fas fa-car"></i><?php echo htmlspecialchars((string) $h['patrol_vehicle']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($h['notes'])): ?>
                    <div class="hist-notes"><?php echo nl2br(htmlspecialchars((string) $h['notes'])); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($h['partners'])): ?>
                    <div class="hist-partners">
                        <?php foreach ($h['partners'] as $pn): ?>
                            <span class="hist-partner-chip"><i class="fas fa-user-group" style="font-size:.58rem;opacity:.7"></i> <?php echo htmlspecialchars((string) $pn); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ MODALE PRISE DE SERVICE ═══════════════════════════════════════ -->
<div class="modal-ov hidden" id="svStartModal">
  <div class="modal modal-sm">
    <div class="modal-hd">
      <h2><i class="fas fa-play-circle"></i> Prendre son service</h2>
      <button class="modal-cl" onclick="LSSD.closeModal('svStartModal')">×</button>
    </div>
    <form id="svStartForm" onsubmit="return SV.submitStart(event)">
      <div class="modal-bd">
        <div class="form-grid">
          <div class="form-group form-full">
            <label class="form-label">Véhicule de patrouille *</label>
            <select name="vehicle" class="form-select" required>
              <option value="">— Choisir un véhicule —</option>
              <?php foreach ($vehicles as $v): ?>
                <option value="<?php echo htmlspecialchars((string) $v['name']); ?>"><?php echo htmlspecialchars((string) $v['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group form-full">
            <label class="form-label">Zone de patrouille *</label>
            <select name="zone" class="form-select" required>
              <option value="">— Choisir un secteur —</option>
              <?php foreach ($zones as $z): ?>
                <option value="<?php echo htmlspecialchars($z); ?>"><?php echo htmlspecialchars($z); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group form-full">
            <label class="form-label">Notes (optionnel)</label>
            <textarea name="notes" class="form-textarea" rows="3" maxlength="1000" placeholder="Informations complémentaires, objectif de patrouille..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('svStartModal')">Annuler</button>
        <button type="submit" class="btn btn-success" id="svStartSubmit"><i class="fas fa-play-circle"></i> Prendre le service</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODALE FIN DE SERVICE ═════════════════════════════════════════ -->
<div class="modal-ov hidden" id="svEndModal">
  <div class="modal modal-sm">
    <div class="modal-hd">
      <h2><i class="fas fa-stop-circle"></i> Fin de service</h2>
      <button class="modal-cl" onclick="LSSD.closeModal('svEndModal')">×</button>
    </div>
    <form id="svEndForm" onsubmit="return SV.submitEnd(event)">
      <div class="modal-bd">
        <div class="alert alert-info" style="margin-bottom:1.05rem">
          <i class="fas fa-circle-info"></i>
          <span>Votre service sera archivé et vos binômes seront libérés.</span>
        </div>
        <div class="form-group form-full">
          <label class="form-label">Notes de fin de service (optionnel)</label>
          <textarea name="notes" class="form-textarea" rows="4" maxlength="1000" placeholder="Résumé de la patrouille, incidents, interpellations..."></textarea>
        </div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('svEndModal')">Annuler</button>
        <button type="submit" class="btn btn-danger" id="svEndSubmit"><i class="fas fa-flag-checkered"></i> Terminer le service</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODALE GESTION BINÔMES ════════════════════════════════════════ -->
<div class="modal-ov hidden" id="svPartnerModal">
  <div class="modal">
    <div class="modal-hd">
      <h2><i class="fas fa-user-group"></i> Gérer mes binômes</h2>
      <button class="modal-cl" onclick="LSSD.closeModal('svPartnerModal')">×</button>
    </div>
    <div class="modal-bd">
      <div class="alert alert-info" style="margin-bottom:.9rem">
        <i class="fas fa-circle-info"></i>
        <span>Sélectionnez un ou plusieurs agents. Un agent déjà en binôme avec quelqu'un d'autre sera automatiquement réassigné à votre patrouille.</span>
      </div>
      <div class="partner-search">
        <input type="text" id="svPartnerSearch" class="form-input" placeholder="Rechercher un agent..." oninput="SV.filterPartners()">
      </div>
      <div id="svPartnerList" class="partner-picker">
        <div style="text-align:center;padding:1.4rem;color:var(--txt-m)"><i class="fas fa-spinner fa-spin"></i> Chargement...</div>
      </div>
      <div class="partner-summary" id="svPartnerSummary">Aucun binôme sélectionné.</div>
    </div>
    <div class="modal-ft">
      <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('svPartnerModal')">Annuler</button>
      <button type="button" class="btn btn-primary" id="svPartnerSave" onclick="SV.submitPartners()"><i class="fas fa-check"></i> Enregistrer les binômes</button>
    </div>
  </div>
</div>

<script>
const SV = (() => {
<?php
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
    ?>
    const csrf = <?php echo json_encode($csrf, $jsFlags); ?>;
    const urls = {
        start:    <?php echo json_encode(SITE_URL . '/pages/lssd/ajax/service_start.php', $jsFlags); ?>,
        end:      <?php echo json_encode(SITE_URL . '/pages/lssd/ajax/service_end.php', $jsFlags); ?>,
        partners: <?php echo json_encode(SITE_URL . '/pages/lssd/ajax/service_partners_update.php', $jsFlags); ?>,
        list:     <?php echo json_encode(SITE_URL . '/pages/lssd/ajax/service_list.php', $jsFlags); ?>
    };
    const meUid = <?php echo (int) $uid; ?>;
    const appTz = <?php echo json_encode(Database::timezone(), $jsFlags); ?>;
    let state = {
        officers: <?php echo json_encode($officers, $jsFlags); ?>,
        mine: <?php echo json_encode($myShift, $jsFlags); ?>,
        stats: <?php echo json_encode($stats, $jsFlags); ?>,
        serverNowTs: <?php echo (int) $nowTs; ?>,
        clientRefMs: Date.now()  // instant où serverNowTs a été pris côté client
    };
    let selectedPartners = new Set();

    /** Horloge "serveur" synchronisée : insensible au clock skew du client. */
    function serverNow() {
        const elapsedMs = Date.now() - state.clientRefMs;
        return state.serverNowTs + Math.floor(elapsedMs / 1000);
    }

    // ── Helpers ─────────────────────────────────────────────
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
    const initials = n => {
        const t = (n || '').trim();
        if (!t) return 'U';
        const parts = t.split(/\s+/);
        return (parts[0][0] + (parts[1]?.[0] || '')).toUpperCase();
    };
    const fmtDurMin = m => {
        if (m < 60) return m + ' min';
        return Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0');
    };
    const fmtDurSec = s => {
        const h = Math.floor(s / 3600),
              m = Math.floor((s % 3600) / 60),
              c = s % 60;
        return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(c).padStart(2,'0');
    };
    // Formatter HH:MM dans le fuseau applicatif (celui de pages/staff/settings.php).
    // Repli silencieux sur le fuseau du navigateur si Intl refuse le TZ.
    let _hmFmt = null;
    try {
        _hmFmt = new Intl.DateTimeFormat('fr-FR', {
            hour: '2-digit', minute: '2-digit', hour12: false, timeZone: appTz
        });
    } catch (e) {
        _hmFmt = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', hour12: false });
    }
    const timeHMfromTs = ts => _hmFmt.format(new Date(ts * 1000));

    async function api(url, body, method = 'POST') {
        const opts = { method, headers: { 'Accept': 'application/json' } };
        if (body !== undefined && body !== null) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(Object.assign({ csrf }, body));
        }
        let r;
        try { r = await fetch(url, opts); }
        catch (e) { return { ok: false, error: 'Réseau indisponible' }; }
        let j = null;
        try { j = await r.json(); } catch (e) {}
        if (!j) return { ok: false, error: 'Réponse invalide (HTTP ' + r.status + ')' };
        return j;
    }

    // ── Rendu des cartes agents ────────────────────────────
    function renderAgents() {
        const grid = document.getElementById('svAgentGrid');
        const count = document.getElementById('svCount');
        if (!grid) return;
        count.textContent = state.officers.length;

        if (!state.officers.length) {
            grid.innerHTML = `<div class="empty-panel" style="grid-column:1/-1">
                <i class="fas fa-moon"></i>
                <div class="ep-title">Aucun agent en service</div>
                <div class="ep-sub">Soyez le premier à prendre votre service.</div>
            </div>`;
            return;
        }

        const now = serverNow();
        grid.innerHTML = state.officers.map(o => {
            const isMe = o.user_id === meUid;
            const start = (o.shift_start_ts | 0);
            const durMin = Math.max(0, Math.round((now - start) / 60));
            const partnersHtml = o.partners.length
                ? o.partners.map(p => `<span class="agent-partner-chip" title="${esc(p.faction_rank)}"><i class="fas fa-user-shield"></i>${esc(p.display_name)}</span>`).join('')
                : `<span class="agent-partners-empty">Patrouille solo</span>`;
            const actions = isMe
                ? `<button type="button" class="btn btn-secondary btn-sm" onclick="SV.openPartnerModal()"><i class="fas fa-user-group"></i> Binômes</button>
                   <button type="button" class="btn btn-danger btn-sm" onclick="SV.openEndModal()"><i class="fas fa-stop-circle"></i> Fin</button>`
                : '';
            return `
            <div class="agent-card${isMe ? ' is-me' : ''}" data-uid="${o.user_id}">
                <div class="agent-card-head">
                    <div class="agent-avatar">${esc(initials(o.display_name))}</div>
                    <div class="agent-id">
                        <div class="agent-name">${esc(o.display_name)}</div>
                        <div class="agent-rank">${esc(o.faction_rank || '—')}</div>
                    </div>
                    <div class="agent-pulse" title="En service"></div>
                </div>
                <div class="agent-meta">
                    <div class="agent-meta-row"><i class="fas fa-clock"></i><span>Depuis ${esc(timeHMfromTs(start))}</span><span class="agent-timer" data-start="${start}">${esc(fmtDurMin(durMin))}</span></div>
                    <div class="agent-meta-row"><i class="fas fa-location-dot"></i><strong>${esc(o.patrol_zone || '—')}</strong></div>
                    <div class="agent-meta-row"><i class="fas fa-car"></i><strong>${esc(o.patrol_vehicle || '—')}</strong></div>
                </div>
                ${o.notes ? `<div class="agent-notes">${esc(o.notes)}</div>` : ''}
                <div class="agent-partners-lbl"><i class="fas fa-user-group"></i> Binômes (${o.partners.length})</div>
                <div class="agent-partners">${partnersHtml}</div>
                ${actions ? `<div class="agent-actions">${actions}</div>` : ''}
            </div>`;
        }).join('');
    }

    // ── Timer hero (basé sur l'horloge SERVEUR) ────────────
    function tickTimers() {
        const now = serverNow();
        const hero = document.getElementById('svTimer');
        if (hero) {
            const s = parseInt(hero.dataset.start || '0', 10);
            if (s > 0) hero.textContent = fmtDurSec(Math.max(0, now - s));
        }
        document.querySelectorAll('.agent-timer[data-start]').forEach(el => {
            const s = parseInt(el.dataset.start || '0', 10);
            if (s > 0) el.textContent = fmtDurMin(Math.max(0, Math.round((now - s) / 60)));
        });
    }

    // ── Prise de service ──────────────────────────────────
    function openStartModal() {
        const f = document.getElementById('svStartForm');
        if (f) f.reset();
        LSSD.openModal('svStartModal');
    }
    async function submitStart(ev) {
        ev.preventDefault();
        const f = ev.target;
        const btn = document.getElementById('svStartSubmit');
        const vehicle = f.vehicle.value.trim();
        const zone = f.zone.value.trim();
        const notes = f.notes.value.trim();
        if (!vehicle) { LSSD.toast('Véhicule requis', 'warning'); return false; }
        if (!zone)    { LSSD.toast('Zone requise',     'warning'); return false; }
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement...';
        const r = await api(urls.start, { vehicle, zone, notes });
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play-circle"></i> Prendre le service';
        if (!r.ok) { LSSD.toast(r.error || 'Erreur', 'error'); return false; }
        LSSD.toast('Service pris. Bonne patrouille !', 'success');
        LSSD.closeModal('svStartModal');
        setTimeout(() => location.reload(), 450);
        return false;
    }

    // ── Fin de service ────────────────────────────────────
    function openEndModal() {
        const f = document.getElementById('svEndForm');
        if (f) f.reset();
        LSSD.openModal('svEndModal');
    }
    async function submitEnd(ev) {
        ev.preventDefault();
        const btn = document.getElementById('svEndSubmit');
        const notes = ev.target.notes.value.trim();
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archivage...';
        const r = await api(urls.end, { notes });
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-flag-checkered"></i> Terminer le service';
        if (!r.ok) { LSSD.toast(r.error || 'Erreur', 'error'); return false; }
        // Affiche les secondes pour les services < 1 min (évite un "0 min" trompeur)
        const secs = (typeof r.duration_seconds === 'number') ? r.duration_seconds : (r.duration_minutes || 0) * 60;
        const durLbl = secs < 60 ? (Math.max(0, Math.round(secs)) + ' sec') : fmtDurMin(r.duration_minutes || 0);
        LSSD.toast('Fin de service enregistrée (' + durLbl + ')', 'success');
        LSSD.closeModal('svEndModal');
        setTimeout(() => location.reload(), 450);
        return false;
    }

    // ── Gestion binômes ───────────────────────────────────
    function openPartnerModal() {
        if (!state.mine) { LSSD.toast("Vous n'êtes pas en service", 'warning'); return; }
        selectedPartners = new Set((state.mine.partners || []).map(p => p.user_id));
        const search = document.getElementById('svPartnerSearch');
        if (search) search.value = '';
        renderPartnerList();
        LSSD.openModal('svPartnerModal');
    }
    function renderPartnerList() {
        const box = document.getElementById('svPartnerList');
        if (!box) return;
        const candidates = state.officers.filter(o => o.user_id !== meUid);
        if (!candidates.length) {
            box.innerHTML = `<div class="empty-panel" style="padding:1.4rem">
                <i class="fas fa-users-slash"></i>
                <div class="ep-title">Aucun autre agent en service</div>
                <div class="ep-sub">Attendez qu'un autre officier prenne son service.</div>
            </div>`;
            updatePartnerSummary();
            return;
        }
        box.innerHTML = candidates.map(o => {
            const sel = selectedPartners.has(o.user_id);
            // Indique si l'agent est déjà binôme d'un autre shift (serveur-side)
            let busy = '';
            const busyLead = o.busy_with_user_id;
            if (busyLead && busyLead !== meUid) {
                const lead = state.officers.find(x => x.user_id === busyLead);
                const name = lead ? lead.display_name : ('agent #' + busyLead);
                busy = `<span class="po-busy" title="Sera réassigné"><i class="fas fa-arrow-right-arrow-left"></i> Avec ${esc(name)}</span>`;
            }
            return `
            <label class="partner-option${sel ? ' selected' : ''}" data-uid="${o.user_id}" data-name="${esc((o.display_name + ' ' + (o.faction_rank || '')).toLowerCase())}">
                <input type="checkbox" ${sel ? 'checked' : ''} onchange="SV.togglePartner(${o.user_id}, this.checked)">
                <div class="agent-avatar" style="width:32px;height:32px;font-size:.78rem">${esc(initials(o.display_name))}</div>
                <div style="flex:1;min-width:0">
                    <div class="po-name">${esc(o.display_name)}</div>
                    <div class="po-rank">${esc(o.faction_rank || '—')}</div>
                </div>
                ${busy}
            </label>`;
        }).join('');
        updatePartnerSummary();
    }
    function togglePartner(uid, on) {
        if (on) selectedPartners.add(uid); else selectedPartners.delete(uid);
        // Met à jour la classe visuelle sans recharger tout le DOM
        const label = document.querySelector(`.partner-option[data-uid="${uid}"]`);
        if (label) label.classList.toggle('selected', on);
        updatePartnerSummary();
    }
    function updatePartnerSummary() {
        const el = document.getElementById('svPartnerSummary');
        if (!el) return;
        const n = selectedPartners.size;
        if (n === 0) { el.textContent = 'Aucun binôme sélectionné — patrouille solo.'; return; }
        const names = state.officers
            .filter(o => selectedPartners.has(o.user_id))
            .map(o => o.display_name);
        el.innerHTML = `<strong>${n} binôme${n > 1 ? 's' : ''}</strong> : ${names.map(esc).join(', ')}`;
    }
    function filterPartners() {
        const q = (document.getElementById('svPartnerSearch').value || '').trim().toLowerCase();
        document.querySelectorAll('#svPartnerList .partner-option').forEach(el => {
            el.style.display = (!q || el.dataset.name.includes(q)) ? '' : 'none';
        });
    }
    async function submitPartners() {
        const btn = document.getElementById('svPartnerSave');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement...';
        const r = await api(urls.partners, { partners: Array.from(selectedPartners) });
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Enregistrer les binômes';
        if (!r.ok) { LSSD.toast(r.error || 'Erreur', 'error'); return; }
        LSSD.toast('Binômes mis à jour', 'success');
        LSSD.closeModal('svPartnerModal');
        await refresh();
    }

    // ── Rafraîchissement liste + stats ─────────────────────
    async function refresh() {
        const r = await api(urls.list, null, 'GET');
        if (!r.ok) return;
        state.officers = r.officers || [];
        state.mine = r.current || null;
        if (typeof r.now_ts === 'number') {
            state.serverNowTs = r.now_ts;
            state.clientRefMs = Date.now();
        }
        if (r.stats) {
            state.stats = r.stats;
            updateStats();
        }
        renderAgents();
        updateHeroCounter();
    }
    function updateHeroCounter() {
        const sub = document.getElementById('svHeroSub');
        if (sub && state.mine) {
            const n = (state.mine.partners || []).length;
            sub.innerHTML = esc(state.mine.patrol_zone || '—')
                + ' · ' + esc(state.mine.patrol_vehicle || '—')
                + ' · Binômes : <strong id="svHeroPartners">' + n + '</strong>';
        }
    }

    // ── Stats (met à jour live les 4 compteurs) ────────────
    function updateStats() {
        const s = state.stats || { week:0, month:0, prev_month:0, total:0 };
        const week  = document.getElementById('svStatWeek');
        const mo    = document.getElementById('svStatMonth');
        const prev  = document.getElementById('svStatPrev');
        const total = document.getElementById('svStatTotal');
        if (week)  week.textContent  = fmtDurMin(s.week);
        if (mo)    mo.textContent    = fmtDurMin(s.month);
        if (prev)  prev.textContent  = fmtDurMin(s.prev_month);
        if (total) total.textContent = fmtDurMin(s.total);
    }

    /**
     * Augmente localement les compteurs (semaine, mois, total) pour que les
     * stats s'incrémentent chaque minute tant que l'utilisateur est en service.
     * Le serveur renverra la valeur exacte au prochain refresh (20 s).
     */
    let lastTickTs = Math.floor(Date.now() / 1000);
    function accumulateStatsLive() {
        if (!state.mine || !state.stats) return;
        const now = serverNow();
        const delta = now - lastTickTs;
        if (delta < 60) return;  // on n'accumule qu'à la minute pleine
        const minutes = Math.floor(delta / 60);
        lastTickTs += minutes * 60;
        state.stats.week  = (state.stats.week  || 0) + minutes;
        state.stats.month = (state.stats.month || 0) + minutes;
        state.stats.total = (state.stats.total || 0) + minutes;
        updateStats();
    }

    // ── Init ──────────────────────────────────────────────
    function init() {
        renderAgents();
        tickTimers();
        setInterval(() => { tickTimers(); accumulateStatsLive(); }, 1000);
        setInterval(refresh, 20000);
    }
    document.addEventListener('DOMContentLoaded', init);

    return {
        openStartModal, submitStart,
        openEndModal,   submitEnd,
        openPartnerModal, togglePartner, filterPartners, submitPartners
    };
})();
window.SV = SV;
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
