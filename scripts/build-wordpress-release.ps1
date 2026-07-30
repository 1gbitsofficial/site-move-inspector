param(
	[string] $Version = '1.0.1'
)

$ErrorActionPreference = 'Stop'

$slug = '1gbits-site-move-inspector'
$repoRoot = [System.IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$appRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'apps\wordpress'))
$buildRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'build\wordpress\release'))
$stagePlugin = [System.IO.Path]::GetFullPath((Join-Path $buildRoot $slug))
$distRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'dist\wordpress'))
$zipPath = [System.IO.Path]::GetFullPath((Join-Path $distRoot "$slug-$Version.zip"))
$checksumPath = [System.IO.Path]::GetFullPath((Join-Path $distRoot "$slug-$Version.sha256.txt"))
$rootPrefix = $repoRoot.TrimEnd('\') + '\'

foreach ($target in @($appRoot, $buildRoot, $stagePlugin, $distRoot, $zipPath, $checksumPath)) {
	if (-not $target.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
		throw "WordPress release path is outside the repository: $target"
	}
}

$mainFile = Join-Path $appRoot "$slug.php"
$readmeFile = Join-Path $appRoot 'readme.txt'

if (-not (Test-Path -LiteralPath $mainFile -PathType Leaf)) {
	throw "Missing plugin bootstrap: $mainFile"
}
if (-not (Test-Path -LiteralPath $readmeFile -PathType Leaf)) {
	throw "Missing WordPress.org readme: $readmeFile"
}

$mainSource = Get-Content -Raw -LiteralPath $mainFile
$readmeSource = Get-Content -Raw -LiteralPath $readmeFile

if ($mainSource -notmatch "(?m)^\s*\*\s+Version:\s+$([regex]::Escape($Version))\s*$") {
	throw "Plugin header version does not match $Version."
}
if ($mainSource -notmatch "(?m)^define\(\s*'OGSMI_VERSION',\s*'$([regex]::Escape($Version))'\s*\);\s*$") {
	throw "OGSMI_VERSION does not match $Version."
}
if ($readmeSource -notmatch "(?m)^Stable tag:\s+$([regex]::Escape($Version))\s*$") {
	throw "readme.txt Stable tag does not match $Version."
}

if (Test-Path -LiteralPath $buildRoot) {
	Remove-Item -LiteralPath $buildRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $stagePlugin -Force | Out-Null
New-Item -ItemType Directory -Path $distRoot -Force | Out-Null

$releaseItems = @(
	"$slug.php",
	'uninstall.php',
	'readme.txt',
	'README.md',
	'composer.json',
	'assets',
	'includes'
)

foreach ($item in $releaseItems) {
	$source = Join-Path $appRoot $item
	if (-not (Test-Path -LiteralPath $source)) {
		throw "Missing release item: $item"
	}
	Copy-Item -LiteralPath $source -Destination $stagePlugin -Recurse -Force
}

$licenseSource = Join-Path $repoRoot 'LICENSE.txt'
if (-not (Test-Path -LiteralPath $licenseSource -PathType Leaf)) {
	throw "Missing repository license: $licenseSource"
}
Copy-Item -LiteralPath $licenseSource -Destination (Join-Path $stagePlugin 'LICENSE.txt') -Force

if (Test-Path -LiteralPath $zipPath) {
	Remove-Item -LiteralPath $zipPath -Force
}
if (Test-Path -LiteralPath $checksumPath) {
	Remove-Item -LiteralPath $checksumPath -Force
}

Add-Type -AssemblyName System.IO.Compression

$zipStream = [System.IO.File]::Open(
	$zipPath,
	[System.IO.FileMode]::CreateNew,
	[System.IO.FileAccess]::ReadWrite,
	[System.IO.FileShare]::None
)
$zipArchive = [System.IO.Compression.ZipArchive]::new(
	$zipStream,
	[System.IO.Compression.ZipArchiveMode]::Create,
	$false
)
$fixedTimestamp = [DateTimeOffset]::new(1980, 1, 1, 0, 0, 0, [TimeSpan]::Zero)

try {
	$releaseFiles = Get-ChildItem -LiteralPath $stagePlugin -Recurse -File |
		Sort-Object { $_.FullName.Substring($buildRoot.Length) }

	foreach ($file in $releaseFiles) {
		$entryName = $file.FullName.Substring($buildRoot.Length).TrimStart('\', '/').Replace('\', '/')
		$entry = $zipArchive.CreateEntry(
			$entryName,
			[System.IO.Compression.CompressionLevel]::Optimal
		)
		$entry.LastWriteTime = $fixedTimestamp

		$sourceStream = [System.IO.File]::OpenRead($file.FullName)
		$entryStream = $entry.Open()
		try {
			$sourceStream.CopyTo($entryStream)
		}
		finally {
			$entryStream.Dispose()
			$sourceStream.Dispose()
		}
	}
}
finally {
	$zipArchive.Dispose()
	$zipStream.Dispose()
}

$archive = Get-Item -LiteralPath $zipPath
$hash = Get-FileHash -LiteralPath $zipPath -Algorithm SHA256
$hashText = "{0}  {1}`n" -f $hash.Hash.ToLowerInvariant(), $archive.Name
[System.IO.File]::WriteAllText(
	$checksumPath,
	$hashText,
	[System.Text.UTF8Encoding]::new($false)
)

[pscustomobject]@{
	Platform     = 'wordpress'
	Version      = $Version
	Path         = $archive.FullName
	ChecksumPath = $checksumPath
	Bytes        = $archive.Length
	SHA256       = $hash.Hash.ToLowerInvariant()
}
