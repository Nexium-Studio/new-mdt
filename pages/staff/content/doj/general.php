<?php
/**
 * Configuration générale / thème visuel du panel DOJ
 * pages/staff/content/doj/general.php
 *
 * Permet de choisir un thème (1 = DOJ Classique bleu grisé, 2 = DOJ Sombre,
 * 3 = DOJ Dégradé personnalisé) appliqué automatiquement à tout le panel DOJ
 * via pages/doj/includes/theme.php (lit la table `doj_settings`).
 */
defined('STAFF_PANEL') or define('STAFF_PANEL', true);
require_once dirname(__DIR__, 4) . '/config/config.php';
requireLogin();

if (!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db  = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

/** Lit tous les paramètres DOJ sous forme de tableau clé => valeur. */
function doj_load_settings(PDO $db): array {
    $out = ['theme' => '1', 'gradient_color_1' => '#3b82f6', 'gradient_color_2' => '#1e40af'];
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM doj_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $k => $v) $out[$k] = $v;
    } catch (Exception $e) { /* table absente : valeurs par défaut */ }
    return $out;
}

// ── Sauvegarde ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $theme = in_array($_POST['theme'] ?? '1', ['1','2','3'], true) ? $_POST['theme'] : '1';
    $c1 = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['gradient_color_1'] ?? '') ? $_POST['gradient_color_1'] : '#3b82f6';
    $c2 = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['gradient_color_2'] ?? '') ? $_POST['gradient_color_2'] : '#1e40af';
    try {
        $stmt = $db->prepare(
            "INSERT INTO doj_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute(['theme', $theme]);
        $stmt->execute(['gradient_color_1', $c1]);
        $stmt->execute(['gradient_color_2', $c2]);
        $msg = 'Thème DOJ enregistré et appliqué à tout le panel.'; $msgType = 'success';
    } catch (Exception $e) {
        $msg = "Impossible d'enregistrer. Appliquez d'abord la migration database/migration_doj_refonte.sql."; $msgType = 'error';
    }
}

$settings = doj_load_settings($db);
$curTheme = $settings['theme'];
$c1 = $settings['gradient_color_1'];
$c2 = $settings['gradient_color_2'];

$pageTitle  = 'Général DOJ';
$breadcrumb = [['label' => 'Contenu'], ['label' => 'DOJ'], ['label' => 'Général']];
include __DIR__ . '/../../includes/header.php';
?>
<style>
.sg-wrap { max-width: 920px; }
.sg-alert { display:flex; align-items:center; gap:.7rem; padding:.85rem 1.1rem; border-radius:10px;
  margin-bottom:1.5rem; font-size:.875rem; font-weight:500; }
