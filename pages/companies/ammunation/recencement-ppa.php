<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🔫 Ammunation';
$companyColor   = '#dc2626';
$companyFaction = 'AMMUNATION';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Recensement PPA';
$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);

require_once ROOT_PATH . '/includes/faction_acl.php';
if (!faction_acl_can($db, $uid, $companyFaction, 'dashboard.view')) faction_acl_deny();

require_once ROOT_PATH . '/includes/ppa.php';
mdt_ppa_migrate($db);

$search = trim($_GET['q'] ?? '');

$computeAge = static function ($dob, $ageCol) {
    if (!empty($dob) && $dob !== '0000-00-00') { try { return (new DateTime('today'))->diff(new DateTime((string)$dob))->y; } catch (Exception $e) {} }
    return ($ageCol !== null && $ageCol !== '') ? (int)$ageCol : null;
};

// ── Détenteurs de PPA (profil marqué license_ppa=1) ────────────────────────
$holders = [];
try {
    $sql = "SELECT id, firstname, lastname, avatar, date_of_birth, age FROM profiles WHERE license_ppa=1";
    $params = [];
    if ($search !== '') { $sql .= " AND (firstname LIKE ? OR lastname LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
    $sql .= " ORDER BY lastname, firstname LIMIT 500";
    $st = $db->prepare($sql); $st->execute($params); $holders = $st->fetchAll();
} catch (Exception $e) {}

$ids = array_map(static fn($h) => (int)$h['id'], $holders);

// ── Toutes les demandes PPA + tous les casiers en 2 requêtes (évite le N+1) ──
$ppasByCitizen = [];
$casierByCitizen = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $db->prepare("SELECT citizen_id, type_arme, statut, date_demande, date_traitement, sams_doc_url, final_doc_url
                            FROM lspd_ppa_demandes WHERE citizen_id IN ($ph)
                            ORDER BY FIELD(statut,'valide','en_cours','demande','refuse'), created_at DESC");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) { $ppasByCitizen[(int)$r['citizen_id']][] = $r; }
    } catch (Exception $e) {}
    try {
        $st = $db->prepare("SELECT citizen_id, offense_code, offense_title, fine_amount, jail_time, status, incident_date
                            FROM criminal_records WHERE citizen_id IN ($ph)
                            ORDER BY incident_date DESC, id DESC");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) { $casierByCitizen[(int)$r['citizen_id']][] = $r; }
    } catch (Exception $e) {}
}

// ── Données détaillées (consommées par la modale côté client) ───────────────
$holdersData = [];
foreach ($holders as $h) {
    $cid    = (int)$h['id'];
    $avatar = trim((string)($h['avatar'] ?? ''));
    $avatarUrl = $avatar !== '' ? (preg_match('/^https?:\/\//', $avatar) ? $avatar : rtrim((string)SITE_URL, '/') . $avatar) : '';
    $prenom = trim((string)($h['firstname'] ?? ''));
    $nom    = trim((string)($h['lastname'] ?? ''));
    $age    = $computeAge($h['date_of_birth'] ?? null, $h['age'] ?? null);

    $ppas = [];
    foreach ($ppasByCitizen[$cid] ?? [] as $p) {
        $dateRaw = $p['date_traitement'] ?: $p['date_demande'];
        $ppas[] = [
            'type'   => (string)($p['type_arme'] ?: 'PPA'),
            'statut' => (string)$p['statut'],
            'date'   => ($dateRaw && $dateRaw !== '0000-00-00') ? date('d/m/Y', strtotime((string)$dateRaw)) : '',
            'final'  => (string)($p['final_doc_url'] ?? ''),
            'sams'   => (string)($p['sams_doc_url'] ?? ''),
        ];
    }
    $casier = [];
    foreach ($casierByCitizen[$cid] ?? [] as $c) {
        $casier[] = [
            'title'  => trim((string)($c['offense_title'] ?? '')) ?: (string)($c['offense_code'] ?? '—'),
            'date'   => !empty($c['incident_date']) ? date('d/m/Y', strtotime((string)$c['incident_date'])) : '—',
            'fine'   => (float)($c['fine_amount'] ?? 0),
            'jail'   => (int)($c['jail_time'] ?? 0),
            'active' => ('active' === ($c['status'] ?? '')),
        ];
    }

    $holdersData[$cid] = [
        'name'   => trim($prenom . ' ' . $nom) ?: '—',
        'nom'    => $nom, 'prenom' => $prenom,
        'age'    => $age,
        'avatar' => $avatarUrl,
        'init'   => strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1)),
        'ppas'   => $ppas,
        'casier' => $casier,
    ];
}

