<?php
// ============================================
// FONCTIONS MAINTENANCE - À AJOUTER À CONFIG.PHP
// ============================================

/**
 * Vérifie si un panel est en maintenance
 * Les utilisateurs avec rôle Staff peuvent toujours accéder
 * 
 * @param string $panel Le code du panel (ex: 'LSPD', 'HAUTE_LUXURY')
 * @return bool True si en maintenance (pour ce user), False sinon
 */
function isInMaintenance($panel) {
    $db = Database::getInstance()->getConnection();
    
    // BYPASS MAINTENANCE pour Staff
    // Ces rôles peuvent accéder même en maintenance
    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Ilégale', 'Pôle Légal'])) {
        return false;
    }
    
    try {
        // Vérifier si ALL est en maintenance (met tout en maintenance)
        $stmt = $db->prepare("SELECT is_active FROM maintenance WHERE panel = 'ALL'");
        $stmt->execute();
        $allMaintenance = $stmt->fetchColumn();
        
        if ($allMaintenance) {
            return true;
        }
        
        // Vérifier le panel spécifique
        $stmt = $db->prepare("SELECT is_active FROM maintenance WHERE panel = ?");
        $stmt->execute([$panel]);
        $result = $stmt->fetchColumn();
        
        return (bool)$result;
        
    } catch (Exception $e) {
        error_log("Erreur vérification maintenance: " . $e->getMessage());
        return false; // En cas d'erreur, ne pas bloquer
    }
}

/**
 * Récupère le message de maintenance pour un panel
 * 
 * @param string $panel Le code du panel
 * @return string Le message de maintenance ou un message par défaut
 */
function getMaintenanceMessage($panel) {
    $db = Database::getInstance()->getConnection();
    
    try {
        // Vérifier ALL en premier
        $stmt = $db->prepare("SELECT message FROM maintenance WHERE panel = 'ALL' AND is_active = 1");
        $stmt->execute();
        $allMessage = $stmt->fetchColumn();
        
        if ($allMessage) {
            return $allMessage;
        }
        
        // Message du panel spécifique
        $stmt = $db->prepare("SELECT message FROM maintenance WHERE panel = ? AND is_active = 1");
        $stmt->execute([$panel]);
        $message = $stmt->fetchColumn();
        
        return $message ?: 'Ce panel est actuellement en maintenance. Merci de réessayer plus tard.';
        
    } catch (Exception $e) {
        error_log("Erreur récupération message maintenance: " . $e->getMessage());
        return 'Ce panel est actuellement en maintenance. Merci de réessayer plus tard.';
    }
}

// ============================================
// EXEMPLE D'UTILISATION DANS CHAQUE PAGE
// ============================================

/*

// EN HAUT DE CHAQUE PAGE DE PANEL (après requireLogin)
// Exemple pour LSPD :

<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

// Vérifier maintenance
if (isInMaintenance('LSPD')) {
    $maintenanceMessage = getMaintenanceMessage('LSPD');
    include __DIR__ . '/../../pages/maintenance-page.php';
    exit;
}

// Si on arrive ici, soit pas en maintenance, soit user est Staff
// ... suite du code normal de la page
?>

*/

// ============================================
// LISTE DES CODES PANELS
// ============================================

/*
Services Publics:
- 'STAFF'
- 'DOJ'
- 'USSS'
- 'LSPD'
- 'LSSD'
- 'SAMS'
- 'GRUPP6'

Bars & Boîtes:
- 'HAUTE_LUXURY'
- 'BAHAMAS'

Garages:
- 'BENNYS'
- 'LS_CUSTOM'
- 'LS_MOTORS'
- 'HEAVY_IMPOUND'

Commerces:
- 'DYNASTY'
- 'LTD'
- 'AMMUNATION'
- 'LIFE_INVADER'
- 'POSTOP'
- 'TAXI'
- 'TRIADS'

Restaurants:
- 'UWU_COFFEE'
- 'RESTAURANT_KOI'
- 'UP_ATOM'
*/

// ============================================
// PAGE MAINTENANCE (maintenance-page.php)
// ============================================

/*
Créer le fichier: /pages/maintenance-page.php

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance en cours</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #0a0e1a 0%, #1a1f35 100%);
            color: #e8edf5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .maintenance-container {
            max-width: 600px;
            width: 100%;
            text-align: center;
        }
        
        .maintenance-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 2rem;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 0 20px rgba(245, 158, 11, 0);
            }
        }
        
        h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .message {
            font-size: 1.1rem;
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="maintenance-icon">
            🔧
        </div>
        <h1>Maintenance en cours</h1>
        <p class="message">
            <?php echo htmlspecialchars($maintenanceMessage ?? 'Ce panel est actuellement en maintenance. Merci de réessayer plus tard.'); ?>
        </p>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="back-button">
            ← Retour au dashboard
        </a>
    </div>
</body>
</html>
*/
?>