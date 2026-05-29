<?php
/**
 * SenTri – Auto Database Installer
 * =====================================
 * Run this file once in your browser to set up the full database schema.
 * Example: http://localhost/sentri/install.php
 *
 * DELETE or rename this file after successful installation.
 */

// ── Connection Settings ──────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sentri');

// ── Simple lock – prevents re-running if DB already fully exists ─────────────
$steps   = [];   // log of each step
$success = true;

// ── Connect WITHOUT selecting a database first ───────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    $success = false;
    $steps[] = ['error', 'Cannot connect to MySQL: ' . $conn->connect_error];
    render($steps, $success);
    exit;
}
$steps[] = ['ok', 'Connected to MySQL as <strong>' . DB_USER . '@' . DB_HOST . '</strong>'];

// ── Helper to run a query and log the result ─────────────────────────────────
function run(mysqli $c, string $label, string $sql): bool {
    global $steps, $success;
    if ($c->query($sql)) {
        $steps[] = ['ok', $label];
        return true;
    }
    $steps[] = ['error', $label . ' — <em>' . htmlspecialchars($c->error) . '</em>'];
    $success = false;
    return false;
}

// ── 1. Create database ───────────────────────────────────────────────────────
run($conn, 'Create database <code>sentri</code>',
    "CREATE DATABASE IF NOT EXISTS `sentri`
     DEFAULT CHARACTER SET utf8mb4
     COLLATE utf8mb4_general_ci"
);

$conn->select_db(DB_NAME);
$steps[] = ['ok', 'Selected database <code>' . DB_NAME . '</code>'];

// Disable FK checks during setup
$conn->query("SET FOREIGN_KEY_CHECKS = 0");

