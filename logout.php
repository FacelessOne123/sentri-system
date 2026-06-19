<?php
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
session_unset();
session_destroy();
header("Location: index.php");
exit;
?>
