<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Base Survey Tool</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #1a202c;
            background: #0a0a0a;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Animated background */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
        }

        .bg-animation::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Floating particles */
        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
            animation: float 20s infinite linear;
        }

        @keyframes float {
            0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-100vh) rotate(360deg); opacity: 0; }
        }

        header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .logo {
            font-size: 1.8rem;
            font-weight: 800;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo::before {
            content: '📊';
            font-size: 1.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4299e1, #667eea);
            color: white;
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.6);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 255, 255, 0.2);
        }

        .hero {
            padding: 120px 0 80px;
            text-align: center;
            color: white;
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .hero-content {
            animation: fadeInUp 1s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero h1 {
            font-size: 4rem;
            margin-bottom: 20px;
            font-weight: 800;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            background: linear-gradient(135deg, #ffffff, #f0f8ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: titleGlow 3s ease-in-out infinite alternate;
        }

        @keyframes titleGlow {
            0% { text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3); }
            100% { text-shadow: 0 4px 20px rgba(255, 255, 255, 0.3); }
        }

        .hero p {
            font-size: 1.4rem;
            margin-bottom: 40px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
            opacity: 0.9;
            animation: fadeInUp 1s ease-out 0.2s both;
        }

        .hero-cta {
            animation: fadeInUp 1s ease-out 0.4s both;
        }

        .features {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 100px 0;
            margin-top: -60px;
            border-radius: 60px 60px 0 0;
            position: relative;
            box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.1);
        }

        .features::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, #667eea, transparent);
        }

        .features h2 {
            text-align: center;
            font-size: 3rem;
            margin-bottom: 80px;
            color: #2d3748;
            font-weight: 800;
            position: relative;
        }

        .features h2::after {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 4px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 2px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 50px;
            margin-bottom: 80px;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            padding: 50px 40px;
            border-radius: 25px;
            text-align: center;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.95);
        }

        .feature-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            font-size: 2.5rem;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }

        .feature-card:hover .feature-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.4);
        }

        .feature-card h3 {
            font-size: 1.6rem;
            margin-bottom: 20px;
            color: #2d3748;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }

        .feature-card p {
            color: #4a5568;
            line-height: 1.8;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }

        .cta-section {
            background: linear-gradient(135deg, #2d3748 0%, #4a5568 50%, #667eea 100%);
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
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="80" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="60" cy="30" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="30" cy="70" r="1" fill="rgba(255,255,255,0.1)"/></svg>');
            animation: float 30s infinite linear;
        }

        .cta-section h2 {
            font-size: 3rem;
            margin-bottom: 30px;
            font-weight: 800;
            position: relative;
            z-index: 1;
        }

        .cta-section p {
            font-size: 1.3rem;
            margin-bottom: 50px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .cta-buttons {
            display: flex;
            gap: 30px;
            justify-content: center;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }

        .btn-large {
            padding: 20px 40px;
            font-size: 1.2rem;
            border-radius: 15px;
        }

        .btn-white {
            background: white;
            color: #2d3748;
            box-shadow: 0 8px 25px rgba(255, 255, 255, 0.3);
        }

        .btn-white:hover {
            background: #f7fafc;
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(255, 255, 255, 0.4);
        }

        footer {
            background: #1a202c;
            color: white;
            text-align: center;
            padding: 60px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Scroll animations */
        .scroll-fade {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }

        .scroll-fade.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.8rem;
            }

            .hero p {
                font-size: 1.2rem;
            }

            .features h2 {
                font-size: 2.2rem;
            }

            .cta-section h2 {
                font-size: 2.2rem;
            }

            .header-content {
                justify-content: center;
                text-align: center;
            }

            .nav-buttons {
                justify-content: center;
            }

            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }

            .features-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .feature-card {
                padding: 40px 30px;
            }

            .hero {
                padding: 100px 0 60px;
            }
        }

        @media (max-width: 480px) {
            .btn {
                padding: 10px 20px;
                font-size: 0.9rem;
            }

            .btn-large {
                padding: 16px 32px;
                font-size: 1rem;
            }

            .hero h1 {
                font-size: 2.2rem;
            }

            .features h2 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation"></div>
    
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">Form Base Survey Tool</div>
                <div class="nav-buttons">
                    <a href="fbs/admin/login" class="btn btn-primary">Login</a>
                    <a href="fbs/admin/register" class="btn btn-secondary">Register</a>
                </div>
            </div>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="container">
                <div class="hero-content">
                    <h1>Advanced Survey Management</h1>
                    <p>Create, customize, and deploy powerful surveys with seamless DHIS2 integration and multi-language support for the modern data-driven world</p>
                    <div class="hero-cta">
                        <a href="fbs/admin/register" class="btn btn-primary btn-large">Get Started Free</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="features">
            <div class="container">
                <h2 class="scroll-fade">Powerful Features</h2>
                <div class="features-grid">
                    <div class="feature-card scroll-fade">
                        <div class="feature-icon">🌐</div>
                        <h3>Multi-Language Support</h3>
                        <p>Create surveys in multiple languages with built-in translation capabilities to reach diverse audiences effectively across different regions and cultures.</p>
                    </div>
                    <div class="feature-card scroll-fade">
                        <div class="feature-icon">⚙️</div>
                        <h3>Customizable Questions</h3>
                        <p>Design and edit questions with various input types, validation rules, and conditional logic to create sophisticated survey flows that adapt to responses.</p>
                    </div>
                    <div class="feature-card scroll-fade">
                        <div class="feature-icon">📊</div>
                        <h3>DHIS2 Integration</h3>
                        <p>Seamlessly create surveys from DHIS2 data and automatically sync results back to your DHIS2 instance for comprehensive health information system analytics.</p>
                    </div>
                    <div class="feature-card scroll-fade">
                        <div class="feature-icon">📱</div>
                        <h3>Mobile-First Design</h3>
                        <p>Respondents can easily take surveys on any device by simply scanning a QR code - perfect for field data collection in remote areas.</p>
                    </div>
                    <div class="feature-card scroll-fade">
                        <div class="feature-icon">📋</div>
                        <h3>Advanced Survey Builder</h3>
                        <p>Intuitive drag-and-drop interface with advanced features like branching logic, piping, and skip patterns to create complex survey flows.</p>
                    </div>
                    <div class="feature-card scroll-fade">
                        <div class="feature-icon">📈</div>
                        <h3>Real-time Analytics</h3>
                        <p>Monitor survey responses in real-time with automatic data synchronization, comprehensive dashboards, and exportable reports.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="cta-section">
            <div class="container">
                <h2 class="scroll-fade">Ready to Transform Your Data Collection?</h2>
                <p class="scroll-fade">Join thousands of organizations using our survey tool for better insights and decision-making</p>
                <div class="cta-buttons scroll-fade">
                    <a href="fbs/admin/register" class="btn btn-white btn-large">Create Account</a>
                    <a href="fbs/admin/login" class="btn btn-secondary btn-large">Sign In</a>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2025 Form Base Survey Tool. Empowering data collection worldwide.</p>
        </div>
    </footer>

    <script>
        // Floating particles animation
        function createParticles() {
            for (let i = 0; i < 15; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 20 + 's';
                particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
                document.body.appendChild(particle);
            }
        }

        // Scroll animations
        function handleScrollAnimations() {
            const elements = document.querySelectorAll('.scroll-fade');
            const windowHeight = window.innerHeight;
            
            elements.forEach(element => {
                const elementTop = element.getBoundingClientRect().top;
                const elementVisible = 150;
                
                if (elementTop < windowHeight - elementVisible) {
                    element.classList.add('visible');
                }
            });
        }

        // Header background on scroll
        function handleHeaderScroll() {
            const header = document.querySelector('header');
            if (window.scrollY > 50) {
                header.style.background = 'rgba(255, 255, 255, 0.15)';
            } else {
                header.style.background = 'rgba(255, 255, 255, 0.1)';
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            handleScrollAnimations();
            
            window.addEventListener('scroll', () => {
                handleScrollAnimations();
                handleHeaderScroll();
            });
        });
    </script>
</body>
</html>