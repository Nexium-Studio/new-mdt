<?php
/**
 * Configuration générale / thème du panel LSPD (staff).
 * pages/staff/content/lspd/general.php
 *
 * • Sélecteur de thème global LSPD : dark | light | gradient.
 *   Persisté dans admin_panel_config.lspd_theme (panel_key='LSPD'), lu par
 *   pages/lspd/includes/header.php qui pose data-theme sur <html>.
 * • Hub regroupant les outils de configuration LSPD (Tags de rapport,
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

/** Garantit la présence de la colonne lspd_theme et d'une ligne LSPD. */
function lspd_theme_ensure(PDO $db): void {
    try { $db->exec("ALTER TABLE admin_panel_config ADD COLUMN IF NOT EXISTS lspd_theme VARCHAR(20) NULL DEFAULT 'dark'"); } catch (Exception $e) {}
    try { $db->prepare("INSERT IGNORE INTO admin_panel_config (panel_key) VALUES ('LSPD')")->execute(); } catch (Exception $e) {}
}

function lspd_theme_get(PDO $db): string {
    try {
        $s = $db->prepare("SELECT lspd_theme FROM admin_panel_config WHERE panel_key='LSPD' LIMIT 1");
        $s->execute(); $v = $s->fetchColumn();
        if ($v && in_array($v, ['dark','light','gradient'], true)) return $v;
    } catch (Exception $e) {}
    return 'dark';
}

lspd_theme_ensure($db);

// ── Sauvegarde ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_theme') {
    $theme = in_array($_POST['theme'] ?? 'dark', ['dark','light','gradient'], true) ? $_POST['theme'] : 'dark';
    try {
        $db->prepare("UPDATE admin_panel_config SET lspd_theme=? WHERE panel_key='LSPD'")->execute([$theme]);
        $msg = 'Thème LSPD enregistré et appliqué à tout le panel.'; $msgType = 'success';
    } catch (Exception $e) {
        $msg = "Impossible d'enregistrer le thème."; $msgType = 'error';
    }
}

$curTheme = lspd_theme_get($db);

$THEMES = [
    'dark'     => ['name' => 'Dark (par défaut)', 'desc' => 'Bleu foncé LSPD, UI standard MDT. Identité d\'origine du panel.'],
    'light'    => ['name' => 'Light LSPD',        'desc' => 'Gris clair, contraste doux, identité bleue LSPD conservée.'],
    'gradient' => ['name' => 'Gradient',          'desc' => 'Dégradé bleu / cyan animé, accents lumineux, UI dynamique.'],
];

$dispatchActive = true;
try {
    $r = $db->prepare("SELECT active_pages FROM admin_panel_config WHERE panel_key='LSPD' LIMIT 1");
    $r->execute(); $ap = $r->fetchColumn();
    if ($ap) { $apj = json_decode($ap, true) ?: []; if (isset($apj['dispatch']) && $apj['dispatch'] === false) $dispatchActive = false; }
} catch (Exception $e) {}

$pageTitle  = 'Général LSPD';
$breadcrumb = [['label' => 'Contenu'], ['label' => 'LSPD'], ['label' => 'Général']];
include __DIR__ . '/../../includes/header.php';
?>
<style>
.lg-wrap{max-width:960px}
.lg-alert{display:flex;align-items:center;gap:.7rem;padding:.85rem 1.1rem;border-radius:10px;margin-bottom:1.5rem;font-size:.875rem;font-weight:500}
.lg-alert.ok{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);color:#10b981}
.lg-alert.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#ef4444}
.lg-title{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;margin:1.6rem 0 1rem;display:flex;align-items:center;gap:.5rem}
.lg-title i{color:var(--blue,#3b82f6)}
.lg-themes{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem}
.lg-theme{border:2px solid var(--border);border-radius:14px;overflow:hidden;cursor:pointer;background:var(--bg-card);transition:all .2s;position:relative}
.lg-theme:hover{border-color:var(--border-hover);transform:translateY(-2px)}
.lg-theme.selected{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.18)}
.lg-check{position:absolute;top:10px;right:10px;width:24px;height:24px;border-radius:50%;background:#3b82f6;color:#fff;display:none;place-items:center;font-size:.75rem;z-index:2}
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
.pvd{background:#03080f}.pvd .lg-pv-side{background:#050c1e}.pvd .lg-pv-bar{background:#2563eb}.pvd .lg-pv-card{background:#060d1a;border:1px solid rgba(37,99,235,.22)}
.pvl{background:#e9eef6}.pvl .lg-pv-side{background:#f4f7fb}.pvl .lg-pv-bar{background:#2563eb}.pvl .lg-pv-card{background:#fff;border:1px solid rgba(37,99,235,.18)}
.pvg{background:linear-gradient(135deg,#04101f,#03283a)}.pvg .lg-pv-side{background:rgba(6,36,58,.8)}.pvg .lg-pv-bar{background:#22d3ee}.pvg .lg-pv-card{background:rgba(34,211,238,.06);border:1px solid rgba(34,211,238,.2)}
/* hub outils */
.lg-tools{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}
.lg-tool{display:flex;align-items:center;gap:.9rem;padding:1.1rem 1.2rem;border:1px solid var(--border);border-radius:14px;background:var(--bg-card);text-decoration:none;transition:all .2s}
.lg-tool:hover{border-color:#3b82f6;transform:translateY(-2px)}
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

    <div class="lg-title"><i class="fas fa-palette"></i> Thème global du panel LSPD</div>
    <div class="lg-themes">
      <?php
      $pvClass = ['dark' => 'pvd', 'light' => 'pvl', 'gradient' => 'pvg'];
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
    <div style="text-align:right;margin-bottom:.5rem">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer le thème</button>
    </div>
  </form>

  <div class="lg-title"><i class="fas fa-sliders"></i> Outils de configuration LSPD</div>
  <div class="lg-tools">
    <a class="lg-tool" href="<?php echo SITE_URL; ?>/pages/staff/content/report-tags.php">
      <span class="lg-tool-ico" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8)"><i class="fas fa-tags"></i></span>
      <span><span class="lg-tool-name">Tags de rapport</span><span class="lg-tool-desc">Gérer les tags et leurs couleurs</span></span>
      <i class="fas fa-chevron-right"></i>
    </a>
    <?php if ($dispatchActive): ?>
    <a class="lg-tool" href="<?php echo SITE_URL; ?>/pages/staff/content/dispatch-custom.php">
      <span class="lg-tool-ico" style="background:linear-gradient(135deg,#06b6d4,#0e7490)"><i class="fas fa-radio"></i></span>
      <span><span class="lg-tool-name">Custom Dispatch</span><span class="lg-tool-desc">Codes, tags et thème du dispatch</span></span>
      <i class="fas fa-chevron-right"></i>
    </a>
    <?php endif; ?>
  </div>

</div>

<script>
function lgSelectTheme(t) {
  document.getElementById('lgThemeInput').value = t;
  document.querySelectorAll('.lg-theme').forEach(el => el.classList.toggle('selected', el.dataset.theme === t));
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
