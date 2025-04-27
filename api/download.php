<?php
// Function to decrypt AES encrypted data
function decryptAES($encryptedBuffer, $password) {
    $salt = 'salt';
    $iterations = 100000;
    
    // Generate key using PBKDF2
    $key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    
    // Extract IV and encrypted content - now using 16 bytes for IV
    $iv = substr($encryptedBuffer, 0, 16);
    $encryptedContent = substr($encryptedBuffer, 16);
    
    // Decrypt using AES-CBC
    $decrypted = openssl_decrypt($encryptedContent, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    
    if ($decrypted === false) {
        throw new Exception('Decryption failed: ' . openssl_error_string());
    }
    
    return $decrypted;
}

// Custom Base64 decoding (URL-safe)
function customBase64Decode($base64) {
    $base64 = str_replace(['-', '_'], ['+', '/'], $base64);
    $padding = strlen($base64) % 4;
    if ($padding) {
        $base64 .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($base64);
}

// Video format definitions
$VIDEO_FORMATS = [
    'mp4' => ['mimeType' => 'video/mp4'],
    'mkv' => ['mimeType' => 'video/x-matroska'],
    'avi' => ['mimeType' => 'video/x-msvideo'],
    'mov' => ['mimeType' => 'video/quicktime'],
    'wmv' => ['mimeType' => 'video/x-ms-wmv'],
    'flv' => ['mimeType' => 'video/x-flv'],
    'webm' => ['mimeType' => 'video/webm'],
    'mpeg' => ['mimeType' => 'video/mpeg'],
    'mpg' => ['mimeType' => 'video/mpeg'],
    '3gp' => ['mimeType' => 'video/3gpp'],
    'hevc' => ['mimeType' => 'video/hevc'],
    'h264' => ['mimeType' => 'video/h264'],
    'm4v' => ['mimeType' => 'video/x-m4v'],
    'ts' => ['mimeType' => 'video/mp2t'],
    'vob' => ['mimeType' => 'video/x-ms-vob'],
    'ogv' => ['mimeType' => 'video/ogg'],
    'rm' => ['mimeType' => 'application/vnd.rn-realmedia'],
    'rmvb' => ['mimeType' => 'application/vnd.rn-realmedia-vbr'],
    'asf' => ['mimeType' => 'video/x-ms-asf'],
    'divx' => ['mimeType' => 'video/divx']
];

// Function to get file extension
function getExtension($filename) {
    $parts = explode('.', $filename);
    if (count($parts) > 1) {
        return strtolower(end($parts));
    }
    return null;
}

// Function to parse HTTP range header
function parseRange($rangeHeader, $fileSize) {
    if (!$rangeHeader || !preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
        return null;
    }
    
    $start = !empty($matches[1]) ? intval($matches[1]) : 0;
    $end = !empty($matches[2]) ? intval($matches[2]) : $fileSize - 1;
    
    $end = min($end, $fileSize - 1);
    
    if ($start > $end || $start >= $fileSize) {
        return null;
    }
    
    return ['start' => $start, 'end' => $end];
}

// Function to stream a file in chunks using curl instead of fopen
function streamFileWithoutZipHeaderWithCurl($sourceUrl, $start = 0, $end = null, $skipBytes = 4) {
    // Calculate the range
    $range = ($start + $skipBytes) . '-';
    if ($end !== null) {
        $range .= ($end + $skipBytes);
    }
    
    // Initialize curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sourceUrl);
    curl_setopt($ch, CURLOPT_RANGE, $range);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        echo $data;
        flush();
        return strlen($data);
    });
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Execute the request
    curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("CURL Error: $error");
    }
}

// Default password for encryption
$defaultPassword = '1234';

// Ensure no output has been sent before
ob_start();

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    ob_end_clean();
    http_response_code(400);
    echo 'Missing token parameter';
    exit;
}

$token = $_GET['token'];

try {
    // Decode and decrypt the token
    $encryptedBuffer = customBase64Decode($token);
    $decryptedData = decryptAES($encryptedBuffer, $defaultPassword);
    
    $data = json_decode($decryptedData, true);
    if (!$data || !isset($data['url']) || !isset($data['title'])) {
        ob_end_clean();
        http_response_code(400);
        echo 'Invalid download token';
        exit;
    }
    
    $sourceUrl = $data['url'];
    $title = $data['title'];
    
    // Verify the file extension
    $extension = getExtension($title);
    if (!$extension || !isset($VIDEO_FORMATS[$extension])) {
        ob_end_clean();
        http_response_code(400);
        echo 'Invalid or unsupported video format';
        exit;
    }
    
    $formatInfo = $VIDEO_FORMATS[$extension];
    
    // Get file size with HEAD request using curl
    $ch = curl_init($sourceUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    
    $originalContentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    
    if ($originalContentLength <= 0) {
        ob_end_clean();
        http_response_code(500);
        echo 'Could not determine file size';
        exit;
    }
    
    // Adjusted content length (removing 4 bytes ZIP header)
    $originalContentLength = (int)$originalContentLength;
    $contentLength = $originalContentLength - 4;
    
    // Clear any existing output before sending headers
    ob_end_clean();
    
    // Process Range header if present
    $rangeHeader = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;
    $range = parseRange($rangeHeader, $contentLength);
    
    if ($range) {
        // Handle range request (resume support)
        $start = $range['start'];
        $end = $range['end'];
        $chunkSize = $end - $start + 1;
        
        // Set up partial response headers
        http_response_code(206); // Partial Content
        header('Content-Type: ' . $formatInfo['mimeType']);
        header('Content-Disposition: attachment; filename="' . $title . '"');
        header('Content-Length: ' . $chunkSize);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $contentLength);
        header('Accept-Ranges: bytes');
        
        // Stream the file
        streamFileWithoutZipHeaderWithCurl($sourceUrl, $start, $end);
    } else {
        // Full download
        http_response_code(200);
        header('Content-Type: ' . $formatInfo['mimeType']);
        header('Content-Disposition: attachment; filename="' . $title . '"');
        header('Content-Length: ' . $contentLength);
        header('Accept-Ranges: bytes');
        
        // Stream the full file
        streamFileWithoutZipHeaderWithCurl($sourceUrl);
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo 'Error processing download: ' . $e->getMessage();
}
?>
