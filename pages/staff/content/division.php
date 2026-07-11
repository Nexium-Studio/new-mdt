<?php
/**
 * Gestion des divisions de faction (catalogue + créations staff).
 * Tables : faction_divisions, faction_division_grades, admin_panels.
 */
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Divisions';
$breadcrumb = [['label' => 'Contenu'], ['label' => 'Divisions']];
$success = '';
$error   = '';

if (empty($_SESSION['staff_division_csrf'])) {
    $_SESSION['staff_division_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['staff_division_csrf'];

function division_build_panel_key(string $factionKey, string $divisionKey): string
{
    $fk = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $factionKey));
    $dk = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $divisionKey));
    return 'DIV_' . $fk . '_' . $dk;
}

function division_default_target_url(string $factionKey): string
{
    return match ($factionKey) {
        'LSPD'   => '/pages/lspd/divisions.php',
        'SAMS'   => '/pages/sams/dashboard.php',
        'USSS'   => '/pages/usss/dashboard.php',
        'LSSD'   => '/pages/lssd/dashboard.php',
        'GRUPP6' => '/pages/grupp6/dashboard.php',
        default  => '/pages/dashboard.php',
    };
}

function division_sync_admin_panel(PDO $db, string $panelKey, int $isActive): void
{
    $st = $db->prepare(
        'INSERT INTO admin_panels (panel_key, is_active, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), updated_at = NOW()'
    );
    $st->execute([$panelKey, $isActive]);
}

function division_remove_admin_panel(PDO $db, string $panelKey): void
{
    $st = $db->prepare('DELETE FROM admin_panels WHERE panel_key = ? LIMIT 1');
    $st->execute([$panelKey]);
}

