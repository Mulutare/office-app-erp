param([string]$OutputPath = '')
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot
if(!$OutputPath){$OutputPath=Join-Path $root 'dist/careers'}
if(Test-Path -LiteralPath $OutputPath){throw 'Choose an empty destination to avoid mixing release files.'}
New-Item -ItemType Directory -Path $OutputPath | Out-Null
Copy-Item -Path (Join-Path $root 'careers/*') -Destination $OutputPath -Recurse
New-Item -ItemType Directory -Path (Join-Path $OutputPath 'lib') | Out-Null
foreach($name in @('Rules','CareersContract','CareersRequestSigner')) { Copy-Item -LiteralPath (Join-Path $root "app/services/Recruitment/$name.php") -Destination (Join-Path $OutputPath 'lib') }
Copy-Item -LiteralPath (Join-Path $root 'docs/recruitment-careers.md') -Destination (Join-Path $OutputPath 'README.md')
Write-Output "Standalone Careers package: $OutputPath"
