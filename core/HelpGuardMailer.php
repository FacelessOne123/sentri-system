<?php
/**
 * HelpGuardMailer – Minimal Gmail SMTP mailer (no Composer/PHPMailer required)
 * Uses PHP's built-in stream_socket_client + STARTTLS for Gmail App Password auth.
 *
 * Configuration: edit email_config.php – never put credentials directly here.
 */

require_once __DIR__ . '/../config/email.php';

class HelpGuardMailer
{
    private $socket;
    private $lastResponse = '';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Send a transactional email.
     *
     * @param  string $toEmail   Recipient email address
     * @param  string $toName    Recipient display name
     * @param  string $subject   Email subject
     * @param  string $htmlBody  Full HTML body
     * @return bool              true on success
     * @throws RuntimeException  on SMTP error
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $this->connect();
        $this->ehlo();
        $this->startTLS();
        $this->ehloAgain();
        $this->authLogin();
        $this->mailFrom();
        $this->rcptTo($toEmail);
        $this->data($toEmail, $toName, $subject, $htmlBody);
        $this->quit();
        return true;
    }

    // ── SMTP Steps ────────────────────────────────────────────────────────────

    private function connect(): void
    {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);
        $errno  = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            'tcp://' . MAIL_HOST . ':' . MAIL_PORT,
            $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$this->socket) {
            throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($this->socket, 15);
        $this->expect('220', 'Connect');
    }

    private function ehlo(): void
    {
        $this->cmd('EHLO localhost', '250', 'EHLO');
    }

    private function startTLS(): void
    {
        $this->cmd('STARTTLS', '220', 'STARTTLS');
        // Upgrade plain TCP to TLS
        if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS crypto handshake failed.');
        }
    }

    private function ehloAgain(): void
    {
        // Must re-send EHLO after TLS upgrade
        $this->cmd('EHLO localhost', '250', 'EHLO-TLS');
    }

    private function authLogin(): void
    {
        $this->cmd('AUTH LOGIN', '334', 'AUTH LOGIN');
        $this->cmd(base64_encode(MAIL_USERNAME), '334', 'AUTH user');
        $this->cmd(base64_encode(MAIL_PASSWORD), '235', 'AUTH pass');
    }

    private function mailFrom(): void
    {
        $this->cmd('MAIL FROM:<' . MAIL_FROM . '>', '250', 'MAIL FROM');
    }

    private function rcptTo(string $toEmail): void
    {
        $this->cmd('RCPT TO:<' . $toEmail . '>', '250', 'RCPT TO');
    }

    private function data(string $toEmail, string $toName, string $subject, string $htmlBody): void
    {
        $this->cmd('DATA', '354', 'DATA init');
        $msgId  = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@helpguard>';
        $date   = date('r');
        $fromLine = MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
        $toLne    = $toName ? "$toName <$toEmail>" : $toEmail;

        $msg  = "From: $fromLine\r\n";
        $msg .= "To: $toLne\r\n";
        $msg .= "Subject: " . $this->encodeHeader($subject) . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: quoted-printable\r\n";
        $msg .= "Message-ID: $msgId\r\n";
        $msg .= "Date: $date\r\n";
        $msg .= "\r\n";
        $msg .= quoted_printable_encode($htmlBody);
        // Dot-stuffing: lone dots on a line must be doubled
        $msg  = str_replace("\r\n.", "\r\n..", $msg);
        $msg .= "\r\n.";

        $this->raw($msg);
        $this->expect('250', 'DATA end');
    }

    private function quit(): void
    {
        $this->raw('QUIT');
        fclose($this->socket);
        $this->socket = null;
    }

    // ── Low-level helpers ─────────────────────────────────────────────────────

    private function cmd(string $cmd, string $expectedCode, string $ctx): string
    {
        $this->raw($cmd);
        return $this->expect($expectedCode, $ctx);
    }

    private function raw(string $cmd): void
    {
        fwrite($this->socket, $cmd . "\r\n");
    }

    private function expect(string $code, string $ctx): string
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // A line like "250 " (space after code) or "250-" (dash = more lines follow)
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $this->lastResponse = trim($response);
        $actual = substr($response, 0, 3);
        if ($actual !== $code) {
            throw new RuntimeException("SMTP [$ctx] expected $code, got: " . trim($response));
        }
        return $this->lastResponse;
    }

    private function encodeHeader(string $str): string
    {
        // RFC 2047 Base64 encoding for non-ASCII subject lines
        if (preg_match('/[^\x20-\x7E]/', $str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return $str;
    }
}


// ── Convenience functions (used by signup.php, etc.) ─────────────────────────

/**
 * Send an account verification email.
 */
function sendVerificationEmail(string $toEmail, string $toName, string $token): bool
{
    $link = APP_URL . '/verify_email.php?token=' . urlencode($token);
    $subject = 'Verify your HelpGuard account';
    $html = buildEmailTemplate(
        'Verify Your Email',
        "Hi $toName,",
        "Thanks for signing up for HelpGuard! Please click the button below to verify your email address and activate your account.",
        $link,
        'Verify My Account',
        'This link expires in <strong>24 hours</strong>. If you did not create a HelpGuard account, you can safely ignore this email.'
    );
    $mailer = new HelpGuardMailer();
    return $mailer->send($toEmail, $toName, $subject, $html);
}

/**
 * Send a password-reset email.
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $token): bool
{
    $link = APP_URL . '/reset_password.php?token=' . urlencode($token);
    $subject = 'Reset your HelpGuard password';
    $html = buildEmailTemplate(
        'Reset Your Password',
        "Hi $toName,",
        "We received a request to reset the password for your HelpGuard account. Click the button below to choose a new password.",
        $link,
        'Reset My Password',
        'This link expires in <strong>1 hour</strong>. If you did not request a password reset, you can safely ignore this email — your password will not change.'
    );
    $mailer = new HelpGuardMailer();
    return $mailer->send($toEmail, $toName, $subject, $html);
}

/**
 * Build a branded HTML email.
 */
function buildEmailTemplate(
    string $heading,
    string $greeting,
    string $body,
    string $ctaUrl,
    string $ctaText,
    string $footer
): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$heading}</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:600px;width:100%;">
      <!-- Header -->
      <tr>
        <td style="background:linear-gradient(135deg,#1c57b2,#3a8dff);padding:36px 40px;text-align:center;">
          <div style="display:inline-flex;align-items:center;gap:12px;">
            <div style="background:rgba(255,255,255,0.2);border-radius:12px;padding:10px 14px;display:inline-block;">
              <span style="font-size:24px;">🛡️</span>
            </div>
            <span style="font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">HelpGuard</span>
          </div>
          <div style="color:rgba(255,255,255,0.8);font-size:13px;margin-top:6px;">Community Safety Network</div>
        </td>
      </tr>
      <!-- Body -->
      <tr>
        <td style="padding:40px 40px 32px;">
          <h1 style="margin:0 0 8px;font-size:26px;font-weight:700;color:#1a1a2e;letter-spacing:-0.5px;">{$heading}</h1>
          <p style="margin:0 0 20px;font-size:15px;color:#555;">{$greeting}</p>
          <p style="margin:0 0 32px;font-size:15px;color:#444;line-height:1.7;">{$body}</p>
          <!-- CTA Button -->
          <table cellpadding="0" cellspacing="0" style="margin:0 auto 32px;">
            <tr>
              <td style="background:linear-gradient(135deg,#3a8dff,#1c57b2);border-radius:10px;padding:14px 36px;text-align:center;">
                <a href="{$ctaUrl}" style="color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;display:inline-block;">{$ctaText}</a>
              </td>
            </tr>
          </table>
          <!-- Fallback link -->
          <p style="font-size:13px;color:#888;line-height:1.6;">If the button doesn't work, copy and paste this link into your browser:<br>
          <a href="{$ctaUrl}" style="color:#3a8dff;word-break:break-all;">{$ctaUrl}</a></p>
        </td>
      </tr>
      <!-- Divider -->
      <tr><td style="padding:0 40px;"><hr style="border:none;border-top:1px solid #eee;margin:0;"></td></tr>
      <!-- Footer -->
      <tr>
        <td style="padding:24px 40px 32px;background:#fafafa;">
          <p style="margin:0 0 8px;font-size:13px;color:#888;line-height:1.6;">{$footer}</p>
          <p style="margin:0;font-size:12px;color:#aaa;">© HelpGuard · Community Safety Network · Philippines</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}
