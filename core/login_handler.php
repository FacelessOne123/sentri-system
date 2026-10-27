<?php
// core/login_handler.php - Shared sign-in POST handler
//
// Used by the role-specific sign-in pages so every portal shares ONE copy of
// the authentication logic (lockout, flagged attempts, role checks, logs):
//   login.php      -> community portal only
//   gov/login.php  -> barangay / LGU / first_responder (admin-provisioned)
//   admin.php      -> admin panel sign-in
//
// The including page MUST define, BEFORE requiring this file:
//   $SENTRI_PORTALS = [
//       'portal-key' => ['allowed','roles'],   // whitelist per portal card
//       ...
//   ];
// Only the portal keys listed are accepted on that page; any other value is
// rejected, so a stale form can never authenticate against the wrong portal.
//
// The page must also have, before the require:
//   - session started
//   - config/db.php, config/auth.php required

if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

if (empty($SENTRI_PORTALS) || !is_array($SENTRI_PORTALS)) {
 $SENTRI_PORTALS = ['community' => ['community', 'user']];
}

function getFailedAttemptsCount($conn, $email, $minutes = 30) {
 $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
 $stmt = $conn->prepare("SELECT COUNT(*) FROM login_logs WHERE email = ? AND status = 'Failed' AND created_at >= ?");
 $stmt->bind_param("ss", $email, $cutoff);
 $stmt->execute();
 $stmt->bind_result($count);
 $stmt->fetch();
 $stmt->close();
 return (int)$count;
}

function isLockedOut($conn, $email) {
 $stmt = $conn->prepare("SELECT COUNT(*) FROM login_logs WHERE email = ? AND status = 'Locked' AND created_at >= NOW() - INTERVAL 30 SECOND");
 $stmt->bind_param("s", $email);
 $stmt->execute();
 $stmt->bind_result($count);
 $stmt->fetch();
 $stmt->close();
 return $count > 0;
}

function flagHighRiskAttempt($conn, $email) {
 $failed_count = getFailedAttemptsCount($conn, $email, 30);
 if ($failed_count >= 5) {
 $user_id = 0;
 $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
 $stmt->bind_param("s", $email);
 $stmt->execute();
 $stmt->bind_result($user_id);
 $stmt->fetch();
 $stmt->close();

 $stmt = $conn->prepare("INSERT INTO flagged_accounts (user_id, risk_level, failed_count, last_attempt, notes, reviewed) VALUES (?, 'high', ?, NOW(), 'Multiple failed login attempts detected', 0) ON DUPLICATE KEY UPDATE risk_level = 'high', failed_count = ?, last_attempt = NOW(), reviewed = 0");
 $stmt->bind_param("iii", $user_id, $failed_count, $failed_count);
 $stmt->execute();
 $stmt->close();

 $stmt = $conn->prepare("SELECT COUNT(*) FROM login_logs WHERE email = ? AND status = 'Locked' AND created_at >= NOW() - INTERVAL 30 SECOND");
 $stmt->bind_param("s", $email);
 $stmt->execute();
 $stmt->bind_result($lock_count);
 $stmt->fetch();
 $stmt->close();

 if ($lock_count === 0) {
 $stmt = $conn->prepare("INSERT INTO login_logs (user_id, email, ip_address, device, status) VALUES (?, ?, 'Lockout', 'System', 'Locked')");
 $stmt->bind_param("is", $user_id, $email);
 $stmt->execute();
 $stmt->close();
 }

 return true;
 }

 return false;
}

function sendLoginOtpEmail($email, $name, $otp) {
 require_once __DIR__ . '/SenTriMailer.php';
 $subject = 'Your SenTri login code';
 $html = '<div style="font-family:Arial,sans-serif;color:#111;line-height:1.6;">'
 . '<h2 style="color:#1a1a2e;">Your SenTri login code</h2>'
 . '<p>Use the code below to complete your SenTri sign in.</p>'
 . '<p style="font-size:2rem;font-weight:700;margin:18px 0;letter-spacing:0.2rem;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>'
 . '<p style="margin-top:10px;color:#555;">This code expires in 10 minutes. Do not share it with anyone.</p>'
 . '<hr style="border:none;border-top:1px solid #eee;margin:22px 0;">'
 . '<p style="font-size:0.9rem;color:#777;">If you did not request this code, ignore this email.</p>'
 . '</div>';
 try {
 $mailer = new SenTriMailer();
 return $mailer->send($email, $name, $subject, $html);
 } catch (Throwable $e) {
 error_log('OTP email error: ' . $e->getMessage());
 return false;
 }
}

