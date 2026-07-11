<?php
/**
 * scripts/apply_granular_rbac.php
 *
 * Migre TOUS les panels vers les permissions granulaires (faction_acl_can).
 * Gère à la fois les fichiers encore non migrés ET ceux déjà sur l'ancien système RBAC.
 *
 * ?dry=1  → simulation (défaut)
 * ?dry=0  → application réelle
 */
define('SKIP_LICENSE_GUARD', true);
require_once __DIR__ . '/../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
    http_response_code(403); die('Accès refusé.');
}

$dryRun  = ($_GET['dry'] ?? '1') !== '0';
$results = [];

// ── Mappings : nom_de_page → blocs RBAC granulaires ───────────────────────────
// Clé = basename du fichier PHP
// value = code PHP qui remplace le bloc de permissions

$GRANULAR_BLOCKS = [

    'sanctions.php' => <<<'PHP'
// Permissions — système RBAC granulaire centralisé
require_once ROOT_PATH . '/includes/faction_acl.php';
$_uid_rbac = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $_uid_rbac, $companyFaction, 'sanctions.view')) faction_acl_deny();
$canManage     = faction_acl_can($db, $_uid_rbac, $companyFaction, 'sanctions.create');
$canEdit       = faction_acl_can($db, $_uid_rbac, $companyFaction, 'sanctions.edit');
$canDelete     = faction_acl_can($db, $_uid_rbac, $companyFaction, 'sanctions.delete');
$canManageTarif = $canManage;
PHP,

    'stock.php' => <<<'PHP'
// Permissions — système RBAC granulaire centralisé
require_once ROOT_PATH . '/includes/faction_acl.php';
$_uid_rbac = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $_uid_rbac, $companyFaction, 'stock.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $_uid_rbac, $companyFaction, 'stock.add');
$canEdit   = faction_acl_can($db, $_uid_rbac, $companyFaction, 'stock.edit');
$canDelete = faction_acl_can($db, $_uid_rbac, $companyFaction, 'stock.delete');
PHP,

    'depenses.php' => <<<'PHP'
// Permissions — système RBAC granulaire centralisé
require_once ROOT_PATH . '/includes/faction_acl.php';
$_uid_rbac = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $_uid_rbac, $companyFaction, 'depenses.view')) faction_acl_deny();
$canAdd    = true; // tout membre peut ajouter une dépense
$canDelete = faction_acl_can($db, $_uid_rbac, $companyFaction, 'depenses.delete');
$canManage = $canDelete;
PHP,

    'custom.php' => <<<'PHP'
// Permissions — système RBAC granulaire centralisé
require_once ROOT_PATH . '/includes/faction_acl.php';
$_uid_rbac    = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $_uid_rbac, $companyFaction, 'custom.view')) faction_acl_deny();
$canCustomize = faction_acl_can($db, $_uid_rbac, $companyFaction, 'custom.customize');
$canManage    = faction_acl_can($db, $_uid_rbac, $companyFaction, 'custom.add');
$canEdit      = faction_acl_can($db, $_uid_rbac, $companyFaction, 'custom.edit');
$canDelete    = faction_acl_can($db, $_uid_rbac, $companyFaction, 'custom.delete');
PHP,

    'fourriere.php' => <<<'PHP'
// Permissions — système RBAC granulaire centralisé
require_once ROOT_PATH . '/includes/faction_acl.php';
$_uid_rbac       = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $_uid_rbac, $companyFaction, 'fourriere.view')) faction_acl_deny();
$canManage       = faction_acl_can($db, $_uid_rbac, $companyFaction, 'fourriere.add');
$canEdit         = faction_acl_can($db, $_uid_rbac, $companyFaction, 'fourriere.edit');
$canDelete       = faction_acl_can($db, $_uid_rbac, $companyFaction, 'fourriere.delete');
$canManageTarif  = faction_acl_can($db, $_uid_rbac, $companyFaction, 'fourriere.edit_tarif');
PHP,

    'biens.php' => <<<'PHP'
