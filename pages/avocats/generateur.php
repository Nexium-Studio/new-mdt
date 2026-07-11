<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Générateur Juridique';
$db = Database::getInstance()->getConnection();

$allClients=$db->query("SELECT ac.id,p.firstname,p.lastname FROM avocat_clients ac JOIN profiles p ON p.id=ac.profile_id WHERE ac.statut='actif' ORDER BY p.lastname")->fetchAll();
$allDossiers=$db->query("SELECT id,numero,titre,chef_accusation FROM avocat_dossiers WHERE statut NOT IN ('ferme','gagne','perdu') ORDER BY updated_at DESC")->fetchAll();
$allLois=$db->query("SELECT article,titre,categorie FROM avocat_lois ORDER BY categorie,article")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<style>
.gen-tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:1px solid var(--bdr);padding-bottom:.75rem}
.gen-tab{padding:.6rem 1.2rem;border-radius:9px 9px 0 0;font-size:.845rem;font-weight:600;cursor:pointer;border:1px solid var(--bdr);background:transparent;color:var(--txt-s);transition:all .18s;font-family:inherit}
.gen-tab.active{background:rgba(217,119,6,.12);border-color:var(--gold);color:var(--gold-l);border-bottom-color:transparent}
.gen-panel{display:none}.gen-panel.active{display:block}
.gen-output{background:var(--bg-ov);border:1px solid var(--bdr);border-radius:10px;padding:1.25rem;font-size:.875rem;line-height:1.8;color:var(--txt-s);white-space:pre-wrap;min-height:200px;font-family:'IBM Plex Sans',serif;position:relative}
.gen-output:empty::before{content:'Le texte généré apparaîtra ici…';color:var(--txt-m);font-style:italic}
.loading-overlay{display:none;position:absolute;inset:0;background:rgba(6,8,15,.8);border-radius:10px;align-items:center;justify-content:center;color:var(--gold-l)}
.loading-overlay.visible{display:flex}
.spin{animation:spin 1s linear infinite}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
</style>

<div class="gen-tabs">
    <button class="gen-tab active" onclick="showTab('contrat')"><i class="fas fa-file-signature"></i> Contrat d'avocat</button>
    <button class="gen-tab" onclick="showTab('plaidoirie')"><i class="fas fa-comments"></i> Plaidoirie</button>
    <button class="gen-tab" onclick="showTab('requete')"><i class="fas fa-scroll"></i> Requête/Acte</button>
</div>

<!-- ── CONTRAT ────────────────────────────────────────────── -->
<div class="gen-panel active" id="tab-contrat">
    <div class="grid2">
        <div class="card">
            <div class="card-title"><i class="fas fa-cog"></i> Paramètres du contrat</div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Client *</label>
                <select id="contrat_client" class="form-select">
                    <option value="">Sélectionner un client…</option>
                    <?php foreach($allClients as $c):?><option value="<?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?>"><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></option><?php endforeach;?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Dossier lié</label>
                <select id="contrat_dossier" class="form-select">
                    <option value="">— Aucun —</option>
                    <?php foreach($allDossiers as $d):?><option value="<?php echo htmlspecialchars($d['titre']);?>"><?php echo htmlspecialchars($d['numero'].' — '.$d['titre']);?></option><?php endforeach;?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Avocat responsable</label>
                <input type="text" id="contrat_avocat" class="form-input" value="<?php echo htmlspecialchars(mdt_session_display_name());?>">
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Honoraires ($)</label>
                <input type="number" id="contrat_honoraires" class="form-input" placeholder="5000" min="0">
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Détails / Conditions particulières</label>
                <textarea id="contrat_details" class="form-textarea" placeholder="Précisez ici les conditions spécifiques du mandat…"></textarea>
            </div>
            <button onclick="generateContrat()" class="btn btn-primary" style="width:100%;justify-content:center">
                <i class="fas fa-wand-magic-sparkles"></i> Générer le contrat
            </button>
        </div>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem">
                <div class="card-title" style="margin:0"><i class="fas fa-file-alt"></i> Contrat généré</div>
                <button onclick="copyOutput('output-contrat')" class="btn btn-secondary btn-sm"><i class="fas fa-copy"></i> Copier</button>
            </div>
            <div class="gen-output" id="output-contrat">
                <div class="loading-overlay" id="loading-contrat"><i class="fas fa-spinner spin" style="font-size:2rem;margin-bottom:.5rem;display:block"></i> Génération en cours…</div>
            </div>
            <button onclick="saveDoc('output-contrat','contrat')" class="btn btn-secondary btn-sm" style="margin-top:.75rem;width:100%;justify-content:center"><i class="fas fa-save"></i> Sauvegarder comme document</button>
        </div>
    </div>
