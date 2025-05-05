<?php
/**
 * Simple PHP Mailer Script for Static Site Forms
 *
 * This script processes form submissions from a static HTML site
 * and sends the data via PHP's mail() function.
 */
// error_log('PHP Mailer DEBUG: sendmail.php script started.'); // Removed log

// --- Configuration ---
$config_included = @include 'mailer-config.php';
if (!$config_included) {
    error_log('PHP Mailer ERROR: mailer-config.php could not be included.'); // Keep this error log
    die('Server configuration error (config include). Please contact the site administrator.');
}
// error_log('PHP Mailer DEBUG: mailer-config.php included.'); // Removed log

// --- Basic Validation & Setup ---

// Check if recipient email is configured and not the placeholder
if ( ! isset( $recipient_email ) || empty( $recipient_email ) || $recipient_email === 'your-email-here@example.com' ) {
    error_log( 'PHP Mailer ERROR: Recipient email is not configured in mailer-config.php. Value: ' . (isset($recipient_email) ? $recipient_email : 'Not Set') ); // Keep this error log
    die( 'Server configuration error (recipient). Please contact the site administrator.' );
}
// error_log('PHP Mailer DEBUG: Recipient email check passed: ' . $recipient_email); // Removed log

// Check if form was submitted via POST
if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
    // error_log('PHP Mailer ERROR: Invalid request method: ' . $_SERVER['REQUEST_METHOD']); // Removed log
    die( 'Invalid request method.' );
}
// error_log('PHP Mailer DEBUG: Request method is POST.'); // Removed log

// --- Process Form Data ---
$form_data = $_POST;
$email_body = "Form Submission Details:\n";
$form_location = 'Unknown Page';
$sender_email = null;

unset( $form_data['recipient_email'] );
unset( $form_data['default_subject'] );
unset( $form_data['default_from_address'] );

if (isset($form_data['_form_location'])) {
    $form_location = htmlspecialchars($form_data['_form_location'], ENT_QUOTES, 'UTF-8');
    unset($form_data['_form_location']);
    $email_body .= "Submitted From: " . $form_location . "\n";
}
$email_body .= "--------------------------\n\n";


foreach ( $form_data as $field_name => $field_value ) {
    $clean_field_name = htmlspecialchars( $field_name, ENT_QUOTES, 'UTF-8' );
    if ( is_array( $field_value ) ) {
        $clean_field_value = htmlspecialchars( implode(', ', $field_value), ENT_QUOTES, 'UTF-8' );
    } else {
        $clean_field_value = htmlspecialchars( $field_value, ENT_QUOTES, 'UTF-8' );
    }
    $email_body .= $clean_field_name . ": " . $clean_field_value . "\n";
    if ( $sender_email === null && !is_array($field_value) && in_array( strtolower( $field_name ), [ 'email', 'your-email', 'sender_email', 'email_address' ] ) && filter_var( $field_value, FILTER_VALIDATE_EMAIL ) ) {
        $sender_email = $field_value;
    }
}
// error_log('PHP Mailer DEBUG: Form data processed.'); // Removed log
// error_log('PHP Mailer DEBUG: Email Body: ' . substr(str_replace("\n", " | ", $email_body), 0, 200) . '...'); // Removed log

// --- Prepare Email ---
$subject = isset( $default_subject ) ? $default_subject : 'Form Submission from ' . $form_location;
$from_address = isset( $default_from_address ) ? $default_from_address : null;
if (!$from_address && $sender_email) { $from_address = $sender_email; }
elseif (!$from_address) { $from_address = 'noreply@' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'example.com'); }
$headers = "From: " . $from_address . "\r\n";
$headers .= "Reply-To: " . ($sender_email ? $sender_email : $from_address) . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// error_log('PHP Mailer DEBUG: Preparing to send email.'); // Removed log
// error_log('  - To: ' . $recipient_email); // Removed log
// error_log('  - Subject: ' . $subject); // Removed log
// error_log('  - From Header: ' . $from_address); // Removed log
// error_log('  - Reply-To Header: ' . ($sender_email ? $sender_email : $from_address)); // Removed log

// --- Send Email ---
// error_log('PHP Mailer DEBUG: Calling mail() function...'); // Removed log
$mail_sent = mail( $recipient_email, $subject, $email_body, $headers );
// error_log('PHP Mailer DEBUG: mail() function returned: ' . ($mail_sent ? 'true' : 'false')); // Removed log

// --- Provide Feedback ---
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
$redirect_url = $referer ? strtok($referer, '?') : 'index.html';

if ( $mail_sent ) {
    // error_log('PHP Mailer INFO: Email sent successfully. Redirecting to ' . $redirect_url . '?status=success'); // Removed log
    header( 'Location: ' . $redirect_url . '?status=success' );
    exit;
} else {
    error_log( 'PHP Mailer ERROR: mail() function failed to send. Redirecting to ' . $redirect_url . '?status=error' ); // Keep this error log
    header( 'Location: ' . $redirect_url . '?status=error' );
    exit;
}

?>
