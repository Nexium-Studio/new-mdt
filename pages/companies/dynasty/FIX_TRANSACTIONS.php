<?php
/**
 * FIX_TRANSACTIONS.php
 * Script pour corriger automatiquement les transactions imbriquées dans Dynasty
 * 
 * UTILISATION:
 * 1. Uploader ce fichier dans /pages/companies/dynasty/
 * 2. Accéder via navigateur: https://mdt.lysoria.fr/pages/companies/dynasty/FIX_TRANSACTIONS.php
 * 3. Le script corrige automatiquement les 3 fichiers
 * 4. Supprimer ce fichier après utilisation
 */

// Chemins des fichiers à corriger
$files = [
    'biens.php'     => __DIR__ . '/biens.php',
    'locations.php' => __DIR__ . '/locations.php',
    'ventes.php'    => __DIR__ . '/ventes.php'
];

// Vérifier que les fichiers existent
foreach ($files as $name => $path) {
    if (!file_exists($path)) {
        die("❌ Fichier introuvable: $name à $path");
    }
}

// Fonction de correction pour nextRef, generateBL, generateBV
function fixTransactionFunction($content, $functionName, $type) {
    // Pattern pour trouver la fonction
    $pattern = '/function ' . preg_quote($functionName) . '\(\$db\)\s*\{([^}]+)\}/s';
    
    if (!preg_match($pattern, $content, $matches)) {
        return $content;
    }
    
    $oldFunction = $matches[0];
    
    // Nouvelle version corrigée
    $newFunction = "function $functionName(\$db) {
    // Vérifier si une transaction est déjà active
    \$ownTransaction = false;
    if (!\$db->inTransaction()) {
        \$db->beginTransaction();
        \$ownTransaction = true;
    }
    
    try {
        \$r    = \$db->query(\"SELECT * FROM dynasty_sequences WHERE type='$type' FOR UPDATE\")->fetch(PDO::FETCH_ASSOC);
        \$year = (int)date('Y');
        \$next = (\$r && (int)\$r['annee'] === \$year) ? (int)\$r['dernier'] + 1 : 1;
        \$db->prepare(\"INSERT INTO dynasty_sequences(type,annee,dernier) VALUES('$type',?,?) ON DUPLICATE KEY UPDATE annee=VALUES(annee),dernier=VALUES(dernier)\")
           ->execute([\$year, \$next]);
        
        // Commit seulement si c'est nous qui avons ouvert la transaction
        if (\$ownTransaction) {
            \$db->commit();
        }
        
        ";
    
    // Ajouter le return selon le type
    if ($type === 'REF') {
        $newFunction .= "return 'REF-'.str_pad(\$next, 5, '0', STR_PAD_LEFT);";
    } elseif ($type === 'BL') {
        $newFunction .= "return 'BL-'.\$year.'-'.str_pad(\$next, 5, '0', STR_PAD_LEFT);";
    } elseif ($type === 'BV') {
        $newFunction .= "return 'BV-'.\$year.'-'.str_pad(\$next, 5, '0', STR_PAD_LEFT);";
    }
    
    $newFunction .= "
    } catch (Exception \$e) {
        if (\$ownTransaction) {
            \$db->rollBack();
        }
        throw \$e;
    }
}";
    
    // Remplacer l'ancienne fonction par la nouvelle
    return str_replace($oldFunction, $newFunction, $content);
}

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <title>Correction des Transactions Dynasty</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #0f172a;
            color: #e2e8f0;
        }
        .card {
            background: #1e293b;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid #334155;
        }
        h1 {
            color: #22c55e;
            margin-top: 0;
        }
        .success {
            color: #22c55e;
            font-weight: bold;
        }
        .error {
            color: #ef4444;
            font-weight: bold;
        }
        .warning {
            color: #f59e0b;
            font-weight: bold;
        }
        pre {
            background: #0f172a;
            padding: 12px;
            border-radius: 8px;
            overflow-x: auto;
            border: 1px solid #334155;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #22c55e;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            margin-top: 10px;
        }
        .btn:hover {
            background: #16a34a;
        }
    </style>
</head>
<body>
    <div class=\"card\">
        <h1>🔧 Correction des Transactions Dynasty</h1>
        <p>Ce script corrige automatiquement les transactions imbriquées dans les fichiers Dynasty.</p>
    </div>
";

$results = [];

// Corriger biens.php
echo "<div class=\"card\">";
echo "<h2>📄 biens.php</h2>";
$content = file_get_contents($files['biens.php']);
$original = $content;
$content = fixTransactionFunction($content, 'nextRef', 'REF');

