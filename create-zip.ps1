#Requires -Version 5.1
<#
.SYNOPSIS
  Bygger en WordPress-kompatibel plugin-zip (forward slashes, inte backslash).

.DESCRIPTION
  - Zip skapas i repots root: maca-backup-x.y.z.zip
  - create-zip.ps1 ligger i repots root
  - Höjer patch-version (x.y.z -> x.y.(z+1)) vid varje körning (om inte -SkipVersionBump)
  - -WordPressOrg: slug-mapp maca-backup (wp.org / Plugin Check). Annars maca-backup-pro.
  - Uppdaterar Version i maca-backup-pro.php före paketering

.EXAMPLE
  .\create-zip.ps1 -WordPressOrg -SkipVersionBump
  .\create-zip.ps1
  .\create-zip.ps1 -SkipVersionBump
  .\create-zip.ps1 -OutputPath .\maca-backup-1.0.1.zip
#>
[CmdletBinding()]
param(
    [string] $OutputPath = '',
    [string] $PluginSlug = '',
    [string] $ZipBasename = 'maca-backup',
    [switch] $SkipVersionBump,
    # Default zip is wordpress.org–safe (no private updater). Use -IncludeMacaUpdater for maca.se builds.
    [switch] $IncludeMacaUpdater,
    [switch] $WordPressOrg
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($PluginSlug)) {
    $PluginSlug = if ($WordPressOrg) { 'maca-backup' } else { 'maca-backup-pro' }
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Root)) {
    $Root = Get-Location
}

$PluginFile = Join-Path $Root 'maca-backup-pro.php'
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

    throw 'Kunde inte läsa versionsnummer från maca-backup-pro.php'
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
        throw 'Kunde inte uppdatera versionsnummer i maca-backup-pro.php'
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

function Add-FolderToZip {
    param(
        [System.IO.Compression.ZipArchive] $Archive,
        [string] $SourceFolder,
        [string] $PluginSlug
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
            $fileStream = [System.IO.File]::OpenRead($file.FullName)
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

$zip = [System.IO.Compression.ZipFile]::Open($OutputPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Remove-PhpBomInTree -SourceRoot $Root
    Add-FolderToZip -Archive $zip -SourceFolder $Root -PluginSlug $PluginSlug

    # Private updater is opt-in (forbidden on wordpress.org / Plugin Check).
    $withUpdater = $IncludeMacaUpdater -and -not $WordPressOrg
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

    $mainEntry = "$PluginSlug/maca-backup-pro.php"
    $hasMain = @($verifyZip.Entries | Where-Object { $_.FullName -eq $mainEntry })
    if ($hasMain.Count -eq 0) {
        throw "Zip saknar $mainEntry"
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

$sizeKb = [math]::Round((Get-Item -LiteralPath $OutputPath).Length / 1KB, 1)
Write-Host "Klar: $OutputPath ($entryCount filer, ${sizeKb} KB)" -ForegroundColor Green
Write-Host "Zip-mapp: $PluginSlug/  |  Version i zip: $buildVersion" -ForegroundColor DarkGray
