#Requires -Version 5.1
<#
.SYNOPSIS
  Bygger en WordPress-kompatibel plugin-zip (forward slashes, inte backslash).

.DESCRIPTION
  - Zip skapas i repots root: maca-backup-x.y.z.zip
  - create-zip.ps1 ligger i repots root
  - Höjer patch-version (x.y.z -> x.y.(z+1)) vid varje körning (om inte -SkipVersionBump)
  - Plugin-mapp i zip är alltid maca-backup (wp.org-slug), om inte -PluginSlug anges
  - Uppdaterar Version i maca-backup.php före paketering
  - wordpress.org-zip (default / -SkipVersionBump): tvingar MACA_BACKUP_PRO_MIGRATE_UI till false
    i zip-innehållet utan att ändra working tree (lokalt kan vara true för test)

.EXAMPLE
  .\create-zip.ps1 -SkipVersionBump
  .\create-zip.ps1
  .\create-zip.ps1 -IncludeMacaUpdater
  .\create-zip.ps1 -OutputPath .\maca-backup-1.0.1.zip
#>
[CmdletBinding()]
param(
    [string] $OutputPath = '',
    [string] $PluginSlug = 'maca-backup',
    [string] $ZipBasename = 'maca-backup',
    [switch] $SkipVersionBump,
    # Default zip is wordpress.org–safe (no private updater). Use -IncludeMacaUpdater for maca.se builds.
    [switch] $IncludeMacaUpdater,
    # Kept for callers; slug is always maca-backup unless -PluginSlug overrides.
    [switch] $WordPressOrg
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($PluginSlug)) {
    $PluginSlug = 'maca-backup'
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Root)) {
    $Root = Get-Location
}

$PluginFile = Join-Path $Root 'maca-backup.php'
$ReadmeFile = Join-Path $Root 'readme.txt'
if (-not (Test-Path -LiteralPath $PluginFile)) {
    throw "Hittar inte $PluginFile"
}

function Get-PluginVersion {
    param([string] $Path)

    $content = Get-Content -LiteralPath $Path -Raw -Encoding UTF8

    if ($content -match "define\s*\(\s*'MACA_BACKUP_PRO_VERSION'\s*,\s*'([\d.]+)'\s*\)") {
        return $Matches[1]
    }
    if ($content -match '\* Version:\s*([\d.]+)') {
        return $Matches[1]
    }

    throw 'Kunde inte läsa versionsnummer från maca-backup.php'
}

function Set-PluginVersion {
    param(
        [string] $Path,
        [string] $Version,
        [string] $ReadmePath = ''
    )

    if ($Version -notmatch '^\d+\.\d+\.\d+$') {
        throw "Ogiltigt versionsformat: $Version (förväntat x.y.z)"
    }

    $content = Get-Content -LiteralPath $Path -Raw -Encoding UTF8
    $updated = $content -replace '(?m)(\* Version:\s*)[\d.]+', "`${1}$Version"
    $updated = $updated -replace "define\s*\(\s*'MACA_BACKUP_PRO_VERSION'\s*,\s*'[\d.]+'\s*\)", "define( 'MACA_BACKUP_PRO_VERSION', '$Version' )"

    if ($updated -eq $content) {
        throw 'Kunde inte uppdatera versionsnummer i maca-backup.php'
    }

    [System.IO.File]::WriteAllText($Path, $updated, [System.Text.UTF8Encoding]::new($false))

    if ($ReadmePath -and (Test-Path -LiteralPath $ReadmePath)) {
        $readmeContent = Get-Content -LiteralPath $ReadmePath -Raw -Encoding UTF8
        $readmeUpdated = $readmeContent -replace '(?m)^(Stable tag:\s*)[\d.]+', "`${1}$Version"
        if ($readmeUpdated -ne $readmeContent) {
            [System.IO.File]::WriteAllText($ReadmePath, $readmeUpdated, [System.Text.UTF8Encoding]::new($false))
        }
    }
}

