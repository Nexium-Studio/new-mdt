<?php
/**
 * scripts/apply_rbac_panels.php
 *
 * Migration RBAC automatique — supprime toutes les permissions hardcodées
 * dans les panels companies/ et panels/ et les remplace par faction_acl.
 *
 * Usage :
 *   ?dry=1  → simulation (défaut) — affiche ce qui serait modifié
 *   ?dry=0  → application réelle
 *
 * Accès : Administrateur / Développeur uniquement.
 */

define('SKIP_LICENSE_GUARD', true);
require_once __DIR__ . '/../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
    http_response_code(403);
    die('Accès refusé — admins uniquement.');
}

$dryRun  = ($_GET['dry'] ?? '1') !== '0';
$results = [];

// ── Bloc RBAC à injecter pour les pages sanctions / depenses / stock / custom ──
// Inclut les aliases des variables hardcodées les plus courantes
$RBAC_MANAGE = "// Permissions — système RBAC centralisé\nrequire_once ROOT_PATH . '/includes/faction_acl.php';\n\$_uid_rbac = (int)(\$_SESSION['user_id'] ?? 0);\nif (!faction_acl_can_view(\$db, \$_uid_rbac, \$companyFaction, basename(__FILE__))) faction_acl_deny();\n\$canManage     = faction_acl_can_edit(\$db, \$_uid_rbac, \$companyFaction, basename(__FILE__));\n\$canDelete     = \$canManage;\n\$canManageTarif = \$canManage;";

// ── Bloc RBAC pour info_vente.php (accès + isStaff) ───────────────────────────
$RBAC_INFO_VENTE = "// Accès — système RBAC centralisé\nrequire_once ROOT_PATH . '/includes/faction_acl.php';\n\$_uid_rbac = (int)(\$_SESSION['user_id'] ?? 0);\nif (\$_SESSION['user_faction'] !== \$companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])) {\n    redirect(SITE_URL . '/pages/dashboard.php');\n}\nif (!faction_acl_can_view(\$db, \$_uid_rbac, \$companyFaction, basename(__FILE__))) faction_acl_deny();\n\$isStaff = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']);";

// ────────────────────────────────────────────────────────────────────────────────

/**
 * Tente d'appliquer le remplacement RBAC dans le contenu d'un fichier.
 * Gère toutes les variantes de permissions hardcodées.
 * Retourne [nouveau_contenu, bool_change_effectué]
 */
function rbac_patch_manage(string $content, string $replacement): array
{
    $patterns = [
        // ── getGradePermissions patterns ────────────────────────────────────────

        // Pattern A : bloc avec commentaire optionnel + grade loading + getGradePermissions
        // Couvre can_recruit, can_manage_stock, can_manage_finances, is_owner, is_ceo
        '/(?:\/\/ [^\n]+\n)?(?:\$__gradeStmt[^\n]+\n\s*\$__gradeStmt[^\n]+\n\s*\$__grade[^\n]+\n)?\s*\$(?:permissions|perms)\s*=\s*getGradePermissions\([^)]+\)\s*;\n\s*\$can\w+\s*=[^\n]+\$(?:permissions|perms)\[[^\]]+\][^\n]+\n(?:\s*\$can\w+\s*=[^\n]+\n)?/',

        // Pattern B : getGradePermissions sans grade loading préalable
        '/\s*\$(?:permissions|perms)\s*=\s*getGradePermissions\([^)]+\)\s*;\s*\n\s*\$can\w+\s*=[^\n]+\$(?:permissions|perms)\[[^\]]+\][^\n]+\n(?:\s*\$can\w+\s*=[^\n]+\n)?/',

        // ── Patterns $isStaff || !empty(myGradePerms[...]) ──────────────────────

        // Pattern C : avec commentaire avant
        '/(?:\/\/ [^\n]+can_(?:recruit|manage)[^\n]*\n(?:\/\/ [^\n]*\n)?)\s*\$can\w+\s*=\s*\$isStaff\s*\|\|[^\n]+\[\'can_(?:recruit|manage_\w+)\'\][^\n]+\n(?:\s*\$can\w+\s*=[^\n]+\n)?/',

        // Pattern D : sans commentaire
        '/\s*\$can\w+\s*=\s*\$isStaff\s*\|\|\s*!empty\s*\(\s*\$\w+\[\'can_(?:recruit|manage_\w+)\'\]\s*\)[^\n]*\n(?:\s*\$can\w+\s*=[^\n]+\n)?/',

        // Pattern E : variante avec hasRole seul (sans !empty)
        '/\s*\$can\w+\s*=\s*\$isStaff\s*\|\|[^\n]+can_(?:recruit|manage)[^\n]+hasRole[^\n]+\n(?:\s*\$can\w+\s*=[^\n]+\n)?/',
    ];

    foreach ($patterns as $pattern) {
        $new = preg_replace($pattern, $replacement . "\n", $content, 1);
        if ($new !== null && $new !== $content) {
            return [$new, true];
        }
    }

    return [$content, false];
}

