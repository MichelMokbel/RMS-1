$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigPath = Join-Path $Root "config.json"
$StatePath = Join-Path $Root "state"
$SpoolPath = Join-Path $Root "spool"
$LogPath = Join-Path $Root "agent.log"
$SumatraPath = Join-Path $Root "SumatraPDF.exe"

function Write-AgentLog {
    param([string]$Message)
    if (Test-Path $LogPath) {
        $log = Get-Item $LogPath
        if ($log.Length -gt 1048576) {
            Move-Item -Force $LogPath ($LogPath + ".previous")
        }
    }
    Add-Content -Path $LogPath -Value ("{0:u} {1}" -f (Get-Date), $Message)
}

function Invoke-Rms {
    param(
        [string]$Method,
        [string]$Path,
        [object]$Body = $null
    )

    $headers = @{
        "Accept" = "application/json"
        "Authorization" = "Bearer " + $Config.token
        "X-Device-Id" = $Config.device_id
    }
    $arguments = @{
        Method = $Method
        Uri = $Config.base_url.TrimEnd("/") + $Path
        Headers = $headers
        ContentType = "application/json"
        TimeoutSec = 45
    }
    if ($null -ne $Body) {
        $arguments.Body = $Body | ConvertTo-Json -Depth 8 -Compress
    }

    return Invoke-RestMethod @arguments
}

function Acknowledge-Job {
    param(
        [long]$JobId,
        [string]$ClaimToken,
        [string]$Status,
        [string]$ErrorCode = $null
    )

    $body = @{
        claim_token = $ClaimToken
        status = $Status
    }
    if ($Status -eq "failed") {
        $body.error_code = $(if ($ErrorCode) { $ErrorCode } else { "PRINT_FAILED" })
        $body.error_message = "The Windows print agent could not complete this job."
    }
    Invoke-Rms -Method "POST" -Path ("/api/pos/print-jobs/{0}/ack" -f $JobId) -Body $body | Out-Null
}

function Test-PdfHeader {
    param([byte[]]$Bytes)
    if ($Bytes.Length -lt 5) {
        return $false
    }
    return [Text.Encoding]::ASCII.GetString($Bytes, 0, 5) -eq "%PDF-"
}

function Handle-Job {
    param([object]$Job)

    $jobId = [long]$Job.job_id
    $claimToken = [string]$Job.claim_token
    if ($jobId -le 0 -or [string]::IsNullOrWhiteSpace($claimToken)) {
        Write-AgentLog "job=0 result=failed code=INVALID_JOB"
        return
    }

    $printedMarker = Join-Path $StatePath ("{0}.printed" -f $jobId)
    $submittingMarker = Join-Path $StatePath ("{0}.submitting" -f $jobId)
    if (Test-Path $printedMarker) {
        Acknowledge-Job -JobId $jobId -ClaimToken $claimToken -Status "printed"
        Write-AgentLog ("job={0} result=acknowledged" -f $jobId)
        return
    }
    if (Test-Path $submittingMarker) {
        Acknowledge-Job -JobId $jobId -ClaimToken $claimToken -Status "failed" -ErrorCode "AMBIGUOUS_LOCAL_STATE"
        Write-AgentLog ("job={0} result=failed code=AMBIGUOUS_LOCAL_STATE" -f $jobId)
        return
    }

    $errorCode = "PRINT_FAILED"
    try {
        if ([string]$Job.doc_type -ne "order_label_pdf" -or [string]$Job.target -ne "order_label_printer") {
            throw "UNSUPPORTED_DOCUMENT"
        }
        if ([string]$Job.metadata.printer_queue -ne [string]$Config.queue_name) {
            throw "QUEUE_NOT_ALLOWLISTED"
        }
        $mediaMatches = (
            [int]$Job.metadata.width_tenths_mm -eq [int]$Config.width_tenths_mm -and
            [int]$Job.metadata.resolution_dpi -eq [int]$Config.resolution_dpi
        )
        if ([string]$Config.media_mode -eq "fixed") {
            $mediaMatches = $mediaMatches -and (
                [int]$Job.metadata.height_tenths_mm -eq [int]$Config.height_tenths_mm
            )
        } elseif ([string]$Config.media_mode -eq "continuous") {
            $mediaMatches = $mediaMatches -and (
                [int]$Job.metadata.height_tenths_mm -ge [int]$Config.min_height_tenths_mm -and
                [int]$Job.metadata.height_tenths_mm -le [int]$Config.max_height_tenths_mm
            )
        } else {
            $mediaMatches = $false
        }
        if (-not $mediaMatches) {
            throw "INVALID_MEDIA"
        }

        try {
            [byte[]]$pdf = [Convert]::FromBase64String([string]$Job.payload_base64)
        } catch {
            throw "INVALID_PAYLOAD"
        }
        if ($pdf.Length -gt 10485760 -or -not (Test-PdfHeader -Bytes $pdf)) {
            throw "INVALID_PDF"
        }
        if (-not (Get-Printer -Name $Config.queue_name -ErrorAction SilentlyContinue)) {
            throw "PRINTER_NOT_FOUND"
        }

        New-Item -ItemType File -Path $submittingMarker -ErrorAction Stop | Out-Null
        $pdfPath = Join-Path $SpoolPath ("{0}.pdf" -f $jobId)
        [IO.File]::WriteAllBytes($pdfPath, $pdf)

        try {
            & $SumatraPath "-print-to" $Config.queue_name "-silent" $pdfPath
            $printExitCode = $LASTEXITCODE
            if ($printExitCode -ne 0) {
                Remove-Item -Force $submittingMarker -ErrorAction SilentlyContinue
                throw ("OS_PRINT_FAILED_{0}" -f $printExitCode)
            }
        } finally {
            Remove-Item -Force $pdfPath -ErrorAction SilentlyContinue
        }

        Move-Item -Force $submittingMarker $printedMarker
        Acknowledge-Job -JobId $jobId -ClaimToken $claimToken -Status "printed"
        Write-AgentLog ("job={0} result=printed" -f $jobId)
    } catch {
        $candidate = [string]$_.Exception.Message
        if ($candidate -match "^[A-Z0-9_]+$") {
            $errorCode = $candidate
        }
        try {
            Acknowledge-Job -JobId $jobId -ClaimToken $claimToken -Status "failed" -ErrorCode $errorCode
        } catch {
        }
        Write-AgentLog ("job={0} result=failed code={1}" -f $jobId, $errorCode)
    }
}

New-Item -ItemType Directory -Force -Path $StatePath, $SpoolPath | Out-Null
if (-not (Test-Path $ConfigPath) -or -not (Test-Path $SumatraPath)) {
    Write-AgentLog "agent result=stopped code=LOCAL_CONFIG"
    exit 1
}

$Config = Get-Content -Raw -Path $ConfigPath | ConvertFrom-Json
$required = @("base_url", "token", "device_id", "queue_name", "media_mode", "width_tenths_mm", "resolution_dpi")
foreach ($field in $required) {
    if ($null -eq $Config.$field -or [string]::IsNullOrWhiteSpace([string]$Config.$field)) {
        Write-AgentLog "agent result=stopped code=LOCAL_CONFIG"
        exit 1
    }
}

Write-AgentLog "agent result=started"
while ($true) {
    try {
        $response = Invoke-Rms -Method "GET" -Path "/api/pos/print-jobs/pull?wait_seconds=30&limit=10"
        foreach ($job in @($response.jobs)) {
            Handle-Job -Job $job
        }
    } catch {
        Write-AgentLog "agent result=retry code=RMS_UNAVAILABLE"
        Start-Sleep -Seconds 5
    }
}