function Get-NextPatchVersion {
    param([string] $Version)

    $parts = $Version -split '\.'
    if ($parts.Count -lt 2) {
        throw "Ogiltigt versionsformat: $Version (förväntat minst x.y)"
    }

    while ($parts.Count -lt 3) {
        $parts += '0'
    }

    $major = [int]$parts[0]
    $minor = [int]$parts[1]
    $patch = [int]$parts[2] + 1

    return "$major.$minor.$patch"
}

$currentVersion = Get-PluginVersion -Path $PluginFile
$buildVersion   = if ($SkipVersionBump) { $currentVersion } else { Get-NextPatchVersion -Version $currentVersion }

if (-not $SkipVersionBump -and $buildVersion -ne $currentVersion) {
    Set-PluginVersion -Path $PluginFile -Version $buildVersion -ReadmePath $ReadmeFile
    Write-Host "Version: $currentVersion -> $buildVersion" -ForegroundColor Cyan
}
else {
    Write-Host "Version: $buildVersion (oförändrad)" -ForegroundColor Cyan
}

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $Root "$ZipBasename-$buildVersion.zip"
}

$ExcludeDirs = @(
    '.git',
    '.cursor',
    '.idea',
    '.vscode',
    'node_modules',
    'deploy',
    'dist',
    'website',
    'logs',
    '.wordpress-org',
    'agent-transcripts',
    'tests',
    'bin'
)

$ExcludeFiles = @(
    '.gitignore',
    '.gitattributes',
    '.editorconfig',
    'create-zip.ps1',
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
    'phpunit.xml',
    'phpunit.xml.dist',
    'phpcs.xml',
    'phpcs.xml.dist',
    'README.md',
    'TERMS.md',
    'PRIVACY.md',
    'Thumbs.db',
    '.DS_Store'
)

function Test-ExcludedPath {
    param(
        [string] $RelativePath
    )

    $normalized = $RelativePath -replace '\\', '/'
    $segments   = $normalized -split '/'

    foreach ($segment in $segments) {
        if ($ExcludeDirs -contains $segment) {
            return $true
        }
    }

    $fileName = Split-Path -Leaf $RelativePath
    if ($ExcludeFiles -contains $fileName) {
        return $true
    }

    if ($fileName -like '*.zip') {
        return $true
    }

    return $false
}

function Remove-PhpBomInTree {
    param([string] $SourceRoot)

    $removed = 0
    Get-ChildItem -Path $SourceRoot -Recurse -Filter '*.php' -File | ForEach-Object {
        $relative = $_.FullName.Substring($SourceRoot.Length).TrimStart('\', '/')
        if (Test-ExcludedPath -RelativePath $relative) {
            return
        }

        $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
        if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
            [System.IO.File]::WriteAllBytes($_.FullName, $bytes[3..($bytes.Length - 1)])
            $removed++
            Write-Host "BOM borttagen: $($_.FullName)" -ForegroundColor Yellow
        }
    }

    if ($removed -gt 0) {
        Write-Host "Rensade UTF-8 BOM från $removed PHP-fil(er)." -ForegroundColor Yellow
    }
}

function Get-ZipEntryName {
    param(
        [string] $PluginSlug,
        [string] $RelativePath
    )

    $relative = $RelativePath -replace '\\', '/'
    $relative = $relative.TrimStart('/')
    return "$PluginSlug/$relative"
}

function Set-MigrateUiInPluginSource {
    param(
        [string] $PhpSource,
        [bool] $Enabled
    )

    $literal = if ($Enabled) { 'true' } else { 'false' }
    $pattern = "define\s*\(\s*'MACA_BACKUP_PRO_MIGRATE_UI'\s*,\s*(?:true|false)\s*\)"
    $replacement = "define( 'MACA_BACKUP_PRO_MIGRATE_UI', $literal )"
    $updated = [regex]::Replace($PhpSource, $pattern, $replacement, 1)
    if ($updated -eq $PhpSource -and $PhpSource -notmatch $pattern) {
        throw 'Kunde inte sätta MACA_BACKUP_PRO_MIGRATE_UI i maca-backup.php för zip'
    }
    return $updated
}