if ($content !== $original) {
    if (file_put_contents($files['biens.php'], $content)) {
        echo "<p class=\"success\">✅ Fonction nextRef() corrigée avec succès</p>";
        $results['biens.php'] = true;
    } else {
        echo "<p class=\"error\">❌ Erreur lors de l'écriture du fichier</p>";
        $results['biens.php'] = false;
    }
} else {
    echo "<p class=\"warning\">⚠️ Fonction nextRef() déjà corrigée ou non trouvée</p>";
    $results['biens.php'] = 'skip';
}
echo "</div>";

// Corriger locations.php
echo "<div class=\"card\">";
echo "<h2>📄 locations.php</h2>";
$content = file_get_contents($files['locations.php']);
$original = $content;
$content = fixTransactionFunction($content, 'generateBL', 'BL');

if ($content !== $original) {
    if (file_put_contents($files['locations.php'], $content)) {
        echo "<p class=\"success\">✅ Fonction generateBL() corrigée avec succès</p>";
        $results['locations.php'] = true;
    } else {
        echo "<p class=\"error\">❌ Erreur lors de l'écriture du fichier</p>";
        $results['locations.php'] = false;
    }
} else {
    echo "<p class=\"warning\">⚠️ Fonction generateBL() déjà corrigée ou non trouvée</p>";
    $results['locations.php'] = 'skip';
}
echo "</div>";

// Corriger ventes.php
echo "<div class=\"card\">";
echo "<h2>📄 ventes.php</h2>";
$content = file_get_contents($files['ventes.php']);
$original = $content;
$content = fixTransactionFunction($content, 'generateBV', 'BV');

if ($content !== $original) {
    if (file_put_contents($files['ventes.php'], $content)) {
        echo "<p class=\"success\">✅ Fonction generateBV() corrigée avec succès</p>";
        $results['ventes.php'] = true;
    } else {
        echo "<p class=\"error\">❌ Erreur lors de l'écriture du fichier</p>";
        $results['ventes.php'] = false;
    }
} else {
    echo "<p class=\"warning\">⚠️ Fonction generateBV() déjà corrigée ou non trouvée</p>";
    $results['ventes.php'] = 'skip';
}
echo "</div>";

// Résumé
$success = count(array_filter($results, fn($v) => $v === true));
$total = count($results);

echo "<div class=\"card\">";
echo "<h2>📊 Résumé</h2>";
echo "<p><strong>$success/$total fichiers corrigés</strong></p>";

if ($success > 0) {
    echo "<p class=\"success\">✅ Les corrections ont été appliquées avec succès !</p>";
    echo "<p><strong>Prochaines étapes :</strong></p>";
    echo "<ol>";
    echo "<li>Tester la création d'un bien, d'une location et d'une vente</li>";
    echo "<li>Vérifier qu'il n'y a plus d'erreur \"already an active transaction\"</li>";
    echo "<li>Supprimer ce fichier FIX_TRANSACTIONS.php du serveur</li>";
    echo "</ol>";
} else {
    echo "<p class=\"warning\">⚠️ Aucune correction n'a été nécessaire</p>";
    echo "<p>Les fonctions sont peut-être déjà corrigées, ou le format a changé.</p>";
}

echo "<a href=\"biens.php\" class=\"btn\">🏠 Aller à Biens</a>";
echo "<a href=\"locations.php\" class=\"btn\" style=\"background:#3b82f6;\">📋 Aller à Locations</a>";
echo "<a href=\"ventes.php\" class=\"btn\" style=\"background:#8b5cf6;\">💰 Aller à Ventes</a>";
echo "</div>";

echo "<div class=\"card\">";
echo "<h2>🔍 Qu'est-ce qui a été corrigé ?</h2>";
echo "<p>Chaque fonction (nextRef, generateBL, generateBV) a été modifiée pour :</p>";
echo "<ol>";
echo "<li>Vérifier si une transaction est déjà active avec <code>\$db->inTransaction()</code></li>";
echo "<li>N'ouvrir une transaction que si aucune n'est active</li>";
echo "<li>Ne commit que si c'est elle qui a ouvert la transaction</li>";
echo "<li>Gérer les erreurs avec try/catch et rollback si nécessaire</li>";
echo "</ol>";
echo "<p>Cela évite les transactions imbriquées qui causaient l'erreur.</p>";
echo "</div>";

echo "</body></html>";
?>