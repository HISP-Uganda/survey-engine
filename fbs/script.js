class FacilitySearch {
    constructor() {
        this.searchInput = document.getElementById('facility-search');
        this.resultsContainer = document.getElementById('facility-results');
        this.facilityIdInput = document.getElementById('facility_id');
        this.pathDisplay = document.getElementById('path-display');
        this.hierarchyDataInput = document.getElementById('hierarchy_data');
        this.debounceTimer = null;
        
        this.init();
    }
    
    init() {
        this.setupEventListeners();
    }
    
    setupEventListeners() {
        // Search input with debounce
        this.searchInput.addEventListener('input', () => {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                this.handleSearch();
            }, 300);
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!this.searchInput.contains(e.target) && !this.resultsContainer.contains(e.target)) {
                this.resultsContainer.style.display = 'none';
            }
        });
    }
    
    async handleSearch() {
        const searchTerm = this.searchInput.value.trim();
        
        if (searchTerm.length < 2) {
            this.resultsContainer.style.display = 'none';
            return;
        }
        
        try {
            const facilities = await this.fetchFacilities(searchTerm);
            this.displayResults(facilities);
        } catch (error) {
            console.error('Search error:', error);
            this.showError('Error searching facilities');
        }
    }
    
    async fetchFacilities(searchTerm) {
        const response = await fetch(`../api/location.php?action=search_facilities&term=${encodeURIComponent(searchTerm)}`);
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        return data;
    }
    
    displayResults(facilities) {
        this.resultsContainer.innerHTML = '';
        
        if (facilities.length === 0) {
            this.resultsContainer.innerHTML = '<div class="no-results">No facilities found</div>';
            this.resultsContainer.style.display = 'block';
            return;
        }
        
        facilities.forEach(facility => {
            const item = document.createElement('div');
            item.className = 'facility-item';
            item.textContent = facility.facility_name;
            
            item.addEventListener('click', () => {
                this.selectFacility(facility);
            });
            
            this.resultsContainer.appendChild(item);
        });
        
        this.resultsContainer.style.display = 'block';
    }
    
    async selectFacility(facility) {
        this.searchInput.value = facility.facility_name;
        this.facilityIdInput.value = facility.id;
        this.resultsContainer.style.display = 'none';
        
        try {
            const hierarchy = await this.fetchHierarchy(facility.id);
            this.updateHierarchyDisplay(hierarchy);
        } catch (error) {
            console.error('Error loading hierarchy:', error);
            this.showError('Error loading location data');
        }
    }
    
    async fetchHierarchy(facilityId) {
        const response = await fetch(`admin/location.php?action=get_hierarchy&facility_id=${facilityId}`);
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        return data;
    }
    
    updateHierarchyDisplay(hierarchy) {
        if (!hierarchy || !hierarchy.hierarchy_path) {
            this.pathDisplay.textContent = 'No hierarchy data available';
            this.hierarchyDataInput.value = '';
            return;
        }
        
        // Display full path including facility name
        const fullPath = hierarchy.hierarchy_path ? 
                         `${hierarchy.hierarchy_path} → ${hierarchy.facility_name}` : 
                         hierarchy.facility_name;
        
        this.pathDisplay.textContent = fullPath;
        this.hierarchyDataInput.value = JSON.stringify(hierarchy);
    }
    
    showError(message) {
        this.resultsContainer.innerHTML = `<div class="error-message">${message}</div>`;
        this.resultsContainer.style.display = 'block';
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new FacilitySearch();
});

// Fetch service units on page load
document.addEventListener('DOMContentLoaded', function () {
    fetch('get_service_units.php')
        .then(response => response.json())
        .then(data => {
            const serviceUnitSelect = document.getElementById('serviceUnit');
            serviceUnitSelect.innerHTML = '<option value="">none selected</option>'; // Reset options
            data.forEach(unit => {
                serviceUnitSelect.innerHTML += `<option value="${unit.id}">${unit.name}</option>`;
            });
        })
        .catch(error => console.error('Error fetching service units:', error));
});



// Fetch ownership options on page load
document.addEventListener('DOMContentLoaded', function () {
    fetch('get_ownership_options.php')
        .then(response => response.json())
        .then(data => {
            const ownershipOptionsDiv = document.getElementById('ownership-options');
            data.forEach(option => {
                ownershipOptionsDiv.innerHTML += `
                    <label class="radio-option">
                        <input type="radio" name="ownership" value="${option.id}" class="radio" data-translate="${option.name.toLowerCase()}"/>
                        <span>${option.name}</span>
                    </label>
                `;
            });
        })
        .catch(error => console.error('Error fetching ownership options:', error));
});

// Form validation
function validateForm() {
  const facility = document.getElementById('facility').value;
  const serviceUnit = document.getElementById('serviceUnit').value;
  if (!facility || !serviceUnit) {
      alert('Please select a facility and service unit.');
      return false;
  }
  return true;
}

function toggleDemographics() {
    var section = document.getElementById("demographics-section");
    var arrow = document.querySelector(".dropdown .arrow");
  
    if (section.classList.contains("hidden")) {
        section.classList.remove("hidden");
        section.classList.add("active");
        arrow.innerHTML = "&#9650;"; // Up Arrow
    } else {
        section.classList.add("hidden");
        section.classList.remove("active");
        arrow.innerHTML = "&#9660;"; // Down Arrow
    }
  }
  
  function printForm() {
    // Save the current page contents
    var originalContents = document.body.innerHTML;
  
    // Get the form contents
    var printContents = document.getElementById("form-content").innerHTML;
  
    // Replace body content with only the form
    document.body.innerHTML = printContents;
  
    // Print the form
    window.print();
  
    // Restore original page content
    document.body.innerHTML = originalContents;
    location.reload(); // Reload page to restore event listeners
  }
  function validateForm() {
    const requiredFields = document.querySelectorAll('[required]');
    for (const field of requiredFields) {
        if (!field.value) {
            alert(`Please fill out the required field: ${field.name}`);
            return false;
        }
    }
    return true;
}

document.getElementById('languageSelect').addEventListener('change', function () {
    const selectedLanguage = this.value;

    // Fetch translations for the selected language
    fetch(`get_translations.php?language=${selectedLanguage}`)
        .then(response => response.json())
        .then(translations => {
            // Update question labels
            document.querySelectorAll('.question-label').forEach(label => {
                const questionId = label.dataset.questionId;
                if (translations.questions[questionId]) {
                    label.textContent = translations.questions[questionId];
                }
            });

            // Update option labels
            document.querySelectorAll('.option-label').forEach(label => {
                const optionId = label.dataset.optionId;
                if (translations.options[optionId]) {
                    label.textContent = translations.options[optionId];
                }
            });

            // Update default text (e.g., headings, buttons)
            document.querySelectorAll('[data-translate]').forEach(element => {
                const key = element.dataset.translate;
                if (translations.defaultText[key]) {
                    element.textContent = translations.defaultText[key];
                }
            });
        });
});