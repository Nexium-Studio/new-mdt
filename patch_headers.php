<?php
// ============================================================
//  patch_headers.php — LysoriaMDT
//  Script de patch navigateur (remplace le script bash)
//
//  1. Uploader ce fichier à la RACINE de ton site
//  2. Ouvrir dans le navigateur : https://tonsite.com/patch_headers.php
//  3. Cliquer "Lancer le patch"
//  4. SUPPRIMER ce fichier après utilisation !
// ============================================================

// ── Sécurité basique ──────────────────────────────────────────
// Remplace par un token secret avant d'uploader si tu veux
define('PATCH_TOKEN', 'lysoria_patch_2024');

if (isset($_GET['token']) && $_GET['token'] !== PATCH_TOKEN) {
    http_response_code(403); die('Accès refusé.');
}

$root         = $_SERVER['DOCUMENT_ROOT'];
$dryRun       = isset($_GET['dry']);    // ?dry=1 → simulation sans écrire
$doRun        = isset($_POST['run']);
$results      = [];
$totalPatched = 0;
$totalSkipped = 0;
$totalErrors  = 0;

// ── Fonctions de patch ────────────────────────────────────────

function findHeaders(string $root): array {
    $found = [];
    $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/pages'));
    foreach ($it as $file) {
        if ($file->getFilename() !== 'header.php') continue;
        $path = str_replace('\\', '/', $file->getPathname());
        // Exclure staff, custom, et les non-includes/
        if (strpos($path, '/staff/') !== false) continue;
        if (strpos($path, '/custom/') !== false) continue;
        if (strpos($path, '/includes/') === false) continue;
        $found[] = $path;
    }
    sort($found);
    return $found;
}

function patchFile(string $file, bool $dryRun): array {
    $log = [];

    if (!is_readable($file)) {
        return [['type' => 'error', 'msg' => 'Fichier illisible']];
    }

    $src = file_get_contents($file);

    // Déjà patché ?
    if (strpos($src, 'panel_theme_auto') !== false) {
        return [['type' => 'skip', 'msg' => 'Déjà patché — ignoré']];
    }

    $patched = $src;

    // ── PATCH 1 : require_once après <?php ──
    $inject1 = "require_once \$_SERVER['DOCUMENT_ROOT'] . '/config/panel_theme_auto.php';\n";
    if (preg_match('/^<\?php\s*\n/m', $patched)) {
        $patched = preg_replace('/(<\?php\s*\n)/m', '$1' . $inject1, $patched, 1);
        $log[]   = ['type' => 'ok', 'msg' => 'require_once panel_theme_auto.php ajouté'];
    } else {
        $log[]   = ['type' => 'warn', 'msg' => "<?php introuvable, injection ignorée"];
    }

    // ── PATCH 2 : echo $__panelThemeCSS avant </head> ──
    if (strpos($patched, '__panelThemeCSS') === false && strpos($patched, '</head>') !== false) {
        $patched = str_replace(
            '</head>',
            "    <?php echo \$__panelThemeCSS; ?>\n</head>",
            $patched
        );
        $log[] = ['type' => 'ok', 'msg' => 'echo $__panelThemeCSS injecté avant </head>'];
    }

    // ── PATCH 3 : sidebar-logo-text dynamique ──
    // Remplace <div class="sidebar-logo-text">TEXTE STATIQUE</div>
    $count3 = 0;
    $patched = preg_replace_callback(
        '/<div class="sidebar-logo-text">([^<{?]+)<\/div>/',
        function($m) use (&$count3) {
            $count3++;
            return '<div class="sidebar-logo-text"><?php echo htmlspecialchars($__panelName); ?></div>';
        },
        $patched
    );
    if ($count3 > 0) {
        $log[] = ['type' => 'ok', 'msg' => 'Nom sidebar rendu dynamique ($__panelName)'];
    }

    // ── PATCH 4 : sidebar-logo-subtitle dynamique ──
    $count4 = 0;
    $patched = preg_replace_callback(
        '/<div class="sidebar-logo-subtitle">([^<{?]+)<\/div>/',
        function($m) use (&$count4) {
            $count4++;
            return '<div class="sidebar-logo-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>';
        },
        $patched
    );
    if ($count4 > 0) {
        $log[] = ['type' => 'ok', 'msg' => 'Sous-titre sidebar rendu dynamique ($__panelSubtitle)'];
    }

    if ($patched === $src) {
        return [['type' => 'skip', 'msg' => 'Aucune modification nécessaire']];
    }

    if (!$dryRun) {
        // Backup
        file_put_contents($file . '.bak', $src);
        // Écriture
        if (file_put_contents($file, $patched) === false) {
            return [['type' => 'error', 'msg' => "Impossible d'écrire le fichier (permissions ?)"]];
        }
        $log[] = ['type' => 'ok', 'msg' => 'Fichier sauvegardé ✓ (backup : ' . basename($file) . '.bak)'];
    } else {
        $log[] = ['type' => 'info', 'msg' => '[SIMULATION] Fichier non modifié'];
    }

    return $log;
}