// ── Factions (liste déroulante) ─────────────────────────────────────────────
$factions = [];
try {
    $factions = $db->query('SELECT id, name, faction_key FROM factions ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Impossible de charger les factions : ' . $e->getMessage();
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $tok = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, $tok)) {
        $error = 'Session expirée. Réessayez.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create' || $action === 'update') {
            $id            = (int) ($_POST['id'] ?? 0);
            $factionId     = (int) ($_POST['faction_id'] ?? 0);
            $divisionKey   = trim((string) ($_POST['division_key'] ?? ''));
            $name          = trim((string) ($_POST['name'] ?? ''));
            $abbreviation  = trim((string) ($_POST['abbreviation'] ?? ''));
            $description   = trim((string) ($_POST['description'] ?? ''));
            $icon          = trim((string) ($_POST['icon'] ?? 'layer-group'));
            $color         = trim((string) ($_POST['color'] ?? '#64748b'));
            $targetUrl     = trim((string) ($_POST['target_url'] ?? ''));
            $sortOrder     = (int) ($_POST['sort_order'] ?? 0);
            $isActive      = isset($_POST['is_active']) ? 1 : 0;
            $showDashboard = isset($_POST['show_on_dashboard']) ? 1 : 0;

            if ($factionId < 1 || $name === '' || $divisionKey === '') {
                $error = 'Faction, clé technique et nom sont obligatoires.';
            } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $divisionKey)) {
                $error = 'Clé technique : lettres, chiffres et underscore uniquement (max 64).';
            } elseif (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $error = 'Couleur invalide (format #RRGGBB).';
            } else {
                $fkStmt = $db->prepare('SELECT faction_key FROM factions WHERE id = ? LIMIT 1');
                $fkStmt->execute([$factionId]);
                $frow = $fkStmt->fetch(PDO::FETCH_ASSOC);
                if (!$frow) {
                    $error = 'Faction introuvable.';
                } else {
                    $panelKey = division_build_panel_key($frow['faction_key'], $divisionKey);
                    if ($targetUrl === '') {
                        $targetUrl = division_default_target_url($frow['faction_key']);
                    }
                    if (strlen($targetUrl) > 512) {
                        $error = 'URL trop longue.';
                    }
                    if ($icon === '') {
                        $icon = 'layer-group';
                    }
                    if (!preg_match('/^[a-z0-9\-]+$/', $icon)) {
                        $error = 'Icône Font Awesome : caractères autorisés [a-z0-9-].';
                    }
                }
            }

            if ($error === '') {
                try {
                    if ($action === 'create') {
                        $chk = $db->prepare('SELECT id FROM faction_divisions WHERE faction_id = ? AND division_key = ? LIMIT 1');
                        $chk->execute([$factionId, $divisionKey]);
                        if ($chk->fetch()) {
                            $error = 'Une division avec cette clé existe déjà pour cette faction.';
                        } else {
                            $chk2 = $db->prepare('SELECT id FROM faction_divisions WHERE panel_key = ? LIMIT 1');
                            $chk2->execute([$panelKey]);
                            if ($chk2->fetch()) {
                                $error = 'La clé panel générée existe déjà. Modifiez la clé technique.';
                            }
                        }
                        if ($error === '') {
                            $ins = $db->prepare(
                                'INSERT INTO faction_divisions (
                                    faction_id, division_key, panel_key, name, abbreviation, description,
                                    icon, color, target_url, sort_order, is_active, show_on_dashboard, mdt_ref_unit_id
                                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL)'
                            );
                            $ins->execute([
                                $factionId, $divisionKey, $panelKey, $name,
                                $abbreviation !== '' ? $abbreviation : null,
                                $description !== '' ? $description : null,
                                $icon, $color, $targetUrl, $sortOrder, $isActive, $showDashboard,
                            ]);
                            division_sync_admin_panel($db, $panelKey, $isActive);
                            $success = 'Division créée.';
                            $_SESSION['staff_division_csrf'] = bin2hex(random_bytes(16));
                            $csrf = $_SESSION['staff_division_csrf'];
                        }
                    } else {
                        $rowStmt = $db->prepare('SELECT * FROM faction_divisions WHERE id = ? LIMIT 1');
                        $rowStmt->execute([$id]);
                        $existing = $rowStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$existing) {
                            $error = 'Division introuvable.';
                        } else {
                            $oldPanelKey = $existing['panel_key'];
                            if ((int) $existing['faction_id'] !== $factionId) {
                                $chk = $db->prepare(
                                    'SELECT id FROM faction_divisions WHERE faction_id = ? AND division_key = ? AND id <> ? LIMIT 1'
                                );
                                $chk->execute([$factionId, $divisionKey, $id]);
                            } else {
                                $chk = $db->prepare(
                                    'SELECT id FROM faction_divisions WHERE faction_id = ? AND division_key = ? AND id <> ? LIMIT 1'
                                );
                                $chk->execute([$factionId, $divisionKey, $id]);
                            }
                            if ($chk->fetch()) {
                                $error = 'Une division avec cette clé existe déjà pour cette faction.';
                            } else {
                                $chk2 = $db->prepare('SELECT id FROM faction_divisions WHERE panel_key = ? AND id <> ? LIMIT 1');
                                $chk2->execute([$panelKey, $id]);
                                if ($chk2->fetch()) {
                                    $error = 'La clé panel générée est déjà utilisée.';
                                }
                            }
                        }
                        if ($error === '' && is_array($existing)) {
                            $upd = $db->prepare(
                                'UPDATE faction_divisions SET
                                    faction_id = ?, division_key = ?, panel_key = ?, name = ?, abbreviation = ?, description = ?,
                                    icon = ?, color = ?, target_url = ?, sort_order = ?, is_active = ?, show_on_dashboard = ?,
                                    updated_at = NOW()
                                 WHERE id = ?'
                            );
                            $upd->execute([
                                $factionId, $divisionKey, $panelKey, $name,
                                $abbreviation !== '' ? $abbreviation : null,
                                $description !== '' ? $description : null,
                                $icon, $color, $targetUrl, $sortOrder, $isActive, $showDashboard, $id,
                            ]);
                            if ($oldPanelKey !== $panelKey) {
                                division_remove_admin_panel($db, $oldPanelKey);
                            }
                            division_sync_admin_panel($db, $panelKey, $isActive);
                            $success = 'Division mise à jour.';
                            $_SESSION['staff_division_csrf'] = bin2hex(random_bytes(16));
                            $csrf = $_SESSION['staff_division_csrf'];
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Erreur SQL : ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                $error = 'Identifiant invalide.';
            } else {
                try {
                    $st = $db->prepare('SELECT panel_key FROM faction_divisions WHERE id = ? LIMIT 1');
                    $st->execute([$id]);
                    $pk = $st->fetchColumn();
                    if (!$pk) {
                        $error = 'Division introuvable.';
                    } else {
                        $db->prepare('DELETE FROM faction_divisions WHERE id = ?')->execute([$id]);
                        division_remove_admin_panel($db, (string) $pk);
                        $success = 'Division supprimée.';
                        $_SESSION['staff_division_csrf'] = bin2hex(random_bytes(16));
                        $csrf = $_SESSION['staff_division_csrf'];
                    }
                } catch (Exception $e) {
                    $error = 'Suppression impossible : ' . $e->getMessage();
                }
            }
        } elseif ($action === 'sync_from_mdt_ref') {
            if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
                $error = 'Synchronisation réservée aux administrateurs / développeurs.';
            } else {
                try {
                    $db->beginTransaction();

                    $sqlDivs = <<<'SQL'
INSERT INTO faction_divisions (
  faction_id, division_key, panel_key, `name`, abbreviation, description,
  icon, color, target_url, sort_order, is_active, show_on_dashboard, mdt_ref_unit_id
)
SELECT
  f.`id`,
  u.`unit_key`,
  CONCAT(
    CASE s.`scope_key`
      WHEN 'LSPD_DIV' THEN 'DIV_LSPD_'
      WHEN 'SAMS_DIV' THEN 'DIV_SAMS_'
      WHEN 'USSS_DIV' THEN 'DIV_USSS_'
      WHEN 'LSSD_DIV' THEN 'DIV_LSSD_'
      WHEN 'GRUPP6_DIV' THEN 'DIV_GRUPP6_'
      ELSE 'DIV_'
    END,
    u.`unit_key`
  ),
  u.`name`,
  u.`unit_key`,
  CONCAT('Division ', u.`name`),
  'layer-group',
  CASE s.`scope_key`
    WHEN 'LSPD_DIV' THEN '#2563eb'
    WHEN 'SAMS_DIV' THEN '#f43f5e'
    WHEN 'USSS_DIV' THEN '#334155'
    WHEN 'LSSD_DIV' THEN '#10b981'
    WHEN 'GRUPP6_DIV' THEN '#64748b'
    ELSE '#64748b'
  END,
  CASE s.`scope_key`
    WHEN 'LSPD_DIV' THEN '/pages/lspd/divisions.php'
    WHEN 'SAMS_DIV' THEN '/pages/sams/dashboard.php'
    WHEN 'USSS_DIV' THEN '/pages/usss/dashboard.php'
    WHEN 'LSSD_DIV' THEN '/pages/lssd/dashboard.php'
    WHEN 'GRUPP6_DIV' THEN '/pages/grupp6/dashboard.php'
    ELSE '/pages/dashboard.php'
  END,
  u.`sort_order`,
  1,
  1,
  u.`id`
FROM `mdt_ref_division_unit` u
INNER JOIN `mdt_ref_division_scope` s ON s.`id` = u.`scope_id`
INNER JOIN `factions` f ON f.`faction_key` COLLATE utf8mb4_unicode_ci = (
  CASE s.`scope_key`
    WHEN 'LSPD_DIV' THEN 'LSPD'
    WHEN 'SAMS_DIV' THEN 'SAMS'
    WHEN 'USSS_DIV' THEN 'USSS'
    WHEN 'LSSD_DIV' THEN 'LSSD'
    WHEN 'GRUPP6_DIV' THEN 'GRUPP6'
    ELSE ''
  END
) COLLATE utf8mb4_unicode_ci
LEFT JOIN `faction_divisions` fd ON fd.`mdt_ref_unit_id` = u.`id`
WHERE fd.`id` IS NULL
  AND s.`scope_key` IN ('LSPD_DIV','SAMS_DIV','USSS_DIV','LSSD_DIV','GRUPP6_DIV')
SQL;
                    $nDivs = $db->exec($sqlDivs);

                    $sqlGrades = <<<'SQL'
INSERT INTO faction_division_grades (division_id, `name`, rank_order, track_code)
SELECT fd.`id`, dg.`grade_name`, dg.`rank_order`, dg.`track_code`
FROM `mdt_ref_division_grade` dg
INNER JOIN `mdt_ref_division_unit` u ON u.`id` = dg.`division_unit_id`
INNER JOIN `faction_divisions` fd ON fd.`mdt_ref_unit_id` = u.`id`
WHERE NOT EXISTS (
  SELECT 1 FROM `faction_division_grades` g
  WHERE g.`division_id` = fd.`id`
    AND g.`name` COLLATE utf8mb4_unicode_ci = dg.`grade_name` COLLATE utf8mb4_unicode_ci
    AND (g.`track_code` <=> dg.`track_code`)
)
SQL;
                    $nGrades = $db->exec($sqlGrades);

                    $sqlPanels = <<<'SQL'
INSERT INTO `admin_panels` (`panel_key`, `is_active`, `updated_at`)
SELECT fd.`panel_key`, fd.`is_active`, NOW()
FROM `faction_divisions` fd
ON DUPLICATE KEY UPDATE `is_active` = VALUES(`is_active`), `updated_at` = VALUES(`updated_at`)
SQL;
                    $db->exec($sqlPanels);

                    $db->commit();

                    $hint = '';
                    if ((int) $nDivs === 0 && (int) $nGrades === 0) {
                        try {
                            $miss = (int) $db->query(
                                "SELECT COUNT(DISTINCT u.id) FROM mdt_ref_division_unit u
                                 INNER JOIN mdt_ref_division_scope s ON s.id = u.scope_id
                                 LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = (
                                   CASE s.scope_key
                                     WHEN 'LSPD_DIV' THEN 'LSPD'
                                     WHEN 'SAMS_DIV' THEN 'SAMS'
                                     WHEN 'USSS_DIV' THEN 'USSS'
                                     WHEN 'LSSD_DIV' THEN 'LSSD'
                                     WHEN 'GRUPP6_DIV' THEN 'GRUPP6'
                                     ELSE ''
                                   END
                                 ) COLLATE utf8mb4_unicode_ci
                                 WHERE s.scope_key IN ('LSPD_DIV','SAMS_DIV','USSS_DIV','LSSD_DIV','GRUPP6_DIV')
                                   AND f.id IS NULL"
                            )->fetchColumn();
                            $already = (int) $db->query(
                                'SELECT COUNT(*) FROM faction_divisions WHERE mdt_ref_unit_id IS NOT NULL'
                            )->fetchColumn();
                            if ($miss > 0) {
                                $hint = ' Aucune ligne importée : la table `factions` ne contient pas les clés attendues (LSPD, SAMS, USSS, LSSD, GRUPP6) — '
                                    . (int) $miss . ' unité(s) mdt_ref sans faction correspondante. Ajoutez ou corrigez ces factions, puis réessayez.';
                            } elseif ($already > 0) {
                                $hint = ' Les divisions liées au catalogue étaient déjà présentes (' . $already . ' ligne(s) avec mdt_ref_unit_id). Rien à ajouter.';
                            } else {
                                $hint = ' Vérifiez que `mdt_ref_division_unit` contient des données et que les factions LSPD/SAMS/USSS/LSSD/GRUPP6 existent dans `factions`.';
                            }
                        } catch (Exception $e) {
                            $hint = ' (Diagnostic impossible : ' . $e->getMessage() . ')';
                        }
                    }

                    $success = 'Synchronisation terminée : '
                        . (int) $nDivs . ' division(s) importée(s) depuis le catalogue mdt_ref, '
                        . (int) $nGrades . ' ligne(s) de grades ajoutée(s). Les clés panel sont enregistrées dans admin_panels.'
                        . $hint;
                    $_SESSION['staff_division_csrf'] = bin2hex(random_bytes(16));
                    $csrf = $_SESSION['staff_division_csrf'];
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = 'Synchronisation impossible : ' . $e->getMessage();
                }
            }
        }
    }
}

