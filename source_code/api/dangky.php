<?php

header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");

// Chỉ cho phép phương thức POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Chỉ hỗ trợ phương thức POST."
    ]);
    exit();
}

// Đọc dữ liệu JSON
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Dữ liệu JSON không hợp lệ."
    ]);
    exit();
}

// Lấy dữ liệu
$fullName = trim($data["fullName"] ?? "");
$username = trim($data["username"] ?? "");
$email = trim($data["email"] ?? "");
$password = trim($data["password"] ?? "");

// Kiểm tra rỗng
if (
    empty($fullName) ||
    empty($username) ||
    empty($email) ||
    empty($password)
) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Vui lòng nhập đầy đủ thông tin."
    ]);
    exit();
}

// Chuẩn hóa khoảng trắng
$fullName = preg_replace('/\s+/', ' ', trim($fullName));

// Kiểm tra họ và tên (ít nhất 2 từ)
if (!preg_match("/^[\p{L}]+(\s+[\p{L}]+)+$/u", $fullName)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Vui lòng nhập đầy đủ họ và tên (ít nhất 2 từ, chỉ chứa chữ cái)."
    ]);
    exit();
}

// Kiểm tra username
if (!preg_match("/^[a-zA-Z0-9_]{4,20}$/", $username)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Tên đăng nhập phải từ 4 đến 20 ký tự và chỉ gồm chữ, số hoặc dấu gạch dưới (_)."
    ]);
    exit();
}

// Kiểm tra email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Địa chỉ email không hợp lệ."
    ]);
    exit();
}

// Kiểm tra mật khẩu
if (!preg_match("/^(?=.*[A-Za-z])(?=.*\d).{8,}$/", $password)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Mật khẩu phải có ít nhất 8 ký tự và bao gồm cả chữ và số."
    ]);
    exit();
}

try {

    // Kiểm tra email đã tồn tại
    $stmt = $conn->prepare("SELECT userId FROM user WHERE email = ?");
    $stmt->execute([$email]);

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Email đã được đăng ký."
        ]);
        exit();
    }

    // Kiểm tra username đã tồn tại
    $stmt = $conn->prepare("SELECT userId FROM user WHERE username = ?");
    $stmt->execute([$username]);

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Tên đăng nhập đã tồn tại."
        ]);
        exit();
    }

    // Mã hóa mật khẩu
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Giá trị mặc định
    $status = "active";
    $roleId = 2; // Nếu User của bạn có roleId khác thì đổi tại đây

    // Thêm tài khoản
    $stmt = $conn->prepare("
        INSERT INTO user
        (
            fullName,
            username,
            email,
            password,
            status,
            roleId
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?
        )
    ");

    $stmt->execute([
        $fullName,
        $username,
        $email,
        $hashedPassword,
        $status,
        $roleId
    ]);

    http_response_code(201);

    echo json_encode([
        "success" => true,
        "message" => "Đăng ký tài khoản thành công.",
        "userId" => $conn->lastInsertId()
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Lỗi cơ sở dữ liệu.",
        "error" => $e->getMessage()
    ]);
}

?>