<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Triage & Urgences';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            $pid = intval($_POST['patient_id'] ?? 0) ?: null;
            $db->prepare("INSERT INTO sams_triage (patient_id,patient_nom,niveau,motif,constantes,infirmier_id,heure_arrivee) VALUES (?,?,?,?,?,?,NOW())")
               ->execute([$pid, $_POST['patient_nom'] ?? null, $_POST['niveau'], $_POST['motif'],
                   json_encode(['tension'=>$_POST['tension']??'','pouls'=>$_POST['pouls']??'','spo2'=>$_POST['spo2']??'','temp'=>$_POST['temp']??'']),
                   $_SESSION['user_id']]);
            $msg = 'Patient ajouté au triage.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur: '.$e->getMessage(); $msgType = 'error'; }
    }
    if ($action === 'take') {
        $db->prepare("UPDATE sams_triage SET statut='en_traitement',medecin_id=?,heure_prise_en_charge=NOW() WHERE id=?")->execute([$_SESSION['user_id'], intval($_POST['id'])]);
        $msg = 'Prise en charge enregistrée.'; $msgType = 'success';
    }
    if ($action === 'close') {
        $db->prepare("UPDATE sams_triage SET statut=?,heure_sortie=NOW(),notes=? WHERE id=?")->execute([$_POST['statut'], trim($_POST['notes'] ?? ''), intval($_POST['id'])]);
        $msg = 'Dossier clôturé.'; $msgType = 'success';
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM sams_triage WHERE id=?")->execute([intval($_POST['id'])]);
        $msg = 'Supprimé.'; $msgType = 'success';
    }
}

