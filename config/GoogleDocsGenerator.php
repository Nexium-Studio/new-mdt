<?php
/**
 * GÉNÉRATEUR DE DOCUMENTS HTML POUR FIVEM
 * Crée automatiquement des documents HTML partageables
 */

class GoogleDocsGenerator {
    
    /**
     * Génère un document HTML et retourne l'URL partageable
     * 
     * @param string $title Titre du document
     * @param string $content Contenu HTML du document
     * @param string $type Type de document (ordonnance, rapport, etc.)
     * @return string|null URL du document généré ou null en cas d'erreur
     */
    public function generateDocument($title, $content, $type = 'medical') {
        try {
            // Créer le nom de fichier unique
            $filename = $this->generateFilename($type);
            $filepath = $this->getDocumentPath($filename);
            
            // Créer le dossier si nécessaire
            $this->ensureDirectoryExists($filepath);
            
            // Générer le HTML
            $html = $this->getDocumentTemplate($title, $content, $type);
            
            // Sauvegarder le fichier
            if (file_put_contents($filepath, $html) === false) {
                throw new Exception("Impossible de créer le fichier");
            }
            
            // Retourner l'URL publique
            return $this->getPublicUrl($filename);
            
        } catch (Exception $e) {
            error_log("Erreur génération document: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Génère un nom de fichier unique
     */
    private function generateFilename($type) {
        return $type . '_' . date('Ymd_His') . '_' . uniqid() . '.html';
    }
    
    /**
     * Retourne le chemin complet du fichier
     */
    private function getDocumentPath($filename) {
        return __DIR__ . '/../public/documents/' . $filename;
    }
    
    /**
     * Retourne l'URL publique du document
     */
    private function getPublicUrl($filename) {
        return SITE_URL . '/public/documents/' . $filename;
    }
    
    /**
     * Crée le dossier s'il n'existe pas
     */
    private function ensureDirectoryExists($filepath) {
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception("Impossible de créer le dossier documents");
            }
        }
    }
    
    /**
     * Template HTML Premium pour les documents SAMS
     */
    private function getDocumentTemplate($title, $content, $type) {
        $date = date('d/m/Y à H:i');
        $year = date('Y');
        
        // Icône selon le type
        $icons = [
            'ordonnance' => '💊',
            'rapport' => '📋',
            'certificat' => '📄',
            'grossesse' => '🤰',
            'naissance' => '👶',
            'psy' => '🧠',
            'arret' => '🏥',
            'aptitude' => '✅'
        ];
        
        $icon = $icons[$type] ?? '🏥';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Chakra+Petch:wght@400;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Chakra Petch', sans-serif;
            background: linear-gradient(135deg, #0d1220, #111a2e);
            color: #f0e6e8;
            padding: 2rem;
            min-height: 100vh;
            line-height: 1.6;
        }
        
        .document-wrapper {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .document-container {
            background: #1a1f2e;
            border: 2px solid rgba(255, 23, 68, 0.3);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 
                0 0 60px rgba(255, 23, 68, 0.2),
                0 30px 90px rgba(0, 0, 0, 0.6);
            position: relative;
        }
        
        .document-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #ff1744, #ff2d78, #ff1744, transparent);
        }
        
        .document-header {
            background: linear-gradient(135deg, rgba(255, 23, 68, 0.15), rgba(220, 20, 60, 0.08));
            border-bottom: 2px solid rgba(255, 23, 68, 0.25);
            padding: 2.5rem;
            position: relative;
        }
        
        .doc-logo-section {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .doc-logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, rgba(255, 23, 68, 0.25), rgba(220, 20, 60, 0.15));
            border: 2px solid rgba(255, 23, 68, 0.4);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            box-shadow: 0 0 30px rgba(255, 23, 68, 0.3);
        }
        
        .doc-logo-text {
            font-family: 'Orbitron', monospace;
            font-size: 1.3rem;
            font-weight: 900;
            background: linear-gradient(135deg, #ff1744, #ff4569);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 3px;
        }
        
        .doc-title {
            font-family: 'Orbitron', monospace;
            font-size: 2rem;
            font-weight: 900;
            color: #f0e6e8;
            margin-bottom: 0.75rem;
            text-shadow: 0 0 20px rgba(255, 23, 68, 0.3);
        }
        
        .doc-meta {
            display: flex;
            gap: 2rem;
            font-size: 0.9rem;
            color: #7a6b70;
            font-family: 'JetBrains Mono', monospace;
        }
        
        .doc-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .doc-meta-item i {
            color: #ff1744;
        }
        
        .document-body {
            padding: 3rem;
        }
        
        .document-content {
            font-size: 1.05rem;
            color: #f0e6e8;
            line-height: 1.8;
        }
        
        .document-content h2 {
            font-family: 'Orbitron', monospace;
            color: #ff4569;
            margin: 2rem 0 1rem;
            font-size: 1.3rem;
        }
        
        .document-content h3 {
            color: #ff4569;
            margin: 1.5rem 0 0.75rem;
        }
        
        .document-content p {
            margin-bottom: 1rem;
        }
        
        .document-content ul,
        .document-content ol {
            margin-left: 2rem;
            margin-bottom: 1rem;
        }
        
        .document-content li {
            margin-bottom: 0.5rem;
        }
        
        .document-content strong {
            color: #ff4569;
        }
        
        .document-content hr {
            border: none;
            border-top: 1px solid rgba(255, 23, 68, 0.2);
            margin: 2rem 0;
        }
        
        .signature-section {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid rgba(255, 23, 68, 0.2);
            text-align: right;
        }
        
        .signature-title {
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            color: #ff1744;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 0.9rem;
        }
        
        .signature-info {
            color: #7a6b70;
            font-size: 0.95rem;
        }
        
        .document-footer {
            padding: 2rem 3rem;
            border-top: 1px solid rgba(255, 23, 68, 0.15);
            background: rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        
        .footer-official {
            font-size: 0.85rem;
            color: #7a6b70;
            margin-bottom: 0.75rem;
        }
        
        .footer-verification {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 23, 68, 0.1);
            border: 1px solid rgba(255, 23, 68, 0.3);
            border-radius: 8px;
            font-size: 0.8rem;
            color: #ff4569;
            font-family: 'JetBrains Mono', monospace;
        }
        
        .print-button {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #ff1744, #dc143c);
            border: none;
            border-radius: 12px;
            color: white;
            font-family: 'Chakra Petch', sans-serif;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(255, 23, 68, 0.4);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .print-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(255, 23, 68, 0.6);
        }
        
        @media print {
            body {
                background: white;
                color: black;
                padding: 0;
            }
            
            .document-container {
                border: none;
                box-shadow: none;
            }
            
            .print-button {
                display: none;
            }
            
            .document-header {
                background: #f5f5f5;
                color: black;
            }
            
            .doc-title,
            .document-content {
                color: black;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .document-header,
            .document-body,
            .document-footer {
                padding: 1.5rem;
            }
            
            .doc-title {
                font-size: 1.5rem;
            }
            
            .doc-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="document-wrapper">
        <div class="document-container">
            <div class="document-header">
                <div class="doc-logo-section">
                    <div class="doc-logo-icon">{$icon}</div>
                    <div class="doc-logo-text">SAMS<br><span style="font-size: 0.6rem; opacity: 0.7;">San Andreas Medical Service</span></div>
                </div>
                
                <h1 class="doc-title">{$title}</h1>
                
                <div class="doc-meta">
                    <div class="doc-meta-item">
                        <i class="fas fa-calendar"></i>
                        <span>{$date}</span>
                    </div>
                    <div class="doc-meta-item">
                        <i class="fas fa-shield-alt"></i>
                        <span>Document Officiel</span>
                    </div>
                </div>
            </div>
            
            <div class="document-body">
                <div class="document-content">
                    {$content}
                </div>
                
                <div class="signature-section">
                    <div class="signature-title">
                        <i class="fas fa-user-md"></i> San Andreas Medical Service
                    </div>
                    <div class="signature-info">
                        Los Santos, San Andreas<br>
                        Tél: 911 | Email: contact@sams-sa.gov
                    </div>
                </div>
            </div>
            
            <div class="document-footer">
                <div class="footer-official">
                    <i class="fas fa-certificate"></i>
                    Ce document est officiel et a été généré automatiquement par le système MDT SAMS.
                </div>
                <div class="footer-verification">
                    <i class="fas fa-shield-check"></i>
                    Pour vérification, contactez le SAMS au 911
                </div>
            </div>
        </div>
    </div>
    
    <button class="print-button" onclick="window.print()">
        <i class="fas fa-print"></i>
        Imprimer
    </button>
</body>
</html>
HTML;
    }
}