# Form Base Survey Tool (FBS)

A comprehensive PHP-based web application for creating, managing, and deploying surveys with integrated DHIS2 support.

## Features

- **Dual Survey Creation**: Create both local surveys and DHIS2-integrated surveys
- **DHIS2 Integration**: Full support for DHIS2 programs, datasets, and data synchronization
- **Multi-language Support**: Built-in translation management system
- **QR Code Sharing**: Mobile-friendly survey access via QR codes
- **Data Export**: Generate PDF and Excel reports
- **User Management**: Admin authentication and session management
- **Question Bank**: Reusable question library with various question types
- **Analytics Dashboard**: Real-time survey analytics and visualizations
- **Responsive Design**: Modern UI built with Argon Dashboard framework

## Architecture

### Directory Structure
- **`/fbs/`** - Main application directory
  - **`/admin/`** - Administrative interface with Argon Dashboard theme
    - `/components/` - Reusable UI components (navbar, sidebar, etc.)
    - `/dhis2/` - DHIS2 integration modules
    - `/uploads/` - File uploads (survey logos, etc.)
    - `/temp/` - Temporary files for sync operations
  - **`/api/`** - API endpoints for data access
  - **`/vendor/`** - Composer dependencies
- **`/db/`** - Database schema and backups
- **`/index.php`** - Public landing page

### Technology Stack
- **Backend**: PHP 8.3+ with PDO for database operations
- **Frontend**: HTML5, CSS3, JavaScript with Argon Dashboard framework
- **Database**: MySQL 8.0 (database name: `fbtv3`)
- **Dependencies**: Managed via Composer
  - `mpdf/mpdf` - PDF generation
  - `phpoffice/phpspreadsheet` - Excel file handling

## Quick Start

### Prerequisites
- PHP 8.3 or higher
- MySQL 8.0 or higher
- Web server (Apache/Nginx) or MAMP/XAMPP for local development
- Composer for dependency management

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd survey-engine
   ```

2. **Install dependencies**
   ```bash
   cd fbs
   composer install
   ```

3. **Database setup**
   - Import the latest database schema from `/db/fbtv3_20250803.sql`
   - Update database credentials in `/fbs/admin/connect.php`

4. **Configuration**
   - Update database connection settings
   - Configure web server to point to the project root
   - Ensure proper file permissions for uploads directory

### Default Access
- **Public Interface**: `http://localhost/survey-engine/`
- **Admin Interface**: `http://localhost/survey-engine/fbs/admin/`
- **Default Credentials**: Create admin account via registration

## Development Setup

### Database Configuration
- Database connection settings are in `/fbs/admin/connect.php` and `/fbs/db.php`
- Default credentials: `localhost:3306`, database: `fbtv3`, user: `root`, password: `root`
- Latest schema is in `/db/fbtv3_20250728.sql`

### Local Development
This appears to be configured for MAMP environment:
- Document root: `/Applications/MAMP/htdocs/survey-engine`
- Access via: `http://localhost/survey-engine/`
- Admin interface: `http://localhost/survey-engine/fbs/admin/`

### Dependencies
Install PHP dependencies:
```bash
cd fbs
composer install
```

## Key Components

### Survey Creation
- **Survey Builder** (`/fbs/admin/sb.php`) - Create both local and DHIS2 surveys
- **Question Bank** (`/fbs/admin/question_bank.php`) - Manage reusable questions
- **Question Manager** (`/fbs/admin/question_manager.php`) - Advanced question management

### DHIS2 Integration
- **DHIS2 Configuration** (`/fbs/admin/settings.php`) - Manage DHIS2 instances
- **Data Synchronization** (`/fbs/admin/dhis2/`) - Sync modules and mappings
- **Program/Dataset Import** - Import DHIS2 programs and datasets as surveys

### Data Management
- **Survey Records** (`/fbs/admin/records.php`) - View and manage survey responses
- **Analytics Dashboard** (`/fbs/admin/survey_dashboard.php`) - Real-time analytics
- **Data Export** (`/fbs/admin/generate_download.php`) - PDF and Excel exports

### User Interface
- **Admin Dashboard** (`/fbs/admin/main.php`) - Main admin interface
- **Survey Listing** (`/fbs/admin/survey.php`) - Survey management
- **Form Preview** (`/fbs/admin/preview_form.php`) - Preview surveys before deployment

## Usage

### Creating Surveys

1. **Local Surveys**
   - Navigate to Survey Builder (`sb.php`)
   - Select "Local Survey" option
   - Configure survey details and questions
   - Deploy and share

2. **DHIS2 Surveys**
   - Configure DHIS2 instances in Settings
   - Navigate to Survey Builder (`sb.php`)
   - Select "DHIS2 Survey" option
   - Choose DHIS2 instance and program/dataset
   - Map questions and deploy

### Managing Data
- View responses in Survey Records
- Use Analytics Dashboard for insights
- Export data in PDF or Excel formats
- Sync data with DHIS2 systems

## Security & Deployment

### Security Features
- Session-based authentication with timeout management
- Password hashing using PHP's `password_hash()` function
- SQL injection protection via PDO prepared statements
- User permission management
- Secure file upload handling

### Production Deployment
- Configure environment variables for database credentials
- Set up proper file permissions for upload directories
- Configure web server with SSL/HTTPS
- Regular database backups
- Monitor error logs and system performance

### System Requirements
- PHP 8.3+ with PDO MySQL extension
- MySQL 8.0+ database server
- Web server with URL rewriting support
- Sufficient disk space for file uploads and logs

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## Support

For technical support or questions:
- Check the documentation
- Review error logs in `/fbs/admin/dhis2/php-error.log`
- Test database connectivity
- Verify DHIS2 configurations

## License

This project is developed for health information system integration and survey management.