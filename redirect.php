<?php
include('config.php');

// Get the short code from URL
$code = isset($_GET['code']) && is_string($_GET['code']) ? trim($_GET['code']) : '';

if (!empty($code) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $code)) {
    // Check if code exists in database
    $stmt = $conn->prepare("SELECT long_url, expires_at FROM short_urls WHERE short_code = ?");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $url = $result->fetch_assoc();
        
        // Check expiration
        if ($url['expires_at'] && strtotime($url['expires_at']) < time()) {
            die("<h2 style='text-align:center;margin-top:50px;'>This link has expired!</h2>");
        }

        // Update click count
        $update = $conn->prepare("UPDATE short_urls SET click_count = click_count + 1 WHERE short_code = ?");
        $update->bind_param("s", $code);
        $update->execute();

        // Use a temporary redirect so expiry and destination changes take effect.
        header("HTTP/1.1 302 Found"); 
        header("Location: " . $url['long_url']);
        exit();
    } else {
        die("<h2 style='text-align:center;margin-top:50px;'>Invalid short URL!</h2>");
    }
} else {
    header("Location: index.php");
    exit();
}
?>
