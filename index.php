<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');

// OpenAI API Key
$openai_api_key = str_replace("\n", "", file_get_contents(__DIR__ . '/apiKey.txt'));
// Function to resize image
function resizeImage($source_path, $destination_path, $new_width, $new_height) {
    list($width, $height, $image_type) = getimagesize($source_path);

    switch ($image_type) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($source_path);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($source_path);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($source_path);
            break;
        default:
            return false; // Unsupported format
    }

    $new_image = imagecreatetruecolor($new_width, $new_height);
    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    switch ($image_type) {
        case IMAGETYPE_JPEG:
            imagejpeg($new_image, $destination_path, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($new_image, $destination_path);
            break;
        case IMAGETYPE_GIF:
            imagegif($new_image, $destination_path);
            break;
    }

    imagedestroy($image);
    imagedestroy($new_image);
    return true;
}

// Handle file upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["image"])) {
    $upload_dir = "uploads/";
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_tmp = $_FILES["image"]["tmp_name"];
    $file_name = basename($_FILES["image"]["name"]);
    $file_path = $upload_dir . $file_name;

    if ($_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
        die("File upload error: " . $_FILES["image"]["error"]);
    }

    // Move uploaded file
    if (move_uploaded_file($file_tmp, $file_path)) {
        list($width, $height) = getimagesize($file_path);

        // Determine new size
        if ($width > 1024 || $height > 1024) {
            $new_size = 1024;
        } else {
            $new_size = 512;
        }

        // Resize image
        $resized_path = $upload_dir . time() . "resized_" . $file_name;
        resizeImage($file_path, $resized_path, $new_size, $new_size);

        // Call OpenAI API
        $response = callOpenAI($resized_path, $openai_api_key);

        echo "<h2>Extracted Data:</h2>";
        echo "<pre>" . json_encode($response, JSON_PRETTY_PRINT) . "</pre>";
        echo "</hr>";
        echo '<h1>Final Data</h1>';
        echo "<hr/>";
        echo "<pre>";
        $jsonResponse = str_replace("Here's the structured data extracted from the provided document in JSON format:", '', $response['choices'][0]['message']['content']);
        $json_string = preg_replace('/```json\n|\n```/', '', $jsonResponse);
        // Step 2: Decode JSON into an associative array
        $parsed_json = json_decode($json_string, true);
        var_dump($parsed_json);
        echo "</pre>";
        echo "</hr>";

    } else {
        echo "Failed to upload the image.";
    }
}

// Function to call OpenAI Vision API
function callOpenAI($image_path, $api_key) {
    $prompt = str_replace("\n", "",file_get_contents(__DIR__ . '/prompt.txt'));
    $image_data = base64_encode(file_get_contents($image_path));
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $script_path = dirname($_SERVER['SCRIPT_NAME']);
    $base_url = $protocol . "://" . $host . $script_path . "/uploads/";
    $image_url = $base_url . basename($image_path);

    if (empty($prompt)) {
        echo ("Error: \$prompt is empty!");
        exit;
    }
    if (empty($image_data)) {
        echo ("Error: \$image_data is empty!");
        exit;
    }

    $payloadArr = [
        "model" => "gpt-4o-mini",  // ✅ Ensure this is set correctly
        "messages" => [
            ["role" => "system", "content" => "You are an AI assistant that extracts structured data from documents."],
            ["role" => "user", "content" => [
                ["type" => "text", "text" => $prompt],
                ["type" => "image_url", "image_url" => ['url' => $image_url]]
                ]]
        ],
        "max_tokens" => 500
    ];
    $payload = json_encode($payloadArr);

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_key",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    var_dump('<pre>', $payloadArr);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception("Curl error: " . curl_error($ch));
    }

    curl_close($ch);
    $decoded_response = json_decode($response, true);

    if (!$decoded_response) {
        throw new Exception("Failed to parse OpenAI API response.");
    }

    return $decoded_response;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Image for GPT-4 Vision</title>
</head>
<body>
    <h2>Upload an Image</h2>
    <form action="" method="post" enctype="multipart/form-data">
        <input type="file" name="image" accept="image/*" required>
        <button type="submit">Upload and Process</button>
    </form>
</body>
</html>
