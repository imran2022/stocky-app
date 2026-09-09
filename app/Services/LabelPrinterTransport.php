<?php

namespace App\Services;

use App\Models\PosSetting;

/**
 * Delivers a raw command payload (TSPL bytes) to the configured label printer.
 *
 * - 'network': TCP RAW/JetDirect socket (same mechanism as receipt Direct
 *   Network Printing) — printer must be reachable from the web server.
 * - 'windows': USB printer attached to the (Windows) server — bytes are sent
 *   through the Windows print spooler in RAW mode via a PowerShell helper
 *   (resources/scripts/raw_print.ps1), so no printer sharing is required.
 */
class LabelPrinterTransport
{
    /** @return array{ok: bool, message: string} */
    public function send(PosSetting $printer, string $payload): array
    {
        $connection = $printer->label_printer_connection === 'network' ? 'network' : 'windows';

        return $connection === 'network'
            ? $this->sendNetwork($printer, $payload)
            : $this->sendWindows($printer, $payload);
    }

    private function sendNetwork(PosSetting $printer, string $payload): array
    {
        $ip = trim((string) $printer->label_printer_ip);
        $port = (int) ($printer->label_printer_port ?: 9100);
        if ($ip === '' || $port < 1 || $port > 65535) {
            return ['ok' => false, 'message' => 'Label printer IP/port is not configured.'];
        }

        $fp = @fsockopen($ip, $port, $errno, $errstr, 3);
        if (! $fp) {
            return ['ok' => false, 'message' => "Could not reach label printer at {$ip}:{$port} ({$errstr})."];
        }
        @stream_set_timeout($fp, 5);
        // Large jobs can exceed the socket send buffer — loop until everything
        // is written instead of trusting a single fwrite().
        $total = strlen($payload);
        $sent = 0;
        while ($sent < $total) {
            $written = @fwrite($fp, substr($payload, $sent));
            if ($written === false || $written === 0) {
                break;
            }
            $sent += $written;
        }
        @fflush($fp);
        @fclose($fp);

        if ($sent < $total) {
            return ['ok' => false, 'message' => "Write to label printer at {$ip}:{$port} failed ({$sent}/{$total} bytes sent)."];
        }
        return ['ok' => true, 'message' => "Sent to {$ip}:{$port}."];
    }

    private function sendWindows(PosSetting $printer, string $payload): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return ['ok' => false, 'message' => 'USB (Windows spooler) printing requires the server to run on Windows. Use the network connection type instead.'];
        }

        $name = trim((string) $printer->label_printer_name);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Windows printer name is not configured.'];
        }

        $script = base_path('resources/scripts/raw_print.ps1');
        if (! is_file($script)) {
            return ['ok' => false, 'message' => 'raw_print.ps1 helper script is missing.'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'lbl');
        if ($tmp === false || file_put_contents($tmp, $payload) === false) {
            return ['ok' => false, 'message' => 'Could not write the temporary print file.'];
        }

        try {
            $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
                . escapeshellarg($script)
                . ' -Printer ' . escapeshellarg($name)
                . ' -Path ' . escapeshellarg($tmp)
                . ' 2>&1';
            @exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $detail = trim(implode(' ', array_slice((array) $output, 0, 3)));
                return ['ok' => false, 'message' => "Windows spooler print to \"{$name}\" failed" . ($detail !== '' ? ": {$detail}" : '.')];
            }
            return ['ok' => true, 'message' => "Sent to \"{$name}\"."];
        } finally {
            @unlink($tmp);
        }
    }
}
