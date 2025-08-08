<?php
session_start();

// Include the database connection file
require_once 'connect.php';

// Get survey_id from the URL
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    die("Survey ID is missing.");
}

// Fetch survey details
$defaultSurveyTitle = 'DHIS2 Tracker Program';

if (isset($pdo)) {
    try {
        $surveyStmt = $pdo->prepare("SELECT id, type, name, dhis2_program_uid, dhis2_instance FROM survey WHERE id = ?");
        if ($surveyStmt) {
            $surveyStmt->execute([$surveyId]);
            $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
            if ($survey) {
                $defaultSurveyTitle = htmlspecialchars($survey['name']);
                
                // Check if this is a DHIS2 tracker program
                if ($survey['type'] !== 'dhis2' || empty($survey['dhis2_program_uid'])) {
                    // Redirect to regular share page
                    header("Location: share_page.php?survey_id=" . $surveyId);
                    exit();
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Database Query failed in tracker_share.php (survey fetch): " . $e->getMessage());
    }
}

// Get DHIS2 configuration for program details
$dhis2Config = null;
$trackerProgram = null;
try {
    if (!empty($survey['dhis2_instance'])) {
        $stmt = $pdo->prepare("SELECT id, url as base_url, username, password, instance_key, description FROM dhis2_instances WHERE instance_key = ?");
        $stmt->execute([$survey['dhis2_instance']]);
        $dhis2Config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Decode password if it's base64 encoded
        if ($dhis2Config && !empty($dhis2Config['password'])) {
            $decodedPassword = base64_decode($dhis2Config['password']);
            if ($decodedPassword !== false) {
                $dhis2Config['password'] = $decodedPassword;
            }
        }
        
        // Fetch basic program info from DHIS2
        if ($dhis2Config) {
            $url = rtrim($dhis2Config['base_url'], '/') . '/api/programs/' . $survey['dhis2_program_uid'] . '.json?fields=id,name,description';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($dhis2Config['username'] . ':' . $dhis2Config['password']),
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $trackerProgram = json_decode($response, true);
            }
            curl_close($ch);
        }
    }
} catch (Exception $e) {
    error_log("Error fetching DHIS2 program info: " . $e->getMessage());
}

// Fetch survey settings from the database
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
                    'flag_yellow_color' => '#FCD116',
                    'flag_red_color' => '#D21034',
                    'show_flag_bar' => 1,
                    'republic_title_text' => 'THE REPUBLIC OF UGANDA',
                    'show_republic_title_share' => 1,
                    'ministry_subtitle_text' => 'MINISTRY OF HEALTH',
                    'show_ministry_subtitle_share' => 1,
                    'qr_instructions_text' => 'Scan this QR Code to Access the DHIS2 Tracker Program',
                    'show_qr_instructions_share' => 1,
                    'footer_note_text' => 'Thank you for participating in our health data collection program.',
                    'show_footer_note_share' => 1,
                    'title_text' => $defaultSurveyTitle,
                ];
            }
        }
    } catch (PDOException $e) {
        error_log("Database Query failed in tracker_share.php (survey settings fetch): " . $e->getMessage());
    }
}

// Construct the full URL for tracker_program_form.php
$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = dirname($scriptPath);

$finalBaseDir = $basePath;
if ($finalBaseDir !== '/') {
    $finalBaseDir .= '/';
} else {
    $finalBaseDir = '/';
}

$qrCodeTargetUrl = $scheme . "://" . $host . $finalBaseDir . "tracker_program_form.php?survey_id=" . $surveyId;