header('Content-Type: application/json');
$action = trim($_POST['action'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');
$portal = trim($_POST['portal'] ?? array_key_first($SENTRI_PORTALS));
$ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$device = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

if ($action === 'verify_otp') {
 $otp_code = trim($_POST['otp'] ?? '');
 $pending = $_SESSION['otp_login'] ?? null;
 if (!$pending || empty($pending['email']) || empty($pending['code']) || empty($pending['expires_at'])) {
 echo json_encode(['status' => 'error', 'message' => 'No OTP session found. Please sign in again.']);
 exit;
 }
 if ($pending['email'] !== $email) {
 echo json_encode(['status' => 'error', 'message' => 'OTP email mismatch. Please use the same email.']);
 exit;
 }
 if (new DateTime() > new DateTime($pending['expires_at'])) {
 unset($_SESSION['otp_login']);
 echo json_encode(['status' => 'error', 'message' => 'OTP expired. Please sign in again.']);
 exit;
 }
 if ($otp_code !== $pending['code']) {
 echo json_encode(['status' => 'error', 'message' => 'Invalid OTP. Please try again.']);
 exit;
 }

 $stmt = $conn->prepare("SELECT id, first_name, last_name, role, email_verified, is_approved FROM users WHERE id = ? LIMIT 1");
 $stmt->bind_param("i", $pending['user_id']);
 $stmt->execute();
 $user = $stmt->get_result()->fetch_assoc();
 $stmt->close();

 if (!$user) {
 unset($_SESSION['otp_login']);
 echo json_encode(['status' => 'error', 'message' => 'User not found.']);
 exit;
 }

 $stmt = $conn->prepare("INSERT INTO login_logs(user_id,email,ip_address,device,status) VALUES (?,?,?,?,?)");
 $successStatus = 'Success';
 $stmt->bind_param("issss", $user['id'], $email, $ip, $device, $successStatus);
 $stmt->execute();
 $stmt->close();

 session_regenerate_id(true);
 $_SESSION = [
 'user_id' => $user['id'],
 'first_name' => $user['first_name'],
 'last_name' => $user['last_name'],
 'role' => $user['role'],
 'is_approved'=> (bool)$user['is_approved'],
 ];
 unset($_SESSION['otp_login']);

 echo json_encode(['status' => 'success', 'redirect' => portal_url($user['role']), 'message' => 'OTP verified. Signing in...']);
 exit;
}

// ── Auto-migrate: keeps schema current before any SELECT on new columns ──────
$db = $conn->query("SELECT DATABASE()")->fetch_row()[0];
$cols = [];
$cr = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users'");
while ($r = $cr->fetch_row()) $cols[] = $r[0];
$migrations = [
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_token VARCHAR(64) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS token_expires_at DATETIME DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expires DATETIME DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_approved TINYINT(1) NOT NULL DEFAULT 0",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS force_password_reset TINYINT(1) NOT NULL DEFAULT 0",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_number VARCHAR(30) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS org_name VARCHAR(255) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS `position` VARCHAR(150) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS barangay_name VARCHAR(150) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS municipality VARCHAR(150) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS responder_type VARCHAR(30) DEFAULT NULL",
];
foreach ($migrations as $q) $conn->query($q);
if (!in_array('email_verified',$cols)) $conn->query("UPDATE users SET email_verified=1 WHERE created_at < NOW()");
if (!in_array('is_approved',$cols)) { $conn->query("UPDATE users SET is_approved=1 WHERE role='community' OR role='user' OR role='admin'"); }
$enumRes = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users' AND COLUMN_NAME='role'");
if ($enumRes) { $enumRow=$enumRes->fetch_row(); if($enumRow && strpos($enumRow[0],'first_responder')===false) $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('user','community','barangay','lgu','first_responder','admin') NOT NULL DEFAULT 'community'"); }

if ($portal === 'resend') {
 $stmt = $conn->prepare("SELECT id,first_name,email_verified FROM users WHERE email=? LIMIT 1");
 $stmt->bind_param("s",$email); $stmt->execute();
 $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
 if ($user && !$user['email_verified']) {
 $tok = bin2hex(random_bytes(32)); $exp = date('Y-m-d H:i:s', strtotime('+24 hours'));
 $u = $conn->prepare("UPDATE users SET verification_token=?,token_expires_at=? WHERE id=?");
 $u->bind_param("ssi",$tok,$exp,$user['id']); $u->execute(); $u->close();
 try { require_once __DIR__.'/SenTriMailer.php'; sendVerificationEmail($email,$user['first_name'],$tok); } catch(Throwable $e){ error_log($e->getMessage()); }
 }
 echo json_encode(['status'=>'success','message'=>'If that account exists and is unverified, a new link has been sent.']); exit;
}

// ── Portal whitelist: only portals defined on THIS page may be used here ────
if (!array_key_exists($portal, $SENTRI_PORTALS)) {
 echo json_encode(['status'=>'error','message'=>'This portal is not available on this sign-in page. Please use the correct sign-in page for your account.']);
 exit;
}
$allowed = $SENTRI_PORTALS[$portal];

if (empty($email)||empty($password)) { echo json_encode(['status'=>'error','message'=>'All fields are required.']); exit; }

if (isLockedOut($conn, $email)) {
 echo json_encode(['status'=>'error','message'=>'Too many failed attempts. Please wait 30 seconds before trying again.','lockout'=>true]);
 exit;
}

$stmt = $conn->prepare("SELECT id,first_name,last_name,password,role,email_verified,is_approved,force_password_reset FROM users WHERE email=? LIMIT 1");
$stmt->bind_param("s",$email); $stmt->execute(); $stmt->store_result();
$log_status='Failed'; $log_uid=null; $resp=['status'=>'error','message'=>'Invalid credentials.'];
$is_successful_login = false;

if ($stmt->num_rows > 0) {
 $stmt->bind_result($id,$fn,$ln,$hash,$role,$verified,$approved,$force_reset);
 $stmt->fetch();
 if (password_verify($password,$hash)) {
 if (!$verified && in_array($role, ['community','user'], true)) {
 $stmt->close();
 $lg=$conn->prepare("INSERT INTO login_logs(user_id,email,ip_address,device,status)VALUES(?,?,?,?,?)");
 $s='Failed'; $lg->bind_param("issss",$id,$email,$ip,$device,$s); $lg->execute(); $lg->close();
 echo json_encode(['status'=>'error','message'=>'Please verify your email before signing in.','unverified'=>true,'email'=>$email]); exit;
 }
 if (!in_array($role,$allowed,true)) {
 echo json_encode(['status'=>'error','message'=>'This account does not have access to the selected portal. Choose the correct portal for your role.']); exit;
 }
 if (!$approved && !in_array($role,['community','user','admin'])) {
 echo json_encode(['status'=>'error','message'=>'Your account is pending administrator approval. You will be notified once approved.','pending'=>true]); exit;
 }
 // First login on an admin-provisioned account: force a password change
 // before the session is created. Reuse the reset-token flow.
 $stmt->close();
 if (!empty($force_reset)) {
 $reset_tok = bin2hex(random_bytes(32));
 $u = $conn->prepare("UPDATE users SET reset_token=?, reset_token_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?");
 $u->bind_param("si", $reset_tok, $id); $u->execute(); $u->close();
 $lg=$conn->prepare("INSERT INTO login_logs(user_id,email,ip_address,device,status)VALUES(?,?,?,?,?)");
 $lg->bind_param("issss",$id,$email,$ip,$device,$log_status); $lg->execute(); $lg->close();
 echo json_encode(['status'=>'must_reset','message'=>'For security, you must set a new password before signing in.','redirect'=>'/reset_password.php?token='.$reset_tok]);
 exit;
 }
 session_regenerate_id(true);
 $_SESSION = ['user_id'=>$id,'first_name'=>$fn,'last_name'=>$ln,'role'=>$role,'is_approved'=>(bool)$approved];
 $log_status='Success'; $log_uid=$id;
 $is_successful_login = true;
 require_once __DIR__.'/../config/auth.php';
 $resp=['status'=>'success','redirect'=>portal_url($role),'message'=>'Welcome, '.htmlspecialchars($fn).'!'];
 }
}
if (!$is_successful_login) $stmt->close();

$lg=$conn->prepare("INSERT INTO login_logs(user_id,email,ip_address,device,status)VALUES(?,?,?,?,?)");
$lg->bind_param("issss",$log_uid,$email,$ip,$device,$log_status); $lg->execute(); $lg->close();

if (!$is_successful_login) {
 $lockout = flagHighRiskAttempt($conn, $email);
 if ($lockout) {
 $resp = ['status'=>'error','message'=>'Too many failed attempts. Please wait 30 seconds before trying again.','lockout'=>true];
 }
}

echo json_encode($resp); exit;
