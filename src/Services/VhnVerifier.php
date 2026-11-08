<?php

namespace Nfgarching\Bea\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Prüft die CMS-Signatur des Herkunftsnachweises (vhn.xml.p7s über vhn.xml).
 *
 * Status:
 *  valid         Signatur gültig, Kette bis zu einem hinterlegten Wurzelzertifikat
 *  signature_ok  Signatur mathematisch gültig, Kette nicht geprüft (kein bea.vhn.trust)
 *  invalid       Signatur ungültig oder Kette nicht vertrauenswürdig
 *  missing       vhn.xml oder vhn.xml.p7s fehlt
 *  notool        openssl nicht gefunden
 *  disabled      Prüfung abgeschaltet
 */
class VhnVerifier
{
    /**
     * @return array{status: string, signer?: ?string, issuer?: ?string, signed_at?: ?string, error?: string}
     */
    public function verify(?string $vhnPath, ?string $signaturePath): array
    {
        if (! config('bea.vhn.verify', true)) {
            return ['status' => 'disabled'];
        }
        if (! $vhnPath || ! $signaturePath || ! is_file($vhnPath) || ! is_file($signaturePath)) {
            return ['status' => 'missing'];
        }

        $binary = (string) config('bea.vhn.openssl', 'openssl');
        $trust = config('bea.vhn.trust');

        $signerFile = tempnam(sys_get_temp_dir(), 'vhn');
        $command = [$binary, 'cms', '-verify', '-binary', '-inform', 'DER',
            '-in', $signaturePath, '-content', $vhnPath, '-out', '/dev/null', '-purpose', 'any',
            '-signer', $signerFile];
        if ($trust && is_readable($trust)) {
            array_push($command, '-CAfile', $trust);
        } else {
            $command[] = '-noverify';
        }

        try {
            $result = Process::timeout(60)->run($command);
            $signerPem = is_file($signerFile) ? (string) file_get_contents($signerFile) : '';
        } finally {
            @unlink($signerFile);
        }
        $output = $result->output().$result->errorOutput();

        if ($result->exitCode() === 127 || str_contains($output, 'command not found')) {
            return ['status' => 'notool', 'error' => "Programm '{$binary}' nicht gefunden."];
        }

        $certificate = $this->signerCertificate($binary, $signaturePath, $signerPem);

        if (! $result->successful()) {
            return ['status' => 'invalid', 'error' => Str::limit(trim($output), 500)] + $certificate;
        }

        return ['status' => $trust && is_readable($trust) ? 'valid' : 'signature_ok'] + $certificate;
    }

    /**
     * @return array{signer: ?string, issuer: ?string, signed_at: ?string}
     */
    private function signerCertificate(string $binary, string $signaturePath, string $signerPem): array
    {
        // Signer aus der Prüfung; bei ungültiger Signatur ersatzweise das erste Zertifikat
        if (! str_contains($signerPem, 'BEGIN CERTIFICATE')) {
            $certs = Process::timeout(30)->run([$binary, 'pkcs7', '-inform', 'DER', '-in', $signaturePath, '-print_certs']);
            $signerPem = $certs->successful() ? $certs->output() : '';
        }
        if (! preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $signerPem, $pem)) {
            return ['signer' => null, 'issuer' => null, 'signed_at' => null];
        }

        $parsed = openssl_x509_parse($pem[0]) ?: [];
        $signedAt = null;
        $print = Process::timeout(30)->run([$binary, 'cms', '-cmsout', '-print', '-inform', 'DER', '-in', $signaturePath]);
        if (preg_match('/signingTime.*?(?:UTCTIME|GENERALIZEDTIME):([^\n]+)/s', $print->output(), $m)) {
            $time = strtotime(trim($m[1]));
            $signedAt = $time ? date(DATE_ATOM, $time) : null;
        }

        return [
            'signer' => $parsed['subject']['CN'] ?? null,
            'issuer' => $parsed['issuer']['CN'] ?? null,
            'signed_at' => $signedAt,
        ];
    }
}
