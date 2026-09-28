<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| PHPMailer
|--------------------------------------------------------------------------
*/

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;


/*
|--------------------------------------------------------------------------
| Load PHPMailer
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../vendor/autoload.php';


/*
|--------------------------------------------------------------------------
| Configure SMTP
|--------------------------------------------------------------------------
*/

function configure_blackthorne_mailer(
    PHPMailer $mail
): void {

    $mail->isSMTP();

    $mail->Host =
        MAIL_HOST;

    $mail->SMTPAuth =
        true;

    $mail->Username =
        MAIL_USERNAME;

    $mail->Password =
        MAIL_PASSWORD;

    $mail->Port =
        MAIL_PORT;


    if (
        MAIL_ENCRYPTION
        === 'ssl'
    ) {

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_SMTPS;

    } else {

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;

    }


    $mail->CharSet =
        'UTF-8';


    $mail->setFrom(
        MAIL_FROM_ADDRESS,
        MAIL_FROM_NAME
    );

}


/*
|--------------------------------------------------------------------------
| Contact Form Email
|--------------------------------------------------------------------------
*/

function send_contact_message(
    string $name,
    string $email,
    string $username,
    string $subjectLabel,
    string $message
): bool {

    $mail =
        new PHPMailer(
            true
        );


    try {

        configure_blackthorne_mailer(
            $mail
        );


        /*
        |--------------------------------------------------------------------------
        | Recipient
        |--------------------------------------------------------------------------
        */

        $mail->addAddress(
            CONTACT_RECIPIENT,
            'Blackthorne Academy Administration'
        );


        /*
         * Replies go directly to the person who submitted the form.
         */

        $mail->addReplyTo(
            $email,
            $name
        );


        /*
        |--------------------------------------------------------------------------
        | Subject
        |--------------------------------------------------------------------------
        */

        $mail->Subject =
            'Blackthorne Contact: '
            . $subjectLabel;


        /*
        |--------------------------------------------------------------------------
        | Safe HTML Values
        |--------------------------------------------------------------------------
        */

        $safeName =
            htmlspecialchars(
                $name,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        $safeEmail =
            htmlspecialchars(
                $email,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        $safeUsername =
            htmlspecialchars(
                $username,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        $safeSubject =
            htmlspecialchars(
                $subjectLabel,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        $safeMessage =
            nl2br(
                htmlspecialchars(
                    $message,
                    ENT_QUOTES
                    |
                    ENT_SUBSTITUTE,
                    'UTF-8'
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Optional Username Row
        |--------------------------------------------------------------------------
        */

        $usernameRow =
            '';


        if (
            $username !== ''
        ) {

            $usernameRow = <<<HTML

<tr>

<td
    style="
        padding:8px 0;
        color:#9f929e;
        width:150px;
        vertical-align:top;
    "
>
Academy Username
</td>

<td
    style="
        padding:8px 0;
        color:#e9e0e8;
        vertical-align:top;
    "
>
{$safeUsername}
</td>

</tr>

HTML;

        }


        /*
        |--------------------------------------------------------------------------
        | HTML Email
        |--------------------------------------------------------------------------
        */

        $htmlBody = <<<HTML
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
Blackthorne Academy Correspondence
</title>

</head>


<body
    style="
        margin:0;
        padding:0;
        background:#100713;
        color:#e9e0e8;
        font-family:Arial,Helvetica,sans-serif;
    "
>


<table
    role="presentation"
    width="100%"
    cellspacing="0"
    cellpadding="0"
    border="0"
    style="
        width:100%;
        background:#100713;
        padding:32px 16px;
    "
>

<tr>

<td align="center">


<table
    role="presentation"
    width="600"
    cellspacing="0"
    cellpadding="0"
    border="0"
    style="
        width:100%;
        max-width:600px;
        background:#190c20;
        border:1px solid #6f5b33;
    "
>


<tr>

<td
    style="
        padding:38px 42px 22px;
        text-align:center;
        border-bottom:1px solid #3a2840;
    "
>


<p
    style="
        margin:0 0 10px;
        color:#c4a15a;
        font-size:12px;
        font-weight:bold;
        letter-spacing:2px;
        text-transform:uppercase;
    "
>
Blackthorne Academy
</p>


<h1
    style="
        margin:0;
        color:#dbc17d;
        font-family:Georgia,'Times New Roman',serif;
        font-size:30px;
        font-weight:normal;
    "
>
Academy Correspondence
</h1>


</td>

</tr>


<tr>

<td
    style="
        padding:32px 42px;
    "
>


<table
    role="presentation"
    width="100%"
    cellspacing="0"
    cellpadding="0"
    border="0"
>


<tr>

<td
    style="
        padding:8px 0;
        color:#9f929e;
        width:150px;
        vertical-align:top;
    "
>
Name
</td>


<td
    style="
        padding:8px 0;
        color:#e9e0e8;
        vertical-align:top;
    "
>
{$safeName}
</td>

</tr>


<tr>

<td
    style="
        padding:8px 0;
        color:#9f929e;
        width:150px;
        vertical-align:top;
    "
>
Email
</td>


<td
    style="
        padding:8px 0;
        color:#e9e0e8;
        vertical-align:top;
    "
>
{$safeEmail}
</td>

</tr>


{$usernameRow}


<tr>

<td
    style="
        padding:8px 0;
        color:#9f929e;
        width:150px;
        vertical-align:top;
    "
>
Subject
</td>


<td
    style="
        padding:8px 0;
        color:#e9e0e8;
        vertical-align:top;
    "
>
{$safeSubject}
</td>

</tr>


</table>


<div
    style="
        margin-top:28px;
        padding-top:24px;
        border-top:1px solid #3a2840;
    "
>


<p
    style="
        margin:0 0 10px;
        color:#c4a15a;
        font-size:12px;
        font-weight:bold;
        letter-spacing:1.5px;
        text-transform:uppercase;
    "
>
Message
</p>


<div
    style="
        color:#c8bec5;
        font-size:15px;
        line-height:1.75;
    "
>
{$safeMessage}
</div>


</div>


</td>

</tr>


<tr>

<td
    style="
        padding:20px 42px;
        border-top:1px solid #3a2840;
        color:#786d78;
        font-size:11px;
        line-height:1.6;
        text-align:center;
    "
>
This message was submitted through the Blackthorne Academy contact form.
</td>

</tr>


</table>


</td>

</tr>

</table>


</body>

</html>
HTML;


        /*
        |--------------------------------------------------------------------------
        | Plain Text
        |--------------------------------------------------------------------------
        */

        $plainUsername =
            $username !== ''
                ? "\nAcademy Username: {$username}"
                : '';


        $plainBody =
            "BLACKTHORNE ACADEMY\n"
            . "Academy Correspondence\n\n"
            . "Name: {$name}\n"
            . "Email: {$email}"
            . $plainUsername
            . "\nSubject: {$subjectLabel}\n\n"
            . "Message:\n"
            . $message;


        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $mail->isHTML(
            true
        );


        $mail->Body =
            $htmlBody;


        $mail->AltBody =
            $plainBody;


        return
            $mail->send();


    } catch (Exception $exception) {

        error_log(
            'Blackthorne contact mail error: '
            . $mail->ErrorInfo
        );


        return false;

    }

}


/*
|--------------------------------------------------------------------------
| Account Verification Email
|--------------------------------------------------------------------------
*/

function send_verification_email(
    string $name,
    string $email,
    string $verificationUrl
): bool {

    $mail =
        new PHPMailer(
            true
        );


    try {

        configure_blackthorne_mailer(
            $mail
        );


        /*
        |--------------------------------------------------------------------------
        | Recipient
        |--------------------------------------------------------------------------
        */

        $mail->addAddress(
            $email,
            $name
        );


        /*
        |--------------------------------------------------------------------------
        | Subject
        |--------------------------------------------------------------------------
        */

        $mail->Subject =
            'Verify Your Blackthorne Academy Account';


        /*
        |--------------------------------------------------------------------------
        | Safe HTML Values
        |--------------------------------------------------------------------------
        */

        $safeName =
            htmlspecialchars(
                $name,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        $safeVerificationUrl =
            htmlspecialchars(
                $verificationUrl,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        $safeFromAddress =
            htmlspecialchars(
                MAIL_FROM_ADDRESS,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        /*
        |--------------------------------------------------------------------------
        | Manual Verification Code
        |--------------------------------------------------------------------------
        |
        | Registration already creates a secure 64-character token and stores
        | only its SHA-256 hash. Pull the raw token from the verification URL so
        | the same credential can also be entered manually when an email app
        | refuses to open links.
        |
        */

        $verificationCode =
            '';


        $verificationQuery =
            parse_url(
                $verificationUrl,
                PHP_URL_QUERY
            );


        if (
            is_string(
                $verificationQuery
            )
            &&
            $verificationQuery !== ''
        ) {

            $verificationParameters =
                [];


            parse_str(
                $verificationQuery,
                $verificationParameters
            );


            if (
                isset(
                    $verificationParameters['token']
                )
                &&
                is_string(
                    $verificationParameters['token']
                )
                &&
                preg_match(
                    '/^[a-f0-9]{64}$/i',
                    $verificationParameters['token']
                )
            ) {

                $verificationCode =
                    strtoupper(
                        $verificationParameters['token']
                    );

            }

        }


        $formattedVerificationCode =
            $verificationCode !== ''
                ? implode(
                    ' ',
                    str_split(
                        $verificationCode,
                        8
                    )
                )
                : '';


        $safeVerificationCode =
            htmlspecialchars(
                $formattedVerificationCode,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        $manualVerificationUrl =
            url(
                'verify.php'
            );


        $safeManualVerificationUrl =
            htmlspecialchars(
                $manualVerificationUrl,
                ENT_QUOTES
                |
                ENT_SUBSTITUTE,
                'UTF-8'
            );


        $manualVerificationHtml =
            '';


        if (
            $safeVerificationCode !== ''
        ) {

            $manualVerificationHtml = <<<HTML

<!-- Manual Verification Code -->

<div
    style="
        margin:24px 0 0;
        padding:18px;
        background:#100713;
        border:1px solid #6f5b33;
    "
>

<p
    style="
        margin:0 0 8px;
        color:#dbc17d;
        font-size:13px;
        font-weight:bold;
        line-height:1.6;
        text-align:center;
    "
>
Verification Code
</p>


<p
    style="
        margin:0 0 12px;
        color:#9f929e;
        font-size:12px;
        line-height:1.65;
        text-align:center;
    "
>
If your email app will not open the button or link, copy the code below.
Then open the Blackthorne Academy verification page in your browser and
paste the code into the manual verification form.
</p>


<div
    style="
        margin:14px 0;
        padding:14px 10px;
        background:#190c20;
        border:1px solid #3a2840;
        color:#e9e0e8;
        font-family:Consolas,'Courier New',monospace;
        font-size:13px;
        font-weight:bold;
        line-height:1.8;
        letter-spacing:0.6px;
        text-align:center;
        word-break:break-word;
    "
>
{$safeVerificationCode}
</div>


<p
    style="
        margin:0;
        color:#786d78;
        font-size:11px;
        line-height:1.65;
        text-align:center;
        word-break:break-all;
    "
>
Manual verification page:<br>
{$safeManualVerificationUrl}
</p>

</div>

HTML;

        }


        /*
        |--------------------------------------------------------------------------
        | HTML Email
        |--------------------------------------------------------------------------
        */

        $htmlBody = <<<HTML
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
Verify Your Blackthorne Academy Account
</title>

</head>


<body
    style="
        margin:0;
        padding:0;
        background:#100713;
        color:#e9e0e8;
        font-family:Arial,Helvetica,sans-serif;
    "
>


<table
    role="presentation"
    width="100%"
    cellspacing="0"
    cellpadding="0"
    border="0"
    style="
        width:100%;
        background:#100713;
        padding:32px 14px;
    "
>

<tr>

<td align="center">


<table
    role="presentation"
    width="600"
    cellspacing="0"
    cellpadding="0"
    border="0"
    style="
        width:100%;
        max-width:600px;
        background:#190c20;
        border:1px solid #6f5b33;
    "
>


<tr>

<td
    style="
        padding:40px 32px 22px;
        text-align:center;
    "
>


<p
    style="
        margin:0 0 10px;
        color:#c4a15a;
        font-size:12px;
        font-weight:bold;
        letter-spacing:2px;
        text-transform:uppercase;
    "
>
Blackthorne Academy
</p>


<h1
    style="
        margin:0;
        color:#dbc17d;
        font-family:Georgia,'Times New Roman',serif;
        font-size:32px;
        line-height:1.2;
        font-weight:normal;
    "
>
The gates are waiting.
</h1>


</td>

</tr>


<tr>

<td
    style="
        padding:14px 32px 40px;
    "
>


<p
    style="
        margin:0 0 20px;
        color:#ddd2dc;
        font-size:16px;
        line-height:1.7;
    "
>
Welcome to Blackthorne, {$safeName}.
</p>


<p
    style="
        margin:0 0 24px;
        color:#c8bec5;
        font-size:15px;
        line-height:1.75;
    "
>
Your academy account has been created, but one final step remains.
Verify your email address to activate your account and enter the academy.
</p>


<!-- Verification Button -->

<p
    style="
        margin:32px 0;
        text-align:center;
    "
>
<a
    href="{$safeVerificationUrl}"
    style="
        display:inline-block;
        padding:15px 30px;
        background:#c4a15a;
        color:#100713;
        font-family:Arial,Helvetica,sans-serif;
        font-size:14px;
        line-height:20px;
        font-weight:bold;
        text-decoration:none;
        border-radius:3px;
    "
>
Verify My Academy Account
</a>
</p>


<p
    style="
        margin:28px 0 8px;
        color:#9f929e;
        font-size:13px;
        line-height:1.65;
    "
>
This verification link expires in 24 hours.
</p>


<p
    style="
        margin:0 0 22px;
        color:#786d78;
        font-size:12px;
        line-height:1.65;
    "
>
If the button does not open the verification page, tap the link below.
You can also copy and paste it into your browser.
</p>


<!-- Clickable Fallback URL -->

<div
    style="
        margin:0;
        padding:14px;
        background:#100713;
        border:1px solid #3a2840;
        font-size:11px;
        line-height:1.6;
        word-break:break-all;
        overflow-wrap:anywhere;
    "
>

<a
    href="{$safeVerificationUrl}"
    target="_blank"
    rel="noopener noreferrer"
    style="
        color:#dbc17d;
        text-decoration:underline;
        word-break:break-all;
        overflow-wrap:anywhere;
    "
>
{$safeVerificationUrl}
</a>

</div>


{$manualVerificationHtml}


<div
    style="
        margin:24px 0 0;
        padding:16px 18px;
        background:#140919;
        border:1px solid #3a2840;
    "
>

<p
    style="
        margin:0 0 8px;
        color:#dbc17d;
        font-size:12px;
        font-weight:bold;
        line-height:1.6;
    "
>
Keep Blackthorne messages out of Spam
</p>


<p
    style="
        margin:0;
        color:#9f929e;
        font-size:12px;
        line-height:1.7;
    "
>
If this message was delivered to Spam or Junk, mark it as
<strong style="color:#c8bec5;">Not Spam</strong>
before opening the verification link. Some mobile email apps may disable
links while a message is treated as suspicious. To help future Blackthorne
Academy messages reach your inbox, add
<strong style="color:#c8bec5;">{$safeFromAddress}</strong>
to your contacts or safe-sender list.
</p>

</div>


<p
    style="
        margin:24px 0 0;
        color:#786d78;
        font-size:12px;
        line-height:1.65;
    "
>
If you did not create this account, you may safely ignore this message.
</p>


</td>

</tr>


<tr>

<td
    style="
        padding:20px 32px;
        border-top:1px solid #3a2840;
        color:#786d78;
        font-size:11px;
        line-height:1.6;
        text-align:center;
    "
>
Blackthorne Academy
</td>

</tr>


</table>


</td>

</tr>

</table>


</body>

</html>
HTML;


        /*
        |--------------------------------------------------------------------------
        | Plain Text Verification Email
        |--------------------------------------------------------------------------
        */

        $plainBody =
            "BLACKTHORNE ACADEMY\n\n"
            . "Welcome to Blackthorne, {$name}.\n\n"
            . "Your academy account has been created, but one final step remains.\n"
            . "Verify your email address to activate your account:\n\n"
            . $verificationUrl
            . "\n\n"
            . (
                $formattedVerificationCode !== ''
                    ? "MANUAL VERIFICATION CODE:\n"
                        . $formattedVerificationCode
                        . "\n\n"
                        . "Manual verification page:\n"
                        . $manualVerificationUrl
                        . "\n\n"
                    : ''
            )
            . "This verification link expires in 24 hours.\n\n"
            . "If this message was delivered to Spam or Junk, mark it as Not Spam before opening the verification link. "
            . "To help future Blackthorne Academy messages reach your inbox, add "
            . MAIL_FROM_ADDRESS
            . " to your contacts or safe-sender list.\n\n"
            . "If you did not create this account, you may safely ignore this message.";


        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $mail->isHTML(
            true
        );


        $mail->Body =
            $htmlBody;


        $mail->AltBody =
            $plainBody;


        return
            $mail->send();


    } catch (Exception $exception) {

        error_log(
            'Blackthorne verification mail error: '
            . $mail->ErrorInfo
        );


        return false;

    }

}