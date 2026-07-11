<?php
/**
 * config_local_writer.php — Édition sécurisée de config/config.local.php.
 *
 * Permet de mettre à jour une seule constante PHP dans le fichier de config
 * locale sans toucher au reste. Utilisé notamment par le panel staff pour
 * persister NEXIUM_API_HMAC_KEY après une saisie utilisateur.
 *
 * Sécurité :
 *  - Écriture atomique (fichier .tmp puis rename).
 *  - Backup .bak avant écrasement.
 *  - Refus si la valeur contient des caractères suspects (NUL, retour chariot).
 *  - Permissions resserrées (0640).
 */
declare(strict_types=1);

/**
 * Met à jour (ou crée) une constante define() dans config/config.local.php.
 *
 * @param string $constName  Nom de la constante (ex. NEXIUM_API_HMAC_KEY)
 * @param string $value      Nouvelle valeur (chaîne)
 * @param string|null $path  Chemin du fichier (par défaut config/config.local.php)
 * @return array{0:bool,1:string}  [success, message]
 */
function mdt_config_local_set_constant(string $constName, string $value, ?string $path = null): array
{
    if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $constName)) {
        return [false, 'Nom de constante invalide.'];
    }
    // Refuse les valeurs avec retour ligne ou NUL (injection PHP)
    if (preg_match("/[\x00\r\n]/", $value)) {
        return [false, 'Valeur contient des caractères interdits (NL/NUL).'];
    }

    $path ??= dirname(__DIR__) . '/config/config.local.php';

    // Crée le fichier s'il n'existe pas
    if (!is_file($path)) {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return [false, 'Dossier config/ inexistant et impossible à créer.'];
        }
        $base = "<?php\ndeclare(strict_types=1);\n";
        if (@file_put_contents($path, $base) === false) {
            return [false, 'Impossible de créer config/config.local.php.'];
        }
        @chmod($path, 0640);
    }

    if (!is_writable($path)) {
        return [false, basename($path) . ' n\'est pas inscriptible (vérifiez les permissions, attendu 0640).'];
    }

    $source = @file_get_contents($path);
    if ($source === false) {
        return [false, 'Lecture impossible de ' . basename($path) . '.'];
    }

    // Vérification rapide : doit commencer par une ouverture PHP standard.
    // Évite d'utiliser '<' . '?' dans le source pour ne pas être trompé par short_open_tag.
    $head = ltrim($source);
    $expectedOpen = '<' . '?' . 'php';
    if (strncmp($head, $expectedOpen, 5) !== 0) {
        return [false, basename($path) . ' ne commence pas par une ouverture PHP standard — refus d\'écriture.'];
    }

    $escaped   = addcslashes($value, "\\'");
    $newDefine = "define('{$constName}', '{$escaped}');";

    // Remplace si déjà présent, sinon ajoute en fin de fichier.
    $pattern = '/define\s*\(\s*[\'"]' . preg_quote($constName, '/') . '[\'"]\s*,\s*[^)]*\)\s*;?/u';
    if (preg_match($pattern, $source)) {
        $newSource = preg_replace($pattern, $newDefine, $source, 1);
        if ($newSource === null) {
            return [false, 'Échec lors du remplacement de la constante.'];
        }
    } else {
        // Évite de couper après une éventuelle balise de fermeture PHP
        $body = rtrim($source);
        $closeTag = '?' . '>';
        if (str_ends_with($body, $closeTag)) {
            $body = rtrim(substr($body, 0, -2));
        }
        $newSource = $body . "\n\n/* Ajouté automatiquement depuis le panel Paramètres le " . date('c') . " */\n" . $newDefine . "\n";
    }

    // Sanity check : tente une compilation token-level pour rejeter une corruption
    if (!@token_get_all($newSource, TOKEN_PARSE)) {
        return [false, 'Le nouveau contenu n\'est pas du PHP valide — annulation.'];
    }

    // Backup .bak (best-effort)
    @copy($path, $path . '.bak');

    // Écriture atomique
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $newSource) === false) {
        return [false, 'Écriture du fichier temporaire impossible.'];
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return [false, 'Renommage du fichier temporaire impossible.'];
    }
    @chmod($path, 0640);

    return [true, 'Constante ' . $constName . ' mise à jour dans ' . basename($path) . '.'];
}
