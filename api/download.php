<?php
// Function to decrypt AES encrypted data
function decryptAES($encryptedBuffer, $password) {
    $salt = 'salt';
    $iterations = 100000;
    
    // Generate key using PBKDF2
    $key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    
    // Extract IV and encrypted content - using 16 bytes for IV
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
function parseRange($rangeHeader) {
    if (!$rangeHeader || !preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
        return null;
    }
    
    $start = !empty($matches[1]) ? intval($matches[1]) : 0;
    $end = !empty($matches[2]) ? intval($matches[2]) : null;
    
    return ['start' => $start, 'end' => $end];
}

// Function to stream a file directly - quick start, no size checking
function streamFileQuick($sourceUrl, $start = 0, $skipBytes = 4) {
    // Set timeout to avoid Vercel's 10-second limit
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    
    // Calculate the range start with ZIP header skip
    $rangeStart = $start + $skipBytes;
    $range = "bytes=$rangeStart-";
    
    // Set up context options
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Range: $range\r\n",
            'follow_location' => 1,
            'ignore_errors' => true,
            'timeout' => 2  // Short timeout to start quickly
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    // Start streaming immediately in small chunks
    $handle = @fopen($sourceUrl, 'rb', false, $context);
    if (!$handle) {
        throw new Exception("Could not open stream for URL: $sourceUrl");
    }
    
    // Set to non-blocking if possible
    if (function_exists('stream_set_blocking')) {
        stream_set_blocking($handle, 0);
    }
    
    // Read and output in small chunks to get data flowing quickly
    $chunkSize = 8192; // 8KB chunks
    $bytesRead = 0;
    $startTime = microtime(true);
    
    while (!feof($handle) && (microtime(true) - $startTime) < 9.5) { // Keep under Vercel's 10s limit
        $data = fread($handle, $chunkSize);
        if ($data === false) {
            break;
        }
        echo $data;
        flush();
        $bytesRead += strlen($data);
        
        // Brief pause to allow output buffer to flush
        if (function_exists('usleep')) {
            usleep(1000); // 1ms pause
        }
    }
    
    fclose($handle);
    return $bytesRead;
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
    // Start timing for performance tracking
    $startTime = microtime(true);
    
    // Decode and decrypt the token (this is quick)
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
    
    // Verify the file extension (this is quick)
    $extension = getExtension($title);
    if (!$extension || !isset($VIDEO_FORMATS[$extension])) {
        ob_end_clean();
        http_response_code(400);
        echo 'Invalid or unsupported video format';
        exit;
    }
    
    $formatInfo = $VIDEO_FORMATS[$extension];
    
    // Process Range header if present
    $rangeHeader = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;
    $range = parseRange($rangeHeader);
    $start = $range ? $range['start'] : 0;
    
    // Clear any existing output before sending headers
    ob_end_clean();
    
    // Send basic headers to start the download immediately
    // Note: We're not setting Content-Length since we don't know it yet
    // This will make the download start as a chunked transfer
    header('Content-Type: ' . $formatInfo['mimeType']);
    header('Content-Disposition: attachment; filename="' . $title . '"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    if ($range) {
        http_response_code(206); // Partial Content
        if (isset($range['end'])) {
            header('Content-Range: bytes ' . $start . '-' . $range['end'] . '/*');
        } else {
            header('Content-Range: bytes ' . $start . '-*/*');
        }
    } else {
        http_response_code(200);
    }
    
    // Start streaming immediately - this needs to happen within the 10-second limit
    streamFileQuick($sourceUrl, $start);
    
    // Note: The script will terminate before hitting Vercel's timeout,
    // but the client will have already started receiving data
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo 'Error processing download: ' . $e->getMessage();
}
?>
