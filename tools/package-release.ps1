$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$mainFile = Join-Path $root 'coderembassy-order-vanguard.php'
$mainSource = Get-Content -LiteralPath $mainFile -Raw
$versionMatch = [regex]::Match($mainSource, "define\(\s*'CEOG_VERSION',\s*'([^']+)'\s*\)")
if (-not $versionMatch.Success) {
	throw 'Could not read CEOG_VERSION from the plugin bootstrap.'
}

$version = $versionMatch.Groups[1].Value
$slug = 'coderembassy-order-vanguard'
$releaseDirectory = Join-Path $root 'release'
$zipPath = Join-Path $releaseDirectory ($slug + '-' + $version + '.zip')
$stageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('ceog-release-' + [guid]::NewGuid().ToString('N'))
$stagePlugin = Join-Path $stageRoot $slug
$runtimeItems = @(
	'build',
	'includes',
	'languages',
	'coderembassy-order-vanguard.php',
	'assets',
	'readme.txt',
	'uninstall.php',
	'src',
	'package.json',
	'package-lock.json'
)

New-Item -ItemType Directory -Force -Path $releaseDirectory, $stagePlugin | Out-Null

try {
	foreach ($item in $runtimeItems) {
		$source = Join-Path $root $item
		if (-not (Test-Path -LiteralPath $source)) {
			throw "Required release item is missing: $item"
		}
		Copy-Item -LiteralPath $source -Destination $stagePlugin -Recurse -Force
	}

	if (Test-Path -LiteralPath $zipPath) {
		$resolvedRelease = (Resolve-Path -LiteralPath $releaseDirectory).Path
		$resolvedZip = (Resolve-Path -LiteralPath $zipPath).Path
		if (-not $resolvedZip.StartsWith($resolvedRelease + [System.IO.Path]::DirectorySeparatorChar)) {
			throw 'Refusing to replace a ZIP outside the release directory.'
		}
		Remove-Item -LiteralPath $resolvedZip -Force
	}

	Add-Type -AssemblyName System.IO.Compression
	Add-Type -AssemblyName System.IO.Compression.FileSystem
	$archiveStream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew)
	$writeArchive = New-Object System.IO.Compression.ZipArchive(
		$archiveStream,
		[System.IO.Compression.ZipArchiveMode]::Create,
		$false
	)
	try {
		foreach ($file in Get-ChildItem -LiteralPath $stagePlugin -Recurse -File) {
			$entryName = $file.FullName.Substring($stageRoot.Length + 1).Replace('\', '/')
			$entry = $writeArchive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
			$entryStream = $entry.Open()
			$fileStream = [System.IO.File]::OpenRead($file.FullName)
			try {
				$fileStream.CopyTo($entryStream)
			}
			finally {
				$fileStream.Dispose()
				$entryStream.Dispose()
			}
		}
	}
	finally {
		$writeArchive.Dispose()
		$archiveStream.Dispose()
	}
}
finally {
	$temporaryRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
	$resolvedStage = [System.IO.Path]::GetFullPath($stageRoot)
	if ($resolvedStage.StartsWith($temporaryRoot) -and (Test-Path -LiteralPath $resolvedStage)) {
		Remove-Item -LiteralPath $resolvedStage -Recurse -Force
	}
}

$archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
	$entries = @($archive.Entries | ForEach-Object { $_.FullName.Replace('\', '/') })
	$requiredPrefix = $slug + '/'
	if ($entries.Count -lt 10 -or @($entries | Where-Object { -not $_.StartsWith($requiredPrefix) }).Count -gt 0) {
		throw 'The release ZIP does not have the expected single plugin root.'
	}
	$forbidden = @($entries | Where-Object { $_ -match '/(node_modules|tests|tools|release)/' })
	$requiredSource = @(($requiredPrefix + 'src/index.js'), ($requiredPrefix + 'package.json'), ($requiredPrefix + 'package-lock.json'))
	if (@($requiredSource | Where-Object { $_ -notin $entries }).Count -gt 0) {
		throw 'Human-readable source or build configuration is missing from the ZIP.'
	}
	if ($forbidden.Count -gt 0) {
		throw ('Forbidden development files found in the ZIP: ' + ($forbidden -join ', '))
	}
}
finally {
	$archive.Dispose()
}

$sha = [System.Security.Cryptography.SHA256]::Create()
try {
	$hash = ([System.BitConverter]::ToString($sha.ComputeHash([System.IO.File]::ReadAllBytes($zipPath)))).Replace('-', '')
}
finally {
	$sha.Dispose()
}
Write-Output ("Release: {0}" -f $zipPath)
Write-Output ("SHA256:  {0}" -f $hash)
Write-Output ("Entries: {0}" -f $entries.Count)
