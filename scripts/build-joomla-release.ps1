param(
	[string] $Version = '1.0.1'
)

$ErrorActionPreference = 'Stop'

$component = 'com_sitemoveinspector'
$manifestName = "$component.xml"
$repoRoot = [System.IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$appRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'apps/joomla/component'))
$buildRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'build/joomla/release'))
$stageRoot = [System.IO.Path]::GetFullPath((Join-Path $buildRoot $component))
$distRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'dist/joomla'))
$zipPath = [System.IO.Path]::GetFullPath((Join-Path $distRoot "$component-$Version.zip"))
$checksumPath = [System.IO.Path]::GetFullPath((Join-Path $distRoot "$component-$Version.sha256.txt"))
$validationScript = Join-Path $PSScriptRoot 'test-joomla-package.ps1'
$repoPrefix = $repoRoot.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar

foreach ($target in @($appRoot, $buildRoot, $stageRoot, $distRoot, $zipPath, $checksumPath)) {
	if (-not $target.StartsWith($repoPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
		throw "Joomla release path is outside the repository: $target"
	}
}

if ($Version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$') {
	throw "Invalid Joomla release version: $Version"
}
if (-not (Test-Path -LiteralPath $appRoot -PathType Container)) {
	throw "Missing Joomla application directory: $appRoot"
}
if (-not (Test-Path -LiteralPath (Join-Path $appRoot $manifestName) -PathType Leaf)) {
	throw "Missing Joomla manifest: $(Join-Path $appRoot $manifestName)"
}
if (-not (Test-Path -LiteralPath $validationScript -PathType Leaf)) {
	throw "Missing Joomla package validation script: $validationScript"
}

& $validationScript -SourcePath $appRoot -Version $Version | Out-Null

$sourceItems = Get-ChildItem -LiteralPath $appRoot -Force -Recurse
$reparsePoint = $sourceItems | Where-Object {
	($_.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0
} | Select-Object -First 1
if ($null -ne $reparsePoint) {
	throw "Joomla source must not contain symlinks or reparse points: $($reparsePoint.FullName)"
}

function Test-DevelopmentOnlyPath {
	param(
		[Parameter(Mandatory = $true)]
		[string] $RelativePath
	)

	$path = $RelativePath.Replace('\', '/').TrimStart('/')
	return (
		$path -match '(^|/)(tests?|docs?|node_modules|\.github|\.git|\.idea|\.vscode)(/|$)' -or
		$path -match '(^|/)(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml(\.dist)?|phpcs\.xml(\.dist)?|\.editorconfig)$'
	)
}

if (Test-Path -LiteralPath $buildRoot) {
	Remove-Item -LiteralPath $buildRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $stageRoot -Force | Out-Null
New-Item -ItemType Directory -Path $distRoot -Force | Out-Null

foreach ($file in $sourceItems | Where-Object { -not $_.PSIsContainer }) {
	$relativePath = $file.FullName.Substring($appRoot.Length).TrimStart('\', '/')
	if (Test-DevelopmentOnlyPath -RelativePath $relativePath) {
		continue
	}

	$destination = Join-Path $stageRoot $relativePath
	$destinationDirectory = Split-Path -Parent $destination
	New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
	Copy-Item -LiteralPath $file.FullName -Destination $destination -Force
}

$licenseSource = Join-Path $repoRoot 'LICENSE.txt'
$stagedLicense = Join-Path $stageRoot 'LICENSE.txt'
if (-not (Test-Path -LiteralPath $stagedLicense -PathType Leaf)) {
	if (-not (Test-Path -LiteralPath $licenseSource -PathType Leaf)) {
		throw "Missing repository license: $licenseSource"
	}
	Copy-Item -LiteralPath $licenseSource -Destination $stagedLicense -Force
}

if (-not (Test-Path -LiteralPath (Join-Path $stageRoot $manifestName) -PathType Leaf)) {
	throw "The staged Joomla manifest is missing."
}

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
	$releaseFiles = Get-ChildItem -LiteralPath $stageRoot -Recurse -File |
		Sort-Object { $_.FullName.Substring($stageRoot.Length) }

	foreach ($file in $releaseFiles) {
		$entryName = $file.FullName.Substring($stageRoot.Length).TrimStart('\', '/').Replace('\', '/')
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

& $validationScript -SourcePath $appRoot -ZipPath $zipPath -Version $Version | Out-Null

$archive = Get-Item -LiteralPath $zipPath
$hash = Get-FileHash -LiteralPath $zipPath -Algorithm SHA256
$hashText = "{0}  {1}`n" -f $hash.Hash.ToLowerInvariant(), $archive.Name
[System.IO.File]::WriteAllText(
	$checksumPath,
	$hashText,
	[System.Text.UTF8Encoding]::new($false)
)

[pscustomobject]@{
	Platform     = 'joomla'
	Component    = $component
	Version      = $Version
	Path         = $archive.FullName
	ChecksumPath = $checksumPath
	Bytes        = $archive.Length
	SHA256       = $hash.Hash.ToLowerInvariant()
}
