<?php
// pages/staff/content/dispatch-theme.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();

$lspdName = 'SASP';
try {
    $r = $db->prepare("SELECT custom_name FROM admin_panel_config WHERE panel_key = 'LSPD' LIMIT 1");
    $r->execute(); $row = $r->fetch();
    if ($row && $row['custom_name']) $lspdName = $row['custom_name'];
} catch (Exception $e) {}

$pageTitle  = 'Apparence Dispatch ' . $lspdName;
$breadcrumb = [['label' => 'Panels', 'url' => 'panels.php'], ['label' => 'Apparence Dispatch ' . $lspdName]];
$success = $error = '';

// Assurer la table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS dispatch_config (config_key VARCHAR(64) PRIMARY KEY, config_value TEXT)");
} catch (Exception $e) {}

// Valeurs par défaut
$defaults = [
    'bg_type'        => 'gradient',
    'bg_color'       => '#060f1d',
    'bg_image'       => '',
    'bg_overlay'     => '0.55',
    'accent'         => '#00e5ff',
    'board_title'    => 'DISPATCH BOARD',
    'col_opacity'    => '0.88',
    'card_radius'    => '12',
    'topbar_opacity' => '0.97',
    'col_blur'       => '8',
];

// Charger depuis DB
$theme = $defaults;
try {
    $row = $db->prepare("SELECT config_value FROM dispatch_config WHERE config_key='dispatch_theme' LIMIT 1");
    $row->execute();
    $saved = $row->fetchColumn();
    if ($saved) $theme = array_merge($defaults, json_decode($saved, true) ?: []);
} catch (Exception $e) {}

// Dossier upload backgrounds
define('BG_UPLOAD_DIR', __DIR__ . '/../../../public/uploads/dispatch/bg/');
define('BG_UPLOAD_URL', SITE_URL . '/public/uploads/dispatch/bg/');

/* ── POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_theme') {
        $theme['bg_type']        = in_array($_POST['bg_type']??'',['gradient','solid','image']) ? $_POST['bg_type'] : 'gradient';
        $theme['bg_color']       = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['bg_color']??'') ? $_POST['bg_color'] : '#060f1d';
        $theme['accent']         = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent']??'')    ? $_POST['accent']  : '#00e5ff';
        $theme['board_title']    = mb_substr(trim($_POST['board_title']??'DISPATCH BOARD'), 0, 40) ?: 'DISPATCH BOARD';
        $theme['bg_overlay']     = (string)max(0, min(1, (float)($_POST['bg_overlay']??0.55)));
        $theme['col_opacity']    = (string)max(0, min(1, (float)($_POST['col_opacity']??0.88)));
        $theme['topbar_opacity'] = (string)max(0, min(1, (float)($_POST['topbar_opacity']??0.97)));
        $theme['col_blur']       = (string)max(0, min(30, (int)($_POST['col_blur']??8)));
        $theme['card_radius']    = (string)max(4, min(24, (int)($_POST['card_radius']??12)));

        // Upload image de fond
        if (!empty($_FILES['bg_image_file']['name'])) {
            $f = $_FILES['bg_image_file'];
            if (!in_array($f['type'], ['image/jpeg','image/png','image/webp','image/gif'])) {
                $error = 'Format image invalide (JPG, PNG, WEBP, GIF).';
            } elseif ($f['size'] > 8 * 1024 * 1024) {
                $error = 'Image trop lourde (max 8 MB).';
            } else {
                if (!is_dir(BG_UPLOAD_DIR)) mkdir(BG_UPLOAD_DIR, 0755, true);
                $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                $fn  = 'dispatch_bg_' . time() . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], BG_UPLOAD_DIR . $fn)) {
                    // Supprimer l'ancienne
                    if (!empty($theme['bg_image'])) {
                        $oldFile = BG_UPLOAD_DIR . basename($theme['bg_image']);
                        if (file_exists($oldFile)) @unlink($oldFile);
                    }
                    $theme['bg_image'] = BG_UPLOAD_URL . $fn;
                } else {
                    $error = 'Erreur lors de l\'upload de l\'image.';
                }
            }
        } elseif (!empty($_POST['bg_image_url'])) {
            $theme['bg_image'] = filter_var($_POST['bg_image_url'], FILTER_VALIDATE_URL) ? $_POST['bg_image_url'] : $theme['bg_image'];
        }

        if (!$error) {
            $json = json_encode($theme);
            $db->prepare("INSERT INTO dispatch_config (config_key,config_value) VALUES ('dispatch_theme',?) ON DUPLICATE KEY UPDATE config_value=?")
               ->execute([$json, $json]);
            $success = 'Apparence sauvegardée.';
        }
    }

    if ($action === 'reset_theme') {
        $theme = $defaults;
        $json  = json_encode($theme);
        $db->prepare("INSERT INTO dispatch_config (config_key,config_value) VALUES ('dispatch_theme',?) ON DUPLICATE KEY UPDATE config_value=?")
           ->execute([$json, $json]);
        $success = 'Apparence réinitialisée.';
    }

    if ($success) {
        header("Location: dispatch-theme.php?ok=" . urlencode($success));
        exit;
    }
}

if (!empty($_GET['ok'])) $success = $_GET['ok'];

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Preview board miniature ── */
.board-preview {
    width: 100%; height: 220px;
    border-radius: 14px;
    overflow: hidden;
    position: relative;
    border: 1px solid var(--border);
    transition: all .3s;
}
.board-preview-bg {
    position: absolute; inset: 0;
    transition: all .3s;
}
.board-preview-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0);
    transition: background .3s;
}
.board-preview-topbar {
    position: relative; z-index: 2;
    height: 36px; display: flex; align-items: center;
    padding: 0 12px; gap: 8px;
    border-bottom: 1px solid rgba(255,255,255,.1);
    transition: background .3s;
}
.board-preview-title {
    font-family: 'DM Mono', monospace;
    font-size: .65rem; font-weight: 800;
    letter-spacing: 2px;
    transition: color .3s;
}
.board-preview-cols {
    position: relative; z-index: 2;
    display: flex; gap: 8px;
    padding: 10px 12px;
}
.board-preview-col {
    width: 80px; height: 120px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,.12);
    overflow: hidden;
    transition: all .3s;
}
.board-preview-col-hdr {
    height: 22px; display: flex; align-items: center;
    padding: 0 7px; gap: 5px;
    border-bottom: 2px solid transparent;
}
.board-preview-col-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.board-preview-col-name { font-size: .5rem; font-weight: 700; color: rgba(255,255,255,.8); }
.board-preview-card {
    margin: 5px 5px 0;
    height: 20px; border-radius: 5px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.08);
}

