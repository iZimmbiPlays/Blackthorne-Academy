param(
    [string]$Target = "all"
)

$root = Split-Path -Parent $MyInvocation.MyCommand.Path

$excludedFolders = @(
    ".git",
    ".github",
    ".vscode",
    "vendor",
    "node_modules",
    "uploads",
    "cache",
    "tmp",
    "logs",
    "backups"
)

Write-Host ""
Write-Host "Blackthorne Academy PHP Beautifier"
Write-Host "=================================="
Write-Host ""

switch ($Target.ToLower()) {
    "all" {
        $searchPath = $root
        $recurse = $true
    }

    "root" {
        $searchPath = $root
        $recurse = $false
    }

    default {
        $searchPath = Join-Path $root $Target
        $recurse = $true

        if (-not (Test-Path $searchPath)) {
            Write-Host "ERROR: '$Target' does not exist in the Blackthorne project."
            Write-Host ""
            exit 1
        }
    }
}

if ($recurse) {
    $phpFiles = Get-ChildItem -Path $searchPath -Recurse -File -Filter *.php
}
else {
    $phpFiles = Get-ChildItem -Path $searchPath -File -Filter *.php
}

$phpFiles = $phpFiles | Where-Object {
    $filePath = $_.FullName

    $shouldExclude = $false

    foreach ($folder in $excludedFolders) {
        $folderPattern = [regex]::Escape(
            [System.IO.Path]::DirectorySeparatorChar + $folder + [System.IO.Path]::DirectorySeparatorChar
        )

        if ($filePath -match $folderPattern) {
            $shouldExclude = $true
            break
        }
    }

    -not $shouldExclude
}

if (-not $phpFiles -or $phpFiles.Count -eq 0) {
    Write-Host "No PHP files found for target: $Target"
    Write-Host ""
    exit 0
}

Write-Host "Target: $Target"
Write-Host "Found $($phpFiles.Count) PHP file(s)."
Write-Host ""

$formatted = 0
$failed = 0

foreach ($file in $phpFiles) {
    $relativePath = $file.FullName.Substring($root.Length).TrimStart('\', '/')

    Write-Host "Formatting: $relativePath"

    & npx html-beautify `
        "$($file.FullName)" `
        --replace `
        --templating php `
        --indent-size 4 `
        --wrap-line-length 120 `
        --wrap-attributes auto `
        --max-preserve-newlines 2 `
        --indent-inner-html `
        --end-with-newline

    if ($LASTEXITCODE -eq 0) {
        $formatted++
    }
    else {
        Write-Host "FAILED: $relativePath"
        $failed++
    }
}

Write-Host ""
Write-Host "=================================="
Write-Host "Beautification complete."
Write-Host ""
Write-Host "Formatted: $formatted"
Write-Host "Failed:    $failed"
Write-Host ""
Write-Host "Review changes with:"
Write-Host "git diff --stat"
Write-Host ""
Write-Host "Review the full diff with:"
Write-Host "git diff"
Write-Host ""