$totalHolders  = count($holders);
$totalLicences = 0;
foreach ($ppasByCitizen as $arr) { foreach ($arr as $p) { if ($p['statut'] === 'valide') $totalLicences++; } }

include __DIR__ . '/includes/header.php';
?>
<style>
.ppa-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
.ppa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:1rem}
.ppa-card{background:rgba(13,21,38,.7);border:1px solid rgba(220,38,38,.14);border-radius:14px;padding:1.1rem;cursor:pointer;transition:transform .18s,border-color .18s,box-shadow .18s;display:flex;flex-direction:column;gap:.85rem}
.ppa-card:hover{border-color:rgba(220,38,38,.4);transform:translateY(-2px);box-shadow:0 8px 26px rgba(0,0,0,.3)}
.ppa-card-top{display:flex;align-items:center;gap:.85rem}
.ppa-av{width:52px;height:52px;border-radius:12px;overflow:hidden;background:rgba(220,38,38,.12);color:var(--cc-l,#fca5a5);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.05rem;flex-shrink:0}
.ppa-av img{width:100%;height:100%;object-fit:cover}
.ppa-card-name{font-weight:700;font-size:.95rem;color:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ppa-card-sub{font-size:.75rem;color:#64748b;margin-top:.15rem}
.ppa-card-foot{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
/* ── Fiche détaillée (modale) ── */
.phm-hd{display:flex;align-items:center;gap:1rem;padding-bottom:1rem;margin-bottom:.4rem;border-bottom:1px solid rgba(220,38,38,.14)}
.phm-av{width:64px;height:64px;border-radius:14px;overflow:hidden;background:rgba(220,38,38,.12);color:var(--cc-l,#fca5a5);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.3rem;flex-shrink:0}
.phm-av img{width:100%;height:100%;object-fit:cover}
.phm-name{font-size:1.15rem;font-weight:800;color:#f1f5f9}
.phm-fields{display:flex;gap:1.5rem;margin-top:.5rem;flex-wrap:wrap}
.phm-field-l{font-size:.6rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b}
.phm-field-v{font-size:.85rem;font-weight:600;color:#e2e8f0;margin-top:.1rem}
.phm-sec-t{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--cc-l,#fca5a5);margin:1.2rem 0 .6rem;display:flex;align-items:center;gap:.45rem}
.phm-ppa{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.6rem .75rem;border-radius:9px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.06);margin-bottom:.45rem}
.phm-ppa-main{font-weight:600;font-size:.85rem;color:#f1f5f9;flex:1;min-width:110px}
.phm-ppa-main i{color:var(--cc-l,#fca5a5);margin-right:.4rem}
.phm-ppa-date{font-size:.72rem;color:#94a3b8}
.phm-empty,.phm-vierge{padding:.9rem;border-radius:9px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.06);font-size:.82rem;color:#94a3b8;text-align:center}
.phm-inf{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.55rem .7rem;border-radius:8px;background:rgba(255,255,255,.02);border:1px solid rgba(239,68,68,.14);margin-bottom:.4rem}
.phm-inf-main{flex:1;min-width:140px;font-size:.82rem;font-weight:600;color:#e2e8f0}
.phm-inf-main i{color:#fca5a5;margin-right:.4rem;font-size:.72rem}
.phm-classe{font-weight:400;color:#64748b;font-size:.72rem}
.phm-inf-date{font-size:.72rem;color:#94a3b8}
.phm-inf-jail{font-size:.72rem;color:#fca5a5}
.phm-inf-fine{font-size:.72rem;color:#fcd34d;font-weight:700}
</style>

<div class="ppa-kpis">
    <div class="card" style="margin-bottom:0">
        <div style="display:flex;align-items:center;gap:.9rem">
            <div style="width:44px;height:44px;border-radius:11px;background:rgba(220,38,38,.12);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--cc-l,#fca5a5)"><i class="fas fa-id-card-clip"></i></div>
            <div>
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b">Détenteurs de PPA</div>
                <div style="font-size:1.6rem;font-weight:800;line-height:1"><?php echo $totalHolders; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="margin-bottom:0">
        <div style="display:flex;align-items:center;gap:.9rem">
            <div style="width:44px;height:44px;border-radius:11px;background:rgba(34,197,94,.12);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#4ade80"><i class="fas fa-shield-halved"></i></div>
            <div>
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b">Licences délivrées</div>
                <div style="font-size:1.6rem;font-weight:800;line-height:1"><?php echo $totalLicences; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-title" style="justify-content:space-between;flex-wrap:wrap;gap:.75rem">
        <span><i class="fas fa-id-card-clip"></i> Citoyens détenteurs d'un PPA valide</span>
        <form method="GET" style="display:flex;gap:.5rem;align-items:center">
            <input type="text" name="q" class="form-input" placeholder="Rechercher un citoyen…" value="<?php echo htmlspecialchars($search); ?>" style="width:230px">
            <?php if($search!==''):?><a href="recencement-ppa.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
        </form>
    </div>

    <?php if(empty($holders)): ?>
    <div style="text-align:center;padding:3rem 1rem;color:#64748b">
        <i class="fas fa-id-card-clip" style="font-size:2.4rem;opacity:.15;display:block;margin-bottom:.8rem"></i>
        <div style="font-weight:600"><?php echo $search!==''?'Aucun résultat pour cette recherche':'Aucun citoyen possédant un PPA valide'; ?></div>
    </div>
    <?php else: ?>
    <div class="ppa-grid">
        <?php foreach($holders as $h):
            $cid = (int)$h['id'];
            $d   = $holdersData[$cid];
            $cas = $casierByCitizen[$cid] ?? [];
            $casActive = count(array_filter($cas, fn($c) => ($c['status'] ?? '') === 'active'));
            $casTotal  = count($cas);
            $nbPpa     = count($d['ppas']);
        ?>
        <div class="ppa-card" onclick="openHolder(<?php echo $cid; ?>)">
            <div class="ppa-card-top">
                <div class="ppa-av"><?php if($d['avatar']!==''):?><img src="<?php echo htmlspecialchars($d['avatar']); ?>" alt="" referrerpolicy="no-referrer"><?php else:?><?php echo htmlspecialchars($d['init']!==''?$d['init']:'?'); ?><?php endif;?></div>
                <div style="min-width:0">
                    <div class="ppa-card-name"><?php echo htmlspecialchars($d['name']); ?></div>
                    <div class="ppa-card-sub"><?php echo $d['age']!==null?((int)$d['age'].' ans'):'Âge —'; ?> · <?php echo $nbPpa; ?> licence<?php echo $nbPpa>1?'s':''; ?></div>
                </div>
            </div>
            <div class="ppa-card-foot">
                <span class="badge badge-green"><i class="fas fa-shield-halved"></i> PPA valide</span>
                <?php if($casActive>0):?><span class="badge badge-red"><i class="fas fa-scale-balanced"></i> <?php echo $casActive; ?> infraction<?php echo $casActive>1?'s':''; ?></span>
                <?php elseif($casTotal>0):?><span class="badge badge-orange"><i class="fas fa-scale-balanced"></i> Antécédents</span>
                <?php else:?><span class="badge badge-gray"><i class="fas fa-check"></i> Casier vierge</span><?php endif;?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Fiche détaillée ── -->
<div id="ppaHolderModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-id-card"></i> Fiche du détenteur PPA</h2>
            <button class="modal-close" onclick="Company.closeModal('ppaHolderModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="phmBody"></div>
    </div>
</div>

<script>
const PPA_HOLDERS = <?php echo json_encode($holdersData, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const PPA_BADGE = {demande:['badge-yellow','En attente'],en_cours:['badge-blue','En cours'],valide:['badge-green','Validé'],refuse:['badge-red','Refusé']};
function phmEsc(s){ var d=document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; }
function openHolder(id){
    var h = PPA_HOLDERS[id]; if(!h) return;
    var av = h.avatar ? '<img src="'+phmEsc(h.avatar)+'" alt="" referrerpolicy="no-referrer">' : phmEsc(h.init||'?');
    var html = '<div class="phm-hd"><div class="phm-av">'+av+'</div><div style="min-width:0;flex:1">'+
        '<div class="phm-name">'+phmEsc(h.name)+'</div>'+
        '<div class="phm-fields">'+
            '<div><div class="phm-field-l">Nom</div><div class="phm-field-v">'+phmEsc(h.nom||'—')+'</div></div>'+
            '<div><div class="phm-field-l">Prénom</div><div class="phm-field-v">'+phmEsc(h.prenom||'—')+'</div></div>'+
            '<div><div class="phm-field-l">Âge</div><div class="phm-field-v">'+(h.age?phmEsc(h.age)+' ans':'—')+'</div></div>'+
            '<div><div class="phm-field-l">Statut PPA</div><div class="phm-field-v"><span class="badge badge-green"><i class="fas fa-shield-halved"></i> Valide</span></div></div>'+
        '</div></div></div>';

    html += '<div class="phm-sec-t"><i class="fas fa-id-card-clip"></i> Licences PPA ('+h.ppas.length+')</div>';
    if (h.ppas.length) {
        html += h.ppas.map(function(p){
            var b = PPA_BADGE[p.statut] || ['badge-gray', p.statut];
            var s = '<div class="phm-ppa"><div class="phm-ppa-main"><i class="fas fa-gun"></i>'+phmEsc(p.type)+'</div>'+
                '<span class="badge '+b[0]+'">'+phmEsc(b[1])+'</span>';
            if (p.date) s += '<span class="phm-ppa-date"><i class="fas fa-calendar" style="opacity:.6;margin-right:.25rem"></i>'+phmEsc(p.date)+'</span>';
            if (p.final) s += '<button type="button" class="btn btn-secondary btn-sm" data-doc="'+phmEsc(p.final)+'" data-title="Certificat PPA"><i class="fas fa-file-shield"></i> Certificat</button>';
            if (p.sams)  s += '<button type="button" class="btn btn-ghost btn-sm" data-doc="'+phmEsc(p.sams)+'" data-title="Justificatif SAMS"><i class="fas fa-file-medical"></i> SAMS</button>';
            return s + '</div>';
        }).join('');
    } else {
        html += '<div class="phm-empty">Aucune licence PPA enregistrée pour ce citoyen.</div>';
    }

    html += '<div class="phm-sec-t"><i class="fas fa-scale-balanced"></i> Casier judiciaire ('+h.casier.length+')</div>';
    if (h.casier.length) {
        html += h.casier.map(function(c){
            var s = '<div class="phm-inf"><div class="phm-inf-main"><i class="fas fa-gavel"></i>'+phmEsc(c.title)+(c.active?'':' <span class="phm-classe">(classé)</span>')+'</div>'+
                '<span class="phm-inf-date">'+phmEsc(c.date)+'</span>';
            if (c.jail > 0) s += '<span class="phm-inf-jail"><i class="fas fa-handcuffs" style="margin-right:.2rem"></i>'+c.jail+' mois</span>';
            if (c.fine > 0) s += '<span class="phm-inf-fine">$'+Number(c.fine).toLocaleString('fr-FR')+'</span>';
            return s + '</div>';
        }).join('');
    } else {
        html += '<div class="phm-vierge"><i class="fas fa-check-circle" style="color:#4ade80;margin-right:.4rem"></i>Casier vierge — aucune infraction enregistrée.</div>';
    }

    var body = document.getElementById('phmBody');
    body.innerHTML = html;
    body.querySelectorAll('[data-doc]').forEach(function(btn){
        btn.addEventListener('click', function(){ if(window.mdtOpenDoc) mdtOpenDoc(btn.dataset.doc, 'doc', btn.dataset.title || 'Document'); });
    });
    Company.openModal('ppaHolderModal');
}
window.openHolder = openHolder;
</script>
<?php include ROOT_PATH . '/includes/doc_viewer.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
