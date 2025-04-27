<?php
// Function to encrypt text using AES
function encryptAES($text, $password) {
    $salt = 'salt';
    $iterations = 100000;
    
    // Generate key using PBKDF2
    $key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    
    // Generate proper 16-byte IV for AES-256-CBC
    $iv = random_bytes(16);
    
    // Encrypt using AES-256-CBC
    $encrypted = openssl_encrypt($text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    
    if ($encrypted === false) {
        throw new Exception('Encryption failed: ' . openssl_error_string());
    }
    
    // Combine IV and encrypted content
    $encryptedBuffer = $iv . $encrypted;
    
    return $encryptedBuffer;
}

// Custom Base64 encoding (URL-safe)
function customBase64Encode($buffer) {
    $base64 = base64_encode($buffer);
    return str_replace(['+', '/', '='], ['-', '_', ''], $base64);
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

// Default password for encryption
$defaultPassword = '1234';

// Get the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseUrl = $protocol . '://' . $host . $scriptPath;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url']) && isset($_POST['title']) && !empty($_POST['url']) && !empty($_POST['title'])) {
    $sourceUrl = $_POST['url'];
    $title = $_POST['title'];
    
    // Verify file extension
    $extension = getExtension($title);
    if (!$extension || !isset($VIDEO_FORMATS[$extension])) {
        $error = 'Invalid or unsupported video format. Make sure your title ends with a supported extension (.mp4, .mkv, .avi, etc.)';
    } else {
        try {
            // Encrypt the data
            $dataToEncrypt = json_encode(['url' => $sourceUrl, 'title' => $title]);
            $encryptedBuffer = encryptAES($dataToEncrypt, $defaultPassword);
            
            // Encode to URL-safe Base64
            $encodedToken = customBase64Encode($encryptedBuffer);
            
            // Generate the download URL
            $downloadUrl = $baseUrl . '/download.php?token=' . $encodedToken;
            
            // Show the result page
            echo generateResultHTML($downloadUrl, $title, $extension);
            exit;
        } catch (Exception $e) {
            $error = 'Error generating link: ' . $e->getMessage();
        }
    }
}

// Show the main form (with error if any)
$error = isset($error) ? $error : '';
echo generateFormHTML($baseUrl, $error);

// Function to generate HTML for the main form
function generateFormHTML($baseUrl, $error = '') {
    global $VIDEO_FORMATS;
    $supportedFormats = implode(', .', array_keys($VIDEO_FORMATS));
    
    $errorHtml = '';
    if (!empty($error)) {
        $errorHtml = <<<HTML
        <div class="error">
            <strong>Error:</strong> {$error}
        </div>
HTML;
    }
    
    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Multi-Format Secure Download Generator</title>
      <style>
        body {
          font-family: Arial, sans-serif;
          max-width: 800px;
          margin: 0 auto;
          padding: 20px;
          background-color: #f5f5f5;
        }
        .container {
          background-color: white;
          padding: 30px;
          border-radius: 8px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
          color: #333;
          text-align: center;
        }
        .form-group {
          margin-bottom: 20px;
        }
        label {
          display: block;
          margin-bottom: 5px;
          font-weight: bold;
        }
        input[type="text"] {
          width: 100%;
          padding: 10px;
          border: 1px solid #ddd;
          border-radius: 4px;
          font-size: 16px;
        }
        .help-text {
          color: #666;
          font-size: 12px;
          margin-top: 5px;
        }
        .features {
          background-color: #e8f5e9;
          border-left: 4px solid #4CAF50;
          padding: 10px 15px;
          margin: 20px 0;
          font-size: 14px;
        }
        .formats {
          background-color: #e3f2fd;
          border-left: 4px solid #2196F3;
          padding: 10px 15px;
          margin: 20px 0;
          font-size: 14px;
        }
        .error {
          background-color: #ffebee;
          border-left: 4px solid #f44336;
          padding: 10px 15px;
          margin: 20px 0;
          font-size: 14px;
        }
        .features ul, .formats ul {
          margin: 5px 0;
          padding-left: 20px;
        }
        button {
          background-color: #4CAF50;
          color: white;
          border: none;
          padding: 12px 20px;
          border-radius: 4px;
          cursor: pointer;
          font-size: 16px;
          display: block;
          width: 100%;
        }
        button:hover {
          background-color: #45a049;
        }
      </style>
    </head>
    <body>
      <div class="container">
        <h1>Multi-Format Secure Download Generator</h1>
        
        {$errorHtml}
        
        <div class="features">
          <strong>Features:</strong>
          <ul>
            <li>Creates masked download links for various video formats</li>
            <li>Encrypted source URL protection</li>
            <li>Supports resumable downloads</li>
            <li>Handles large files with efficient streaming</li>
            <li>Automatically converts polyglot ZIP files to their original format</li>
            <li>Works with any file type</li>
          </ul>
        </div>
        
        <div class="formats">
          <strong>Supported Formats:</strong>
          <p>Title must end with one of these extensions: .{$supportedFormats}</p>
          <p>Example: "My Video.mkv" or "Presentation.avi"</p>
        </div>
        
        <form action="" method="POST">
          <div class="form-group">
            <label for="url">Direct Download URL:</label>
            <input type="text" id="url" name="url" placeholder="https://example.com/file.zip" required>
            <div class="help-text">Enter the URL to the polyglot ZIP file</div>
          </div>
          <div class="form-group">
            <label for="title">Original File Name (with extension):</label>
            <input type="text" id="title" name="title" placeholder="My Video.mkv" required>
            <div class="help-text">Enter the original file name with correct extension</div>
          </div>
          <button type="submit">Generate Secure Download Link</button>
        </form>
      </div>
    </body>
    </html>
HTML;
}

// Function to generate HTML for the result page
function generateResultHTML($downloadUrl, $title, $extension) {
    global $VIDEO_FORMATS;
    $formatInfo = $VIDEO_FORMATS[$extension];
    
    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Download Link Generated</title>
      <style>
        body {
          font-family: Arial, sans-serif;
          max-width: 800px;
          margin: 0 auto;
          padding: 20px;
          background-color: #f5f5f5;
        }
        .container {
          background-color: white;
          padding: 30px;
          border-radius: 8px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
          color: #333;
          text-align: center;
        }
        .result {
          background-color: #f9f9f9;
          padding: 15px;
          border-radius: 4px;
          margin-bottom: 20px;
          word-break: break-all;
        }
        .format-info {
          background-color: #e3f2fd;
          border-left: 4px solid #2196F3;
          padding: 10px 15px;
          margin: 15px 0;
          font-size: 14px;
        }
        .note {
          background-color: #fff8e1;
          border-left: 4px solid #ffc107;
          padding: 10px 15px;
          margin: 15px 0;
          font-size: 14px;
        }
        .copy-btn {
          background-color: #4CAF50;
          color: white;
          border: none;
          padding: 10px 15px;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
          margin-top: 10px;
        }
        .copy-btn:hover {
          background-color: #45a049;
        }
        .back-btn {
          background-color: #f44336;
          color: white;
          border: none;
          padding: 10px 15px;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
          margin-top: 20px;
          text-decoration: none;
          display: inline-block;
        }
        .back-btn:hover {
          background-color: #d32f2f;
        }
      </style>
    </head>
    <body>
      <div class="container">
        <h1>Download Link Generated</h1>
        <p>Your secure download link has been created:</p>
        <div class="result" id="download-url">{$downloadUrl}</div>
        
        <div class="format-info">
          <strong>File Format:</strong> {$extension} ({$formatInfo['mimeType']})
        </div>
        
        <div class="note">
          <strong>Note:</strong> The ZIP header will be automatically removed and the file will be downloaded as a proper {$extension} file.
          Downloads can be paused and resumed.
        </div>
        
        <button class="copy-btn" onclick="copyToClipboard()">Copy Link</button>
        <div>
          <a href="" class="back-btn">Generate Another Link</a>
        </div>
      </div>
      <script>
        function copyToClipboard() {
          const url = document.getElementById('download-url').textContent;
          navigator.clipboard.writeText(url).then(() => {
            alert('Link copied to clipboard');
          }).catch(err => {
            console.error('Failed to copy: ', err);
          });
        }
      </script>
    </body>
    </html>
HTML;
}
?>