// ── Exécution ─────────────────────────────────────────────────
if ($doRun || $dryRun) {
    $headers = findHeaders($root);
    foreach ($headers as $file) {
        $relPath = str_replace($root, '', $file);
        $log     = patchFile($file, $dryRun);

        $hasError = !empty(array_filter($log, fn($l) => $l['type'] === 'error'));
        $isSkip   = count($log) === 1 && $log[0]['type'] === 'skip';

        if ($hasError) $totalErrors++;
        elseif ($isSkip) $totalSkipped++;
        else $totalPatched++;

        $results[] = ['file' => $relPath, 'log' => $log, 'error' => $hasError, 'skip' => $isSkip];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LysoriaMDT — Patch Thème</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, 'Segoe UI', sans-serif; background: #0a0e1a; color: #e2e8f0; min-height: 100vh; padding: 2rem; }
.container { max-width: 860px; margin: 0 auto; }
h1 { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, #3b82f6, #818cf8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: .25rem; }
.subtitle { color: #64748b; font-size: .875rem; margin-bottom: 2rem; }
.card { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem; }
.card h2 { font-size: 1rem; font-weight: 700; margin-bottom: .75rem; color: #cbd5e1; }
.btns { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 1rem; }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: .65rem 1.25rem; border-radius: 8px; border: none; font-size: .875rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .2s; }
.btn-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; }
.btn-primary:hover { opacity: .9; transform: translateY(-1px); }
.btn-secondary { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); color: #cbd5e1; }
.btn-secondary:hover { background: rgba(255,255,255,.1); }
.btn-danger { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.3); color: #f87171; }
.alert { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .875rem; display: flex; gap: 8px; align-items: flex-start; }
.alert-warn { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3); color: #fbbf24; }
.alert-danger { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: #f87171; }
.file-result { background: rgba(255,255,255,.02); border: 1px solid rgba(255,255,255,.06); border-radius: 8px; margin-bottom: 8px; overflow: hidden; }
.file-header { padding: .6rem 1rem; display: flex; align-items: center; gap: 10px; font-size: .8rem; font-family: monospace; }
.file-header.ok  { border-left: 3px solid #22c55e; }
.file-header.skip{ border-left: 3px solid #64748b; color: #64748b; }
.file-header.err { border-left: 3px solid #ef4444; }
.file-logs { padding: .5rem 1rem .75rem 1.5rem; }
.log-line { font-size: .77rem; padding: 2px 0; display: flex; align-items: center; gap: 6px; }
.log-ok   { color: #4ade80; }
.log-warn { color: #fbbf24; }
.log-info { color: #60a5fa; }
.log-error{ color: #f87171; }
.log-skip { color: #64748b; }
.summary { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 1.5rem; }
.stat { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 10px; padding: 1rem; text-align: center; }
.stat-val { font-size: 2rem; font-weight: 800; }
.stat-label { font-size: .75rem; color: #64748b; margin-top: 2px; }
.green { color: #22c55e; } .gray { color: #64748b; } .red { color: #ef4444; }
code { background: rgba(255,255,255,.07); padding: 1px 6px; border-radius: 4px; font-size: .8rem; color: #93c5fd; }
</style>
</head>
<body>
<div class="container">
    <h1>🎨 LysoriaMDT — Patch Thème Automatique</h1>
    <p class="subtitle">Ajoute le système de thème dynamique à tous les header.php de panels en un clic.</p>

    <?php if (!$doRun && !$dryRun): ?>
    <!-- ── PAGE D'ACCUEIL ── -->
    <div class="card">
        <h2>⚡ Pré-requis</h2>
        <p style="font-size:.875rem;color:#94a3b8;line-height:1.7;">
            Avant de lancer le patch, vérifie que ces fichiers sont bien en place :
        </p>
        <ul style="margin-top:.75rem;padding-left:1.25rem;font-size:.85rem;color:#cbd5e1;line-height:2;">
            <li>📄 <code>config/panel_theme_auto.php</code></li>
            <li>📄 <code>config/panel_theme.php</code> <span style="color:#64748b;">(si tu utilises panel_theme_auto)</span></li>
            <li>🗄️ Migration SQL exécutée <code>(custom_style, custom_subtitle)</code></li>
        </ul>
    </div>

    <div class="alert alert-warn">
        ⚠️ Le script crée un <code>.bak</code> de chaque fichier modifié. Si quelque chose se passe mal, tu peux restaurer depuis les backups.
    </div>

    <?php
    // Lister les fichiers qui seront touchés
    $headers = findHeaders($root);
    ?>

    <div class="card">
        <h2>📋 Fichiers détectés (<?php echo count($headers); ?>)</h2>
        <div style="margin-top:.5rem;">
        <?php foreach ($headers as $h): ?>
            <div style="font-size:.78rem;font-family:monospace;padding:3px 0;color:#94a3b8;">
                <?php
                $rel = str_replace($root, '', $h);
                $already = strpos(file_get_contents($h), 'panel_theme_auto') !== false;
                echo $already
                    ? '<span style="color:#64748b;">⏭ ' . htmlspecialchars($rel) . ' (déjà patché)</span>'
                    : '<span style="color:#60a5fa;">🔧 ' . htmlspecialchars($rel) . '</span>';
                ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="btns">
        <form method="POST">
            <button type="submit" name="run" class="btn btn-primary">🚀 Lancer le patch (<?php echo count($headers); ?> fichiers)</button>
        </form>
        <a href="?dry=1" class="btn btn-secondary">👁 Simulation (sans modifier)</a>
    </div>

    <?php elseif ($dryRun): ?>
    <!-- ── SIMULATION ── -->
    <div class="alert alert-warn">
        ⚠️ Mode SIMULATION — aucun fichier n'a été modifié.
        <a href="/" style="color:#fbbf24;margin-left:8px;"></a>
    </div>
    <?php include_partial_results($results, $totalPatched, $totalSkipped, $totalErrors, $dryRun); ?>

    <?php else: ?>
    <!-- ── RÉSULTATS RÉELS ── -->
    <?php display_results($results, $totalPatched, $totalSkipped, $totalErrors, $dryRun); ?>

    <div class="alert alert-danger" style="margin-top:1.5rem;">
        🗑️ <strong>Pense à supprimer ce fichier !</strong>
        Supprime <code>patch_headers.php</code> depuis ton gestionnaire de fichiers Plesk.
    </div>
    <?php endif; ?>

</div><!-- .container -->

<?php
function display_results(array $results, int $patched, int $skipped, int $errors, bool $dry): void {
    ?>
    <div class="summary">
        <div class="stat"><div class="stat-val green"><?php echo $patched; ?></div><div class="stat-label">Patchés</div></div>
        <div class="stat"><div class="stat-val gray"><?php echo $skipped; ?></div><div class="stat-label">Ignorés</div></div>
        <div class="stat"><div class="stat-val red"><?php echo $errors; ?></div><div class="stat-label">Erreurs</div></div>
    </div>

    <?php foreach ($results as $r):
        $cls = $r['error'] ? 'err' : ($r['skip'] ? 'skip' : 'ok');
        $icon = $r['error'] ? '❌' : ($r['skip'] ? '⏭' : '✅');
    ?>
    <div class="file-result">
        <div class="file-header <?php echo $cls; ?>">
            <?php echo $icon; ?>
            <?php echo htmlspecialchars($r['file']); ?>
        </div>
        <?php if (!$r['skip']): ?>
        <div class="file-logs">
            <?php foreach ($r['log'] as $l):
                $lcls = 'log-' . $l['type'];
                $licon = match($l['type']) {
                    'ok'    => '✓',
                    'warn'  => '⚠',
                    'info'  => 'ℹ',
                    'error' => '✗',
                    default => '·'
                };
            ?>
            <div class="log-line <?php echo $lcls; ?>"><?php echo $licon; ?> <?php echo htmlspecialchars($l['msg']); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach;
}
display_results($results, $totalPatched, $totalSkipped, $totalErrors, $dryRun);
?>

</body>
</html>