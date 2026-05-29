<?php
session_start();
require_once __DIR__ . '/../config/auth.php';
require_role(['first_responder']);
require_once __DIR__ . '/../config/db.php';

$uid   = (int)$_SESSION['user_id'];
$fname = $_SESSION['first_name'];
$view  = $_GET['view'] ?? 'queue';

$stmt = $conn->prepare("SELECT org_name,`position`,responder_type,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i",$uid); $stmt->execute();
$prof = $stmt->get_result()->fetch_assoc(); $stmt->close();
$unit  = $prof['org_name']       ?? 'Responder Unit';
$pos   = $prof['position']       ?? 'First Responder';
$rtype = strtoupper($prof['responder_type'] ?? 'UNIT');
$area  = $prof['municipality']   ?? $prof['barangay_name'] ?? '';

$type_colors = ['bfp'=>'#dc2626','pnp'=>'#1d4ed8','ems'=>'#15803d','drrmo'=>'#d97706','mdrrmo'=>'#d97706','hospital'=>'#0e7490','other'=>'#6b7280'];
$unit_color  = $type_colors[strtolower($prof['responder_type']??'')] ?? '#dc2626';

$cat_icons = ['crime'=>'fa-user-slash','accident'=>'fa-car-burst','flooding'=>'fa-water','fire'=>'fa-fire','health'=>'fa-heart-pulse','infrastructure'=>'fa-road-barrier','other'=>'fa-circle-exclamation'];
$type_icons = ['lgu'=>'fa-landmark','hospital'=>'fa-hospital','traffic'=>'fa-traffic-light','barangay'=>'fa-house-flag','police'=>'fa-shield','fire'=>'fa-fire-extinguisher','other'=>'fa-phone'];

function cq($conn,$sql,$t='',$p=[]){
    $s=$conn->prepare($sql);
    if($t && count($p)){
        $refs=[];
        foreach($p as &$v) $refs[]=&$v;
        array_unshift($refs,$t);
        call_user_func_array([$s,'bind_param'],$refs);
    }
    $s->execute();$s->bind_result($n);$s->fetch();$s->close();return(int)$n;
}
$total_active  = cq($conn,"SELECT COUNT(*) FROM reports WHERE status IN('dangerous','caution') AND is_archived=0");
$danger_count  = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='dangerous' AND is_archived=0");
$caution_count = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='caution' AND is_archived=0");

// Per-view data
$queue = $assigned = $contacts = $resolved = [];

if ($view === 'queue' || $view === 'overview') {
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description,r.assigned_to,u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 AND r.status IN('dangerous','caution') ORDER BY FIELD(r.status,'dangerous','caution'),r.created_at DESC LIMIT 60");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $queue[]=$row;
    $s->close();
}

if ($view === 'assigned') {
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description,u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.assigned_to=? AND r.is_archived=0 ORDER BY r.created_at DESC");
    $s->bind_param("i",$uid); $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $assigned[]=$row;
    $s->close();
}

if ($view === 'resolved') {
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.created_at,r.resolved_at,u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.assigned_to=? AND r.status='safe' ORDER BY r.resolved_at DESC LIMIT 50");
    $s->bind_param("i",$uid); $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $resolved[]=$row;
    $s->close();
}

if ($view === 'contacts') {
    $s = $conn->prepare("SELECT * FROM emergency_contacts WHERE is_active=1 ORDER BY type,name");
    $s->execute(); $res=$s->get_result();
    while($r=$res->fetch_assoc()) $contacts[]=$r;
    $s->close();
}

$my_count = cq($conn,"SELECT COUNT(*) FROM reports WHERE assigned_to=? AND is_archived=0 AND status IN('dangerous','caution')",'i',[$uid]);

