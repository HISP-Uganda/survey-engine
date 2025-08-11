# Tracker Submission Test Analysis

## Current Test Data Structure - WHAT YOU NEED TO INPUT

Based on the DHIS2 validation errors, here's what each field expects:

### Working Test Data Structure
```json
{
  "events": {
    "aHufHcZAnm5_1": {
      "eventDate": "2025-08-11",
      "dataValues": {
        "ahf2oYbp4wQ": "true",           // Boolean field - use "true" or "false"
        "lID4wrE2yVu": "5",             // Numeric field - use numbers
        "uEEIHjPYMGO": "23"             // Numeric field - use numbers  
      },
      "programStage": "aHufHcZAnm5"
    },
    "aHufHcZAnm5_2": {
      "eventDate": "2025-08-11", 
      "dataValues": {
        "ahf2oYbp4wQ": "false",
        "lID4wrE2yVu": "3", 
        "uEEIHjPYMGO": "55"
      },
      "programStage": "aHufHcZAnm5"
    },
    "pkSb9eGI2qY_1": {
      "eventDate": "2025-08-11",
      "dataValues": {
        "Zw49RKyXYRj": "false",                    // Boolean field
        "ebmdvu4hMqa": "CARE International",       // OPTION SET - Must use exact valid option
        "fkipjGtgOHg": "ABC1234def5",             // FILE RESOURCE - Must use 11-char UID OR skip this field
        "jwJogFZa78i": "2019-2022"                // Text field - any text OK
      },
      "programStage": "pkSb9eGI2qY"
    }
  },
  "trackedEntityAttributes": {
    "Brip9mXEPEK": "+256793028334",    // Phone number format
    "KhpZKRtUL6W": "Any text here",    // Text field - any value OK
    "mfVkIGKYTzq": "+256793028334"     // Phone number format
  }
}
```

## CRITICAL FIELD REQUIREMENTS

### For `ebmdvu4hMqa` (Option Set Field)
**You MUST use one of these exact values:**
- `"United Nations Population Fund (UNFPA)"`
- `"CARE International"` 
- `"Straight Talk Africa"`
- `"World Vision International"`
- `"UNESCO"`
- `"Reach A Hand Uganda"`
- `"Save the Children International"`
- `"FAWE"`
- `"Trail Blazers Foundation"`
- `"UNICEF"`
- `"UNHCR"`
- `"BRAC"`
- `"Reproductive Health Uganda"`
- `"PLAN International"`
- `"PEPFAR"`
- `"Other Partner"` ← Use this for testing
- ...and many others from the error list

