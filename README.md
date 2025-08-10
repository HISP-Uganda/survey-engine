# Survey Engine

This project is a PHP-based survey management tool called "FormBase". It allows users to create, deploy, and analyze surveys with features like a visual survey builder, DHIS2 integration, and real-time analytics.

## Features

*   **Survey Management:** Create, edit, and manage surveys.
*   **Question Bank:** A repository of questions for building surveys.
*   **User Management:** Manage admin users.
*   **DHIS2 Integration:** Sync survey data with DHIS2 instances.
*   **Reporting and Analytics:** View survey submissions and analyze data through a dashboard with charts and statistics.
*   **Mobile-First Design:** Surveys are optimized for mobile devices.
*   **Advanced Question Types:** Supports various question types, including conditional logic and validation.
*   **Multi-Language Support:** Create surveys in multiple languages.

## Installation

1.  **Prerequisites:**
    *   PHP
    *   MySQL or other compatible database
    *   Composer

2.  **Clone the repository:**
    ```bash
    git clone <repository-url>
    ```

3.  **Install dependencies:**
    ```bash
    cd fbs
    composer install
    ```

4.  **Database Setup:**
    *   Create a database for the application.
    *   Import the latest SQL file from the `db/` directory to set up the necessary tables:
        ```bash
        mysql -u <username> -p <database_name> < db/fbtv3_20250810.sql
        ```
    *   Configure the database connection in `fbs/admin/connect.php` with your database credentials.

5.  **Web Server Configuration:**
    *   Point your web server's document root to the project directory.
    *   If using Apache, ensure the `.htaccess` file is configured to handle URL rewriting for the survey engine routes.
    *   For subfolder installations (e.g., `/survey-engine/`), update the base paths in:
        *   `.htaccess` - Adjust RewriteBase if needed
        *   `fbs/admin/connect.php` - Update any absolute path references
        *   Configuration files to reflect the correct subfolder structure

6.  **Remove Unused Dependencies:**
    *   Delete the root-level `vendor/` directory as the application uses `fbs/vendor/` instead:
        ```bash
        rm -rf vendor/
        ```

## Usage

*   **Admin Panel:** Access the admin panel by navigating to `fbs/admin/`. The default login credentials may need to be configured or reset.
*   **Survey Submission:** Surveys can be accessed and submitted via their respective URLs. The `fbs/submit.php` file handles form submissions.
*   **Landing Page:** The main landing page is `index.php`.

## Dependencies

This project uses the following PHP libraries, managed by Composer:

*   `mpdf/mpdf`: A PHP library for generating PDF files.
*   `phpoffice/phpspreadsheet`: A PHP library for reading and writing spreadsheet files.

## Database

The database schema is defined in the SQL files located in the `db/` directory. The key tables include:

*   `survey`: Stores survey information.
*   `question`: Stores survey questions.
*   `submission`: Stores survey submission metadata.
*   `submission_response`: Stores individual responses to questions.
*   `users`: Stores admin user information.

## API

The application includes a few API endpoints for asynchronous data retrieval:

*   `fbs/admin/api/groupings.php`
*   `fbs/admin/api/questions.php`
*   `fbs/api/get_submissions.php`
*   `fbs/admin/dhis2/`: Contains several endpoints for DHIS2 integration.
*   `fbs/admin/dashboard_api.php`: Provides analytics data for survey dashboards, including tracker program support.

## Recent Updates

### Tracker Program Support (August 2025)
*   **Enhanced Dashboard Analytics:** Fixed tracker program dashboard to properly display analytics for DHIS2 tracker programs
*   **Question Analysis:** Improved question response analysis for tracker programs that store data in JSON format in the `tracker_submissions` table
*   **Survey Type Identification:** Removed T, E, A bracket indicators from survey names since `domain_type` column now properly identifies survey types
*   **Vendor Cleanup:** Consolidated dependencies to use `fbs/vendor/` directory only, removing duplicate root-level `vendor/` folder

### Database Schema
*   **Tracker Tables:** Added `tracker_submissions` table for DHIS2 tracker program data storage
*   **Program Types:** Enhanced `survey` table with `program_type` and `domain_type` columns for better survey classification
