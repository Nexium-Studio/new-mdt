<?php
require_once __DIR__ . '/config/config.php';

// Si déjà connecté, rediriger
if (isLoggedIn()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$error = '';
$success = '';
$step = 1; // Étape 1: entrer Discord ID

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $discord_id = sanitize($_POST['discord_id'] ?? '');
    
    if (empty($discord_id)) {
        $error = 'Veuillez entrer votre ID Discord';
    } elseif (!preg_match('/^\d{17,20}$/', $discord_id)) {
        $error = 'ID Discord invalide. L\'ID doit être composé uniquement de chiffres (17-20 caractères)';
    } else {
        // Vérifier si l'ID Discord existe dans la base de données
        $db = Database::getInstance();
        $stmt = $db->getConnection()->prepare("SELECT id, email, username, discord_id FROM users WHERE discord_id = ? AND is_active = 1");
        $stmt->execute([$discord_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $error = 'Aucun compte n\'est associé à cet ID Discord';
        } else {
            // Générer un nouveau mot de passe temporaire
            $tempPassword = generateSecurePassword(12);
            $hashedPassword = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            
            // Mettre à jour le mot de passe dans la base de données
            $updateStmt = $db->getConnection()->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateResult = $updateStmt->execute([$hashedPassword, $user['id']]);
            
            if ($updateResult) {
                if (trim((string) BOT_API_URL) === '') {
                    $error = 'Réinitialisation par bot non configurée sur ce serveur (BOT_API_URL). Contactez un administrateur.';
                } else {
                // Envoyer la demande au bot Discord
                $botApiUrl = BOT_API_URL;
                $timeout = BOT_API_TIMEOUT;
                
                $postData = [
                    'discord_id' => $discord_id,
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'temp_password' => $tempPassword,
                    'secret_key' => BOT_API_SECRET
                ];
                
                $ch = curl_init($botApiUrl . '/api/password-reset');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_close($ch);
                
                // Log détaillé
                error_log("[BOT_API] URL: $botApiUrl/api/password-reset | HTTP: $httpCode | Timeout: {$timeout}s | Error: $curlError ($curlErrno) | Response: $response");
                
                if ($httpCode === 200 && $response) {
                    $success = 'Un message privé contenant vos nouveaux identifiants a été envoyé sur Discord. Vérifiez vos MPs !';
                    $step = 2;
                } else {
                    // Déterminer le type d'erreur
                    if ($curlErrno) {
                        $error = "Erreur de connexion au serveur Discord. Veuillez réessayer dans quelques instants. (Code: $curlErrno)";
                    } elseif ($httpCode === 0) {
                        $error = "Impossible de contacter le serveur Discord. Veuillez réessayer plus tard.";
                    } else {
                        $error = "Erreur serveur Discord (Code: $httpCode). Veuillez réessayer.";
                    }
                    
                    error_log("Password reset failed for Discord ID: $discord_id - HTTP: $httpCode - Curl Error: $curlError - Response: $response");
                }
                }
            } else {
                $error = 'Une erreur est survenue lors de la mise à jour du mot de passe.';
            }
        }
    }
}

/**
 * Génère un mot de passe sécurisé
 */
function generateSecurePassword($length = 12) {
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '!@#$%&*';
    
    $password = '';
    
    // Au moins un de chaque type
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];
    
    // Compléter le reste
    $allChars = $uppercase . $lowercase . $numbers . $special;
    for ($i = 4; $i < $length; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }
    
    // Mélanger le mot de passe
    return str_shuffle($password);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo ASSETS_PATH; ?>/css/style.css">
    <link rel="stylesheet" href="<?php echo ASSETS_PATH; ?>/css/auth.css">
    <style>
        .discord-help {
            background: rgba(88, 101, 242, 0.1);
            border: 1px solid rgba(88, 101, 242, 0.3);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .discord-help h3 {
            color: #5865F2;
            font-size: 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .discord-help ol {
            font-size: 13px;
            color: #b0b0b0;
            padding-left: 20px;
            margin: 0;
        }
        .discord-help ol li {
            margin-bottom: 6px;
        }
        .discord-help code {
            background: rgba(0, 0, 0, 0.3);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            color: #5865F2;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #57F287, #3ba55c);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .success-icon svg {
            width: 40px;
            height: 40px;
            color: white;
        }
        .success-message {
            text-align: center;
        }
        .success-message h2 {
            color: #57F287;
            margin-bottom: 10px;
        }
        .success-message p {
            color: #b0b0b0;
            margin-bottom: 20px;
        }
        .warning-box {
            background: rgba(254, 231, 92, 0.1);
            border: 1px solid rgba(254, 231, 92, 0.3);
            border-radius: 8px;
            padding: 12px;
            margin-top: 15px;
            font-size: 13px;
            color: #FEE75C;
        }
        .warning-box svg {
            vertical-align: middle;
            margin-right: 6px;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-background"></div>
        
        <div class="auth-content">
            <!-- Logo et retour -->
            <div class="auth-header">
                <a href="login.php" class="back-link">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                    <span>Retour à la connexion</span>
                </a>
            </div>
            
            <!-- Carte -->
            <div class="auth-card">
                <?php if ($step === 2 && $success): ?>
                    <!-- Étape 2: Succès -->
                    <div class="success-message">
                        <div class="success-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                <path d="M20 6L9 17l-5-5"/>
                            </svg>
                        </div>
                        <h2>Message envoyé !</h2>
                        <p>Vos nouveaux identifiants ont été envoyés sur Discord.<br>Vérifiez vos messages privés.</p>
                        
                        <div class="warning-box">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                            <strong>Important :</strong> Pensez à modifier votre mot de passe temporaire dès votre connexion !
                        </div>
                        
                        <a href="login.php" class="btn btn-primary btn-block" style="margin-top: 20px;">
                            Retour à la connexion
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Étape 1: Formulaire -->
                    <div class="auth-card-header">
                        <h1 class="auth-title">Mot de passe oublié</h1>
                        <p class="auth-subtitle">Récupérez vos identifiants via Discord</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="discord-help">
                        <h3>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515a.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0a12.64 12.64 0 0 0-.617-1.25a.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057a19.9 19.9 0 0 0 5.993 3.03a.078.078 0 0 0 .084-.028a14.09 14.09 0 0 0 1.226-1.994a.076.076 0 0 0-.041-.106a13.107 13.107 0 0 1-1.872-.892a.077.077 0 0 1-.008-.128a10.2 10.2 0 0 0 .372-.292a.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127a12.299 12.299 0 0 1-1.873.892a.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028a19.839 19.839 0 0 0 6.002-3.03a.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/>
                            </svg>
                            Comment trouver mon ID Discord ?
                        </h3>
                        <ol>
                            <li>Ouvrez Discord et allez dans <strong>Paramètres utilisateur</strong></li>
                            <li>Allez dans <strong>Avancés</strong> et activez le <strong>Mode développeur</strong></li>
                            <li>Cliquez droit sur votre avatar puis <strong>"Copier l'identifiant"</strong></li>
                            <li>Collez l'ID ci-dessous (exemple: <code>389168772243783690</code>)</li>
                        </ol>
                        Attention, si vous n'avez pas lié votre compte Discord aux MDT, cette méthode ne fonctionnera pas !
                    </div>
                    
                    <form method="POST" class="auth-form">
                        <div class="form-group">
                            <label for="discord_id" class="form-label">ID Discord</label>
                            <input 
                                type="text" 
                                id="discord_id" 
                                name="discord_id" 
                                class="form-input" 
                                placeholder="123456789012345678"
                                value="<?php echo htmlspecialchars($_POST['discord_id'] ?? ''); ?>"
                                pattern="\d{17,20}"
                                required
                            >
                        </div>
                        
                        <button type="submit" class="btn btn-discord btn-block">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515a.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0a12.64 12.64 0 0 0-.617-1.25a.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057a19.9 19.9 0 0 0 5.993 3.03a.078.078 0 0 0 .084-.028a14.09 14.09 0 0 0 1.226-1.994a.076.076 0 0 0-.041-.106a13.107 13.107 0 0 1-1.872-.892a.077.077 0 0 1-.008-.128a10.2 10.2 0 0 0 .372-.292a.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127a12.299 12.299 0 0 1-1.873.892a.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028a19.839 19.839 0 0 0 6.002-3.03a.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/>
                            </svg>
                            Envoyer mes identifiants sur Discord
                        </button>
                    </form>
                    
                    <div class="auth-footer" style="margin-top: 20px;">
                        <p>Vous n'avez pas de compte Discord lié ? <a href="mailto:support@lysoria.fr">Contactez le support</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
