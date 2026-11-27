<#
.SYNOPSIS
    Deploys Country Quiz to the Synology web folder.

.DESCRIPTION
    Copies only the files the website needs (index.php, api.php, .htaccess, assets/, src/, data/)
    to the target folder with robocopy, which is built into Windows.

    The sub folders assets/, src/ and data/ are mirrored: files you deleted or renamed locally
    are also removed on the NAS. Nothing else in the target folder is touched.

    Not copied: .git, README.md, tools/ (only needed to rebuild the map data) and this script.

.PARAMETER Target
    Destination folder. Default: \\10.8.0.1\web\world

.PARAMETER WhatIf
    Show what would be copied/removed without changing anything.

.EXAMPLE
    .\deploy.ps1
.EXAMPLE
    .\deploy.ps1 -WhatIf
.EXAMPLE
    .\deploy.ps1 -Target \\10.8.0.1\web\world-test
#>
param(
    [string] $Target = '\\10.8.0.1\web\world',
    [switch] $WhatIf
)

$ErrorActionPreference = 'Stop'

# The repository folder is the folder this script lives in.
$sourceFolder = $PSScriptRoot

# Files in the repository root that the website needs.
$rootFiles = @('index.php', 'api.php', '.htaccess')

# Folders that are mirrored to the NAS.
$mirroredFolders = @('assets', 'src', 'data')

# Common robocopy options:
#   /R:2 /W:2  retry twice, wait 2 seconds (default would retry for a very long time)
#   /NP /NDL   no progress percentage and no directory lines, to keep the output short
#   /XF        skip files that never belong on the web server
$commonOptions = @('/R:2', '/W:2', '/NP', '/NDL', '/XF', '*.geojson', '.DS_Store', 'Thumbs.db')
if ($WhatIf) {
    # /L = list only, nothing is copied or deleted.
    $commonOptions += '/L'
}

<#
    Runs robocopy and stops the script if it reports a failure.
    Robocopy exit codes 0-7 mean success (with or without copied files); 8 and above are errors.
#>
function Invoke-Robocopy {
    param([string[]] $Arguments)

    & robocopy @Arguments
    if ($LASTEXITCODE -ge 8) {
        throw "robocopy failed with exit code $LASTEXITCODE (arguments: $Arguments)"
    }
}

# Check that the NAS share is reachable before copying anything.
$targetParent = Split-Path -Path $Target -Parent
if (-not (Test-Path -LiteralPath $targetParent)) {
    throw "Cannot reach $targetParent. Is the NAS on and are you signed in to the share (open it in Explorer once)?"
}

Write-Host "Deploying Country Quiz" -ForegroundColor Cyan
Write-Host "  from: $sourceFolder"
Write-Host "  to:   $Target"
if ($WhatIf) {
    Write-Host "  (WhatIf: only listing changes, nothing is copied)" -ForegroundColor Yellow
}

# 1. Root files (copied, never deleted). /XO is not used so a rollback to older files also works.
Invoke-Robocopy (@($sourceFolder, $Target) + $rootFiles + $commonOptions)

# 2. Folders, mirrored so removed files disappear on the NAS too.
foreach ($folderName in $mirroredFolders) {
    $sourcePath = Join-Path $sourceFolder $folderName
    $targetPath = Join-Path $Target $folderName
    Invoke-Robocopy (@($sourcePath, $targetPath, '/MIR') + $commonOptions)
}

Write-Host ""
Write-Host "Done. Open the site in your browser to check it." -ForegroundColor Green
