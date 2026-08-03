<?php
declare(strict_types=1);
define('SITE_URL', 'https://mdt.nexium-studio.com');
define('SITE_NAME', 'MDT Demo');
define('ADMIN_EMAIL', 'nexium.studio.off@gmail.com');
define('DISCORD_CLIENT_ID', '1478494174742581258');
define('DISCORD_CLIENT_SECRET', 'ITFvvxUH2GKI-VuU_qpSyQHtrWIjKtml');
define('BOT_API_URL', 'http://87.106.8.133:3001');
define('BOT_API_SECRET', 'lxiK-hQi5Lib3JbH-pLmsDLQI0r_k0r8');
define('BOT_API_TIMEOUT', 20);
define('ANTHROPIC_API_KEY', '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');
define('MDT_TIMEZONE', 'Europe/Paris');
define('MDT_DISPLAY_ERRORS', false);
/**
 * OBLIGATOIRE pour la licence Nexium : même valeur que API_SECRET_KEY
 * dans api.nexium-studio.com/config/config.php (sinon 0 produit + misconfigured_hmac).
 */
define('NEXIUM_API_HMAC_KEY', 'R,PiW%PbwP:biJjQvP7)25~VP$j7_5x;28ds]#2(iQ9.9*bU9Zu8=e-4F6Q65[Ei');

/**
 * Cle partagee avec le serveur FiveM (resource nexium_tablet -> sv_config.lua,
 * Config.ApiSecret). DOIT etre strictement identique des deux cotes.
 * Sert a authentifier l'appel server-to-server de auth/fivem-ticket.php.
 * Genere une valeur longue et aleatoire (48+ caracteres).
 */
define('FIVEM_AUTH_SECRET', '9B6ZwB4iK2zATuvV7y5w9Kjy3Yz8D8');


/**
 * Cle liaison Groq (MDT <-> Groq) : 
 */
define('MDT_AI_API_KEY', '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');
