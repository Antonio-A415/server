<?php


// Configuración de base de datos
define('DB_CONFIG', [
    'host' => 'localhost',
    'dbname' => 'app',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4'
]);

// Configuración de correo electrónico
define('EMAIL_CONFIG', [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'cesarcbr1600@gmail.com',
    'smtp_password' => 'ydwf zdag lost eajh',
    'from_email' => 'cesarcbr1600@gmail.com',
    'from_name' => 'Software Development'
]);

// Configuración de WhatsApp (Ultramsg)
define('WHATSAPP_CONFIG', [
    'api_url' => 'https://api.ultramsg.com',
    'instance_id' => 'instance143219',
    'token' => 'ajdhwu215arqoj25'
]);

// Configuración de Firebase

/*
define('FIREBASE_CONFIG', [
    'server_key' => 'clave_servidor_firebase',
    'database_url' => 'url base_datos_firebase'
]);

*/
// Configuración de seguridad
define('SECURITY_CONFIG', [
    'max_login_attempts' => 5,
    'lockout_duration' => 15, // minutos
    'verification_code_expiry' => 10, // minutos
    'session_duration' => 7, // días
    'password_min_length' => 8
]);

?>