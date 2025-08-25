# DHIS2 Program Builder (pb.php)

## Overview

The DHIS2 Program Builder is a planned feature that addresses a specific use case where an existing survey has been created in the Survey Engine but no corresponding program exists in DHIS2. This feature would automatically create the required DHIS2 program structure based on the local survey definition.

## Use Case Scenario

### Problem Statement
- A survey has been designed and built using the Survey Engine's local survey builder
- The survey contains questions, validation rules, and structure
- **But**: No corresponding program exists in the target DHIS2 instance
- **Need**: Create a DHIS2 program automatically to accommodate the existing survey

### Current Workflow Gap
Currently, the system supports:
1. ✅ Import existing DHIS2 programs → Create surveys in Survey Engine
2. ❌ Export local surveys → Create DHIS2 programs (THIS IS THE GAP)

## Technical Requirements

### 1. Survey Analysis
- **Input**: Local survey ID from `survey` table
- **Process**: Analyze survey structure including:
  - Questions and their types
  - Validation rules
  - Skip logic/conditional logic
  - Option sets
  - Grouping/sections
  - Required vs optional fields

### 2. DHIS2 Program Type Detection
Based on survey characteristics, determine the appropriate DHIS2 program type:

#### **Event Program** (Recommended for most surveys)
- **When to use**: Single event, one-time data collection
- **Characteristics**: Simple surveys, feedback forms, assessments
- **Structure**: One program stage with all data elements

#### **Tracker Program** 
- **When to use**: Multi-stage processes, longitudinal data
- **Characteristics**: Surveys with multiple phases, follow-up required
- **Structure**: Multiple program stages, tracked entity attributes

#### **Dataset** (Aggregate)
- **When to use**: Routine reporting, aggregated data
- **Characteristics**: Periodic reports, facility-level data
- **Structure**: Data elements with periods and categories

### 3. DHIS2 Metadata Creation

#### A. Data Elements
```json
{
  "name": "Question Text",
  "shortName": "Q1_ShortName",
  "code": "SURVEY_Q1",
  "valueType": "TEXT|INTEGER|DATE|BOOLEAN|TRUE_ONLY",
  "domainType": "TRACKER|AGGREGATE",
  "aggregationType": "NONE|SUM|AVERAGE",
  "optionSet": "UID_if_multiple_choice"
}
```

#### B. Option Sets (for multiple choice questions)
```json
{
  "name": "Q1_Options",
  "code": "Q1_OPTIONS",
  "options": [
    {"name": "Option 1", "code": "OPT1"},
    {"name": "Option 2", "code": "OPT2"}
  ]
}
```

#### C. Program/Dataset Structure
**For Event Program:**
```json
{
  "name": "Survey Name - Event Program",
  "shortName": "Survey_Event",
  "programType": "WITHOUT_REGISTRATION",
  "programStages": [{
    "name": "Survey Data Entry",
    "programStageDataElements": [...]
  }]
}
```

**For Tracker Program:**
```json
{
  "name": "Survey Name - Tracker Program", 
  "shortName": "Survey_Tracker",
  "programType": "WITH_REGISTRATION",
  "trackedEntityType": "UID",
  "programTrackedEntityAttributes": [...],
  "programStages": [...]
}
```

### 4. Question Type Mapping

| Survey Question Type | DHIS2 Value Type | Additional Setup |
|---------------------|------------------|------------------|
| Text (short) | TEXT | maxLength limit |
| Text (long) | LONG_TEXT | - |
| Number | INTEGER/NUMBER | min/max validation |
| Decimal | NUMBER | decimal places |
| Date | DATE | - |
| Time | TIME | - |
| DateTime | DATETIME | - |
| Yes/No | BOOLEAN | - |
| Checkbox | TRUE_ONLY | - |
| Radio/Select | TEXT | Requires Option Set |
| Multi-select | TEXT | Requires Option Set |
| File Upload | FILE_RESOURCE | - |

### 5. Validation Rules Migration
- **Required fields** → DHIS2 Mandatory data elements
- **Min/Max values** → DHIS2 Validation rules
- **Pattern matching** → DHIS2 Regular expression validation
- **Conditional logic** → DHIS2 Program rules

