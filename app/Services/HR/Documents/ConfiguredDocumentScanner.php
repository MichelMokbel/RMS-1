<?php

namespace App\Services\HR\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

class ConfiguredDocumentScanner implements DocumentScanner
{
    public function scan(string $absolutePath): DocumentScanResult
    {
        if (! is_file($absolutePath) || is_link($absolutePath)) {
            return new DocumentScanResult(false, 'filesystem', 'The upload is not a regular file.');
        }

        $driver = (string) config('hr.documents.scanner', 'null');

        if (in_array($driver, ['', 'null'], true)) {
            $driver = app()->isProduction() ? 'required' : 'safe';
        }

        if ($driver === 'safe' && ! app()->isProduction()) {
            return new DocumentScanResult(true, 'safe-local');
        }

        if ($driver !== 'clamav') {
            throw new RuntimeException('A malware scanner must be configured before HR documents can be accepted.');
        }

        $binary = (string) config('hr.documents.clamav_binary', 'clamscan');
        $timeout = max((int) config('hr.documents.scan_timeout', 30), 1);
        $process = new Process([$binary, '--no-summary', $absolutePath]);
        $process->setTimeout($timeout);
        $process->run();

        if ($process->getExitCode() === 0) {
            return new DocumentScanResult(true, 'clamav');
        }

        if ($process->getExitCode() === 1) {
            return new DocumentScanResult(false, 'clamav', trim($process->getOutput()) ?: 'Malware detected.');
        }

        throw new RuntimeException('The malware scanner failed: '.trim($process->getErrorOutput()));
    }
}