/**
 * Remplace le bloc d'accès info_vente.php (basé sur __isRHPlus).
 */
function rbac_patch_info_vente(string $content, string $replacement): array
{
    // Le bloc va de la définition de __isStaff jusqu'à la redirection
    $pattern = '/\$__isStaff[^\n]+\n\s*\$__isMember[^\n]+\n\s*\$__isRHPlus\s*=\s*false;.*?if\s*\(!\(\s*\$__isStaff[^\)]+\)\)\s*\{[^}]+\}\s*\n\s*\$isStaff\s*=[^\n]+\n/s';
    if (preg_match($pattern, $content)) {
        $new = preg_replace($pattern, $replacement . "\n", $content, 1);
        if ($new !== null && $new !== $content) {
            return [$new, true];
        }
    }
    // Fallback pattern plus large
    $pattern2 = '/\$__isRHPlus\s*=\s*false;.*?\$isStaff\s*=\s*\$__isStaff;/s';
    if (preg_match($pattern2, $content)) {
        $new = preg_replace($pattern2, ltrim($replacement), $content, 1);
        if ($new !== null && $new !== $content) {
            return [$new, true];
        }
    }
    return [$content, false];
}

// ── Scan des répertoires ────────────────────────────────────────────────────────
$baseDirs = [
    ROOT_PATH . '/pages/companies',
    ROOT_PATH . '/pages/panels',
];

// Pages ciblées et leur type de patch
$pageTypeMap = [
    'sanctions.php'   => 'manage',
    'depenses.php'    => 'manage',
    'stock.php'       => 'manage',
    'custom.php'      => 'manage',
    'biens.php'       => 'manage',
    'fourriere.php'   => 'manage',
    'info_vente.php'  => 'info_vente',
];