$nav_items = [
    'queue'    => ['icon'=>'fa-siren-on',         'label'=>'Dispatch Queue'],
    'assigned' => ['icon'=>'fa-clipboard-check',  'label'=>'My Assignments'],
    'resolved' => ['icon'=>'fa-circle-check',     'label'=>'Resolved by Me'],
    'contacts' => ['icon'=>'fa-address-book',     'label'=>'Emergency Contacts'],
    'profile'  => ['icon'=>'fa-id-card',          'label'=>'My Profile'],
];
$page_titles = [
    'queue'    => 'Dispatch Queue',
    'assigned' => 'My Assignments',
    'resolved' => 'Resolved by Me',
    'contacts' => 'Emergency Contacts',
    'profile'  => 'My Profile',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $page_titles[$view] ?? 'Responder Portal' ?> — SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--red:#b91c1c;--red-dark:#7f1d1d;--red-mid:#991b1b;--red-light:#dc2626;--gold:#f39c12;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--bg:#fafafa;--card:#fff;--sidebar-w:256px;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
html,body{height:100%;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;}
/* SIDEBAR */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:linear-gradient(180deg,#450a0a 0%,#7f1d1d 50%,#991b1b 100%);color:#fff;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:200;box-shadow:4px 0 20px rgba(0,0,0,0.3);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);}
.sb-header{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.sb-brand{display:flex;align-items:center;gap:10px;}
.sb-seal{width:40px;height:40px;border-radius:50%;background:rgba(239,68,68,0.2);border:2px solid rgba(239,68,68,0.45);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fca5a5;flex-shrink:0;}
.sb-title{font-size:0.96rem;font-weight:800;line-height:1.2;}
.sb-sub{font-size:0.58rem;color:rgba(255,255,255,0.4);letter-spacing:1.5px;text-transform:uppercase;}
.sb-close{background:none;border:none;color:rgba(255,255,255,0.6);font-size:1.1rem;cursor:pointer;padding:4px 6px;border-radius:6px;display:none;flex-shrink:0;}
.sb-unit{padding:10px 16px;background:rgba(0,0,0,0.25);border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0;}
.sb-unit p{font-size:0.65rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.sb-unit strong{font-size:0.82rem;color:#fff;font-weight:700;display:block;word-break:break-word;margin-bottom:4px;}
.unit-badge{display:inline-block;padding:2px 9px;border-radius:4px;font-size:0.68rem;font-weight:800;letter-spacing:1px;color:#fff;}
.sb-nav{padding:12px 8px;flex:1;overflow-y:auto;}
.sb-nav a{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.7);text-decoration:none;font-size:0.84rem;font-weight:500;padding:10px 12px;border-radius:10px;transition:all 0.18s;margin-bottom:2px;white-space:nowrap;}
.sb-nav a:hover{background:rgba(255,255,255,0.1);color:#fff;}
.sb-nav a.active{background:rgba(255,255,255,0.15);color:#fff;font-weight:700;}
.sb-nav a i{width:18px;text-align:center;font-size:0.9rem;flex-shrink:0;}
.sb-badge{background:rgba(255,255,255,0.15);padding:1px 8px;border-radius:10px;font-size:0.7rem;margin-left:auto;flex-shrink:0;}
.sb-section{font-size:0.62rem;color:rgba(255,255,255,0.25);letter-spacing:2px;text-transform:uppercase;padding:14px 12px 5px;font-weight:700;}
.sb-footer{padding:12px 16px;border-top:1px solid rgba(255,255,255,0.1);flex-shrink:0;}
.sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.sb-avatar{width:34px;height:34px;border-radius:50%;flex-shrink:0;background:rgba(239,68,68,0.2);display:flex;align-items:center;justify-content:center;font-size:0.88rem;font-weight:800;color:#fca5a5;}
.sb-uname{font-size:0.82rem;font-weight:700;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-upos{font-size:0.65rem;color:rgba(255,255,255,0.4);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-logout{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.55);text-decoration:none;font-size:0.8rem;font-weight:600;padding:8px 10px;border-radius:8px;transition:all 0.18s;}
.sb-logout:hover{background:rgba(220,38,38,0.2);color:#fca5a5;}
/* MAIN */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-width:0;min-height:100vh;}
/* TOPBAR */
.topbar{background:#fff;border-bottom:4px solid var(--red-light);padding:0 24px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 10px rgba(0,0,0,0.07);}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0;}
.ham-btn{background:none;border:none;font-size:1.15rem;color:var(--muted);cursor:pointer;padding:7px;border-radius:8px;display:none;flex-shrink:0;}
.ham-btn:hover{background:#f3f4f6;}
.page-title{font-size:1rem;font-weight:800;color:var(--red-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.page-sub{font-size:0.72rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.badge-resp{font-size:0.71rem;font-weight:700;padding:5px 12px;border-radius:20px;border:1px solid;white-space:nowrap;flex-shrink:0;}
/* ALERT */
.alert-banner{background:#fef2f2;border-left:4px solid var(--red-light);padding:11px 24px;display:flex;align-items:center;gap:12px;font-size:0.84rem;font-weight:600;color:#991b1b;flex-shrink:0;}
/* CONTENT */
.content{padding:22px 24px;flex:1;}
/* STATS */
.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--card);border-radius:14px;padding:18px 16px;box-shadow:0 2px 8px rgba(0,0,0,0.05);border:1px solid var(--border);display:flex;align-items:center;gap:14px;}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;}
.stat-num{font-size:1.6rem;font-weight:800;line-height:1;}
.stat-lbl{font-size:0.71rem;color:var(--muted);font-weight:600;margin-top:3px;}
/* CARD */
.card{background:var(--card);border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,0.06);border:1px solid var(--border);overflow:hidden;margin-bottom:18px;}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;}
.card-header h3{font-size:0.88rem;font-weight:700;}
.card-meta{font-size:0.72rem;color:var(--muted);white-space:nowrap;}
/* INCIDENT ROWS */
.incident-row{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #f3f4f6;transition:background 0.15s;}
.incident-row:last-child{border-bottom:none;}
.incident-row:hover{background:#fafafa;}
.inc-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.inc-body{flex:1;min-width:0;}
.inc-title{font-size:0.88rem;font-weight:700;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.inc-meta{font-size:0.74rem;color:var(--muted);display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px;}
.inc-actions{display:flex;flex-direction:column;gap:5px;align-items:flex-end;flex-shrink:0;}
/* PILLS */
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;white-space:nowrap;}
.pill-dangerous{background:#fef2f2;color:#991b1b;}
.pill-caution{background:#fffbeb;color:#92400e;}
.pill-safe{background:#f0fdf4;color:#166534;}
/* BUTTONS */
.btn-dispatch{background:var(--red-light);color:#fff;border:none;padding:6px 13px;border-radius:8px;font-size:0.76rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background 0.18s;font-family:'Inter',sans-serif;white-space:nowrap;}
.btn-dispatch:hover{background:var(--red-mid);}
.btn-dispatch:disabled{opacity:0.5;cursor:not-allowed;}
.btn-assigned{background:#f0fdf4;color:#166534;border:1.5px solid #bbf7d0;padding:5px 12px;border-radius:8px;font-size:0.74rem;font-weight:700;white-space:nowrap;}
.btn-resolve-sm{background:#f0fdf4;color:#166534;border:1.5px solid #bbf7d0;padding:5px 12px;border-radius:8px;font-size:0.74rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:'Inter',sans-serif;transition:all 0.18s;}
.btn-resolve-sm:hover{background:#dcfce7;}
.map-link{font-size:0.72rem;color:#2563eb;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
.map-link:hover{text-decoration:underline;}
/* TABLE */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
table{width:100%;border-collapse:collapse;min-width:480px;}
thead tr{background:#f8fafc;}
th{padding:10px 14px;font-size:0.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
td{padding:11px 14px;font-size:0.82rem;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
.cat-chip{display:inline-flex;align-items:center;gap:5px;font-size:0.72rem;font-weight:600;color:#0a3d62;background:#eff6ff;padding:3px 8px;border-radius:6px;white-space:nowrap;}
/* CONTACTS */
.contact-row{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.contact-row:last-child{border-bottom:none;}
.contact-icon{width:40px;height:40px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1rem;}
.contact-name{font-size:0.88rem;font-weight:700;margin-bottom:3px;}
.contact-meta{font-size:0.75rem;color:var(--muted);line-height:1.6;}
.contact-type-badge{display:inline-block;font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;padding:2px 8px;border-radius:4px;margin-bottom:4px;}
/* EMPTY */
.empty{padding:48px 20px;text-align:center;color:var(--muted);}
.empty i{font-size:2.2rem;display:block;margin-bottom:12px;opacity:0.3;}
.empty p{font-size:0.86rem;}
/* COMING SOON */
.coming-soon{padding:60px 24px;text-align:center;}
.coming-soon i{font-size:3rem;color:var(--red-light);opacity:0.2;display:block;margin-bottom:16px;}
.coming-soon h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:6px;}
.coming-soon p{font-size:0.85rem;color:var(--muted);}
/* OVERLAY */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:150;}
.overlay.show{display:block;}
/* RESPONSIVE */
@media(max-width:860px){.sidebar{transform:translateX(-100%);}.sidebar.open{transform:translateX(0);}.sb-close{display:flex;}.main{margin-left:0;}.ham-btn{display:flex;}.stat-row{grid-template-columns:1fr 1fr;}.content{padding:16px;}.topbar{padding:0 16px;}}
@media(max-width:480px){.stat-row{grid-template-columns:1fr;}.badge-resp,.page-sub{display:none;}}
</style>
</head>
<body>
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-brand">
      <div class="sb-seal"><i class="fas fa-truck-medical"></i></div>
      <div><div class="sb-title">SenTri</div><div class="sb-sub">Responder Portal</div></div>
    </div>
    <button class="sb-close" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="sb-unit">
    <p>Unit</p>
    <strong><?= htmlspecialchars($unit) ?></strong>
    <div><span class="unit-badge" style="background:<?= $unit_color ?>;"><?= htmlspecialchars($rtype) ?></span></div>
  </div>
  <nav class="sb-nav">
    <?php foreach($nav_items as $key => $item): ?>
      <?php if($key === 'contacts'): ?><div class="sb-section">Reference</div><?php endif; ?>
      <?php if($key === 'profile'): ?><div class="sb-section">Account</div><?php endif; ?>
      <a href="responder.php?view=<?= $key ?>" class="<?= $view===$key?'active':'' ?>">
        <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
        <?php if($key==='assigned' && $my_count > 0): ?><span class="sb-badge"><?= $my_count ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-avatar"><?= strtoupper(substr($fname,0,1)) ?></div>
      <div style="min-width:0;"><div class="sb-uname"><?= htmlspecialchars($fname) ?></div><div class="sb-upos"><?= htmlspecialchars($pos) ?></div></div>
    </div>
    <a href="../logout.php" class="sb-logout"><i class="fas fa-right-from-bracket"></i> Sign Out</a>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="ham-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <div style="min-width:0;">
        <div class="page-title"><?= htmlspecialchars($page_titles[$view] ?? 'Responder Portal') ?></div>
        <div class="page-sub"><?= htmlspecialchars($unit) ?><?= $area ? ' &mdash; '.htmlspecialchars($area) : '' ?></div>
      </div>
    </div>
    <span class="badge-resp" style="color:<?= $unit_color ?>;border-color:<?= $unit_color ?>;background:<?= $unit_color ?>18;">
      <i class="fas fa-truck-medical"></i>&nbsp; <?= htmlspecialchars($rtype) ?>
    </span>
  </div>

  <?php if($view === 'queue' && $danger_count > 0): ?>
  <div class="alert-banner">
    <i class="fas fa-circle-exclamation"></i>
    <?= $danger_count ?> ACTIVE DANGEROUS INCIDENT<?= $danger_count > 1 ? 'S' : '' ?> — Immediate response required.
  </div>
  <?php endif; ?>

  <div class="content">

  <?php if($view === 'queue'): ?>
    <div class="stat-row">
      <div class="stat-card"><div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="stat-num"><?= $danger_count ?></div><div class="stat-lbl">Dangerous</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="stat-num"><?= $caution_count ?></div><div class="stat-lbl">Caution</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-clipboard-check"></i></div><div><div class="stat-num"><?= $my_count ?></div><div class="stat-lbl">My Assignments</div></div></div>
    </div>
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-siren-on" style="color:#dc2626;margin-right:6px;"></i>Active Incidents — Dispatch Queue</h3>
        <span class="card-meta"><?= count($queue) ?> incidents</span>
      </div>
      <?php if(empty($queue)): ?>
        <div class="empty"><i class="fas fa-shield-check" style="color:#16a34a;opacity:0.4;"></i><p>No active incidents. All clear.</p></div>
      <?php else: foreach($queue as $r):
        $is_mine = (int)$r['assigned_to'] === $uid;
        $is_assigned = $r['assigned_to'] !== null;
        $ibg = $r['status']==='dangerous' ? '#fef2f2' : '#fffbeb';
        $iclr = $r['status']==='dangerous' ? '#dc2626' : '#d97706';
      ?>
        <div class="incident-row">
          <div class="inc-icon" style="background:<?= $ibg ?>;color:<?= $iclr ?>;"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i></div>
          <div class="inc-body">
            <div class="inc-title"><?= htmlspecialchars($r['title']) ?></div>
            <div class="inc-meta">
              <span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
              <span><i class="fas fa-location-dot"></i> <?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></span>
              <span><?= date('M j, g:ia', strtotime($r['created_at'])) ?></span>
              <?php if($r['latitude']): ?>
              <a class="map-link" href="https://maps.google.com/?q=<?= $r['latitude'] ?>,<?= $r['longitude'] ?>" target="_blank"><i class="fas fa-map-pin"></i> View Map</a>
              <?php endif; ?>
            </div>
            <?php if($r['description']): ?><div style="font-size:0.76rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($r['description'],0,100,'…')) ?></div><?php endif; ?>
          </div>
          <div class="inc-actions">
            <?php if($is_mine): ?>
              <span class="btn-assigned"><i class="fas fa-check"></i> Assigned to Me</span>
              <button class="btn-resolve-sm" onclick="resolve(<?= $r['id'] ?>,this)"><i class="fas fa-circle-check"></i> Resolve</button>
            <?php elseif($is_assigned): ?>
              <span style="font-size:0.72rem;color:var(--muted);font-weight:600;">Assigned</span>
            <?php else: ?>
              <button class="btn-dispatch" onclick="assign(<?= $r['id'] ?>,this)"><i class="fas fa-hand-pointer"></i> Assign to Me</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif($view === 'assigned'): ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-clipboard-check" style="color:var(--red-light);margin-right:6px;"></i>My Active Assignments</h3><span class="card-meta"><?= count($assigned) ?> reports</span></div>
      <?php if(empty($assigned)): ?>
        <div class="empty"><i class="fas fa-clipboard"></i><p>No active assignments. Check the dispatch queue.</p></div>
      <?php else: foreach($assigned as $r):
        $ibg = $r['status']==='dangerous' ? '#fef2f2' : ($r['status']==='caution' ? '#fffbeb' : '#f0fdf4');
        $iclr = $r['status']==='dangerous' ? '#dc2626' : ($r['status']==='caution' ? '#d97706' : '#16a34a');
      ?>
        <div class="incident-row">
          <div class="inc-icon" style="background:<?= $ibg ?>;color:<?= $iclr ?>;"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i></div>
          <div class="inc-body">
            <div class="inc-title"><?= htmlspecialchars($r['title']) ?></div>
            <div class="inc-meta">
              <span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
              <span><i class="fas fa-location-dot"></i> <?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></span>
              <span><?= date('M j, g:ia', strtotime($r['created_at'])) ?></span>
              <?php if($r['latitude']): ?>
              <a class="map-link" href="https://maps.google.com/?q=<?= $r['latitude'] ?>,<?= $r['longitude'] ?>" target="_blank"><i class="fas fa-map-pin"></i> View Map</a>
              <?php endif; ?>
            </div>
          </div>
          <div class="inc-actions">
            <button class="btn-resolve-sm" onclick="resolve(<?= $r['id'] ?>,this)"><i class="fas fa-circle-check"></i> Resolve</button>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif($view === 'resolved'): ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-circle-check" style="color:#16a34a;margin-right:6px;"></i>Resolved by Me</h3><span class="card-meta"><?= count($resolved) ?> reports</span></div>
      <?php if(empty($resolved)): ?>
        <div class="empty"><i class="fas fa-circle-check" style="color:#16a34a;"></i><p>No resolved reports yet.</p></div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Location</th><th>Reported</th><th>Resolved</th></tr></thead>
          <tbody>
          <?php foreach($resolved as $r): ?>
            <tr>
              <td style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
              <td style="font-weight:600;max-width:200px;"><?= htmlspecialchars(mb_strimwidth($r['title'],0,50,'…')) ?></td>
              <td><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
              <td style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></td>
              <td style="font-size:0.74rem;color:var(--muted);"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
              <td style="font-size:0.74rem;color:#16a34a;font-weight:600;"><?= $r['resolved_at'] ? date('M j, Y', strtotime($r['resolved_at'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  <?php elseif($view === 'contacts'): ?>
    <?php
    $ctype_colors = ['lgu'=>['#f0f7ff','#0a3d62'],'hospital'=>['#ecfdf5','#059669'],'traffic'=>['#fffbeb','#d97706'],'barangay'=>['#f0fdf4','#166534'],'police'=>['#eff6ff','#2563eb'],'fire'=>['#fef2f2','#dc2626'],'other'=>['#f5f3ff','#7c3aed']];
    $ctype_icons = ['lgu'=>'fa-landmark','hospital'=>'fa-hospital','traffic'=>'fa-traffic-light','barangay'=>'fa-house-flag','police'=>'fa-shield','fire'=>'fa-fire-extinguisher','other'=>'fa-phone'];
    ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-address-book" style="color:var(--red-light);margin-right:6px;"></i>Emergency Contacts</h3><span class="card-meta"><?= count($contacts) ?> contacts</span></div>
      <?php if(empty($contacts)): ?><div class="empty"><i class="fas fa-address-book"></i><p>No emergency contacts on file.</p></div>
      <?php else: foreach($contacts as $c): $tc=$ctype_colors[$c['type']]??['#f5f3ff','#7c3aed']; $ti=$ctype_icons[$c['type']]??'fa-phone'; ?>
        <div class="contact-row">
          <div class="contact-icon" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><i class="fas <?= $ti ?>"></i></div>
          <div style="flex:1;min-width:0;">
            <div class="contact-type-badge" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><?= strtoupper($c['type']) ?></div>
            <div class="contact-name"><?= htmlspecialchars($c['name']) ?></div>
            <div class="contact-meta">
              <?php if($c['contact_number']): ?><span><i class="fas fa-phone" style="color:var(--red-light);margin-right:4px;"></i><?= htmlspecialchars($c['contact_number']) ?></span>&ensp;<?php endif; ?>
              <?php if($c['contact_email']): ?><span><i class="fas fa-envelope" style="margin-right:4px;color:var(--muted);"></i><?= htmlspecialchars($c['contact_email']) ?></span>&ensp;<?php endif; ?>
              <?php if($c['city']): ?><span><i class="fas fa-location-dot" style="margin-right:4px;color:var(--muted);"></i><?= htmlspecialchars($c['barangay'] ? $c['barangay'].', '.$c['city'] : $c['city']) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif($view === 'profile'): ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-id-card" style="color:var(--red-light);margin-right:6px;"></i>My Profile</h3></div>
      <div style="padding:24px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <?php foreach(['Name'=>htmlspecialchars($fname),'Role'=>'First Responder','Unit Type'=>htmlspecialchars($rtype),'Position'=>htmlspecialchars($pos),'Unit Name'=>htmlspecialchars($unit),'Coverage Area'=>htmlspecialchars($area?:'Not set')] as $lbl=>$val): ?>
          <div>
            <div style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;"><?= $lbl ?></div>
            <div style="font-size:0.9rem;font-weight:600;"><?= $val ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  <?php else: ?>
    <div class="card"><div class="coming-soon"><i class="fas <?= $nav_items[$view]['icon'] ?? 'fa-gear' ?>"></i><h3><?= htmlspecialchars($page_titles[$view] ?? ucfirst($view)) ?></h3><p>This section is under development.</p></div></div>
  <?php endif; ?>

  </div>
</div>

<script>
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('overlay').classList.add('show');document.body.style.overflow='hidden';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('show');document.body.style.overflow='';}

async function assign(id,btn){
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
  try{
    var fd=new FormData(); fd.append('action','assign_report'); fd.append('report_id',id);
    var res=await fetch('../api/reports.php',{method:'POST',body:fd});
    var data=await res.json();
    if(data.status==='success') location.reload();
    else{ alert(data.message||'Error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-hand-pointer"></i> Assign to Me'; }
  }catch(e){ btn.disabled=false; btn.innerHTML='<i class="fas fa-hand-pointer"></i> Assign to Me'; }
}

async function resolve(id,btn){
  if(!confirm('Mark this incident as resolved?')) return;
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
  try{
    var fd=new FormData(); fd.append('action','resolve_report'); fd.append('report_id',id);
    var res=await fetch('../api/reports.php',{method:'POST',body:fd});
    var data=await res.json();
    if(data.status==='success') location.reload();
    else{ alert(data.message||'Error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-circle-check"></i> Resolve'; }
  }catch(e){ btn.disabled=false; btn.innerHTML='<i class="fas fa-circle-check"></i> Resolve'; }
}

// Auto-refresh queue every 90 seconds
<?php if($view === 'queue'): ?>
setTimeout(function(){location.reload();}, 90000);
<?php endif; ?>
</script>
</body>
</html>