// Permissions — système RBAC granulaire centralisé
require_once ROOT_PATH . '/includes/faction_acl.php';
$_uid_rbac = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $_uid_rbac, $companyFaction, 'stock.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $_uid_rbac, $companyFaction, 'stock.add');
$canDelete = faction_acl_can($db, $_uid_rbac, $companyFaction, 'stock.delete');
PHP,

];

// Bloc info_vente.php → remplacement spécial (bloc d'accès + isStaff)
$INFO_VENTE_REPLACEMENT = <<<'PHP'
// Accès — système RBAC granulaire centralisé
require_once ROOT_PATH . '/includes/faction_acl.php';
$_uid_rbac = (int)($_SESSION['user_id'] ?? 0);
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
if (!faction_acl_can($db, $_uid_rbac, $companyFaction, 'info_vente.view')) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$isStaff = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']);
PHP;

// ── Fonctions de remplacement ─────────────────────────────────────────────────

/**
 * Remplace le bloc RBAC existant (v1 ou v2) pour les pages types courants.
 */
function granular_patch_manage(string $content, string $replacement): array
{
    $patterns = [
        // Déjà migré v1/v2 : faction_acl_can_edit / faction_acl_can_view
        '/\/\/ Permissions[^\n]*\n(?:[^\n]{0,300}\n){0,6}?\s*\$can(?:Manage|Edit|Delete)[^\n]+faction_acl_can_(?:edit|view)[^\n]+\n(?:[^\n]+\$can[^\n]+\n){0,4}/',
        // Encore sur getGradePermissions
        '/(?:\/\/ [^\n]+\n)?(?:\$__gradeStmt[^\n]+\n\s*\$__gradeStmt[^\n]+\n\s*\$__grade[^\n]+\n)?\s*\$(?:permissions|perms)\s*=\s*getGradePermissions\([^)]+\)\s*;\n\s*\$can\w+\s*=[^\n]+\$(?:permissions|perms)\[[^\]]+\][^\n]+\n(?:\s*\$can\w+\s*=[^\n]+\n)?/',
        // Pattern $isStaff || !empty
        '/(?:\/\/ [^\n]+can_(?:recruit|manage)[^\n]*\n(?:\/\/ [^\n]*\n)?)\s*\$can\w+\s*=\s*\$isStaff\s*\|\|[^\n]+\[\'can_(?:recruit|manage_\w+)\'\][^\n]+\n(?:\s*\$can\w+\s*=[^\n]+\n)?/',
        '/\s*\$can\w+\s*=\s*\$isStaff\s*\|\|\s*!empty\s*\(\s*\$\w+\[\'can_(?:recruit|manage_\w+)\'\]\s*\)[^\n]*\n(?:\s*\$can\w+\s*=[^\n]+\n)?/',
    ];
    foreach ($patterns as $p) {
        $new = preg_replace($p, $replacement . "\n", $content, 1);
        if ($new !== null && $new !== $content) return [$new, true];
    }
    return [$content, false];
}

/**
 * Remplace le bloc d'accès info_vente.php.
 * Couvre toutes les variantes : bahamas ($__isMember), bennys ($__isBennys), etc.
 */
