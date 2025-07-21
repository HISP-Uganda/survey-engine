class FacilitySearch {
    constructor() {
        this.searchInput = document.getElementById('facility-search');
        this.resultsContainer = document.getElementById('facility-results');
        this.facilityIdInput = document.getElementById('facility_id');
        this.pathDisplay = document.getElementById('path-display');
        
        if (!this.facilityIdInput) {
            this.facilityIdInput = document.createElement('input');
            this.facilityIdInput.type = 'hidden';
            this.facilityIdInput.id = 'facility_id';
            this.facilityIdInput.name = 'facility_id';
            document.querySelector('.facility-section').appendChild(this.facilityIdInput);
        }
        
        if (!this.pathDisplay) {
            this.pathDisplay = document.createElement('div');
            this.pathDisplay.id = 'path-display';
            this.pathDisplay.className = 'path-display';
            document.querySelector('.hierarchy-path').appendChild(this.pathDisplay);
        }
        
        this.init();
    }
    
    init() {
        this.setupEventListeners();
    }
    
    setupEventListeners() {
        this.searchInput.addEventListener('input', () => {
            const searchTerm = this.searchInput.value.trim();
            if (searchTerm.length >= 2) {
                this.handleSearch(searchTerm);
            } else {
                this.resultsContainer.style.display = 'none';
            }
        });
        
        document.addEventListener('click', (e) => {
            if (!this.searchInput.contains(e.target) && !this.resultsContainer.contains(e.target)) {
                this.resultsContainer.style.display = 'none';
            }
        });
    }
    
    async handleSearch(searchTerm) {
        try {
            const response = await fetch(`location.php?action=search_facilities&term=${encodeURIComponent(searchTerm)}`);
            if (!response.ok) throw new Error('Network error');
            
            const facilities = await response.json();
            this.displayResults(facilities);
        } catch (error) {
            console.error('Search error:', error);
            this.resultsContainer.innerHTML = '<div class="error">Error loading facilities</div>';
            this.resultsContainer.style.display = 'block';
        }
    }
    
    displayResults(facilities) {
        this.resultsContainer.innerHTML = '';
        
        if (!facilities || facilities.length === 0) {
            this.resultsContainer.innerHTML = '<div class="no-results">No facilities found</div>';
            this.resultsContainer.style.display = 'block';
            return;
        }
        
        facilities.forEach(facility => {
            const div = document.createElement('div');
            div.className = 'facility-item';
            div.textContent = facility.facility_name;
            div.addEventListener('click', () => this.selectFacility(facility));
            this.resultsContainer.appendChild(div);
        });
        
        this.resultsContainer.style.display = 'block';
    }
    
    async selectFacility(facility) {
        this.searchInput.value = facility.facility_name;
        this.facilityIdInput.value = facility.id;
        this.resultsContainer.style.display = 'none';
        
        try {
            const response = await fetch(`location.php?action=get_hierarchy&facility_id=${facility.id}`);
            if (!response.ok) throw new Error('Network error');
            
            const data = await response.json();
            this.displayHierarchy(data);
        } catch (error) {
            console.error('Hierarchy error:', error);
            this.pathDisplay.textContent = 'Error loading hierarchy';
        }
    }
    
    displayHierarchy(data) {
        if (!data || data.error) {
            this.pathDisplay.textContent = 'No hierarchy data';
            return;
        }
        
        let path = '';
        if (data.hierarchy && data.hierarchy.length > 0) {
            path = data.hierarchy.map(loc => loc.name).join(' → ');
            path += ' → ';
        }
        path += data.facility_name;
        
        this.pathDisplay.textContent = path;
    }
}

// Initialize when DOM loads
document.addEventListener('DOMContentLoaded', () => {
    new FacilitySearch();
    
    // Your other initialization code...
    fetch('../get_service_units.php')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('serviceUnit');
            if (select) {
                select.innerHTML = '<option value="">none selected</option>';
                data.forEach(unit => {
                    select.innerHTML += `<option value="${unit.id}">${unit.name}</option>`;
                });
            }
        })
        .catch(error => console.error('Service units error:', error));
    
    fetch('../get_ownership_options.php')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('ownership-options');
            if (container) {
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


    // Initialize date picker
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

    
    // Form validation
    window.validateForm = function() {
        const requiredFields = document.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!field.value) {
                alert(`Please fill out the required field: ${field.name || field.id}`);
                isValid = false;
            }
        });
        
        return isValid;
    };
    
    // Toggle demographics section
    window.toggleDemographics = function() {
        const section = document.getElementById("demographics-section");
        const arrow = document.querySelector(".dropdown .arrow");
        
        if (section && arrow) {
            if (section.classList.contains("hidden")) {
                section.classList.remove("hidden");
                arrow.innerHTML = "&#9650;";
            } else {
                section.classList.add("hidden");
                arrow.innerHTML = "&#9660;";
            }
        }
    };
    
    // Print form
    window.printForm = function() {
        const printContents = document.getElementById("form-content")?.innerHTML;
        if (printContents) {
            const originalContents = document.body.innerHTML;
            document.body.innerHTML = printContents;
            window.print();
            document.body.innerHTML = originalContents;
            location.reload();
        }
    };
    
    // Load all data
    loadServiceUnits();
    loadOwnershipOptions();
});