/* Sliders custom */
.dt-slider-wrap { display: flex; align-items: center; gap: 10px; }
.dt-slider {
    flex: 1;
    -webkit-appearance: none; height: 4px;
    border-radius: 2px; background: var(--border);
    outline: none; cursor: pointer;
}
.dt-slider::-webkit-slider-thumb {
    -webkit-appearance: none; width: 16px; height: 16px;
    border-radius: 50%; background: var(--blue);
    cursor: pointer; border: 2px solid var(--bg-elevated);
    transition: transform .15s;
}
.dt-slider::-webkit-slider-thumb:hover { transform: scale(1.2); }
.dt-slider-val {
    width: 38px; text-align: right;
    font-family: 'DM Mono', monospace;
    font-size: .8rem; color: var(--text-secondary);
    flex-shrink: 0;
}

.bg-type-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
.bg-type-btn {
    padding: 10px; border-radius: 10px;
    border: 2px solid var(--border);
    background: var(--bg-elevated);
    cursor: pointer; text-align: center;
    transition: all .2s; font-size: .8rem;
    color: var(--text-secondary);
}
.bg-type-btn:hover { border-color: var(--border-hover); color: var(--text-primary); }
.bg-type-btn.active { border-color: var(--blue); color: var(--blue); background: rgba(59,130,246,.08); }
.bg-type-btn i { display: block; font-size: 1.2rem; margin-bottom: 5px; }

