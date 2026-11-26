<?php
/**
 * WhatsAppService
 * Sends automated WhatsApp OTP & fee approval notifications via Meta WhatsApp Cloud API
 * to 2 configured admin/owner numbers, and verifies submitted OTPs.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../functions.php';

class WhatsAppService
{
    /**
     * Normalize a phone number for Meta WhatsApp API (digits only, prepend 91 if 10 digits)
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '91' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }
        return $digits;
    }

    /**
     * Mask phone number for safe UI display (e.g. ******4321)
     */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) <= 4) {
            return $digits;
        }
        return str_repeat('*', max(4, strlen($digits) - 4)) . substr($digits, -4);
    }

    /**
     * Get configured WhatsApp API settings & 2 admin numbers
     */
    public static function getConfig(): array
    {
        return [
            'enabled' => get_setting('wa_otp_enabled', '1') === '1',
            'phone_number_id' => trim((string)get_setting('wa_phone_number_id', '')),
            'access_token' => trim((string)get_setting('wa_access_token', '')),
            'api_version' => trim((string)get_setting('wa_api_version', 'v21.0')) ?: 'v21.0',
            'template_name' => trim((string)get_setting('wa_template_name', '')),
            'template_lang' => trim((string)get_setting('wa_template_lang', 'en')) ?: 'en',
            'mobile_1' => trim((string)get_setting('wa_otp_mobile_1', '')),
            'mobile_2' => trim((string)get_setting('wa_otp_mobile_2', '')),
        ];
    }

    /**
     * Send a single message via Meta WhatsApp Cloud API
     */
    public static function sendMetaMessage(string $toPhone, array $payload, array $config): array
    {
        $normalized = self::normalizePhone($toPhone);
        if (strlen($normalized) < 11) {
            return ['success' => false, 'error' => "અમાન્ય મોબાઈલ નંબર: {$toPhone}"];
        }

        if ($config['phone_number_id'] === '' || $config['access_token'] === '') {
            return ['success' => false, 'error' => 'Meta Phone Number ID અથવા Access Token Settings માં સેટ કરેલ નથી.'];
        }

        $url = "https://graph.facebook.com/{$config['api_version']}/{$config['phone_number_id']}/messages";
        $body = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $normalized,
        ], $payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['access_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);

        $responseRaw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'error' => "Network error: {$curlErr}"];
        }

        $decoded = json_decode((string)$responseRaw, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['messages'][0]['id'])) {
            return [
                'success' => true,
                'message_id' => $decoded['messages'][0]['id'],
                'error' => null,
            ];
        }

        $apiError = $decoded['error']['message'] ?? "HTTP {$httpCode}: Unknown Meta API error";
        return ['success' => false, 'error' => $apiError];
    }

    /**
     * Generate a 6-digit OTP, store it in DB, and send with full fee details to both admin numbers
     */
    public static function sendOtpToAdmins(array $details, int $requestedBy): array
    {
        $config = self::getConfig();

        $recipients = [];
        if ($config['mobile_1'] !== '') {
            $recipients[] = $config['mobile_1'];
        }
        if ($config['mobile_2'] !== '' && self::normalizePhone($config['mobile_2']) !== self::normalizePhone($config['mobile_1'])) {
            $recipients[] = $config['mobile_2'];
        }

        if (empty($recipients)) {
            throw new RuntimeException('Settings માં ઓછામાં ઓછો ૧ એડમિન WhatsApp નંબર સેટ કરો.');
        }

        $otpCode = (string)random_int(100000, 999999);
        $context = $details['context'] ?? 'payment';
        if (!in_array($context, ['payment', 'manual_receipt', 'delete_payment'], true)) {
            $context = 'payment';
        }

        $studentName = trim((string)($details['student_name'] ?? 'Student'));
        $amount = (float)($details['amount'] ?? 0);
        $amountFormatted = '₹' . number_format($amount, 2);
        $stageLabel = trim((string)($details['stage_label'] ?? 'Course Fee'));
        $method = strtoupper(trim((string)($details['method'] ?? 'CASH')));
        $refNo = trim((string)($details['reference_no'] ?? ''));
        $collectorName = trim((string)($details['collector_name'] ?? 'Admin'));
        $extraNote = trim((string)($details['notes'] ?? ''));

        $pdo = db();

        // Expire previous pending OTPs requested by this user to keep table clean
        $pdo->prepare("UPDATE payment_otps SET status = 'expired' WHERE requested_by = ? AND status = 'pending'")
            ->execute([$requestedBy]);

        // Save new OTP (valid for 5 minutes)
        $stmt = $pdo->prepare("
            INSERT INTO payment_otps (
                otp_code, context, student_name, amount, stage_label, method,
                details_json, sent_to_1, sent_to_2, status, requested_by, expires_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, 'pending', ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE)
            )
        ");
        $stmt->execute([
            $otpCode,
            $context,
            $studentName,
            $amount,
            $stageLabel,
            $method,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            $recipients[0] ?? null,
            $recipients[1] ?? null,
            $requestedBy
        ]);
        $otpId = (int)$pdo->lastInsertId();

        // Build full readable details string
        $summaryLine = "{$stageLabel} | {$method}" . ($refNo !== '' ? " (Ref: {$refNo})" : '') . " | By: {$collectorName}";
        if ($extraNote !== '') {
            $summaryLine .= " | {$extraNote}";
        }

        $textBody = "🔐 *Devangi Sewing Classes - Fee Approval OTP*\n"
            . "----------------------------------------\n"
            . "Verification OTP: *{$otpCode}*\n"
            . "(Valid for 5 minutes)\n\n"
            . "📋 *Fee Details:*\n"
            . "• Student: {$studentName}\n"
            . "• Amount: {$amountFormatted}\n"
            . "• Fee Type: {$stageLabel}\n"
            . "• Mode: {$method}" . ($refNo !== '' ? " (Ref: {$refNo})" : "") . "\n"
            . ($extraNote !== '' ? "• Note: {$extraNote}\n" : "")
            . "• Entered By: {$collectorName}\n"
            . "• Time: " . date('d-M-Y, h:i A') . "\n"
            . "----------------------------------------\n"
            . "Share this OTP with staff only if payment is received.";

        // Choose template payload or direct text payload
        if ($config['template_name'] !== '') {
            $payload = [
                'type' => 'template',
                'template' => [
                    'name' => $config['template_name'],
                    'language' => ['code' => $config['template_lang']],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $otpCode],
                                ['type' => 'text', 'text' => $studentName],
                                ['type' => 'text', 'text' => $amountFormatted],
                                ['type' => 'text', 'text' => mb_substr($summaryLine, 0, 200)],
                            ],
                        ],
                    ],
                ],
            ];
        } else {
            $payload = [
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $textBody,
                ],
            ];
        }

        $sentCount = 0;
        $errors = [];
        $maskedNumbers = [];

        foreach ($recipients as $phone) {
            $maskedNumbers[] = self::maskPhone($phone);
            $res = self::sendMetaMessage($phone, $payload, $config);
            if ($res['success']) {
                $sentCount++;
            } else {
                $errors[] = self::maskPhone($phone) . ': ' . $res['error'];
            }
        }

        if ($sentCount === 0) {
            throw new RuntimeException('WhatsApp OTP મોકલવામાં નિષ્ફળ: ' . implode(' | ', $errors));
        }

        return [
            'success' => true,
            'otp_id' => $otpId,
            'sent_count' => $sentCount,
            'masked_numbers' => $maskedNumbers,
            'warnings' => $errors,
        ];
    }

    /**
     * Verify a 6-digit WhatsApp OTP (marks it verified if valid & unexpired)
     */
    public static function verifyOtp(string $code): bool
    {
        $code = trim($code);
        if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT id FROM payment_otps
            WHERE otp_code = ? AND status = 'pending' AND expires_at >= NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$code]);
        $otpId = $stmt->fetchColumn();

        if (!$otpId) {
            return false;
        }

        $pdo->prepare("UPDATE payment_otps SET status = 'verified', verified_at = NOW() WHERE id = ?")
            ->execute([$otpId]);

        return true;
    }
}
