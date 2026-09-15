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

    public function sendTourRouteChanged(string $toEmail, string $tourName, string $routeName, string $link): void
    {
        $this->send(
            $toEmail,
            $this->appName . " - A route in your tour \"$tourName\" was changed",
            "The route \"$routeName\", which is part of your tour \"$tourName\", was just changed.\n" .
            "Please check your tour to make sure it still makes sense:\n\n" .
            "$link"
        );
    }

    public function sendTourRouteDeleted(string $toEmail, string $tourName, string $routeName): void
    {
        $this->send(
            $toEmail,
            $this->appName . " - A route in your tour \"$tourName\" was deleted",
            "The route \"$routeName\", which was part of your tour \"$tourName\", has just been deleted.\n" .
            "Please check your tour and remove it if it's no longer needed."
        );
    }

    public function sendRouteAutoUnpublished(string $toEmail, string $routeName): void
    {
        $this->send(
            $toEmail,
            $this->appName . ' - Your public route was deleted',
            "Your public route \"$routeName\" has been deleted. Because it was part of one or more tours, " .
            "you're receiving this separate notice: it is no longer publicly visible."
        );
    }

    private function send(string $toEmail, string $subject, string $bodyText): void
    {
        $this->buildMailer($toEmail, $subject, $bodyText)->send();
    }

    /**
     * Builds and configures the PHPMailer instance but never calls send()
     * itself - split out from send() purely so a test can inspect the
     * composed message (via PHPMailer's own preSend()/getSentMIMEMessage(),
     * which never touch the network) without needing a real SMTP server.
     *
     * PHPMailer defaults CharSet to iso-8859-1 and Encoding to 8bit -
     * everything this app sends (subjects/bodies built from PHP string
     * literals, translated text, and user-supplied values like tour/route/
     * user names) is UTF-8, so left at the default, any non-ASCII character
     * (German umlauts/ß in particular) was declared as the wrong charset
     * and rendered incorrectly in the recipient's mail client. CharSet is
     * set explicitly to UTF-8; Encoding to quoted-printable, which PHPMailer's
     * own docs recommend pairing with UTF-8 for content that's mostly plain
     * ASCII with occasional multi-byte characters (this app's emails, e.g.
     * "the route <RouteNameWithUmlaut> was changed") - it stays readable
     * even opened as raw source, unlike base64.
     */
    private function buildMailer(string $toEmail, string $subject, string $bodyText): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
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

        return $mail;
    }
}
