<?php
include('config.php');

// Get the short code from URL
$code = isset($_GET['code']) ? trim($_GET['code']) : '';

if (!empty($code)) {
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
        $conn->query("UPDATE short_urls SET click_count = click_count + 1 WHERE short_code = '$code'");

        // Redirect with 301 (Permanent) or 302 (Temporary)
        header("HTTP/1.1 301 Moved Permanently"); 
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
