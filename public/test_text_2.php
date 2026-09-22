<?php
// Connect to DB directly
$pdo = new PDO("mysql:host=127.0.0.1;dbname=edvora_chat;charset=utf8mb4", "edvora", "EdvoraChat_Secure2026!", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$stmt = $pdo->prepare("SELECT * FROM llm_providers WHERE id = 4");
$stmt->execute();
$provider = $stmt->fetch();

if (!$provider) {
    die("Error: LLM provider with ID 4 not found in database.");
}

$encKey = "a7f4e92b8c1d3e5f6a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f";
$dataBin = hex2bin($provider['api_key_encrypted']);
$ivLen = openssl_cipher_iv_length('AES-256-CBC');
$iv = substr($dataBin, 0, $ivLen);
$encrypted = substr($dataBin, $ivLen);
$apiKey = openssl_decrypt($encrypted, 'AES-256-CBC', md5($encKey), 0, $iv);

if (!$apiKey) {
    $apiKey = $provider['api_key_encrypted'];
}

$modelName = $provider['model_name'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($modelName); ?></title>
</head>
<body>
    <h1><?php echo htmlspecialchars($modelName); ?></h1>
    <pre>
<?php
$data = [
    "model" => $modelName,
    "input" => "Write a small one-paragraph article about Mahatma Gandhi. Keep it around 100 words and use simple English."
];

$ch = curl_init("https://api.openai.com/v1/responses");

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($data)
]);

$response = curl_exec($ch);

if ($response === false) {
    die("Error: " . curl_error($ch));
}

curl_close($ch);

$result = json_decode($response, true);

if (isset($result["output"][0]["content"][0]["text"])) {
    echo $result["output"][0]["content"][0]["text"];
} elseif (isset($result["choices"][0]["message"]["content"])) {
    echo $result["choices"][0]["message"]["content"];
} else {
    print_r($result);
}
?>
    </pre>
</body>
</html>
