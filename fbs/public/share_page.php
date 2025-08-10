<?php
session_start();

// Include the database connection file
require_once '../admin/connect.php'; // Make sure the path is correct

// Get survey_id from the URL
$surveyId = $_GET['survey_id'] ?? null;

// **** REMOVED: No longer expecting 'url' parameter from preview_form.php ****
// $qrCodeTargetUrl = $_GET['url'] ?? '';

if (!$surveyId) {
    die("Survey ID is missing.");
}

// Fetch survey details (just 'name' for default title)
$defaultSurveyTitle = 'Ministry of Health Client Satisfaction Feedback Tool';

if (isset($pdo)) {
    try {
        $surveyStmt = $pdo->prepare("SELECT name FROM survey WHERE id = ?");
        if ($surveyStmt) {
            $surveyStmt->execute([$surveyId]);
            $row = $surveyStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $defaultSurveyTitle = htmlspecialchars($row['name']);
            }
        }
    } catch (PDOException $e) {
        error_log("Database Query failed in share_page.php (survey name fetch): " . $e->getMessage());
    }
}

// Fetch survey settings from the database (rest of this logic remains the same)
$surveySettings = [];
if (isset($pdo)) {
    try {
        $settingsStmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$surveyId]);
            $existingSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingSettings) {
                $surveySettings = $existingSettings;
                if (!isset($surveySettings['title_text'])) {
                    $surveySettings['title_text'] = $defaultSurveyTitle;
                }
            } else {
                $surveySettings = [
                    'logo_path' => 'asets/asets/img/loog.jpg',
                    'show_logo' => 1,
                    'flag_black_color' => '#000000',
                    'flag_yellow_color' => '#FFCE00',
                    'flag_red_color' => '#FF0000',
                    'show_flag_bar' => 1,
                    'republic_title_text' => 'THE REPUBLIC OF UGANDA',
                    'show_republic_title_share' => 1,
                    'ministry_subtitle_text' => 'MINISTRY OF HEALTH',
                    'show_ministry_subtitle_share' => 1,
                    'qr_instructions_text' => 'Scan this QR Code to Give Your Feedback on Services Received',
                    'show_qr_instructions_share' => 1,
                    'footer_note_text' => 'Thank you for helping us improve our services.',
                    'show_footer_note_share' => 1,
                    'title_text' => $defaultSurveyTitle,
                ];
            }
        }
    } catch (PDOException $e) {
        error_log("Database Query failed in share_page.php (survey settings fetch): " . $e->getMessage());
        $surveySettings = [
            'logo_path' => 'asets/asets/img/loog.jpg',
            'show_logo' => 1,
            'flag_black_color' => '#000000',
            'flag_yellow_color' => '#FFCE00',
            'flag_red_color' => '#FF0000',
            'show_flag_bar' => 1,
            'republic_title_text' => 'THE REPUBLIC OF UGANDA',
            'show_republic_title_share' => 1,
            'ministry_subtitle_text' => 'MINISTRY OF HEALTH',
            'show_ministry_subtitle_share' => 1,
            'qr_instructions_text' => 'Scan this QR Code to Give Your Feedback on Services Received',
            'show_qr_instructions_share' => 1,
            'footer_note_text' => 'Thank you for helping us improve our services.',
            'show_footer_note_share' => 1,
            'title_text' => $defaultSurveyTitle,
        ];
    }
} else {
    error_log("PDO connection not established in share_page.php. Using default settings.");
    $surveySettings = [
        'logo_path' => 'asets/asets/img/loog.jpg',
        'show_logo' => 1,
        'flag_black_color' => '#000000',
        'flag_yellow_color' => '#FFCE00',
        'flag_red_color' => '#FF0000',
        'show_flag_bar' => 1,
        'republic_title_text' => 'THE REPUBLIC OF UGANDA',
        'show_republic_title_share' => 1,
        'ministry_subtitle_text' => 'MINISTRY OF HEALTH',
        'show_ministry_subtitle_share' => 1,
        'qr_instructions_text' => 'Scan this QR Code to Give Your Feedback on Services Received',
        'show_qr_instructions_share' => 1,
        'footer_note_text' => 'Thank you for helping us improve our services.',
        'show_footer_note_share' => 1,
        'title_text' => $defaultSurveyTitle,
    ];
}

// **** THIS IS THE NEW CRITICAL LOGIC FOR DYNAMIC URL CONSTRUCTION ****

