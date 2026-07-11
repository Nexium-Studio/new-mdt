<?php
/**
 * DASHBOARD USSS — VERSION DEBUG
 * Uploader ce fichier à la place de dashboard.php TEMPORAIREMENT
 * Il affiche l'erreur exacte au lieu du 500
 * ⚠️ SUPPRIMER après diagnostic
 */

// Forcer l'affichage de TOUTES les erreurs
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Intercepter les erreurs fatales
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "<div style='background:#7f1d1d;color:#fca5a5;padding:1rem;margin:1rem;border-radius:8px;font-family:monospace'>";
    echo "<strong>ERREUR PHP [$errno]</strong><br>";
    echo "Message : " . htmlspecialchars($errstr) . "<br>";
    echo "Fichier  : " . htmlspecialchars($errfile) . " ligne $errline";
    echo "</div>";
});

set_exception_handler(function($e) {
    echo "<div style='background:#7f1d1d;color:#fca5a5;padding:1rem;margin:1rem;border-radius:8px;font-family:monospace'>";
    echo "<strong>EXCEPTION : " . get_class($e) . "</strong><br>";
    echo "Message : " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "Fichier  : " . htmlspecialchars($e->getFile()) . " ligne " . $e->getLine() . "<br>";
    echo "<pre style='margin-top:.5rem;font-size:.8rem'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
});

echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><title>Debug USSS Dashboard</title>
<style>
body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:2rem}
h2{color:#60a5fa;border-bottom:1px solid #1e3a8a;padding-bottom:.5rem;margin-top:2rem}
.ok{background:#14532d;color:#86efac;padding:.6rem 1rem;border-radius:6px;margin:.3rem 0;display:block}
.fail{background:#7f1d1d;color:#fca5a5;padding:.6rem 1rem;border-radius:6px;margin:.3rem 0;display:block}
.warn{background:#78350f;color:#fcd34d;padding:.6rem 1rem;border-radius:6px;margin:.3rem 0;display:block}
pre{background:#1e293b;padding:1rem;border-radius:6px;overflow-x:auto;font-size:.82rem;white-space:pre-wrap}
table{border-collapse:collapse;width:100%;margin:.5rem 0}
td,th{border:1px solid #334155;padding:.4rem .7rem;text-align:left;font-size:.85rem}
th{background:#1e3a8a;color:#93c5fd}
</style></head><body>";

echo "<h1 style='color:#c8102e'>🔍 Debug Dashboard USSS</h1>";
echo "<p style='color:#64748b'>Généré le " . date('d/m/Y à H:i:s') . "</p>";

// ── ÉTAPE 1 : Config ──────────────────────────────────────────
echo "<h2>1. Chargement config.php</h2>";
$configPath = __DIR__ . '/../../../config/config.php';
if (file_exists($configPath)) {
    echo "<span class='ok'>✓ config.php trouvé : $configPath</span>";
    try {
        require_once $configPath;
        echo "<span class='ok'>✓ config.php chargé sans erreur</span>";
    } catch (Throwable $e) {
        echo "<span class='fail'>✗ Erreur au chargement : " . htmlspecialchars($e->getMessage()) . "</span>";
        die("</body></html>");
    }
} else {
    echo "<span class='fail'>✗ config.php INTROUVABLE à : $configPath</span>";
    // Chercher dans les dossiers parents
    foreach (['/../../../config/config.php','/../../../config.php','/../config.php','/../../config/config.php'] as $p) {
        $fp = __DIR__ . $p;
        if (file_exists($fp)) echo "<span class='warn'>→ Trouvé à : $fp</span>";
    }
    die("</body></html>");
}

// ── ÉTAPE 2 : Session / Auth ──────────────────────────────────
echo "<h2>2. Session & Authentification</h2>";
if (session_status() === PHP_SESSION_NONE) session_start();
echo "<span class='ok'>✓ Session active : " . session_id() . "</span>";
echo "<pre>";
$sessionInfo = [
    'user_id'      => $_SESSION['user_id'] ?? 'NON DÉFINI',
    'user_faction' => $_SESSION['user_faction'] ?? 'NON DÉFINI',
    'username'     => $_SESSION['username'] ?? 'NON DÉFINI',
    'role'         => $_SESSION['role'] ?? 'NON DÉFINI',
];
foreach ($sessionInfo as $k => $v) echo "$k = $v\n";
echo "</pre>";

if (function_exists('requireLogin')) {
    echo "<span class='ok'>✓ Fonction requireLogin() disponible</span>";
} else {
    echo "<span class='fail'>✗ requireLogin() NON DÉFINIE — config.php mal chargé ?</span>";
}

// ── ÉTAPE 3 : Connexion DB ────────────────────────────────────
echo "<h2>3. Connexion Base de Données</h2>";
try {
    $db = Database::getInstance()->getConnection();
    echo "<span class='ok'>✓ Connexion DB réussie</span>";
    
    // Infos serveur
    $v = $db->query("SELECT VERSION() as v")->fetchColumn();
    echo "<span class='ok'>✓ MySQL version : $v</span>";
} catch (Throwable $e) {
    echo "<span class='fail'>✗ ERREUR DB : " . htmlspecialchars($e->getMessage()) . "</span>";
    die("</body></html>");
}

// ── ÉTAPE 4 : Tables USSS ────────────────────────────────────
echo "<h2>4. Tables USSS</h2>";
$tables = [
    'usss_agents', 'usss_operations', 'usss_mandats', 'usss_dossiers',
    'usss_niveau_alerte', 'usss_annonces', 'usss_divisions',
];
echo "<table><tr><th>Table</th><th>Statut</th><th>Nb lignes</th><th>Colonnes clés</th></tr>";
foreach ($tables as $tbl) {
    try {
        $n = $db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        $cols = $db->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
        echo "<tr><td>$tbl</td><td style='color:#86efac'>✓ OK</td><td>$n</td><td>" . implode(', ', array_slice($cols, 0, 8)) . "…</td></tr>";
    } catch (PDOException $e) {
        echo "<tr><td>$tbl</td><td style='color:#fca5a5'>✗ " . htmlspecialchars($e->getMessage()) . "</td><td>—</td><td>—</td></tr>";
    }
}
echo "</table>";

// ── ÉTAPE 5 : Tester chaque requête ──────────────────────────
echo "<h2>5. Test de chaque requête SQL</h2>";

$queries = [
    'agentsStats' => "SELECT COUNT(*) as total, SUM(CASE WHEN statut = 'EN_SERVICE' THEN 1 ELSE 0 END) as en_service FROM usss_agents",
    'operationsEnCours' => "SELECT COUNT(*) FROM usss_operations WHERE statut IN ('PLANIFIEE','EN_COURS')",
    'mandatsActifs' => "SELECT COUNT(*) FROM usss_mandats WHERE statut = 'ACTIF'",
    'dossiersOuverts' => "SELECT COUNT(*) FROM usss_dossiers WHERE statut IN ('OUVERT','EN_COURS')",
    'niveauAlerte' => "SELECT * FROM usss_niveau_alerte ORDER BY created_at DESC LIMIT 1",
    'statsHebdo' => "SELECT COUNT(DISTINCT d.id) as dossiers_semaine FROM usss_dossiers d WHERE DATE(d.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
    'agentsActifs_JSON_ARRAY' => "SELECT a.id, COUNT(DISTINCT o.id) as nb_ops FROM usss_agents a LEFT JOIN usss_operations o ON JSON_CONTAINS(o.participants, JSON_ARRAY(a.id)) WHERE a.statut='EN_SERVICE' GROUP BY a.id LIMIT 1",
    'dossiersRecents' => "SELECT d.*, a.nom_rp FROM usss_dossiers d JOIN usss_agents a ON d.agent_responsable = a.id ORDER BY d.created_at DESC LIMIT 1",
    'operationsActives' => "SELECT o.*, a.nom_rp FROM usss_operations o JOIN usss_agents a ON o.chef_operation = a.id WHERE o.statut IN ('PLANIFIEE','EN_COURS') LIMIT 1",
    'mandatsAttente' => "SELECT m.*, a.nom_rp FROM usss_mandats m JOIN usss_agents a ON m.agent_demandeur = a.id WHERE m.statut='EN_ATTENTE_VALIDATION' LIMIT 1",
    'annonces' => "SELECT a.*, u.username FROM usss_annonces a JOIN users u ON a.auteur_id = u.id WHERE a.actif=1 LIMIT 1",
    'divisions' => "SELECT d.*, COUNT(a.id) as effectif FROM usss_divisions d LEFT JOIN usss_agents a ON d.id=a.division_id AND a.statut='EN_SERVICE' WHERE d.actif=1 GROUP BY d.id ORDER BY d.ordre LIMIT 1",
];

foreach ($queries as $name => $sql) {
    try {
        $res = $db->query($sql)->fetch();
        echo "<span class='ok'>✓ $name — OK</span>";
        if ($res) {
            echo "<pre style='margin-top:-.2rem;margin-bottom:.5rem'>" . htmlspecialchars(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
        } else {
            echo "<span class='warn' style='margin-top:-.2rem'>  → Aucun résultat (table vide)</span>";
        }
    } catch (PDOException $e) {
        echo "<span class='fail'>✗ $name — ERREUR : " . htmlspecialchars($e->getMessage()) . "</span>";
        echo "<pre style='background:#450a0a;margin-top:-.2rem'>SQL : $sql</pre>";
    }
}

// ── ÉTAPE 6 : Colonnes importantes ───────────────────────────
echo "<h2>6. Colonnes critiques</h2>";

$colChecks = [
    'usss_dossiers'       => ['reference','titre','priorite','statut','date_ouverture','agent_responsable','division_responsable'],
    'usss_operations'     => ['nom_code','statut','chef_operation','participants','niveau_classification','date_debut','division_responsable'],
    'usss_agents'         => ['nom_rp','prenom_rp','badge_number','grade','statut','division_id'],
    'usss_niveau_alerte'  => ['niveau','description','created_at'],
    'usss_annonces'       => ['titre','contenu','actif','niveau_importance','date_publication','date_expiration','auteur_id'],
    'usss_mandats'        => ['statut','agent_demandeur','created_at'],
    'usss_divisions'      => ['nom','code','actif','ordre'],
];

foreach ($colChecks as $tbl => $needed) {
    try {
        $found = $db->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($needed, $found);
        if (empty($missing)) {
            echo "<span class='ok'>✓ $tbl — toutes les colonnes présentes</span>";
        } else {
            echo "<span class='fail'>✗ $tbl — COLONNES MANQUANTES : " . implode(', ', $missing) . "</span>";
            echo "<span class='warn'>  Colonnes disponibles : " . implode(', ', $found) . "</span>";
        }
    } catch (PDOException $e) {
        echo "<span class='fail'>✗ $tbl — Table inaccessible</span>";
    }
}

// ── ÉTAPE 7 : Constants & fonctions ──────────────────────────
echo "<h2>7. Constantes & fonctions</h2>";
foreach (['SITE_URL','DB_HOST','DB_NAME'] as $c) {
    if (defined($c)) {
        echo "<span class='ok'>✓ Constante $c = " . htmlspecialchars(constant($c)) . "</span>";
    } else {
        echo "<span class='warn'>⚠ Constante $c non définie</span>";
    }
}
foreach (['requireLogin','hasRole','redirect'] as $fn) {
    if (function_exists($fn)) {
        echo "<span class='ok'>✓ Fonction $fn() disponible</span>";
    } else {
        echo "<span class='fail'>✗ Fonction $fn() MANQUANTE</span>";
    }
}

echo "<h2 style='color:#22c55e'>✅ Diagnostic terminé</h2>";
echo "<p style='color:#ef4444;font-weight:700'>⚠️ Supprimer ce fichier après diagnostic !</p>";
echo "</body></html>";