// ── Liste ────────────────────────────────────────────────────────────────────
$divisions = [];
$divisionsTableOk = true;
try {
    $divisions = $db->query(
        'SELECT fd.*, f.name AS faction_name, f.faction_key,
                (SELECT COUNT(*) FROM faction_division_grades g WHERE g.division_id = fd.id) AS grades_count
         FROM faction_divisions fd
         LEFT JOIN factions f ON f.id = fd.faction_id
         ORDER BY COALESCE(f.name, \'\') ASC, fd.sort_order ASC, fd.name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $divisionsTableOk = false;
    if ($error === '') {
        $error = 'Table `faction_divisions` absente ou erreur : exécutez `database/migration_faction_divisions_full.sql`. Détail : ' . $e->getMessage();
    }
}

$mdtRefUnitsCount = 0;
$mdtUnitsWithoutFaction = null;
if ($divisionsTableOk) {
    try {
        $mdtRefUnitsCount = (int) $db->query('SELECT COUNT(*) FROM mdt_ref_division_unit')->fetchColumn();
    } catch (Exception $e) {
        $mdtRefUnitsCount = 0;
    }
    if (hasRole(['*', 'Administrateur', 'Développeur'])) {
        try {
            $mdtUnitsWithoutFaction = (int) $db->query(
                "SELECT COUNT(DISTINCT u.id) FROM mdt_ref_division_unit u
                 INNER JOIN mdt_ref_division_scope s ON s.id = u.scope_id
                 LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = (
                   CASE s.scope_key
                     WHEN 'LSPD_DIV' THEN 'LSPD'
                     WHEN 'SAMS_DIV' THEN 'SAMS'
                     WHEN 'USSS_DIV' THEN 'USSS'
                     WHEN 'LSSD_DIV' THEN 'LSSD'
                     WHEN 'GRUPP6_DIV' THEN 'GRUPP6'
                     ELSE ''
                   END
                 ) COLLATE utf8mb4_unicode_ci
                 WHERE s.scope_key IN ('LSPD_DIV','SAMS_DIV','USSS_DIV','LSSD_DIV','GRUPP6_DIV')
                   AND f.id IS NULL"
            )->fetchColumn();
        } catch (Exception $e) {
            $mdtUnitsWithoutFaction = null;
        }
    }
}

$editRow = null;
if (isset($_GET['edit']) && $divisionsTableOk) {
    $eid = (int) $_GET['edit'];
    if ($eid > 0) {
        $es = $db->prepare(
            'SELECT fd.*, f.faction_key FROM faction_divisions fd
             LEFT JOIN factions f ON f.id = fd.faction_id WHERE fd.id = ? LIMIT 1'
        );
        $es->execute([$eid]);
        $editRow = $es->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

$extraCss = <<<'CSS'
.division-layout{display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start}
@media(max-width:1100px){.division-layout{grid-template-columns:1fr}}
.division-table-wrap{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
.division-form-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:20px 22px;position:sticky;top:88px}
.division-form-card h2{font-size:1rem;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:600px){.form-row-2{grid-template-columns:1fr}}
.key-hint{font-size:.72rem;color:var(--text-secondary);margin-top:4px;line-height:1.4}
CSS;

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fa-solid fa-sitemap" style="color:var(--blue);"></i> Divisions</h1>
        <p>
            Ici vous gérez la table <strong>faction_divisions</strong> (panels MDT / dashboard).
            C’est distinct du <strong>catalogue mdt_ref</strong> (page <a href="<?php echo SITE_URL; ?>/pages/staff/users/div_grad.php" style="color:var(--blue);">Utilisateurs → Division &amp; Grade</a>),
            où l’erreur « duplicate LSPA » apparaît si la clé existe déjà dans ce catalogue — même si cette liste est vide.
        </p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($mdtUnitsWithoutFaction !== null && $mdtUnitsWithoutFaction > 0): ?>
<div class="alert alert-danger" style="margin-bottom:16px;">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <strong>Import bloqué :</strong> <?php echo (int) $mdtUnitsWithoutFaction; ?> unité(s) du catalogue mdt_ref n’ont pas de ligne correspondante dans <code>factions</code>.
    La table doit contenir les clés exactes <code>LSPD</code>, <code>SAMS</code>, <code>USSS</code>, <code>LSSD</code>, <code>GRUPP6</code> (colonne <code>faction_key</code>). Sans cela, la synchronisation importe 0 ligne.
    <div style="margin-top:8px;font-size:.85rem;opacity:.95;">Ordre SQL recommandé : 1) dump principal, 2) <code>mdt_ref_v2_full.sql</code>, 3) <code>migration_faction_divisions_full.sql</code>. Si vous aviez lancé la migration avant <code>mdt_ref</code>, les INSERT du catalogue ont échoué — réimportez <code>mdt_ref</code> puis recliquez sur Importer.</div>
</div>
<?php endif; ?>

<?php if (!$divisionsTableOk): ?>
<div class="alert alert-warning">
    <i class="fa-solid fa-database"></i>
    Importez d’abord le référentiel <code>database/mdt_ref_v2_full.sql</code> puis
    <code>database/migration_faction_divisions_full.sql</code>.
</div>
<?php elseif (empty($divisions) && $mdtRefUnitsCount > 0 && hasRole(['*', 'Administrateur', 'Développeur'])): ?>
<div class="alert alert-info" style="margin-bottom:20px;">
    <i class="fa-solid fa-circle-info"></i>
    <div>
        <strong>Liste vide alors que le catalogue mdt_ref contient des unités (<?php echo (int) $mdtRefUnitsCount; ?>).</strong>
        Les lignes n’ont pas encore été copiées vers <code>faction_divisions</code> (souvent si la migration SQL n’a pas été jouée sur cet hébergement).
        <form method="post" style="display:inline;margin-left:8px;vertical-align:middle;">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="sync_from_mdt_ref">
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px;">
                <i class="fa-solid fa-download"></i> Importer le catalogue mdt_ref ici
            </button>
        </form>
    </div>
</div>
<?php elseif (empty($divisions) && $mdtRefUnitsCount === 0): ?>
<div class="alert alert-warning" style="margin-bottom:20px;">
    <i class="fa-solid fa-database"></i>
    Aucune unité dans <code>mdt_ref_division_unit</code> : importez <code>database/mdt_ref_v2_full.sql</code>, puis utilisez le bouton ci-dessus ou la migration <code>migration_faction_divisions_full.sql</code>.
</div>
<?php elseif (empty($divisions) && $mdtRefUnitsCount > 0): ?>
<div class="alert alert-warning" style="margin-bottom:20px;">
    <i class="fa-solid fa-database"></i>
    Le catalogue mdt_ref contient des unités, mais cette liste est encore vide. Un <strong>administrateur</strong> peut cliquer sur « Importer le catalogue mdt_ref ici » (réservé aux rôles autorisés) ou exécuter <code>database/migration_faction_divisions_full.sql</code> sur la base.
</div>
<?php endif; ?>

<div class="division-layout">
    <div>
        <div class="division-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Division</th>
                        <th>Faction</th>
                        <th>Panel</th>
                        <th>Grades</th>
                        <th>Dashboard</th>
                        <th>Actif</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($divisions)): ?>
                    <tr><td colspan="7" class="empty-state" style="padding:32px;"><i class="fa-solid fa-inbox"></i><p>Aucune division dans faction_divisions</p><?php if ($mdtRefUnitsCount > 0 && hasRole(['*', 'Administrateur', 'Développeur'])): ?><p style="margin-top:10px;font-size:.8rem;color:var(--text-secondary);max-width:520px;margin-left:auto;margin-right:auto;">Utilisez le bouton « Importer le catalogue mdt_ref » au-dessus du tableau.</p><?php endif; ?></td></tr>
                    <?php else: foreach ($divisions as $d): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($d['name']); ?></strong>
                            <div class="text-muted" style="font-size:.75rem;"><?php echo htmlspecialchars($d['division_key']); ?></div>
                        </td>
                        <td>
                            <?php if (!empty($d['faction_key'])): ?>
                                <span class="badge badge-slate"><?php echo htmlspecialchars($d['faction_key']); ?></span>
                            <?php else: ?>
                                <span class="badge badge-red" title="faction_id=<?php echo (int) $d['faction_id']; ?>">Faction manquante</span>
                            <?php endif; ?>
                        </td>
                        <td><code class="mono" style="font-size:.72rem;"><?php echo htmlspecialchars($d['panel_key']); ?></code></td>
                        <td><?php echo (int) $d['grades_count']; ?></td>
                        <td><?php echo !empty($d['show_on_dashboard']) ? '<span class="badge badge-green">Oui</span>' : '<span class="badge badge-slate">Non</span>'; ?></td>
                        <td><?php echo !empty($d['is_active']) ? '<span class="badge badge-green">Oui</span>' : '<span class="badge badge-red">Non</span>'; ?></td>
                        <td style="white-space:nowrap;">
                            <a href="?edit=<?php echo (int) $d['id']; ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer cette division et ses grades ?');">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="division-form-card">
        <h2>
            <i class="fa-solid fa-<?php echo $editRow ? 'pen-to-square' : 'plus'; ?>"></i>
            <?php echo $editRow ? 'Modifier la division' : 'Nouvelle division'; ?>
        </h2>

        <?php if ($editRow): ?>
        <p style="font-size:.8rem;color:var(--text-secondary);margin-bottom:16px;">
            <a href="division.php" class="text-blue" style="text-decoration:none;">← Annuler l’édition</a>
        </p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int) $editRow['id']; ?>"><?php endif; ?>

            <div class="form-group">
                <label class="form-label">Faction *</label>
                <select name="faction_id" class="form-control" required <?php echo $divisionsTableOk ? '' : 'disabled'; ?>>
                    <?php foreach ($factions as $f): ?>
                    <option value="<?php echo (int) $f['id']; ?>"
                        <?php echo ($editRow && (int)$editRow['faction_id'] === (int)$f['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($f['name'] . ' (' . $f['faction_key'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Clé technique *</label>
                <input type="text" name="division_key" class="form-control mono" required maxlength="64" pattern="[A-Za-z0-9_]+"
                    value="<?php echo htmlspecialchars($editRow['division_key'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo $editRow && $editRow['mdt_ref_unit_id'] ? 'readonly title="Catalogue mdt_ref — non modifiable"' : ''; ?>>
                <div class="key-hint">Utilisée pour générer la clé panel <code>DIV_&lt;FACTION&gt;_&lt;CLE&gt;</code>. Lettres, chiffres, underscore.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Nom affiché *</label>
                <input type="text" name="name" class="form-control" required maxlength="180"
                    value="<?php echo htmlspecialchars($editRow['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Abréviation</label>
                    <input type="text" name="abbreviation" class="form-control" maxlength="32"
                        value="<?php echo htmlspecialchars($editRow['abbreviation'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Tri</label>
                    <input type="number" name="sort_order" class="form-control" value="<?php echo (int) ($editRow['sort_order'] ?? 0); ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3" style="resize:vertical;"><?php echo htmlspecialchars($editRow['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Icône (Font Awesome)</label>
                    <input type="text" name="icon" class="form-control" placeholder="layer-group" maxlength="64"
                        value="<?php echo htmlspecialchars($editRow['icon'] ?? 'layer-group', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Couleur</label>
                    <input type="text" name="color" class="form-control mono" pattern="#[0-9A-Fa-f]{6}" placeholder="#64748b" maxlength="32"
                        value="<?php echo htmlspecialchars($editRow['color'] ?? '#64748b', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">URL du panel</label>
                <input type="text" name="target_url" class="form-control" maxlength="512" placeholder="Par défaut selon la faction"
                    value="<?php echo htmlspecialchars($editRow['target_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-group">
                <div class="toggle-wrap">
                    <label class="toggle">
                        <input type="checkbox" name="is_active" value="1" <?php echo !isset($editRow) || !empty($editRow['is_active']) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-label">Division active</span>
                </div>
            </div>
            <div class="form-group">
                <div class="toggle-wrap">
                    <label class="toggle">
                        <input type="checkbox" name="show_on_dashboard" value="1" <?php echo !isset($editRow) || !empty($editRow['show_on_dashboard']) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-label">Afficher sur le tableau de bord MDT</span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-full" <?php echo $divisionsTableOk ? '' : 'disabled'; ?>>
                <i class="fa-solid fa-floppy-disk"></i> <?php echo $editRow ? 'Enregistrer' : 'Créer la division'; ?>
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