function Add-FolderToZip {
    param(
        [System.IO.Compression.ZipArchive] $Archive,
        [string] $SourceFolder,
        [string] $PluginSlug,
        [bool] $ForceMigrateUiOff = $false
    )

    $files = Get-ChildItem -Path $SourceFolder -Recurse -File -Force

    foreach ($file in $files) {
        $relative = $file.FullName.Substring($SourceFolder.Length).TrimStart('\', '/')

        if (Test-ExcludedPath -RelativePath $relative) {
            continue
        }

        $entryName = Get-ZipEntryName -PluginSlug $PluginSlug -RelativePath $relative
        $entry     = $Archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)

        $entryStream = $entry.Open()
        try {
            # wp.org packages: hide Migrate even if the working tree has it enabled for local testing.
            if ($ForceMigrateUiOff -and ($relative -replace '\\', '/') -eq 'maca-backup.php') {
                $php = Get-Content -LiteralPath $file.FullName -Raw -Encoding UTF8
                $php = Set-MigrateUiInPluginSource -PhpSource $php -Enabled $false
                $bytes = [System.Text.UTF8Encoding]::new($false).GetBytes($php)
                $entryStream.Write($bytes, 0, $bytes.Length)
            }
            else {
                $fileStream = [System.IO.File]::OpenRead($file.FullName)
                try {
                    $fileStream.CopyTo($entryStream)
                }
                finally {
                    $fileStream.Dispose()
                }
            }
        }
        finally {
            $entryStream.Dispose()
        }
    }
}

