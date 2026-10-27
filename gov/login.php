<?php
ini_set('display_errors', 0);
ob_start();
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
if (isset($_SESSION['user_id'])) {
 require_once __DIR__ . '/../config/auth.php';
 redirect_to_portal();
}
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

// Government portal page: official accounts only (barangay / LGU / first
// responder). Accounts are provisioned by the system administrator via the
// admin panel - there is no self-registration here. Community members use
// login.php; administrators sign in directly at admin.php.
$SENTRI_PORTALS = [
 'barangay' => ['barangay'],
 'lgu' => ['lgu'],
 'first_responder' => ['first_responder'],
];
require __DIR__ . '/../core/login_handler.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Government Portal Sign In - SenTri</title>
<link rel="stylesheet" href="../assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<style>
:root{--navy:#0a3d62;--navy-dark:#062444;--navy-light:#1a5276;--gold:#f39c12;--gold-dark:#d68910;--green:#166534;--green-light:#16a34a;--red:#b91c1c;--text:#1a1a2e;--muted:#6b7280;--border:#e5e7eb;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
body{min-height:100vh;background:var(--navy-dark);display:flex;flex-direction:column;}
.gov-bar{background:#fff;border-bottom:4px solid var(--gold);padding:0 32px;display:flex;align-items:center;justify-content:space-between;height:60px;box-shadow:0 2px 12px rgba(0,0,0,0.15);}
.gov-brand{display:flex;align-items:center;gap:14px;text-decoration:none;}
.gov-seal{width:40px;height:40px;background:linear-gradient(135deg,var(--navy),var(--navy-light));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);border:2px solid var(--gold);}
.gov-text h1{font-family:'Poppins',sans-serif;font-size:1.1rem;font-weight:800;color:var(--navy);}
.gov-text p{font-size:0.7rem;color:var(--muted);font-weight:500;}
.gov-links{display:flex;gap:20px;}
.gov-links a{font-size:0.82rem;color:var(--navy);text-decoration:none;font-weight:600;opacity:0.8;}
.gov-links a:hover{opacity:1;color:var(--gold-dark);}
.hero{background:linear-gradient(135deg,var(--navy-dark) 0%,var(--navy) 60%,#1a5276 100%);padding:18px 20px 0;position:relative;overflow:visible;}
.hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");}
.hero-inner{max-width:920px;margin:0 auto;display:flex;align-items:center;gap:20px;position:relative;z-index:1;}
.hero-eyebrow{font-size:0.68rem;font-weight:700;color:var(--gold);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px;}
.hero-inner h2{font-family:'Poppins',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:3px;}
.hero-inner h2 span{color:var(--gold);}
.hero-inner p{font-size:0.8rem;color:rgba(255,255,255,0.7);line-height:1.45;max-width:420px;}
.hero-stats{display:flex;gap:18px;margin-top:10px;}
.stat-num{font-family:'Poppins',sans-serif;font-size:1.2rem;font-weight:800;color:var(--gold);}
.stat-lbl{font-size:0.65rem;color:rgba(255,255,255,0.55);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;}
.hero-badge{background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:10px 14px;flex-shrink:0;text-align:center;min-width:110px;margin-left:auto;}
.hero-badge i{font-size:1.5rem;color:var(--gold);display:block;margin-bottom:3px;}
.hero-badge span{font-size:0.68rem;color:rgba(255,255,255,0.65);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;}
.portal-section{max-width:920px;margin:0 auto;padding:12px 16px 0;position:relative;z-index:1;}
.portal-lbl{font-size:0.65rem;font-weight:700;color:var(--gold);letter-spacing:1.5px;text-transform:uppercase;text-align:center;margin-bottom:8px;}
.portal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;transform:translateY(12px);}
.portal-card{background:#fff;border-radius:11px;padding:12px 8px;text-align:center;cursor:pointer;border:2.5px solid transparent;transition:border-color 0.2s,box-shadow 0.2s;box-shadow:0 4px 16px rgba(0,0,0,0.15);}
.portal-card:hover{box-shadow:0 6px 20px rgba(0,0,0,0.22);}
.portal-card.selected{box-shadow:0 6px 20px rgba(243,156,18,0.35);}
.p-barangay.selected{border-color:#166534;}.p-lgu.selected{border-color:#0a3d62;}.p-responder.selected{border-color:#b91c1c;}
.portal-icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;margin:0 auto 5px;font-size:1.05rem;}
.p-barangay .portal-icon{background:#f0fdf4;color:#166534;}.p-lgu .portal-icon{background:#f0f7ff;color:#0a3d62;}.p-responder .portal-icon{background:#fef2f2;color:#b91c1c;}
.portal-name{font-size:0.76rem;font-weight:700;color:#1a1a2e;line-height:1.25;}
.portal-sub{font-size:0.64rem;color:#6b7280;margin-top:1px;}
.form-wrap{max-width:480px;width:100%;margin:0 auto;padding:0 16px 28px;position:relative;z-index:2;}
.form-card{background:#fff;border-radius:16px;padding:24px 26px 20px;margin-top:20px;box-shadow:0 8px 32px rgba(0,0,0,0.2);width:100%;}
.portal-header{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:12px;border-bottom:2px solid #e5e7eb;}
.ph-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.ph-title{font-size:0.98rem;font-weight:800;color:#1a1a2e;}
.ph-sub{font-size:0.75rem;color:#6b7280;margin-top:2px;}
.official-notice{background:#fef9ec;border:1px solid #fde68a;border-radius:8px;padding:8px 11px;font-size:0.75rem;color:#92400e;display:flex;align-items:flex-start;gap:7px;margin-bottom:11px;line-height:1.4;}
.official-notice i{margin-top:2px;color:#d97706;flex-shrink:0;}
.form-group{margin-bottom:11px;}
.form-group label{display:block;font-size:0.76rem;font-weight:600;color:#374151;margin-bottom:3px;}
.form-group input{width:100%;padding:9px 11px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.86rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;transition:border-color 0.15s,box-shadow 0.15s;color:#1a1a2e;}
.form-group input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,0.09);}
.pw-wrap{position:relative;}
.pw-wrap .toggle-pw{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#6b7280;font-size:0.75rem;font-weight:700;font-family:'Inter',sans-serif;}
.forgot-row{display:flex;justify-content:flex-end;margin-top:-4px;margin-bottom:12px;}
.forgot-row a{font-size:0.75rem;color:#2563eb;text-decoration:none;font-weight:600;}
.btn-login{width:100%;min-height:42px;padding:10px 14px;border:none;border-radius:9px;font-size:0.9rem;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;color:#fff;background:#0a3d62;background-image:linear-gradient(135deg,#1a5276,#0a3d62);display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,0.12);transition:filter 0.15s ease,box-shadow 0.15s ease;}
.btn-login:hover:not(:disabled){filter:brightness(1.05);box-shadow:0 4px 14px rgba(0,0,0,0.2);}
.btn-login:active:not(:disabled){transform:scale(0.99);}
.btn-login:disabled{opacity:0.6;cursor:not-allowed;background:#9ca3af!important;background-image:none!important;}
.msg{padding:8px 12px;border-radius:8px;font-size:0.8rem;font-weight:500;margin-bottom:11px;display:none;text-align:center;}
.msg.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.msg.success{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0;}
.com-row{text-align:center;margin-top:10px;font-size:0.78rem;color:#6b7280;border-top:1px solid var(--border);padding-top:10px;}
.com-row a{color:#2563eb;font-weight:700;text-decoration:none;}
.page-notice{max-width:920px;margin:12px auto 0;padding:0 24px;}
.notice{padding:10px 14px;border-radius:9px;font-size:0.81rem;font-weight:500;display:flex;align-items:flex-start;gap:8px;line-height:1.45;}
.notice.info{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}
.notice.success{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}
.site-footer{background:var(--navy-dark);border-top:1px solid rgba(255,255,255,0.07);padding:16px 24px;text-align:center;margin-top:auto;}
.site-footer p{font-size:0.74rem;color:rgba(255,255,255,0.35);}
@media(max-width:840px){.hero-badge{display:none;}.gov-links{display:none;}.form-card{padding:22px 20px 18px;margin-top:18px;}.form-wrap{max-width:480px;padding:0 16px 24px;}.portal-section{padding:12px 14px 0;}}
@media(max-width:580px){.portal-grid{gap:6px;transform:translateY(8px);}.portal-card{padding:8px 4px;border-radius:8px;}.portal-icon{width:32px;height:32px;font-size:0.9rem;margin-bottom:3px;}.portal-name{font-size:0.66rem;}.portal-sub{display:none;}.hero-inner h2{font-size:1.2rem;}.form-card{padding:18px 14px 16px;margin-top:14px;}.form-wrap{max-width:100%;padding:0 12px 20px;}}

/* ── Motion ── */
@keyframes iconSwap{0%{opacity:0;transform:scale(.85);}100%{opacity:1;transform:scale(1);}}
@keyframes msgError{0%{opacity:0;transform:translateY(-4px);}50%{opacity:1;transform:translateY(0);}100%{transform:translateY(0);}}
@keyframes msgSuccess{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);}}
.portal-card.justSelected{box-shadow:0 0 0 3px rgba(243,156,18,0.4),0 6px 20px rgba(243,156,18,0.3);}
.portal-card:focus-visible{outline:2.5px solid var(--gold);outline-offset:2px;}
.ph-icon i{animation:iconSwap .2s ease;}
.msg.error{animation:msgError .3s ease;}
.msg.success{animation:msgSuccess .25s ease;}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;}}
</style>
</head>
<body>
<header class="gov-bar">
 <a href="../index.php" class="gov-brand">
 <div class="gov-seal"><i class="fas fa-shield-halved"></i></div>
 <div class="gov-text"><h1>SenTri</h1><p>Government Access - Official Use Only</p></div>
 </a>
 <nav class="gov-links">
 <a href="../index.php">Home</a>
 <a href="../login.php">Community Portal</a>
 <a href="../forgot_password.php">Forgot Password</a>
 </nav>
</header>

<section class="hero">
 <div class="hero-inner">
 <div>
 <p class="hero-eyebrow">Official Government Access</p>
 <h2>Government <span>Portal</span></h2>
 <p>Restricted access for barangay officials, LGU staff, and first responders. Each portal is restricted to its designated role.</p>
 <div class="hero-stats">
 <div><div class="stat-num">3</div><div class="stat-lbl">Official Portals</div></div>
 <div><div class="stat-num">24/7</div><div class="stat-lbl">Monitoring</div></div>
 </div>
 </div>
 <div class="hero-badge"><i class="fas fa-landmark"></i><span>Official<br>Gov Access</span></div>
 </div>
 <div class="portal-section">
 <p class="portal-lbl">Select Your Office</p>
 <div class="portal-grid">
 <div class="portal-card p-barangay selected" tabindex="0" onclick="selectPortal('barangay',this)"><div class="portal-icon"><i class="fas fa-house-flag"></i></div><div class="portal-name">Barangay</div><div class="portal-sub">Barangay Officials</div></div>
 <div class="portal-card p-lgu" tabindex="0" onclick="selectPortal('lgu',this)"><div class="portal-icon"><i class="fas fa-landmark"></i></div><div class="portal-name">LGU</div><div class="portal-sub">City / Municipal</div></div>
 <div class="portal-card p-responder" tabindex="0" onclick="selectPortal('first_responder',this)"><div class="portal-icon"><i class="fas fa-truck-medical"></i></div><div class="portal-name">First Responder</div><div class="portal-sub">BFP / PNP / EMS</div></div>
 </div>
 </div>
</section>

<?php if(isset($_GET['pending'])): ?><div class="page-notice"><div class="notice info"><i class="fas fa-clock"></i><span>Your account is awaiting administrator approval. Official accounts are activated directly by the system administrator.</span></div></div>
<?php elseif(isset($_GET['reset'])): ?><div class="page-notice"><div class="notice success"><i class="fas fa-circle-check"></i><span>Password updated successfully. You may now sign in.</span></div></div>
<?php endif; ?>

<div class="form-wrap">
 <div class="form-card">
 <div class="portal-header">
 <div class="ph-icon" id="phIcon" style="background:#f0fdf4;color:#166534;"><i class="fas fa-house-flag"></i></div>
 <div><div class="ph-title" id="phTitle">Barangay Official Sign In</div><div class="ph-sub" id="phSub">Barangay operations and incident management portal</div></div>
 </div>
 <div class="official-notice"><i class="fas fa-key"></i><span>Official accounts are provisioned by the system administrator. There is no self-registration - contact your system administrator if you need an account.</span></div>
 <div id="loginMsg" class="msg"></div>
 <form id="loginForm" novalidate>
 <input type="hidden" name="portal" id="portalField" value="barangay">
 <div class="form-group"><label>Official Email Address</label><input type="email" name="email" id="emailField" placeholder="official@example.gov.ph" required autocomplete="email"></div>
 <div class="form-group"><label>Password</label><div class="pw-wrap"><input type="password" name="password" id="pwField" placeholder="••••••••" required><button type="button" class="toggle-pw" onclick="togglePw()">SHOW</button></div></div>
 <div class="forgot-row"><a href="../forgot_password.php">Forgot Password?</a></div>
 <button type="submit" class="btn-login" id="loginBtn" style="background:#166534;background-image:linear-gradient(135deg,#16a34a,#166534);"><i class="fas fa-right-to-bracket" id="loginBtnIcon"></i> <span id="loginBtnLabel">Sign In to Barangay Portal</span></button>
 </form>
 <div id="otpSection" style="display:none;">
 <form id="otpForm" novalidate>
 <input type="hidden" name="action" value="verify_otp">
 <input type="hidden" name="email" id="otpEmail" value="">
 <input type="hidden" name="portal" id="otpPortal" value="barangay">
 <div class="form-group"><label>One-Time Code</label><input type="text" name="otp" id="otpField" placeholder="123456" maxlength="6" pattern="[0-9]*" inputmode="numeric" required></div>
 <button type="submit" class="btn-login" id="otpSubmitBtn"><i class="fas fa-key"></i> Verify OTP</button>
 <button type="button" class="btn-login" id="otpBackBtn" style="background:#f3f4f6;color:#1f2937;margin-top:10px;"><i class="fas fa-arrow-left"></i> Back to Password</button>
 </form>
 </div>
 <div class="com-row">Community member? <a href="../login.php">Sign in at the Community Portal</a></div>
 </div>
</div>
<footer class="site-footer"><p>SenTri Government Access - For authorized government personnel only. Unauthorized access is subject to legal action.</p></footer>
<script>
const portals={
 barangay: {title:'Barangay Official Sign In', sub:'Barangay operations and incident management portal', icon:'fa-house-flag', bg:'#f0fdf4', color:'#166534', btn:'linear-gradient(135deg,#16a34a,#166534)', label:'Sign In to Barangay Portal'},
 lgu: {title:'LGU Official Sign In', sub:'City and municipal government incident oversight portal', icon:'fa-landmark', bg:'#f0f7ff', color:'#0a3d62', btn:'linear-gradient(135deg,#1a5276,#062444)', label:'Sign In to LGU Portal'},
 first_responder:{title:'First Responder Sign In', sub:'Emergency dispatch and active incident response portal', icon:'fa-truck-medical', bg:'#fef2f2', color:'#b91c1c', btn:'linear-gradient(135deg,#ef4444,#b91c1c)', label:'Sign In to Responder Portal'},
};
let current='barangay';
let timerInterval = null;

document.querySelectorAll('.portal-card').forEach(c=>{
 c.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();c.click();}});
});

function selectPortal(key,el){
 current=key;
 document.querySelectorAll('.portal-card').forEach(c=>c.classList.remove('selected','justSelected'));
 if(el) el.classList.add('selected','justSelected');
 setTimeout(()=>{ if(el) el.classList.remove('justSelected'); }, 250);
 const p=portals[key];
 const pi=document.getElementById('phIcon');
 pi.style.backgroundColor=p.bg;
 pi.style.color=p.color;
 pi.innerHTML=`<i class="fas ${p.icon}"></i>`;
 document.getElementById('phTitle').textContent=p.title;
 document.getElementById('phSub').textContent=p.sub;
 document.getElementById('portalField').value=key;
 document.getElementById('otpPortal').value=key;
 const btn=document.getElementById('loginBtn');
 btn.style.backgroundColor=p.color;
 btn.style.background=p.btn;
 const lbl=document.getElementById('loginBtnLabel');
 if(lbl){lbl.textContent=p.label;}else{btn.innerHTML=`<i class="fas fa-right-to-bracket" id="loginBtnIcon"></i> <span id="loginBtnLabel">${p.label}</span>`;}
 document.getElementById('loginMsg').style.display='none';
}

function togglePw(){const f=document.getElementById('pwField');const b=f.nextElementSibling;const s=f.type==='password';f.type=s?'text':'password';b.textContent=s?'HIDE':'SHOW';}

function showOtpForm(email){
 document.getElementById('loginForm').style.display='none';
 document.getElementById('otpSection').style.display='block';
 document.getElementById('otpEmail').value=email;
 document.getElementById('otpField').value='';
 document.getElementById('otpField').focus();
}

function hideOtpForm(){
 document.getElementById('otpSection').style.display='none';
 document.getElementById('loginForm').style.display='block';
 document.getElementById('loginMsg').style.display='none';
}

document.getElementById('otpBackBtn').addEventListener('click',()=>hideOtpForm());

document.getElementById('otpForm').addEventListener('submit',async e=>{
 e.preventDefault();
 const btn=document.getElementById('otpSubmitBtn');const msg=document.getElementById('loginMsg');const orig=btn.innerHTML;
 btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Verifying...';msg.style.display='none';
 try{
 const res=await fetch('login.php',{method:'POST',body:new FormData(e.target)});
 const data=await res.json();
 if(data.status==='success'){showMsg('success',data.message||'Redirecting...');setTimeout(()=>window.location.href=data.redirect,700);}
 else{
 showMsg('error',data.message||'Invalid OTP.');
 btn.disabled=false;btn.innerHTML=orig;
 if(data.lockout){startLockTimer(30);}
 }
 }catch{showMsg('error','Connection error. Please try again.');btn.disabled=false;btn.innerHTML=orig;}
});

function startLockTimer(seconds) {
 const btn = document.getElementById('loginBtn');
 const msg = document.getElementById('loginMsg');
 btn.disabled = true;
 let timeLeft = seconds;

 if (timerInterval) clearInterval(timerInterval);

 showMsg('error', `Too many failed attempts. Please wait ${timeLeft} seconds before trying again.`);

 timerInterval = setInterval(() => {
 timeLeft--;
 btn.innerHTML = `<i class="fas fa-lock"></i> Wait ${timeLeft}s`;
 showMsg('error', `Too many failed attempts. Please wait ${timeLeft} seconds before trying again.`);
 if (timeLeft <= 0) {
 clearInterval(timerInterval);
 btn.disabled = false;
 const p = portals[current];
 btn.style.backgroundColor=p.color;
 btn.style.backgroundImage=p.btn;
 btn.innerHTML = `<i class="fas fa-right-to-bracket" id="loginBtnIcon"></i> <span id="loginBtnLabel">${p.label}</span>`;
 msg.style.display = 'none';
 }
 }, 1000);
}

document.getElementById('loginForm').addEventListener('submit',async e=>{
 e.preventDefault();
 const btn=document.getElementById('loginBtn');const msg=document.getElementById('loginMsg');const orig=btn.innerHTML;
 btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Signing in...';msg.style.display='none';
 try{
 const res=await fetch('login.php',{method:'POST',body:new FormData(e.target)});
 const data=await res.json();
 if(data.status==='success'){showMsg('success',data.message||'Redirecting...');setTimeout(()=>window.location.href=data.redirect,700);}
 else if(data.status==='must_reset'){showMsg('success',data.message||'Redirecting to password setup...');setTimeout(()=>window.location.href=data.redirect,900);}
 else if(data.status==='otp_required'){
 showMsg('success',data.message||'Enter the 6-digit code from your email.');
 showOtpForm(data.email || document.getElementById('emailField').value);
 btn.disabled=false;
 btn.innerHTML=orig;
 }
 else{
 showMsg('error',data.message||'Sign in failed.');
 if(data.lockout) {
 startLockTimer(30);
 } else {
 btn.disabled=false;
 const p = portals[current];
 btn.style.backgroundColor=p.color;
 btn.style.backgroundImage=p.btn;
 btn.innerHTML = `<i class="fas fa-right-to-bracket" id="loginBtnIcon"></i> <span id="loginBtnLabel">${p.label}</span>`;
 }
 if(data.pending){
 document.querySelector('.official-notice span').innerHTML='Your account is awaiting administrator approval. You will be able to sign in once the system administrator activates your account.';
 }
 }
 }catch{showMsg('error','Connection error. Please try again.');btn.disabled=false;btn.innerHTML=orig;}
});

function showMsg(type,text){const el=document.getElementById('loginMsg');el.className='msg '+type;el.textContent=text;el.style.display='block';}
</script>
</body>
</html>
