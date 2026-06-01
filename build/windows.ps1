param(
    [ValidateSet('Embedded', 'Expanded')]
    [string] $Mode = 'Embedded',

    [string] $PhpVersion = '8.5.5',

    [string] $VsToolset = 'vs17',

    [string] $Triplet = 'x64-windows',

    [string] $VsDevCmd = '',

    [switch] $SkipComposer,

    [switch] $SkipNpm,

    [switch] $SkipFrontend,

    [switch] $NoZip
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# 检查 Windows 长路径支持
$regResult = $null
$regResult = Get-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Control\FileSystem' -Name LongPathsEnabled -ErrorAction Ignore
if ($null -eq $regResult -or $regResult.LongPathsEnabled -ne 1) {
    Write-Warning 'Windows LongPathsEnabled is not set. OPcache paths may cause deletion failures.'
    Write-Warning 'Run as admin: reg add "HKLM\SYSTEM\CurrentControlSet\Control\FileSystem" /v LongPathsEnabled /t REG_DWORD /d 1 /f'
}

$RepoRoot = Split-Path -Parent $PSScriptRoot
$BuildRoot = Join-Path $RepoRoot 'build\windows'
$OutputRoot = Join-Path $RepoRoot 'build\output'
$PhpZip = Join-Path $BuildRoot 'php.zip'
$PhpDevelZip = Join-Path $BuildRoot 'php-devel.zip'
$PhpBin = Join-Path $BuildRoot 'php'
$PhpDevelRoot = Join-Path $BuildRoot 'php-devel'
$PhpDevel = Join-Path $PhpDevelRoot "php-$PhpVersion-devel-$VsToolset-x64"
$ComposerPhar = Join-Path $BuildRoot 'composer.phar'
$VcpkgRoot = Join-Path $BuildRoot 'vcpkg'
$VcpkgManifest = Join-Path $BuildRoot 'vcpkg-manifest'
$VcpkgInstalled = Join-Path $VcpkgManifest "vcpkg_installed\$Triplet"
$AppStaging = Join-Path $BuildRoot 'app-staging'
$FrankenphpEmbedded = Join-Path $BuildRoot 'frankenphp-embedded'
$PackageName = if ($Mode -eq 'Embedded') { 'helmdesk-win-x64-embedded' } else { 'helmdesk-win-x64' }
$PackageDir = Join-Path $OutputRoot $PackageName
$PackageZip = Join-Path $OutputRoot "$PackageName.zip"

function Write-Step([string] $Message) {
    Write-Host ''
    Write-Host "==> $Message"
}

function Invoke-Checked([string] $FilePath, [string[]] $Arguments, [string] $WorkingDirectory = $RepoRoot) {
    Write-Host "> $FilePath $($Arguments -join ' ')"
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code $LASTEXITCODE`: $FilePath"
    }
}

function Remove-TreeSafely([string] $Path, [string] $AllowedRoot) {
    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    $resolved = (Resolve-Path -LiteralPath $Path).Path
    $allowed = (Resolve-Path -LiteralPath $AllowedRoot).Path
    if (-not $resolved.StartsWith($allowed, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to remove outside allowed root: $resolved"
    }

    if (Test-Path -LiteralPath $resolved -PathType Container) {
        Get-ChildItem -LiteralPath $resolved -Recurse -Force -ErrorAction Ignore | ForEach-Object {
            $_.Attributes = [System.IO.FileAttributes]::Normal
        }
        $longPath = if ($resolved.StartsWith('\\?\')) { $resolved } else { '\\?\' + $resolved }
        [System.IO.Directory]::Delete($longPath, $true)
    } else {
        $item = Get-Item -LiteralPath $resolved -Force
        $item.Attributes = [System.IO.FileAttributes]::Normal
        Remove-Item -LiteralPath $resolved -Force
    }
}

function Find-VsDevCmd {
    if ($VsDevCmd -ne '') {
        if (-not (Test-Path -LiteralPath $VsDevCmd)) {
            throw "VsDevCmd not found: $VsDevCmd"
        }
        return (Resolve-Path -LiteralPath $VsDevCmd).Path
    }

    $vswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
    if (Test-Path -LiteralPath $vswhere) {
        $path = & $vswhere -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -find 'Common7\Tools\VsDevCmd.bat' | Select-Object -First 1
        if ($path -and (Test-Path -LiteralPath $path)) {
            return $path
        }
    }

    $candidates = @(
        "$env:ProgramFiles\Microsoft Visual Studio\18\Community\Common7\Tools\VsDevCmd.bat",
        "$env:ProgramFiles\Microsoft Visual Studio\18\Professional\Common7\Tools\VsDevCmd.bat",
        "$env:ProgramFiles\Microsoft Visual Studio\18\Enterprise\Common7\Tools\VsDevCmd.bat",
        "$env:ProgramFiles\Microsoft Visual Studio\18\BuildTools\Common7\Tools\VsDevCmd.bat",
        "$env:ProgramFiles\Microsoft Visual Studio\2022\Community\Common7\Tools\VsDevCmd.bat",
        "$env:ProgramFiles\Microsoft Visual Studio\2022\Professional\Common7\Tools\VsDevCmd.bat",
        "$env:ProgramFiles\Microsoft Visual Studio\2022\Enterprise\Common7\Tools\VsDevCmd.bat",
        "$env:ProgramFiles\Microsoft Visual Studio\2022\BuildTools\Common7\Tools\VsDevCmd.bat"
    )

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    throw 'Visual Studio VsDevCmd.bat was not found. Install Visual Studio C++ tools with Clang, or pass -VsDevCmd.'
}

function Import-VsEnvironment([string] $DevCmd) {
    Write-Step "Loading Visual Studio build environment"

    cmd /s /c "`"$DevCmd`" -arch=amd64 -host_arch=amd64 >nul && set" | ForEach-Object {
        $idx = $_.IndexOf('=')
        if ($idx -gt 0) {
            [Environment]::SetEnvironmentVariable($_.Substring(0, $idx), $_.Substring($idx + 1), 'Process')
        }
    }
}

