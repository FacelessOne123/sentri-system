<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
require_once __DIR__ . '/config/auth.php';
$_role = $_SESSION['role'] ?? 'community';
if (!in_array($_role, ['community','user'], true)) { redirect_to_portal(); }
require __DIR__ . '/config/db.php';

$user_id    = (int)$_SESSION['user_id'];
$first_name = $_SESSION['first_name'];
$last_name  = $_SESSION['last_name'];
$role       = $_SESSION['role'];

// Fetch user profile details (avatar_color if column exists)
$avatar_color = '#1c57b2';
$user_email   = '';
$res = $conn->query("SHOW COLUMNS FROM users LIKE 'avatar_color'");
if ($res && $res->num_rows > 0) {
    $s = $conn->prepare("SELECT email, avatar_color FROM users WHERE id=?");
    $s->bind_param("i", $user_id); $s->execute(); $s->bind_result($user_email, $av); $s->fetch(); $s->close();
    if ($av) $avatar_color = $av;
} else {
    $s = $conn->prepare("SELECT email FROM users WHERE id=?");
    $s->bind_param("i", $user_id); $s->execute(); $s->bind_result($user_email); $s->fetch(); $s->close();
}

// Fetch saved GPS (gps_lat / gps_lng columns added on demand)
$saved_gps_lat = null; $saved_gps_lng = null;
$gpsColRes = $conn->query("SHOW COLUMNS FROM users LIKE 'gps_lat'");
if ($gpsColRes && $gpsColRes->num_rows > 0) {
    $sg = $conn->prepare("SELECT gps_lat, gps_lng FROM users WHERE id=?");
    $sg->bind_param("i", $user_id); $sg->execute(); $sg->bind_result($saved_gps_lat, $saved_gps_lng); $sg->fetch(); $sg->close();
}

$total_reports=0;$s=$conn->prepare("SELECT COUNT(*) FROM reports WHERE is_archived=0");$s->execute();$s->bind_result($total_reports);$s->fetch();$s->close();
$danger_count=0;$s=$conn->prepare("SELECT COUNT(*) FROM reports WHERE status='dangerous' AND is_archived=0");$s->execute();$s->bind_result($danger_count);$s->fetch();$s->close();
$safe_count=0;$s=$conn->prepare("SELECT COUNT(*) FROM reports WHERE status='safe' AND is_archived=0");$s->execute();$s->bind_result($safe_count);$s->fetch();$s->close();
$my_count=0;$s=$conn->prepare("SELECT COUNT(*) FROM reports WHERE user_id=? AND is_archived=0");$s->bind_param("i",$user_id);$s->execute();$s->bind_result($my_count);$s->fetch();$s->close();

$filter_my = isset($_GET['filter']) && $_GET['filter'] === 'my';
$section   = isset($_GET['section']) ? $_GET['section'] : 'feed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
/* ─── LIGHT MODE VARIABLES ─── */
:root{
  --blue:#0a3d62;--blue-dark:#062444;--blue-light:#1a5276;--gold:#f39c12;
  --red:#e53e3e;--green:#38a169;--orange:#dd6b20;
  --text:#1a1a2e;--muted:#666;
  --bg:#f0f2f7;--card:#fff;--card-border:#eee;
  --input-bg:#fafafa;--input-border:#e0e0e0;--input-focus:#fff;
  --topbar-bg:#fff;--topbar-shadow:0 2px 12px rgba(0,0,0,0.07);
  --modal-bg:#fff;--filter-bg:#fff;
  --sidebar-w:252px;
}