$OutputDir = Split-Path -Parent $OutputPath
if (-not [string]::IsNullOrWhiteSpace($OutputDir) -and -not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

if (Test-Path $OutputPath) {
    Remove-Item -LiteralPath $OutputPath -Force
}

function Add-FileToZip {
    param(
        [System.IO.Compression.ZipArchive] $Archive,
        [string] $SourceFile,
        [string] $EntryName
    )

    $entry = $Archive.CreateEntry($EntryName, [System.IO.Compression.CompressionLevel]::Optimal)
    $entryStream = $entry.Open()
    try {
        $fileStream = [System.IO.File]::OpenRead($SourceFile)
        try {
            $fileStream.CopyTo($entryStream)
        }
        finally {
            $fileStream.Dispose()
        }
    }
    finally {
        $entryStream.Dispose()
    }
}

# Private updater is opt-in (forbidden on wordpress.org / Plugin Check).
$withUpdater = $IncludeMacaUpdater -and -not $WordPressOrg
# Default / wordpress.org zip: always ship Migrate hidden; leave working-tree constant alone.
$forceMigrateUiOff = -not $withUpdater

$zip = [System.IO.Compression.ZipFile]::Open($OutputPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Remove-PhpBomInTree -SourceRoot $Root
    Add-FolderToZip -Archive $zip -SourceFolder $Root -PluginSlug $PluginSlug -ForceMigrateUiOff $forceMigrateUiOff

    if ( $withUpdater ) {
        $updaterDir = Join-Path $Root 'deploy\updater'
        $updaterFiles = @(
            @{ Src = 'class-maca-plugin-updater.php.inc'; Dest = 'class-maca-plugin-updater.php' },
            @{ Src = 'class-plugin-updater.php.inc'; Dest = 'class-plugin-updater.php' }
        )
        foreach ($pair in $updaterFiles) {
            $src = Join-Path $updaterDir $pair.Src
            if (Test-Path -LiteralPath $src) {
                $entry = Get-ZipEntryName -PluginSlug $PluginSlug -RelativePath ("includes/" + $pair.Dest)
                Add-FileToZip -Archive $zip -SourceFile $src -EntryName $entry
                Write-Host "Inkluderade updater: includes/$($pair.Dest)" -ForegroundColor DarkCyan
            }
        }
    }
    else {
        Write-Host "Updater utelämnad (Plugin Check / wordpress.org-säker). Använd -IncludeMacaUpdater för maca.se." -ForegroundColor Yellow
    }

    if ($forceMigrateUiOff) {
        Write-Host "Migrate-UI satt till false i zip (wordpress.org-säker); working tree oförändrad." -ForegroundColor Yellow
    }
}
finally {
    $zip.Dispose()
}

$verifyZip = [System.IO.Compression.ZipFile]::OpenRead($OutputPath)
try {
    $badEntries = @($verifyZip.Entries | Where-Object { $_.FullName -match '\\' })
    if ($badEntries.Count -gt 0) {
        throw "Zip innehåller fortfarande backslash i sökvägar: $($badEntries.FullName -join ', ')"
    }

    $wrongRoot = @($verifyZip.Entries | Where-Object {
        $_.FullName -notmatch "^$([regex]::Escape($PluginSlug))/"
    })
    if ($wrongRoot.Count -gt 0) {
        throw "Zip-root ska vara $PluginSlug/ men hittade: $($wrongRoot[0].FullName)"
    }

    $mainEntry = "$PluginSlug/maca-backup.php"
    $hasMain = @($verifyZip.Entries | Where-Object { $_.FullName -eq $mainEntry })
    if ($hasMain.Count -eq 0) {
        throw "Zip saknar $mainEntry"
    }

    if ($forceMigrateUiOff) {
        $mainStream = $hasMain[0].Open()
        try {
            $reader = New-Object System.IO.StreamReader($mainStream, [System.Text.UTF8Encoding]::new($false), $true)
            $mainPhp = $reader.ReadToEnd()
            $reader.Dispose()
            if ($mainPhp -notmatch "define\s*\(\s*'MACA_BACKUP_PRO_MIGRATE_UI'\s*,\s*false\s*\)") {
                throw "wordpress.org-zip måste ha MACA_BACKUP_PRO_MIGRATE_UI false i $mainEntry"
            }
        }
        finally {
            $mainStream.Dispose()
        }
    }

    $entryCount = $verifyZip.Entries.Count

    foreach ($entry in $verifyZip.Entries) {
        if ($entry.FullName -notlike '*.php') {
            continue
        }

        $stream = $entry.Open()
        try {
            $buf = New-Object byte[] 3
            $read = $stream.Read($buf, 0, 3)
            if ($read -eq 3 -and $buf[0] -eq 0xEF -and $buf[1] -eq 0xBB -and $buf[2] -eq 0xBF) {
                throw "Zip innehåller UTF-8 BOM i $($entry.FullName)"
            }
        }
        finally {
            $stream.Dispose()
        }
    }
}
finally {
    $verifyZip.Dispose()
}

# Working tree must still allow local Migrate testing when zip forced false.
$treePhp = Get-Content -LiteralPath $PluginFile -Raw -Encoding UTF8
if ($forceMigrateUiOff -and $treePhp -match "define\s*\(\s*'MACA_BACKUP_PRO_MIGRATE_UI'\s*,\s*true\s*\)") {
    Write-Host "Working tree: MIGRATE_UI=true (lokalt test OK)." -ForegroundColor DarkGray
}

$sizeKb = [math]::Round((Get-Item -LiteralPath $OutputPath).Length / 1KB, 1)
Write-Host "Klar: $OutputPath ($entryCount filer, ${sizeKb} KB)" -ForegroundColor Green
Write-Host "Zip-mapp: $PluginSlug/  |  Version i zip: $buildVersion" -ForegroundColor DarkGray