function Install-Php {
    Write-Step "Preparing PHP $PhpVersion TS runtime and devel pack"
    New-Item -ItemType Directory -Force -Path $BuildRoot | Out-Null

    $phpUrl = "https://windows.php.net/downloads/releases/php-$PhpVersion-Win32-$VsToolset-x64.zip"
    $phpDevelUrl = "https://windows.php.net/downloads/releases/php-devel-pack-$PhpVersion-Win32-$VsToolset-x64.zip"

    if (-not (Test-Path -LiteralPath $PhpZip)) {
        Invoke-WebRequest -Uri $phpUrl -OutFile $PhpZip
    }

    if (-not (Test-Path -LiteralPath $PhpDevelZip)) {
        Invoke-WebRequest -Uri $phpDevelUrl -OutFile $PhpDevelZip
    }

    if (-not (Test-Path -LiteralPath $PhpBin)) {
        Expand-Archive -LiteralPath $PhpZip -DestinationPath $PhpBin -Force
    }

    if (-not (Test-Path -LiteralPath $PhpDevel)) {
        New-Item -ItemType Directory -Force -Path $PhpDevelRoot | Out-Null
        Expand-Archive -LiteralPath $PhpDevelZip -DestinationPath $PhpDevelRoot -Force
    }

    $phpIni = Join-Path $PhpBin 'php.ini'
    if (-not (Test-Path -LiteralPath $phpIni)) {
        Copy-Item -LiteralPath (Join-Path $PhpBin 'php.ini-production') -Destination $phpIni -Force
    }

    $ini = Get-Content -LiteralPath $phpIni -Raw
    if ($ini -match '(?m)^;?extension_dir\s*=') {
        $ini = $ini -replace '(?m)^;?extension_dir\s*=.*$', 'extension_dir = "ext"'
    } else {
        $ini += "`nextension_dir = `"ext`"`n"
    }

    $extensions = @('curl', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'zip')
    foreach ($extension in $extensions) {
        $ini = $ini -replace "(?m)^;extension=$extension\s*$", "extension=$extension"
    }
    Set-Content -LiteralPath $phpIni -Value $ini -Encoding ascii

    if (-not (Test-Path -LiteralPath $ComposerPhar)) {
        Invoke-WebRequest -Uri 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $ComposerPhar
    }
}

