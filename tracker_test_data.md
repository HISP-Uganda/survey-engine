# Tracker Form Test Data - District School Health Programme

This file contains dummy test data for testing the DHIS2 tracker program form submission.

## Basic Information Fields

### DEO's Name (TEXT)
```
John Mwangi Kamau
```

### DEO's Contact Unique (PHONE_NUMBER)
```
+254712345678
```

### Contact of School health focal person (PHONE_NUMBER)
```
+254723456789
```

## Program Stages Test Data

### Stage 1: District School Health Programme (Repeatable)

#### Entry 1
- **Does the District have a strategy/mechanism in place to support School Health Programmes?** 
  - `true` (YES)

- **Number of benefiting Primary schools (Government)**
  - `45`

- **Number of benefiting Primary schools (Private)**
  - `12`

#### Entry 2 (Second repetition)
- **Does the District have a strategy/mechanism in place to support School Health Programmes?**
  - `false` (NO)

- **Number of benefiting Primary schools (Government)**
  - `0`

- **Number of benefiting Primary schools (Private)**
  - `0`

### Stage 2: School Health Partner (Repeatable)

#### Entry 1
- **Has the district profiled the partners in the district supporting School Health in the district?**
  - `true` (YES)

- **Name of the organization**
  - `World Vision Kenya`

- **Names of schools supported by the organization (FILE_RESOURCE)**
  - `school_list_worldvision.pdf` (dummy filename)

- **Years of Support**
  - `2018-2024`

#### Entry 2 (Second repetition)
- **Has the district profiled the partners in the district supporting School Health in the district?**
  - `true` (YES)

- **Name of the organization**
  - `Save the Children International`

- **Names of schools supported by the organization (FILE_RESOURCE)**
  - `schools_savechildren_2024.xlsx` (dummy filename)

- **Years of Support**
  - `2020-2025`

#### Entry 3 (Third repetition)
- **Has the district profiled the partners in the district supporting School Health in the district?**
  - `false` (NO)

- **Name of the organization**
  - `Kenya Red Cross`

- **Names of schools supported by the organization (FILE_RESOURCE)**
  - `redcross_schools.docx` (dummy filename)

- **Years of Support**
  - `2019-2023`

## JSON Format for API Testing

```json
{
  "deoName": "John Mwangi Kamau",
  "deoContact": "+254712345678",
  "schoolHealthContact": "+254723456789",
  "districtProgramme": [
    {
      "hasStrategy": true,
      "governmentSchools": 45,
      "privateSchools": 12
    },
    {
      "hasStrategy": false,
      "governmentSchools": 0,
      "privateSchools": 0
    }
  ],
  "healthPartners": [
    {
      "hasProfiled": true,
      "organizationName": "World Vision Kenya",
      "supportedSchoolsFile": "school_list_worldvision.pdf",
      "yearsOfSupport": "2018-2024"
    },
    {
      "hasProfiled": true,
      "organizationName": "Save the Children International",
      "supportedSchoolsFile": "schools_savechildren_2024.xlsx",
      "yearsOfSupport": "2020-2025"
    },
    {
      "hasProfiled": false,
      "organizationName": "Kenya Red Cross",
      "supportedSchoolsFile": "redcross_schools.docx",
      "yearsOfSupport": "2019-2023"
    }
  ]
}
```

## Form Field Testing Values

Use these exact values when filling out the tracker form:

### Basic Fields
| Field | Value |
|-------|-------|
| DEO's Name | John Mwangi Kamau |
| DEO's Contact | +254712345678 |
| School Health Focal Person Contact | +254723456789 |

### District Programme Stage (Add 2 entries)
| Entry | Strategy/Mechanism | Gov Schools | Private Schools |
|-------|-------------------|-------------|-----------------|
| 1 | Yes | 45 | 12 |
| 2 | No | 0 | 0 |

### Health Partner Stage (Add 3 entries)
| Entry | Profiled | Organization | File | Years |
|-------|----------|-------------|------|-------|
| 1 | Yes | World Vision Kenya | school_list_worldvision.pdf | 2018-2024 |
| 2 | Yes | Save the Children International | schools_savechildren_2024.xlsx | 2020-2025 |
| 3 | No | Kenya Red Cross | redcross_schools.docx | 2019-2023 |

## Test Scenarios

### Scenario 1: Complete Submission
Fill all fields with the data above and submit successfully.

### Scenario 2: Partial Submission
Fill only the basic information and first entry of each repeatable stage.

### Scenario 3: Validation Testing
- Leave required fields empty to test validation
- Enter invalid phone numbers (without + or country code)
- Try submitting with only basic info (no repeatable stages)

### Scenario 4: File Upload Testing
If file upload is implemented:
- Test with small text files (.txt, .pdf, .docx, .xlsx)
- Test file size limits
- Test invalid file types

## Expected Behavior

1. Form should validate all required fields
2. Phone numbers should be formatted correctly
3. Repeatable stages should allow adding/removing entries
4. File uploads should work (if implemented)
5. Successful submission should redirect to success page
6. Data should be stored in local database and optionally sent to DHIS2