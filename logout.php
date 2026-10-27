<?php
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
$was_role = $_SESSION['role'] ?? 'community';
session_unset();
session_destroy();
// Return each user to the sign-in page for their portal
switch ($was_role) {
 case 'barangay':
 case 'lgu':
 case 'first_responder': header("Location: gov/login.php"); break;
 case 'admin': header("Location: admin.php"); break;
 default: header("Location: login.php"); break;
}
exit;
?>
