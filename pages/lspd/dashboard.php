<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
require_once __DIR__ . '/includes/announcements_api.php';
$pageTitle='Dashboard'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0);
$annCanManage = lspd_ann_user_can_manage();
$annCsrf      = lspd_ann_ensure_csrf();

// ── STATS ──────────────────────────────────────────────────────────────
$stats=[];
$qs=[
    'officiers'   =>"SELECT COUNT(*) FROM users WHERE faction='LSPD' AND is_active=1",
    'en_service'  =>"SELECT COUNT(DISTINCT user_id) FROM company_shifts WHERE faction='LSPD' AND shift_end IS NULL",
    'rapports_today'=>"SELECT COUNT(*) FROM reports WHERE faction='LSPD' AND DATE(created_at)=CURDATE()",
    'recherches'  =>"SELECT COUNT(*) FROM arrest_warrants WHERE status='active'",
    'casiers_open'=>"SELECT COUNT(*) FROM criminal_records WHERE status='active'",
    'sanctions_act'=>"SELECT COUNT(*) FROM lspd_sanctions WHERE status='active'",
    'rapports_total'=>"SELECT COUNT(*) FROM reports WHERE faction='LSPD'",
    'infractions'  =>"SELECT COUNT(*) FROM penal_code WHERE active=1",
];
foreach($qs as $k=>$q){try{$stats[$k]=(int)$db->query($q)->fetchColumn();}catch(Exception $e){$stats[$k]=0;}}

// ── OFFICIERS EN SERVICE ─────────────────────────────────────────────
$enService=[];
try{$enService=$db->query("SELECT cs.*,u.username,u.faction_rank FROM company_shifts cs JOIN users u ON u.id=cs.user_id WHERE cs.faction='LSPD' AND cs.shift_end IS NULL ORDER BY cs.shift_start DESC LIMIT 12")->fetchAll();}catch(Exception $e){}

// ── RECHERCHES ACTIVES ───────────────────────────────────────────────
$wantedRecents=[];
try{$wantedRecents=$db->query("SELECT aw.*,p.firstname,p.lastname,u.username AS officer FROM arrest_warrants aw LEFT JOIN profiles p ON p.id=aw.citizen_id LEFT JOIN users u ON u.id=aw.issued_by WHERE aw.status='active' ORDER BY FIELD(aw.severity,'critical','high','medium','low'),aw.issued_date DESC LIMIT 6")->fetchAll();}catch(Exception $e){}

// ── RAPPORTS RÉCENTS ─────────────────────────────────────────────────
$rapRecents=[];
try{$rapRecents=$db->query("SELECT r.*,u.username AS auteur FROM reports r LEFT JOIN users u ON u.id=r.created_by WHERE r.faction='LSPD' ORDER BY r.created_at DESC LIMIT 6")->fetchAll();}catch(Exception $e){}

// ── ANNONCES ─────────────────────────────────────────────────────────
$annonces = lspd_ann_list($db, 10);

