# Tracker Submissions Table Improvements

## Problem
Currently, tracker submissions use `T-{id}` format for UIDs instead of proper unique identifiers. This causes issues with:
- Participant ID display in forms
- Download exports showing temporary IDs
- Inconsistent UID format compared to regular submissions

## Solution
Add a proper `uid` column to the `tracker_submissions` table and update the submission process to generate real UIDs.

## Database Changes Required

### 1. Add UID Column to Existing Table
```sql
ALTER TABLE tracker_submissions ADD COLUMN uid VARCHAR(25) UNIQUE DEFAULT NULL AFTER id;
```

### 2. Add Index for UID Column
```sql
ALTER TABLE tracker_submissions ADD INDEX idx_uid (uid);
```

### 3. Update Existing Records (Optional - Backfill)
```sql
UPDATE tracker_submissions SET uid = UPPER(SUBSTR(MD5(CONCAT(id, UNIX_TIMESTAMP())), 1, 10)) WHERE uid IS NULL;
```

## Updated Table Structure
After applying the changes, the `tracker_submissions` table will have:

```sql
CREATE TABLE tracker_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(255) UNIQUE DEFAULT NULL,                    -- NEW COLUMN
    survey_id INT NOT NULL,
    tracked_entity_instance VARCHAR(255),
    selected_facility_id VARCHAR(255),
    selected_facility_name VARCHAR(500),
    selected_orgunit_uid VARCHAR(255),
    form_data JSON,
    dhis2_response JSON,
    submission_status ENUM('submitted', 'failed') DEFAULT 'submitted',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_session_id VARCHAR(255),
    INDEX idx_survey_id (survey_id),
    INDEX idx_uid (uid),                                     -- NEW INDEX
    INDEX idx_tei (tracked_entity_instance),
    INDEX idx_facility (selected_facility_id),
    INDEX idx_status (submission_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Code Changes Required After Database Update

### 1. Update tracker_program_submit.php
- Generate UID before saving submission
- Pass UID to saveLocalSubmission function
- Return UID in success response for display

### 2. Update all display files to use `uid` instead of `CONCAT('T-', id)`
Files to update:
- `/fbs/admin/records.php`
- `/fbs/admin/view_record.php`
- `/fbs/admin/generate_download.php`
- `/fbs/admin/main.php`
- `/fbs/admin/settings/payload_checker_tab.php`

### 3. Update tracker form to display UID as Participant ID
The tracker form should show the generated UID as the "Participant ID" instead of a temporary ID.

## Benefits After Implementation
1. ✅ **Proper UIDs** - Real unique identifiers instead of T-{id}
2. ✅ **Consistent Format** - Same UID format as regular submissions
3. ✅ **Better UX** - Participant ID shows actual UID in form
4. ✅ **Clean Downloads** - Proper UIDs in exported data
5. ✅ **Database Integrity** - Proper unique constraints on UID field

## Implementation Steps
1. Run the ALTER TABLE statements above
2. Update code files to use `uid` column instead of generating T-{id}
3. Test tracker form submission to ensure UID generation works
4. Verify all display interfaces show proper UIDs
5. Test download functionality with real UIDs