function granular_patch_info_vente(string $content, string $replacement): array
{
    $patterns = [
        // Déjà migré v1 (faction_acl_can_view/can_edit sans granulaire)
        '/\/\/ Accès[^\n]*\n(?:[^\n]{0,300}\n){0,6}?\s*\$isStaff\s*=[^\n]+hasRole[^\n]+\n/',

        // Pattern classique bahamas : $__isRHPlus = false; ... $isStaff = $__isStaff;
        '/\$__isStaff[^\n]+\n\s*\$__isMember[^\n]+\n\s*\$__isRHPlus\s*=\s*false;.*?\$isStaff\s*=\s*\$__isStaff;/s',
        '/\$__isRHPlus\s*=\s*false;.*?\$isStaff\s*=\s*\$__isStaff;/s',

        // Pattern bennys/variant : $__isRHPlus = !empty(...['can_recruit']) + redirect + $isStaff = hasRole
        '/(?:\/\/ [^\n]+\n)?(?:[^\n]+\n){0,10}?\$__isRHPlus\s*=[^\n]+can_recruit[^\n]+\n(?:[^\n]+\n){0,4}?if\s*\(!\([^\)]+\)\)\s*\{\s*\n\s*redirect[^\n]+\n\s*\}\s*\n\s*\$isStaff\s*=[^\n]+hasRole[^\n]+\n/s',

        // Variante encore différente : getGradePermissions suivi d'isRHPlus et redirect
        '/(?:[^\n]+getGradePermissions[^\n]+\n)\s*\$__isRHPlus\s*=[^\n]+\n(?:[^\n]+\n){0,5}?if\s*\(![^\n]+\n\s*redirect[^\n]+\n\s*\}\s*\n\s*\$isStaff\s*=[^\n]+\n/s',
    ];
    foreach ($patterns as $p) {
        $new = preg_replace($p, $replacement . "\n", $content, 1);
        if ($new !== null && $new !== $content) return [$new, true];
    }
    return [$content, false];
}

// ── Pages ouvertes à tous (faction = seule restriction) → jamais patchées ─────
$OPEN_PAGES = [
    'dashboard.php', 'vente.php', 'prises-service.php', 'facturation.php',
    'chambres.php', 'reservations.php', 'planning.php', 'statistiques.php',
    'info.php', 'accueil.php', 'rapports.php', 'config.php',
    'header.php', 'footer.php', 'index.php', 'nav.php',
];

// ── Scan des répertoires ──────────────────────────────────────────────────────
$baseDirs = [
    ROOT_PATH . '/pages/companies',
    ROOT_PATH . '/pages/panels',
];

foreach ($baseDirs as $baseDir) {
    if (!is_dir($baseDir)) continue;
    foreach (scandir($baseDir) as $entry) {
        if ($entry[0] === '.') continue;
        $companyDir = $baseDir . '/' . $entry;
        if (!is_dir($companyDir)) continue;

        $files = glob($companyDir . '/*.php') ?: [];
        foreach ($files as $file) {
            $base = basename($file);

            // Ignorer les pages open / techniques
            if (in_array($base, $OPEN_PAGES, true)) continue;

            $content = file_get_contents($file);
            if ($content === false) continue;

            // Ignorer si déjà sur faction_acl_can (granulaire)
            if (strpos($content, 'faction_acl_can(') !== false) {
                $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => '⊘ déjà granulaire', 'changed' => false];
                continue;
            }

            // Vérification préventive : fichier déjà syntaxiquement invalide → skip
            try { token_get_all($content, TOKEN_PARSE); }
            catch (\ParseError $e) {
                $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => '⚠ FICHIER INVALIDE (pré-existant)', 'changed' => false, 'error' => $e->getMessage()];
                continue;
            }

            $changed    = false;
            $newContent = $content;

            if ($base === 'info_vente.php') {
                [$newContent, $changed] = granular_patch_info_vente($content, $INFO_VENTE_REPLACEMENT);
            } elseif (isset($GRANULAR_BLOCKS[$base])) {
                [$newContent, $changed] = granular_patch_manage($content, $GRANULAR_BLOCKS[$base]);
            } else {
                $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => '— non ciblé', 'changed' => false];
                continue;
            }

            if ($changed) {
                // Vérification syntaxe après remplacement
                $syntaxOk = true;
                try { token_get_all($newContent, TOKEN_PARSE); }
                catch (\ParseError $e) {
                    $syntaxOk = false;
                    $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => '⚠ ERREUR SYNTAXE (après patch)', 'changed' => false, 'error' => $e->getMessage()];
                    continue;
                }

                if (!$dryRun && $syntaxOk) file_put_contents($file, $newContent);

                $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => $dryRun ? '→ serait modifié' : '✓ modifié', 'changed' => true];
            } else {
                $results[] = ['file' => str_replace(ROOT_PATH, '', $file), 'status' => '? pattern non trouvé', 'changed' => false];
            }
        }
    }
}