// Get the scheme (http or https)
$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";

// Get the host (e.g., localhost, example.com)
$host = $_SERVER['HTTP_HOST'];

// Get the base path of the current script.
// This handles cases where your application is in a subfolder (e.g., example.com/myapp/)
$scriptPath = $_SERVER['SCRIPT_NAME']; // e.g., /fbs/public/share_page.php
$basePath = dirname($scriptPath);     // e.g., /fbs/public

// **** ADJUSTMENT START ****
// If share_page.php and survey_page.php are in the SAME directory (e.g., both in 'admin/'):
$finalBaseDir = $basePath; // Use the current directory's path

// Ensure a trailing slash for the base directory unless it's the root
if ($finalBaseDir !== '/') {
    $finalBaseDir .= '/';
} else {
    // If it's root, ensure it's just '/'
    $finalBaseDir = '/';
}


// Construct the full URL for survey_page.php
$qrCodeTargetUrl = $scheme . "://" . $host . "/s/" . $surveyId;

// You can add a check here for debugging to see what $qrCodeTargetUrl becomes
error_log("QR Code Target URL: " . $qrCodeTargetUrl);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Survey: <?php echo htmlspecialchars($surveySettings['title_text'] ?? $defaultSurveyTitle); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
     
        
        :root {
            --primary-blue: #007bff;
            --dark-blue: #0056b3;
            --uganda-black: #000000;
            --uganda-yellow: #FFCE00;
            --uganda-red: #FF0000;
            --light-blue-bg: #e6f7ff;
            --primary-font: 'Poppins', sans-serif;
            --text-color-dark: #2c3e50;
        }

        body {
            font-family: var(--primary-font);
            background: linear-gradient(135deg, #f0f4f8, #e6e9ee);
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: var(--text-color-dark);
            line-height: 1.6;
        }


        .feedback-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            max-width: 650px;
            width: 100%;
            position: relative;
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }

        .header-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 30px;
        }

        .logo-container {
            width: 130px;
            height: 130px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            border: 1px solid #eee;
            border-radius: 50%;
            padding: 10px;
            background: white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            overflow: hidden; /* Ensure logo doesn't overflow circular container */
        }

        .logo-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .title-uganda, .subtitle-moh {
            text-align: center;
        }

        .title-uganda {
            font-size: 19px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .subtitle-moh {
            font-size: 34px;
            font-weight: 700;
            margin-bottom: 30px;
            color: var(--primary-blue);
            position: relative;
            padding-bottom: 12px;
        }
        .subtitle-moh::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 4px;
            background: linear-gradient(90deg, var(--uganda-black), var(--uganda-yellow), var(--uganda-red));
            border-radius: 2px;
        }

        .flag-bar {
            height: 28px;
            width: 100%;
            margin: 30px 0;
            display: flex;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }
        .flag-black, .flag-yellow, .flag-red {
            flex: 1;
            /* Default colors are set in JS, but these provide a fallback */
            background-color: var(--uganda-black);
        }
        .flag-yellow { background-color: var(--uganda-yellow); }
        .flag-red { background-color: var(--uganda-red); }

        .qr-section {
            background-color: var(--light-blue-bg);
            padding: 35px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 35px;
            border: 1px solid #ccefff;
            box-shadow: inset 0 2px 8px rgba(0, 123, 255, 0.1);
        }

        .qr-code-container {
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
            border: 2px solid var(--primary-blue);
            transition: transform 0.3s ease-in-out;
            display: flex; /* To center QR code image if it's the content */
            justify-content: center;
            align-items: center;
        }

        .qr-code-container:hover {
            transform: scale(1.02);
        }

        .qr-code-container canvas,
        .qr-code-container img { /* Style for QR code canvas/image */
            width: 200px;
            height: 200px;
            display: block;
        }

        .instructions {
            font-size: 20px;
            line-height: 1.6;
            text-align: center;
            margin: 25px 0;
            color: var(--text-color-dark);
            font-weight: 600;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .instructions .icon {
            font-size: 36px;
            color: var(--primary-blue);
        }

        .go-to-survey-button { /* Changed class name for clarity */
            margin-top: 30px;
            padding: 14px 30px;
            background-color: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
            text-decoration: none; /* Add this for anchor tag styling */
        }

        .go-to-survey-button:hover {
            background-color: var(--dark-blue);
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(0, 123, 255, 0.4);
            text-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .go-to-survey-button:active {
            transform: translateY(0);
            box-shadow: 0 2px 5px rgba(0, 123, 255, 0.2);
        }

        .footer-note {
            margin-top: 35px;
            font-size: 15px;
            color: #666;
            text-align: center;
            border-top: 1px solid #eee;
            padding-top: 20px;
            font-weight: 500;
        }

        /* Utility class for hiding elements */
        .hidden-element {
            display: none !important;
        }

        @media (max-width: 600px) {
            body {
                padding: 15px;
            }
            .feedback-container {
                padding: 30px 20px;
            }

            .subtitle-moh {
                font-size: 28px;
            }

            .qr-section {
                padding: 25px 15px;
            }

            .qr-code-container canvas,
            .qr-code-container img {
                width: 180px;
                height: 180px;
            }

            .instructions {
                font-size: 17px;
            }

            .go-to-survey-button { /* Changed class name */
                font-size: 16px;
                padding: 12px 25px;
            }
        }
    </style>
</head>
<body>
    <div class="feedback-container">
        <div class="header-section" id="share-header-section">
            <div class="logo-container" style="display: <?php echo ($surveySettings['show_logo'] ?? true) ? 'flex' : 'none'; ?>;">
                <img id="moh-logo" src="<?php echo htmlspecialchars($surveySettings['logo_path'] ?? 'asets/asets/img/loog.jpg'); ?>" alt="Ministry of Health Logo">
            </div>
            <div class="title-uganda" id="republic-title-share" style="display: <?php echo ($surveySettings['show_republic_title_share'] ?? true) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars($surveySettings['republic_title_text'] ?? 'THE REPUBLIC OF UGANDA'); ?></div>
            <div class="subtitle-moh" id="ministry-subtitle-share" style="display: <?php echo ($surveySettings['show_ministry_subtitle_share'] ?? true) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars($surveySettings['ministry_subtitle_text'] ?? 'MINISTRY OF HEALTH'); ?></div>
        </div>

        <div class="flag-bar" id="flag-bar-share" style="display: <?php echo ($surveySettings['show_flag_bar'] ?? true) ? 'flex' : 'none'; ?>;">
            <div class="flag-black" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_black_color'] ?? '#000000'); ?>;"></div>
            <div class="flag-yellow" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_yellow_color'] ?? '#FFCE00'); ?>;"></div>
            <div class="flag-red" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_red_color'] ?? '#FF0000'); ?>;"></div>
        </div>

        <h1><?php echo htmlspecialchars($surveySettings['title_text'] ?? $defaultSurveyTitle); ?></h1>

        <div class="qr-section">
            <div class="qr-code-container">
                <div id="qr-code"></div>
            </div>

            <div class="instructions" id="qr-instructions-text" style="display: <?php echo ($surveySettings['show_qr_instructions_share'] ?? true) ? 'flex' : 'none'; ?>;">
                <i class="fas fa-qrcode icon"></i>
                <span><?php echo htmlspecialchars($surveySettings['qr_instructions_text'] ?? 'Scan this QR Code to Give Your Feedback<br>on Services Received'); ?></span>
            </div>

            <a href="<?php echo htmlspecialchars($qrCodeTargetUrl); ?>" class="go-to-survey-button" target="_blank">
                <i class="fas fa-external-link-alt"></i> Go to Survey Page
            </a>
        </div>

        <div class="footer-note" id="footer-note-text" style="display: <?php echo ($surveySettings['show_footer_note_share'] ?? true) ? 'block' : 'none'; ?>;">
            <?php echo htmlspecialchars($surveySettings['footer_note_text'] ?? 'Thank you for helping us improve our services.'); ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        // Data passed from PHP
        const phpSurveyId = <?php echo json_encode($surveyId); ?>;
        // The target URL is now fully constructed by PHP
        const phpQrCodeTargetUrl = <?php echo json_encode($qrCodeTargetUrl); ?>;
        const phpSurveySettings = <?php echo json_encode($surveySettings); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            if (phpQrCodeTargetUrl && document.getElementById('qr-code')) {
                new QRCode(document.getElementById('qr-code'), {
                    text: phpQrCodeTargetUrl,
                    width: 200,
                    height: 200,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            } else {
                document.getElementById('qr-code').innerHTML = '<p style="color:red; text-align:center;">Error: QR code URL missing.</p>';
            }
        });
    </script>
</body>
</html>