<?php
require_once 'config/config.php';
require_once 'config/GoogleDocsGenerator.php';

// Test de génération
$generator = new GoogleDocsGenerator();

$title = "Test Document " . date('d/m/Y H:i:s');
$content = "<h2>Test de génération</h2><p>Ceci est un test.</p>";

$url = $generator->generateDocument($title, $content, 'test');

if ($url) {
    echo "✅ Document généré avec succès !<br>";
    echo "URL: <a href='$url' target='_blank'>$url</a><br>";
    echo "Chemin: " . DOCUMENTS_DIR . "<br>";
    
    // Vérifier que le fichier existe
    $files = glob(DOCUMENTS_DIR . '/test_*.html');
    echo "Fichiers trouvés: " . count($files) . "<br>";
    foreach ($files as $file) {
        echo "- " . basename($file) . " (" . filesize($file) . " bytes)<br>";
    }
} else {
    echo "❌ Erreur lors de la génération<br>";
    echo "Vérifiez les permissions du dossier: " . DOCUMENTS_DIR . "<br>";
    
    if (!is_dir(DOCUMENTS_DIR)) {
        echo "⚠️ Le dossier n'existe pas !<br>";
    } elseif (!is_writable(DOCUMENTS_DIR)) {
        echo "⚠️ Le dossier n'est pas accessible en écriture !<br>";
    }
}