// ── Rapport HTML ─────────────────────────────────────────────────────────────
$totalChanged  = count(array_filter($results, fn($r) => $r['changed']));
$totalGranular = count(array_filter($results, fn($r) => $r['status'] === '⊘ déjà granulaire'));
$totalNotFound = count(array_filter($results, fn($r) => $r['status'] === '? pattern non trouvé'));
$totalNotTarget= count(array_filter($results, fn($r) => $r['status'] === '— non ciblé'));
$totalError    = count(array_filter($results, fn($r) => strpos($r['status'] ?? '', 'ERREUR') !== false));
?><!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Migration RBAC Granulaire</title>
<style>
body{font-family:monospace;background:#0f1117;color:#e2e8f0;padding:24px;margin:0}
h1{color:#a855f7;margin-bottom:4px}
.sub{color:#64748b;font-size:.85rem;margin-bottom:20px}
.dry{background:rgba(234,179,8,.1);border:1px solid rgba(234,179,8,.3);color:#eab308;padding:10px 16px;border-radius:8px;margin-bottom:14px;font-size:.85rem}
.live{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#22c55e;padding:10px 16px;border-radius:8px;margin-bottom:14px;font-size:.85rem}
table{width:100%;border-collapse:collapse;font-size:.79rem}
th{text-align:left;padding:5px 10px;border-bottom:1px solid #1e293b;color:#64748b}
td{padding:4px 10px;border-bottom:1px solid #0f192933}
.ok{color:#22c55e}.skip{color:#475569}.warn{color:#f59e0b}.err{color:#ef4444}
.btn{display:inline-block;padding:7px 16px;border-radius:7px;text-decoration:none;font-size:.82rem;font-weight:700;margin-right:8px}
.btn-apply{background:#a855f7;color:white}.btn-dry{background:rgba(255,255,255,.07);color:#e2e8f0;border:1px solid rgba(255,255,255,.1)}
.summary span{margin-right:14px;font-size:.88rem}
</style></head>
<body>
<h1>Migration RBAC Granulaire</h1>
<p class="sub">Remplacement de faction_acl_can_edit / getGradePermissions par faction_acl_can() avec permissions détaillées par action</p>

<?php if ($dryRun): ?>
<div class="dry">⚠ Mode simulation — ajoutez <code>?dry=0</code> pour appliquer</div>
<?php else: ?>
<div class="live">✓ Application réelle effectuée</div>
<?php endif; ?>

<div style="margin-bottom:14px">
    <a href="?dry=1" class="btn btn-dry">Simulation</a>
    <a href="?dry=0" class="btn btn-apply" onclick="return confirm('Appliquer la migration granulaire ?')">Appliquer</a>
</div>

<div class="summary" style="margin-bottom:14px">
    <span class="ok">✓ <?php echo $totalChanged; ?> <?php echo $dryRun ? 'à modifier' : 'modifié(s)'; ?></span>
    <span class="skip">⊘ <?php echo $totalGranular; ?> déjà granulaires</span>
    <span style="color:#64748b">— <?php echo $totalNotTarget; ?> non ciblés</span>
    <span class="warn">? <?php echo $totalNotFound; ?> pattern non trouvé</span>
    <?php if ($totalError): ?><span class="err">⚠ <?php echo $totalError; ?> erreur(s)</span><?php endif; ?>
</div>

<table>
<thead><tr><th>Fichier</th><th>Statut</th><th>Détail</th></tr></thead>
<tbody>
<?php foreach ($results as $r): ?>
<tr>
    <td><?php echo htmlspecialchars($r['file']); ?></td>
    <td class="<?php
        if ($r['changed']) echo 'ok';
        elseif ($r['status'] === '⊘ déjà granulaire') echo 'skip';
        elseif (strpos($r['status'], 'ERREUR') !== false) echo 'err';
        elseif ($r['status'] === '— non ciblé') echo 'skip';
        else echo 'warn';
    ?>"><?php echo htmlspecialchars($r['status']); ?></td>
    <td style="color:#64748b;font-size:.75rem"><?php echo htmlspecialchars($r['error'] ?? ''); ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</body></html>
