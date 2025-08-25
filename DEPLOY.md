# Survey Engine Deployment Guide

This guide provides comprehensive steps for deploying the FormBase Survey Engine from your local MAMP development environment to a **production server at the root domain**. The system is designed and optimized for root domain deployment.

## Pre-Deployment Checklist

### 1. Current Local Configuration
- **Document Root**: `/Applications/MAMP/htdocs/survey-engine`
- **Access URLs**: 
  - Admin: `http://localhost/fbs/admin/`
  - Surveys: `http://localhost/s/{survey_id}`
  - Trackers: `http://localhost/t/{survey_id}`
- **Database**: `fbtv3` (MySQL) - Export available in `db/fbtv3_20250825.sql`
- **RewriteBase**: `/` (configured for root deployment)

### 2. Server Requirements
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **Apache**: 2.4+ with mod_rewrite enabled
- **Extensions**: PDO_MySQL, GD, OpenSSL, cURL
- **Composer**: Latest version

---

## Root Domain Deployment (Recommended & Optimized)

**This is the primary and recommended deployment method. Your current configuration is already set up for this approach.**

### Step 1: Server Setup
```bash
# Create web directory (if using Apache/Nginx)
sudo mkdir -p /var/www/html
cd /var/www/html

# Clone/upload your project files
# Upload all files from /Applications/MAMP/htdocs/survey-engine/ to /var/www/html/
```

### Step 2: File Upload
Upload the entire survey-engine folder contents to your web server's document root:
```
/var/www/html/
├── fbs/
├── db/
├── index.php
├── .htaccess
└── README.md
```

### Step 3: Database Setup
```bash
# Create database
mysql -u root -p
CREATE DATABASE fbtv3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;

# Import your current database export (contains all tables and data)
mysql -u root -p fbtv3 < db/fbtv3_20250825.sql

# Verify database import
mysql -u root -p fbtv3 -e "SHOW TABLES;"
```

**Important**: The `fbtv3_20250825.sql` file contains your complete database including:
- All base tables (survey, question, users, etc.)
- Question grouping system tables (question_groups, question_group_assignments)
- All your survey data and configurations
- User accounts and settings

### Step 4: Configuration Updates
```php
// Update fbs/admin/connect.php
<?php
$host = "localhost"; // Or your database host
$dbname = "fbtv3"; // Your production database name
$username = "your_db_user"; // Production database user
$password = "your_secure_password"; // Production database password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
```

### Step 5: Dependencies Installation
```bash
cd /var/www/html/fbs
composer install --no-dev --optimize-autoloader
```

### Step 6: Permissions Setup
```bash
# Set proper ownership
sudo chown -R www-data:www-data /var/www/html

# Set proper permissions
sudo find /var/www/html -type d -exec chmod 755 {} \;
sudo find /var/www/html -type f -exec chmod 644 {} \;

# Make specific directories writable
sudo chmod -R 775 /var/www/html/fbs/admin/asets/
sudo chmod -R 775 /var/www/html/uploads/ # If exists
```

### Step 7: Apache Configuration
Ensure your `.htaccess` is correctly configured (current configuration should work):
```apache
RewriteEngine On
RewriteBase /

# Rule for regular surveys
RewriteRule ^s/([0-9]+)$ fbs/public/survey_page.php?survey_id=$1 [L]
# ... (rest of your current rules)
```

---

---

## Alternative: Subfolder Deployment (Not Recommended)

**Note**: While subfolder deployment is possible, it requires additional configuration changes and is not the recommended approach. The system is optimized for root domain deployment.

If you must deploy to a subfolder, you'll need to:
1. Update `RewriteBase` in `.htaccess` to `/your-subfolder/`
2. Update any hardcoded paths in PHP files
3. Test all URL rewriting rules thoroughly

**For best results, use root domain deployment as outlined above.**

---

## SSL Configuration

### Let's Encrypt (Free SSL)
```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache

# Get SSL certificate
sudo certbot --apache -d yourdomain.com

# Auto-renewal (add to crontab)
0 12 * * * /usr/bin/certbot renew --quiet
```

---

## Security Hardening

### 1. Environment Variables
Create `.env` file for sensitive data:
```env
DB_HOST=localhost
DB_NAME=fbtv3
DB_USER=your_db_user
DB_PASS=your_secure_password
```

### 2. Additional Security Headers
Add to your Apache virtual host or .htaccess:
```apache
# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"
Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
```

### 3. Hide Sensitive Files
```apache
# Deny access to sensitive files
<Files ~ "^\.">
    Order allow,deny
    Deny from all
</Files>

<Files "composer.json">
    Order allow,deny
    Deny from all
</Files>

<Files "DEPLOY.md">
    Order allow,deny
    Deny from all
</Files>
```

---

## Post-Deployment Testing

### 1. Test Core Functionality
- ✅ Admin login: `https://yourdomain.com/fbs/admin/`
- ✅ Survey creation and management
- ✅ Public survey access: `https://yourdomain.com/s/{survey_id}`
- ✅ Tracker programs: `https://yourdomain.com/t/{survey_id}`
- ✅ Database groupings load correctly
- ✅ File uploads work
- ✅ DHIS2 integration (if used)

### 2. Performance Testing
- Test with multiple concurrent users
- Monitor database performance
- Check page load times

### 3. Mobile Responsiveness
- Test all forms on mobile devices
- Verify tracker programs work on tablets

---

## Backup Strategy

### Database Backup
```bash
# Daily backup script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u root -p fbtv3 > /backups/fbtv3_backup_$DATE.sql
```

### File Backup
```bash
# Weekly file backup
tar -czf /backups/survey_engine_files_$(date +%Y%m%d).tar.gz /var/www/html
```

---

## Monitoring & Maintenance

### Log Monitoring
- Monitor Apache error logs: `/var/log/apache2/error.log`
- Monitor PHP errors: Check your PHP error log configuration
- Application logs: Monitor survey submissions and errors

### Regular Updates
- Keep PHP and MySQL updated
- Monitor for security patches
- Regular database maintenance

### Performance Monitoring
- Monitor database query performance
- Check disk space usage
- Monitor memory and CPU usage

---

## Troubleshooting

### Common Issues

1. **Rewrite Rules Not Working**
   - Ensure mod_rewrite is enabled: `sudo a2enmod rewrite`
   - Restart Apache: `sudo systemctl restart apache2`

2. **Database Connection Issues**
   - Check database credentials in `connect.php`
   - Verify MySQL service is running
   - Check firewall settings

3. **File Permission Issues**
   - Ensure web server can read/write required directories
   - Check upload directory permissions

4. **HTTPS Issues**
   - Verify SSL certificate installation
   - Check mixed content warnings
   - Update any hardcoded HTTP links

### Debug Mode
For troubleshooting, temporarily enable PHP error display:
```php
// Add to top of problematic PHP files during debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
```

---

## Final Checklist

- [ ] Database migrated and tested
- [ ] All dependencies installed
- [ ] File permissions set correctly
- [ ] SSL certificate installed
- [ ] Rewrite rules working
- [ ] Admin panel accessible
- [ ] Public surveys working
- [ ] Tracker programs functional
- [ ] Question groupings loading
- [ ] Mobile responsiveness verified
- [ ] Backup systems in place
- [ ] Monitoring configured

**Your survey engine should now be successfully deployed! 🚀**

For support or issues, refer to the main README.md or check the application logs.