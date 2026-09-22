<?php
// Connect to DB directly
$pdo = new PDO("mysql:host=127.0.0.1;dbname=edvora_chat;charset=utf8mb4", "edvora", "EdvoraChat_Secure2026!", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$stmt = $pdo->prepare("SELECT * FROM llm_providers WHERE id = 3");
$stmt->execute();
$provider = $stmt->fetch();

if (!$provider) {
    die("Error: LLM provider with ID 3 not found in database.");
}

function decryptApiKey($encryptedHex) {
    if (empty($encryptedHex)) return '';
    $data = hex2bin($encryptedHex);
    if ($data === false) return '';
    $ivLen = openssl_cipher_iv_length('AES-256-CBC');
    $iv = substr($data, 0, $ivLen);
    $encrypted = substr($data, $ivLen);
    
    $secrets = [
        'a7f4e92b8c1d3e5f6a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f',
        'EdvoraLLM_SecretEncryptionKey2026!'
    ];
    
    foreach ($secrets as $secret) {
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', md5($secret), 0, $iv);
        if ($decrypted !== false && !empty($decrypted)) {
            return $decrypted;
        }
    }
    return '';
}

$apiKey = decryptApiKey($provider['api_key_encrypted']);
if (empty($apiKey)) {
    die("Error: Could not decrypt API key for provider ID 3.");
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
$text = "Mahatma Gandhi was an Indian leader who played a major role in India's struggle for independence from British rule. He advocated nonviolent resistance, civil disobedience, and peaceful protest. His philosophy of truth and nonviolence influenced political movements around the world.";

$data = [
    "model" => $modelName,
    "input" => $text
];

$ch = curl_init("https://api.openai.com/v1/embeddings");

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
    die("cURL Error: " . curl_error($ch));
}

curl_close($ch);

$result = json_decode($response, true);

if (isset($result["error"])) {
    die("OpenAI Error: " . $result["error"]["message"]);
}

$embedding = $result["data"][0]["embedding"];

echo "Embedding dimensions: " . count($embedding) . "\n\n";

echo "First 10 values:\n";
print_r(array_slice($embedding, 0, 10));
?>
    </pre>
</body>
</html>