function Install-Vcpkg {
    Write-Step 'Preparing vcpkg dependencies'

    if (-not (Test-Path -LiteralPath $VcpkgRoot)) {
        Invoke-Checked 'git' @('clone', '--depth', '1', 'https://github.com/microsoft/vcpkg.git', $VcpkgRoot)
    }

    $vcpkgExe = Join-Path $VcpkgRoot 'vcpkg.exe'
    if (-not (Test-Path -LiteralPath $vcpkgExe)) {
        Invoke-Checked 'cmd' @('/c', (Join-Path $VcpkgRoot 'bootstrap-vcpkg.bat'), '-disableMetrics') $VcpkgRoot
    }

    New-Item -ItemType Directory -Force -Path $VcpkgManifest | Out-Null
    $manifest = @'
{
  "dependencies": [
    "brotli",
    "pthreads"
  ]
}
'@
    Set-Content -LiteralPath (Join-Path $VcpkgManifest 'vcpkg.json') -Value $manifest -Encoding ascii
    Invoke-Checked $vcpkgExe @('install', "--triplet=$Triplet", "--x-manifest-root=$VcpkgManifest")
}

function Set-PhpRuntimeEnvironment {
    Write-Step 'Configuring PHP runtime environment'

    $env:PATH = "$PhpBin;$env:PATH"
    $env:PHPRC = $PhpBin
}

