<?php
/**
 * FormFill Email Sender
 * 
 * PHPMailer wrapper with styled HTML templates.
 * SMTP configuration from src/config.php ($smtp_config).
 */

namespace FormFill\Lib;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private static function createMailer(): PHPMailer
    {
        global $smtp_config;

        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host       = $smtp_config['host'];
        $mail->Port       = $smtp_config['port'];
        $mail->SMTPAuth   = $smtp_config['auth'] ?? true;
        $mail->Username   = $smtp_config['username'];
        $mail->Password   = $smtp_config['password'];
        $mail->SMTPSecure = $smtp_config['security'] ?? PHPMailer::ENCRYPTION_STARTTLS;

        $fromName = Config::brandName();
        $mail->setFrom($smtp_config['username'], $fromName);
        $mail->isHTML(true);

        return $mail;
    }

    /**
     * Send a generic HTML email.
     */
    public static function send(string $to, string $subject, string $htmlBody, ?string $attachment = null): bool
    {
        try {
            $mail = self::createMailer();
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            if ($attachment && file_exists($attachment)) {
                $mail->addAttachment($attachment);
            }

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("FormFill Mailer error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a 6-digit OTP code to a user's email.
     */
    public static function sendOtp(string $to, string $code): bool
    {
        $brand = htmlspecialchars(Config::brandName(), ENT_QUOTES, 'UTF-8');
        $subject = "{$brand} — Código de Verificação";

        $html = <<<HTML
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 480px; margin: 0 auto; padding: 32px 24px; background: #f8fafc;">
            <div style="background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="color: #4F46E5; margin: 0 0 8px 0;">{$brand}</h2>
                <p style="color: #475569; font-size: 16px; margin: 24px 0 8px 0;">O seu código de verificação é:</p>
                <div style="background: #EEF2FF; border-radius: 8px; padding: 16px; text-align: center; margin: 16px 0;">
                    <span style="font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #4F46E5;">{$code}</span>
                </div>
                <p style="color: #94A3B8; font-size: 14px;">Este código expira em 10 minutos.</p>
            </div>
        </div>
        HTML;

        return self::send($to, $subject, $html);
    }

    /**
     * Send form submission confirmation with PDF attachment.
     */
    public static function sendFormConfirmation(string $to, string $nome, string $subject, string $body, string $pdfPath): bool
    {
        $brand = htmlspecialchars(Config::brandName(), ENT_QUOTES, 'UTF-8');
        $nomeSafe = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
        $bodySafe = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        $html = <<<HTML
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 480px; margin: 0 auto; padding: 32px 24px; background: #f8fafc;">
            <div style="background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="color: #4F46E5; margin: 0 0 8px 0;">{$brand}</h2>
                <p style="color: #475569; font-size: 16px;">Olá, {$nomeSafe}!</p>
                <p style="color: #475569; font-size: 16px;">{$bodySafe}</p>
                <p style="color: #94A3B8; font-size: 14px; margin-top: 24px;">O documento preenchido segue em anexo.</p>
            </div>
        </div>
        HTML;

        return self::send($to, $subject, $html, $pdfPath);
    }

    /**
     * Send admin response notification with PDF re-attachment.
     */
    public static function sendResponseNotification(string $to, string $nome, string $resposta, string $pdfPath): bool
    {
        $brand = htmlspecialchars(Config::brandName(), ENT_QUOTES, 'UTF-8');
        $nomeSafe = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
        $respostaSafe = nl2br(htmlspecialchars($resposta, ENT_QUOTES, 'UTF-8'));

        $html = <<<HTML
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 480px; margin: 0 auto; padding: 32px 24px; background: #f8fafc;">
            <div style="background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="color: #4F46E5; margin: 0 0 8px 0;">{$brand}</h2>
                <p style="color: #475569; font-size: 16px;">Olá, {$nomeSafe}!</p>
                <p style="color: #475569; font-size: 16px;">Recebeu uma resposta ao seu formulário:</p>
                <div style="background: #EEF2FF; border-radius: 8px; padding: 16px; margin: 16px 0;">
                    <p style="color: #475569; font-size: 15px; margin: 0;">{$respostaSafe}</p>
                </div>
                <p style="color: #94A3B8; font-size: 14px;">O documento original segue em anexo.</p>
            </div>
        </div>
        HTML;

        $subject = "{$brand} — Resposta ao seu formulário";
        return self::send($to, $subject, $html, $pdfPath);
    }
}