$actifs = [];
try {
    $actifs = $db->query("
        SELECT t.*, p.firstname, p.lastname,
               NULLIF(TRIM(CONCAT(COALESCE(up.firstname,''),' ',COALESCE(up.lastname,''))),'') AS infirmier_nom,
               NULLIF(TRIM(CONCAT(COALESCE(mp.firstname,''),' ',COALESCE(mp.lastname,''))),'') AS medecin_nom
        FROM sams_triage t
        LEFT JOIN profiles p ON p.id = t.patient_id
        LEFT JOIN users u ON u.id = t.infirmier_id
        LEFT JOIN profiles up ON up.id = u.profile_id
        LEFT JOIN users m ON m.id = t.medecin_id
        LEFT JOIN profiles mp ON mp.id = m.profile_id
        WHERE t.statut IN ('attente','en_traitement')
        ORDER BY FIELD(t.niveau,'P1','P2','P3','P4'), t.heure_arrivee ASC
    ")->fetchAll();
} catch (Exception $e) {}

$historique = [];
try {
    $historique = $db->query("
        SELECT t.*, p.firstname, p.lastname
        FROM sams_triage t
        LEFT JOIN profiles p ON p.id = t.patient_id
        WHERE t.statut IN ('sorti','dcd','transfert')
        ORDER BY t.heure_sortie DESC LIMIT 20
    ")->fetchAll();
} catch (Exception $e) {}

$patients = [];
try {
    $patients = $db->query("SELECT id, CONCAT(firstname,' ',lastname) AS name FROM profiles ORDER BY lastname LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/includes/header.php';
?>

<style>
/* Priority level cards */
.triage-board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: .8rem; margin-bottom: 1.4rem;
}
.priority-col {
    background: var(--bg-c); border-radius: 12px;
    border: 1px solid var(--bdr);
    overflow: hidden;
}
.priority-hdr {
    padding: .7rem 1rem;
    display: flex; align-items: center; gap: .5rem;
    border-bottom: 1px solid var(--bdr);
    font-family: 'Barlow Condensed', sans-serif;
    font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .12em;
}
.priority-hdr .count {
    margin-left: auto;
    width: 22px; height: 22px; border-radius: 50%;
    display: grid; place-items: center;
    font-size: .68rem; font-weight: 800;
    font-family: 'JetBrains Mono', monospace;
}
.col-P1 .priority-hdr { background: rgba(225,29,72,.1);   color: var(--red-l);   border-color: rgba(225,29,72,.2); }
.col-P1 .priority-hdr .count { background: rgba(225,29,72,.25); color: var(--red-l); }
.col-P2 .priority-hdr { background: rgba(245,158,11,.1);  color: var(--yellow-l);border-color: rgba(245,158,11,.2); }
.col-P2 .priority-hdr .count { background: rgba(245,158,11,.2); color: var(--yellow-l); }
.col-P3 .priority-hdr { background: rgba(59,130,246,.1);  color: #93c5fd;        border-color: rgba(59,130,246,.2); }
.col-P3 .priority-hdr .count { background: rgba(59,130,246,.2); color: #93c5fd; }
.col-P4 .priority-hdr { background: rgba(16,185,129,.1);  color: var(--green-l); border-color: rgba(16,185,129,.2); }
.col-P4 .priority-hdr .count { background: rgba(16,185,129,.2); color: var(--green-l); }

.priority-list { padding: .6rem; min-height: 80px; display: flex; flex-direction: column; gap: .45rem; }

.triage-card {
    background: var(--bg-e); border: 1px solid var(--bdr);
    border-radius: 9px; padding: .75rem .85rem;
    transition: all .15s; position: relative;
}
.triage-card:hover { border-color: var(--bdr-h); background: var(--bg-ov); }
.triage-card .tc-name {
    font-size: .82rem; font-weight: 600; color: var(--txt);
    margin-bottom: .25rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.triage-card .tc-motif {
    font-size: .7rem; color: var(--txt-m);
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden;
}
.triage-card .tc-meta {
    display: flex; align-items: center; gap: .4rem;
    margin-top: .45rem;
}
.triage-card .tc-time {
    font-family: 'JetBrains Mono', monospace;
    font-size: .65rem; color: var(--txt-m);
}
.triage-card .tc-actions {
    display: flex; gap: .3rem; margin-top: .5rem;
}

/* Vitals display */
.vital-strip {
    display: flex; gap: .5rem; flex-wrap: wrap;
    padding: .5rem .7rem;
    background: rgba(6,182,212,.04);
    border: 1px solid var(--bdr);
    border-radius: 7px; margin-top: .4rem;
}
.vital-item {
    display: flex; flex-direction: column; align-items: center; gap: .1rem;
    flex: 1; min-width: 55px;
}
.vital-lbl {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: .57rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    color: var(--txt-m);
}
.vital-val {
    font-family: 'JetBrains Mono', monospace;
    font-size: .82rem; font-weight: 700;
    color: var(--cyan-l);
}
.vital-val.empty { color: var(--txt-m); }

.p-badge { display: inline-block; width: 28px; height: 28px; border-radius: 50%; font-family: 'Rajdhani'; font-size: .7rem; font-weight: 800; display: grid; place-items: center; }
.p-badge-P1 { background: rgba(225,29,72,.2); color: var(--red-l); border: 1.5px solid rgba(225,29,72,.4); }
.p-badge-P2 { background: rgba(245,158,11,.2); color: var(--yellow-l); border: 1.5px solid rgba(245,158,11,.4); }
.p-badge-P3 { background: rgba(59,130,246,.2); color: #93c5fd; border: 1.5px solid rgba(59,130,246,.4); }
.p-badge-P4 { background: rgba(16,185,129,.2); color: var(--green-l); border: 1.5px solid rgba(16,185,129,.4); }
</style>

<!-- ══ PAGE HEADER ══ -->
<div class="page-hdr">
    <div class="page-hdr-left">
        <div class="breadcrumb">
            <a href="dashboard.php"><i class="fas fa-home"></i></a>
            <span class="breadcrumb-sep">/</span>
            <span>Triage & Urgences</span>
        </div>
        <h1><i class="fas fa-hospital"></i> Triage & Urgences</h1>
        <div class="page-hdr-sub">
            <?php echo count($actifs); ?> patient(s) en cours — Mis à jour en temps réel
        </div>
    </div>
    <div class="page-hdr-right">
        <button class="btn btn-primary" onclick="openModal('modal-add')">
            <i class="fas fa-user-plus"></i> Ajouter au triage
        </button>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'circle-xmark'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- ══ TRIAGE BOARD — 4 colonnes priorité ══ -->
<?php
$byLevel = ['P1'=>[],'P2'=>[],'P3'=>[],'P4'=>[]];
foreach ($actifs as $t) {
    $lvl = in_array($t['niveau'], ['P1','P2','P3','P4']) ? $t['niveau'] : 'P4';
    $byLevel[$lvl][] = $t;
}
$levelLabels = ['P1'=>'Critique','P2'=>'Urgent','P3'=>'Semi-urgent','P4'=>'Non urgent'];
$levelIcons  = ['P1'=>'heart-crack','P2'=>'triangle-exclamation','P3'=>'circle-exclamation','P4'=>'circle-check'];
?>
<div class="triage-board">
<?php foreach ($byLevel as $lvl => $patients_lvl): ?>
<div class="priority-col col-<?php echo $lvl; ?>">
    <div class="priority-hdr">
        <i class="fas fa-<?php echo $levelIcons[$lvl]; ?>"></i>
        <?php echo $lvl; ?> — <?php echo $levelLabels[$lvl]; ?>
        <span class="count"><?php echo count($patients_lvl); ?></span>
    </div>
    <div class="priority-list">
        <?php if (empty($patients_lvl)): ?>
        <div style="text-align:center;padding:1.2rem .5rem;color:var(--txt-m);font-size:.72rem;">
            <i class="fas fa-check" style="display:block;font-size:1.2rem;opacity:.2;margin-bottom:.4rem;"></i>
            Aucun patient
        </div>
        <?php endif; ?>
        <?php foreach ($patients_lvl as $t):
            $nom = $t['firstname'] && $t['lastname']
                ? $t['firstname'].' '.$t['lastname']
                : ($t['patient_nom'] ?? 'Inconnu');
            $constantes = json_decode($t['constantes'] ?? '{}', true) ?: [];
            $arriveHour = $t['heure_arrivee'] ? date('H:i', strtotime($t['heure_arrivee'])) : '—';
            $waitMinutes = $t['heure_arrivee'] ? round((time() - strtotime($t['heure_arrivee'])) / 60) : 0;
        ?>
        <div class="triage-card" id="tc-<?php echo $t['id']; ?>">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.3rem;">
                <span class="p-badge p-badge-<?php echo $lvl; ?>"><?php echo $lvl; ?></span>
                <div class="tc-name" style="flex:1"><?php echo htmlspecialchars($nom); ?></div>
            </div>
            <div class="tc-motif"><?php echo htmlspecialchars($t['motif'] ?? '—'); ?></div>

            <!-- Constantes vitales -->
            <?php if (array_filter($constantes)): ?>
            <div class="vital-strip">
                <?php foreach (['tension'=>'TA','pouls'=>'FC','spo2'=>'SpO2','temp'=>'Temp'] as $key=>$label): ?>
                <div class="vital-item">
                    <div class="vital-lbl"><?php echo $label; ?></div>
                    <div class="vital-val <?php echo empty($constantes[$key]) ? 'empty' : ''; ?>">
                        <?php echo htmlspecialchars($constantes[$key] ?: '—'); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="tc-meta">
                <span class="tc-time"><i class="fas fa-clock" style="margin-right:.2rem;"></i><?php echo $arriveHour; ?></span>
                <?php if ($waitMinutes > 0): ?>
                <span style="font-size:.62rem;color:<?php echo $waitMinutes>30?'var(--red-l)':($waitMinutes>15?'var(--yellow-l)':'var(--txt-m)'); ?>;">
                    (<?php echo $waitMinutes; ?> min)
                </span>
                <?php endif; ?>
                <?php if ($t['statut'] === 'en_traitement'): ?>
                <span class="badge badge-cyan" style="font-size:.58rem;">En traitement</span>
                <?php endif; ?>
            </div>

            <div class="tc-actions">
                <?php if ($t['statut'] === 'attente'): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="take">
                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                    <button type="submit" class="btn btn-cyan btn-xs">
                        <i class="fas fa-hand-holding-medical"></i> Prendre en charge
                    </button>
                </form>
                <?php endif; ?>
                <button class="btn btn-success btn-xs"
                        onclick="openCloseModal(<?php echo $t['id']; ?>,'<?php echo addslashes($nom); ?>')">
                    <i class="fas fa-check"></i> Clôturer
                </button>
                <form method="POST"
                      data-confirm
                      data-confirm-title="Supprimer du triage"
                      data-confirm-msg="Le patient sera retiré de la file de triage."
                      data-confirm-target="<?php echo htmlspecialchars(addslashes($nom)); ?>"
                      data-confirm-icon="user-minus"
                      data-confirm-ok="Supprimer"
                      style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-xs btn-icon" title="Supprimer">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ HISTORIQUE ══ -->
<?php if (!empty($historique)): ?>
<div class="card">
    <div class="card-hdr">
        <div class="card-title"><i class="fas fa-history"></i> Historique récent</div>
    </div>
    <div class="tbl-wrap" style="margin:0;border:none;">
        <table class="tbl">
            <thead><tr>
                <th>Niveau</th><th>Patient</th><th>Motif</th><th>Arrivée</th><th>Sortie</th><th>Statut</th>
            </tr></thead>
            <tbody>
            <?php foreach ($historique as $h):
                $nom = $h['firstname'] && $h['lastname'] ? $h['firstname'].' '.$h['lastname'] : ($h['patient_nom'] ?? 'Inconnu');
                $statusLabel = match($h['statut']) { 'sorti'=>'Sorti', 'dcd'=>'Décédé', 'transfert'=>'Transféré', default=>'—' };
                $statusClass = match($h['statut']) { 'sorti'=>'badge-green', 'dcd'=>'badge-red', 'transfert'=>'badge-cyan', default=>'badge-gray' };
            ?>
            <tr>
                <td><span class="p-badge p-badge-<?php echo $h['niveau'] ?? 'P4'; ?>"><?php echo $h['niveau'] ?? '—'; ?></span></td>
                <td style="font-weight:500;"><?php echo htmlspecialchars($nom); ?></td>
                <td style="font-size:.75rem;color:var(--txt-s);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($h['motif'] ?? '—'); ?></td>
                <td class="mono" style="font-size:.72rem;color:var(--txt-m);"><?php echo $h['heure_arrivee'] ? date('H:i', strtotime($h['heure_arrivee'])) : '—'; ?></td>
                <td class="mono" style="font-size:.72rem;color:var(--txt-m);"><?php echo $h['heure_sortie'] ? date('H:i', strtotime($h['heure_sortie'])) : '—'; ?></td>
                <td><span class="badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<!-- ══ MODAL — AJOUTER AU TRIAGE ══ -->
<div class="modal-ov hidden" id="modal-add">
    <div class="modal">
        <div class="modal-hd">
            <h2><i class="fas fa-user-plus"></i> Nouveau patient — Triage</h2>
            <button class="modal-close" onclick="closeModal('modal-add')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="add">

                <!-- Niveau priorité — custom buttons -->
                <div class="form-group" style="margin-bottom:1.1rem;">
                    <label class="form-label required"><i class="fas fa-signal"></i> Niveau de priorité</label>
                    <input type="hidden" name="niveau" id="niveau-input" required>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.55rem;" id="niveau-btns">
                        <?php
                        $niveaux = [
                            'P1'=>['Critique','heart-crack','rgba(225,29,72,.15)','var(--red-l)','rgba(225,29,72,.35)'],
                            'P2'=>['Urgent','triangle-exclamation','rgba(245,158,11,.12)','var(--yellow-l)','rgba(245,158,11,.35)'],
                            'P3'=>['Semi-urgent','circle-exclamation','rgba(59,130,246,.12)','#93c5fd','rgba(59,130,246,.35)'],
                            'P4'=>['Non urgent','circle-check','rgba(16,185,129,.1)','var(--green-l)','rgba(16,185,129,.3)'],
                        ];
                        foreach ($niveaux as $n => [$label, $ico, $bg, $col, $border]): ?>
                        <button type="button"
                                onclick="selectNiveau('<?php echo $n; ?>')"
                                id="n-btn-<?php echo $n; ?>"
                                style="padding:.7rem .5rem;border-radius:9px;border:1.5px solid var(--bdr);background:transparent;cursor:pointer;text-align:center;transition:all .15s;font-family:'Barlow',sans-serif;">
                            <i class="fas fa-<?php echo $ico; ?>" style="font-size:1.1rem;display:block;margin-bottom:.3rem;color:<?php echo $col; ?>;"></i>
                            <div style="font-family:'Barlow Condensed',sans-serif;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-s);"><?php echo $n; ?></div>
                            <div style="font-size:.67rem;color:var(--txt-m);"><?php echo $label; ?></div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user-injured"></i> Patient (si enregistré)</label>
                        <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="triagePatBtn"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
                        <input type="hidden" name="patient_id" id="triagePatId">
                        <div id="triagePatChip" style="margin-top:.5rem"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required"><i class="fas fa-id-card"></i> Nom (si non enregistré)</label>
                        <input type="text" name="patient_nom" class="form-input" placeholder="Ex: John Doe / Inconnu">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label required"><i class="fas fa-notes-medical"></i> Motif de consultation</label>
                        <textarea name="motif" class="form-textarea" rows="2" placeholder="Décrivez les symptômes, le motif d'admission..." required></textarea>
                    </div>
                </div>

                <!-- Constantes vitales -->
                <div class="hr"></div>
                <div style="font-family:'Barlow Condensed';font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--txt-m);margin-bottom:.8rem;">
                    <i class="fas fa-heartbeat" style="margin-right:.35rem;color:var(--cyan-l);"></i>
                    Constantes vitales (optionnel)
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-heart"></i> Tension artérielle</label>
                        <input type="text" name="tension" class="form-input" placeholder="120/80">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-wave-square"></i> Fréquence cardiaque</label>
                        <input type="text" name="pouls" class="form-input" placeholder="75 bpm">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-lungs"></i> SpO2</label>
                        <input type="text" name="spo2" class="form-input" placeholder="98%">
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-add')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Ajouter au triage
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ MODAL — CLÔTURER ══ -->
<div class="modal-ov hidden" id="modal-close">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-check-circle"></i> Clôturer le dossier</h2>
            <button class="modal-close" onclick="closeModal('modal-close')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="id" id="close-id">
                <div style="font-size:.84rem;font-weight:600;color:var(--txt);margin-bottom:1rem;" id="close-patient-name"></div>
                <div class="form-group">
                    <label class="form-label required"><i class="fas fa-door-open"></i> Mode de sortie</label>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <?php foreach (['sorti'=>['Sorti','check','green'],'dcd'=>['Décédé','cross','red'],'transfert'=>['Transféré','truck-medical','cyan']] as $v=>[$label,$ico,$c]): ?>
                        <button type="button" onclick="selectSortie('<?php echo $v; ?>')"
                                id="sortie-<?php echo $v; ?>"
                                class="btn btn-ghost" style="flex:1;flex-direction:column;gap:.3rem;padding:.75rem .5rem;font-size:.75rem;">
                            <i class="fas fa-<?php echo $ico; ?>"></i>
                            <?php echo $label; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="statut" id="sortie-val" required>
                </div>
                <div class="form-group" style="margin-top:.8rem;">
                    <label class="form-label"><i class="fas fa-clipboard"></i> Notes de clôture</label>
                    <textarea name="notes" class="form-textarea" rows="2" placeholder="Observations finales, recommandations..."></textarea>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-close')">Annuler</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirmer la clôture</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ── Niveau selector ── */
const NIVEAU_CFG = {
    P1: { bg:'rgba(225,29,72,.15)',  border:'rgba(225,29,72,.45)',  color:'var(--red-l)' },
    P2: { bg:'rgba(245,158,11,.15)', border:'rgba(245,158,11,.45)', color:'var(--yellow-l)' },
    P3: { bg:'rgba(59,130,246,.15)', border:'rgba(59,130,246,.45)', color:'#93c5fd' },
    P4: { bg:'rgba(16,185,129,.1)',  border:'rgba(16,185,129,.4)',  color:'var(--green-l)' },
};
function selectNiveau(n) {
    document.getElementById('niveau-input').value = n;
    ['P1','P2','P3','P4'].forEach(x => {
        const btn = document.getElementById('n-btn-' + x);
        if (x === n) {
            const c = NIVEAU_CFG[x];
            btn.style.background    = c.bg;
            btn.style.borderColor   = c.border;
            btn.style.setProperty('--active-color', c.color);
        } else {
            btn.style.background  = 'transparent';
            btn.style.borderColor = 'var(--bdr)';
        }
    });
}

/* ── Sortie selector ── */
function selectSortie(v) {
    document.getElementById('sortie-val').value = v;
    ['sorti','dcd','transfert'].forEach(x => {
        document.getElementById('sortie-' + x).classList.toggle('btn-cyan', x === v);
    });
}

function openCloseModal(id, name) {
    document.getElementById('close-id').value = id;
    document.getElementById('close-patient-name').textContent = 'Patient : ' + name;
    document.getElementById('sortie-val').value = '';
    ['sorti','dcd','transfert'].forEach(x => document.getElementById('sortie-' + x).classList.remove('btn-cyan'));
    openModal('modal-close');
}

/* Auto-refresh every 30s */
setTimeout(() => location.reload(), 30000);

<?php if ($msg): ?>
setTimeout(() => showToast(<?php echo json_encode($msg); ?>, '<?php echo $msgType; ?>'), 300);
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/components.php'; ?>
<script>
const TRIAGE_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>$p['name']], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'triagePatBtn',hidden:'triagePatId',chip:'triagePatChip',title:'Sélection du patient',items:TRIAGE_PATIENTS,required:false});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>