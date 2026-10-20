<?php

declare(strict_types=1);

/**
 * Client teeeotp pour les projets PHP 8.1 (KoliGo, ERP scolaire, Pressing…).
 *
 * Un seul fichier, aucune dependance hormis l'extension curl :
 *
 *   $otp = new TeeeOtpClient('https://trugroup.cm/teeeotp', getenv('TEEEOTP_API_KEY'));
 *
 *   $demande = $otp->sendOtp('690000000', 'signup');   // ['requestId' => …, 'expiresIn' => 300]
 *   $_SESSION['otp_request_id'] = $demande['requestId'];
 *
 *   $res = $otp->verifyOtp($_SESSION['otp_request_id'], $_POST['code']);
 *   if ($res['valid']) { … activer le compte … }
 *
 * Le projet ne voit jamais le code : il ne conserve que requestId.
 *
 * Avec une cle de test (sk_test_…), les reponses portent en plus `test`,
 * `testCode` (le code envoye, pour les tests automatises) et
 * `testSmsRemaining` ; au-dela de l allocation de vrais SMS, `status` vaut
 * `simulated` et rien ne part.
 */
final class TeeeOtpClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeoutSeconds = 10,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Demande l'envoi d'un code par SMS.
     *
     * @return array{requestId: string, expiresIn: int, length: int, status: string, testCode?: string}
     * @throws TeeeOtpException code TOO_MANY_REQUESTS, QUOTA_EXCEEDED, INVALID_PHONE…
     */
    public function sendOtp(string $phone, string $purpose = 'verification', int $length = 6): array
    {
        return $this->request('POST', '/v1/otp/send', [
            'phone'   => $phone,
            'purpose' => $purpose,
            'length'  => $length,
        ]);
    }

    /**
     * Verifie le code saisi par l'utilisateur.
     *
     * @return array{valid: bool, reason?: string, attemptsLeft?: int, phone?: string, purpose?: string}
     *         reason : invalid_code, blocked, expired, already_used, not_found
     */
    public function verifyOtp(string $requestId, string $code): array
    {
        return $this->request('POST', '/v1/otp/verify', [
            'requestId' => $requestId,
            'code'      => preg_replace('/\D+/', '', $code),
        ]);
    }

    /** Etat d'une demande, y compris celui du SMS (queued, sent, delivered, failed). */
    public function otpStatus(string $requestId): array
    {
        return $this->request('GET', '/v1/otp/' . rawurlencode($requestId));
    }

    /**
     * SMS libre : notification de commande prete, rappel, etc.
     *
     * @return array{id: string, status: string}
     */
    public function sendSms(string $phone, string $message, int $ttlSeconds = 86400): array
    {
        return $this->request('POST', '/v1/sms', [
            'phone'      => $phone,
            'message'    => $message,
            'ttlSeconds' => $ttlSeconds,
        ]);
    }

    /** Projet de la cle : nom, mode (test ou live), quota et consommation. */
    public function me(): array
    {
        return $this->request('GET', '/v1/me');
    }

    public function smsStatus(string $id): array
    {
        return $this->request('GET', '/v1/sms/' . rawurlencode($id));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Accept: application/json', 'X-Api-Key: ' . $this->apiKey];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new TeeeOtpException('Service OTP injoignable : ' . $error, 'UNREACHABLE', 0);
        }

        $data = json_decode((string) $raw, true);
        if ($status >= 400 || !is_array($data)) {
            $err = is_array($data) ? ($data['error'] ?? []) : [];
            throw new TeeeOtpException(
                (string) ($err['message'] ?? "Reponse inattendue (HTTP $status)"),
                (string) ($err['code'] ?? 'HTTP_' . $status),
                $status,
                isset($err['retryAfter']) ? (int) $err['retryAfter'] : null,
            );
        }

        return $data;
    }
}

final class TeeeOtpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
