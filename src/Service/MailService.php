<?php

declare(strict_types=1);

namespace Ytan\Service;

use PHPMailer\PHPMailer\PHPMailer;

final class MailService
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly string $encryption,
        private readonly string $appName,
    ) {
    }

    public function sendInvite(string $toEmail, string $username, string $link): void
    {
        $this->send(
            $toEmail,
            $this->appName . ' - Set up your account',
            "Hello $username,\n\n" .
            "An account has been created for you on " . $this->appName . ".\n" .
            "Please use the link below to set your password and log in for the first time:\n\n" .
            "$link\n\n" .
            "This link is valid for 7 days."
        );
    }

    public function sendPasswordReset(string $toEmail, string $link): void
    {
        $this->send(
            $toEmail,
            $this->appName . ' - Reset your password',
            "You (or someone else) requested a password reset for your " . $this->appName . " account.\n" .
            "Use the link below to set a new password:\n\n" .
            "$link\n\n" .
            "This link is valid for 1 hour. If you did not request this, you can ignore this email."
        );
    }

    public function sendEmailChangeConfirmation(string $toNewEmail, string $link): void
    {
        $this->send(
            $toNewEmail,
            $this->appName . ' - Confirm your new email address',
            "You requested to change the email address on your " . $this->appName . " account to this one.\n" .
            "Use the link below to confirm the change:\n\n" .
            "$link\n\n" .
            "This link is valid for 1 hour. If you did not request this, you can ignore this email."
        );
    }

    private function send(string $toEmail, string $subject, string $bodyText): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->host;
        $mail->Port = $this->port;
        $mail->SMTPAuth = $this->username !== '';
        $mail->Username = $this->username;
        $mail->Password = $this->password;
        if ($this->encryption !== '') {
            $mail->SMTPSecure = $this->encryption;
        }

        $mail->setFrom($this->from, $this->appName);
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $mail->Body = $bodyText;

        $mail->send();
    }
}
