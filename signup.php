<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }
require __DIR__ . '/config/db.php';

// ── Auto-migrate: runs on every request (before POST check) ──────────────────
// Ensures schema is up-to-date before any INSERT attempt.
try {
 $db = $conn->query("SELECT DATABASE()")->fetch_row()[0];
 $existingCols = [];
 $colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users'");
 while ($r = $colRes->fetch_row()) $existingCols[] = $r[0];
 $colRes->free();

 if (!in_array('email_verified', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
 if (!in_array('verification_token', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN verification_token VARCHAR(64) DEFAULT NULL AFTER email_verified");
 if (!in_array('token_expires_at', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN token_expires_at DATETIME DEFAULT NULL AFTER verification_token");
 if (!in_array('reset_token', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL AFTER token_expires_at");
 if (!in_array('reset_token_expires', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN reset_token_expires DATETIME DEFAULT NULL AFTER reset_token");
 if (!in_array('force_password_reset', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN force_password_reset TINYINT(1) NOT NULL DEFAULT 0");

 if (!in_array('email_verified', $existingCols))
 $conn->query("UPDATE users SET email_verified=1 WHERE created_at < NOW()");

 if (!in_array('phone_number', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN phone_number VARCHAR(30) DEFAULT NULL AFTER email");
 if (!in_array('org_name', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN org_name VARCHAR(255) DEFAULT NULL");
 if (!in_array('position', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN `position` VARCHAR(150) DEFAULT NULL");
 if (!in_array('barangay_name', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN barangay_name VARCHAR(150) DEFAULT NULL");
 if (!in_array('municipality', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN municipality VARCHAR(150) DEFAULT NULL");
 if (!in_array('is_approved', $existingCols)) {
 $conn->query("ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0");
 $conn->query("UPDATE users SET is_approved=1 WHERE role IN('user','community','admin')");
 }
 if (!in_array('responder_type', $existingCols))
 $conn->query("ALTER TABLE users ADD COLUMN responder_type VARCHAR(30) DEFAULT NULL");

 $enumRes = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users' AND COLUMN_NAME='role'");
 if ($enumRes) {
 $enumRow = $enumRes->fetch_row();
 $enumRes->free();
 if ($enumRow && strpos($enumRow[0], 'first_responder') === false) {
 $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('user','community','barangay','lgu','first_responder','admin') NOT NULL DEFAULT 'community'");
 }
 }

 // Flush any remaining multi-results from DDL
 while ($conn->more_results()) $conn->next_result();
} catch (Throwable $migErr) {
 error_log('SenTri migration error: ' . $migErr->getMessage());
}
// ─────────────────────────────────────────────────────────────────────────────

// NOTE: Government accounts (barangay / LGU / first responder) are no longer
// self-registered here. They are provisioned by the system administrator via
// the admin panel, and sign in at gov/login.php. This page creates
// community accounts only.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 ob_clean();
 header('Content-Type: application/json');
 try {

 $first = trim($_POST['first_name'] ?? '');
 $last = trim($_POST['last_name'] ?? '');
 $email = trim($_POST['email'] ?? '');
 $pw = $_POST['password'] ?? '';
 $pw2 = $_POST['confirm_password'] ?? '';
 $phone = trim($_POST['phone'] ?? '');

 if (empty($first)||empty($last)||empty($email)||empty($pw)) {
 echo json_encode(['status'=>'error','message'=>'All fields are required.']); exit;
 }
 if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
 echo json_encode(['status'=>'error','message'=>'Invalid email address.']); exit;
 }
 if (strlen($pw) < 8) {
 echo json_encode(['status'=>'error','message'=>'Password must be at least 8 characters.']); exit;
 }
 if ($pw !== $pw2) {
 echo json_encode(['status'=>'error','message'=>'Passwords do not match.']); exit;
 }

 $chk = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
 if (!$chk) { echo json_encode(['status'=>'error','message'=>'Database error. Please try again.']); exit; }
 $chk->bind_param("s", $email); $chk->execute(); $chk->store_result();
 if ($chk->num_rows > 0) {
 $chk->close();
 echo json_encode(['status'=>'error','message'=>'Email is already registered.']); exit;
 }
 $chk->close();

 $token = bin2hex(random_bytes(32));
 $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
 $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);

 $bind_phone = $phone ?: null;
 $bind_role = 'community';

 $ins = $conn->prepare(
 "INSERT INTO users
 (first_name, last_name, email, password, role, phone_number,
 is_approved, email_verified, verification_token, token_expires_at)
 VALUES (?,?,?,?,?, ?,1,0,?,?)"
 );
 if (!$ins) {
 echo json_encode(['status'=>'error','message'=>'Prepare error: '.$conn->error]); exit;
 }
 $ins->bind_param(
 "ssssssss",
 $first, $last, $email, $hash, $bind_role,
 $bind_phone, $token, $expires_at
 );
 if (!$ins->execute()) {
 echo json_encode(['status'=>'error','message'=>'Registration failed: '.$ins->error]); exit;
 }
 $ins->close();

 $emailSent = false;
 try {
 require_once __DIR__ . '/core/SenTriMailer.php';
 $emailSent = sendVerificationEmail($email, $first, $token);
 } catch (Throwable $e) {
 error_log('SenTri email error: ' . $e->getMessage());
 }

 $message = $emailSent
 ? 'Account created! Please check your inbox and verify your email before signing in.'
 : 'Account created! We had trouble sending the verification email - use the "Resend" option on the login page.';

 echo json_encode(['status'=>'success','message'=>$message,'redirect'=>'login.php?verify_sent=1']);
 exit;
 } catch (Throwable $e) {
 ob_clean();
 error_log('SenTri signup error: ' . $e->getMessage());
 echo json_encode(['status'=>'error','message'=>'Server error: ' . $e->getMessage()]);
 exit;
 }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Register | SenTri Community Portal</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<style>
:root{--navy:#0a3d62;--navy-dark:#062444;--gold:#f39c12;--green:#166534;--red:#b91c1c;--purple:#5b21b6;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--bg:#f1f5f9;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
body{background:var(--bg);min-height:100vh;display:flex;flex-direction:column;}
.gov-bar{background:#fff;border-bottom:4px solid var(--gold);padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 10px rgba(0,0,0,0.1);}
.gov-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.gov-seal{width:42px;height:42px;background:linear-gradient(135deg,var(--navy),#1a5276);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);border:2px solid var(--gold);}
.gov-text h1{font-size:1.1rem;font-weight:800;color:var(--navy);}
.gov-text p{font-size:0.7rem;color:var(--muted);font-weight:500;}
.gov-links{display:flex;gap:20px;}
.gov-links a{font-size:0.82rem;color:var(--navy);text-decoration:none;font-weight:600;opacity:0.8;}
.gov-links a:hover{opacity:1;}
.page-wrap{max-width:720px;margin:0 auto;padding:32px 20px 60px;width:100%;}
.page-header{text-align:center;margin-bottom:28px;}
.page-header h2{font-size:1.55rem;font-weight:800;color:var(--navy);margin-bottom:6px;}
.page-header p{font-size:0.88rem;color:var(--muted);}
.form-card{background:#fff;border-radius:18px;padding:32px 36px;box-shadow:0 4px 24px rgba(0,0,0,0.1);border:1px solid var(--border);}
.section-title{font-size:0.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:14px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:5px;}
.form-group input{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.9rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;color:var(--text);transition:all 0.18s;}
.form-group input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,0.08);}
.pw-wrap{position:relative;}
.pw-wrap button{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:0.75rem;font-weight:700;color:var(--muted);font-family:'Inter',sans-serif;}
.btn-register{width:100%;padding:12px;border:none;border-radius:11px;font-size:0.95rem;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;color:#fff;transition:all 0.2s;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;}
.btn-register:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 22px rgba(37,99,235,0.35);}
.btn-register:disabled{opacity:0.6;cursor:not-allowed;}
.msg{padding:10px 14px;border-radius:9px;font-size:0.83rem;font-weight:500;margin-bottom:14px;display:none;text-align:center;}
.msg.success{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0;}
.msg.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.login-row{text-align:center;margin-top:16px;font-size:0.85rem;color:var(--muted);}
.login-row a{color:#2563eb;font-weight:700;text-decoration:none;}
.gov-row{text-align:center;margin-top:6px;font-size:0.78rem;color:var(--muted);}
.gov-row a{color:var(--navy);font-weight:700;text-decoration:none;}
.terms{font-size:0.76rem;color:var(--muted);text-align:center;margin-top:10px;line-height:1.5;}
@media(max-width:640px){.form-row{grid-template-columns:1fr;}.form-card{padding:22px 18px;}.page-wrap{padding:20px 14px 50px;}.gov-links{display:none;}}
</style>
</head>
<body>
<header class="gov-bar">
 <a href="index.php" class="gov-brand">
 <div class="gov-seal"><i class="fas fa-shield-halved"></i></div>
 <div class="gov-text"><h1>SenTri</h1><p>Community Safety Incident Reporting System</p></div>
 </a>
 <nav class="gov-links">
 <a href="login.php">Sign In</a>
 <a href="gov/login.php">Government Portal</a>
 <a href="index.php">Home</a>
 </nav>
</header>

<div class="page-wrap">
 <div class="page-header">
 <h2>Create Your Community Account</h2>
 <p>Register as a citizen / resident to report incidents and follow community safety updates.</p>
 </div>

 <div class="form-card">
 <div id="regMsg" class="msg"></div>

 <form id="regForm" novalidate>
 <input type="hidden" name="role" value="community">

 <div class="section-title">Personal Information</div>
 <div class="form-row">
 <div class="form-group"><label>First Name</label><input type="text" name="first_name" placeholder="Juan" required></div>
 <div class="form-group"><label>Last Name</label><input type="text" name="last_name" placeholder="dela Cruz" required></div>
 </div>
 <div class="form-row">
 <div class="form-group"><label>Email Address</label><input type="email" name="email" placeholder="you@example.com" required></div>
 <div class="form-group"><label>Phone Number <span style="color:var(--muted);font-weight:400;">(optional)</span></label><input type="tel" name="phone" placeholder="+63 9XX XXX XXXX"></div>
 </div>
 <div class="form-row">
 <div class="form-group"><label>Password</label><div class="pw-wrap"><input type="password" name="password" id="pw1" placeholder="Min. 8 characters" required><button type="button" onclick="togglePw('pw1',this)">SHOW</button></div></div>
 <div class="form-group"><label>Confirm Password</label><div class="pw-wrap"><input type="password" name="confirm_password" id="pw2" placeholder="Re-enter password" required><button type="button" onclick="togglePw('pw2',this)">SHOW</button></div></div>
 </div>

 <div style="margin-top:20px;">
 <button type="submit" class="btn-register" id="regBtn"><i class="fas fa-user-plus"></i> <span id="regBtnLabel">Create Community Account</span></button>
 </div>
 </form>

 <div class="login-row">Already have an account? <a href="login.php">Sign in here</a></div>
 <div class="gov-row">Barangay official, LGU staff, or first responder? Government accounts are provisioned by the system administrator - <a href="gov/login.php">sign in at the Government Portal</a>.</div>
 <p class="terms">By registering you agree to use this system solely for legitimate community safety reporting. Misuse is subject to legal action under applicable Philippine laws.</p>
 </div>
</div>

<script>
function togglePw(id,btn){const f=document.getElementById(id);const s=f.type==='password';f.type=s?'text':'password';btn.textContent=s?'HIDE':'SHOW';}
document.getElementById('regForm').addEventListener('submit',async e=>{
 e.preventDefault();
 const btn=document.getElementById('regBtn');const msg=document.getElementById('regMsg');
 const orig=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Creating account...';
 msg.style.display='none';
 try{
 const res=await fetch('signup.php',{method:'POST',body:new FormData(e.target)});
 const data=await res.json();
 if(data.status==='success'){
 showMsg('success',data.message);
 setTimeout(()=>window.location.href=data.redirect,1800);
 }else{showMsg('error',data.message||'Registration failed.');btn.disabled=false;btn.innerHTML=orig;}
 }catch(err){showMsg('error','Error: '+err.message);btn.disabled=false;btn.innerHTML=orig;}
});
function showMsg(type,text){const el=document.getElementById('regMsg');el.className='msg '+type;el.textContent=text;el.style.display='block';}
</script>
</body>
</html>