</div>

<!-- ── PLAIDOIRIE ─────────────────────────────────────────── -->
<div class="gen-panel" id="tab-plaidoirie">
    <div class="grid2">
        <div class="card">
            <div class="card-title"><i class="fas fa-cog"></i> Paramètres de la plaidoirie</div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Dossier *</label>
                <select id="plaid_dossier" class="form-select">
                    <option value="">Sélectionner…</option>
                    <?php foreach($allDossiers as $d):?><option value="<?php echo htmlspecialchars($d['titre'].' | '.$d['chef_accusation']);?>"><?php echo htmlspecialchars($d['numero'].' — '.$d['titre']);?></option><?php endforeach;?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Nom du client</label>
                <select id="plaid_client" class="form-select">
                    <option value="">Sélectionner…</option>
                    <?php foreach($allClients as $c):?><option value="<?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?>"><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></option><?php endforeach;?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Arguments de défense</label>
                <textarea id="plaid_args" class="form-textarea" placeholder="Ex: Alibi, légitime défense, erreur de personne, vice de procédure LSPD…"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Articles de loi invoqués</label>
                <select id="plaid_lois" class="form-select">
                    <option value="">— Aucun —</option>
                    <?php foreach($allLois as $l):?><option value="Article <?php echo htmlspecialchars($l['article']);?> — <?php echo htmlspecialchars($l['titre']);?>"><?php echo htmlspecialchars($l['article'].' — '.$l['titre']);?></option><?php endforeach;?>
                </select>
                <input type="text" id="plaid_lois_custom" class="form-input" style="margin-top:.4rem" placeholder="Ou saisir manuellement…">
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Ton</label>
                <select id="plaid_ton" class="form-select">
                    <option value="formel et persuasif">Formel et persuasif</option>
                    <option value="passionné et émotionnel">Passionné et émotionnel</option>
                    <option value="technique et juridique">Technique et juridique</option>
                    <option value="sobre et factuel">Sobre et factuel</option>
                </select>
            </div>
            <button onclick="generatePlaidoirie()" class="btn btn-primary" style="width:100%;justify-content:center">
                <i class="fas fa-wand-magic-sparkles"></i> Générer la plaidoirie
            </button>
        </div>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem">
                <div class="card-title" style="margin:0"><i class="fas fa-comments"></i> Plaidoirie générée</div>
                <button onclick="copyOutput('output-plaidoirie')" class="btn btn-secondary btn-sm"><i class="fas fa-copy"></i> Copier</button>
            </div>
            <div class="gen-output" id="output-plaidoirie">
                <div class="loading-overlay" id="loading-plaidoirie"><i class="fas fa-spinner spin" style="font-size:2rem;margin-bottom:.5rem;display:block"></i> Génération en cours…</div>
            </div>
            <button onclick="saveDoc('output-plaidoirie','plaidoirie')" class="btn btn-secondary btn-sm" style="margin-top:.75rem;width:100%;justify-content:center"><i class="fas fa-save"></i> Sauvegarder comme document</button>
        </div>
    </div>
</div>

