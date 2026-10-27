<?php
// config/auth.php - Role guard and portal redirect helper
require_once __DIR__ . '/csrf.php';

function login_url_for_role($role) {
 // Each role signs in on its own page:
 // community -> login.php, officials -> gov/login.php, admin -> admin.php
 switch($role) {
 case 'barangay':
 case 'lgu':
 case 'first_responder': return '/gov/login.php';
 case 'admin': return '/admin.php';
 case 'community':
 case 'user':
 default: return '/login.php';
 }
}

function require_login() {
 if (!isset($_SESSION['user_id'])) {
 header('Location: ' . login_url_for_role($_SESSION['role'] ?? 'community')); exit;
 }
}

function require_role($allowed) {
 require_login();
 $role = $_SESSION['role'] ?? '';
 if (!in_array($role, $allowed, true)) {
 header('Location: ' . portal_url($role)); exit;
 }
}

function require_approved() {
 require_login();
 if (!($_SESSION['is_approved'] ?? false)) {
 $role = $_SESSION['role'] ?? 'community';
 session_destroy();
 header('Location: ' . login_url_for_role($role) . '?pending=1'); exit;
 }
}

function portal_url($role) {
 switch($role) {
 case 'barangay': return '/portal/barangay.php';
 case 'lgu': return '/portal/lgu.php';
 case 'first_responder': return '/portal/responder.php';
 case 'admin': return '/admin.php';
 case 'community':
 case 'user': return '/portal/community.php';
 default: return '/portal/community.php';
 }
}

function redirect_to_portal() {
 header('Location: ' . portal_url($_SESSION['role'] ?? 'community')); exit;
}

function gov_login_url() {
 return '/gov/login.php';
}

function role_label($role) {
 switch($role) {
 case 'community': return 'Community Member';
 case 'barangay': return 'Barangay Official';
 case 'lgu': return 'LGU Official';
 case 'first_responder': return 'First Responder';
 case 'admin': return 'System Administrator';
 default: return 'User';
 }
}

function role_badge_color($role) {
 switch($role) {
 case 'community': return '#2563eb';
 case 'barangay': return '#166534';
 case 'lgu': return '#0a3d62';
 case 'first_responder': return '#b91c1c';
 case 'admin': return '#6d28d9';
 default: return '#555';
 }
}