error_log("Tracker QR Code Target URL: " . $qrCodeTargetUrl);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Tracker Program: <?php echo htmlspecialchars($surveySettings['title_text'] ?? $defaultSurveyTitle); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #667eea;
            --dark-blue: #5a6fd8;
            --secondary-purple: #764ba2;
            --uganda-black: #000000;
            --uganda-yellow: #FCD116;
            --uganda-red: #D21034;
            --light-blue-bg: #f0f4ff;
            --primary-font: 'Poppins', sans-serif;
            --text-color-dark: #2c3e50;
            --success-green: #28a745;
            --info-blue: #17a2b8;
        }

        body {
            font-family: var(--primary-font);
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
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
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            max-width: 700px;
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
            border: 2px solid var(--primary-blue);
            border-radius: 50%;
            padding: 10px;
            background: white;
            box-shadow: 0 6px 15px rgba(102, 126, 234, 0.2);
            overflow: hidden;
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
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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

        .program-title {
            font-size: 28px;
            font-weight: 700;
            text-align: center;
            margin: 20px 0;
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .program-description {
            text-align: center;
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
            font-style: italic;
        }

        .tracker-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--success-green), #20c997);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
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
        }
        .flag-black { background-color: var(--uganda-black); }
        .flag-yellow { background-color: var(--uganda-yellow); }
        .flag-red { background-color: var(--uganda-red); }

        .qr-section {
            background: linear-gradient(135deg, var(--light-blue-bg), #e8f2ff);
            padding: 40px;
            border-radius: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 35px;
            border: 2px solid var(--primary-blue);
            box-shadow: inset 0 2px 10px rgba(102, 126, 234, 0.1);
        }

        .qr-code-container {
            margin-bottom: 30px;
            padding: 25px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            border: 3px solid var(--primary-blue);
            transition: transform 0.3s ease-in-out;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        .qr-code-container::before {
            content: '';
            position: absolute;
            top: -3px;
            left: -3px;
            right: -3px;
            bottom: -3px;
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-purple));
            border-radius: 18px;
            z-index: -1;
        }

        .qr-code-container:hover {
            transform: scale(1.03);
        }

        .qr-code-container canvas,
        .qr-code-container img {
            width: 220px;
            height: 220px;
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
            font-size: 40px;
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .action-button {
            padding: 14px 28px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            text-decoration: none;
            color: white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .action-button.primary {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-purple));
        }

        .action-button.success {
            background: linear-gradient(135deg, var(--success-green), #20c997);
        }

        .action-button.info {
            background: linear-gradient(135deg, var(--info-blue), #138496);
        }

        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            text-decoration: none;
            color: white;
        }

        .action-button:active {
            transform: translateY(-1px);
        }

        .footer-note {
            margin-top: 35px;
            font-size: 15px;
            color: #666;
            text-align: center;
            border-top: 2px solid #eee;
            padding-top: 20px;
            font-weight: 500;
        }

        .hidden-element {
            display: none !important;
        }

        .info-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid var(--primary-blue);
        }

        .info-section h4 {
            color: var(--primary-blue);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-section p {
            margin: 5px 0;
            color: #555;
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

            .program-title {
                font-size: 24px;
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

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }

            .action-button {
                width: 100%;
                justify-content: center;
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
            <div class="flag-yellow" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_yellow_color'] ?? '#FCD116'); ?>;"></div>
            <div class="flag-red" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_red_color'] ?? '#D21034'); ?>;"></div>
        </div>

        <div class="text-center">
            <div class="tracker-badge">
                <i class="fas fa-database"></i>
                DHIS2 Tracker Program
            </div>
        </div>

        <h1 class="program-title"><?php echo htmlspecialchars($surveySettings['title_text'] ?? $defaultSurveyTitle); ?></h1>

        <?php if ($trackerProgram && !empty($trackerProgram['description'])): ?>
            <p class="program-description"><?php echo htmlspecialchars($trackerProgram['description']); ?></p>
        <?php endif; ?>

        <div class="info-section">
            <h4><i class="fas fa-info-circle"></i> About This Program</h4>
            <p><strong>Program Type:</strong> DHIS2 Tracker Program with Repeatable Stages</p>
            <p><strong>Data Collection:</strong> Participant data with multiple visits/events</p>
            <p><strong>Integration:</strong> Data is directly synchronized with DHIS2</p>
        </div>

        <div class="qr-section">
            <div class="qr-code-container">
                <div id="qr-code"></div>
            </div>

            <div class="instructions" id="qr-instructions-text" style="display: <?php echo ($surveySettings['show_qr_instructions_share'] ?? true) ? 'flex' : 'none'; ?>;">
                <i class="fas fa-qrcode icon"></i>
                <span><?php echo htmlspecialchars($surveySettings['qr_instructions_text'] ?? 'Scan this QR Code to Access the DHIS2 Tracker Program'); ?></span>
            </div>

            <div class="action-buttons">
                <a href="<?php echo htmlspecialchars($qrCodeTargetUrl); ?>" class="action-button primary" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Open Tracker Form
                </a>
                
                <button onclick="copyToClipboard('<?php echo htmlspecialchars($qrCodeTargetUrl); ?>')" class="action-button success">
                    <i class="fas fa-copy"></i> Copy Link
                </button>
            </div>
        </div>

        <div class="footer-note" id="footer-note-text" style="display: <?php echo ($surveySettings['show_footer_note_share'] ?? true) ? 'block' : 'none'; ?>;">
            <?php echo htmlspecialchars($surveySettings['footer_note_text'] ?? 'Thank you for participating in our health data collection program.'); ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        // Data passed from PHP
        const phpSurveyId = <?php echo json_encode($surveyId); ?>;
        const phpQrCodeTargetUrl = <?php echo json_encode($qrCodeTargetUrl); ?>;
        const phpSurveySettings = <?php echo json_encode($surveySettings); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            if (phpQrCodeTargetUrl && document.getElementById('qr-code')) {
                new QRCode(document.getElementById('qr-code'), {
                    text: phpQrCodeTargetUrl,
                    width: 220,
                    height: 220,
                    colorDark: "#2c3e50",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            } else {
                document.getElementById('qr-code').innerHTML = '<p style="color:red; text-align:center;">Error: QR code URL missing.</p>';
            }
        });

        function copyToClipboard(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    showToast('Link copied to clipboard!', 'success');
                }, function(err) {
                    console.error('Could not copy text: ', err);
                    fallbackCopyTextToClipboard(text);
                });
            } else {
                fallbackCopyTextToClipboard(text);
            }
        }

        function fallbackCopyTextToClipboard(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.top = "0";
            textArea.style.left = "0";
            textArea.style.position = "fixed";

            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showToast('Link copied to clipboard!', 'success');
                } else {
                    showToast('Failed to copy link', 'error');
                }
            } catch (err) {
                console.error('Fallback: Oops, unable to copy', err);
                showToast('Failed to copy link', 'error');
            }

            document.body.removeChild(textArea);
        }

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#28a745' : '#dc3545'};
                color: white;
                padding: 12px 20px;
                border-radius: 6px;
                font-weight: 600;
                z-index: 10000;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                opacity: 0;
                transition: opacity 0.3s ease;
            `;

            document.body.appendChild(toast);
            
            setTimeout(() => toast.style.opacity = '1', 10);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => document.body.removeChild(toast), 300);
            }, 3000);
        }
    </script>
</body>
</html>