<!-- ── REQUÊTE ─────────────────────────────────────────────── -->
<div class="gen-panel" id="tab-requete">
    <div class="grid2">
        <div class="card">
            <div class="card-title"><i class="fas fa-cog"></i> Paramètres de l'acte</div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Type d'acte *</label>
                <select id="req_type" class="form-select">
                    <option value="requête en liberté provisoire">Requête en liberté provisoire</option>
                    <option value="demande de non-lieu">Demande de non-lieu</option>
                    <option value="appel de jugement">Appel de jugement</option>
                    <option value="assignation en justice">Assignation en justice</option>
                    <option value="plainte pour vice de procédure">Plainte pour vice de procédure</option>
                    <option value="demande de réexamen">Demande de réexamen</option>
                    <option value="accord de plaider coupable">Accord de plaider coupable</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Destinataire</label>
                <input type="text" id="req_dest" class="form-input" placeholder="Ex: Honorable Juge Smith, Tribunal de LS">
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Client</label>
                <select id="req_client" class="form-select">
                    <option value="">Sélectionner…</option>
                    <?php foreach($allClients as $c):?><option value="<?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?>"><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></option><?php endforeach;?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.85rem">
                <label class="form-label">Motifs & arguments</label>
                <textarea id="req_motifs" class="form-textarea" style="min-height:100px" placeholder="Arguments à inclure dans l'acte…"></textarea>
            </div>
            <button onclick="generateRequete()" class="btn btn-primary" style="width:100%;justify-content:center">
                <i class="fas fa-wand-magic-sparkles"></i> Générer l'acte
            </button>
        </div>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem">
                <div class="card-title" style="margin:0"><i class="fas fa-scroll"></i> Acte généré</div>
                <button onclick="copyOutput('output-requete')" class="btn btn-secondary btn-sm"><i class="fas fa-copy"></i> Copier</button>
            </div>
            <div class="gen-output" id="output-requete">
                <div class="loading-overlay" id="loading-requete"><i class="fas fa-spinner spin" style="font-size:2rem;margin-bottom:.5rem;display:block"></i> Génération en cours…</div>
            </div>
            <button onclick="saveDoc('output-requete','contrat')" class="btn btn-secondary btn-sm" style="margin-top:.75rem;width:100%;justify-content:center"><i class="fas fa-save"></i> Sauvegarder</button>
        </div>
    </div>
</div>

<!-- SAVE FORM (caché) — textarea pour supporter les contenus longs -->
<form method="POST" action="documents.php" id="saveDocForm" style="display:none">
    <input type="hidden" name="action"     value="create">
    <input type="hidden" name="type"       id="saveDocType"   value="autre">
    <input type="hidden" name="titre"      id="saveDocTitre"  value="">
    <input type="hidden" name="client_id"  value="">
    <input type="hidden" name="dossier_id" value="">
    <textarea name="contenu" id="saveDocContenu" style="display:none"></textarea>
</form>

<script>
function showTab(name) {
    document.querySelectorAll('.gen-tab').forEach((t,i)=>t.classList.remove('active'));
    document.querySelectorAll('.gen-panel').forEach(p=>p.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('tab-'+name).classList.add('active');
}

async function callClaude(prompt, outputId, loadingId) {
    const out = document.getElementById(outputId);
    const loading = document.getElementById(loadingId);
    loading.classList.add('visible');
    out.textContent = '';
    try {
        const res = await fetch('api_claude.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: 'claude-sonnet-4-20250514',
                max_tokens: 2048,
                system: "Tu es un assistant juridique expert dans le droit de Los Santos (univers GTA RP). Tu génères des documents juridiques réalistes en français pour un serveur FiveM. Tes documents doivent être formels, professionnels et adaptés au jeu de rôle policier. Utilise des noms de lieux de GTA (Los Santos, Tribunal de LS, LSPD, etc.). Réponds UNIQUEMENT avec le document, sans commentaire.",
                messages: [{ role: 'user', content: prompt }]
            })
        });

        // Lire le texte brut d'abord, puis parser
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch(parseErr) {
            out.textContent = "Erreur proxy (réponse non-JSON) : " + rawText.substring(0, 400);
            return;
        }

        if (data.error) {
            out.textContent = "Erreur API : " + data.error.message + " (type: " + data.error.type + ")";
            return;
        }
        if (!data.content || !data.content[0] || data.content[0].type !== 'text') {
            out.textContent = "Réponse inattendue : " + JSON.stringify(data).substring(0, 300);
            return;
        }
        out.textContent = data.content[0].text;
    } catch(e) {
        out.textContent = "Erreur réseau : " + e.message;
    } finally {
        loading.classList.remove('visible');
    }
}

