# SenTri
### A Community-Driven Web-Based Safety Incident Reporting & Geospatial Monitoring System

---

## Project Structure

```
sentri-system/
│
├── index.php              # Landing / welcome page
├── login.php              # User & admin login
├── signup.php             # New user registration
├── logout.php             # Session destroy
├── dashboard.php          # Main community feed, map, report form
├── admin.php              # Admin control panel
├── forgot_password.php    # Password reset request
├── reset_password.php     # Password reset (via token)
├── verify_email.php       # Email verification handler
├── install.php            # One-time DB installer (delete after use)
│
├── config/
│   ├── db.php             # Database connection (MySQLi)
│   ├── email.php          # SMTP / mailer configuration
│   └── .htaccess          # Blocks direct web access to config/
│
├── core/
│   └── SenTriMailer.php  # Minimal Gmail SMTP mailer (no Composer)
│
├── api/
│   ├── reports.php        # Reports CRUD, voting, profile, GPS, image upload
│   ├── contacts.php       # Emergency contacts CRUD + notification dispatch
│   └── geocode_proxy.php  # Server-side Nominatim proxy (CORS-safe)
│
├── uploads/
│   ├── .htaccess          # Blocks PHP execution inside uploads/
│   └── reports/           # Uploaded incident photos (runtime, git-ignored)
│
└── sql/
    ├── sentri.sql                          # Full fresh-install schema
    └── migrations/
        ├── 001_map.sql                        # Geo columns (lat/lng/radius)
        ├── 002_email_verification.sql         # Email token table
        ├── 003_profile.sql                    # Avatar color, GPS columns
        ├── 004_report_images.sql              # Photo upload table
        └── 005_emergency_contacts.sql         # LGU/Hospital/Traffic contacts
```

---

## Quick Install

### Requirements
- PHP 8.0+
- MySQL 8.0
- Apache or Nginx with PHP-FPM
- Local stack: XAMPP / Laragon / MAMP

### Steps

1. Copy the project folder into your web server root
   (e.g. htdocs/sentri-system/ for XAMPP)

2. Configure config/email.php with your Gmail App Password
   (needed for registration, password reset, and alert emails)

3. Run the installer in your browser:
   http://localhost/sentri-system/install.php
   This creates the sentri database and all tables automatically.

4. Delete install.php after successful installation.

5. Access the system:
   - Community:  http://localhost/sentri-system/
   - Admin panel: http://localhost/sentri-system/admin.php
   - Default admin: admin@sentri.ph / Admin@1234
   ⚠️  Change the admin password immediately after first login.

---

## Features

### Community (Registered Users)
- Submit geo-tagged incident reports with title, category, severity status, and location pin
- Attach up to 3 photos per report (JPG, PNG, WEBP — max 5 MB each)
- Adjustable affected radius slider (50 m to 3 km)
- Upvote / downvote reports for community credibility scoring
- Interactive Leaflet.js map with color-coded severity overlays
- Filter feed by status, category, or keyword
- Dark / light mode toggle

### Admin Panel
- Moderate reports (archive / restore / delete)
- Manage user accounts and roles
- Emergency Contacts directory — add LGU offices, hospitals, traffic offices,
  police, fire stations with phone number and email; link to specific barangays
  or set city-wide coverage
- View login audit log (IP, device, success/failure)

### Automatic Notifications
When a Dangerous report is submitted, api/contacts.php is called automatically
to email all active emergency contacts whose city (and optionally barangay)
matches the report location.

---

## Technology Stack

| Layer        | Technology                             |
|--------------|----------------------------------------|
| Backend      | PHP 8.0+ (procedural + MySQLi)         |
| Database     | MySQL 8.0 (InnoDB, utf8mb4)            |
| Frontend     | Vanilla HTML5, CSS3, JavaScript ES6+   |
| Map Engine   | Leaflet.js 1.9.4 + OpenStreetMap       |
| Geocoding    | Nominatim (server-proxied)             |
| Icons        | Font Awesome 6.5                       |
| Typography   | Google Fonts — Poppins                 |
| Auth         | PHP native sessions + bcrypt (cost 12) |
| Email        | Gmail SMTP via SenTriMailer         |
| Web Server   | Apache or Nginx + PHP-FPM              |

---

## Database Tables

| Table                   | Purpose                                        |
|-------------------------|------------------------------------------------|
| users                   | Registered accounts (role: user / admin)       |
| reports                 | Incident reports with geo fields               |
| report_images           | Photo attachments linked to reports            |
| report_votes            | Upvote / downvote records (1 per user/report)  |
| emergency_contacts      | LGU, hospital, traffic, police, fire directory |
| contact_notifications   | Log of emails sent per report                  |
| login_logs              | Authentication audit trail                     |

---

## Security Notes

- config/ is protected by .htaccess (direct web access denied)
- uploads/ blocks PHP execution via .htaccess
- All DB queries use prepared statements with bound parameters
- Passwords hashed with password_hash() at bcrypt cost 12
- Role-based session checks on every protected page and API endpoint
- Rate limiting: 10 reports per user per hour

---

## Roadmap

- [ ] Real-time proximity alerts via polling or WebSocket
- [ ] SMS notification support (Semaphore / Vonage PH)
- [ ] Auto-call integration for Dangerous reports (Twilio)
- [ ] System rename / rebranding (client request - TBD)
- [ ] Mobile app wrapper (future)