### 6. Organization Unit Assignment
- **Default**: Assign to all org units where survey is intended to be used
- **Option**: Allow admin to select specific org unit levels or units
- **Consideration**: Respect DHIS2 org unit hierarchy and permissions

## Implementation Workflow

### Phase 1: Analysis & Planning
1. **Survey Structure Analysis**
   - Parse survey questions from `question` and `survey_question` tables
   - Identify question types and validation rules
   - Detect groupings and sections
   - Analyze skip logic patterns

2. **Program Type Recommendation**
   - Analyze survey complexity
   - Recommend optimal DHIS2 program type
   - Present options to user with explanations

### Phase 2: Metadata Preparation
1. **Generate DHIS2 Metadata JSON**
   - Create data elements for each question
   - Generate option sets for multiple choice questions
   - Build program/dataset structure
   - Create validation rules

2. **User Review & Customization**
   - Show preview of generated structure
   - Allow modifications to names, codes, types
   - Confirm organization unit assignments
   - Review and approve before creation

### Phase 3: DHIS2 Creation
1. **Metadata Import**
   - Post metadata to DHIS2 API in correct order:
     1. Option sets
     2. Data elements  
     3. Programs/Datasets
     4. Validation rules

2. **Error Handling**
   - Handle naming conflicts
   - Manage duplicate codes
   - Validate metadata structure
   - Provide detailed error reporting

### Phase 4: Integration Setup
1. **Update Survey Configuration**
   - Link survey to created program UID
   - Update survey type from 'local' to 'dhis2'
   - Configure field mappings
   - Set up organization unit relationships

2. **Testing & Validation**
   - Test submission flow
   - Validate data mapping
   - Confirm DHIS2 data visibility

## User Interface Requirements

### 1. Survey Selection
- List of local surveys (type = 'local')
- Survey preview showing structure
- Program type recommendation

### 2. Configuration Wizard
```
Step 1: Select Survey
Step 2: Choose Program Type (Event/Tracker/Dataset)
Step 3: Review Generated Structure
Step 4: Customize Names & Codes
Step 5: Select Organization Units
Step 6: Confirm & Create
```

### 3. Progress Tracking
- Real-time progress indicators
- Detailed logging of creation steps
- Error reporting with suggested fixes
- Success confirmation with next steps

## Technical Considerations

### 1. DHIS2 API Requirements
- Admin privileges in target DHIS2 instance
- Metadata import permissions
- Program/dataset creation rights

### 2. Naming Conventions
- **Programs**: `[Survey Name] - [Type]`
- **Data Elements**: `[Survey Name] - [Question Text]`  
- **Codes**: `SURVEY_[ID]_Q[NUMBER]`
- **Option Sets**: `[Survey Name]_Q[NUMBER]_OPTIONS`

### 3. Error Scenarios
- DHIS2 connection failures
- Insufficient permissions
- Naming conflicts
- Invalid metadata structure
- Partial creation failures

### 4. Rollback Strategy
- Track created UIDs for rollback
- Implement cleanup procedures
- Preserve original local survey
- Allow retry with modifications

## Future Enhancements

### 1. Batch Processing
- Create multiple programs from multiple surveys
- Template-based program creation
- Bulk organization unit assignment

### 2. Advanced Mapping
- Custom field mapping editor  
- Complex validation rule builder
- Advanced skip logic conversion

### 3. Synchronization
- Two-way sync between local and DHIS2
- Change detection and updates
- Version control for program modifications

## Database Schema Changes

### 1. New Tables
```sql
-- Track program creation jobs
CREATE TABLE dhis2_program_creation_jobs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  survey_id INT NOT NULL,
  job_status ENUM('pending', 'processing', 'completed', 'failed'),
  dhis2_program_uid VARCHAR(11),
  created_metadata JSON,
  error_log TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (survey_id) REFERENCES survey(id)
);
```

### 2. Enhanced Survey Table
```sql
-- Add program builder tracking
ALTER TABLE survey ADD COLUMN created_from_local BOOLEAN DEFAULT FALSE;
ALTER TABLE survey ADD COLUMN original_local_survey_id INT;
```

This feature would significantly enhance the Survey Engine's flexibility by allowing users to prototype surveys locally and then seamlessly deploy them to DHIS2 infrastructure.