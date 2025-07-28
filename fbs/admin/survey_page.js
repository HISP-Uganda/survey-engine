// Remove the entire FacilitySearch class and its initialization.
// The location search logic is now managed directly within survey_page.php.

// Your other initialization code for service units and ownership options
// (These are currently being fetched via direct fetch calls, not through FacilitySearch)
document.addEventListener('DOMContentLoaded', () => {

    // Removed the "new FacilitySearch();" line as it's no longer needed.

    // Fetch service units (if they are still needed and their HTML is in survey_page.php)
    fetch('../get_service_units.php')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('serviceUnit');
            if (select) { // Ensure element exists
                select.innerHTML = '<option value="">none selected</option>';
                data.forEach(unit => {
                    select.innerHTML += `<option value="${unit.id}">${unit.name}</option>`;
                });
            }
        })
        .catch(error => console.error('Service units error:', error));

    // Fetch ownership options (if they are still needed and their HTML is in survey_page.php)
    fetch('../get_ownership_options.php')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('ownership-options');
            if (container) { // Ensure element exists
                container.innerHTML = ''; // Clear existing options before adding
                data.forEach(option => {
                    container.innerHTML += `
                        <label class="radio-option">
                            <input type="radio" name="ownership" value="${option.id}"
                                   class="radio" data-translate="${option.name.toLowerCase()}"/>
                            <span>${option.name}</span>
                        </label>
                    `;
                });
            }
        })
        .catch(error => console.error('Ownership options error:', error));


    // Initialize date picker (if still needed)
    const dateInputs = document.querySelectorAll('.date-picker');
    dateInputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.type = 'date';
        });
        input.addEventListener('blur', function() {
            if (!this.value) {
                this.type = 'text'; // Reset to text if no value
            }
        });
    });

    // Removed the old window.validateForm here. The validation is now handled in survey_page.php's script block.
    // Removed window.toggleDemographics as its section is not in survey_page.php HTML.
    // Removed window.printForm as its section is not in survey_page.php HTML.
    // Removed old loadServiceUnits() and loadOwnershipOptions() calls, as the fetch calls above handle it directly.

});

// Important Note: The main pagination logic, location search behavior,
// and form submission validation are now managed directly within the <script>
// tags of survey_page.php itself. This file (survey_page.js) should only
// contain supplementary, non-conflicting JavaScript.