.upload-zone {
    border: 2px dashed var(--border); border-radius: 10px;
    padding: 20px; text-align: center;
    cursor: pointer; transition: all .2s;
    color: var(--text-secondary); font-size: .82rem;
}
.upload-zone:hover { border-color: var(--blue); color: var(--blue); background: rgba(59,130,246,.04); }
.upload-zone i { font-size: 1.5rem; display: block; margin-bottom: 6px; opacity: .6; }
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1>
            <i class="fa-solid fa-palette" style="color:var(--purple);margin-right:10px;"></i>
            Apparence Dispatch
            <span style="color:var(--cyan);"><?php echo htmlspecialchars($lspdName); ?></span>
        </h1>
        <p>Personnalisez le fond, les couleurs, le titre et l'opacité du board dispatch</p>
    </div>
    <div class="page-header-right">
        <a href="<?php echo SITE_URL; ?>/pages/lspd/dispatch.php" target="_blank" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Voir le Dispatch
        </a>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="reset_theme">
            <button type="submit" class="btn btn-secondary" onclick="return confirm('Réinitialiser l\'apparence aux valeurs par défaut ?')">
                <i class="fa-solid fa-rotate-left"></i> Réinitialiser
            </button>
        </form>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="themeForm">
<input type="hidden" name="action" value="save_theme">

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;">

    <!-- ── Colonne gauche : formulaire ── -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Fond -->
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-image"></i> Fond du board</div></div>
            <div class="card-body">

                <div class="form-group">
                    <label class="form-label">Type de fond</label>
                    <div class="bg-type-grid">
                        <div class="bg-type-btn <?php echo $theme['bg_type']==='gradient'?'active':''; ?>" onclick="setBgType('gradient')">
                            <i class="fa-solid fa-circle-half-stroke"></i> Dégradé
                        </div>
                        <div class="bg-type-btn <?php echo $theme['bg_type']==='solid'?'active':''; ?>" onclick="setBgType('solid')">
                            <i class="fa-solid fa-square"></i> Couleur unie
                        </div>
                        <div class="bg-type-btn <?php echo $theme['bg_type']==='image'?'active':''; ?>" onclick="setBgType('image')">
                            <i class="fa-solid fa-image"></i> Image
                        </div>
                    </div>
                    <input type="hidden" name="bg_type" id="bgTypeInput" value="<?php echo htmlspecialchars($theme['bg_type']); ?>">
                </div>

                <div class="form-group" id="bgColorGroup" style="display:<?php echo $theme['bg_type']==='image'?'none':'block'; ?>;">
                    <label class="form-label">Couleur de fond</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="color" name="bg_color" id="bgColor" class="form-control"
                               value="<?php echo htmlspecialchars($theme['bg_color']); ?>"
                               style="height:38px;padding:4px;width:52px;flex-shrink:0;"
                               oninput="updatePreview()">
                        <span style="font-size:.8rem;color:var(--text-secondary);">Couleur principale du fond</span>
                    </div>
                </div>

                <div id="bgImageGroup" style="display:<?php echo $theme['bg_type']==='image'?'block':'none'; ?>;">
                    <div class="form-group">
                        <label class="form-label">Image de fond — Upload</label>
                        <label class="upload-zone" for="bgImageFile">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            Cliquez pour uploader (JPG, PNG, WEBP — max 8 MB)
                        </label>
                        <input type="file" id="bgImageFile" name="bg_image_file" accept="image/*" style="display:none;"
                               onchange="previewBgImage(this)">
                        <?php if (!empty($theme['bg_image'])): ?>
                        <div style="margin-top:8px;position:relative;display:inline-block;">
                            <img src="<?php echo htmlspecialchars($theme['bg_image']); ?>" alt="" style="height:60px;border-radius:8px;border:1px solid var(--border);">
                            <span style="font-size:.72rem;color:var(--text-secondary);display:block;margin-top:4px;">Image actuelle</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">— ou URL d'image externe</label>
                        <input type="url" name="bg_image_url" class="form-control"
                               value="<?php echo htmlspecialchars($theme['bg_image']); ?>"
                               placeholder="https://exemple.com/image.jpg"
                               oninput="updatePreview()">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Opacité du voile sombre : <span id="overlayVal"><?php echo $theme['bg_overlay']; ?></span></label>
                        <div class="dt-slider-wrap">
                            <input type="range" name="bg_overlay" class="dt-slider" id="overlaySlider"
                                   min="0" max="1" step="0.05"
                                   value="<?php echo htmlspecialchars($theme['bg_overlay']); ?>"
                                   oninput="document.getElementById('overlayVal').textContent=this.value; updatePreview();">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accent & Titre -->
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Accent & Titre</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Couleur d'accent (bordures, texte cyan, etc.)</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="color" name="accent" id="accentColor" class="form-control"
                               value="<?php echo htmlspecialchars($theme['accent']); ?>"
                               style="height:38px;padding:4px;width:52px;flex-shrink:0;"
                               oninput="updatePreview()">
                        <div>
                            <span style="font-size:.8rem;color:var(--text-secondary);">Affecte les bordures, le logo, les badges et les boutons.</span>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Titre du board</label>
                    <input type="text" name="board_title" class="form-control"
                           value="<?php echo htmlspecialchars($theme['board_title']); ?>"
                           maxlength="40" placeholder="DISPATCH BOARD"
                           oninput="updatePreview()">
                </div>
            </div>
        </div>

        <!-- Panneaux -->
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-sliders"></i> Colonnes & Cartes</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Opacité des colonnes : <span id="colOpacityVal"><?php echo $theme['col_opacity']; ?></span></label>
                    <div class="dt-slider-wrap">
                        <input type="range" name="col_opacity" class="dt-slider" id="colOpacitySlider"
                               min="0" max="1" step="0.05"
                               value="<?php echo htmlspecialchars($theme['col_opacity']); ?>"
                               oninput="document.getElementById('colOpacityVal').textContent=this.value; updatePreview();">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Flou des colonnes (backdrop-filter) : <span id="colBlurVal"><?php echo $theme['col_blur']; ?>px</span></label>
                    <div class="dt-slider-wrap">
                        <input type="range" name="col_blur" class="dt-slider" id="colBlurSlider"
                               min="0" max="30" step="1"
                               value="<?php echo htmlspecialchars($theme['col_blur']); ?>"
                               oninput="document.getElementById('colBlurVal').textContent=this.value+'px'; updatePreview();">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Rayon des cartes : <span id="cardRadiusVal"><?php echo $theme['card_radius']; ?>px</span></label>
                    <div class="dt-slider-wrap">
                        <input type="range" name="card_radius" class="dt-slider" id="cardRadiusSlider"
                               min="4" max="24" step="1"
                               value="<?php echo htmlspecialchars($theme['card_radius']); ?>"
                               oninput="document.getElementById('cardRadiusVal').textContent=this.value+'px'; updatePreview();">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Opacité de la barre de navigation : <span id="topbarOpacityVal"><?php echo $theme['topbar_opacity']; ?></span></label>
                    <div class="dt-slider-wrap">
                        <input type="range" name="topbar_opacity" class="dt-slider" id="topbarOpacitySlider"
                               min="0" max="1" step="0.05"
                               value="<?php echo htmlspecialchars($theme['topbar_opacity']); ?>"
                               oninput="document.getElementById('topbarOpacityVal').textContent=this.value; updatePreview();">
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Colonne droite : preview ── -->
    <div style="position:sticky;top:80px;">
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-eye"></i> Aperçu en temps réel</div></div>
            <div class="card-body" style="padding:16px;">

                <div class="board-preview" id="boardPreview">
                    <div class="board-preview-bg" id="previewBg"></div>
                    <div class="board-preview-overlay" id="previewOverlay"></div>
                    <div class="board-preview-topbar" id="previewTopbar">
                        <span class="board-preview-title" id="previewTitle" style="color:#00e5ff;">DISPATCH BOARD</span>
                        <div style="margin-left:auto;display:flex;gap:4px;">
                            <div style="width:20px;height:8px;border-radius:3px;background:rgba(0,229,255,.2);border:1px solid rgba(0,229,255,.3);"></div>
                            <div style="width:20px;height:8px;border-radius:3px;background:rgba(0,229,255,.2);border:1px solid rgba(0,229,255,.3);"></div>
                        </div>
                    </div>
                    <div class="board-preview-cols">
                        <?php foreach (['#00e5ff','#22c55e','#ef4444'] as $col): ?>
                        <div class="board-preview-col" id="previewCol<?php echo str_replace('#','',$col); ?>" style="background:rgba(11,25,41,.88);">
                            <div class="board-preview-col-hdr" style="border-color:<?php echo $col; ?>33;">
                                <div class="board-preview-col-dot" style="background:<?php echo $col; ?>;box-shadow:0 0 5px <?php echo $col; ?>66;"></div>
                                <div class="board-preview-col-name">COLONNE</div>
                            </div>
                            <div class="board-preview-card"></div>
                            <div class="board-preview-card"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:14px;padding:10px 12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:8px;font-size:.75rem;color:var(--text-secondary);line-height:1.6;">
                    <i class="fa-solid fa-circle-info" style="color:var(--blue);margin-right:5px;"></i>
                    L'aperçu est indicatif. Le rendu final peut légèrement varier selon le navigateur.
                </div>
            </div>
        </div>

        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;padding:12px;">
                <i class="fa-solid fa-floppy-disk"></i> Sauvegarder l'apparence
            </button>
        </div>
    </div>

