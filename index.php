<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FormBase Survey Tool - Professional Survey Management</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        :root {
            --primary: #0F172A;
            --primary-light: #1E293B;
            --primary-dark: #020617;
            --secondary: #3B82F6;
            --secondary-light: #60A5FA;
            --secondary-dark: #1D4ED8;
            --accent: #06B6D4;
            --accent-light: #22D3EE;
            --success: #10B981;
            --warning: #F59E0B;
            --background: #F8FAFC;
            --surface: #FFFFFF;
            --surface-secondary: #F1F5F9;
            --text-primary: #0F172A;
            --text-secondary: #475569;
            --text-muted: #64748B;
            --text-light: #94A3B8;
            --border: #E2E8F0;
            --border-light: #F1F5F9;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: var(--text-primary);
            background: var(--background);
            font-size: 16px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Header Styles */
        header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(20px);
            background: rgba(248, 250, 252, 0.95);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            min-height: 80px;
        }

        .logo {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo::before {
            content: '📋';
            font-size: 32px;
            filter: grayscale(0.3);
        }

        .nav-buttons {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            font-family: inherit;
            line-height: 1.5;
            min-height: 44px;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--secondary);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover {
            background: var(--secondary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--surface-secondary);
            border-color: var(--secondary);
            color: var(--secondary);
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 120px 0;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="a" cx="50%" cy="50%"><stop offset="0%" stop-color="rgba(59,130,246,0.1)"/><stop offset="100%" stop-color="transparent"/></radialGradient></defs><circle cx="200" cy="200" r="300" fill="url(%23a)"/><circle cx="800" cy="800" r="400" fill="url(%23a)"/></svg>');
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 800px;
            margin: 0 auto;
        }

        .hero h1 {
            font-size: 56px;
            font-weight: 700;
            margin-bottom: 24px;
            line-height: 1.1;
            letter-spacing: -1px;
            background: linear-gradient(135deg, #ffffff 0%, #e2e8f0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero p {
            font-size: 20px;
            margin-bottom: 40px;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
            font-weight: 400;
        }

        .hero-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 48px;
        }

        .btn-large {
            padding: 16px 32px;
            font-size: 16px;
            font-weight: 500;
            min-height: 52px;
        }

        .btn-white {
            background: white;
            color: var(--primary);
            box-shadow: var(--shadow-lg);
        }

        .btn-white:hover {
            background: var(--surface-secondary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-xl);
        }

        /* Features Section */
        .features {
            padding: 120px 0;
            background: var(--surface);
        }

        .features-header {
            text-align: center;
            margin-bottom: 80px;
        }

        .features h2 {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--primary);
            letter-spacing: -0.5px;
        }

        .features-subtitle {
            font-size: 20px;
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 32px;
        }

        .feature-card {
            background: var(--surface);
            padding: 40px 32px;
            border-radius: 16px;
            border: 1px solid var(--border);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary), var(--accent));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .feature-card:hover::before {
            transform: scaleX(1);
        }

        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
            border-color: var(--secondary-light);
        }

        .feature-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--secondary), var(--accent));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            font-size: 24px;
            box-shadow: var(--shadow-md);
        }

        .feature-card h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 12px;
            color: var(--primary);
        }

        .feature-card p {
            color: var(--text-secondary);
            line-height: 1.6;
            font-size: 15px;
        }

        /* Stats Section */
        .stats {
            background: var(--surface-secondary);
            padding: 80px 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 48px;
            text-align: center;
        }

        .stat-item h3 {
            font-size: 36px;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 8px;
        }

        .stat-item p {
            color: var(--text-secondary);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        /* CTA Section */
        .cta-section {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            padding: 100px 0;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><pattern id="grid" width="50" height="50" patternUnits="userSpaceOnUse"><path d="M 50 0 L 0 0 0 50" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="1"/></pattern></defs><rect width="100%" height="100%" fill="url(%23grid)"/></svg>');
        }

        .cta-content {
            position: relative;
            z-index: 2;
        }

        .cta-section h2 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }

        .cta-section p {
            font-size: 18px;
            margin-bottom: 40px;
            opacity: 0.9;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .cta-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* Footer */
        footer {
            background: var(--primary-dark);
            color: rgba(255, 255, 255, 0.8);
            padding: 60px 0 20px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-section h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: white;
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section ul li {
            margin-bottom: 8px;
        }

        .footer-section ul li a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: color 0.2s;
            font-size: 14px;
        }

        .footer-section ul li a:hover {
            color: var(--secondary-light);
        }

        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 20px;
            text-align: center;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.6);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 40px;
            }
            
            .hero p {
                font-size: 18px;
            }
            
            .features h2 {
                font-size: 32px;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
            }
            
            .feature-card {
                padding: 32px 24px;
            }
            
            .cta-section h2 {
                font-size: 28px;
            }
            
            .hero-buttons,
            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-large {
                width: 100%;
                max-width: 280px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0 16px;
            }
            
            .hero {
                padding: 80px 0;
            }
            
            .hero h1 {
                font-size: 32px;
            }
            
            .features {
                padding: 80px 0;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }
            
            .feature-card {
                padding: 24px 20px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">FormBase</div>
                <nav class="nav-buttons">
                    <a href="fbs/admin/login.php" class="btn btn-secondary">Sign In</a>
                    <!-- <a href="fbs/admin/register" class="btn btn-primary">Get Started</a> -->
                </nav>
            </div>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="container">
                <div class="hero-content">
                    <h1>Professional Survey Management</h1>
                    <p>Create, deploy, and analyze surveys with enterprise-grade features, seamless DHIS2 integration, and advanced analytics capabilities.</p>
                    <div class="hero-buttons">
                        <!-- <a href="fbs/admin/register" class="btn btn-white btn-large">Start Free Trial</a> -->
                        <a href="#features" class="btn btn-secondary btn-large">Learn More</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="stats">
            <div class="container">
                <div class="stats-grid">
                    <div class="stat-item">
                        <h3>10K+</h3>
                        <p>Active Users</p>
                    </div>
                    <div class="stat-item">
                        <h3>50M+</h3>
                        <p>Surveys Completed</p>
                    </div>
                    <div class="stat-item">
                        <h3>99.9%</h3>
                        <p>Uptime</p>
                    </div>
                    <div class="stat-item">
                        <h3>150+</h3>
                        <p>Countries</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="features" id="features">
            <div class="container">
                <div class="features-header">
                    <h2>Enterprise-Grade Features</h2>
                    <p class="features-subtitle">Everything you need to create, deploy, and analyze surveys at scale with professional-grade tools and integrations.</p>
                </div>
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">🌐</div>
                        <h3>Multi-Language Support</h3>
                        <p>Create surveys in multiple languages with built-in translation management and localization tools to reach global audiences effectively.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">⚙️</div>
                        <h3>Advanced Question Types</h3>
                        <p>Design sophisticated surveys with conditional logic, validation rules, and custom question types tailored to your specific requirements.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">📊</div>
                        <h3>DHIS2 Integration</h3>
                        <p>Seamlessly sync survey data with DHIS2 instances for comprehensive health information management and analytics workflows.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">📱</div>
                        <h3>Mobile-First Design</h3>
                        <p>Optimized for mobile devices with QR code deployment, offline capabilities, and responsive design for field data collection.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">🔧</div>
                        <h3>Visual Survey Builder</h3>
                        <p>Intuitive drag-and-drop interface with real-time preview, branching logic, and advanced customization options for complex surveys.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">📈</div>
                        <h3>Real-time Analytics</h3>
                        <p>Monitor responses in real-time with comprehensive dashboards, automated reporting, and advanced data visualization tools.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="cta-section">
            <div class="container">
                <div class="cta-content">
                    <h2>Ready to Transform Your Data Collection?</h2>
                    <p>Join thousands of organizations worldwide who trust FormBase for their survey management needs.</p>
                    <div class="cta-buttons">
                        <!-- <a href="fbs/admin/register" class="btn btn-white btn-large">Start Free Trial</a> -->
                        <a href="fbs/admin/login" class="btn btn-secondary btn-large">Sign In</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>Product</h4>
                    <ul>
                        <li><a href="#">Features</a></li>
                        <li><a href="#">Pricing</a></li>
                        <li><a href="#">Integrations</a></li>
                        <li><a href="#">API</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Resources</h4>
                    <ul>
                        <li><a href="#">Documentation</a></li>
                        <li><a href="#">Help Center</a></li>
                        <li><a href="#">Blog</a></li>
                        <li><a href="#">Community</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Company</h4>
                    <ul>
                        <li><a href="#">About</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Contact</a></li>
                        <li><a href="#">Privacy</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Support</h4>
                    <ul>
                        <li><a href="#">Help Center</a></li>
                        <li><a href="#">Status</a></li>
                        <li><a href="#">Security</a></li>
                        <li><a href="#">Terms</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 FormBase Survey Tool. All rights reserved. Empowering professional data collection worldwide.</p>
            </div>
        </div>
    </footer>
</body>
</html>