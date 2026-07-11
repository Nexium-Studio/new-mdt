# Génère mdt_service_public_import_ready.sql puis crée une archive ZIP prête à déployer
# (sans secrets locaux ni verrou d'installation). Exécuter depuis PowerShell à la racine du projet
# ou : powershell -ExecutionPolicy Bypass -File scripts\package-release.ps1

$ErrorActionPreference = 'Stop'

$ProjectRoot = if ($PSScriptRoot) {
    (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
} else {
    $PWD.Path
}

if (-not (Test-Path (Join-Path $ProjectRoot 'install.php'))) {
    Write-Error "Répertoire projet invalide : $ProjectRoot (install.php introuvable)."
}

Set-Location $ProjectRoot

Write-Host ">> Préparation SQL mutualisé..."
$py = Join-Path $ProjectRoot 'database\prepare_sql_for_shared_hosting.py'
if (-not (Test-Path $py)) {
    Write-Error "Fichier manquant : $py"
}
& python $py
if ($LASTEXITCODE -ne 0) {
    Write-Error "Échec de prepare_sql_for_shared_hosting.py (code $LASTEXITCODE)."
}

$importReady = Join-Path $ProjectRoot 'mdt_service_public_import_ready.sql'
if (-not (Test-Path $importReady)) {
    Write-Error "Fichier non généré : mdt_service_public_import_ready.sql"
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmm'
$outDir = Join-Path $ProjectRoot 'dist'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$zipName = "MDT-release-$stamp.zip"
$zipPath = Join-Path $outDir $zipName

$stageRoot = Join-Path $env:TEMP ("mdt-zip-" + [guid]::NewGuid().ToString())
$innerName = 'MDT'
$stage = Join-Path $stageRoot $innerName
New-Item -ItemType Directory -Force -Path $stage | Out-Null

Write-Host ">> Copie des fichiers (exclusions : .git, secrets, verrou)..."
# /E sous-dossiers ; /XD exclut dossiers ; /XF exclut fichiers (noms, tous chemins)
$rc = & robocopy $ProjectRoot $stage /E /NFL /NDL /NJH /NJS `
    /XD .git .cursor dist node_modules `
    /XF config.local.php database.local.php installed.lock .env error_log.txt mdt_service_public_import_ready.sql.bak 2>&1
# robocopy : codes 0-7 = succès partiel ou complet
if ($LASTEXITCODE -gt 7) {
    Write-Error "robocopy a échoué (code $LASTEXITCODE)."
}

# Inclure explicitement le SQL généré (robocopy l’a copié s’il est à la racine — il est généré avant copie, OK)
if (-not (Test-Path (Join-Path $stage 'mdt_service_public_import_ready.sql'))) {
    Copy-Item -Path $importReady -Destination (Join-Path $stage 'mdt_service_public_import_ready.sql') -Force
}

Write-Host ">> Compression..."
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }
Compress-Archive -Path $stage -DestinationPath $zipPath -Force

Remove-Item -Recurse -Force $stageRoot

Write-Host ""
Write-Host "OK : $zipPath"
Write-Host "Déployez le dossier $innerName à la racine du site, base vide, puis https://votre-domaine/install.php"
Write-Host "Voir docs/PACKAGE_ZIP.md pour la liste d'exclusions et le dépannage."
