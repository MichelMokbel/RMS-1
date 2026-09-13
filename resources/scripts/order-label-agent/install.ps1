$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if (-not $scriptPath) {
        Write-Host "Run this setup from the downloaded file." -ForegroundColor Red
        Read-Host "Press Enter to close"
        exit 1
    }
    $elevatedArguments = '-NoProfile -ExecutionPolicy Bypass -File "' + $scriptPath.Replace('"', '""') + '"'
    Start-Process -FilePath "powershell.exe" -Verb RunAs -ArgumentList $elevatedArguments
    exit 0
}

$ProfileId = "__PROFILE_ID__"
$InstallDir = Join-Path $env:ProgramData ("LaylaKitchen\PrintAgent\" + $ProfileId)
$AgentPath = Join-Path $InstallDir "agent.ps1"
$ConfigPath = Join-Path $InstallDir "config.json"
$SumatraPath = Join-Path $InstallDir "SumatraPDF.exe"
$TaskName = "Layla Kitchen Print Agent " + $ProfileId
$Utf8 = New-Object Text.UTF8Encoding($false)

try {
    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
    icacls $InstallDir /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" | Out-Null

    $agentText = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("__AGENT_SCRIPT_BASE64__"))
    $configText = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("__CONFIG_BASE64__"))
    $sumatraUrl = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("__SUMATRA_URL_BASE64__"))
    [IO.File]::WriteAllText($AgentPath, $agentText, $Utf8)
    [IO.File]::WriteAllText($ConfigPath, $configText, $Utf8)

    $config = $configText | ConvertFrom-Json
    $printer = Get-Printer -Name $config.queue_name -ErrorAction SilentlyContinue
    if (-not $printer) {
        Write-Host ""
        Write-Host ("Windows printer not found: {0}" -f $config.queue_name) -ForegroundColor Red
        Write-Host "Installed printer names:" -ForegroundColor Yellow
        Get-Printer | Select-Object -ExpandProperty Name
        throw "Install the printer driver or correct the Operating system queue in RMS, then download setup again."
    }

    if (-not (Test-Path $SumatraPath)) {
        $zipPath = Join-Path $env:TEMP ("Layla-Sumatra-" + [Guid]::NewGuid().ToString("N") + ".zip")
        $extractPath = Join-Path $env:TEMP ("Layla-Sumatra-" + [Guid]::NewGuid().ToString("N"))
        Invoke-WebRequest -UseBasicParsing -Uri $sumatraUrl -OutFile $zipPath
        $actualHash = (Get-FileHash -Algorithm SHA256 -Path $zipPath).Hash.ToLowerInvariant()
        if ($actualHash -ne "__SUMATRA_SHA256__") {
            Remove-Item -Force $zipPath -ErrorAction SilentlyContinue
            throw "The PDF renderer download failed its security check."
        }
        Expand-Archive -Path $zipPath -DestinationPath $extractPath -Force
        $downloadedExe = Get-ChildItem -Path $extractPath -Filter "SumatraPDF*.exe" | Select-Object -First 1
        if (-not $downloadedExe) {
            throw "The PDF renderer package is incomplete."
        }
        Copy-Item -Force $downloadedExe.FullName $SumatraPath
        Remove-Item -Recurse -Force $extractPath -ErrorAction SilentlyContinue
        Remove-Item -Force $zipPath -ErrorAction SilentlyContinue
    }

    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $AgentPath + '"'
    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $arguments
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Force | Out-Null
    Start-ScheduledTask -TaskName $TaskName

    Write-Host ""
    Write-Host "Layla Kitchen Print Agent is installed and running." -ForegroundColor Green
    Write-Host ("Printer: {0}" -f $config.queue_name)
    Write-Host "Return to RMS. The queued test label should print shortly."
    Write-Host "After it prints, select Verify printed test, then Activate."

    $self = $MyInvocation.MyCommand.Path
    if ($self) {
        $deleteCommand = 'ping 127.0.0.1 -n 3 > nul & del /f /q "' + $self + '"'
        Start-Process -WindowStyle Hidden -FilePath "cmd.exe" -ArgumentList "/c", $deleteCommand
    }
} catch {
    Write-Host ""
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Setup did not finish. Nothing was activated in RMS."
    Read-Host "Press Enter to close"
    exit 1
}
