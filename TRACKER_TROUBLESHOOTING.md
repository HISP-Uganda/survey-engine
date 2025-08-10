# DHIS2 Tracker Form Integration - Troubleshooting Guide

## Overview

The tracker form system in this survey engine uses a **completely different architecture** from the regular survey forms. This document explains the integration, data flow, storage, and common issues.

## Key Files and Components

### 1. **Frontend Files**
- **`/fbs/public/tracker_program_form.php`** - Main tracker form interface
- **`/fbs/admin/tracker_preview.php`** - Admin preview of tracker forms

### 2. **Backend Processing**
- **`/fbs/public/tracker_program_submit.php`** - Handles form submissions
- **`/fbs/public/tracker_program_success.php`** - Success/thank you page

### 3. **DHIS2 Connection Files**
The tracker form **does NOT use** the same DHIS2 connection system as regular surveys:

#### Regular Survey System:
- Uses: `dhis2_submit.php`, `dhis2_shared.php`
- Handler: `DHIS2SubmissionHandler` class
- Method: `dhis2_post()`, `dhis2_get()` functions

#### Tracker Form System:
- Uses: **Custom functions in `tracker_program_submit.php`**
- Handler: `submitToDHIS2Tracker()` function
- Method: **Direct cURL calls via `submitToDHIS2API()`**

## Data Storage Architecture

### 1. **Local Storage Tables**

#### Tracker Submissions Table:
```sql
CREATE TABLE tracker_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    user_session_id VARCHAR(255)
);
```

### 2. **Storage Process**
1. **Form submitted** → `tracker_program_submit.php`
2. **DHIS2 submission attempted** → `submitToDHIS2Tracker()`
3. **Data saved locally** → `tracker_submissions` table (**regardless of DHIS2 success/failure**)
4. **Response returned** → Success/failure message

## DHIS2 Integration Process

### 1. **Configuration Lookup**
```php
// Gets DHIS2 config from dhis2_instances table
$dhis2Config = null;
if (!empty($survey['dhis2_instance'])) {
    $stmt = $pdo->prepare("SELECT id, url as base_url, username, password, instance_key, description FROM dhis2_instances WHERE instance_key = ?");
    $stmt->execute([$survey['dhis2_instance']]);
    $dhis2Config = $stmt->fetch(PDO::FETCH_ASSOC);
}
```

### 2. **DHIS2 Submission Steps**
1. **Create Tracked Entity Instance (TEI)**
2. **Create Enrollment** 
3. **Create Events** (for each program stage)

### 3. **API Endpoints Used**
- `/api/trackedEntityInstances` - Create TEI
- `/api/enrollments` - Create enrollment  
- `/api/events` - Create events for program stages

## Key Differences from Regular Surveys

| Aspect | Regular Surveys | Tracker Forms |
|--------|----------------|---------------|
| **Submission Handler** | `DHIS2SubmissionHandler` class | `submitToDHIS2Tracker()` function |
| **Storage Table** | `submission` + `submission_response` | `tracker_submissions` |
| **DHIS2 Connection** | `dhis2_shared.php` functions | Custom cURL in `submitToDHIS2API()` |
| **Payload Checker** | ✅ Logs to `dhis2_submission_log` | ❌ **NO LOGGING TO PAYLOAD CHECKER** |
| **Retry Mechanism** | ✅ Via payload checker | ❌ **NO RETRY CAPABILITY** |
| **Error Handling** | Comprehensive with logging | Basic try/catch only |

## 🚨 **CRITICAL ISSUES IDENTIFIED**

### 1. **NO Payload Checker Integration**
- Tracker submissions **do NOT appear** in the payload checker
- Failed tracker submissions **cannot be retried**
- No entries in `dhis2_submission_log` table

### 2. **Inconsistent Error Handling**
- Tracker uses basic exception throwing
- No detailed DHIS2 error message extraction
- Limited logging compared to regular surveys

### 3. **Authentication Issues**
The tracker system uses **different authentication**:
```php
// Custom function instead of shared dhis2_shared.php
function submitToDHIS2API($url, $data, $dhis2Config) {
    // Custom cURL setup
    $auth = base64_encode($dhis2Config['username'] . ':' . $dhis2Config['password']);
    $headers = [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}
```

## Troubleshooting Steps

### 1. **Check DHIS2 Configuration**
```sql
-- Verify instance configuration
SELECT * FROM dhis2_instances WHERE instance_key = 'YOUR_INSTANCE_KEY';

-- Check survey DHIS2 settings
SELECT id, name, dhis2_instance, dhis2_program_uid, dhis2_tracked_entity_type_uid 
FROM survey WHERE id = YOUR_SURVEY_ID;
```

### 2. **Check Local Storage**
```sql
-- Check if submissions are being stored locally
SELECT * FROM tracker_submissions WHERE survey_id = YOUR_SURVEY_ID ORDER BY submitted_at DESC;
```

### 3. **Check Error Logs**
```bash
# Check PHP error logs for tracker submission errors
tail -f /Applications/MAMP/htdocs/survey-engine/fbs/admin/dhis2/php-error.log | grep -i tracker
```

### 4. **Test DHIS2 Connectivity**
- Use the connection test in DHIS2 Config tab (Settings)
- Verify credentials work for regular surveys first

### 5. **Manual DHIS2 API Testing**
```bash
# Test TEI creation endpoint
curl -X POST "https://your-dhis2-instance.com/api/trackedEntityInstances" \
  -H "Authorization: Basic $(echo -n 'username:password' | base64)" \
  -H "Content-Type: application/json" \
  -d '{"trackedEntityType":"MCPQUTHX1Ze","orgUnit":"YOUR_ORG_UNIT","attributes":[]}'
```

## Recommended Fixes

### 1. **Integrate with Payload Checker**
- Modify tracker system to use `dhis2_submission_log` table
- Add retry capability for failed tracker submissions

### 2. **Standardize DHIS2 Connection**
- Use shared `dhis2_shared.php` functions
- Consistent error handling and logging

### 3. **Improve Error Messages**
- Extract detailed DHIS2 error messages
- Better user feedback for failures

### 4. **Add Comprehensive Logging**
- Log all DHIS2 requests and responses
- Track retry attempts and outcomes

## Quick Fix for Current Issues

If tracker submissions are **always failing**, check:

1. **Credentials**: Verify base64 encoding/decoding in tracker vs regular system
2. **Endpoints**: Confirm DHIS2 tracker API endpoints are correct
3. **Permissions**: Ensure DHIS2 user has tracker program access
4. **Program Configuration**: Verify `dhis2_program_uid` and `dhis2_tracked_entity_type_uid` are correct

## Next Steps for Investigation

1. **Enable detailed logging** in `tracker_program_submit.php`
2. **Compare working regular survey** vs failing tracker submission
3. **Test same credentials** in both systems
4. **Check DHIS2 server logs** for incoming requests
5. **Verify DHIS2 program configuration** matches survey settings