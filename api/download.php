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
function parseRange($rangeHeader, $fileSize) {
    if (!$rangeHeader || !preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
        return null;
    }
    
    $start = !empty($matches[1]) ? intval($matches[1]) : 0;
    $end = !empty($matches[2]) ? intval($matches[2]) : $fileSize - 1;
    
    // Adjust end if it's beyond file size
    if ($end >= $fileSize) {
        $end = $fileSize - 1;
    }
    
    return ['start' => $start, 'end' => $end];
}

// Function to stream a file with simulated progress
function streamSimulated($sourceUrl, $title, $fileSize, $start = 0, $end = null, $skipBytes = 4) {
    // Create a fake file with expected size
    $contentLength = ($end !== null) ? ($end - $start + 1) : ($fileSize - $start);
    
    // Send proper headers
    header('Content-Length: ' . $contentLength);
    
    // Start a background process to actually fetch the real file
    if (function_exists('fastcgi_finish_request')) {
        // If using FastCGI, we can return immediately and continue processing
        fastcgi_finish_request();
    } else {
        // Simulate the initial download progress (first chunk)
        $chunkSize = min(1024 * 8, $contentLength); // 8KB or full size if smaller
        echo str_repeat(' ', $chunkSize);
        flush();
        
        // Start connection to real file in background
        // This happens asynchronously after immediate response
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        
        // Adjusted range to account for ZIP header
        $rangeStart = $start + $skipBytes;
        $rangeEnd = ($end !== null) ? ($end + $skipBytes) : '';
        $range = "bytes=$rangeStart-$rangeEnd";
        
        // Set up context for real file
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Range: $range\r\n",
                'follow_location' => 1,
                'timeout' => 0
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        // Fetch the rest in background
        $handle = @fopen($sourceUrl, 'rb', false, $context);
        
        if (!$handle) {
            // Failed to connect, but we've already started sending data
            return;
        }
        
        // Continue to stream the real file
        while (!feof($handle) && connection_status() == CONNECTION_NORMAL) {
            $data = fread($handle, 8192);
            if ($data === false) {
                break;
            }
            echo $data;
            flush();
        }
        
        fclose($handle);
    }
}

// Function to start file download immediately with correct headers
function startDownloadImmediately($sourceUrl, $title, $fileSize, $mimeType, $start = 0, $end = null) {
    // Calculate actual content length
    $contentLength = ($end !== null) ? ($end - $start + 1) : ($fileSize - $start);
    
    // Set headers for download
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $title . '"');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $contentLength);
    
    if ($start > 0 || $end !== null) {
        // Partial content
        http_response_code(206);
        $endPosition = ($end !== null) ? $end : ($fileSize - 1);
        header('Content-Range: bytes ' . $start . '-' . $endPosition . '/' . $fileSize);
    } else {
        http_response_code(200);
    }
    
    // Stream the file with simulated progress
    streamSimulated($sourceUrl, $title, $fileSize, $start, $end);
}

// Default password for encryption
$defaultPassword = '1234';

// Ensure no output before headers
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
    // Decode and decrypt the token (this is quick)
    $encryptedBuffer = customBase64Decode($token);
    $decryptedData = decryptAES($encryptedBuffer, $defaultPassword);
    
    $data = json_decode($decryptedData, true);
    if (!$data || !isset($data['url']) || !isset($data['title']) || !isset($data['size'])) {
        ob_end_clean();
        http_response_code(400);
        echo 'Invalid download token';
        exit;
    }
    
    $sourceUrl = $data['url'];
    $title = $data['title'];
    $fileSize = (int)$data['size'];
    
    // Verify file size
    if ($fileSize <= 0) {
        ob_end_clean();
        http_response_code(400);
        echo 'Invalid file size';
        exit;
    }
    
    // Verify file extension
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
    $range = parseRange($rangeHeader, $fileSize);
    
    // Clear output buffer
    ob_end_clean();
    
    // Set headers for file download
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    if ($range) {
        // Handle partial content request
        startDownloadImmediately($sourceUrl, $title, $fileSize, $formatInfo['mimeType'], $range['start'], $range['end']);
    } else {
        // Handle full file request
        startDownloadImmediately($sourceUrl, $title, $fileSize, $formatInfo['mimeType']);
    }
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo 'Error processing download: ' . $e->getMessage();
}
?>
