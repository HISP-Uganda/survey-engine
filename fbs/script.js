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

// Service units and ownership options are now loaded directly within survey pages
// These fetch calls have been removed as the referenced files no longer exist

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

// Translation functionality has been moved to translations.js with proper error handling