<!-- <div class="fixed-plugin">
    <a class="fixed-plugin-button text-dark position-fixed px-3 py-2" id="darkModeToggle">
        <i class="fas fa-moon py-2"></i>
    </a>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const darkModeToggle = document.getElementById('darkModeToggle');
    const icon = darkModeToggle.querySelector('i');
    
    // Check for saved mode or prefer-color-scheme
    const savedMode = localStorage.getItem('darkMode');
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    // Initialize mode
    if (savedMode === 'dark' || (savedMode === null && systemPrefersDark)) {
        enableDarkMode();
    }
    
    // Toggle functionality
    darkModeToggle.addEventListener('click', function() {
        if (document.body.classList.contains('dark-mode')) {
            disableDarkMode();
        } else {
            enableDarkMode();
        }
    });
    
    // Dark mode functions
    function enableDarkMode() {
        document.body.classList.add('dark-mode');
        document.body.classList.remove('bg-gray-100');
        document.body.classList.add('bg-dark');
        icon.classList.remove('fa-moon');
        icon.classList.add('fa-sun');
        localStorage.setItem('darkMode', 'dark');
        
        // Update Argon components if they exist
        if (typeof Argon !== 'undefined') {
            document.querySelectorAll('.card, .navbar').forEach(el => {
                el.classList.add('bg-dark');
                el.classList.remove('bg-white');
            });
        }
    }
    
    function disableDarkMode() {
        document.body.classList.remove('dark-mode');
        document.body.classList.add('bg-gray-100');
        document.body.classList.remove('bg-dark');
        icon.classList.remove('fa-sun');
        icon.classList.add('fa-moon');
        localStorage.setItem('darkMode', 'light');
        
        // Update Argon components if they exist
        if (typeof Argon !== 'undefined') {
            document.querySelectorAll('.card, .navbar').forEach(el => {
                el.classList.remove('bg-dark');
                el.classList.add('bg-white');
            });
        }
    }
    
    // Watch for system preference changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (localStorage.getItem('darkMode') === null) {
            e.matches ? enableDarkMode() : disableDarkMode();
        }
    });
});
</script>

<style>
    /* Dark mode styles */
    body.dark-mode {
        --bg-color: #1a2035;
        --text-color: #f8f9fa;
        --card-bg: #222b45;
    }
    
    body.dark-mode .card {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: rgba(255, 255, 255, 0.1);
    }
    
    body.dark-mode .navbar {
        background-color: var(--card-bg) !important;
        color: var(--text-color);
    }
    
    body.dark-mode .table {
        color: var(--text-color);
    }
    
    body.dark-mode .fixed-plugin-button {
        color: var(--text-color) !important;
    }
    
    /* Smooth transitions */
    body, .card, .navbar {
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    
    /* Fixed plugin button styling */
    .fixed-plugin {
        position: fixed;
        top: 120px;
        right: 0;
        width: auto;
        z-index: 1030;
    }
    
    .fixed-plugin-button {
        background: rgba(255, 255, 255, 0.8);
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    }
    
    .fixed-plugin-button:hover {
        transform: translateX(-5px);
        background: rgba(255, 255, 255, 0.9);
    }
    
    body.dark-mode .fixed-plugin-button {
        background: rgba(0, 0, 0, 0.2);
    }
    
    body.dark-mode .fixed-plugin-button:hover {
        background: rgba(0, 0, 0, 0.3);
    }
</style> -->