// ── 2. users ─────────────────────────────────────────────────────────────────
run($conn, 'Create table <code>users</code> (with email verification & password reset columns)',
    "CREATE TABLE IF NOT EXISTS `users` (
        `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
        `first_name`          VARCHAR(100) NOT NULL,
        `last_name`           VARCHAR(100) NOT NULL,
        `email`               VARCHAR(191) NOT NULL,
        `phone_number`        VARCHAR(30)  DEFAULT NULL,
        `password`            VARCHAR(255) NOT NULL,
        `role`                ENUM('user','admin') NOT NULL DEFAULT 'user',
        `email_verified`      TINYINT(1)   NOT NULL DEFAULT 0
            COMMENT '0 = pending verification, 1 = verified',
        `verification_token`  VARCHAR(64)  DEFAULT NULL
            COMMENT 'Email verification token (64-char hex)',
        `token_expires_at`    DATETIME     DEFAULT NULL
            COMMENT 'Verification token expiry (24 hours)',
        `reset_token`         VARCHAR(64)  DEFAULT NULL
            COMMENT 'Password reset token (64-char hex)',
        `reset_token_expires` DATETIME     DEFAULT NULL
            COMMENT 'Password reset token expiry (1 hour)',
        `avatar_color`        VARCHAR(7)   NOT NULL DEFAULT '#1c57b2'
            COMMENT 'Hex colour for user avatar',
        `gps_lat`             DECIMAL(10,7) DEFAULT NULL,
        `gps_lng`             DECIMAL(10,7) DEFAULT NULL,
        `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_email`              (`email`),
        KEY        `idx_reset_token`       (`reset_token`),
        KEY        `idx_verification_token`(`verification_token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ── 2b. Ensure all required columns exist on users (for existing installs) ──
// Safe to run on fresh installs — IF NOT EXISTS means nothing breaks.
$userColChecks = [
    'email_verified'      => "ALTER TABLE `users` ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`",
    'verification_token'  => "ALTER TABLE `users` ADD COLUMN `verification_token` VARCHAR(64) DEFAULT NULL AFTER `email_verified`",
    'token_expires_at'    => "ALTER TABLE `users` ADD COLUMN `token_expires_at` DATETIME DEFAULT NULL AFTER `verification_token`",
    'reset_token'         => "ALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(64) DEFAULT NULL AFTER `token_expires_at`",
    'reset_token_expires' => "ALTER TABLE `users` ADD COLUMN `reset_token_expires` DATETIME DEFAULT NULL AFTER `reset_token`",
    'avatar_color'        => "ALTER TABLE `users` ADD COLUMN `avatar_color` VARCHAR(7) NOT NULL DEFAULT '#1c57b2'",
    'gps_lat'             => "ALTER TABLE `users` ADD COLUMN `gps_lat` DECIMAL(10,7) DEFAULT NULL",
    'gps_lng'             => "ALTER TABLE `users` ADD COLUMN `gps_lng` DECIMAL(10,7) DEFAULT NULL",
    'phone_number'        => "ALTER TABLE `users` ADD COLUMN `phone_number` VARCHAR(30) DEFAULT NULL AFTER `email`",
];
$conn->query("USE `sentri`");
$existingCols = [];
$colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='sentri' AND TABLE_NAME='users'");
if ($colRes) { while ($r = $colRes->fetch_row()) $existingCols[] = $r[0]; }
foreach ($userColChecks as $col => $sql) {
    if (!in_array($col, $existingCols)) {
        if ($conn->query($sql)) {
            $steps[] = ['ok', "Added missing column <code>$col</code> to <code>users</code>"];
        } else {
            $steps[] = ['error', "Failed to add <code>$col</code>: " . htmlspecialchars($conn->error)];
        }
    } else {
        $steps[] = ['skip', "Column <code>$col</code> already exists in <code>users</code> — skipped"];
    }
}
// Mark existing unverified users as verified so no lockout
$conn->query("UPDATE `users` SET `email_verified`=1 WHERE `email_verified`=0 AND `created_at` < NOW()");

// ── 3. reports ───────────────────────────────────────────────────────────────
run($conn, 'Create table <code>reports</code>',
    "CREATE TABLE IF NOT EXISTS `reports` (
        `id`            INT(11)      NOT NULL AUTO_INCREMENT,
        `user_id`       INT(11)      NOT NULL,
        `title`         VARCHAR(255) NOT NULL,
        `description`   TEXT         NOT NULL,
        `location_name` VARCHAR(255) NOT NULL,
        `barangay`      VARCHAR(150) DEFAULT NULL,
        `city`          VARCHAR(150) NOT NULL,
        `province`      VARCHAR(150) DEFAULT NULL,
        `status`        ENUM('dangerous','caution','safe') NOT NULL DEFAULT 'caution',
        `category`      ENUM('crime','accident','flooding','fire','health','infrastructure','other') NOT NULL DEFAULT 'other',
        `upvotes`       INT(11)      NOT NULL DEFAULT 0,
        `downvotes`     INT(11)      NOT NULL DEFAULT 0,
        `is_archived`   TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_id`    (`user_id`),
        KEY `idx_status`     (`status`),
        KEY `idx_city`       (`city`),
        KEY `idx_is_archived`(`is_archived`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ── 4. report_votes ──────────────────────────────────────────────────────────
run($conn, 'Create table <code>report_votes</code>',
    "CREATE TABLE IF NOT EXISTS `report_votes` (
        `id`         INT(11)          NOT NULL AUTO_INCREMENT,
        `report_id`  INT(11)          NOT NULL,
        `user_id`    INT(11)          NOT NULL,
        `vote`       ENUM('up','down') NOT NULL,
        `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_vote` (`report_id`, `user_id`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ── 5. login_logs ────────────────────────────────────────────────────────────
run($conn, 'Create table <code>login_logs</code>',
    "CREATE TABLE IF NOT EXISTS `login_logs` (
        `id`         INT(11)            NOT NULL AUTO_INCREMENT,
        `user_id`    INT(11)            DEFAULT NULL,
        `email`      VARCHAR(191)       NOT NULL,
        `ip_address` VARCHAR(100)       DEFAULT NULL,
        `device`     TEXT               DEFAULT NULL,
        `status`     ENUM('Success','Failed') NOT NULL,
        `created_at` TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ── 6. Foreign keys (add only if not already present) ────────────────────────
// reports.user_id → users.id
$fk1 = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='reports'
    AND CONSTRAINT_NAME='fk_reports_user' LIMIT 1");
if ($fk1 && $fk1->num_rows === 0) {
    run($conn, 'Add foreign key <code>fk_reports_user</code>',
        "ALTER TABLE `reports`
         ADD CONSTRAINT `fk_reports_user`
         FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE"
    );
} else {
    $steps[] = ['skip', 'Foreign key <code>fk_reports_user</code> already exists — skipped'];
}

// report_votes.report_id → reports.id
$fk2 = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='report_votes'
    AND CONSTRAINT_NAME='fk_votes_report' LIMIT 1");
if ($fk2 && $fk2->num_rows === 0) {
    run($conn, 'Add foreign key <code>fk_votes_report</code>',
        "ALTER TABLE `report_votes`
         ADD CONSTRAINT `fk_votes_report`
         FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE"
    );
} else {
    $steps[] = ['skip', 'Foreign key <code>fk_votes_report</code> already exists — skipped'];
}

// report_votes.user_id → users.id
$fk3 = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='report_votes'
    AND CONSTRAINT_NAME='fk_votes_user' LIMIT 1");
if ($fk3 && $fk3->num_rows === 0) {
    run($conn, 'Add foreign key <code>fk_votes_user</code>',
        "ALTER TABLE `report_votes`
         ADD CONSTRAINT `fk_votes_user`
         FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE"
    );
} else {
    $steps[] = ['skip', 'Foreign key <code>fk_votes_user</code> already exists — skipped'];
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// ── 7. Default admin account ─────────────────────────────────────────────────
$chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$adminEmail = 'admin@sentri.ph';
$chk->bind_param("s", $adminEmail);
$chk->execute();
$chk->store_result();

if ($chk->num_rows === 0) {
    $chk->close();
    // password: Admin@1234
    $hash  = password_hash('Admin@1234', PASSWORD_BCRYPT, ['cost' => 12]);
    $fname = 'SenTri'; $lname = 'Admin'; $role = 'admin';
    $ins   = $conn->prepare(
        "INSERT INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?)"
    );
    $ins->bind_param("sssss", $fname, $lname, $adminEmail, $hash, $role);
    if ($ins->execute()) {
        $steps[] = ['ok', 'Default admin created — <strong>admin@sentri.ph</strong> / <strong>Admin@1234</strong>'];
    } else {
        $steps[] = ['error', 'Failed to create admin account: ' . htmlspecialchars($conn->error)];
        $success = false;
    }
    $ins->close();
} else {
    $chk->close();
    $steps[] = ['skip', 'Admin account <code>admin@sentri.ph</code> already exists — skipped'];
}

// ── 8. report_images table ───────────────────────────────────────────────────
run($conn, 'Create table <code>report_images</code> (photo attachments)',
    "CREATE TABLE IF NOT EXISTS `report_images` (
      `id`            int(11)      NOT NULL AUTO_INCREMENT,
      `report_id`     int(11)      NOT NULL,
      `file_name`     varchar(255) NOT NULL,
      `original_name` varchar(255) DEFAULT NULL,
      `mime_type`     varchar(100) DEFAULT NULL,
      `file_size`     int(11)      DEFAULT NULL,
      `uploaded_at`   timestamp    NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `report_id` (`report_id`),
      CONSTRAINT `fk_images_report` FOREIGN KEY (`report_id`)
        REFERENCES `reports` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ── 9. emergency_contacts table ───────────────────────────────────────────────
run($conn, 'Create table <code>emergency_contacts</code> (LGU, Hospital, Traffic, etc.)',
    "CREATE TABLE IF NOT EXISTS `emergency_contacts` (
      `id`             int(11)      NOT NULL AUTO_INCREMENT,
      `name`           varchar(255) NOT NULL,
      `type`           enum('lgu','hospital','traffic','barangay','police','fire','other') NOT NULL DEFAULT 'other',
      `barangay`       varchar(150) DEFAULT NULL,
      `city`           varchar(150) NOT NULL,
      `province`       varchar(150) DEFAULT NULL,
      `contact_number` varchar(50)  DEFAULT NULL,
      `contact_email`  varchar(191) DEFAULT NULL,
      `is_active`      tinyint(1)   NOT NULL DEFAULT 1,
      `created_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
      `updated_at`     timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_city` (`city`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ── 10. contact_notifications table ──────────────────────────────────────────
run($conn, 'Create table <code>contact_notifications</code> (notification log)',
    "CREATE TABLE IF NOT EXISTS `contact_notifications` (
      `id`         int(11) NOT NULL AUTO_INCREMENT,
      `report_id`  int(11) NOT NULL,
      `contact_id` int(11) NOT NULL,
      `method`     enum('email','sms','auto_call') NOT NULL DEFAULT 'email',
      `status`     enum('sent','failed','pending')  NOT NULL DEFAULT 'pending',
      `sent_at`    timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `report_id` (`report_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ── 11. Add phone_number column to users ─────────────────────────────────────
$colCheck = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA='sentri' AND TABLE_NAME='users' AND COLUMN_NAME='phone_number'");
if ($colCheck && $colCheck->num_rows === 0) {
    run($conn, 'Add <code>phone_number</code> column to <code>users</code>',
        "ALTER TABLE `users` ADD COLUMN `phone_number` varchar(30) DEFAULT NULL AFTER `email`");
} else {
    $steps[] = ['skip', 'Column <code>phone_number</code> already exists — skipped'];
}

// ── Create uploads directory if writable ─────────────────────────────────────
$uploadsDir = __DIR__ . '/uploads/reports/';
if (!is_dir($uploadsDir)) {
    if (@mkdir($uploadsDir, 0755, true)) {
        $steps[] = ['ok', 'Created directory <code>uploads/reports/</code>'];
    } else {
        $steps[] = ['error', 'Could not create <code>uploads/reports/</code> — create it manually and set chmod 755'];
    }
} else {
    $steps[] = ['skip', 'Directory <code>uploads/reports/</code> already exists'];
}

$conn->close();

// ── Render ────────────────────────────────────────────────────────────────────
render($steps, $success);

function render(array $steps, bool $success): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SenTri – Database Installer</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }
  body { background:#f0f2f7; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:30px; }

  .card {
    background:#fff; border-radius:18px; padding:36px 40px;
    box-shadow:0 8px 32px rgba(0,0,0,0.1); width:100%; max-width:620px;
  }

  .card-header { display:flex; align-items:center; gap:14px; margin-bottom:28px; }
  .card-header i { font-size:2.2rem; color:#1c57b2; }
  .card-header div h1 { font-size:1.4rem; font-weight:700; color:#1a1a2e; }
  .card-header div p  { font-size:0.83rem; color:#888; margin-top:2px; }

  .steps { display:flex; flex-direction:column; gap:8px; margin-bottom:28px; }
  .step {
    display:flex; align-items:flex-start; gap:12px;
    padding:10px 14px; border-radius:9px; font-size:0.86rem; line-height:1.5;
  }
  .step.ok    { background:#f0fff4; color:#276749; }
  .step.error { background:#fff5f5; color:#c53030; }
  .step.skip  { background:#fafafa; color:#888; }
  .step i     { margin-top:2px; flex-shrink:0; font-size:0.9rem; }
  .step.ok    i { color:#38a169; }
  .step.error i { color:#e53e3e; }
  .step.skip  i { color:#bbb; }
  .step code { background:rgba(0,0,0,0.06); padding:1px 5px; border-radius:4px; font-size:0.82rem; }
  .step strong { font-weight:600; }

  .result {
    border-radius:12px; padding:18px 20px;
    display:flex; align-items:center; gap:14px;
  }
  .result.success { background:linear-gradient(135deg,#f0fff4,#e6ffed); border:1.5px solid #9ae6b4; }
  .result.fail    { background:linear-gradient(135deg,#fff5f5,#ffe5e5); border:1.5px solid #feb2b2; }
  .result i { font-size:1.8rem; flex-shrink:0; }
  .result.success i { color:#38a169; }
  .result.fail    i { color:#e53e3e; }
  .result div h2 { font-size:1rem; font-weight:700; margin-bottom:4px; }
  .result.success div h2 { color:#276749; }
  .result.fail    div h2 { color:#c53030; }
  .result div p { font-size:0.82rem; color:#555; line-height:1.6; }

  .actions { margin-top:22px; display:flex; gap:12px; flex-wrap:wrap; }
  .btn {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 22px; border-radius:9px; font-size:0.88rem;
    font-weight:600; text-decoration:none; transition:0.2s; cursor:pointer; border:none;
    font-family:'Poppins',sans-serif;
  }
  .btn-primary { background:#1c57b2; color:#fff; }
  .btn-primary:hover { background:#164eab; }
  .btn-danger  { background:#fff0f0; color:#e53e3e; border:1.5px solid #feb2b2; }
  .btn-danger:hover { background:#e53e3e; color:#fff; }

  .warning-box {
    margin-top:20px; background:#fffbeb; border:1.5px solid #f6e05e;
    border-radius:10px; padding:13px 16px; font-size:0.82rem; color:#744210;
    display:flex; align-items:flex-start; gap:10px;
  }
  .warning-box i { color:#d69e2e; margin-top:2px; flex-shrink:0; }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <i class="fas fa-shield-halved"></i>
    <div>
      <h1>SenTri Installer</h1>
      <p>Automatic database setup — <?= date('Y-m-d H:i:s') ?></p>
    </div>
  </div>

  <div class="steps">
    <?php foreach ($steps as [$type, $msg]): ?>
    <div class="step <?= $type ?>">
      <i class="fas <?= $type === 'ok' ? 'fa-circle-check' : ($type === 'error' ? 'fa-circle-xmark' : 'fa-circle-minus') ?>"></i>
      <span><?= $msg ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($success): ?>
  <div class="result success">
    <i class="fas fa-circle-check"></i>
    <div>
      <h2>Installation Complete!</h2>
      <p>
        Database <strong>sentri</strong> is ready.<br>
        Admin login: <strong>admin@sentri.ph</strong> / <strong>Admin@1234</strong>
        (change this after first login).
      </p>
    </div>
  </div>
  <div class="actions">
    <a href="index.php" class="btn btn-primary"><i class="fas fa-arrow-right"></i> Go to SenTri</a>
    <a href="login.php" class="btn btn-primary" style="background:#38a169;"><i class="fas fa-right-to-bracket"></i> Log In</a>
  </div>
  <div class="warning-box">
    <i class="fas fa-triangle-exclamation"></i>
    <span><strong>Security reminder:</strong> Delete or rename <code>install.php</code> immediately after setup to prevent unauthorized re-execution.</span>
  </div>

  <?php else: ?>
  <div class="result fail">
    <i class="fas fa-circle-xmark"></i>
    <div>
      <h2>Installation Failed</h2>
      <p>One or more steps encountered an error. Review the log above, fix the issue, and refresh this page to retry.</p>
    </div>
  </div>
  <div class="actions">
    <button onclick="location.reload()" class="btn btn-primary"><i class="fas fa-rotate-right"></i> Retry</button>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
<?php } ?>
