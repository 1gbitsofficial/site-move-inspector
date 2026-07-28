param(
	[string] $Version = '1.0.0'
)

$ErrorActionPreference = 'Stop'

$slug = '1gbits-site-move-inspector'
$projectRoot = [System.IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$buildRoot = [System.IO.Path]::GetFullPath((Join-Path $projectRoot 'build\release'))
$stagePlugin = [System.IO.Path]::GetFullPath((Join-Path $buildRoot $slug))
$distRoot = [System.IO.Path]::GetFullPath((Join-Path $projectRoot 'dist'))
$zipPath = [System.IO.Path]::GetFullPath((Join-Path $distRoot "$slug-$Version.zip"))
$rootPrefix = $projectRoot.TrimEnd('\') + '\'

foreach ($target in @($buildRoot, $stagePlugin, $distRoot, $zipPath)) {
	if (-not $target.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
		throw "Release target is outside the plugin project: $target"
	}
}

$mainFile = Join-Path $projectRoot "$slug.php"
$readmeFile = Join-Path $projectRoot 'readme.txt'

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
	'LICENSE.txt',
	'composer.json',
	'assets',
	'includes'
)

foreach ($item in $releaseItems) {
	$source = Join-Path $projectRoot $item
	if (-not (Test-Path -LiteralPath $source)) {
		throw "Missing release item: $item"
	}
	Copy-Item -LiteralPath $source -Destination $stagePlugin -Recurse -Force
}

if (Test-Path -LiteralPath $zipPath) {
	Remove-Item -LiteralPath $zipPath -Force
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

[pscustomobject]@{
	Version = $Version
	Path = $archive.FullName
	Bytes = $archive.Length
	SHA256 = $hash.Hash.ToLowerInvariant()
}