function Build-FrontendAndVendor {
    if (-not $SkipComposer) {
        Write-Step 'Installing production Composer dependencies'
        Invoke-Checked (Join-Path $PhpBin 'php.exe') @($ComposerPhar, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction')
    }

    if (-not $SkipNpm) {
        Write-Step 'Installing npm dependencies'
        Invoke-Checked 'npm' @('install')
    }

    if (-not $SkipFrontend) {
        Write-Step 'Generating TypeScript routes and building frontend'
        Invoke-Checked (Join-Path $PhpBin 'php.exe') @('artisan', 'typescript:transform', '--force', '--no-interaction')
        Invoke-Checked 'npm' @('run', 'build')
    }
}

function Copy-AppToStaging {
    Write-Step 'Preparing Laravel app staging directory'

    Remove-TreeSafely $AppStaging $BuildRoot
    New-Item -ItemType Directory -Force -Path $AppStaging | Out-Null

    $dirs = @('app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor')
    $files = @('artisan', 'composer.json', 'composer.lock')

    foreach ($dir in $dirs) {
        $source = Join-Path $RepoRoot $dir
        if (-not (Test-Path -LiteralPath $source)) {
            throw "Required app directory missing: $dir"
        }
        Copy-Item -LiteralPath $source -Destination $AppStaging -Recurse -Force
    }

    foreach ($file in $files) {
        $source = Join-Path $RepoRoot $file
        if (-not (Test-Path -LiteralPath $source)) {
            throw "Required app file missing: $file"
        }
        Copy-Item -LiteralPath $source -Destination $AppStaging -Force
    }

    foreach ($relative in @(
        '.env',
        'public\hot',
        'public\storage',
        'bootstrap\cache\config.php',
        'bootstrap\cache\events.php',
        'bootstrap\cache\routes.php',
        'bootstrap\cache\routes-v7.php',
        'storage',
        'database\*.sqlite',
        'database\*.db'
    )) {
        $targets = Get-ChildItem -Path (Join-Path $AppStaging $relative) -Force -ErrorAction SilentlyContinue
        foreach ($target in $targets) {
            Remove-TreeSafely $target.FullName $AppStaging
        }
    }
}

function Initialize-EmbeddedFrankenphp {
    Write-Step 'Preparing embedded FrankenPHP module'

    $env:GOFLAGS = '-mod=mod'
    $frankenphpDir = (& go list -mod=mod -m -f '{{.Dir}}' github.com/dunglas/frankenphp).Trim()
    if (-not (Test-Path -LiteralPath $frankenphpDir)) {
        Invoke-Checked 'go' @('mod', 'download', 'github.com/dunglas/frankenphp')
        $frankenphpDir = (& go list -mod=mod -m -f '{{.Dir}}' github.com/dunglas/frankenphp).Trim()
    }

    Remove-TreeSafely $FrankenphpEmbedded $BuildRoot
    Copy-Item -LiteralPath $frankenphpDir -Destination $FrankenphpEmbedded -Recurse -Force

    $appTar = Join-Path $FrankenphpEmbedded 'app.tar'
    $checksum = Join-Path $FrankenphpEmbedded 'app_checksum.txt'
    if (Test-Path -LiteralPath $appTar) {
        Remove-Item -LiteralPath $appTar -Force
    }
    if (Test-Path -LiteralPath $checksum) {
        Remove-Item -LiteralPath $checksum -Force
    }

    $entries = @('app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor', 'artisan', 'composer.json', 'composer.lock')
    Invoke-Checked 'tar' (@('-cf', $appTar, '-C', $AppStaging) + $entries)

    $hash = (Get-FileHash -LiteralPath $appTar -Algorithm SHA256).Hash.ToLowerInvariant()
    Set-Content -LiteralPath $checksum -Value $hash -NoNewline -Encoding ascii

    $modfile = Join-Path $BuildRoot 'helmdesk-windows.mod'
    Copy-Item -LiteralPath (Join-Path $RepoRoot 'go.mod') -Destination $modfile -Force
    Add-Content -LiteralPath $modfile -Value "`nreplace github.com/dunglas/frankenphp => ./build/windows/frankenphp-embedded" -Encoding ascii

    return $modfile
}

function Set-BuildEnvironment {
    Write-Step 'Configuring Go CGO environment'

    $llvmBin = Join-Path $env:VCToolsInstallDir 'Llvm\x64\bin'
    if (-not (Test-Path -LiteralPath $llvmBin)) {
        $llvmBin = "$env:ProgramFiles\Microsoft Visual Studio\18\Community\VC\Tools\Llvm\x64\bin"
    }
    if (-not (Test-Path -LiteralPath $llvmBin)) {
        throw 'Visual Studio LLVM x64 bin directory was not found.'
    }

    $env:PATH = "$llvmBin;$(Join-Path $VcpkgInstalled 'bin');$PhpBin;$env:PATH"
    $env:PHPRC = $PhpBin
    $env:GOFLAGS = '-mod=mod'
    $env:GONOSUMCHECK = '*'
    $env:GONOSUMDB = '*'
    $env:CGO_ENABLED = '1'
    $env:CC = 'clang'
    $env:CXX = 'clang++'
    $env:CGO_CFLAGS = "-I$(Join-Path $VcpkgInstalled 'include') -I$PhpDevel\include -I$PhpDevel\include\main -I$PhpDevel\include\TSRM -I$PhpDevel\include\Zend -I$PhpDevel\include\ext -I$PhpDevel\include\win32"
    $env:CGO_LDFLAGS = "-L$(Join-Path $VcpkgInstalled 'lib') -lbrotlienc -L$PhpBin -L$PhpBin\dev -L$PhpDevel\lib -lphp8ts -lphp8embed"
}

function Build-GoBinary([string] $Modfile) {
    Write-Step "Building HelmDesk Windows binary ($Mode)"

    Remove-TreeSafely $PackageDir $OutputRoot
    New-Item -ItemType Directory -Force -Path $PackageDir | Out-Null

    $outputExe = Join-Path $PackageDir 'helmdesk.exe'
    $args = @()
    if ($Mode -eq 'Embedded') {
        $args += "-modfile=$Modfile"
    }
    $args += @(
        '-tags', 'nowatcher,nobadger,nomysql,nopgx',
        '-ldflags', '-s -w -extldflags=-fuse-ld=lld',
        '-o', $outputExe,
        '.\cmd\helmdesk'
    )

    Invoke-Checked 'go' (@('build') + $args)
}

function Copy-Runtime {
    Write-Step 'Copying Windows runtime files'

    if ($Mode -eq 'Expanded') {
        Get-ChildItem -LiteralPath $AppStaging -Force | ForEach-Object {
            Copy-Item -LiteralPath $_.FullName -Destination $PackageDir -Recurse -Force
        }
    }

    foreach ($dir in @('ext', 'extras')) {
        $source = Join-Path $PhpBin $dir
        if (Test-Path -LiteralPath $source) {
            Copy-Item -LiteralPath $source -Destination $PackageDir -Recurse -Force
        }
    }

    foreach ($dll in Get-ChildItem -LiteralPath $PhpBin -Filter '*.dll' -Force) {
        if ($dll.Name -in @('php8apache2_4.dll', 'php8phpdbg.dll')) {
            continue
        }
        Copy-Item -LiteralPath $dll.FullName -Destination $PackageDir -Force
    }

    foreach ($file in @('php.ini', 'license.txt', 'readme-redist-bins.txt')) {
        $source = Join-Path $PhpBin $file
        if (Test-Path -LiteralPath $source) {
            Copy-Item -LiteralPath $source -Destination $PackageDir -Force
        }
    }

    foreach ($dll in Get-ChildItem -LiteralPath (Join-Path $VcpkgInstalled 'bin') -Filter '*.dll' -Force) {
        Copy-Item -LiteralPath $dll.FullName -Destination $PackageDir -Force
    }

    foreach ($file in @('LICENSE', 'NOTICE', 'readme.md')) {
        $source = Join-Path $RepoRoot $file
        if (Test-Path -LiteralPath $source) {
            Copy-Item -LiteralPath $source -Destination $PackageDir -Force
        }
    }
}

function Remove-PackageRuntimeState {
    Write-Step 'Cleaning package runtime state'

    foreach ($relative in @(
        '.env',
        'storage'
    )) {
        Remove-TreeSafely (Join-Path $PackageDir $relative) $PackageDir
    }

    if ($Mode -eq 'Expanded') {
        foreach ($relative in @(
            'database\database.sqlite',
            'database\cache.sqlite',
            'database\queue.sqlite',
            'database\mercure.sqlite'
        )) {
            Remove-TreeSafely (Join-Path $PackageDir $relative) $PackageDir
        }
    }
}

function Compress-Package {
    if ($NoZip) {
        return
    }

    Write-Step 'Creating zip package'
    if (Test-Path -LiteralPath $PackageZip) {
        Remove-Item -LiteralPath $PackageZip -Force
    }
    Compress-Archive -Path $PackageDir -DestinationPath $PackageZip -CompressionLevel Optimal -Force
}

function Show-Summary {
    Write-Step 'Windows package complete'
    $exe = Get-Item -LiteralPath (Join-Path $PackageDir 'helmdesk.exe')
    Write-Host "Mode:        $Mode"
    Write-Host "Directory:   $PackageDir"
    Write-Host ('Exe size:    {0} MB' -f ([math]::Round($exe.Length / 1MB, 2)))

    if (-not $NoZip -and (Test-Path -LiteralPath $PackageZip)) {
        $zip = Get-Item -LiteralPath $PackageZip
        Write-Host "Zip:         $PackageZip"
        Write-Host ('Zip size:    {0} MB' -f ([math]::Round($zip.Length / 1MB, 2)))
    }

    Write-Host ''
    Write-Host 'Run:'
    Write-Host "  cd `"$PackageDir`""
    Write-Host '  .\helmdesk.exe -port=8080'
}

Push-Location $RepoRoot
try {
    New-Item -ItemType Directory -Force -Path $BuildRoot | Out-Null
    New-Item -ItemType Directory -Force -Path $OutputRoot | Out-Null

    Import-VsEnvironment (Find-VsDevCmd)
    Install-Php
    Set-PhpRuntimeEnvironment
    Install-Vcpkg
    Build-FrontendAndVendor
    Copy-AppToStaging

    $modfile = ''
    if ($Mode -eq 'Embedded') {
        $modfile = Initialize-EmbeddedFrankenphp
    }

    Set-BuildEnvironment
    Build-GoBinary $modfile
    Copy-Runtime
    Remove-PackageRuntimeState
    Compress-Package
    Show-Summary
} finally {
    Pop-Location
}