.sg-alert.ok  { background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.2); color:#10b981; }
.sg-alert.err { background:rgba(239,68,68,.08);  border:1px solid rgba(239,68,68,.2);  color:#ef4444; }

.sg-section-title { font-family:'Syne',sans-serif; font-size:.95rem; font-weight:700; margin-bottom:1rem;
  display:flex; align-items:center; gap:.5rem; }
.sg-section-title i { color:var(--blue, #3b82f6); }

.sg-themes { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.75rem; }
.sg-theme { border:2px solid var(--border); border-radius:14px; overflow:hidden; cursor:pointer;
  background:var(--bg-card); transition:all .2s; position:relative; }
.sg-theme:hover { border-color:var(--border-hover); transform:translateY(-2px); }
.sg-theme.selected { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.18); }
.sg-theme .sg-check { position:absolute; top:10px; right:10px; width:24px; height:24px; border-radius:50%;
  background:#3b82f6; color:#fff; display:none; place-items:center; font-size:.75rem; z-index:2; }
.sg-theme.selected .sg-check { display:grid; }
.sg-preview { height:120px; display:flex; }
.sg-preview .sg-pv-side { width:34%; padding:10px; display:flex; flex-direction:column; gap:6px; }
.sg-preview .sg-pv-main { flex:1; padding:12px; display:flex; flex-direction:column; gap:8px; }
.sg-pv-bar { height:8px; border-radius:4px; opacity:.85; }
.sg-pv-card { flex:1; border-radius:8px; }
.sg-theme-body { padding:.9rem 1.1rem; }
.sg-theme-name { font-weight:700; font-size:.9rem; margin-bottom:.25rem; }
.sg-theme-desc { font-size:.75rem; color:var(--text-secondary); line-height:1.45; }

/* aperçus statiques des 3 thèmes DOJ */
.pv1 { background:#0f172a; } .pv1 .sg-pv-side{background:#1e293b;} .pv1 .sg-pv-bar{background:#3b82f6;} .pv1 .sg-pv-card{background:#1e293b;border:1px solid rgba(59,130,246,.22);}
.pv2 { background:#0c0c0f; } .pv2 .sg-pv-side{background:#151519;} .pv2 .sg-pv-bar{background:#3b82f6;} .pv2 .sg-pv-card{background:#1a1a1f;border:1px solid rgba(255,255,255,.08);}
.pv3 .sg-pv-side{background:rgba(0,0,0,.25);} .pv3 .sg-pv-bar{background:var(--pv3c1);} .pv3 .sg-pv-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);}

.sg-grad-config { background:var(--bg-card); border:1px solid var(--border); border-radius:14px;
  padding:1.4rem 1.5rem; margin-bottom:1.75rem; }
.sg-grad-config.hidden { display:none; }
.sg-colors { display:flex; gap:1.5rem; flex-wrap:wrap; align-items:flex-end; }
.sg-color-field { display:flex; flex-direction:column; gap:.4rem; }
.sg-color-field label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--text-secondary); }
.sg-color-row { display:flex; align-items:center; gap:.6rem; }
.sg-color-row input[type=color] { width:48px; height:42px; padding:2px; border:1px solid var(--border);
  border-radius:9px; background:rgba(255,255,255,.04); cursor:pointer; }
.sg-color-hex { font-family:'DM Mono',monospace; font-size:.8rem; color:var(--text-secondary); min-width:70px; }
.sg-grad-preview { flex:1; min-width:220px; height:64px; border-radius:12px; border:1px solid var(--border); }
</style>

<div class="sg-wrap">

  <?php if ($msg): ?>
  <div class="sg-alert <?php echo $msgType === 'success' ? 'ok' : 'err'; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
  </div>
  <?php endif; ?>

  <form method="POST" id="themeForm">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="theme" id="themeInput" value="<?php echo htmlspecialchars($curTheme); ?>">

    <div class="sg-section-title"><i class="fas fa-palette"></i> Thème du panel DOJ</div>

    <div class="sg-themes">
      <!-- Thème 1 -->
      <div class="sg-theme <?php echo $curTheme==='1'?'selected':''; ?>" data-theme="1" onclick="selectTheme('1')">
        <div class="sg-check"><i class="fas fa-check"></i></div>
        <div class="sg-preview pv1">
          <div class="sg-pv-side"><div class="sg-pv-bar"></div><div class="sg-pv-bar" style="opacity:.4"></div><div class="sg-pv-bar" style="opacity:.4"></div></div>
          <div class="sg-pv-main"><div class="sg-pv-bar" style="width:50%"></div><div class="sg-pv-card"></div></div>
        </div>
        <div class="sg-theme-body">
          <div class="sg-theme-name">Thème 1 — DOJ Classique</div>
          <div class="sg-theme-desc">Style actuel. Bleu grisé, couleurs et identité d'origine du panel DOJ.</div>
        </div>
      </div>

      <!-- Thème 2 -->
      <div class="sg-theme <?php echo $curTheme==='2'?'selected':''; ?>" data-theme="2" onclick="selectTheme('2')">
        <div class="sg-check"><i class="fas fa-check"></i></div>
        <div class="sg-preview pv2">
          <div class="sg-pv-side"><div class="sg-pv-bar"></div><div class="sg-pv-bar" style="opacity:.4"></div><div class="sg-pv-bar" style="opacity:.4"></div></div>
          <div class="sg-pv-main"><div class="sg-pv-bar" style="width:50%"></div><div class="sg-pv-card"></div></div>
        </div>
        <div class="sg-theme-body">
          <div class="sg-theme-name">Thème 2 — DOJ Sombre</div>
          <div class="sg-theme-desc">Fond gris foncé, éléments noirs, accents bleus. Contrastes renforcés.</div>
        </div>
      </div>

      <!-- Thème 3 -->
      <div class="sg-theme <?php echo $curTheme==='3'?'selected':''; ?>" data-theme="3" onclick="selectTheme('3')">
        <div class="sg-check"><i class="fas fa-check"></i></div>
        <div class="sg-preview pv3" id="pv3preview" style="background:linear-gradient(135deg,<?php echo htmlspecialchars($c1); ?>,<?php echo htmlspecialchars($c2); ?>);--pv3c1:<?php echo htmlspecialchars($c1); ?>">
          <div class="sg-pv-side"><div class="sg-pv-bar"></div><div class="sg-pv-bar" style="opacity:.4"></div><div class="sg-pv-bar" style="opacity:.4"></div></div>
          <div class="sg-pv-main"><div class="sg-pv-bar" style="width:50%"></div><div class="sg-pv-card"></div></div>
        </div>
        <div class="sg-theme-body">
          <div class="sg-theme-name">Thème 3 — DOJ Dégradé</div>
          <div class="sg-theme-desc">Deux couleurs personnalisées, dégradé subtil en arrière-plan, prévisualisation en temps réel.</div>
        </div>
      </div>
    </div>

    <!-- Config dégradé (thème 3) -->
    <div class="sg-grad-config <?php echo $curTheme==='3'?'':'hidden'; ?>" id="gradConfig">
      <div class="sg-section-title"><i class="fas fa-droplet"></i> Couleurs du dégradé</div>
      <div class="sg-colors">
        <div class="sg-color-field">
          <label>Couleur 1</label>
          <div class="sg-color-row">
            <input type="color" name="gradient_color_1" id="gc1" value="<?php echo htmlspecialchars($c1); ?>" oninput="updateGradient()">
            <span class="sg-color-hex" id="gc1hex"><?php echo htmlspecialchars($c1); ?></span>
          </div>
        </div>
        <div class="sg-color-field">
          <label>Couleur 2</label>
          <div class="sg-color-row">
            <input type="color" name="gradient_color_2" id="gc2" value="<?php echo htmlspecialchars($c2); ?>" oninput="updateGradient()">
            <span class="sg-color-hex" id="gc2hex"><?php echo htmlspecialchars($c2); ?></span>
          </div>
        </div>
        <div class="sg-grad-preview" id="gradPreview" style="background:linear-gradient(135deg,<?php echo htmlspecialchars($c1); ?>,<?php echo htmlspecialchars($c2); ?>)"></div>
      </div>
    </div>

    <div style="text-align:right">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer le thème</button>
    </div>
  </form>
</div>

<script>
function selectTheme(t) {
  document.getElementById('themeInput').value = t;
  document.querySelectorAll('.sg-theme').forEach(el => el.classList.toggle('selected', el.dataset.theme === t));
  document.getElementById('gradConfig').classList.toggle('hidden', t !== '3');
}
function updateGradient() {
  const c1 = document.getElementById('gc1').value;
  const c2 = document.getElementById('gc2').value;
  document.getElementById('gc1hex').textContent = c1;
  document.getElementById('gc2hex').textContent = c2;
  const grad = `linear-gradient(135deg,${c1},${c2})`;
  document.getElementById('gradPreview').style.background = grad;
  const pv3 = document.getElementById('pv3preview');
  pv3.style.background = grad;
  pv3.style.setProperty('--pv3c1', c1);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
