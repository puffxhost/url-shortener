<?php
include('config.php');
$user_ip = $_SERVER['REMOTE_ADDR'];

function generateCode($length = 6) {
    return substr(md5(uniqid()), 0, $length);
}

if (isset($_POST['shorten'])) {
    $long_url = trim($_POST['long_url']);
    $custom_code = trim($_POST['custom_code']);
    $expiry_days = intval($_POST['expiry_days']);

    if (!filter_var($long_url, FILTER_VALIDATE_URL)) {
        $error = "Please enter a valid URL (e.g., https://example.com)";
    } else {
        $short_code = (!empty($custom_code)) ? $custom_code : generateCode();
        $expires_at = ($expiry_days > 0) ? date('Y-m-d H:i:s', strtotime("+$expiry_days days")) : NULL;
        
        $stmt = $conn->prepare("INSERT INTO short_urls (long_url, short_code, expires_at, user_ip) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $long_url, $short_code, $expires_at, $user_ip);
        
        if ($stmt->execute()) {
            $short_url = BASE_URL . "/" . $short_code;
            $success = "
                <div class='success-message'>
                    <p>Your short URL:</p>
                    <a href='$short_url' target='_blank'>$short_url</a><br><br>
                    <div class='qr-code'>
                        <img src='https://api.puffxhost.com/qr/api.php?size=150x150&data=$short_url' alt='QR Code'>
                    </div>
                </div>
            ";
        } else {
            if ($conn->errno == 1062) {
                $error = "This custom code is already taken. Please try another one.";
            } else {
                $error = "An error occurred. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced URL Shortener</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6c5ce7;
            --primary-dark: #5649c0;
            --secondary: #00cec9;
            --dark: #2d3436;
            --light: #f5f6fa;
            --glass: rgba(255, 255, 255, 0.25);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            min-height: 100vh;
            padding: 20px;
            color: var(--dark);
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        /* Header */
        .header {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeInDown 0.6s ease;
        }
        
        .header h1 {
            font-size: 2.8rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
            font-weight: 800;
        }
        
        .header p {
            font-size: 1.1rem;
            color: var(--dark);
            opacity: 0.8;
        }
        
        /* URL Form */
        .url-form {
            background: var(--glass);
            backdrop-filter: blur(12px);
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            animation: fadeIn 0.8s ease;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-control {
            width: 100%;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 8px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.8);
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
        }
        
        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.3);
        }
        
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 92, 231, 0.4);
        }
        
        .btn i {
            font-size: 18px;
        }
        
        /* Result Section */
        .result-container {
            display: none;
            background: var(--glass);
            backdrop-filter: blur(12px);
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            animation: fadeIn 0.8s ease;
        }
        
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .result-header h3 {
            color: var(--primary);
            font-size: 1.5rem;
        }
        
        .result-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .short-url-container {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.8);
            padding: 15px;
            border-radius: 8px;
        }
        
        .short-url {
            flex-grow: 1;
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
            word-break: break-all;
        }
        
        .btn-copy {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-copy:hover {
            background: var(--primary-dark);
        }
        
        .qr-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.8);
            padding: 20px;
            border-radius: 8px;
        }
        
        .qr-code {
            margin: 0 auto 15px;
            width: 180px;
            height: 180px;
            background: white;
            padding: 10px;
            border-radius: 8px;
        }
        
        .btn-download {
            background: var(--secondary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-download:hover {
            background: #00b5b2;
        }
        
        /* URL List */
        .url-list {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        
        .url-list h2 {
            margin-bottom: 20px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .url-card {
            background: rgba(255, 255, 255, 0.7);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: all 0.3s;
            border-left: 4px solid var(--primary);
        }
        
        .url-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .url-card .short-url {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
            font-size: 18px;
        }
        
        .url-card .long-url {
            color: #555;
            font-size: 14px;
            margin-bottom: 10px;
            word-break: break-all;
        }
        
        .url-card .meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: #666;
        }
        
        .url-card .meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .no-links {
            text-align: center;
            padding: 20px;
            color: #666;
        }
        
        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert.error {
            background: rgba(255, 0, 0, 0.1);
            border-left: 4px solid #ff0000;
            color: #ff0000;
        }
        
        .alert.success {
            background: rgba(0, 128, 0, 0.1);
            border-left: 4px solid #00a650;
            color: #00a650;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header h1 {
                font-size: 2rem;
            }
            
            .url-form, .result-container, .url-list {
                padding: 20px;
            }
            
            .short-url-container {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .url-card .meta {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>URL Shortener</h1>
            <p>Shorten your links and track them with ease</p>
        </div>
        
        <!-- URL Form -->
        <div class="url-form">
            <?php if (isset($error)): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="shortenForm">
                <div class="form-group">
                    <label for="long_url">Enter Your Long URL</label>
                    <input type="url" name="long_url" id="long_url" class="form-control" 
                           placeholder="https://example.com/very-long-url" required>
                </div>
                
                <div class="form-group">
                    <label for="custom_code">Custom Short Code (Optional)</label>
                    <input type="text" name="custom_code" id="custom_code" class="form-control" 
                           placeholder="my-custom-link">
                </div>
                
                <div class="form-group">
                    <label for="expiry_days">Expires After</label>
                    <select name="expiry_days" id="expiry_days" class="form-control">
                        <option value="0">Never Expires</option>
                        <option value="1">1 Day</option>
                        <option value="7">1 Week</option>
                        <option value="30">1 Month</option>
                    </select>
                </div>
                
                <button type="submit" name="shorten" class="btn">
                    <i class="fas fa-link"></i> Shorten URL
                </button>
            </form>
        </div>
        
        <!-- Result Container (Initially hidden) -->
        <?php if (isset($success)): ?>
        <div class="result-container" id="resultContainer" style="display: block;">
            <div class="result-header">
                <h3><i class="fas fa-check-circle"></i> Your Short URL is Ready!</h3>
            </div>
            <div class="result-content">
                <div class="short-url-container">
                    <div class="short-url" id="shortUrl"><?php echo $short_url; ?></div>
                    <button class="btn-copy" id="copyBtn">
                        <i class="far fa-copy"></i> Copy
                    </button>
                </div>
                <div class="qr-container">
                    <div class="qr-code">
                        <img src="https://api.puffxhost.com/qr/api.php?size=180x180&data=<?php echo urlencode($short_url); ?>" alt="QR Code">
                    </div>
                    <button class="btn-download" id="downloadBtn">
                        <i class="fas fa-download"></i> Download QR Code
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- URL List -->
        <div class="url-list">
            <h2><i class="fas fa-history"></i> Your Recent Links</h2>
            
            <?php
            $stmt = $conn->prepare("SELECT * FROM short_urls WHERE user_ip = ? ORDER BY created_at DESC LIMIT 10");
            $stmt->bind_param("s", $user_ip);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()):
                    $is_expired = ($row['expires_at'] && strtotime($row['expires_at']) < time());
                    $short_url = BASE_URL . '/' . $row['short_code'];
            ?>
                    <div class="url-card">
                        <div class="short-url">
                            <a href="<?php echo $short_url; ?>" target="_blank">
                                <?php echo $short_url; ?>
                            </a>
                        </div>
                        <div class="long-url">
                            <?php echo htmlspecialchars($row['long_url']); ?>
                        </div>
                        <div class="meta">
                            <span><i class="far fa-calendar-alt"></i> <?php echo date('d M Y', strtotime($row['created_at'])); ?></span>
                            <span><i class="far fa-clock"></i> <?php echo $row['expires_at'] ? date('d M Y', strtotime($row['expires_at'])) : 'Never'; ?></span>
                            <span><i class="far fa-eye"></i> <?php echo $row['click_count']; ?> clicks</span>
                        </div>
                    </div>
                <?php endwhile;
            } else {
                echo "<p class='no-links'>No links found. Create your first short URL above!</p>";
            }
            ?>
        </div>
    </div>

    <script>
        // Copy URL functionality
        document.getElementById('copyBtn')?.addEventListener('click', function() {
            const shortUrl = document.getElementById('shortUrl').textContent;
            navigator.clipboard.writeText(shortUrl).then(() => {
                const btn = document.getElementById('copyBtn');
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = '<i class="far fa-copy"></i> Copy';
                }, 2000);
            });
        });
        
        // Download QR Code functionality
        document.getElementById('downloadBtn')?.addEventListener('click', function() {
            const shortUrl = document.getElementById('shortUrl').textContent;
            const qrUrl = `https://api.puffxhost.com/qr/api.php?size=500x500&data=${encodeURIComponent(shortUrl)}`;
            
            fetch(qrUrl)
                .then(response => response.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `QR-${shortUrl.split('/').pop()}.png`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                });
        });
        
        // Form submission with AJAX would be a good addition here
    </script>
</body>
</html>
