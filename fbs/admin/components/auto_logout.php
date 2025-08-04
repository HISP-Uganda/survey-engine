<?php
// Auto-logout component for admin pages
// Include this in all admin pages that require authentication
?>

<style>
/* Auto-logout modal styles */
.auto-logout-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 9999;
    backdrop-filter: blur(5px);
}

.auto-logout-content {
    background: white;
    padding: 30px;
    border-radius: 12px;
    text-align: center;
    max-width: 400px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    animation: modalFadeIn 0.3s ease-out;
}

@keyframes modalFadeIn {
    from {
        opacity: 0;
        transform: scale(0.9) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.auto-logout-content h4 {
    color: #e74c3c;
    margin-bottom: 15px;
    font-size: 18px;
}

.auto-logout-content p {
    color: #666;
    margin-bottom: 20px;
    line-height: 1.6;
}

.auto-logout-timer {
    font-size: 24px;
    font-weight: bold;
    color: #e74c3c;
    margin: 15px 0;
}

.auto-logout-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.btn-extend-session {
    background: #27ae60;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    transition: background 0.2s;
}

.btn-extend-session:hover {
    background: #219a52;
}

.btn-logout-now {
    background: #e74c3c;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    transition: background 0.2s;
}

.btn-logout-now:hover {
    background: #c0392b;
}

/* Session timeout indicator */
.session-timeout-indicator {
    position: fixed;
    top: 20px;
    right: 20px;
    background: rgba(231, 76, 60, 0.9);
    color: white;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    z-index: 1000;
    display: none;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 0.7; }
    50% { opacity: 1; }
    100% { opacity: 0.7; }
}
</style>

<!-- Auto-logout warning modal -->
<div id="autoLogoutModal" class="auto-logout-modal">
    <div class="auto-logout-content">
        <h4>⚠️ Session Expiring Soon</h4>
        <p>Your session will expire in:</p>
        <div id="autoLogoutTimer" class="auto-logout-timer">60</div>
        <p>Do you want to extend your session?</p>
        <div class="auto-logout-buttons">
            <button id="extendSession" class="btn-extend-session">Extend Session</button>
            <button id="logoutNow" class="btn-logout-now">Logout Now</button>
        </div>
    </div>
</div>

<!-- Session timeout indicator -->
<div id="sessionTimeoutIndicator" class="session-timeout-indicator">
    Session expires in: <span id="sessionTimeRemaining">30:00</span>
</div>

<script>
class AutoLogout {
    constructor() {
        this.timeoutDuration = 30 * 60 * 1000; // 30 minutes in milliseconds
        this.warningTime = 60 * 1000; // Show warning 1 minute before timeout
        this.checkInterval = 30 * 1000; // Check session every 30 seconds
        this.lastActivity = Date.now();
        this.timeoutTimer = null;
        this.warningTimer = null;
        this.sessionCheckTimer = null;
        this.warningShown = false;
        
        this.init();
    }
    
    init() {
        // Track user activity
        this.trackActivity();
        
        // Start session monitoring
        this.startSessionMonitoring();
        
        // Set up modal event listeners
        this.setupModalEvents();
        
        // Start the main timeout timer
        this.resetTimeout();
        
        console.log('Auto-logout system initialized - 30 minute timeout');
    }
    
    trackActivity() {
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        
        events.forEach(event => {
            document.addEventListener(event, () => {
                this.resetActivity();
            }, { passive: true });
        });
    }
    
    resetActivity() {
        this.lastActivity = Date.now();
        
        // If warning is shown, hide it
        if (this.warningShown) {
            this.hideWarning();
        }
        
        // Reset timeout
        this.resetTimeout();
    }
    
    resetTimeout() {
        // Clear existing timers
        if (this.timeoutTimer) clearTimeout(this.timeoutTimer);
        if (this.warningTimer) clearTimeout(this.warningTimer);
        
        this.warningShown = false;
        
        // Set warning timer (show warning 1 minute before timeout)
        this.warningTimer = setTimeout(() => {
            this.showWarning();
        }, this.timeoutDuration - this.warningTime);
        
        // Set main timeout timer
        this.timeoutTimer = setTimeout(() => {
            this.performLogout();
        }, this.timeoutDuration);
    }
    
    startSessionMonitoring() {
        // Check session status with server every 30 seconds
        this.sessionCheckTimer = setInterval(() => {
            this.checkSessionStatus();
        }, this.checkInterval);
        
        // Initial check
        this.checkSessionStatus();
    }
    
    async checkSessionStatus() {
        try {
            const response = await fetch('session_check.php', {
                method: 'GET',
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (data.status === 'timeout' || data.status === 'not_logged_in') {
                console.log('Session expired on server');
                this.performLogout();
                return;
            }
            
            if (data.status === 'active') {
                // Update session timeout indicator
                this.updateTimeoutIndicator(data.remaining_time);
                
                // If remaining time is less than warning time, show warning
                if (data.remaining_time <= 60 && !this.warningShown) {
                    this.showWarning();
                }
            }
            
        } catch (error) {
            console.error('Session check failed:', error);
            // If server is unreachable, don't auto-logout
        }
    }
    
    updateTimeoutIndicator(remainingSeconds) {
        const indicator = document.getElementById('sessionTimeoutIndicator');
        const timeSpan = document.getElementById('sessionTimeRemaining');
        
        if (remainingSeconds <= 300) { // Show indicator when 5 minutes or less remain
            const minutes = Math.floor(remainingSeconds / 60);
            const seconds = remainingSeconds % 60;
            timeSpan.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            indicator.style.display = 'block';
        } else {
            indicator.style.display = 'none';
        }
    }
    
    showWarning() {
        this.warningShown = true;
        const modal = document.getElementById('autoLogoutModal');
        modal.style.display = 'flex';
        
        // Start countdown timer
        let countdown = 60;
        const timerElement = document.getElementById('autoLogoutTimer');
        
        const countdownInterval = setInterval(() => {
            countdown--;
            timerElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(countdownInterval);
                this.performLogout();
            }
        }, 1000);
        
        // Store countdown interval for cleanup
        this.countdownInterval = countdownInterval;
    }
    
    hideWarning() {
        this.warningShown = false;
        const modal = document.getElementById('autoLogoutModal');
        modal.style.display = 'none';
        
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
        }
    }
    
    extendSession() {
        console.log('Session extended by user');
        this.resetActivity();
        this.hideWarning();
        
        // Notify server to update session timestamp
        fetch('session_check.php', {
            method: 'GET',
            credentials: 'same-origin'
        }).catch(error => {
            console.error('Failed to extend session:', error);
        });
    }
    
    performLogout() {
        console.log('Auto-logout triggered');
        
        // Clear all timers
        if (this.timeoutTimer) clearTimeout(this.timeoutTimer);
        if (this.warningTimer) clearTimeout(this.warningTimer);
        if (this.sessionCheckTimer) clearInterval(this.sessionCheckTimer);
        if (this.countdownInterval) clearInterval(this.countdownInterval);
        
        // Show loading message
        document.body.innerHTML = `
            <div style="display: flex; justify-content: center; align-items: center; height: 100vh; background: #f8f9fa;">
                <div style="text-align: center; padding: 40px; background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3 style="color: #e74c3c; margin-bottom: 15px;">Session Expired</h3>
                    <p style="color: #666; margin-bottom: 20px;">You have been logged out due to inactivity.</p>
                    <p style="color: #999; font-size: 14px;">Redirecting to login page...</p>
                </div>
            </div>
        `;
        
        // Redirect to logout page after a brief delay
        setTimeout(() => {
            window.location.href = 'logout.php';
        }, 2000);
    }
    
    setupModalEvents() {
        document.getElementById('extendSession').addEventListener('click', () => {
            this.extendSession();
        });
        
        document.getElementById('logoutNow').addEventListener('click', () => {
            this.performLogout();
        });
        
        // Prevent modal from closing when clicking on it
        document.getElementById('autoLogoutModal').addEventListener('click', (e) => {
            if (e.target.id === 'autoLogoutModal') {
                // Don't close modal when clicking outside
                e.preventDefault();
            }
        });
    }
    
    destroy() {
        // Cleanup method
        if (this.timeoutTimer) clearTimeout(this.timeoutTimer);
        if (this.warningTimer) clearTimeout(this.warningTimer);
        if (this.sessionCheckTimer) clearInterval(this.sessionCheckTimer);
        if (this.countdownInterval) clearInterval(this.countdownInterval);
    }
}

// Initialize auto-logout when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if user is logged in (check for session indicator)
    <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
    window.autoLogout = new AutoLogout();
    <?php endif; ?>
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (window.autoLogout) {
        window.autoLogout.destroy();
    }
});
</script>