/* ─── DARK MODE VARIABLES & OVERRIDES ─── */
body.dark{
  --bg:#0d1117;--card:#161b22;--card-border:#30363d;
  --text:#e6edf3;--muted:#8b949e;
  --input-bg:#0d1117;--input-border:#30363d;--input-focus:#161b22;
  --topbar-bg:#161b22;--topbar-shadow:0 2px 12px rgba(0,0,0,0.4);
  --modal-bg:#161b22;--filter-bg:#161b22;
}
body.dark .stat-card{background:var(--card);box-shadow:0 2px 12px rgba(0,0,0,0.3);}
body.dark .stat-card span{color:var(--muted);}
body.dark .stat-card strong{color:var(--text);}
body.dark .stat-icon.blue{background:#1f3a5f;}
body.dark .stat-icon.red{background:#3d1f1f;}
body.dark .stat-icon.green{background:#1a2e24;}
body.dark .stat-icon.orange{background:#2e2010;}
body.dark .filters{background:var(--filter-bg);box-shadow:0 2px 10px rgba(0,0,0,0.3);}
body.dark .filters input,body.dark .filters select{background:var(--input-bg);border-color:var(--input-border);color:var(--text);}
body.dark .filters button{background:#21262d;color:#8b949e;}
body.dark .filters button.active,body.dark .filters button:hover{background:linear-gradient(135deg,var(--blue-light),var(--blue));color:#fff;}
body.dark .view-toggle{background:#21262d;}
body.dark .view-btn{color:#8b949e;}
body.dark .view-btn.active{background:#161b22;color:var(--blue-light);}
body.dark .report-card{background:var(--card);}
body.dark .report-card.dangerous .card-header{background:linear-gradient(90deg,rgba(229,62,62,0.08),transparent);}
body.dark .report-card.caution  .card-header{background:linear-gradient(90deg,rgba(221,107,32,0.08),transparent);}
body.dark .report-card.safe     .card-header{background:linear-gradient(90deg,rgba(56,161,105,0.08),transparent);}
body.dark .card-header h3{color:var(--text);}
body.dark .card-meta{color:#8b949e;}
body.dark .card-body p{color:#c9d1d9;}
body.dark .vote-btn{background:#21262d;border-color:#30363d;color:#8b949e;}
body.dark .vote-btn:hover{background:#0d1117;border-color:var(--blue-light);color:var(--blue-light);}
body.dark .vote-btn.voted{background:#1f3a5f;border-color:var(--blue);color:var(--blue-light);}
body.dark .vote-btn.down.voted{background:#3d1f1f;border-color:var(--red);color:#fc8181;}
body.dark .map-pin-chip{background:#1f3a5f;color:var(--blue-light);}
body.dark .category-tag{background:#21262d;color:#8b949e;}
body.dark .badge.dangerous{background:#3d1f1f;}
body.dark .badge.caution{background:#2e2010;}
body.dark .badge.safe{background:#1a2e24;}
body.dark .topbar{background:var(--topbar-bg);box-shadow:var(--topbar-shadow);}
body.dark .topbar h1{color:var(--text);}
body.dark .ham-btn{color:#8b949e;}
body.dark .ham-btn:hover{background:#21262d;color:var(--text);}
body.dark .icon-btn{border-color:#30363d;color:#8b949e;}
body.dark .icon-btn:hover{background:#21262d;color:var(--text);}
body.dark .user-info:hover{background:#21262d;}
body.dark .user-name{color:var(--text);}
body.dark .modal,.dark .mini-map-box,.dark .rmap-wrap{background:var(--modal-bg);}
body.dark .modal h2{color:var(--text);}
body.dark .modal .subtitle{color:var(--muted);}
body.dark .modal .form-group label{color:#8b949e;}
body.dark .modal .form-group input,body.dark .modal .form-group textarea,body.dark .modal .form-group select{background:var(--input-bg);border-color:var(--input-border);color:var(--text);}
body.dark .modal .form-group input:focus,body.dark .modal .form-group textarea:focus,body.dark .modal .form-group select:focus{background:var(--input-focus);border-color:var(--blue-light);}
body.dark .modal-close,.dark .mini-map-close{background:#21262d;color:#8b949e;}
body.dark .btn-cancel{background:#21262d;border-color:#30363d;color:#8b949e;}
body.dark .status-opt{background:#21262d;border-color:#30363d;}
body.dark .status-opt span{color:var(--text);}
body.dark .status-opt.selected.dangerous{background:#3d1f1f;}
body.dark .status-opt.selected.caution{background:#2e2010;}
body.dark .status-opt.selected.safe{background:#1a2e24;}
body.dark .mini-map-header{border-color:#30363d;}
body.dark .mini-map-header h4{color:var(--text);}
body.dark .mini-map-footer{background:#0d1117;color:#8b949e;}
body.dark .picker-section{border-color:#30363d;background:#0d1117;}
body.dark .picker-header{background:linear-gradient(90deg,#1f3a5f,#0d1117);color:var(--blue-light);border-color:#30363d;}
body.dark .picker-toolbar,.dark .radius-row{background:#161b22;border-color:#30363d;}
body.dark .locate-btn{background:#1f3a5f;border-color:var(--blue);color:var(--blue-light);}
body.dark .clear-pin-btn{background:#21262d;border-color:#30363d;color:#8b949e;}
body.dark .radius-row label{color:#8b949e;}
body.dark .map-legend,.dark .rmap-legend{background:#161b22;border-color:#30363d;}
body.dark .map-info,.dark .legend-item{color:#8b949e;}
body.dark .rmap-header{border-color:#30363d;}
body.dark .rmap-header h2{color:var(--text);}
body.dark .rmap-header p{color:var(--muted);}
body.dark .avatar-picker{background:#21262d;}
body.dark .profile-section-label{color:#8b949e;}
body.dark .profile-divider{border-color:#30363d;}
body.dark .toast{background:var(--card);box-shadow:0 8px 32px rgba(0,0,0,0.4);}
body.dark .toast-title{color:var(--text);}
body.dark .toast-body{color:var(--muted);}

/* ─── ANIMATIONS ─── */
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes scaleIn{from{opacity:0;transform:scale(0.97);}to{opacity:1;transform:scale(1);}}
@keyframes cardEntrance{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}
@keyframes spin{to{transform:rotate(360deg);}}
@keyframes pulse-glow{0%,100%{box-shadow:0 0 0 0 rgba(229,62,62,0.4);}50%{box-shadow:0 0 0 8px rgba(229,62,62,0);}}
@keyframes toastSlide{from{opacity:0;transform:translateX(120%);}to{opacity:1;transform:translateX(0);}}
@keyframes toastFade{from{opacity:1;transform:translateX(0);}to{opacity:0;transform:translateX(120%);}}
@keyframes toastProgress{from{width:100%;}to{width:0%;}}
@keyframes gpsPulse{0%,100%{box-shadow:0 0 0 0 rgba(56,161,105,0.5);}50%{box-shadow:0 0 0 5px rgba(56,161,105,0);}}

*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif;}
body{background:var(--bg);display:flex;min-height:100vh;color:var(--text);overflow-x:hidden;transition:background 0.3s,color 0.3s;}

/* ─── SIDEBAR ─── */
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#062444 0%,#0a3d62 55%,#0e4d80 100%);color:#fff;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);box-shadow:4px 0 20px rgba(0,0,0,0.2);}
.sidebar.closed{transform:translateX(calc(-1*var(--sidebar-w)));}
.sidebar-header{padding:20px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.1);}
.brand-logo{display:flex;align-items:center;gap:10px;}
.brand-icon-s{width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;border:1px solid rgba(255,255,255,0.2);}
.brand-name-s{font-size:1.05rem;font-weight:800;}
.toggle-btn{background:none;border:none;color:rgba(255,255,255,0.7);cursor:pointer;font-size:1rem;padding:6px;border-radius:8px;transition:all 0.2s;}
.toggle-btn:hover{background:rgba(255,255,255,0.1);color:#fff;}
.menu{padding:12px 10px;display:flex;flex-direction:column;gap:2px;}
.menu a{display:flex;align-items:center;gap:11px;padding:11px 13px;text-decoration:none;color:rgba(255,255,255,0.75);font-size:0.875rem;font-weight:500;border-radius:10px;transition:all 0.2s;white-space:nowrap;}
.menu a:hover{background:rgba(255,255,255,0.12);color:#fff;}
.menu a.active{background:rgba(255,255,255,0.2);color:#fff;font-weight:700;border-left:3px solid rgba(255,255,255,0.65);}
.menu a i{font-size:1rem;width:18px;text-align:center;flex-shrink:0;}
.sidebar-stats{padding:12px 14px;margin:8px 0;}
.stat-label-s{font-size:0.68rem;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:10px;padding:0 4px;}
.stat-box{background:rgba(255,255,255,0.08);border-radius:10px;padding:11px 14px;margin-bottom:8px;border:1px solid rgba(255,255,255,0.05);transition:background 0.2s;}
.stat-box:hover{background:rgba(255,255,255,0.12);}
.stat-box .num{font-size:1.3rem;font-weight:700;}
.stat-box .lbl{font-size:0.74rem;opacity:0.7;margin-top:2px;}
.stat-box.danger-box{background:rgba(229,62,62,0.18);border-left:3px solid #e53e3e;}
.stat-box.safe-box{background:rgba(56,161,105,0.18);border-left:3px solid #38a169;}
.sidebar-footer{margin-top:auto;padding:16px;display:flex;flex-direction:column;gap:4px;}
.sidebar-footer a{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.65);text-decoration:none;font-size:0.87rem;padding:10px 13px;border-radius:10px;transition:all 0.2s;}
.sidebar-footer a:hover{background:rgba(255,255,255,0.1);color:#fff;}

/* Dark Mode toggle row */
.dm-row{display:flex;align-items:center;justify-content:space-between;padding:10px 13px;border-radius:10px;cursor:pointer;transition:background 0.2s;}
.dm-row:hover{background:rgba(255,255,255,0.1);}
.dm-row-label{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.65);font-size:0.87rem;}
.dm-switch{position:relative;width:38px;height:20px;flex-shrink:0;}
.dm-switch input{display:none;}
.dm-slider{position:absolute;inset:0;background:rgba(255,255,255,0.2);border-radius:20px;cursor:pointer;transition:background 0.3s;}
.dm-slider::before{content:'';position:absolute;width:14px;height:14px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform 0.3s;}
body.dark .dm-slider{background:var(--blue-light);}
body.dark .dm-slider::before{transform:translateX(18px);}

.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:99;backdrop-filter:blur(2px);}
.sidebar-overlay.show{display:block;}

/* ─── MAIN / TOPBAR ─── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-width:0;transition:margin-left 0.3s;}
.topbar{background:var(--topbar-bg);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--topbar-shadow);position:sticky;top:0;z-index:50;animation:fadeIn 0.4s ease;transition:background 0.3s,box-shadow 0.3s;}
.topbar-left{display:flex;align-items:center;gap:12px;}
.ham-btn{background:none;border:none;font-size:1.2rem;color:var(--muted);cursor:pointer;padding:7px;border-radius:9px;transition:all 0.2s;display:none;} /* shown via JS on desktop when sidebar is closed */
.ham-btn:hover{background:var(--bg);color:var(--text);}
.topbar h1{font-size:1.1rem;font-weight:700;color:var(--text);}
.right-top{display:flex;align-items:center;gap:10px;}
.post-btn{background:linear-gradient(135deg,var(--blue-light),var(--blue));color:#fff;border:none;padding:10px 20px;border-radius:10px;font-size:0.87rem;font-weight:600;cursor:pointer;transition:all 0.25s;display:flex;align-items:center;gap:7px;font-family:'Poppins',sans-serif;white-space:nowrap;}
.post-btn:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(28,87,178,0.4);}
.gps-chip{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:50px;font-size:0.76rem;font-weight:600;background:var(--bg);color:var(--muted);border:1.5px solid var(--input-border);transition:all 0.3s;cursor:default;}
.gps-chip.active{background:#f0fff4;color:#38a169;border-color:#38a169;}
body.dark .gps-chip.active{background:#1a2e24;}
.gps-chip.error{background:#fff0f0;color:#e53e3e;border-color:#e53e3e;}
body.dark .gps-chip.error{background:#3d1f1f;}
.gps-dot{width:7px;height:7px;border-radius:50%;background:currentColor;flex-shrink:0;}
.gps-chip.active .gps-dot{animation:gpsPulse 1.5s ease infinite;}
.user-info{display:flex;align-items:center;gap:10px;cursor:pointer;padding:5px 10px;border-radius:10px;transition:background 0.2s;}
.user-info:hover{background:var(--bg);}
.avatar{width:38px;height:38px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0;transition:transform 0.2s;}
.user-info:hover .avatar{transform:scale(1.08);}
.user-name{font-size:0.88rem;font-weight:600;color:var(--text);}
.content{padding:28px;flex:1;}

/* ─── GPS TOAST ALERTS ─── */
#toastContainer{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;max-width:340px;}
.toast{background:var(--card);border-radius:14px;padding:14px 16px;box-shadow:0 8px 32px rgba(0,0,0,0.15);border-left:5px solid #ccc;display:flex;gap:12px;align-items:flex-start;animation:toastSlide 0.4s cubic-bezier(0.34,1.56,0.64,1) both;pointer-events:auto;position:relative;overflow:hidden;}
.toast.fade-out{animation:toastFade 0.4s ease both;}
.toast.dangerous{border-left-color:var(--red);}
.toast.caution{border-left-color:var(--orange);}
.toast.safe{border-left-color:var(--green);}
.toast-icon{font-size:1.3rem;flex-shrink:0;margin-top:2px;}
.toast.dangerous .toast-icon{color:var(--red);}
.toast.caution  .toast-icon{color:var(--orange);}
.toast.safe     .toast-icon{color:var(--green);}
.toast-title{font-size:0.83rem;font-weight:700;color:var(--text);margin-bottom:3px;}
.toast-body{font-size:0.76rem;color:var(--muted);line-height:1.55;}
.toast-close{position:absolute;top:8px;right:8px;background:none;border:none;cursor:pointer;color:var(--muted);font-size:0.78rem;padding:2px 5px;border-radius:5px;transition:color 0.2s;}
.toast-close:hover{color:var(--text);}
.toast-progress{position:absolute;bottom:0;left:0;height:3px;background:rgba(0,0,0,0.1);animation:toastProgress 5s linear both;}

/* ─── STATS ─── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:28px;}
.stat-card{background:var(--card);border-radius:16px;padding:18px 20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);display:flex;align-items:center;gap:14px;animation:fadeInUp 0.5s both;transition:transform 0.2s,box-shadow 0.2s,background 0.3s;position:relative;overflow:hidden;}
.stat-card:nth-child(1){animation-delay:0.05s;}.stat-card:nth-child(2){animation-delay:0.1s;}.stat-card:nth-child(3){animation-delay:0.15s;}.stat-card:nth-child(4){animation-delay:0.2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(0,0,0,0.1);}
.stat-icon{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0;}
.stat-icon.blue{background:#ebf2ff;color:var(--blue);}.stat-icon.red{background:#fff0f0;color:var(--red);}.stat-icon.green{background:#f0fff4;color:var(--green);}.stat-icon.orange{background:#fff8f0;color:var(--orange);}
.stat-card strong{display:block;font-size:1.5rem;font-weight:800;color:var(--text);}
.stat-card span{font-size:0.78rem;color:var(--muted);}

/* ─── FILTERS + VIEW TOGGLE ─── */
.filters{background:var(--filter-bg);border-radius:14px;padding:16px 20px;box-shadow:0 2px 10px rgba(0,0,0,0.06);margin-bottom:22px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;animation:fadeInUp 0.5s 0.25s both;transition:background 0.3s;}
.filters input,.filters select{padding:9px 13px;border:1.5px solid var(--input-border);border-radius:9px;font-size:0.87rem;outline:none;font-family:'Poppins',sans-serif;transition:all 0.2s;background:var(--input-bg);color:var(--text);}
.filters input{flex:1;min-width:160px;}
.filters input:focus,.filters select:focus{border-color:var(--blue-light);background:var(--input-focus);}
.filters select{min-width:130px;}
.filters button{padding:9px 18px;border:none;border-radius:9px;font-size:0.87rem;font-weight:600;cursor:pointer;background:var(--bg);color:var(--muted);transition:all 0.2s;font-family:'Poppins',sans-serif;}
.filters button.active,.filters button:hover{background:linear-gradient(135deg,var(--blue-light),var(--blue));color:#fff;}
.view-toggle{display:flex;gap:4px;background:var(--bg);border-radius:10px;padding:4px;margin-left:auto;flex-shrink:0;}
.view-btn{display:flex;align-items:center;gap:6px;padding:7px 15px;border:none;border-radius:7px;font-size:0.83rem;font-weight:600;cursor:pointer;background:transparent;color:var(--muted);font-family:'Poppins',sans-serif;transition:all 0.2s;white-space:nowrap;}
.view-btn.active{background:var(--card);color:var(--blue);box-shadow:0 2px 8px rgba(0,0,0,0.1);}

/* ─── FEED ─── */
#feed{display:flex;flex-direction:column;gap:16px;}
.report-card{background:var(--card);border-radius:14px;padding:20px 22px;box-shadow:0 2px 10px rgba(0,0,0,0.06);border-left:5px solid #ccc;transition:all 0.25s,background 0.3s;animation:cardEntrance 0.45s both;}
.report-card:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,0.1);}
.report-card.dangerous{border-left-color:var(--red);}.report-card.dangerous .card-header{background:linear-gradient(90deg,#fff0f0,transparent);}
.report-card.caution{border-left-color:var(--orange);}.report-card.caution .card-header{background:linear-gradient(90deg,#fff8f0,transparent);}
.report-card.safe{border-left-color:var(--green);}.report-card.safe .card-header{background:linear-gradient(90deg,#f0fff4,transparent);}
.card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px;padding:4px 0;}
.card-header h3{font-size:1rem;font-weight:700;color:var(--text);line-height:1.4;}
.badge{padding:4px 12px;border-radius:50px;font-size:0.73rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;flex-shrink:0;}
.badge.dangerous{background:#fff0f0;color:var(--red);}.badge.caution{background:#fff8f0;color:var(--orange);}.badge.safe{background:#f0fff4;color:var(--green);}
.card-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:0.79rem;color:#777;margin-bottom:10px;}
.card-meta span{display:flex;align-items:center;gap:5px;}
.card-body p{font-size:0.87rem;color:#444;line-height:1.75;}
.card-footer{margin-top:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.vote-btn{display:flex;align-items:center;gap:6px;padding:7px 16px;border:1.5px solid var(--input-border);border-radius:9px;font-size:0.82rem;font-weight:600;cursor:pointer;background:var(--card);color:#555;transition:all 0.2s;font-family:'Poppins',sans-serif;}
.vote-btn:hover{background:#f5f8ff;border-color:var(--blue-light);color:var(--blue);}
.vote-btn.voted{background:#ebf2ff;border-color:var(--blue);color:var(--blue);}
.vote-btn.down:hover{background:#fff5f5;border-color:var(--red);color:var(--red);}
.vote-btn.down.voted{background:#fff5f5;border-color:var(--red);color:var(--red);}
.map-pin-chip{display:inline-flex;align-items:center;gap:5px;font-size:0.74rem;background:#ebf2ff;color:var(--blue);border-radius:7px;padding:4px 10px;cursor:pointer;border:none;font-family:'Poppins',sans-serif;font-weight:600;transition:all 0.2s;}
.map-pin-chip:hover{background:#dbeafe;}
.category-tag{font-size:0.76rem;background:var(--bg);padding:5px 11px;border-radius:8px;color:var(--muted);}
.empty{text-align:center;padding:60px 20px;color:#bbb;animation:fadeIn 0.4s ease;}
.empty i{font-size:3rem;margin-bottom:14px;display:block;}
.loading{text-align:center;padding:50px;color:#888;font-size:0.9rem;animation:fadeIn 0.3s ease;}
.loading i{animation:spin 1s linear infinite;margin-right:8px;}

/* ─── MAP VIEW (feed) ─── */
#mapView{display:none;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1);animation:scaleIn 0.3s ease;margin-bottom:28px;isolation:isolate;}
#mapView.show{display:block;}
#mainMap{height:560px;width:100%;background:#dde8f0;}
.map-legend{background:var(--card);padding:14px 20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;border-top:1px solid var(--card-border);transition:background 0.3s;}
.legend-item{display:flex;align-items:center;gap:8px;font-size:0.79rem;font-weight:600;color:#555;}
body.dark .legend-item{color:#8b949e;}
.legend-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;}
.legend-dot.dangerous{background:var(--red);}.legend-dot.caution{background:var(--orange);}.legend-dot.safe{background:var(--green);}.legend-dot.unpinned{background:#aaa;}
.map-info{margin-left:auto;font-size:0.79rem;color:var(--muted);font-style:italic;}

/* ─── REPORTS MAP SECTION ─── */
#reportsMapSection{display:none;}
#reportsMapSection.show{display:block;}
.rmap-wrap{background:var(--card);border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1);animation:scaleIn 0.3s ease;transition:background 0.3s;isolation:isolate;}
.rmap-header{padding:18px 24px;border-bottom:1px solid var(--card-border);display:flex;align-items:center;gap:14px;}
.rmap-header-text h2{font-size:1.05rem;font-weight:700;color:var(--text);}
.rmap-header-text p{font-size:0.82rem;color:var(--muted);margin-top:2px;}
#reportsMapFull{height:calc(100vh - 230px);min-height:520px;width:100%;background:#dde8f0;}
.rmap-legend{padding:14px 20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;border-top:1px solid var(--card-border);background:var(--card);}

/* Hover popup in Reports Map */
.rm-popup .leaflet-popup-content-wrapper{border-radius:14px !important;box-shadow:0 8px 28px rgba(0,0,0,0.16) !important;padding:0 !important;border:none !important;}
.rm-popup .leaflet-popup-content{margin:0 !important;padding:0 !important;}
.rm-popup-inner{padding:14px 16px;min-width:230px;max-width:280px;font-family:'Poppins',sans-serif;}
.rm-popup-title{font-weight:800;font-size:0.93rem;color:#1a1a2e;margin-bottom:6px;line-height:1.4;}
.rm-popup-badge{display:inline-block;padding:2px 10px;border-radius:50px;font-size:0.68rem;font-weight:700;text-transform:uppercase;margin-bottom:8px;color:#fff;}
.rm-popup-meta{font-size:0.76rem;color:#666;display:flex;flex-direction:column;gap:4px;}
.rm-popup-meta span{display:flex;align-items:center;gap:5px;}
.rm-popup-desc{font-size:0.77rem;color:#444;margin-top:8px;padding-top:8px;border-top:1px solid #eee;line-height:1.6;}
.rm-popup-radius{font-size:0.72rem;color:#999;margin-top:6px;display:flex;align-items:center;gap:4px;}

/* ─── MODAL (Post Report) ─── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1000;justify-content:center;align-items:center;padding:20px;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;animation:fadeIn 0.25s ease;}
.modal{background:var(--modal-bg);border-radius:20px;padding:32px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;position:relative;animation:scaleIn 0.3s cubic-bezier(0.34,1.56,0.64,1);transition:background 0.3s;}
.modal h2{font-size:1.2rem;font-weight:800;color:var(--text);margin-bottom:4px;}
.modal .subtitle{font-size:0.84rem;color:var(--muted);margin-bottom:22px;}
.modal-close{position:absolute;top:16px;right:18px;background:var(--bg);border:none;font-size:1rem;color:var(--muted);cursor:pointer;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all 0.2s;}
.modal-close:hover{background:#e0e0e0;color:var(--text);}
.modal .form-group{margin-bottom:15px;}
.modal .form-group label{display:block;font-size:0.82rem;font-weight:600;color:#444;margin-bottom:5px;}
.modal .form-group input,.modal .form-group textarea,.modal .form-group select{width:100%;padding:11px 14px;border:1.5px solid var(--input-border);border-radius:9px;font-size:0.9rem;outline:none;transition:0.2s;font-family:'Poppins',sans-serif;resize:vertical;background:var(--input-bg);color:var(--text);}
.modal .form-group input:focus,.modal .form-group textarea:focus,.modal .form-group select:focus{border-color:var(--blue-light);background:var(--input-focus);box-shadow:0 0 0 3px rgba(58,141,255,0.1);}
.modal .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.status-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.status-opt{border:2px solid var(--input-border);border-radius:12px;padding:14px 8px;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--input-bg);}
.status-opt:hover{border-color:var(--blue-light);transform:translateY(-2px);}
.status-opt.selected.dangerous{border-color:var(--red);background:#fff0f0;}
.status-opt.selected.caution{border-color:var(--orange);background:#fff8f0;}
.status-opt.selected.safe{border-color:var(--green);background:#f0fff4;}
.status-opt i{display:block;font-size:1.4rem;margin-bottom:6px;}
.status-opt.dangerous i{color:var(--red);}.status-opt.caution i{color:var(--orange);}.status-opt.safe i{color:var(--green);}
.status-opt span{font-size:0.82rem;font-weight:700;color:var(--text);}
.modal-actions{display:flex;gap:12px;margin-top:22px;}
.btn-submit{flex:1;background:linear-gradient(135deg,var(--blue-light),var(--blue));color:#fff;border:none;padding:13px;border-radius:10px;font-size:0.95rem;font-weight:700;cursor:pointer;transition:all 0.25s;font-family:'Poppins',sans-serif;}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(28,87,178,0.4);}
.btn-submit:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
.btn-cancel{padding:13px 22px;border:1.5px solid var(--input-border);background:var(--card);border-radius:10px;font-size:0.95rem;cursor:pointer;transition:all 0.2s;font-family:'Poppins',sans-serif;font-weight:500;color:var(--text);}
.btn-cancel:hover{background:var(--bg);}
.modal-msg{padding:10px 14px;border-radius:9px;font-size:0.84rem;margin-bottom:14px;display:none;}
.modal-msg.success{background:#e8f5e9;color:#2e7d32;}.modal-msg.error{background:#ffebee;color:#c62828;}
.report-card.dangerous .badge{animation:pulse-glow 2s ease-in-out infinite;}
.picker-section{border:1.5px solid var(--input-border);border-radius:12px;overflow:hidden;margin-bottom:6px;background:#f8faff;transition:border-color 0.3s;}
.picker-header{padding:10px 14px;background:linear-gradient(90deg,#ebf2ff,#f8faff);display:flex;align-items:center;gap:8px;font-size:0.82rem;font-weight:600;color:var(--blue);border-bottom:1px solid var(--input-border);}
#pickerMap{height:240px;width:100%;background:#dde8f0;}
.picker-toolbar{padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--card);border-top:1px solid var(--card-border);}
.locate-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border:1.5px solid var(--blue-light);border-radius:8px;font-size:0.79rem;font-weight:600;color:var(--blue);background:#f0f6ff;cursor:pointer;transition:all 0.2s;font-family:'Poppins',sans-serif;}
.locate-btn:hover{background:#dbeafe;}.locate-btn:disabled{opacity:0.6;cursor:not-allowed;}
.clear-pin-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;border:1.5px solid var(--input-border);border-radius:8px;font-size:0.79rem;font-weight:600;color:#888;background:var(--card);cursor:pointer;transition:all 0.2s;font-family:'Poppins',sans-serif;}
.clear-pin-btn:hover{border-color:var(--red);color:var(--red);}
.pin-status{font-size:0.76rem;color:var(--muted);margin-left:auto;display:flex;align-items:center;gap:5px;}
.pin-status.set{color:var(--green);font-weight:600;}
.radius-row{padding:10px 14px 12px;background:var(--card);display:flex;align-items:center;gap:10px;border-top:1px solid var(--card-border);}
.radius-row label{font-size:0.79rem;font-weight:600;color:#555;white-space:nowrap;flex-shrink:0;}
.radius-row input[type=range]{flex:1;accent-color:var(--blue);height:4px;}
.radius-val{font-size:0.82rem;font-weight:700;color:var(--blue);min-width:60px;text-align:right;}

/* ─── PHOTO UPLOAD ─── */
.photo-upload-area{border:2px dashed var(--input-border);border-radius:10px;padding:18px 14px;text-align:center;cursor:pointer;color:var(--muted);font-size:0.84rem;transition:border-color 0.2s,background 0.2s;line-height:1.7;}
.photo-upload-area:hover{border-color:var(--blue-light);background:rgba(58,141,255,0.04);}
body.dark .photo-upload-area{background:var(--input-bg);}
.photo-thumb{position:relative;width:72px;height:72px;border-radius:8px;overflow:hidden;border:1.5px solid var(--input-border);flex-shrink:0;}
.photo-thumb img{width:100%;height:100%;object-fit:cover;}
.photo-thumb .remove-photo{position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.65);border:none;color:#fff;border-radius:50%;width:18px;height:18px;font-size:0.65rem;cursor:pointer;display:flex;align-items:center;justify-content:center;}

/* ─── MINI MAP MODAL ─── */
.mini-map-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1100;justify-content:center;align-items:center;padding:20px;backdrop-filter:blur(3px);}
.mini-map-modal.open{display:flex;animation:fadeIn 0.2s ease;}
.mini-map-box{background:var(--modal-bg);border-radius:18px;width:100%;max-width:640px;overflow:hidden;position:relative;animation:scaleIn 0.25s cubic-bezier(0.34,1.56,0.64,1);}
.mini-map-header{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--card-border);}
.mini-map-header h4{font-size:0.95rem;font-weight:700;color:var(--text);}
.mini-map-close{background:var(--bg);border:none;font-size:0.95rem;color:var(--muted);cursor:pointer;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all 0.2s;}
.mini-map-close:hover{background:#e0e0e0;}
#miniMap{height:360px;width:100%;}
.mini-map-footer{padding:12px 18px;font-size:0.79rem;color:var(--muted);background:var(--bg);display:flex;gap:16px;flex-wrap:wrap;}

/* ─── PROFILE MODAL ─── */
.profile-modal{max-width:500px;}
.avatar-picker{display:flex;flex-direction:column;align-items:center;gap:12px;margin-bottom:20px;padding:20px;background:var(--bg);border-radius:14px;}
.avatar-preview{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.7rem;font-weight:800;color:#fff;position:relative;cursor:pointer;transition:transform 0.2s;flex-shrink:0;}
.avatar-preview:hover{transform:scale(1.07);}
.avatar-edit-badge{position:absolute;bottom:0;right:0;width:22px;height:22px;background:var(--blue);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.6rem;color:#fff;border:2px solid var(--card);}
.color-palette{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;}
.color-swatch{width:28px;height:28px;border-radius:50%;cursor:pointer;border:3px solid transparent;transition:all 0.2s;flex-shrink:0;}
.color-swatch:hover{transform:scale(1.2);}
.color-swatch.selected{border-color:#fff;outline:2px solid var(--blue);outline-offset:2px;}
.profile-name-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.profile-divider{border:none;border-top:1px solid var(--card-border);margin:18px 0;}
.profile-section-label{font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--muted);margin-bottom:12px;}
.profile-msg{padding:10px 14px;border-radius:9px;font-size:0.84rem;margin-bottom:14px;display:none;}
.profile-msg.success{background:#e8f5e9;color:#2e7d32;}
.profile-msg.error{background:#ffebee;color:#c62828;}

/* ─── GPS LOCK STATE ─── */
.menu a.gps-locked{opacity:0.38;cursor:not-allowed;pointer-events:none;position:relative;}
.menu a.gps-locked::after{content:'GPS required';font-size:0.6rem;background:rgba(255,255,255,0.15);padding:1px 6px;border-radius:4px;margin-left:auto;letter-spacing:0.3px;white-space:nowrap;}
.gps-required-notice{background:rgba(255,200,0,0.13);border:1px solid rgba(255,200,0,0.3);border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:flex-start;gap:12px;animation:fadeInUp 0.4s both;}
.gps-required-notice i{color:#d97706;font-size:1.1rem;flex-shrink:0;margin-top:2px;}
.gps-required-notice p{font-size:0.83rem;color:var(--text);line-height:1.6;}
.gps-required-notice strong{color:#d97706;}
.gps-section{border:1.5px solid var(--input-border);border-radius:12px;padding:16px;background:var(--input-bg);margin-bottom:4px;}
.gps-section-header{display:flex;align-items:center;gap:8px;font-size:0.82rem;font-weight:700;color:var(--blue);margin-bottom:12px;}
.gps-coords-display{font-size:0.79rem;color:var(--muted);margin-bottom:10px;padding:8px 12px;background:var(--bg);border-radius:8px;display:flex;align-items:center;gap:6px;min-height:34px;}
.gps-coords-display.has-gps{color:var(--green);font-weight:600;}
body.dark .gps-section{background:#0d1117;}
body.dark .gps-required-notice{background:rgba(255,200,0,0.07);}
.get-gps-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:1.5px solid var(--green);border-radius:9px;font-size:0.84rem;font-weight:700;color:var(--green);background:#f0fff4;cursor:pointer;transition:all 0.2s;font-family:'Poppins',sans-serif;}
.get-gps-btn:hover{background:#dcfce7;transform:translateY(-1px);box-shadow:0 4px 12px rgba(56,161,105,0.25);}
.get-gps-btn:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
body.dark .get-gps-btn{background:#1a2e24;}
body.dark .get-gps-btn:hover{background:#14532d;}


/* ─── REPORT DETAIL OVERLAY ─── */
.detail-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.62);z-index:1200;justify-content:center;align-items:center;padding:20px;backdrop-filter:blur(4px);}
.detail-overlay.open{display:flex;animation:fadeIn 0.22s ease;}
.detail-modal{background:var(--modal-bg);border-radius:22px;width:100%;max-width:720px;max-height:92vh;overflow-y:auto;position:relative;animation:scaleIn 0.28s cubic-bezier(0.34,1.56,0.64,1);display:flex;flex-direction:column;transition:background 0.3s;}
.detail-header{padding:22px 24px 0;position:relative;}
.detail-close{position:absolute;top:16px;right:18px;background:var(--bg);border:none;font-size:1rem;color:var(--muted);cursor:pointer;width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;transition:all 0.2s;z-index:2;}
.detail-close:hover{background:#e0e0e0;color:var(--text);}
body.dark .detail-close:hover{background:#30363d;}
.detail-status-bar{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.detail-badge{padding:5px 14px;border-radius:50px;font-size:0.76rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#fff;}
.detail-badge.dangerous{background:var(--red);}
.detail-badge.caution{background:var(--orange);}
.detail-badge.safe{background:var(--green);}
.detail-cat-tag{font-size:0.76rem;background:var(--bg);padding:5px 12px;border-radius:8px;color:var(--muted);font-weight:600;}
.detail-title{font-size:1.18rem;font-weight:800;color:var(--text);line-height:1.4;margin-bottom:4px;padding-right:40px;}
.detail-reporter{font-size:0.79rem;color:var(--muted);margin-bottom:18px;}
.detail-reporter span{font-weight:600;color:var(--text);}
/* Photo gallery */
.detail-photos{display:flex;gap:10px;overflow-x:auto;padding:0 24px 16px;scrollbar-width:thin;}
.detail-photos::-webkit-scrollbar{height:5px;}.detail-photos::-webkit-scrollbar-track{background:var(--bg);border-radius:3px;}.detail-photos::-webkit-scrollbar-thumb{background:var(--input-border);border-radius:3px;}
.detail-photo{flex-shrink:0;width:220px;height:155px;border-radius:13px;overflow:hidden;border:2px solid var(--card-border);cursor:pointer;position:relative;transition:transform 0.2s,box-shadow 0.2s;}
.detail-photo:hover{transform:scale(1.03);box-shadow:0 8px 24px rgba(0,0,0,0.18);}
.detail-photo img{width:100%;height:100%;object-fit:cover;display:block;}
.detail-photo-count{position:absolute;bottom:7px;right:9px;background:rgba(0,0,0,0.55);color:#fff;font-size:0.68rem;font-weight:700;padding:2px 8px;border-radius:20px;backdrop-filter:blur(2px);}
/* Meta grid */
.detail-body{padding:4px 24px 24px;display:flex;flex-direction:column;gap:16px;}
.detail-meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.detail-meta-item{background:var(--bg);border-radius:11px;padding:12px 14px;}
.detail-meta-label{font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:var(--muted);margin-bottom:4px;}
.detail-meta-value{font-size:0.87rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:6px;}
.detail-meta-value i{font-size:0.85rem;flex-shrink:0;}
.detail-desc-box{background:var(--bg);border-radius:11px;padding:14px 16px;}
.detail-desc-label{font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:var(--muted);margin-bottom:8px;}
.detail-desc-text{font-size:0.88rem;color:var(--text);line-height:1.8;}
.detail-footer{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:16px 24px;border-top:1px solid var(--card-border);}
.detail-vote-wrap{display:flex;align-items:center;gap:8px;}
.detail-map-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:1.5px solid var(--blue-light);border-radius:10px;font-size:0.84rem;font-weight:700;color:var(--blue);background:#f0f6ff;cursor:pointer;transition:all 0.2s;font-family:'Poppins',sans-serif;}
.detail-map-btn:hover{background:#dbeafe;transform:translateY(-1px);}
body.dark .detail-map-btn{background:#1f3a5f;color:var(--blue-light);border-color:var(--blue);}
/* Lightbox */
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:1400;justify-content:center;align-items:center;cursor:zoom-out;}
.lightbox.open{display:flex;animation:fadeIn 0.18s ease;}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain;box-shadow:0 20px 60px rgba(0,0,0,0.6);}
.lightbox-close{position:absolute;top:18px;right:22px;background:rgba(255,255,255,0.12);border:none;color:#fff;font-size:1.3rem;cursor:pointer;width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;transition:background 0.2s;}
.lightbox-close:hover{background:rgba(255,255,255,0.22);}
/* Popup photo in map */
.map-popup-photo{width:100%;height:130px;object-fit:cover;border-radius:10px 10px 0 0;display:block;margin-bottom:0;}
.map-popup-photo-wrap{margin:-0px -0px 10px;overflow:hidden;border-radius:10px 10px 0 0;cursor:pointer;}
.map-popup-view-btn{display:block;width:100%;margin-top:10px;padding:7px 0;background:var(--blue,#1c57b2);color:#fff;border:none;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;text-align:center;transition:background 0.2s;}
.map-popup-view-btn:hover{background:#0e3d8c;}

@media(max-width:900px){
  .sidebar{transform:translateX(calc(-1*var(--sidebar-w)));}
  .sidebar.mobile-open{transform:translateX(0);}
  .main{margin-left:0;}
  .ham-btn{display:flex;}
  .stats-row{grid-template-columns:1fr 1fr;}
  .topbar{padding:12px 16px;}
  .topbar h1{font-size:1rem;}
  .content{padding:16px;}
  .user-name,.gps-chip{display:none;}
  .detail-meta-grid{grid-template-columns:1fr;}
  .detail-photo{width:180px;height:130px;}
  #mainMap,#reportsMapFull{height:420px;}
}
@media(max-width:600px){
  .stats-row{grid-template-columns:1fr 1fr;}
  .stat-card{padding:14px 16px;}.stat-card strong{font-size:1.3rem;}
  .modal .form-row,.profile-name-row{grid-template-columns:1fr;}
  .post-btn span{display:none;}.post-btn{padding:10px 13px;}
  .report-card{padding:16px 18px;}
  .card-meta{gap:8px;font-size:0.75rem;}
  .vote-btn{padding:6px 12px;font-size:0.78rem;}
  .modal{padding:24px 18px;}
  .view-btn span{display:none;}
  #mainMap{height:340px;}
  #toastContainer{left:12px;right:12px;bottom:12px;max-width:100%;}
}
@media(max-width:400px){.stats-row{grid-template-columns:1fr;}}
</style>
</head>
<body>

<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- GPS Toast Container -->
<div id="toastContainer"></div>

<!-- ════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="brand-logo">
      <div class="brand-icon-s" style="background:rgba(243,156,18,0.2);border-color:rgba(243,156,18,0.4);"><i class="fas fa-shield-halved" style="color:#f39c12;"></i></div>
      <div><div class="brand-name-s">SenTri</div><div style="font-size:0.6rem;color:rgba(255,255,255,0.5);letter-spacing:1px;text-transform:uppercase;">Community Portal</div></div>
    </div>
    <button class="toggle-btn" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
  </div>
  <nav class="menu">
    <!-- FIX A: active state is set server-side based on URL params -->
    <a href="dashboard.php" <?= (!$filter_my && $section==='feed') ? 'class="active"' : '' ?>><i class="fas fa-house"></i> Dashboard</a>
    <a href="dashboard.php?filter=my" <?= $filter_my ? 'class="active"' : '' ?>><i class="fas fa-file-lines"></i> My Reports</a>
    <a href="dashboard.php?section=reportsmap" id="rmapLink" <?= ($section==='reportsmap') ? 'class="active"' : (is_null($saved_gps_lat) ? 'class="gps-locked"' : '') ?>><i class="fas fa-map-location-dot"></i> Reports Map</a>
    <?php if ($role === 'admin'): ?>
    <a href="admin.php"><i class="fas fa-gauge"></i> Admin Panel</a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-stats">
    <div class="stat-label-s">Community Stats</div>
    <div class="stat-box"><div class="num"><?= $total_reports ?></div><div class="lbl">Active Reports</div></div>
    <div class="stat-box danger-box"><div class="num"><?= $danger_count ?></div><div class="lbl">Dangerous Areas</div></div>
    <div class="stat-box safe-box"><div class="num"><?= $safe_count ?></div><div class="lbl">Safe Areas</div></div>
  </div>
  <div class="sidebar-footer">
    <!-- FIX B: Dark Mode toggle persisted per-account via localStorage[userId] -->
    <div class="dm-row" onclick="toggleDarkMode()">
      <span class="dm-row-label"><i class="fas fa-moon"></i> Dark Mode</span>
      <label class="dm-switch" onclick="event.stopPropagation()">
        <input type="checkbox" id="dmCheck" onchange="toggleDarkMode()">
        <span class="dm-slider"></span>
      </label>
    </div>
    <a href="logout.php"><i class="fas fa-right-from-bracket"></i> Log Out</a>
  </div>
</aside>

<!-- ════════════════════════════════════════
     MAIN
════════════════════════════════════════ -->
<div class="main" id="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="ham-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <h1><i class="fas fa-map-location-dot" style="color:var(--blue-light);margin-right:8px;"></i><span id="topbarTitle">Community Feed</span></h1>
    </div>
    <div class="right-top">
      <!-- GPS Status Chip -->
      <div class="gps-chip" id="gpsChip" title="GPS proximity alert status">
        <span class="gps-dot" id="gpsDot"></span>
        <span id="gpsLabel">GPS Off</span>
      </div>
      <button class="post-btn" onclick="openModal()"><i class="fas fa-plus"></i> <span>Post Report</span></button>
      <!-- C: Profile button – click avatar to open profile editor -->
      <div class="user-info" onclick="openProfile()" title="Edit Profile">
        <div class="avatar" id="topAvatar" style="background:<?= htmlspecialchars($avatar_color) ?>"><?= strtoupper(substr($first_name,0,1).substr($last_name,0,1)) ?></div>
        <span class="user-name"><?= htmlspecialchars($first_name) ?></span>
      </div>
    </div>
  </div>

  <div class="content">
    <!-- STAT CARDS -->
    <div class="stats-row">
      <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-clipboard-list"></i></div><div><strong><?= $total_reports ?></strong><span>Total Reports</span></div></div>
      <div class="stat-card"><div class="stat-icon red"><i class="fas fa-circle-exclamation"></i></div><div><strong><?= $danger_count ?></strong><span>Dangerous Areas</span></div></div>
      <div class="stat-card"><div class="stat-icon green"><i class="fas fa-circle-check"></i></div><div><strong><?= $safe_count ?></strong><span>Safe Areas</span></div></div>
      <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-pen-to-square"></i></div><div><strong><?= $my_count ?></strong><span>My Reports</span></div></div>
    </div>

    <!-- C: REPORTS MAP SECTION -->
    <div id="reportsMapSection" <?= ($section==='reportsmap') ? 'class="show"' : '' ?>>
      <div class="rmap-wrap">
        <div class="rmap-header">
          <div class="stat-icon blue" style="flex-shrink:0;"><i class="fas fa-map-location-dot"></i></div>
          <div class="rmap-header-text">
            <h2>Community Reports Map</h2>
            <p>All active report pins – hover over a marker to see who reported it, when, and what it's about</p>
          </div>
        </div>
        <div id="reportsMapFull"></div>
        <?php if(is_null($saved_gps_lat)): ?>
        <div class="gps-required-notice" id="rmapGpsNotice">
          <i class="fas fa-triangle-exclamation"></i>
          <p><strong>GPS location required.</strong> Open your <em>Profile</em> (avatar in the top-right) and click <strong>Get GPS Data</strong> to unlock the Reports Map and real-time proximity alerts.</p>
        </div>
        <?php endif; ?>
        <div class="rmap-legend">
          <div class="legend-item"><div class="legend-dot dangerous"></div>Dangerous</div>
          <div class="legend-item"><div class="legend-dot caution"></div>Caution</div>
          <div class="legend-item"><div class="legend-dot safe"></div>Safe</div>
          <span class="map-info" id="rmapInfo"></span>
        </div>
      </div>
    </div>

    <!-- FILTERS + VIEW TOGGLE -->
    <div class="filters" id="feedFilters" <?= ($section==='reportsmap') ? 'style="display:none"' : '' ?>>
      <input type="text" id="searchInput" placeholder="Search location, city, keyword...">
      <select id="statusFilter">
        <option value="">All Statuses</option>
        <option value="dangerous">🔴 Dangerous</option>
        <option value="caution">🟠 Caution</option>
        <option value="safe">🟢 Safe</option>
      </select>
      <select id="categoryFilter">
        <option value="">All Categories</option>
        <option value="crime">Crime</option>
        <option value="accident">Accident</option>
        <option value="flooding">Flooding</option>
        <option value="fire">Fire</option>
        <option value="health">Health</option>
        <option value="infrastructure">Infrastructure</option>
        <option value="other">Other</option>
      </select>
      <!-- FIX A: My Reports button in filter bar – highlighted via JS toggle -->
      <button id="myBtn" onclick="toggleMyReports()"><i class="fas fa-user"></i> My Reports</button>
      <button onclick="resetFilters()"><i class="fas fa-rotate"></i> Reset</button>
      <div class="view-toggle">
        <button class="view-btn active" id="btnFeed" onclick="switchView('feed')"><i class="fas fa-list"></i> <span>Feed</span></button>
        <button class="view-btn" id="btnMap" onclick="switchView('map')"><i class="fas fa-map"></i> <span>Map</span></button>
      </div>
    </div>

    <!-- COMMUNITY MAP VIEW -->
    <div id="mapView">
      <div id="mainMap"></div>
      <div class="map-legend">
        <div class="legend-item"><div class="legend-dot dangerous"></div>Dangerous</div>
        <div class="legend-item"><div class="legend-dot caution"></div>Caution</div>
        <div class="legend-item"><div class="legend-dot safe"></div>Safe</div>
        <div class="legend-item"><div class="legend-dot unpinned"></div>No pin (text only)</div>
        <span class="map-info" id="mapInfo"></span>
      </div>
    </div>

    <!-- FEED -->
    <div id="feed" <?= ($section==='reportsmap') ? 'style="display:none"' : '' ?>>
      <div class="loading"><i class="fas fa-spinner"></i> Loading community reports...</div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════
     POST REPORT MODAL
════════════════════════════════════════ -->
<div class="modal-overlay" id="modalOverlay" onclick="outsideClose(event)">
  <div class="modal">
    <button class="modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
    <h2><i class="fas fa-map-pin" style="color:var(--blue);margin-right:8px;"></i>Post a Safety Report</h2>
    <p class="subtitle">Let the community know what you've observed in your area</p>
    <div id="modalMsg" class="modal-msg"></div>
    <form id="reportForm" novalidate>
      <div class="form-group"><label>Report Title *</label><input type="text" id="r_title" placeholder="e.g. Flooding near market area" maxlength="255" required></div>
      <div class="form-group">
        <label>Location Status *</label>
        <div class="status-grid">
          <div class="status-opt dangerous" onclick="selectStatus('dangerous')" id="opt_dangerous"><i class="fas fa-circle-exclamation"></i><span>Dangerous</span></div>
          <div class="status-opt caution"   onclick="selectStatus('caution')"   id="opt_caution"><i class="fas fa-triangle-exclamation"></i><span>Caution</span></div>
          <div class="status-opt safe"      onclick="selectStatus('safe')"      id="opt_safe"><i class="fas fa-circle-check"></i><span>Safe</span></div>
        </div>
        <input type="hidden" id="r_status">
      </div>
      <div class="form-group"><label>Category *</label>
        <select id="r_category">
          <option value="">-- Select --</option>
          <option value="crime">Crime</option><option value="accident">Accident</option>
          <option value="flooding">Flooding</option><option value="fire">Fire</option>
          <option value="health">Health</option><option value="infrastructure">Infrastructure</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="form-group"><label>Specific Location *</label><input type="text" id="r_location" placeholder="e.g. Corner Rizal St. & Mabini Ave." maxlength="255" required></div>
      <div class="form-row">
        <div class="form-group"><label>Barangay</label><input type="text" id="r_barangay" placeholder="Barangay" maxlength="150"></div>
        <div class="form-group"><label>City / Municipality *</label><input type="text" id="r_city" placeholder="e.g. Imus, Cavite" maxlength="150" required></div>
      </div>
      <div class="form-group"><label>Province / Region</label><input type="text" id="r_province" placeholder="e.g. Cavite" maxlength="150"></div>
      <div class="form-group">
        <label><i class="fas fa-map-location-dot" style="color:var(--blue-light);margin-right:5px;"></i>Pin Exact Location on Map</label>
        <div class="picker-section">
          <div class="picker-header"><i class="fas fa-crosshairs"></i> Click the map to drop a pin · Drag the pin to adjust · Scroll to zoom</div>
          <div id="pickerMap"></div>
          <div class="picker-toolbar">
            <button type="button" class="locate-btn" id="locateBtn" onclick="useMyLocation()"><i class="fas fa-location-crosshairs"></i> Use My Location</button>
            <button type="button" class="clear-pin-btn" id="clearPinBtn" onclick="clearPin()" style="display:none;"><i class="fas fa-xmark"></i> Clear Pin</button>
            <span class="pin-status" id="pinStatus"><i class="fas fa-circle-info"></i> No pin placed</span>
          </div>
          <div class="radius-row">
            <label><i class="fas fa-circle-dot" style="color:var(--blue-light);margin-right:4px;"></i>Affected radius:</label>
            <input type="range" id="radiusSlider" min="50" max="3000" step="50" value="200" oninput="onRadiusChange(this.value)">
            <span class="radius-val" id="radiusVal">200 m</span>
          </div>
        </div>
        <input type="hidden" id="r_latitude">
        <input type="hidden" id="r_longitude">
        <input type="hidden" id="r_radius_m" value="200">
      </div>
      <div class="form-group"><label>Description *</label><textarea id="r_description" placeholder="Describe what you observed in detail..." rows="4" maxlength="2000" required></textarea></div>
      <div class="form-group">
        <label><i class="fas fa-camera" style="color:var(--blue-light);margin-right:5px;"></i>Attach Photos <span style="font-weight:400;color:var(--muted);font-size:0.8rem;">(optional &middot; up to 3 &middot; max 5 MB each)</span></label>
        <div class="photo-upload-area" id="photoUploadArea" onclick="document.getElementById('r_photos').click()">
          <i class="fas fa-cloud-arrow-up" style="font-size:1.6rem;color:var(--blue-light);margin-bottom:6px;display:block;"></i>
          <span>Click to choose photos or drag &amp; drop</span><br>
          <span style="font-size:0.75rem;color:var(--muted);">JPG, PNG, WEBP &mdash; max 5 MB each</span>
        </div>
        <input type="file" id="r_photos" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display:none;" onchange="onPhotosChosen(this)">
        <div id="photoPreviewRow" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-submit" id="submitBtn"><i class="fas fa-paper-plane"></i> Submit Report</button>
      </div>
    </form>
  </div>
</div>

<!-- ════════════════════════════════════════
     MINI MAP MODAL
════════════════════════════════════════ -->
<div class="mini-map-modal" id="miniMapModal" onclick="closeMiniMap(event)">
  <div class="mini-map-box">
    <div class="mini-map-header">
      <h4 id="miniMapTitle">Location</h4>
      <button class="mini-map-close" onclick="closeMiniMapDirect()"><i class="fas fa-xmark"></i></button>
    </div>
    <div id="miniMap"></div>
    <div class="mini-map-footer" id="miniMapFooter"></div>
  </div>
</div>

<!-- ════════════════════════════════════════
     PROFILE MODAL (C)
════════════════════════════════════════ -->
<div class="modal-overlay" id="profileOverlay" onclick="outsideCloseProfile(event)">
  <div class="modal profile-modal">
    <button class="modal-close" onclick="closeProfile()"><i class="fas fa-xmark"></i></button>
    <h2><i class="fas fa-circle-user" style="color:var(--blue);margin-right:8px;"></i>Edit Profile</h2>
    <p class="subtitle">Customize your account and avatar</p>

    <div id="profileMsg" class="modal-msg"></div>

    <!-- Avatar Color Picker -->
    <div class="avatar-picker">
      <div class="avatar-preview" id="profileAvatarPreview" style="background:<?= htmlspecialchars($avatar_color) ?>">
        <span id="profileAvatarInitials"><?= strtoupper(substr($first_name,0,1).substr($last_name,0,1)) ?></span>
        <div class="avatar-edit-badge"><i class="fas fa-pen"></i></div>
      </div>
      <p style="font-size:0.79rem;color:var(--muted);">Choose your avatar color</p>
      <div class="color-palette" id="colorPalette"></div>
    </div>

    <div class="profile-section-label">Personal Information</div>
    <div class="profile-name-row">
      <div class="form-group"><label>First Name</label><input type="text" id="p_first" value="<?= htmlspecialchars($first_name) ?>" maxlength="100"></div>
      <div class="form-group"><label>Last Name</label><input type="text" id="p_last" value="<?= htmlspecialchars($last_name) ?>" maxlength="100"></div>
    </div>
    <div class="form-group"><label>Email Address</label><input type="email" id="p_email" value="<?= htmlspecialchars($user_email) ?>"></div>

    <hr class="profile-divider">

    <div class="profile-section-label"><i class="fas fa-location-crosshairs" style="margin-right:5px;color:var(--green);"></i>GPS Location</div>
    <div class="gps-section">
      <div class="gps-section-header"><i class="fas fa-satellite-dish"></i> Your Saved Location</div>
      <div class="gps-coords-display <?= !is_null($saved_gps_lat) ? 'has-gps' : '' ?>" id="gpsCoordsDisplay">
        <?php if(!is_null($saved_gps_lat)): ?>
          <i class="fas fa-circle-check"></i> <?= number_format((float)$saved_gps_lat,6) ?>, <?= number_format((float)$saved_gps_lng,6) ?>
        <?php else: ?>
          <i class="fas fa-circle-info"></i> No GPS location saved yet
        <?php endif; ?>
      </div>
      <p style="font-size:0.76rem;color:var(--muted);margin-bottom:10px;">Your GPS location unlocks the <strong>Reports Map</strong> and <strong>Real-time proximity alerts</strong>. It is saved to your account and is not shared publicly.</p>
      <button class="get-gps-btn" id="getGpsBtn" onclick="getGPSData()"><i class="fas fa-crosshairs"></i> Get GPS Data</button>
    </div>

    <div class="profile-section-label">Change Password <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:0.72rem;">(leave blank to keep current password)</span></div>
    <div class="form-group"><label>Current Password</label><input type="password" id="p_current_pw" placeholder="Enter your current password"></div>
    <div class="profile-name-row">
      <div class="form-group"><label>New Password</label><input type="password" id="p_new_pw" placeholder="Min. 8 characters"></div>
      <div class="form-group"><label>Confirm New Password</label><input type="password" id="p_confirm_pw" placeholder="Repeat new password"></div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn-cancel" onclick="closeProfile()">Cancel</button>
      <button type="button" class="btn-submit" id="profileSaveBtn" onclick="saveProfile()"><i class="fas fa-floppy-disk"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════
     REPORT DETAIL OVERLAY
════════════════════════════════════════ -->
<div class="detail-overlay" id="detailOverlay" onclick="outsideCloseDetail(event)">
  <div class="detail-modal" id="detailModal">
    <button class="detail-close" onclick="closeReportDetail()"><i class="fas fa-xmark"></i></button>
    <div class="detail-header">
      <div class="detail-status-bar">
        <span class="detail-badge" id="d_badge"></span>
        <span class="detail-cat-tag" id="d_cat_tag"></span>
      </div>
      <div class="detail-title" id="d_title"></div>
      <div class="detail-reporter" id="d_reporter"></div>
    </div>
    <div class="detail-photos" id="d_photos" style="display:none;"></div>
    <div class="detail-body">
      <div class="detail-meta-grid" id="d_meta_grid"></div>
      <div class="detail-desc-box" id="d_desc_box" style="display:none;">
        <div class="detail-desc-label"><i class="fas fa-align-left" style="margin-right:5px;"></i>Description</div>
        <div class="detail-desc-text" id="d_desc"></div>
      </div>
    </div>
    <div class="detail-footer" id="d_footer"></div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()"><i class="fas fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="Photo">
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ════════════════════════════════════════════════════════
// STATE & CONSTANTS
// ════════════════════════════════════════════════════════
let allReports = [], showMine = false, currentView = 'feed';
const MY_USER_ID   = <?= $user_id ?>;
const INIT_SECTION = '<?= htmlspecialchars($section) ?>';
const INIT_FILTER  = <?= $filter_my ? 'true' : 'false' ?>;
const SAVED_GPS_LAT = <?= is_null($saved_gps_lat) ? 'null' : (float)$saved_gps_lat ?>;
const SAVED_GPS_LNG = <?= is_null($saved_gps_lng) ? 'null' : (float)$saved_gps_lng ?>;

const S_COLOR = { dangerous:'#e53e3e', caution:'#dd6b20', safe:'#38a169' };
const S_FILL  = { dangerous:'rgba(229,62,62,0.15)', caution:'rgba(221,107,32,0.15)', safe:'rgba(56,161,105,0.15)' };

const AVATAR_COLORS = [
  '#1c57b2','#0e3d8c','#3a8dff','#7c3aed','#db2777',
  '#dc2626','#ea580c','#d97706','#16a34a','#0891b2',
  '#374151','#be185d','#0f766e','#7c2d12','#1d4ed8'
];

// ════════════════════════════════════════════════════════
// FIX B: DARK MODE – persisted per account via localStorage[userId]
// ════════════════════════════════════════════════════════
const DM_KEY = `hg_dm_${MY_USER_ID}`;

function applyDark(on) {
  document.body.classList.toggle('dark', on);
  document.getElementById('dmCheck').checked = on;
}

function toggleDarkMode() {
  const isDark = document.body.classList.toggle('dark');
  document.getElementById('dmCheck').checked = isDark;
  localStorage.setItem(DM_KEY, isDark ? '1' : '0');
}

// Restore on load
(function(){ if(localStorage.getItem(DM_KEY)==='1') applyDark(true); })();

// ════════════════════════════════════════════════════════
// SIDEBAR
// ════════════════════════════════════════════════════════
function isMobile(){ return window.innerWidth <= 900; }

// Clean up sidebar state on window resize to avoid stuck states
window.addEventListener('resize', ()=>{
  const sb   = document.getElementById('sidebar');
  const ov   = document.getElementById('overlay');
  const main = document.querySelector('.main');
  const ham  = document.querySelector('.ham-btn');
  if(!isMobile()){
    // Switching to desktop: clear mobile classes, restore margin unless closed
    sb.classList.remove('mobile-open');
    ov.classList.remove('show');
    if(!sb.classList.contains('closed')){
      main.style.marginLeft = 'var(--sidebar-w)';
      if(ham) ham.style.display = 'none';
    }
  } else {
    // Switching to mobile: clear desktop closed state, reset margin
    sb.classList.remove('closed');
    main.style.marginLeft = '';
    if(ham) ham.style.display = '';
  }
});

function openSidebar(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('overlay');
  const main = document.querySelector('.main');
  if(isMobile()){
    sb.classList.add('mobile-open');
    ov.classList.add('show');
  } else {
    sb.classList.remove('closed');
    main.style.marginLeft = 'var(--sidebar-w)';
    // Hide the topbar hamburger on desktop once sidebar is open
    const ham = document.querySelector('.ham-btn');
    if(ham) ham.style.display = 'none';
  }
}

function closeSidebar(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('overlay');
  const main = document.querySelector('.main');
  if(isMobile()){
    sb.classList.remove('mobile-open');
    ov.classList.remove('show');
  } else {
    sb.classList.add('closed');
    main.style.marginLeft = '0';
    // Show the topbar hamburger on desktop so user can reopen
    const ham = document.querySelector('.ham-btn');
    if(ham) ham.style.display = 'flex';
  }
}

// ════════════════════════════════════════════════════════
// SECTION ROUTING
// ════════════════════════════════════════════════════════
function activateSection(s) {
  const rmap = document.getElementById('reportsMapSection');
  const feed = document.getElementById('feed');
  const filt = document.getElementById('feedFilters');
  const mview= document.getElementById('mapView');

  if(s === 'reportsmap') {
    feed.style.display  = 'none';
    filt.style.display  = 'none';
    mview.classList.remove('show');
    rmap.classList.add('show');
    document.getElementById('topbarTitle').textContent = 'Reports Map';
    // Always init the map (it handles duplicate init internally)
    // Use a small delay so the container is visible and Leaflet can measure it
    setTimeout(() => initReportsMap(), 80);
  } else {
    rmap.classList.remove('show');
    feed.style.display  = '';
    filt.style.display  = '';
    document.getElementById('topbarTitle').textContent = 'Community Feed';
  }
}

// ════════════════════════════════════════════════════════
// FETCH & RENDER FEED
// ════════════════════════════════════════════════════════
async function fetchReports() {
  try {
    const res  = await fetch('api/reports.php?action=get_reports');
    const data = await res.json();
    if(data.status === 'success') {
      allReports = data.reports;
      renderFeed();
      if(currentView === 'map') renderMainMap();
      if(document.getElementById('reportsMapSection').classList.contains('show')) initReportsMap();
      checkGPSProximity();
    } else {
      document.getElementById('feed').innerHTML = '<div class="empty"><i class="fas fa-triangle-exclamation"></i><p>Failed to load reports.</p></div>';
    }
  } catch {
    document.getElementById('feed').innerHTML = '<div class="empty"><i class="fas fa-wifi"></i><p>Network error. Please refresh.</p></div>';
  }
}

const catIcons = {crime:'fa-user-shield',accident:'fa-car-burst',flooding:'fa-water',fire:'fa-fire',health:'fa-heart-pulse',infrastructure:'fa-road',other:'fa-circle-info'};

function getFiltered() {
  const search   = document.getElementById('searchInput').value.trim().toLowerCase();
  const status   = document.getElementById('statusFilter').value;
  const category = document.getElementById('categoryFilter').value;
  return allReports.filter(r => {
    if(showMine && r.user_id != MY_USER_ID) return false;
    if(status && r.status !== status) return false;
    if(category && r.category !== category) return false;
    if(search){ const hay=(r.title+r.location_name+r.city+(r.barangay||'')+(r.description||'')).toLowerCase(); if(!hay.includes(search)) return false; }
    return true;
  });
}

function renderFeed() {
  const feed     = document.getElementById('feed');
  const filtered = getFiltered();
  if(!filtered.length){ feed.innerHTML='<div class="empty"><i class="fas fa-binoculars"></i><p>No reports found. Try adjusting your filters.</p></div>'; return; }
  feed.innerHTML = filtered.map((r,i) => {
    const date    = new Date(r.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    const catIcon = catIcons[r.category] || 'fa-circle-info';
    const isMine  = r.user_id == MY_USER_ID;
    const upVoted = r.user_vote==='up', downVoted = r.user_vote==='down';
    const hasPin  = r.latitude && r.longitude;
    const hasPhotos = r.images && r.images.length > 0;
    return `<div class="report-card ${r.status}" id="card_${r.id}" style="animation-delay:${i*0.04}s;cursor:pointer;" onclick="openReportDetail(${r.id})">
      <div class="card-header"><h3>${esc(r.title)}</h3><span class="badge ${r.status}">${ucFirst(r.status)}</span></div>
      <div class="card-meta">
        <span><i class="fas fa-map-location-dot"></i>${esc(r.location_name)}</span>
        <span><i class="fas fa-city"></i>${esc(r.city)}${r.province?', '+esc(r.province):''}</span>
        <span><i class="fas fa-clock"></i>${date}</span>
        <span><i class="fas fa-user"></i>${esc(r.poster_name)}</span>
        ${hasPhotos?`<span><i class="fas fa-camera" style="color:var(--blue-light);"></i>${r.images.length} photo${r.images.length>1?'s':''}</span>`:''}
      </div>
      <div class="card-body"><p>${esc((r.description||'').substring(0,200))}${(r.description||'').length>200?'…':''}</p></div>
      <div class="card-footer" onclick="event.stopPropagation()">
        <button class="vote-btn ${upVoted?'voted':''}" onclick="vote(${r.id},'up')"><i class="fas fa-thumbs-up"></i><span id="up_${r.id}">${r.upvotes}</span></button>
        <button class="vote-btn down ${downVoted?'voted':''}" onclick="vote(${r.id},'down')"><i class="fas fa-thumbs-down"></i><span id="down_${r.id}">${r.downvotes}</span></button>
        ${hasPin ? `<button class="map-pin-chip" onclick="openMiniMap(${r.id})"><i class="fas fa-map-pin"></i> View on Map</button>` : ''}
        <button class="map-pin-chip" style="background:var(--bg);color:var(--muted);border:1.5px solid var(--input-border);margin-left:auto;" onclick="openReportDetail(${r.id})"><i class="fas fa-expand-alt"></i> Details</button>
        ${isMine
          ? `<button class="vote-btn" onclick="deleteReport(${r.id})" style="border-color:var(--red);color:var(--red);"><i class="fas fa-trash-can"></i></button>`
          : `<span class="category-tag"><i class="fas ${catIcon}"></i> ${ucFirst(r.category)}</span>`}
      </div>
    </div>`;
  }).join('');
}

let searchTmr;
document.getElementById('searchInput').addEventListener('input',()=>{ clearTimeout(searchTmr); searchTmr=setTimeout(()=>{ renderFeed(); if(currentView==='map') renderMainMap(); },300); });
document.getElementById('statusFilter').addEventListener('change',()=>{ renderFeed(); if(currentView==='map') renderMainMap(); });
document.getElementById('categoryFilter').addEventListener('change',()=>{ renderFeed(); if(currentView==='map') renderMainMap(); });

// ════════════════════════════════════════════════════════
// FIX A: My Reports button highlight (toggle .active class)
// ════════════════════════════════════════════════════════
function toggleMyReports() {
  showMine = !showMine;
  document.getElementById('myBtn').classList.toggle('active', showMine);
  renderFeed();
  if(currentView === 'map') renderMainMap();
}

function resetFilters() {
  document.getElementById('searchInput').value   = '';
  document.getElementById('statusFilter').value  = '';
  document.getElementById('categoryFilter').value = '';
  showMine = false;
  document.getElementById('myBtn').classList.remove('active');
  renderFeed();
  if(currentView === 'map') renderMainMap();
}

async function vote(id, voteType) {
  const fd = new FormData();
  fd.append('action','vote'); fd.append('report_id',id); fd.append('vote',voteType);
  try {
    const res  = await fetch('api/reports.php',{method:'POST',body:fd});
    const data = await res.json();
    if(data.status==='success'){
      const rep = allReports.find(r=>r.id==id);
      if(rep){ rep.upvotes=data.upvotes; rep.downvotes=data.downvotes; rep.user_vote=data.user_vote; }
      renderFeed();
    }
  } catch {}
}

async function deleteReport(id) {
  if(!confirm('Delete this report?')) return;
  const fd = new FormData();
  fd.append('action','delete_report'); fd.append('report_id',id);
  try {
    const res  = await fetch('api/reports.php',{method:'POST',body:fd});
    const data = await res.json();
    if(data.status==='success'){ allReports=allReports.filter(r=>r.id!=id); renderFeed(); }
  } catch {}
}

// ════════════════════════════════════════════════════════
// VIEW TOGGLE (feed / map)
// ════════════════════════════════════════════════════════
function switchView(v) {
  currentView = v;
  const feedEl = document.getElementById('feed');
  const mapEl  = document.getElementById('mapView');
  if(v === 'map') {
    feedEl.style.display = 'none';
    mapEl.classList.add('show');
    document.getElementById('btnFeed').classList.remove('active');
    document.getElementById('btnMap').classList.add('active');
    initMainMap();
  } else {
    feedEl.style.display = '';
    mapEl.classList.remove('show');
    document.getElementById('btnMap').classList.remove('active');
    document.getElementById('btnFeed').classList.add('active');
  }
}

// ════════════════════════════════════════════════════════
// COMMUNITY MAP VIEW (feed)
// ════════════════════════════════════════════════════════
let mainMap = null, mainLayers = [];

function makeMarkerIcon(status) {
  const c = S_COLOR[status] || '#888';
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="38" viewBox="0 0 30 38"><path d="M15 0C6.716 0 0 6.716 0 15c0 10 15 23 15 23S30 25 30 15C30 6.716 23.284 0 15 0z" fill="${c}" stroke="white" stroke-width="2"/><circle cx="15" cy="15" r="6" fill="white" opacity="0.9"/></svg>`;
  return L.divIcon({html:svg,className:'',iconSize:[30,38],iconAnchor:[15,38],popupAnchor:[0,-38]});
}

function initMainMap() {
  if(!mainMap) {
    mainMap = L.map('mainMap').setView([14.5995,120.9842],11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',maxZoom:19}).addTo(mainMap);
  }
  renderMainMap();
}

function renderMainMap() {
  if(!mainMap) return;
  mainLayers.forEach(l=>mainMap.removeLayer(l)); mainLayers=[];
  const filtered = getFiltered();
  const bounds=[]; let pinCount=0;
  filtered.forEach(r=>{
    if(!r.latitude||!r.longitude) return;
    pinCount++;
    const ll = [r.latitude,r.longitude]; bounds.push(ll);
    const circle = L.circle(ll,{radius:r.radius_m||200,color:S_COLOR[r.status]||'#888',fillColor:S_FILL[r.status]||'rgba(136,136,136,0.15)',fillOpacity:1,weight:2,dashArray:'6 4'}).addTo(mainMap);
    mainLayers.push(circle);
    const date   = new Date(r.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});
    const marker = L.marker(ll,{icon:makeMarkerIcon(r.status)}).addTo(mainMap);
    const firstPhoto = (r.images && r.images.length > 0) ? r.images[0] : null;
    marker.bindPopup(`<div style="min-width:210px;max-width:260px;font-family:'Poppins',sans-serif;font-size:0.82rem;line-height:1.5;overflow:hidden;border-radius:12px;">
      ${firstPhoto ? `<div class="map-popup-photo-wrap"><img class="map-popup-photo" src="${esc(firstPhoto)}" alt="photo" onerror="this.parentElement.style.display='none'"></div>` : ''}
      <div style="padding:${firstPhoto?'10px':'0'} 12px 10px;">
        <div style="font-weight:800;font-size:0.93rem;color:#1a1a2e;margin-bottom:6px;">${esc(r.title)}</div>
        <span style="display:inline-block;background:${S_COLOR[r.status]};color:#fff;padding:2px 10px;border-radius:50px;font-size:0.69rem;font-weight:700;text-transform:uppercase;margin-bottom:8px;">${ucFirst(r.status)}</span>
        <div style="color:#555;"><i class="fas fa-map-location-dot" style="margin-right:4px;color:${S_COLOR[r.status]};"></i>${esc(r.location_name)}, ${esc(r.city)}</div>
        <div style="color:#888;font-size:0.74rem;margin-top:3px;">${date} · ${esc(r.poster_name)}</div>
        <div style="margin-top:8px;padding-top:8px;border-top:1px solid #eee;color:#444;">${esc((r.description||'').substring(0,120))}${(r.description||'').length>120?'…':''}</div>
        <div style="margin-top:7px;font-size:0.73rem;color:#999;"><i class="fas fa-circle-dot"></i> Radius: ${r.radius_m||200}m${r.images&&r.images.length>0?' · <i class="fas fa-camera"></i> '+r.images.length+' photo'+(r.images.length>1?'s':''):''}</div>
        <button class="map-popup-view-btn" onclick="openReportDetail(${r.id})"><i class="fas fa-expand-alt"></i> Full Details</button>
      </div>
    </div>`,{maxWidth:280});
    mainLayers.push(marker);
  });
  if(bounds.length) mainMap.fitBounds(bounds,{padding:[50,50],maxZoom:15});
  document.getElementById('mapInfo').textContent = `${pinCount} of ${filtered.length} report${filtered.length!==1?'s':''} pinned on map`;
  setTimeout(()=>mainMap.invalidateSize(),60);
}

// ════════════════════════════════════════════════════════
// C: REPORTS MAP SECTION — hover tooltips
// ════════════════════════════════════════════════════════
let reportsMap = null, rmLayers = [];

function initReportsMap() {
  if(!reportsMap) {
    reportsMap = L.map('reportsMapFull').setView([14.5995,120.9842],11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',maxZoom:19}).addTo(reportsMap);
  }
  renderReportsMap();
}

function renderReportsMap() {
  if(!reportsMap) return;
  rmLayers.forEach(l=>reportsMap.removeLayer(l)); rmLayers=[];
  // Hide GPS notice if it exists (GPS was unlocked after page load)
  const notice = document.getElementById('rmapGpsNotice');
  if(notice) notice.style.display='none';
  const bounds=[]; let pinCount=0;

  allReports.forEach(r=>{
    if(!r.latitude||!r.longitude) return;
    pinCount++;
    const ll   = [r.latitude,r.longitude]; bounds.push(ll);
    const date = new Date(r.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    const bStyle = `background:${S_COLOR[r.status]||'#888'};color:#fff;padding:2px 10px;border-radius:50px;font-size:0.68rem;font-weight:700;text-transform:uppercase;display:inline-block;margin-bottom:8px;`;

    const circle = L.circle(ll,{radius:r.radius_m||200,color:S_COLOR[r.status]||'#888',fillColor:S_FILL[r.status]||'rgba(136,136,136,0.15)',fillOpacity:1,weight:2,dashArray:'6 4'}).addTo(reportsMap);
    rmLayers.push(circle);

    const marker = L.marker(ll,{icon:makeMarkerIcon(r.status)}).addTo(reportsMap);
    rmLayers.push(marker);

    const firstPhoto = (r.images && r.images.length > 0) ? r.images[0] : null;

    // Tooltip (hover) — compact card with photo
    const tooltipHtml = `<div class="rm-popup-inner">
      ${firstPhoto ? `<div style="margin:-14px -16px 10px;overflow:hidden;border-radius:10px 10px 0 0;"><img src="${esc(firstPhoto)}" alt="photo" style="width:100%;height:120px;object-fit:cover;display:block;" onerror="this.parentElement.style.display='none'"></div>` : ''}
      <div class="rm-popup-title">${esc(r.title)}</div>
      <span style="${bStyle}">${ucFirst(r.status)}</span>
      <div class="rm-popup-meta">
        <span><i class="fas fa-user" style="color:${S_COLOR[r.status]};width:14px;text-align:center;"></i>&nbsp;<strong>Reported by:</strong>&nbsp;${esc(r.poster_name)}</span>
        <span><i class="fas fa-clock" style="color:#888;width:14px;text-align:center;"></i>&nbsp;${date}</span>
        <span><i class="fas fa-map-location-dot" style="color:#888;width:14px;text-align:center;"></i>&nbsp;${esc(r.location_name)}, ${esc(r.city)}</span>
        ${r.images&&r.images.length>0?`<span><i class="fas fa-camera" style="color:var(--blue,#1c57b2);width:14px;text-align:center;"></i>&nbsp;${r.images.length} photo${r.images.length>1?'s':''}</span>`:''}
      </div>
      <div class="rm-popup-desc">${esc((r.description||'').substring(0,200))}${(r.description||'').length>200?'…':''}</div>
      <div class="rm-popup-radius"><i class="fas fa-circle-dot" style="color:${S_COLOR[r.status]};"></i>&nbsp;Affected radius: ${r.radius_m||200}m</div>
    </div>`;

    // Click popup — same content plus "Full Details" button
    const clickHtml = `<div class="rm-popup-inner">
      ${firstPhoto ? `<div style="margin:-14px -16px 10px;overflow:hidden;border-radius:10px 10px 0 0;"><img src="${esc(firstPhoto)}" alt="photo" style="width:100%;height:140px;object-fit:cover;display:block;" onerror="this.parentElement.style.display='none'"></div>` : ''}
      <div class="rm-popup-title">${esc(r.title)}</div>
      <span style="${bStyle}">${ucFirst(r.status)}</span>
      <div class="rm-popup-meta">
        <span><i class="fas fa-user" style="color:${S_COLOR[r.status]};width:14px;text-align:center;"></i>&nbsp;<strong>Reported by:</strong>&nbsp;${esc(r.poster_name)}</span>
        <span><i class="fas fa-clock" style="color:#888;width:14px;text-align:center;"></i>&nbsp;${date}</span>
        <span><i class="fas fa-map-location-dot" style="color:#888;width:14px;text-align:center;"></i>&nbsp;${esc(r.location_name)}, ${esc(r.city)}</span>
        ${r.images&&r.images.length>0?`<span><i class="fas fa-camera" style="color:var(--blue,#1c57b2);width:14px;text-align:center;"></i>&nbsp;${r.images.length} photo${r.images.length>1?'s':''}</span>`:''}
      </div>
      <div class="rm-popup-desc">${esc((r.description||'').substring(0,200))}${(r.description||'').length>200?'…':''}</div>
      <div class="rm-popup-radius"><i class="fas fa-circle-dot" style="color:${S_COLOR[r.status]};"></i>&nbsp;Affected radius: ${r.radius_m||200}m</div>
      <button class="map-popup-view-btn" onclick="openReportDetail(${r.id})"><i class="fas fa-expand-alt"></i> Full Details</button>
    </div>`;

    // Hover tooltip (shows on mouseover)
    marker.bindTooltip(tooltipHtml,{direction:'top',offset:[0,-38],opacity:1,className:'rm-popup',sticky:false,permanent:false});
    // Click popup (with full details button)
    marker.bindPopup(clickHtml,{maxWidth:300,className:'rm-popup'});
  });

  if(bounds.length) reportsMap.fitBounds(bounds,{padding:[50,50],maxZoom:14});
  document.getElementById('rmapInfo').textContent = `${pinCount} pinned report${pinCount!==1?'s':''}`;
  setTimeout(()=>reportsMap.invalidateSize(),120);
}

// ════════════════════════════════════════════════════════
// GPS STATE & LOCK GATE
// ════════════════════════════════════════════════════════
let userLat = SAVED_GPS_LAT, userLng = SAVED_GPS_LNG;
let hasGPSData = (SAVED_GPS_LAT !== null);
const alertTimes = {};
const ALERT_COOLDOWN_MS = 5 * 60 * 1000; // 5 min per report

// Update Reports Map link lock state + GPS chip
function updateGPSLock() {
  const link   = document.getElementById('rmapLink');
  const chip   = document.getElementById('gpsChip');
  const label  = document.getElementById('gpsLabel');

  if(hasGPSData) {
    link.classList.remove('gps-locked');
    chip.className = 'gps-chip active';
    label.textContent = 'GPS Active';
    // Hide GPS notice in reports map section if visible
    const notice = document.getElementById('rmapGpsNotice');
    if(notice) notice.style.display = 'none';
  } else {
    link.classList.add('gps-locked');
    chip.className = 'gps-chip';
    label.textContent = 'GPS Required';
  }
}

function haversine(lat1,lng1,lat2,lng2){
  const R=6371000, dLat=(lat2-lat1)*Math.PI/180, dLng=(lng2-lng1)*Math.PI/180;
  const a=Math.sin(dLat/2)**2+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)**2;
  return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

function startGPS() {
  if(!navigator.geolocation) { updateGPSLock(); return; }
  const chip=document.getElementById('gpsChip'), label=document.getElementById('gpsLabel');
  navigator.geolocation.watchPosition(
    pos => {
      userLat = pos.coords.latitude;
      userLng = pos.coords.longitude;
      if(!hasGPSData) { hasGPSData = true; updateGPSLock(); }
      chip.className='gps-chip active'; label.textContent='GPS Active';
      checkGPSProximity();
    },
    () => {
      // Browser GPS failed – fall back to saved GPS if available
      updateGPSLock();
      if(!hasGPSData) { chip.className='gps-chip error'; label.textContent='GPS Required'; }
    },
    {enableHighAccuracy:true, maximumAge:15000, timeout:20000}
  );
}

function checkGPSProximity() {
  if(!hasGPSData || userLat===null) return;  // gated – no GPS data means no alerts
  const now=Date.now();
  allReports.forEach(r=>{
    if(!r.latitude||!r.longitude) return;
    const dist = haversine(userLat,userLng,r.latitude,r.longitude);
    const rad  = r.radius_m||200;
    if(dist<=rad){
      if(now-(alertTimes[r.id]||0) < ALERT_COOLDOWN_MS) return;
      alertTimes[r.id]=now;
      showProximityToast(r, Math.round(dist));
    }
  });
}

function showProximityToast(r, distM) {
  const icons={dangerous:'fa-circle-exclamation',caution:'fa-triangle-exclamation',safe:'fa-circle-check'};
  const dist = distM<1000 ? `${distM}m` : `${(distM/1000).toFixed(1)}km`;
  const toast = document.createElement('div');
  toast.className = `toast ${r.status}`;
  toast.innerHTML = `
    <div class="toast-icon"><i class="fas ${icons[r.status]||'fa-location-dot'}"></i></div>
    <div>
      <div class="toast-title">⚠️ Nearby ${ucFirst(r.status)} Report</div>
      <div class="toast-body"><strong>${esc(r.title)}</strong><br>You are ${dist} away · ${esc(r.city)}<br><em>${esc((r.description||'').substring(0,90))}${(r.description||'').length>90?'…':''}</em></div>
    </div>
    <button class="toast-close" onclick="dismissToast(this.closest('.toast'))"><i class="fas fa-xmark"></i></button>
    <div class="toast-progress"></div>`;
  document.getElementById('toastContainer').appendChild(toast);
  setTimeout(()=>dismissToast(toast), 5200);
}

function dismissToast(t){
  if(!t||!t.parentElement) return;
  t.classList.add('fade-out');
  setTimeout(()=>{ if(t.parentElement) t.parentElement.removeChild(t); },400);
}

// ════════════════════════════════════════════════════════
// MINI MAP MODAL (from feed card)
// ════════════════════════════════════════════════════════
let miniMap=null, miniLayers=[];

function openMiniMap(id){
  const r=allReports.find(x=>x.id==id); if(!r||!r.latitude||!r.longitude) return;
  document.getElementById('miniMapModal').classList.add('open');
  document.getElementById('miniMapTitle').textContent=r.title;
  document.getElementById('miniMapFooter').innerHTML=`
    <span><i class="fas fa-map-location-dot"></i> ${esc(r.location_name)}, ${esc(r.city)}${r.province?', '+esc(r.province):''}</span>
    <span><i class="fas fa-circle-dot"></i> Affected radius: ${r.radius_m||200}m</span>
    <span style="color:${S_COLOR[r.status]};font-weight:700;"><i class="fas fa-circle-exclamation"></i> ${ucFirst(r.status)}</span>`;
  setTimeout(()=>{
    if(!miniMap){ miniMap=L.map('miniMap'); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(miniMap); }
    miniLayers.forEach(l=>miniMap.removeLayer(l)); miniLayers=[];
    const ll=[r.latitude,r.longitude];
    const circle=L.circle(ll,{radius:r.radius_m||200,color:S_COLOR[r.status]||'#888',fillColor:S_FILL[r.status]||'rgba(136,136,136,0.15)',fillOpacity:1,weight:2,dashArray:'6 4'}).addTo(miniMap);
    const marker=L.marker(ll,{icon:makeMarkerIcon(r.status)}).addTo(miniMap).bindPopup(esc(r.title)).openPopup();
    miniLayers.push(circle,marker);
    miniMap.setView(ll,16); miniMap.invalidateSize();
  },150);
}
function closeMiniMap(e){ if(e.target===document.getElementById('miniMapModal')) closeMiniMapDirect(); }
function closeMiniMapDirect(){ document.getElementById('miniMapModal').classList.remove('open'); }

// ════════════════════════════════════════════════════════
// POST REPORT MODAL
// ════════════════════════════════════════════════════════
function openModal(){ document.getElementById('modalOverlay').classList.add('open'); setTimeout(initPickerMap,200); }
function closeModal(){
  document.getElementById('modalOverlay').classList.remove('open');
  document.getElementById('reportForm').reset();
  clearStatusSelection();
  document.getElementById('modalMsg').style.display='none';
  clearPin();
  // Reset photo previews
  document.getElementById('photoPreviewRow').innerHTML='';
  const ph=document.getElementById('r_photos'); if(ph) ph.value='';
}

// ─── PHOTO UPLOAD HELPERS ────────────────────────────────────────────────
function onPhotosChosen(input){
  const row=document.getElementById('photoPreviewRow');
  row.innerHTML='';
  const files=Array.from(input.files).slice(0,3);
  files.forEach((file,idx)=>{
    const reader=new FileReader();
    reader.onload=e=>{
      const wrap=document.createElement('div'); wrap.className='photo-thumb';
      const img=document.createElement('img'); img.src=e.target.result; img.alt='preview';
      const btn=document.createElement('button'); btn.className='remove-photo';
      btn.innerHTML='<i class="fas fa-xmark"></i>';
      btn.onclick=ev=>{ ev.stopPropagation(); removePhoto(idx); };
      wrap.appendChild(img); wrap.appendChild(btn); row.appendChild(wrap);
    };
    reader.readAsDataURL(file);
  });
}

function removePhoto(idx){
  const input=document.getElementById('r_photos');
  const dt=new DataTransfer();
  Array.from(input.files).forEach((f,i)=>{ if(i!==idx) dt.items.add(f); });
  input.files=dt.files;
  onPhotosChosen(input);
}
function outsideClose(e){ if(e.target===document.getElementById('modalOverlay')) closeModal(); }
function selectStatus(s){ clearStatusSelection(); document.getElementById('opt_'+s).classList.add('selected'); document.getElementById('r_status').value=s; }
function clearStatusSelection(){ ['dangerous','caution','safe'].forEach(s=>document.getElementById('opt_'+s).classList.remove('selected')); document.getElementById('r_status').value=''; }

document.getElementById('reportForm').addEventListener('submit',async function(e){
  e.preventDefault();
  const msgEl=document.getElementById('modalMsg'), btn=document.getElementById('submitBtn');
  const title=document.getElementById('r_title').value.trim(), status=document.getElementById('r_status').value,
        category=document.getElementById('r_category').value, location=document.getElementById('r_location').value.trim(),
        city=document.getElementById('r_city').value.trim(), desc=document.getElementById('r_description').value.trim();
  if(!title||!status||!category||!location||!city||!desc){ showMsg(msgEl,'error','Please fill in all required fields and choose a status.'); return; }
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Submitting...';
  const fd=new FormData();
  fd.append('action','post_report'); fd.append('title',title); fd.append('status',status); fd.append('category',category);
  fd.append('location_name',location); fd.append('barangay',document.getElementById('r_barangay').value.trim());
  fd.append('city',city); fd.append('province',document.getElementById('r_province').value.trim());
  fd.append('description',desc); fd.append('latitude',document.getElementById('r_latitude').value);
  fd.append('longitude',document.getElementById('r_longitude').value); fd.append('radius_m',document.getElementById('r_radius_m').value);
  // Attach selected photos
  const photoInput = document.getElementById('r_photos');
  if(photoInput && photoInput.files.length > 0){
    const maxPhotos = Math.min(photoInput.files.length, 3);
    for(let pi=0; pi<maxPhotos; pi++) fd.append('photos[]', photoInput.files[pi]);
  }
  try{
    const res=await fetch('api/reports.php',{method:'POST',body:fd}); const data=await res.json();
    if(data.status==='success'){
      showMsg(msgEl,'success','Report posted!');
      // Auto-notify emergency contacts if report is Dangerous
      if(status==='dangerous' && data.id){
        const nfd=new FormData(); nfd.append('action','notify_report'); nfd.append('report_id',data.id);
        fetch('api/contacts.php',{method:'POST',body:nfd}).catch(()=>{});
      }
      setTimeout(()=>{ closeModal(); fetchReports(); },1000);
    } else showMsg(msgEl,'error',data.message||'Failed to submit.');
  }catch{ showMsg(msgEl,'error','Network error. Please try again.'); }
  btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit Report';
});

// ════════════════════════════════════════════════════════
// MAP PICKER
// ════════════════════════════════════════════════════════
let pickerMap=null, pickerMarker=null, pickerCircle=null, pickerRadius=200;

function initPickerMap(){
  if(pickerMap){
    setTimeout(()=>pickerMap.invalidateSize(),60);
    return;
  }
  // Center on saved GPS if available, otherwise default to Manila
  const initLat = SAVED_GPS_LAT !== null ? SAVED_GPS_LAT : 14.5995;
  const initLng = SAVED_GPS_LNG !== null ? SAVED_GPS_LNG : 120.9842;
  const initZoom = SAVED_GPS_LAT !== null ? 15 : 11;

  pickerMap=L.map('pickerMap').setView([initLat, initLng], initZoom);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(pickerMap);
  pickerMap.on('click',e=>placePin(e.latlng.lat,e.latlng.lng,true));
  setTimeout(()=>{
    pickerMap.invalidateSize();
    // Auto-pin saved GPS when modal opens so it's ready to submit immediately
    if(SAVED_GPS_LAT !== null && SAVED_GPS_LNG !== null){
      placePin(SAVED_GPS_LAT, SAVED_GPS_LNG, true);
    }
  },150);
}

function placePin(lat,lng,doRG){
  document.getElementById('r_latitude').value=lat; document.getElementById('r_longitude').value=lng;
  if(pickerMarker) pickerMap.removeLayer(pickerMarker);
  if(pickerCircle) pickerMap.removeLayer(pickerCircle);
  pickerCircle=L.circle([lat,lng],{radius:pickerRadius,color:'#3a8dff',fillColor:'rgba(58,141,255,0.12)',fillOpacity:1,weight:2,dashArray:'6 4'}).addTo(pickerMap);
  pickerMarker=L.marker([lat,lng],{icon:L.divIcon({html:`<svg xmlns="http://www.w3.org/2000/svg" width="30" height="38" viewBox="0 0 30 38"><path d="M15 0C6.716 0 0 6.716 0 15c0 10 15 23 15 23S30 25 30 15C30 6.716 23.284 0 15 0z" fill="#3a8dff" stroke="white" stroke-width="2"/><circle cx="15" cy="15" r="6" fill="white" opacity="0.9"/></svg>`,className:'',iconSize:[30,38],iconAnchor:[15,38]}),draggable:true}).addTo(pickerMap);
  pickerMarker.on('dragend',ev=>{ const p=ev.target.getLatLng(); placePin(p.lat,p.lng,true); });
  const ps=document.getElementById('pinStatus'); ps.className='pin-status set'; ps.innerHTML=`<i class="fas fa-circle-check"></i> ${lat.toFixed(5)}, ${lng.toFixed(5)}`;
  document.getElementById('clearPinBtn').style.display='inline-flex';
  pickerMap.setView([lat,lng],Math.max(pickerMap.getZoom(),15));
  if(doRG) reverseGeocode(lat,lng);
}

function clearPin(){
  if(pickerMarker){pickerMap.removeLayer(pickerMarker);pickerMarker=null;}
  if(pickerCircle){pickerMap.removeLayer(pickerCircle);pickerCircle=null;}
  document.getElementById('r_latitude').value=''; document.getElementById('r_longitude').value='';
  document.getElementById('r_radius_m').value='200'; document.getElementById('radiusSlider').value=200;
  document.getElementById('radiusVal').textContent='200 m'; pickerRadius=200;
  const ps=document.getElementById('pinStatus'); ps.className='pin-status'; ps.innerHTML='<i class="fas fa-circle-info"></i> No pin placed';
  document.getElementById('clearPinBtn').style.display='none';
}

function onRadiusChange(val){
  pickerRadius=parseInt(val);
  document.getElementById('radiusVal').textContent=pickerRadius>=1000?(pickerRadius/1000).toFixed(1)+' km':pickerRadius+' m';
  document.getElementById('r_radius_m').value=pickerRadius;
  if(pickerCircle) pickerCircle.setRadius(pickerRadius);
}

async function reverseGeocode(lat,lng){
  try{
    const res=await fetch(`api/geocode_proxy.php?lat=${lat}&lon=${lng}`); const data=await res.json();
    if(data&&data.address){
      const a=data.address;
      if(!document.getElementById('r_location').value) document.getElementById('r_location').value=a.road||a.hamlet||a.suburb||'';
      if(!document.getElementById('r_barangay').value) document.getElementById('r_barangay').value=a.suburb||a.village||a.quarter||a.neighbourhood||'';
      if(!document.getElementById('r_city').value)     document.getElementById('r_city').value=a.city||a.town||a.municipality||'';
      if(!document.getElementById('r_province').value) document.getElementById('r_province').value=a.state||a.province||'';
    }
  }catch{}
}

function useMyLocation(){
  const btn=document.getElementById('locateBtn');
  const resetBtn=()=>{ btn.innerHTML='<i class="fas fa-location-crosshairs"></i> Use My Location'; btn.disabled=false; };

  // If user already has saved GPS in their profile, use it instantly.
  // No browser permission prompt, no risk of hanging.
  if(SAVED_GPS_LAT !== null && SAVED_GPS_LNG !== null){
    placePin(SAVED_GPS_LAT, SAVED_GPS_LNG, true);
    return;
  }

  // No saved GPS — fall back to live browser geolocation
  if(!navigator.geolocation){
    alert('Geolocation is not supported by your browser. Please save your GPS in your Profile first, or pin a location manually on the map.');
    return;
  }
  btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Locating…'; btn.disabled=true;
  navigator.geolocation.getCurrentPosition(
    pos=>{ resetBtn(); placePin(pos.coords.latitude, pos.coords.longitude, true); },
    err=>{
      resetBtn();
      let msg='Could not get your location.';
      if(err.code===1) msg='Location access was denied. Please allow location access in your browser, or save your GPS in your Profile first.';
      if(err.code===3) msg='Location request timed out. Try saving your GPS in your Profile instead.';
      alert(msg);
    },
    { enableHighAccuracy:true, timeout:10000, maximumAge:60000 }
  );
}

// ════════════════════════════════════════════════════════
// PROFILE GPS — Get GPS Data button
// ════════════════════════════════════════════════════════
async function getGPSData() {
  if(!navigator.geolocation){ alert('Geolocation is not supported by your browser.'); return; }
  const btn   = document.getElementById('getGpsBtn');
  const disp  = document.getElementById('gpsCoordsDisplay');
  const msg   = document.getElementById('profileMsg');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Acquiring…';
  navigator.geolocation.getCurrentPosition(async pos => {
    const lat = pos.coords.latitude, lng = pos.coords.longitude;
    // Save to server
    const fd = new FormData();
    fd.append('action','save_gps'); fd.append('latitude',lat); fd.append('longitude',lng);
    try {
      const res  = await fetch('api/reports.php',{method:'POST',body:fd});
      const data = await res.json();
      if(data.status==='success') {
        // Update display
        disp.className = 'gps-coords-display has-gps';
        disp.innerHTML = `<i class="fas fa-circle-check"></i> ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
        showMsg(msg,'success','GPS location saved! Reports Map and alerts are now unlocked.');
        // Unlock GPS features in the UI immediately
        userLat = lat; userLng = lng;
        hasGPSData = true;
        updateGPSLock();
        checkGPSProximity();
      } else {
        showMsg(msg,'error', data.message || 'Failed to save GPS.');
      }
    } catch { showMsg(msg,'error','Network error saving GPS.'); }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-crosshairs"></i> Get GPS Data';
  }, err => {
    let msg2 = 'Could not get your location.';
    if(err.code===1) msg2 = 'Location access denied. Please allow location in your browser settings.';
    showMsg(msg,'error', msg2);
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-crosshairs"></i> Get GPS Data';
  }, {enableHighAccuracy:true, timeout:15000, maximumAge:0});
}

// ════════════════════════════════════════════════════════
// C: PROFILE MODAL
// ════════════════════════════════════════════════════════
let selectedColor = '<?= htmlspecialchars($avatar_color) ?>';

function buildPalette(){
  const pal=document.getElementById('colorPalette');
  pal.innerHTML=AVATAR_COLORS.map(c=>`<div class="color-swatch${c===selectedColor?' selected':''}" style="background:${c}" onclick="pickColor('${c}')"></div>`).join('');
}

function pickColor(c){
  selectedColor=c;
  document.getElementById('profileAvatarPreview').style.background=c;
  buildPalette();
}

function updateInitials(){
  const f=document.getElementById('p_first').value.trim(), l=document.getElementById('p_last').value.trim();
  const init=(f.charAt(0)+(l.charAt(0)||'')).toUpperCase();
  document.getElementById('profileAvatarInitials').textContent=init;
}
document.getElementById('p_first').addEventListener('input',updateInitials);
document.getElementById('p_last').addEventListener('input',updateInitials);

function openProfile(){
  buildPalette();
  document.getElementById('profileMsg').style.display='none';
  document.getElementById('profileOverlay').classList.add('open');
}
function closeProfile(){ document.getElementById('profileOverlay').classList.remove('open'); }
function outsideCloseProfile(e){ if(e.target===document.getElementById('profileOverlay')) closeProfile(); }

async function saveProfile(){
  const btn=document.getElementById('profileSaveBtn'), msg=document.getElementById('profileMsg');
  msg.style.display='none';
  const firstName=document.getElementById('p_first').value.trim(), lastName=document.getElementById('p_last').value.trim(), email=document.getElementById('p_email').value.trim();
  const currentPw=document.getElementById('p_current_pw').value, newPw=document.getElementById('p_new_pw').value, confirmPw=document.getElementById('p_confirm_pw').value;
  if(!firstName||!lastName||!email){ showMsg(msg,'error','First name, last name, and email are required.'); return; }
  if(newPw&&newPw.length<8){ showMsg(msg,'error','New password must be at least 8 characters.'); return; }
  if(newPw&&newPw!==confirmPw){ showMsg(msg,'error','New passwords do not match.'); return; }
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
  const fd=new FormData();
  fd.append('action','update_profile'); fd.append('first_name',firstName); fd.append('last_name',lastName);
  fd.append('email',email); fd.append('avatar_color',selectedColor);
  if(newPw){ fd.append('current_password',currentPw); fd.append('new_password',newPw); }
  try{
    const res=await fetch('api/reports.php',{method:'POST',body:fd}); const data=await res.json();
    if(data.status==='success'){
      showMsg(msg,'success','Profile updated successfully!');
      const init=(firstName.charAt(0)+lastName.charAt(0)).toUpperCase();
      document.getElementById('topAvatar').style.background=selectedColor;
      document.getElementById('topAvatar').textContent=init;
      document.getElementById('profileAvatarPreview').style.background=selectedColor;
      document.getElementById('profileAvatarInitials').textContent=init;
      // Clear password fields
      ['p_current_pw','p_new_pw','p_confirm_pw'].forEach(id=>document.getElementById(id).value='');
      setTimeout(closeProfile,1800);
    } else showMsg(msg,'error',data.message||'Update failed.');
  }catch{ showMsg(msg,'error','Network error.'); }
  btn.disabled=false; btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save Changes';
}

// ════════════════════════════════════════════════════════
// REPORT DETAIL OVERLAY
// ════════════════════════════════════════════════════════
const catLabels = {crime:'Crime',accident:'Accident',flooding:'Flooding',fire:'Fire',health:'Health',infrastructure:'Infrastructure',other:'Other'};

function openReportDetail(id) {
  const r = allReports.find(x => x.id == id);
  if (!r) return;

  // Badge + category
  const badge = document.getElementById('d_badge');
  badge.textContent = ucFirst(r.status);
  badge.className = 'detail-badge ' + r.status;
  document.getElementById('d_cat_tag').innerHTML = `<i class="fas ${catIcons[r.category]||'fa-circle-info'}" style="margin-right:5px;"></i>${catLabels[r.category]||ucFirst(r.category)}`;

  // Title + reporter
  document.getElementById('d_title').textContent = r.title;
  const date = new Date(r.created_at).toLocaleDateString('en-PH',{weekday:'short',year:'numeric',month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'});
  document.getElementById('d_reporter').innerHTML = `Posted by <span>${esc(r.poster_name)}</span> &mdash; ${date}`;

  // Photos
  const photosEl = document.getElementById('d_photos');
  if (r.images && r.images.length > 0) {
    photosEl.style.display = 'flex';
    photosEl.innerHTML = r.images.map((url, i) =>
      `<div class="detail-photo" onclick="openLightbox('${url.replace(/'/g,"%27")}')">
        <img src="${esc(url)}" alt="Report photo ${i+1}" loading="lazy" onerror="this.parentElement.style.display='none'">
        ${r.images.length > 1 ? `<div class="detail-photo-count"><i class="fas fa-camera"></i> ${i+1}/${r.images.length}</div>` : ''}
      </div>`
    ).join('');
  } else {
    photosEl.style.display = 'none';
    photosEl.innerHTML = '';
  }

  // Meta grid
  const loc = [r.location_name, r.barangay, r.city, r.province].filter(Boolean).join(', ');
  const metaItems = [
    {icon:'fa-map-location-dot',color:S_COLOR[r.status],label:'Location',value:loc||r.city||'—'},
    {icon:'fa-city',color:'#888',label:'City / Municipality',value:r.city+(r.province?', '+r.province:'')},
    {icon:'fa-thumbs-up',color:'#38a169',label:'Community Votes',value:`👍 ${r.upvotes} &nbsp; 👎 ${r.downvotes}`},
    {icon:'fa-circle-dot',color:S_COLOR[r.status],label:'Affected Radius',value:`${r.radius_m||200} meters`},
  ];
  if (r.barangay) metaItems.splice(1,0,{icon:'fa-map-pin',color:'#888',label:'Barangay',value:r.barangay});
  document.getElementById('d_meta_grid').innerHTML = metaItems.map(m =>
    `<div class="detail-meta-item">
      <div class="detail-meta-label">${m.label}</div>
      <div class="detail-meta-value"><i class="fas ${m.icon}" style="color:${m.color};"></i>${m.value}</div>
    </div>`
  ).join('');

  // Description
  const descBox = document.getElementById('d_desc_box');
  if (r.description) {
    descBox.style.display = '';
    document.getElementById('d_desc').textContent = r.description;
  } else {
    descBox.style.display = 'none';
  }

  // Footer
  const upVoted = r.user_vote==='up', downVoted = r.user_vote==='down';
  const hasPin  = r.latitude && r.longitude;
  document.getElementById('d_footer').innerHTML = `
    <div class="detail-vote-wrap">
      <button class="vote-btn ${upVoted?'voted':''}" onclick="vote(${r.id},'up');refreshDetailVotes(${r.id})"><i class="fas fa-thumbs-up"></i><span id="dd_up_${r.id}">${r.upvotes}</span></button>
      <button class="vote-btn down ${downVoted?'voted':''}" onclick="vote(${r.id},'down');refreshDetailVotes(${r.id})"><i class="fas fa-thumbs-down"></i><span id="dd_down_${r.id}">${r.downvotes}</span></button>
    </div>
    ${hasPin ? `<button class="detail-map-btn" onclick="closeReportDetail();openMiniMap(${r.id})"><i class="fas fa-map-pin"></i> View on Map</button>` : ''}
    ${r.user_id==MY_USER_ID ? `<button class="vote-btn" onclick="closeReportDetail();deleteReport(${r.id})" style="margin-left:auto;border-color:var(--red);color:var(--red);"><i class="fas fa-trash-can"></i> Delete</button>` : ''}
  `;

  // Accent color on left border of modal
  document.getElementById('detailModal').style.borderLeft = `5px solid ${S_COLOR[r.status]||'#ccc'}`;

  document.getElementById('detailOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function refreshDetailVotes(id) {
  setTimeout(()=>{
    const r = allReports.find(x=>x.id==id); if(!r) return;
    const u = document.getElementById('dd_up_'+id), d = document.getElementById('dd_down_'+id);
    if(u) u.textContent = r.upvotes;
    if(d) d.textContent = r.downvotes;
  }, 400);
}

function closeReportDetail() {
  document.getElementById('detailOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

function outsideCloseDetail(e) {
  if (e.target === document.getElementById('detailOverlay')) closeReportDetail();
}

// ════════════════════════════════════════════════════════
// LIGHTBOX
// ════════════════════════════════════════════════════════
function openLightbox(url) {
  document.getElementById('lightboxImg').src = url;
  document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.getElementById('lightboxImg').src = '';
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeLightbox();
    closeReportDetail();
  }
});

// ════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════
function esc(s){ if(!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function ucFirst(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : ''; }
function showMsg(el,type,text){ el.className='modal-msg '+type; el.textContent=text; el.style.display='block'; }

// ════════════════════════════════════════════════════════
// BOOT
// ════════════════════════════════════════════════════════
fetchReports();
setInterval(fetchReports, 60000);
updateGPSLock(); // Apply GPS lock state immediately
startGPS();      // Start browser watchPosition (updates lock state if granted)

// Set initial filter state from PHP
if(INIT_FILTER){
  showMine=true;
  document.getElementById('myBtn').classList.add('active');
}

// Activate the right section (feed or reportsmap)
activateSection(INIT_SECTION);
// If reportsmap, init after reports load (handled in fetchReports callback)
</script>
</body>
</html>
