-- SenTri Migration 007: Forced password reset for provisioned accounts
-- Official government accounts (barangay / LGU / first responder) are created
-- by the administrator with a temporary password. This flag forces them to
-- set their own password at first sign-in (handled in core/login_handler.php
-- via the reset-token flow, cleared in reset_password.php).
--
-- Run AFTER sentri.sql (fresh install) or on top of any existing install.

USE `sentri`;

ALTER TABLE users ADD COLUMN force_password_reset TINYINT(1) NOT NULL DEFAULT 0;