function generateContrat() {
    const client = document.getElementById('contrat_client').value || '[CLIENT]';
    const dossier = document.getElementById('contrat_dossier').value || '[DOSSIER]';
    const avocat = document.getElementById('contrat_avocat').value || '[AVOCAT]';
    const honoraires = document.getElementById('contrat_honoraires').value || '[À DÉFINIR]';
    const details = document.getElementById('contrat_details').value;
    const prompt = `Génère un contrat de représentation juridique professionnel entre le cabinet d'avocats de Los Santos et le client suivant :
Client : ${client}
Avocat responsable : Me ${avocat}
Affaire : ${dossier}
Honoraires : $${honoraires}
Conditions particulières : ${details || 'Aucune condition particulière'}
Date : ${new Date().toLocaleDateString('fr-FR')}
Le contrat doit inclure : présentation des parties, objet du mandat, obligations de l'avocat, obligations du client, honoraires et modalités de paiement, confidentialité, résiliation, signatures. Format officiel avec en-tête du cabinet.`;
    callClaude(prompt, 'output-contrat', 'loading-contrat');
}

function generatePlaidoirie() {
    const dossier = document.getElementById('plaid_dossier').value || '[DOSSIER]';
    const client = document.getElementById('plaid_client').value || '[CLIENT]';
    const args = document.getElementById('plaid_args').value || 'arguments à déterminer';
    const lois = document.getElementById('plaid_lois').value || document.getElementById('plaid_lois_custom').value || 'articles pertinents';
    const ton = document.getElementById('plaid_ton').value;
    const prompt = `Génère une plaidoirie de défense ${ton} pour l'affaire suivante :
Client défendu : ${client}
Affaire / accusations : ${dossier}
Arguments de défense : ${args}
Articles de loi invoqués : ${lois}
La plaidoirie doit : s'adresser respectueusement au juge, présenter le client, réfuter les accusations une par une, invoquer les articles de loi, conclure sur une demande de relaxe ou de clémence. Ton : ${ton}. Durée : discours de 3-4 paragraphes substantiels.`;
    callClaude(prompt, 'output-plaidoirie', 'loading-plaidoirie');
}

function generateRequete() {
    const type = document.getElementById('req_type').value;
    const dest = document.getElementById('req_dest').value || 'Monsieur/Madame le Juge';
    const client = document.getElementById('req_client').value || '[CLIENT]';
    const motifs = document.getElementById('req_motifs').value || '';
    const prompt = `Génère une ${type} adressée à ${dest}, pour le client ${client}.
Motifs et arguments à inclure : ${motifs || 'arguments juridiques standards'}
Date : ${new Date().toLocaleDateString('fr-FR')}
Format : acte juridique officiel avec en-tête, référence de dossier, corps de la demande avec attendus numérotés, et formule de conclusion. Style formel et juridique.`;
    callClaude(prompt, 'output-requete', 'loading-requete');
}

function copyOutput(id) {
    const txt = document.getElementById(id).textContent;
    navigator.clipboard.writeText(txt).then(()=>alert('✓ Copié dans le presse-papiers !'));
}

function saveDoc(outputId, type) {
    const content = document.getElementById(outputId).textContent.trim();
    if(!content){alert('Générez d\'abord un document.');return;}
    const titre = prompt('Titre du document à sauvegarder ?', type==='plaidoirie'?'Plaidoirie générée':'Contrat généré');
    if(!titre) return;
    document.getElementById('saveDocType').value = type;
    document.getElementById('saveDocTitre').value = titre;
    document.getElementById('saveDocContenu').value = content;
    document.getElementById('saveDocForm').submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php';?>