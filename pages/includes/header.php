<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' . SITE_NAME : SITE_NAME; ?></title>
    
    <!-- Styles -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo ASSETS_PATH; ?>/css/style.css">
    <link rel="stylesheet" href="<?php echo ASSETS_PATH; ?>/css/profile.css">
    
    <style>
        .dashboard-header nav ul {
            display: flex;
            gap: 2rem;
            list-style: none;
            align-items: center;
        }

        .dashboard-header nav a {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            position: relative;
            transition: var(--transition-normal);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
        }

        .dashboard-header nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--accent-blue), var(--accent-cyan));
            transform: translateX(-50%);
            transition: var(--transition-normal);
        }

        .dashboard-header nav a:hover {
            color: var(--text-primary);
        }

        .dashboard-header nav a:hover::after {
            width: 80%;
        }

        .dashboard-header nav a.active {
            color: var(--text-primary);
            background: rgba(65, 105, 225, 0.1);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="dashboard-header">
        <div class="container">
            <div class="header-content">
                <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="logo">
                    <i class="fas fa-shield-alt"></i> MDT
                </a>
                
                <nav>
                    <ul>
                        <li><a href="<?php echo SITE_URL; ?>/pages/dashboard.php">Accueil</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/profile.php">Mon Profil</a></li>
                        <li><a href="<?php echo SITE_URL; ?>/logout.php">Déconnexion</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    </header>
</body>
</html>