foreach ($baseDirs as $baseDir) {
    if (!is_dir($baseDir)) continue;

    foreach (scandir($baseDir) as $entry) {
        if ($entry[0] === '.') continue;
        $companyDir = $baseDir . '/' . $entry;
        if (!is_dir($companyDir)) continue;

        foreach ($pageTypeMap as $page => $patchType) {
            $file = $companyDir . '/' . $page;
            if (!is_readable($file)) continue;

            $content = file_get_contents($file);
            if ($content === false) continue;

            // Ignorer si déjà migré
            if (strpos($content, 'faction_acl_can_edit') !== false
                || strpos($content, 'faction_acl_can_view') !== false) {
                $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => 'déjà migré', 'changed' => false];
                continue;
            }

            if ($patchType === 'manage') {
                [$newContent, $changed] = rbac_patch_manage($content, $RBAC_MANAGE);
            } else {
                [$newContent, $changed] = rbac_patch_info_vente($content, $RBAC_INFO_VENTE);
            }

            if ($changed) {
                if (!$dryRun) {
                    // Vérification syntaxe PHP via token_get_all (pas besoin du shell)
                    $syntaxOk = true;
                    try {
                        token_get_all($newContent, TOKEN_PARSE);
                    } catch (\ParseError $e) {
                        $syntaxOk = false;
                        $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => '⚠ ROLLBACK (erreur syntaxe)', 'changed' => false, 'error' => $e->getMessage()];
                        continue;
                    }
                    if ($syntaxOk) {
                        file_put_contents($file, $newContent);
                    }
                }
                $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => $dryRun ? '→ serait modifié' : '✓ modifié', 'changed' => true];
            } else {
                $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => 'pattern non trouvé', 'changed' => false];
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Migration RBAC Panels</title>
<style>
body{font-family:monospace;background:#0f1117;color:#e2e8f0;padding:24px;margin:0}
h1{color:#3b82f6;margin-bottom:4px}
.subtitle{color:#64748b;font-size:.85rem;margin-bottom:20px}
.dry-banner{background:rgba(234,179,8,.1);border:1px solid rgba(234,179,8,.3);color:#eab308;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem}
.apply-banner{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#22c55e;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem}
table{width:100%;border-collapse:collapse;font-size:.8rem}
th{text-align:left;padding:6px 10px;border-bottom:1px solid #1e293b;color:#64748b;font-weight:600}
td{padding:5px 10px;border-bottom:1px solid #0f1929}
.ok{color:#22c55e}
.skip{color:#64748b}
.warn{color:#f59e0b}
.err{color:#ef4444}
.btn{display:inline-block;padding:8px 18px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:700;margin-right:8px}
.btn-apply{background:#3b82f6;color:white}
.btn-dry{background:rgba(255,255,255,.08);color:#e2e8f0;border:1px solid rgba(255,255,255,.1)}
.summary{margin-bottom:16px;font-size:.9rem}
.summary span{margin-right:16px}
</style>
</head>
<body>
<h1>Migration RBAC — Panels Entreprise</h1>
<p class="subtitle">Suppression des permissions hardcodées et remplacement par faction_acl_can_view / faction_acl_can_edit</p>

<?php if ($dryRun): ?>
<div class="dry-banner">⚠ Mode simulation — aucune modification effectuée. Ajoutez <code>?dry=0</code> pour appliquer.</div>
<?php else: ?>
<div class="apply-banner">✓ Application réelle — les fichiers ont été modifiés.</div>
<?php endif; ?>

<div style="margin-bottom:16px">
<a href="?dry=1" class="btn btn-dry">Simulation</a>
<a href="?dry=0" class="btn btn-apply" onclick="return confirm('Appliquer les modifications sur le serveur ?')">Appliquer</a>
</div>

<?php
$totalChanged  = count(array_filter($results, fn($r) => $r['changed']));
$totalSkipped  = count(array_filter($results, fn($r) => !$r['changed'] && $r['status'] === 'déjà migré'));
$totalNotFound = count(array_filter($results, fn($r) => !$r['changed'] && $r['status'] === 'pattern non trouvé'));
?>
<div class="summary">
    <span class="ok">✓ <?php echo $totalChanged; ?> fichier(s) <?php echo $dryRun ? 'à modifier' : 'modifié(s)'; ?></span>
    <span class="skip">⊘ <?php echo $totalSkipped; ?> déjà migrés</span>
    <span class="warn">? <?php echo $totalNotFound; ?> pattern non trouvé</span>
</div>

<table>
<thead><tr><th>Fichier</th><th>Statut</th></tr></thead>
<tbody>
<?php foreach ($results as $r): ?>
<tr>
    <td><?php echo htmlspecialchars($r['file']); ?></td>
    <td class="<?php
        if ($r['changed']) echo 'ok';
        elseif ($r['status'] === 'déjà migré') echo 'skip';
        elseif (strpos($r['status'], 'ROLLBACK') !== false) echo 'err';
        else echo 'warn';
    ?>">
        <?php echo htmlspecialchars($r['status']); ?>
        <?php if (!empty($r['error'])): ?>
        <br><small style="color:#ef4444"><?php echo htmlspecialchars($r['error']); ?></small>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
