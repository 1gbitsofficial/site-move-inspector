param(
	[string] $SourcePath = '',
	[string] $ZipPath = '',
	[string] $Version = '1.0.1',
	[string] $PhpExecutable = 'php'
)

$ErrorActionPreference = 'Stop'

$component = 'com_sitemoveinspector'
$manifestName = "$component.xml"
$repoRoot = [System.IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$updateFeedPath = Join-Path $repoRoot 'apps/joomla/updates/site-move-inspector.xml'
$changelogPath = Join-Path $repoRoot 'apps/joomla/updates/changelog.xml'

if ([string]::IsNullOrWhiteSpace($SourcePath)) {
	$SourcePath = Join-Path $repoRoot 'apps/joomla/component'
}

$sourceRoot = [System.IO.Path]::GetFullPath($SourcePath)
$manifestPath = Join-Path $sourceRoot $manifestName
$warnings = [System.Collections.Generic.List[string]]::new()
$sourcePhpCount = 0
$archivePhpCount = 0

function Read-SafeXml {
	param(
		[Parameter(Mandatory = $true)]
		[string] $Text,
		[Parameter(Mandatory = $true)]
		[string] $DisplayName
	)

	$settings = [System.Xml.XmlReaderSettings]::new()
	$settings.DtdProcessing = [System.Xml.DtdProcessing]::Prohibit
	$settings.XmlResolver = $null

	$stringReader = [System.IO.StringReader]::new($Text)
	$xmlReader = $null
	try {
		$xmlReader = [System.Xml.XmlReader]::Create($stringReader, $settings)
		$document = [System.Xml.XmlDocument]::new()
		$document.XmlResolver = $null
		$document.Load($xmlReader)
		return ,$document
	}
	catch {
		throw "Invalid or unsafe XML in ${DisplayName}: $($_.Exception.Message)"
	}
	finally {
		if ($null -ne $xmlReader) {
			$xmlReader.Dispose()
		}
		$stringReader.Dispose()
	}
}

function Get-ManifestRelativePaths {
	param(
		[Parameter(Mandatory = $true)]
		[System.Xml.XmlDocument] $Document
	)

	$paths = [System.Collections.Generic.List[string]]::new()
	$xpath = @(
		"//*[local-name()='files']/*[local-name()='filename' or local-name()='folder']",
		"//*[local-name()='languages']/*[local-name()='language']",
		"//*[local-name()='media']/*[local-name()='filename' or local-name()='folder']",
		"//*[local-name()='sql']/*[local-name()='file']",
		"//*[local-name()='schemas']/*[local-name()='schemapath']",
		"/*[local-name()='extension']/*[local-name()='scriptfile' or local-name()='installfile' or local-name()='uninstallfile']"
	) -join ' | '

	foreach ($node in $Document.SelectNodes($xpath)) {
		$value = $node.InnerText.Trim().Replace('\', '/')
		if ([string]::IsNullOrWhiteSpace($value)) {
			throw "The Joomla manifest contains an empty file or folder entry."
		}

		$base = ''
		if ($null -ne $node.ParentNode.Attributes -and $null -ne $node.ParentNode.Attributes['folder']) {
			$base = $node.ParentNode.Attributes['folder'].Value.Trim().Replace('\', '/').Trim('/')
		}
		elseif (
			$node.LocalName -eq 'file' -or
			$node.LocalName -eq 'schemapath'
		) {
			$administratorFiles = $Document.SelectSingleNode(
				"/*[local-name()='extension']/*[local-name()='administration']/*[local-name()='files']"
			)
			if (
				$null -ne $administratorFiles -and
				$null -ne $administratorFiles.Attributes -and
				$null -ne $administratorFiles.Attributes['folder']
			) {
				$base = $administratorFiles.Attributes['folder'].Value.Trim().Replace('\', '/').Trim('/')
			}
		}

		$relativePath = if ([string]::IsNullOrWhiteSpace($base)) {
			$value.Trim('/')
		}
		else {
			"$base/$($value.Trim('/'))"
		}

		if (
			$relativePath.StartsWith('/') -or
			$relativePath.StartsWith('\') -or
			$relativePath -match '^[A-Za-z]:' -or
			$relativePath -match '(^|/)\.\.(/|$)' -or
			$relativePath.IndexOf([char]0) -ge 0
		) {
			throw "Unsafe path in Joomla manifest: $relativePath"
		}

		if (-not $paths.Contains($relativePath)) {
			$paths.Add($relativePath)
		}
	}

	return ,$paths.ToArray()
}

function Assert-Manifest {
	param(
		[Parameter(Mandatory = $true)]
		[System.Xml.XmlDocument] $Document,
		[Parameter(Mandatory = $true)]
		[string] $DisplayName
	)

	$root = $Document.DocumentElement
	if ($null -eq $root -or $root.LocalName -ne 'extension') {
		throw "$DisplayName does not have an <extension> root element."
	}
	if ($root.GetAttribute('type') -ne 'component') {
		throw "$DisplayName must declare type=`"component`"."
	}
	if ($root.GetAttribute('method') -ne 'upgrade') {
		throw "$DisplayName must declare method=`"upgrade`"."
	}

	$nameNode = $root.SelectSingleNode("./*[local-name()='name']")
	$versionNode = $root.SelectSingleNode("./*[local-name()='version']")
	$administrationNode = $root.SelectSingleNode("./*[local-name()='administration']")

	if ($null -eq $nameNode -or [string]::IsNullOrWhiteSpace($nameNode.InnerText)) {
		throw "$DisplayName is missing the extension name."
	}
	if ($nameNode.InnerText.Trim() -cne 'COM_SITEMOVEINSPECTOR') {
		throw "$DisplayName must use the COM_SITEMOVEINSPECTOR install-name language key."
	}
	if ($null -eq $versionNode -or $versionNode.InnerText.Trim() -ne $Version) {
		throw "$DisplayName version does not match $Version."
	}
	if ($null -eq $administrationNode) {
		throw "$DisplayName must describe an administrator component."
	}

	$updateServers = $root.SelectNodes("./*[local-name()='updateservers']/*[local-name()='server']")
	if ($updateServers.Count -eq 0) {
		$warnings.Add("$DisplayName has no Joomla update server.")
	}
	else {
		foreach ($server in $updateServers) {
			$uri = $null
			if (
				-not [System.Uri]::TryCreate(
					$server.InnerText.Trim(),
					[System.UriKind]::Absolute,
					[ref] $uri
				) -or
				$uri.Scheme -ne 'https'
			) {
				throw "$DisplayName contains an invalid or non-HTTPS update server."
			}
		}
	}
}

function Assert-JedDisplayName {
	param(
		[Parameter(Mandatory = $true)]
		[string] $SourcePath
	)

	$languagePath = Join-Path $SourcePath 'administrator/components/com_sitemoveinspector/language/en-GB/com_sitemoveinspector.sys.ini'
	if (-not (Test-Path -LiteralPath $languagePath -PathType Leaf)) {
		throw "Missing English installation language file: $languagePath"
	}

	$values = @{}
	$text = [System.IO.File]::ReadAllText($languagePath)
	$matches = [regex]::Matches(
		$text,
		'(?m)^\s*(COM_SITEMOVEINSPECTOR(?:_MENU)?)="([^"\r\n]*)"\s*$'
	)
	foreach ($match in $matches) {
		$values[$match.Groups[1].Value] = $match.Groups[2].Value
	}

	foreach ($key in @('COM_SITEMOVEINSPECTOR', 'COM_SITEMOVEINSPECTOR_MENU')) {
		if (-not $values.ContainsKey($key) -or $values[$key] -cne 'Site Move Inspector') {
			throw "$languagePath must define $key as 'Site Move Inspector' for JED name consistency."
		}
	}
}

function Get-RequiredXmlText {
	param(
		[Parameter(Mandatory = $true)]
		[System.Xml.XmlElement] $Parent,
		[Parameter(Mandatory = $true)]
		[string] $XPath,
		[Parameter(Mandatory = $true)]
		[string] $Label
	)

	$node = $Parent.SelectSingleNode($XPath)
	if ($null -eq $node -or [string]::IsNullOrWhiteSpace($node.InnerText)) {
		throw "Missing or empty $Label."
	}

	return $node.InnerText.Trim()
}

function Assert-UpdateFeed {
	param(
		[Parameter(Mandatory = $true)]
		[System.Xml.XmlDocument] $Document,
		[Parameter(Mandatory = $true)]
		[string] $DisplayName,
		[Parameter(Mandatory = $true)]
		[string] $Component,
		[Parameter(Mandatory = $true)]
		[string] $ReleaseVersion,
		[string] $ArchivePath = ''
	)

	$root = $Document.DocumentElement
	if ($null -eq $root -or $root.LocalName -ne 'updates') {
		throw "$DisplayName does not have an <updates> root element."
	}

	$updates = @($root.SelectNodes("./*[local-name()='update']"))
	if ($updates.Count -ne 1) {
		throw "$DisplayName must contain exactly one <update> entry."
	}

	$update = $updates[0]
	$expectedValues = @{
		'name'        = 'Site Move Inspector'
		'element'     = $Component
		'type'        = 'component'
		'client'      = 'administrator'
		'version'     = $ReleaseVersion
		'php_minimum' = '8.1.0'
		'maintainer'  = '1Gbits'
	}

	foreach ($entry in $expectedValues.GetEnumerator()) {
		$value = Get-RequiredXmlText `
			-Parent $update `
			-XPath "./*[local-name()='$($entry.Key)']" `
			-Label "$DisplayName $($entry.Key)"
		if ($value -cne $entry.Value) {
			throw "$DisplayName $($entry.Key) must be '$($entry.Value)', found '$value'."
		}
	}

	$downloadNodes = @(
		$update.SelectNodes(
			"./*[local-name()='downloads']/*[local-name()='downloadurl']"
		)
	)
	if ($downloadNodes.Count -ne 1) {
		throw "$DisplayName must contain exactly one full download URL."
	}

	$download = $downloadNodes[0]
	if ($download.GetAttribute('type') -cne 'full' -or $download.GetAttribute('format') -cne 'zip') {
		throw "$DisplayName download URL must declare type='full' and format='zip'."
	}

	$expectedDownloadUrl = "https://github.com/1gbitsofficial/site-move-inspector/releases/download/joomla-v$ReleaseVersion/$Component-$ReleaseVersion.zip"
	if ($download.InnerText.Trim() -cne $expectedDownloadUrl) {
		throw "$DisplayName download URL does not match the Joomla release artifact."
	}

	$targetNodes = @($update.SelectNodes("./*[local-name()='targetplatform']"))
	if ($targetNodes.Count -ne 1) {
		throw "$DisplayName must contain exactly one target platform."
	}

	$target = $targetNodes[0]
	$targetPattern = '(?:5\.4|6\.1)(?:\.|$)'
	if (
		$target.GetAttribute('name') -cne 'joomla' -or
		$target.GetAttribute('version') -cne $targetPattern
	) {
		throw "$DisplayName must target only Joomla 5.4.x and 6.1.x."
	}

	$anchoredPattern = '^' + $targetPattern
	foreach ($supported in @('5.4', '5.4.0', '5.4.7', '6.1', '6.1.0', '6.1.2')) {
		if (-not [regex]::IsMatch($supported, $anchoredPattern)) {
			throw "$DisplayName target pattern rejects supported Joomla version $supported."
		}
	}
	foreach ($unsupported in @('5.40', '5.5', '6.0', '6.10', '6.2', '7.0')) {
		if ([regex]::IsMatch($unsupported, $anchoredPattern)) {
			throw "$DisplayName target pattern accepts unsupported Joomla version $unsupported."
		}
	}

	$sha256 = Get-RequiredXmlText `
		-Parent $update `
		-XPath "./*[local-name()='sha256']" `
		-Label "$DisplayName sha256"
	if ($sha256 -cnotmatch '^[a-f0-9]{64}$') {
		throw "$DisplayName sha256 must be a lowercase 64-character hexadecimal digest."
	}

	if (-not [string]::IsNullOrWhiteSpace($ArchivePath)) {
		$archiveHash = (Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant()
		if ($sha256 -cne $archiveHash) {
			throw "$DisplayName sha256 does not match the release ZIP."
		}
	}
}

function Assert-Changelog {
	param(
		[Parameter(Mandatory = $true)]
		[System.Xml.XmlDocument] $Document,
		[Parameter(Mandatory = $true)]
		[string] $DisplayName,
		[Parameter(Mandatory = $true)]
		[string] $Component,
		[Parameter(Mandatory = $true)]
		[string] $ReleaseVersion
	)

	$root = $Document.DocumentElement
	if ($null -eq $root -or $root.LocalName -ne 'changelogs') {
		throw "$DisplayName does not have a <changelogs> root element."
	}

	$entries = @($root.SelectNodes("./*[local-name()='changelog']"))
	if ($entries.Count -lt 1) {
		throw "$DisplayName must contain at least one <changelog> entry."
	}

	$versions = [System.Collections.Generic.HashSet[string]]::new(
		[System.StringComparer]::Ordinal
	)
	foreach ($entry in $entries) {
		foreach ($expected in @(
			@('element', $Component),
			@('type', 'component')
		)) {
			$value = Get-RequiredXmlText `
				-Parent $entry `
				-XPath "./*[local-name()='$($expected[0])']" `
				-Label "$DisplayName $($expected[0])"
			if ($value -cne $expected[1]) {
				throw "$DisplayName $($expected[0]) must be '$($expected[1])', found '$value'."
			}
		}

		$version = Get-RequiredXmlText `
			-Parent $entry `
			-XPath "./*[local-name()='version']" `
			-Label "$DisplayName version"
		if ($version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$') {
			throw "$DisplayName contains an invalid changelog version: $version"
		}
		if (-not $versions.Add($version)) {
			throw "$DisplayName contains a duplicate changelog version: $version"
		}
	}

	$latestVersion = Get-RequiredXmlText `
		-Parent $entries[0] `
		-XPath "./*[local-name()='version']" `
		-Label "$DisplayName latest version"
	if ($latestVersion -cne $ReleaseVersion) {
		throw "$DisplayName first changelog version must be '$ReleaseVersion', found '$latestVersion'."
	}
}

function Assert-NoForbiddenPaths {
	param(
		[Parameter(Mandatory = $true)]
		[string[]] $Names,
		[switch] $Archive
	)

	$alwaysForbidden = @(
		'(^|/)\.env($|\.)',
		'(^|/)(\.git|\.svn)(/|$)',
		'\.(bak|log|pem|key|p12|pfx|sqlite|sqlite3)$',
		'(^|/)(configuration\.php|\.htpasswd|id_rsa|id_ed25519)$',
		'(^|/)Thumbs\.db$',
		'(^|/)\.DS_Store$'
	)
	$archiveOnlyForbidden = @(
		'(^|/)(tests?|docs?|node_modules|\.github|\.idea|\.vscode)(/|$)',
		'(^|/)(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml(\.dist)?|phpcs\.xml(\.dist)?)$',
		'\.(7z|gz|rar|tar|zip)$'
	)

	foreach ($nameValue in $Names) {
		$name = $nameValue.Replace('\', '/').TrimStart('/')
		foreach ($pattern in $alwaysForbidden) {
			if ($name -match $pattern) {
				throw "Forbidden file or directory: $name"
			}
		}
		if ($Archive) {
			foreach ($pattern in $archiveOnlyForbidden) {
				if ($name -match $pattern) {
					throw "Development-only file is present in the release ZIP: $name"
				}
			}
		}
	}
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

function Invoke-PhpLint {
	param(
		[Parameter(Mandatory = $true)]
		[string] $Path
	)

	$output = & $PhpExecutable '-l' $Path 2>&1
	if ($LASTEXITCODE -ne 0) {
		throw "PHP syntax check failed for ${Path}: $($output -join [Environment]::NewLine)"
	}
}

function Get-StreamSha256 {
	param(
		[Parameter(Mandatory = $true)]
		[System.IO.Stream] $Stream
	)

	$sha256 = [System.Security.Cryptography.SHA256]::Create()
	try {
		return -join (
			$sha256.ComputeHash($Stream) |
				ForEach-Object { $_.ToString('x2') }
		)
	}
	finally {
		$sha256.Dispose()
	}
}

function Assert-DeclaredSourcePaths {
	param(
		[Parameter(Mandatory = $true)]
		[string[]] $RelativePaths
	)

	foreach ($relativePath in $RelativePaths) {
		$nativePath = $relativePath.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
		$fullPath = Join-Path $sourceRoot $nativePath
		if (-not (Test-Path -LiteralPath $fullPath)) {
			throw "Manifest entry does not exist in source: $relativePath"
		}
	}
}

if (-not (Test-Path -LiteralPath $sourceRoot -PathType Container)) {
	throw "Joomla source directory does not exist: $sourceRoot"
}
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
	throw "Missing Joomla manifest: $manifestPath"
}
if (-not (Test-Path -LiteralPath $updateFeedPath -PathType Leaf)) {
	throw "Missing Joomla update feed: $updateFeedPath"
}
if (-not (Test-Path -LiteralPath $changelogPath -PathType Leaf)) {
	throw "Missing Joomla changelog: $changelogPath"
}
if ($Version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$') {
	throw "Invalid Joomla release version: $Version"
}
if ($null -eq (Get-Command $PhpExecutable -ErrorAction SilentlyContinue)) {
	throw "PHP executable was not found: $PhpExecutable"
}

$sourceItems = Get-ChildItem -LiteralPath $sourceRoot -Force -Recurse
$reparsePoint = $sourceItems | Where-Object {
	($_.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0
} | Select-Object -First 1
if ($null -ne $reparsePoint) {
	throw "Joomla source must not contain symlinks or reparse points: $($reparsePoint.FullName)"
}

$sourceRelativeNames = @(
	$sourceItems | ForEach-Object {
		$_.FullName.Substring($sourceRoot.Length).TrimStart('\', '/').Replace('\', '/')
	}
)
Assert-NoForbiddenPaths -Names $sourceRelativeNames

$sourceManifestText = [System.IO.File]::ReadAllText($manifestPath)
$sourceManifest = Read-SafeXml -Text $sourceManifestText -DisplayName $manifestPath
Assert-Manifest -Document $sourceManifest -DisplayName $manifestPath
Assert-JedDisplayName -SourcePath $sourceRoot
$declaredPaths = Get-ManifestRelativePaths -Document $sourceManifest
if ($declaredPaths.Count -eq 0) {
	throw "The Joomla manifest does not declare any installable files."
}
Assert-DeclaredSourcePaths -RelativePaths $declaredPaths

$updateFeedText = [System.IO.File]::ReadAllText($updateFeedPath)
$updateFeed = Read-SafeXml -Text $updateFeedText -DisplayName $updateFeedPath
Assert-UpdateFeed `
	-Document $updateFeed `
	-DisplayName $updateFeedPath `
	-Component $component `
	-ReleaseVersion $Version

$changelogText = [System.IO.File]::ReadAllText($changelogPath)
$changelog = Read-SafeXml -Text $changelogText -DisplayName $changelogPath
Assert-Changelog `
	-Document $changelog `
	-DisplayName $changelogPath `
	-Component $component `
	-ReleaseVersion $Version

foreach ($xmlFile in $sourceItems | Where-Object { -not $_.PSIsContainer -and $_.Extension -ieq '.xml' }) {
	$xmlText = [System.IO.File]::ReadAllText($xmlFile.FullName)
	[void] (Read-SafeXml -Text $xmlText -DisplayName $xmlFile.FullName)
}

foreach ($phpFile in $sourceItems | Where-Object { -not $_.PSIsContainer -and $_.Extension -ieq '.php' }) {
	Invoke-PhpLint -Path $phpFile.FullName
	$sourcePhpCount++
}

if (-not [string]::IsNullOrWhiteSpace($ZipPath)) {
	$archivePath = [System.IO.Path]::GetFullPath($ZipPath)
	if (-not (Test-Path -LiteralPath $archivePath -PathType Leaf)) {
		throw "Joomla release ZIP does not exist: $archivePath"
	}

	Assert-UpdateFeed `
		-Document $updateFeed `
		-DisplayName $updateFeedPath `
		-Component $component `
		-ReleaseVersion $Version `
		-ArchivePath $archivePath

	Add-Type -AssemblyName System.IO.Compression
	Add-Type -AssemblyName System.IO.Compression.FileSystem

	$zipStream = [System.IO.File]::OpenRead($archivePath)
	$zipArchive = [System.IO.Compression.ZipArchive]::new(
		$zipStream,
		[System.IO.Compression.ZipArchiveMode]::Read,
		$false
	)
	$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("smi-joomla-validation-" + [guid]::NewGuid().ToString('N'))

	try {
		$entryNames = @($zipArchive.Entries | ForEach-Object { $_.FullName })
		if ($entryNames.Count -eq 0) {
			throw "Joomla release ZIP is empty."
		}

		$normalizedNames = [System.Collections.Generic.HashSet[string]]::new(
			[System.StringComparer]::OrdinalIgnoreCase
		)
		foreach ($entry in $zipArchive.Entries) {
			$name = $entry.FullName.Replace('\', '/')
			if (
				$name.StartsWith('/') -or
				$name -match '^[A-Za-z]:' -or
				$name -match '(^|/)\.\.(/|$)' -or
				$name.IndexOf([char]0) -ge 0
			) {
				throw "Unsafe ZIP entry: $name"
			}
			if (-not $normalizedNames.Add($name)) {
				throw "Duplicate ZIP entry: $name"
			}
		}

		Assert-NoForbiddenPaths -Names $entryNames -Archive

		$expectedArchiveNames = [System.Collections.Generic.HashSet[string]]::new(
			[System.StringComparer]::OrdinalIgnoreCase
		)
		foreach ($sourceFile in $sourceItems | Where-Object { -not $_.PSIsContainer }) {
			$relativePath = $sourceFile.FullName.Substring($sourceRoot.Length).TrimStart('\', '/').Replace('\', '/')
			if (-not (Test-DevelopmentOnlyPath -RelativePath $relativePath)) {
				[void] $expectedArchiveNames.Add($relativePath)
			}
		}
		if (-not $expectedArchiveNames.Contains('LICENSE.txt')) {
			$repositoryLicense = Join-Path $repoRoot 'LICENSE.txt'
			if (-not (Test-Path -LiteralPath $repositoryLicense -PathType Leaf)) {
				throw "Missing repository license: $repositoryLicense"
			}
			[void] $expectedArchiveNames.Add('LICENSE.txt')
		}
		foreach ($expectedName in $expectedArchiveNames) {
			if (-not $normalizedNames.Contains($expectedName)) {
				throw "Release ZIP is missing a releasable source file: $expectedName"
			}
		}

		$manifestEntries = @($zipArchive.Entries | Where-Object {
			$_.FullName.Replace('\', '/') -ceq $manifestName
		})
		if ($manifestEntries.Count -ne 1) {
			throw "The release ZIP must contain exactly one $manifestName at its root."
		}

		$manifestReader = [System.IO.StreamReader]::new($manifestEntries[0].Open())
		try {
			$archiveManifestText = $manifestReader.ReadToEnd()
		}
		finally {
			$manifestReader.Dispose()
		}

		$archiveManifest = Read-SafeXml -Text $archiveManifestText -DisplayName "$archivePath::$manifestName"
		Assert-Manifest -Document $archiveManifest -DisplayName "$archivePath::$manifestName"
		$archiveDeclaredPaths = Get-ManifestRelativePaths -Document $archiveManifest
		if ($archiveManifestText -cne $sourceManifestText) {
			throw "The release ZIP manifest differs from the source manifest."
		}

		foreach ($relativePath in $archiveDeclaredPaths) {
			$exactFile = $normalizedNames.Contains($relativePath)
			$folderPrefix = $relativePath.TrimEnd('/') + '/'
			$folderPresent = $false
			foreach ($name in $normalizedNames) {
				if ($name.StartsWith($folderPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
					$folderPresent = $true
					break
				}
			}
			if (-not $exactFile -and -not $folderPresent) {
				throw "Manifest entry is missing from release ZIP: $relativePath"
			}
		}

		$allowedMetadata = @(
			$manifestName,
			'LICENSE',
			'LICENSE.md',
			'LICENSE.txt',
			'README',
			'README.md',
			'README.txt',
			'CHANGELOG',
			'CHANGELOG.md',
			'CHANGELOG.txt'
		)
		foreach ($entry in $zipArchive.Entries | Where-Object { $_.Name }) {
			$name = $entry.FullName.Replace('\', '/')
			if ($allowedMetadata -contains $name) {
				continue
			}

			$isDeclared = $false
			foreach ($relativePath in $archiveDeclaredPaths) {
				if (
					$name -ieq $relativePath -or
					$name.StartsWith(
						$relativePath.TrimEnd('/') + '/',
						[System.StringComparison]::OrdinalIgnoreCase
					)
				) {
					$isDeclared = $true
					break
				}
			}
			if (-not $isDeclared) {
				throw "Release ZIP contains a file not declared by the manifest: $name"
			}
		}

		foreach ($entry in $zipArchive.Entries | Where-Object { $_.Name }) {
			$name = $entry.FullName.Replace('\', '/')
			$relativeNativePath = $name.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
			$componentSourcePath = Join-Path $sourceRoot $relativeNativePath
			$sourcePath = if (Test-Path -LiteralPath $componentSourcePath -PathType Leaf) {
				$componentSourcePath
			}
			elseif ($allowedMetadata -contains $name) {
				Join-Path $repoRoot $relativeNativePath
			}
			else {
				$componentSourcePath
			}

			if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
				throw "Release ZIP entry has no matching source file: $name"
			}

			$entryStream = $entry.Open()
			try {
				$archiveFileHash = Get-StreamSha256 -Stream $entryStream
			}
			finally {
				$entryStream.Dispose()
			}
			$sourceFileHash = (
				Get-FileHash -LiteralPath $sourcePath -Algorithm SHA256
			).Hash.ToLowerInvariant()
			if ($archiveFileHash -cne $sourceFileHash) {
				throw "Release ZIP entry differs from its source file: $name"
			}
		}

		foreach ($xmlEntry in $zipArchive.Entries | Where-Object {
			$_.Name -and [System.IO.Path]::GetExtension($_.Name) -ieq '.xml'
		}) {
			$reader = [System.IO.StreamReader]::new($xmlEntry.Open())
			try {
				$text = $reader.ReadToEnd()
			}
			finally {
				$reader.Dispose()
			}
			[void] (Read-SafeXml -Text $text -DisplayName "$archivePath::$($xmlEntry.FullName)")
		}

		New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
		foreach ($phpEntry in $zipArchive.Entries | Where-Object {
			$_.Name -and [System.IO.Path]::GetExtension($_.Name) -ieq '.php'
		}) {
			$relativeNativePath = $phpEntry.FullName.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
			$tempPath = Join-Path $tempRoot $relativeNativePath
			$tempDirectory = Split-Path -Parent $tempPath
			New-Item -ItemType Directory -Path $tempDirectory -Force | Out-Null

			$inputStream = $phpEntry.Open()
			$outputStream = [System.IO.File]::Open(
				$tempPath,
				[System.IO.FileMode]::CreateNew,
				[System.IO.FileAccess]::Write,
				[System.IO.FileShare]::None
			)
			try {
				$inputStream.CopyTo($outputStream)
			}
			finally {
				$outputStream.Dispose()
				$inputStream.Dispose()
			}

			Invoke-PhpLint -Path $tempPath
			$archivePhpCount++
		}
	}
	finally {
		$zipArchive.Dispose()
		$zipStream.Dispose()

		$tempFullPath = [System.IO.Path]::GetFullPath($tempRoot)
		$tempBase = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
		$tempPrefix = $tempBase.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
		if (
			(Test-Path -LiteralPath $tempFullPath) -and
			$tempFullPath.StartsWith($tempPrefix, [System.StringComparison]::OrdinalIgnoreCase) -and
			([System.IO.Path]::GetFileName($tempFullPath)).StartsWith('smi-joomla-validation-')
		) {
			Remove-Item -LiteralPath $tempFullPath -Recurse -Force
		}
	}
}

[pscustomobject]@{
	Status            = 'passed'
	Version           = $Version
	SourcePath        = $sourceRoot
	ZipPath           = if ([string]::IsNullOrWhiteSpace($ZipPath)) { $null } else { [System.IO.Path]::GetFullPath($ZipPath) }
	ManifestEntries   = $declaredPaths.Count
	SourcePhpFiles    = $sourcePhpCount
	ArchivePhpFiles   = $archivePhpCount
	Warnings          = $warnings.ToArray()
}