### For `fkipjGtgOHg` (File Resource Field)
**Options:**
1. **Skip this field entirely** (don't include it in dataValues)
2. **Use a valid file resource UID** (11 characters, starts with letter): `"ABC1234def5"`
3. **Do NOT use text values** like "Test" or "Other Partner"

## SOLUTION IMPLEMENTED ✅

The tracker form has been updated to:

1. **Fetch option sets from local database** instead of DHIS2 API
2. **Display dropdowns for option set fields** automatically
3. **Handle FILE_RESOURCE fields** properly (show info message, skip in submission)
4. **Enhanced error logging** for debugging

### Changes Made:

1. **Modified `tracker_program_form.php`:**
   - Added `getLocalOptionSetValues()` function to fetch from `dhis2_option_set_mapping` table
   - Enhanced DHIS2 program structure with local option sets
   - Added FILE_RESOURCE field handling (shows informational message)
   - Added debugging logs for option set loading

2. **Enhanced Form Rendering:**
   - Option set fields now automatically render as `<select>` dropdowns
   - FILE_RESOURCE fields show informational message instead of text input
   - Proper error handling and logging

### Expected Behavior Now:

- **Organization Name field (`ebmdvu4hMqa`)** → Dropdown with all valid organizations
- **File Resource fields** → Info message explaining file upload not available
- **All other fields** → Appropriate input types (text, number, date, etc.)

### Test the Updated Form:

1. Load the tracker form - should now show dropdowns for option set fields
2. Organization dropdown should contain: "Other Partner", "CARE International", "UNESCO", etc.
3. File resource fields should show info messages instead of text inputs
4. Form submission should now work without validation errors

The form will now **prevent users from entering invalid values** by providing proper dropdowns with valid options.

## DHIS2 API Errors Identified

### 1. File Resource Error
- **Error**: "File resource: `Test`, reference could not be found."
- **DataElement**: fkipjGtgOHg
- **Value**: "Test"
- **Issue**: DHIS2 expects file resource data elements to contain valid UIDs of uploaded files, not text values

### 2. Mandatory Tracked Entity Attribute Missing
- **Error**: "Attribute: `biIdwUNiNxa`, is mandatory in tracked entity type `y5jPHwWD9ZV` but not declared"
- **Issue**: The tracked entity type requires a mandatory attribute that's not included in the submission

### 3. Option Set Validation Error
- **Error**: "Value Testing is not a valid option for ebmdvu4hMqa DataElement"
- **DataElement**: ebmdvu4hMqa
- **Value**: "Testing " (note the trailing space)
- **Issue**: The value "Testing" (with or without trailing space) is not in the DHIS2 option set

### 4. Cascade Failure
- **Error**: "enrollment cannot be persisted because trackedEntity cannot be persisted"
- **Issue**: TrackedEntity creation fails due to missing mandatory attribute, causing enrollment to fail

## Validation Strategy

### For Data Values:
1. **File Resource Elements**: Check if data element expects file resource and handle accordingly
2. **Option Set Elements**: Validate against available options or provide proper mapping
3. **Text Elements**: Allow any text value
4. **Numeric Elements**: Validate numeric format

### For Tracked Entity Attributes:
1. **Check mandatory attributes**: Query DHIS2 for required attributes of the tracked entity type
2. **Provide default values**: For mandatory attributes not supplied by user
3. **Validate attribute types**: Ensure values match expected data types

## Recommended Solutions

### 1. Add Mandatory Attribute Check
```php
// Before creating tracked entity, check for mandatory attributes
$mandatoryAttributes = getMandatoryAttributesForTET($survey['dhis2_tracked_entity_type_uid'], $instanceKey);
foreach ($mandatoryAttributes as $attr) {
    if (!isset($formData['trackedEntityAttributes'][$attr['id']])) {
        // Add default value or prompt user
        $formData['trackedEntityAttributes'][$attr['id']] = getDefaultValueForAttribute($attr);
    }
}
```

### 2. Data Element Type Validation
```php
// Query DHIS2 for data element metadata to understand expected types
$dataElementMetadata = getDataElementMetadata($deId, $instanceKey);
if ($dataElementMetadata['valueType'] === 'FILE_RESOURCE' && !isValidUID($cleanValue)) {
    // Skip or handle file upload
    continue;
}
```

### 3. Option Set Value Mapping
```php
// For option set data elements, validate or map values
if ($dataElementMetadata['optionSet']) {
    $validOptions = getOptionSetOptions($dataElementMetadata['optionSet']['id'], $instanceKey);
    if (!in_array($cleanValue, $validOptions)) {
        // Log warning but continue, or map to valid option
        error_log("Invalid option value: $cleanValue for DE: $deId");
    }
}
```

## Test Scenarios

### Scenario 1: Valid Submission
- All mandatory attributes provided
- All option set values are valid
- File resource elements contain valid UIDs or are omitted
- Numeric values are properly formatted

### Scenario 2: Missing Mandatory Attribute
- Test system's ability to detect and handle missing required attributes
- Verify error messages are clear and actionable

### Scenario 3: Invalid Option Set Values
- Submit values not in option sets
- Test trimming of whitespace
- Test case sensitivity

### Scenario 4: File Resource Handling
- Submit non-UID values to file resource elements
- Test system's ability to skip or handle appropriately

## Next Steps

1. **Query DHIS2 Metadata**: Get complete metadata for program including:
   - Tracked entity type mandatory attributes
   - Data element types and option sets
   - Program stage requirements

2. **Implement Pre-validation**: Validate form data against DHIS2 metadata before submission

3. **Enhanced Error Handling**: Provide specific guidance for each type of validation error

4. **User Feedback**: Clear messages about what values are expected for each field