</div>
</form>

<script>
/* ── État courant du thème ── */
const theme = <?php echo json_encode($theme); ?>;

function setBgType(type) {
    document.getElementById('bgTypeInput').value = type;
    document.querySelectorAll('.bg-type-btn').forEach(b => b.classList.remove('active'));
    event.currentTarget.classList.add('active');
    document.getElementById('bgColorGroup').style.display = type === 'image' ? 'none' : 'block';
    document.getElementById('bgImageGroup').style.display = type === 'image' ? 'block' : 'none';
    updatePreview();
}

function previewBgImage(input) {
    if (!input.files[0]) return;
    const url = URL.createObjectURL(input.files[0]);
    document.getElementById('previewBg').style.backgroundImage = `url('${url}')`;
    document.getElementById('previewBg').style.backgroundSize = 'cover';
    document.getElementById('previewBg').style.backgroundPosition = 'center';
    updatePreview();
}

function updatePreview() {
    const bgType   = document.getElementById('bgTypeInput').value;
    const bgColor  = document.getElementById('bgColor').value;
    const accent   = document.getElementById('accentColor').value;
    const title    = document.querySelector('[name="board_title"]').value || 'DISPATCH BOARD';
    const overlay  = parseFloat(document.getElementById('overlaySlider')?.value || 0.55);
    const colOp    = parseFloat(document.getElementById('colOpacitySlider').value);
    const colBlur  = parseInt(document.getElementById('colBlurSlider').value);
    const cardR    = parseInt(document.getElementById('cardRadiusSlider').value);
    const topbarOp = parseFloat(document.getElementById('topbarOpacitySlider').value);

    const previewBg      = document.getElementById('previewBg');
    const previewOverlay = document.getElementById('previewOverlay');
    const previewTopbar  = document.getElementById('previewTopbar');
    const previewTitle   = document.getElementById('previewTitle');

    /* Fond */
    const r = parseInt(accent.slice(1,3),16), g = parseInt(accent.slice(3,5),16), b = parseInt(accent.slice(5,7),16);
    if (bgType === 'image') {
        const urlInp = document.querySelector('[name="bg_image_url"]').value;
        if (urlInp && !previewBg.style.backgroundImage.includes('blob')) {
            previewBg.style.background = `url('${urlInp}') center/cover no-repeat`;
        }
        previewOverlay.style.background = `rgba(0,0,0,${overlay})`;
    } else if (bgType === 'solid') {
        previewBg.style.background = bgColor;
        previewOverlay.style.background = 'none';
    } else {
        previewBg.style.background = `radial-gradient(ellipse 70% 50% at 20% 60%, rgba(${r},${g},${b},.12), transparent), ${bgColor}`;
        previewOverlay.style.background = 'none';
    }

    /* Topbar */
    const topbarBg = `rgba(6,15,29,${topbarOp})`;
    previewTopbar.style.background = topbarBg;
    previewTopbar.style.borderColor = `rgba(${r},${g},${b},.2)`;

    /* Titre */
    previewTitle.textContent = title;
    previewTitle.style.color = accent;

    /* Colonnes */
    document.querySelectorAll('.board-preview-col').forEach(col => {
        col.style.background = `rgba(11,25,41,${colOp})`;
        col.style.backdropFilter = `blur(${Math.min(colBlur,6)}px)`;
        col.style.borderRadius = Math.min(cardR,10) + 'px';
        col.style.borderColor  = `rgba(${r},${g},${b},.15)`;
    });

    /* Cartes */
    document.querySelectorAll('.board-preview-card').forEach(c => {
        c.style.borderRadius = Math.min(cardR,8) + 'px';
        c.style.borderColor  = `rgba(${r},${g},${b},.12)`;
    });
}

/* Init preview */
updatePreview();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>