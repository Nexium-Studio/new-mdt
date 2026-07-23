<?php
/**
 * Configuration générale / thème du panel LSSD (staff).
 * pages/staff/content/lssd/general.php
 *
 * • Sélecteur de thème global LSSD : vert | light | custom.
 *   Persisté dans admin_panel_config.lssd_theme (panel_key='LSSD'), lu par
 *   pages/lssd/includes/header.php qui pose data-theme sur <html>.
 * • Thème « Personnalisé » : couleurs stockées en JSON dans lssd_theme_custom.
 * • Hub regroupant les outils de configuration LSSD (Tags de rapport,
 *   Custom Dispatch).
 */
defined('STAFF_PANEL') or define('STAFF_PANEL', true);
require_once dirname(__DIR__, 4) . '/config/config.php';
requireLogin();

if (!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db  = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

/** Garantit la présence des colonnes de thème LSSD et d'une ligne LSSD. */
function lssd_theme_ensure(PDO $db): void {
    try { $db->exec("ALTER TABLE admin_panel_config ADD COLUMN IF NOT EXISTS lssd_theme VARCHAR(20) NULL DEFAULT 'vert'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE admin_panel_config ADD COLUMN IF NOT EXISTS lssd_theme_custom TEXT NULL DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->prepare("INSERT IGNORE INTO admin_panel_config (panel_key) VALUES ('LSSD')")->execute(); } catch (Exception $e) {}
}

function lssd_theme_get(PDO $db): string {
    try {
        $s = $db->prepare("SELECT lssd_theme FROM admin_panel_config WHERE panel_key='LSSD' LIMIT 1");
        $s->execute(); $v = $s->fetchColumn();
        if ($v && in_array($v, ['vert','light','custom'], true)) return $v;
    } catch (Exception $e) {}
    return 'vert';
}

function lssd_theme_custom_get(PDO $db): array {
    $def = ['accent'=>'#10b981','accent_l'=>'#34d399','bg'=>'#05100b','bg_c'=>'#081a12'];
    try {
        $s = $db->prepare("SELECT lssd_theme_custom FROM admin_panel_config WHERE panel_key='LSSD' LIMIT 1");
        $s->execute(); $j = json_decode((string)$s->fetchColumn(), true);
        if (is_array($j)) return array_merge($def, array_intersect_key($j, $def));
    } catch (Exception $e) {}
    return $def;
}

lssd_theme_ensure($db);

// ── Sauvegarde ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_theme') {
    $theme = in_array($_POST['theme'] ?? 'vert', ['vert','light','custom'], true) ? $_POST['theme'] : 'vert';
    // Couleurs personnalisées (validées #hex)
    $custom = [];
    foreach (['accent','accent_l','bg','bg_c'] as $k) {
        $v = $_POST['c_'.$k] ?? '';
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $custom[$k] = $v;
    }
    // Dérive accent_xl / bg_e / bg_ov à partir de l'accent + bg pour un rendu cohérent
    if (!empty($custom['accent'])) $custom['accent_xl'] = $custom['accent'];
    try {
        $db->prepare("UPDATE admin_panel_config SET lssd_theme=?, lssd_theme_custom=? WHERE panel_key='LSSD'")
           ->execute([$theme, $custom ? json_encode($custom) : null]);
        $msg = 'Thème LSSD enregistré et appliqué à tout le panel.'; $msgType = 'success';
    } catch (Exception $e) {
        $msg = "Impossible d'enregistrer le thème."; $msgType = 'error';
    }
}

$curTheme  = lssd_theme_get($db);
$curCustom = lssd_theme_custom_get($db);

$THEMES = [
    'vert'   => ['name' => 'Vert (par défaut)', 'desc' => 'Vert LSSD sur fond sombre, identité Sheriff. Thème d\'origine du panel.'],
    'light'  => ['name' => 'Clair',             'desc' => 'Fond clair, contraste doux, accents verts LSSD conservés.'],
    'custom' => ['name' => 'Personnalisé',      'desc' => 'Couleurs libres : accent et fonds définis ci-dessous.'],
];

$dispatchActive = true;
try {
    $r = $db->prepare("SELECT active_pages FROM admin_panel_config WHERE panel_key='LSSD' LIMIT 1");
    $r->execute(); $ap = $r->fetchColumn();
    if ($ap) { $apj = json_decode($ap, true) ?: []; if (isset($apj['dispatch']) && $apj['dispatch'] === false) $dispatchActive = false; }
} catch (Exception $e) {}

$pageTitle  = 'Général LSSD';
$breadcrumb = [['label' => 'Contenu'], ['label' => 'LSSD'], ['label' => 'Général']];
include __DIR__ . '/../../includes/header.php';
?>
<style>
.lg-wrap{max-width:960px}
.lg-alert{display:flex;align-items:center;gap:.7rem;padding:.85rem 1.1rem;border-radius:10px;margin-bottom:1.5rem;font-size:.875rem;font-weight:500}
.lg-alert.ok{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);color:#10b981}
.lg-alert.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#ef4444}
.lg-title{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;margin:1.6rem 0 1rem;display:flex;align-items:center;gap:.5rem}
.lg-title i{color:#10b981}
.lg-themes{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem}
.lg-theme{border:2px solid var(--border);border-radius:14px;overflow:hidden;cursor:pointer;background:var(--bg-card);transition:all .2s;position:relative}
.lg-theme:hover{border-color:var(--border-hover);transform:translateY(-2px)}
.lg-theme.selected{border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.18)}
.lg-check{position:absolute;top:10px;right:10px;width:24px;height:24px;border-radius:50%;background:#10b981;color:#fff;display:none;place-items:center;font-size:.75rem;z-index:2}
.lg-theme.selected .lg-check{display:grid}
.lg-pv{height:120px;display:flex}
.lg-pv-side{width:34%;padding:10px;display:flex;flex-direction:column;gap:6px}
.lg-pv-main{flex:1;padding:12px;display:flex;flex-direction:column;gap:8px}
.lg-pv-bar{height:8px;border-radius:4px;opacity:.85}
.lg-pv-card{flex:1;border-radius:8px}
.lg-theme-body{padding:.9rem 1.1rem}
.lg-theme-name{font-weight:700;font-size:.9rem;margin-bottom:.25rem}
.lg-theme-desc{font-size:.75rem;color:var(--text-secondary);line-height:1.45}
/* aperçus statiques */
.pvv{background:#05100b}.pvv .lg-pv-side{background:#071c12}.pvv .lg-pv-bar{background:#10b981}.pvv .lg-pv-card{background:#081a12;border:1px solid rgba(16,185,129,.22)}
.pvl{background:#e8f5ee}.pvl .lg-pv-side{background:#f2fbf6}.pvl .lg-pv-bar{background:#10b981}.pvl .lg-pv-card{background:#fff;border:1px solid rgba(16,185,129,.18)}
.pvc{background:#0b141a}.pvc .lg-pv-side{background:#111b22}.pvc .lg-pv-bar{background:#34d399}.pvc .lg-pv-card{background:#0f1a20;border:1px solid rgba(52,211,153,.2)}
/* bloc couleurs custom */
.lg-custom{border:1px solid var(--border);border-radius:14px;padding:1.2rem;margin-bottom:1.5rem;background:var(--bg-card)}
.lg-custom.hidden{display:none}
.lg-colors{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem}
.lg-color{display:flex;flex-direction:column;gap:.4rem}
.lg-color label{font-size:.78rem;font-weight:600;color:var(--text-secondary)}
.lg-color input[type=color]{width:100%;height:42px;border:1px solid var(--border);border-radius:8px;background:transparent;cursor:pointer}
/* hub outils */
.lg-tools{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}
.lg-tool{display:flex;align-items:center;gap:.9rem;padding:1.1rem 1.2rem;border:1px solid var(--border);border-radius:14px;background:var(--bg-card);text-decoration:none;transition:all .2s}
.lg-tool:hover{border-color:#10b981;transform:translateY(-2px)}
.lg-tool-ico{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1.05rem;color:#fff;flex-shrink:0}
.lg-tool-name{font-weight:700;font-size:.9rem;color:var(--text-primary)}
.lg-tool-desc{font-size:.75rem;color:var(--text-secondary);margin-top:.15rem}
.lg-tool .fa-chevron-right{margin-left:auto;color:var(--text-secondary);font-size:.8rem}
</style>

<div class="lg-wrap">

  <?php if ($msg): ?>
  <div class="lg-alert <?php echo $msgType === 'success' ? 'ok' : 'err'; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
  </div>
  <?php endif; ?>

  <form method="POST" id="lgThemeForm">
    <input type="hidden" name="action" value="save_theme">
    <input type="hidden" name="theme" id="lgThemeInput" value="<?php echo htmlspecialchars($curTheme); ?>">

    <div class="lg-title"><i class="fas fa-palette"></i> Thème global du panel LSSD</div>
    <div class="lg-themes">
      <?php
      $pvClass = ['vert' => 'pvv', 'light' => 'pvl', 'custom' => 'pvc'];
      foreach ($THEMES as $key => $t): ?>
      <div class="lg-theme <?php echo $curTheme === $key ? 'selected' : ''; ?>" data-theme="<?php echo $key; ?>" onclick="lgSelectTheme('<?php echo $key; ?>')">
        <div class="lg-check"><i class="fas fa-check"></i></div>
        <div class="lg-pv <?php echo $pvClass[$key]; ?>">
          <div class="lg-pv-side"><div class="lg-pv-bar"></div><div class="lg-pv-bar" style="opacity:.4"></div><div class="lg-pv-bar" style="opacity:.4"></div></div>
          <div class="lg-pv-main"><div class="lg-pv-bar" style="width:50%"></div><div class="lg-pv-card"></div></div>
        </div>
        <div class="lg-theme-body">
          <div class="lg-theme-name"><?php echo htmlspecialchars($t['name']); ?></div>
          <div class="lg-theme-desc"><?php echo htmlspecialchars($t['desc']); ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="lg-custom <?php echo $curTheme === 'custom' ? '' : 'hidden'; ?>" id="lgCustomBlock">
      <div class="lg-title" style="margin-top:0"><i class="fas fa-swatchbook"></i> Couleurs personnalisées</div>
      <div class="lg-colors">
        <div class="lg-color"><label>Accent principal</label><input type="color" name="c_accent" value="<?php echo htmlspecialchars($curCustom['accent']); ?>"></div>
        <div class="lg-color"><label>Accent clair</label><input type="color" name="c_accent_l" value="<?php echo htmlspecialchars($curCustom['accent_l']); ?>"></div>
        <div class="lg-color"><label>Fond de page</label><input type="color" name="c_bg" value="<?php echo htmlspecialchars($curCustom['bg']); ?>"></div>
        <div class="lg-color"><label>Fond des cartes</label><input type="color" name="c_bg_c" value="<?php echo htmlspecialchars($curCustom['bg_c']); ?>"></div>
      </div>
    </div>

    <div style="text-align:right;margin-bottom:.5rem">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer le thème</button>
    </div>
  </form>

  <div class="lg-title"><i class="fas fa-sliders"></i> Outils de configuration LSSD</div>
  <div class="lg-tools">
    <a class="lg-tool" href="<?php echo SITE_URL; ?>/pages/staff/content/lssd/report-tags.php">
      <span class="lg-tool-ico" style="background:linear-gradient(135deg,#10b981,#047857)"><i class="fas fa-tags"></i></span>
      <span><span class="lg-tool-name">Tags LSSD</span><span class="lg-tool-desc">Gérer les tags de rapport et leurs couleurs</span></span>
      <i class="fas fa-chevron-right"></i>
    </a>
    <?php if ($dispatchActive): ?>
    <a class="lg-tool" href="<?php echo SITE_URL; ?>/pages/staff/content/lssd/dispatch-custom.php">
      <span class="lg-tool-ico" style="background:linear-gradient(135deg,#059669,#065f46)"><i class="fas fa-radio"></i></span>
      <span><span class="lg-tool-name">Custom Dispatch</span><span class="lg-tool-desc">Codes, tags et thème du dispatch LSSD</span></span>
      <i class="fas fa-chevron-right"></i>
    </a>
    <?php endif; ?>
  </div>

</div>

<script>
function lgSelectTheme(t) {
  document.getElementById('lgThemeInput').value = t;
  document.querySelectorAll('.lg-theme').forEach(el => el.classList.toggle('selected', el.dataset.theme === t));
  var cb = document.getElementById('lgCustomBlock');
  if (cb) cb.classList.toggle('hidden', t !== 'custom');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