// ── MES RAPPORTS ─────────────────────────────────────────────────────
$mesRapports=[];
try{$s=$db->prepare("SELECT * FROM reports WHERE created_by=? AND faction='LSPD' ORDER BY created_at DESC LIMIT 5");$s->execute([$uid]);$mesRapports=$s->fetchAll();}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
?>
<style>
.officer-chip{background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;padding:.7rem .85rem;display:flex;align-items:center;gap:.65rem;transition:border-color .18s}
.officer-chip:hover{border-color:var(--bdr-h)}
.officer-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;box-shadow:0 0 6px #4ade80;flex-shrink:0;animation:pulse2 2s infinite}
@keyframes pulse2{0%,100%{opacity:1}50%{opacity:.5}}
.wanted-mini{background:var(--bg-ov);border-radius:9px;padding:.7rem .85rem;border:1px solid var(--bdr);margin-bottom:.45rem;display:flex;align-items:center;gap:.75rem;transition:border-color .18s}
.wanted-mini:hover{border-color:var(--bdr-h)}
.ann-card{background:var(--bg-ov);border-radius:10px;padding:.9rem;border:1px solid var(--bdr);margin-bottom:.55rem;border-left:3px solid var(--ann-accent,var(--blue));position:relative;transition:border-color .18s,transform .18s}
.ann-card:hover{border-color:var(--bdr-h);transform:translateY(-1px)}
.ann-card .ann-img{display:block;width:100%;max-height:210px;object-fit:cover;border-radius:8px;margin-top:.55rem;border:1px solid var(--bdr);background:rgba(0,0,0,.25)}
.ann-card .ann-img.broken{display:none}
.ann-actions{display:flex;gap:.3rem;align-items:center}
.ann-actions .btn{padding:.25rem .5rem;font-size:.68rem}
.ann-empty{text-align:center;padding:1.5rem;color:var(--txt-m)}
.ann-title{font-weight:700;font-size:.855rem;margin-bottom:.25rem;color:#fff;line-height:1.35}
.ann-meta{font-size:.67rem;color:var(--txt-m);margin-top:.55rem;display:flex;align-items:center;gap:.45rem;flex-wrap:wrap}
.ann-body{font-size:.78rem;color:var(--txt-s);line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
/* Color swatches (create/edit modal) */
.ann-swatches{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.4rem}
.ann-sw{width:28px;height:28px;border-radius:7px;cursor:pointer;border:2px solid transparent;transition:border-color .15s,transform .15s;position:relative}
.ann-sw:hover{transform:scale(1.08)}
.ann-sw.active{border-color:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.18)}
.ann-sw.active::after{content:'\f00c';font-family:'Font Awesome 5 Free';font-weight:900;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:.65rem;color:#fff;text-shadow:0 0 4px rgba(0,0,0,.6)}
.ann-img-preview{display:none;margin-top:.45rem;border:1px solid var(--bdr);border-radius:8px;overflow:hidden;max-height:160px;background:rgba(0,0,0,.2)}
.ann-img-preview.on{display:block}
.ann-img-preview img{display:block;width:100%;max-height:160px;object-fit:cover}
.ann-img-preview .err{padding:.8rem;font-size:.75rem;color:#fca5a5;text-align:center}
.stat-bar{height:5px;background:rgba(37,99,235,.1);border-radius:3px;overflow:hidden;margin-top:.35rem}
.stat-bar-fill{height:100%;background:var(--blue);border-radius:3px;transition:width .5s ease}
</style>

<?php if($stats['en_service']>0): ?>
<div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:12px;padding:.85rem 1.25rem;margin-bottom:1.4rem;display:flex;align-items:center;gap:.85rem">
    <div style="width:8px;height:8px;border-radius:50%;background:#4ade80;box-shadow:0 0 8px #4ade80;animation:pulse2 2s infinite"></div>
    <span style="font-weight:600;color:#6ee7b7"><?php echo $stats['en_service'];?> officier(s) en service actuellement</span>
    <a href="service.php" class="btn btn-success btn-sm" style="margin-left:auto"><i class="fas fa-clock"></i> Prises de service</a>
</div>
<?php endif;?>

<!-- KPIs -->
<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:.85rem">
    <div class="kpi"><div class="kpi-ico"><i class="fas fa-users"></i></div><div class="kpi-lbl">Effectifs LSPD</div><div class="kpi-val"><?php echo $stats['officiers'];?></div></div>
    <div class="kpi"><div class="kpi-ico" style="background:rgba(16,185,129,.15);color:#6ee7b7"><i class="fas fa-circle"></i></div><div class="kpi-lbl">En service</div><div class="kpi-val green"><?php echo $stats['en_service'];?></div></div>
    <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.15);color:#fca5a5"><i class="fas fa-crosshairs"></i></div><div class="kpi-lbl">Avis de recherche</div><div class="kpi-val red"><?php echo $stats['recherches'];?></div></div>
    <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.15);color:#fde68a"><i class="fas fa-file-alt"></i></div><div class="kpi-lbl">Rapports aujourd'hui</div><div class="kpi-val yellow"><?php echo $stats['rapports_today'];?></div></div>
</div>
<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem">
    <div class="kpi"><div class="kpi-lbl">Casiers actifs</div><div class="kpi-val red"><?php echo $stats['casiers_open'];?></div></div>
    <div class="kpi"><div class="kpi-lbl">Sanctions actives</div><div class="kpi-val yellow"><?php echo $stats['sanctions_act'];?></div></div>
    <div class="kpi"><div class="kpi-lbl">Rapports total</div><div class="kpi-val blue"><?php echo $stats['rapports_total'];?></div></div>
    <div class="kpi"><div class="kpi-lbl">Articles code pénal</div><div class="kpi-val"><?php echo $stats['infractions'];?></div></div>
</div>

<div class="grid2">
  <div>
    <!-- EN SERVICE -->
    <div class="card" style="margin-bottom:1.35rem">
        <div class="card-title"><i class="fas fa-circle" style="color:#4ade80"></i> Officiers en service <a href="service.php" class="btn btn-xs btn-secondary" style="margin-left:auto">Tout voir</a></div>
        <?php if(empty($enService)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)"><i class="fas fa-moon" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun officier en service</div>
        <?php else:?>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.45rem">
        <?php foreach($enService as $o): $dur=round((time()-strtotime($o['shift_start']))/60); ?>
        <div class="officer-chip">
            <div class="officer-dot"></div>
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($o['username']);?></div>
                <div style="font-size:.68rem;color:var(--txt-s)"><?php echo htmlspecialchars($o['faction_rank']??'—');?></div>
            </div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:.72rem;color:#4ade80;flex-shrink:0"><?php echo $dur>=60?floor($dur/60).'h'.($dur%60).'m':$dur.'min';?></div>
        </div>
        <?php endforeach;?>
        </div>
        <?php endif;?>
    </div>
    <!-- ANNONCES -->
    <div class="card" id="annCard">
        <div class="card-title">
            <i class="fas fa-bullhorn"></i> Annonces LSPD
            <?php if($annCanManage):?>
            <button type="button" class="btn btn-primary btn-xs" style="margin-left:auto" onclick="ANN.openCreate()">
                <i class="fas fa-plus"></i> Nouvelle annonce
            </button>
            <?php endif;?>
        </div>
        <div id="annList">
            <?php if(empty($annonces)):?>
                <div class="ann-empty"><i class="fas fa-bullhorn" style="font-size:2rem;opacity:.15;display:block;margin-bottom:.5rem"></i>Aucune annonce</div>
            <?php else: foreach($annonces as $a):
                $accent = !empty($a['color']) ? $a['color'] : '#2563eb';
                $author = trim((string) ($a['author_name'] ?? ($a['username'] ?? '—')));
                if ($author === '' || $author === '—') { $author = (string) ($a['username'] ?? '—'); }
                $when   = !empty($a['created_at']) ? date('d/m/Y H:i', strtotime((string) $a['created_at'])) : '';
                $imgUrl = trim((string) ($a['image_url'] ?? ''));
            ?>
            <div class="ann-card" data-id="<?php echo (int) $a['id'];?>" style="--ann-accent:<?php echo htmlspecialchars($accent);?>">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.65rem">
                    <div style="flex:1;min-width:0">
                        <div class="ann-title"><?php echo htmlspecialchars($a['title']);?></div>
                        <div class="ann-body"><?php echo htmlspecialchars($a['content']);?></div>
                    </div>
                    <?php if($annCanManage):?>
                    <div class="ann-actions">
                        <button type="button" class="btn btn-ghost" title="Modifier" onclick="ANN.openEdit(<?php echo (int) $a['id'];?>)"><i class="fas fa-pen-to-square"></i></button>
                        <button type="button" class="btn btn-ghost" title="Supprimer" style="color:#fca5a5" onclick="ANN.confirmDelete(<?php echo (int) $a['id'];?>,<?php echo htmlspecialchars(json_encode($a['title'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT));?>)"><i class="fas fa-trash"></i></button>
                    </div>
                    <?php endif;?>
                </div>
                <?php if($imgUrl !== ''):?>
                <img src="<?php echo htmlspecialchars($imgUrl);?>" alt="Illustration" class="ann-img" loading="lazy" referrerpolicy="no-referrer" onerror="this.classList.add('broken')">
                <?php endif;?>
                <div class="ann-meta">
                    <i class="fas fa-user-shield" style="color:var(--ann-accent,var(--blue))"></i>
                    <span><?php echo htmlspecialchars($author);?><?php if(!empty($a['author_rank'])):?> <span style="color:var(--txt-s)">· <?php echo htmlspecialchars($a['author_rank']);?></span><?php endif;?></span>
                    <span style="margin-left:auto;font-family:'IBM Plex Mono',monospace"><?php echo htmlspecialchars($when);?></span>
                </div>
            </div>
            <?php endforeach; endif;?>
        </div>
    </div>
  </div>
  <div>
    <!-- RECHERCHES -->
    <div class="card" style="margin-bottom:1.35rem">
        <div class="card-title"><i class="fas fa-crosshairs" style="color:#fca5a5"></i> Avis de recherche actifs <a href="recherches.php" class="btn btn-xs btn-secondary" style="margin-left:auto">Tous</a></div>
        <?php if(empty($wantedRecents)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucun avis actif</div>
        <?php else:foreach($wantedRecents as $w):
            $sC=['critical'=>'bg-red','high'=>'bg-yellow','medium'=>'bg-blue','low'=>'bg-green'][$w['severity']]??'bg-gray';
        ?>
        <div class="wanted-mini">
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.845rem"><?php echo htmlspecialchars(($w['firstname']??'').' '.($w['lastname']??''));?></div>
                <div style="font-size:.72rem;color:var(--txt-s)"><?php echo htmlspecialchars($w['offense_title']);?></div>
            </div>
            <span class="badge <?php echo $sC;?>"><?php echo strtoupper($w['severity']??'');?></span>
        </div>
        <?php endforeach;endif;?>
    </div>
    <!-- RAPPORTS RÉCENTS -->
    <div class="card">
        <div class="card-title"><i class="fas fa-file-alt"></i> Rapports récents <a href="rapports.php" class="btn btn-xs btn-secondary" style="margin-left:auto">Tous</a></div>
        <?php if(empty($rapRecents)):?><div style="text-align:center;padding:1.5rem;color:var(--txt-m)">Aucun rapport</div>
        <?php else:foreach($rapRecents as $r):
            $tColor=['RA'=>'bg-blue','Shooting Report'=>'bg-red','Traffic Ticket'=>'bg-yellow'][$r['type']]??'bg-gray';
        ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.65rem .75rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;margin-bottom:.4rem">
            <span class="badge <?php echo $tColor;?>" style="flex-shrink:0;font-size:.62rem"><?php echo htmlspecialchars($r['type']??'—');?></span>
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($r['title']??'Sans titre');?></div>
                <div style="font-size:.7rem;color:var(--txt-s)"><?php echo htmlspecialchars($r['auteur']??'—');?> · <?php echo date('d/m H:i',strtotime($r['created_at']));?></div>
            </div>
        </div>
        <?php endforeach;endif;?>
    </div>
  </div>
</div>

<!-- ACTIONS RAPIDES -->
<div class="card">
    <div class="card-title"><i class="fas fa-bolt"></i> Actions rapides</div>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <a href="rapports.php?new=1" class="btn btn-primary"><i class="fas fa-file-plus"></i> Nouveau rapport</a>
        <a href="recherches.php?new=1" class="btn btn-secondary"><i class="fas fa-crosshairs"></i> Avis de recherche</a>
        <a href="casiers.php?new=1" class="btn btn-secondary"><i class="fas fa-gavel"></i> Nouveau casier</a>
        <a href="profils.php" class="btn btn-ghost"><i class="fas fa-users"></i> Consulter les profils</a>
        <a href="vehicules.php" class="btn btn-ghost"><i class="fas fa-car"></i> Véhicules</a>
        <a href="service.php" class="btn btn-success"><i class="fas fa-clock"></i> Prise de service</a>
    </div>
</div>
<?php if($annCanManage): ?>
<!-- ══ MODALE CRÉATION / ÉDITION D'ANNONCE ═══════════════════════════════ -->
<div class="modal-ov hidden" id="annFormModal">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2 id="annFormTitle"><i class="fas fa-bullhorn"></i> Nouvelle annonce</h2>
            <button class="modal-cl" type="button" onclick="LSPD.closeModal('annFormModal')">×</button>
        </div>
        <form id="annForm" onsubmit="return ANN.submit(event)">
            <div class="modal-bd">
                <input type="hidden" name="id" id="annId" value="">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label" for="annTitleInp">Titre *</label>
                        <input id="annTitleInp" name="title" type="text" class="form-input" required maxlength="255" placeholder="Titre de l'annonce">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label" for="annContentInp">Description *</label>
                        <textarea id="annContentInp" name="content" class="form-textarea" rows="5" required maxlength="5000" placeholder="Contenu de l'annonce..."></textarea>
                    </div>
                    <div class="form-group form-full" id="annColorGroup">
                        <label class="form-label">Couleur d'accent</label>
                        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                            <input id="annColorInp" name="color" type="color" class="form-input" style="width:54px;height:38px;padding:2px;cursor:pointer" value="#2563eb">
                            <input id="annColorHex" type="text" class="form-input" style="max-width:120px;font-family:'IBM Plex Mono',monospace" value="#2563eb" maxlength="7">
                            <div class="ann-swatches" id="annSwatches">
                                <span class="ann-sw" style="background:#2563eb" data-c="#2563eb" title="Bleu"></span>
                                <span class="ann-sw" style="background:#10b981" data-c="#10b981" title="Vert"></span>
                                <span class="ann-sw" style="background:#f59e0b" data-c="#f59e0b" title="Jaune"></span>
                                <span class="ann-sw" style="background:#ef4444" data-c="#ef4444" title="Rouge"></span>
                                <span class="ann-sw" style="background:#8b5cf6" data-c="#8b5cf6" title="Violet"></span>
                                <span class="ann-sw" style="background:#06b6d4" data-c="#06b6d4" title="Cyan"></span>
                                <span class="ann-sw" style="background:#6b7280" data-c="#6b7280" title="Gris"></span>
                            </div>
                        </div>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label" for="annImageInp">Lien de l'image (optionnel)</label>
                        <input id="annImageInp" name="image_url" type="url" class="form-input" maxlength="2048" placeholder="https://exemple.com/image.png">
                        <small style="color:var(--txt-m);font-size:.7rem">Formats acceptés : .jpg, .jpeg, .png, .gif, .webp, .avif, .svg</small>
                        <div id="annImgPrev" class="ann-img-preview"><img id="annImgPrevImg" alt="Aperçu" referrerpolicy="no-referrer"><div class="err" id="annImgPrevErr" style="display:none">Image inaccessible à cette URL</div></div>
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('annFormModal')">Annuler</button>
                <button type="submit" class="btn btn-primary" id="annSubmitBtn"><i class="fas fa-plus"></i> <span id="annSubmitLbl">Ajouter l'annonce</span></button>
            </div>
        </form>
    </div>
</div>

<!-- ══ MODALE CONFIRMATION SUPPRESSION ══════════════════════════════════ -->
<div class="modal-ov hidden" id="annDeleteModal">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2 style="color:#fca5a5"><i class="fas fa-triangle-exclamation" style="color:#fca5a5"></i> Supprimer l'annonce</h2>
            <button class="modal-cl" type="button" onclick="LSPD.closeModal('annDeleteModal')">×</button>
        </div>
        <div class="modal-bd">
            <div class="alert" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#fca5a5;border-radius:10px;padding:.8rem 1rem;margin-bottom:1rem;display:flex;gap:.6rem;align-items:flex-start">
                <i class="fas fa-circle-exclamation" style="margin-top:.15rem"></i>
                <div>Cette action est <strong>irréversible</strong>. L'annonce ne sera plus visible par les officiers.</div>
            </div>
            <div style="padding:.85rem 1rem;border:1px solid var(--bdr);border-radius:10px;background:var(--bg-ov)">
                <div style="font-size:.7rem;color:var(--txt-m);margin-bottom:.25rem">Annonce à supprimer</div>
                <div id="annDelTitle" style="font-weight:700;color:#fff"></div>
            </div>
        </div>
        <div class="modal-ft">
            <button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('annDeleteModal')">Annuler</button>
            <button type="button" class="btn btn-danger" id="annDelConfirm" onclick="ANN.doDelete()"><i class="fas fa-trash"></i> Supprimer l'annonce</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($annCanManage): ?>
<script>
(function(){
const CSRF  = <?php echo json_encode($annCsrf, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const EP    = { create:'ajax/announcement_create.php', update:'ajax/announcement_update.php', del:'ajax/announcement_delete.php' };
const IMG_RE= /\.(jpe?g|png|gif|webp|avif|svg|bmp)(\?.*)?$/i;

const ANN = window.ANN = {
    _mode: 'create',
    _pendingDelete: 0,

    openCreate(){
        this._mode = 'create';
        document.getElementById('annFormTitle').innerHTML = '<i class="fas fa-bullhorn"></i> Nouvelle annonce';
        document.getElementById('annSubmitLbl').textContent = "Ajouter l'annonce";
        document.getElementById('annSubmitBtn').querySelector('i').className = 'fas fa-plus';
        document.getElementById('annId').value = '';
        document.getElementById('annTitleInp').value = '';
        document.getElementById('annContentInp').value = '';
        document.getElementById('annImageInp').value = '';
        this._setColor('#2563eb');
        this._updatePreview('');
        LSPD.openModal('annFormModal');
        setTimeout(()=>document.getElementById('annTitleInp').focus(), 120);
    },

    openEdit(id){
        const card = document.querySelector('.ann-card[data-id="'+id+'"]');
        if(!card){ LSPD.toast('Annonce introuvable', 'error'); return; }
        const title = card.querySelector('.ann-title')?.textContent || '';
        const body  = card.querySelector('.ann-body')?.textContent || '';
        const img   = card.querySelector('.ann-img')?.getAttribute('src') || '';
        const color = (getComputedStyle(card).getPropertyValue('--ann-accent').trim()) || '#2563eb';

        this._mode = 'edit';
        document.getElementById('annFormTitle').innerHTML = '<i class="fas fa-pen-to-square"></i> Modifier l\'annonce';
        document.getElementById('annSubmitLbl').textContent = "Modifier l'annonce";
        document.getElementById('annSubmitBtn').querySelector('i').className = 'fas fa-check';
        document.getElementById('annId').value = String(id);
        document.getElementById('annTitleInp').value = title;
        document.getElementById('annContentInp').value = body;
        document.getElementById('annImageInp').value = img;
        this._setColor(this._normalizeColor(color));
        this._updatePreview(img);
        LSPD.openModal('annFormModal');
    },

    confirmDelete(id, title){
        this._pendingDelete = id|0;
        document.getElementById('annDelTitle').textContent = title || '(sans titre)';
        LSPD.openModal('annDeleteModal');
    },

    async doDelete(){
        const id = this._pendingDelete|0;
        if(!id) return;
        const btn = document.getElementById('annDelConfirm');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Suppression...';
        try {
            const r = await fetch(EP.del, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({ csrf:CSRF, id }) });
            const j = await r.json();
            if(!r.ok || !j.ok){ throw new Error(j.error || 'Erreur'); }
            const card = document.querySelector('.ann-card[data-id="'+id+'"]');
            if(card){ card.style.transition='opacity .2s,transform .2s'; card.style.opacity='0'; card.style.transform='translateX(20px)'; setTimeout(()=>{card.remove(); ANN._maybeEmpty();},200); }
            LSPD.closeModal('annDeleteModal');
            LSPD.toast('Annonce supprimée', 'success');
        } catch(e){
            LSPD.toast(e.message || 'Erreur lors de la suppression', 'error');
        } finally {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash"></i> Supprimer l\'annonce';
            this._pendingDelete = 0;
        }
    },

    async submit(ev){
        ev.preventDefault();
        const id      = parseInt(document.getElementById('annId').value, 10) || 0;
        const title   = document.getElementById('annTitleInp').value.trim();
        const content = document.getElementById('annContentInp').value.trim();
        const color   = this._normalizeColor(document.getElementById('annColorInp').value);
        const image   = document.getElementById('annImageInp').value.trim();

        if(!title){ LSPD.toast('Le titre est requis', 'error'); return false; }
        if(!content){ LSPD.toast('La description est requise', 'error'); return false; }
        if(image && !ANN._isValidImgUrl(image)){
            LSPD.toast("Lien d'image invalide (http(s) + .jpg/.png/.webp/...)", 'error');
            return false;
        }

        const btn = document.getElementById('annSubmitBtn');
        const lbl = document.getElementById('annSubmitLbl').textContent;
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Enregistrement...</span>';

        try {
            const ep = this._mode === 'edit' ? EP.update : EP.create;
            const payload = { csrf:CSRF, title, content, color, image_url:image };
            if(this._mode === 'edit') payload.id = id;
            const r = await fetch(ep, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify(payload) });
            const j = await r.json();
            if(!r.ok || !j.ok){ throw new Error(j.error || 'Erreur'); }
            LSPD.toast(this._mode === 'edit' ? 'Annonce modifiée' : 'Annonce créée', 'success');
            LSPD.closeModal('annFormModal');
            setTimeout(()=>window.location.reload(), 500);
        } catch(e){
            LSPD.toast(e.message || 'Erreur', 'error');
            btn.disabled = false;
            btn.innerHTML = (this._mode==='edit' ? '<i class="fas fa-check"></i>' : '<i class="fas fa-plus"></i>') + ' <span id="annSubmitLbl">'+lbl+'</span>';
        }
        return false;
    },

    _maybeEmpty(){
        const list = document.getElementById('annList');
        if(list && list.querySelectorAll('.ann-card').length === 0){
            list.innerHTML = '<div class="ann-empty"><i class="fas fa-bullhorn" style="font-size:2rem;opacity:.15;display:block;margin-bottom:.5rem"></i>Aucune annonce</div>';
        }
    },

    _setColor(hex){
        hex = this._normalizeColor(hex);
        document.getElementById('annColorInp').value = hex;
        document.getElementById('annColorHex').value = hex;
        document.querySelectorAll('#annSwatches .ann-sw').forEach(s=>{
            s.classList.toggle('active', s.getAttribute('data-c').toLowerCase() === hex);
        });
    },

    _normalizeColor(v){
        v = String(v||'').trim().toLowerCase();
        if(/^#([0-9a-f]{3})$/i.test(v)){
            v = '#' + v[1]+v[1] + v[2]+v[2] + v[3]+v[3];
        }
        if(!/^#([0-9a-f]{6})$/i.test(v)) v = '#2563eb';
        return v;
    },

    _isValidImgUrl(u){
        try {
            const p = new URL(u);
            if(p.protocol !== 'http:' && p.protocol !== 'https:') return false;
            return IMG_RE.test(p.pathname);
        } catch(e){ return false; }
    },

    _updatePreview(url){
        const box = document.getElementById('annImgPrev');
        const img = document.getElementById('annImgPrevImg');
        const err = document.getElementById('annImgPrevErr');
        if(!url){ box.classList.remove('on'); img.removeAttribute('src'); img.style.display=''; err.style.display='none'; return; }
        box.classList.add('on'); err.style.display='none'; img.style.display='block';
        img.onerror = ()=>{ img.style.display='none'; err.style.display='block'; };
        img.onload  = ()=>{ err.style.display='none'; img.style.display='block'; };
        img.src = url;
    }
};

// Wire-up événements internes (une seule fois)
document.addEventListener('DOMContentLoaded', ()=>{
    const hex = document.getElementById('annColorHex');
    const col = document.getElementById('annColorInp');
    if(col) col.addEventListener('input', e=>ANN._setColor(e.target.value));
    if(hex) hex.addEventListener('change', e=>ANN._setColor(e.target.value));
    document.querySelectorAll('#annSwatches .ann-sw').forEach(s=>{
        s.addEventListener('click', ()=>ANN._setColor(s.getAttribute('data-c')));
    });
    const imgInp = document.getElementById('annImageInp');
    if(imgInp){
        let to = null;
        imgInp.addEventListener('input', ()=>{
            clearTimeout(to);
            to = setTimeout(()=>ANN._updatePreview(imgInp.value.trim()), 350);
        });
    }
});
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php';?>