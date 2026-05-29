<?php
$servername = "localhost";
$db_user    = "root";
$db_pass    = "";
$dbname     = "sentri";

// Try Unix socket first, fallback to TCP
$conn = @new mysqli(null, $db_user, $db_pass, $dbname, 3306, '/var/run/mysqld/mysqld.sock');
if ($conn->connect_error) {
    $conn = new mysqli($servername, $db_user, $db_pass, $dbname);
}
if ($conn->connect_error) {
    error_log("SenTri DB Error: " . $conn->connect_error);
    die(json_encode(['status'=>'error','message'=>'Database connection failed.']));
}
$conn->set_charset("utf8mb4");
?>
