<?php
/**
 * Backend PHP para sistema de login con verificación
 * Archivo: api.php
 */
// phpinfo(); 


use PHPMailer\PHPMailer\PHPMailer;

use PHPMailer\PHPMailer\Exception;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php';
// ✅ AGREGAR AL INICIO DEL ARCHIVO, después de los require
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1); // Sí guardar errores en log

// ✅ LIMPIAR CUALQUIER OUTPUT PREVIO
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
date_default_timezone_set('America/Mexico_City'); // Para coordinar zonas horarias
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'app');
define('DB_USER', 'root');
define('DB_PASS', '');

// Configuración de Firebase (opcional)
define('FIREBASE_SERVER_KEY', 'tu_firebase_server_key_aqui');

// Configuración de correo PHP Mailer
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'cesarcbr1600@gmail.com');
define('SMTP_PASS', 'ydwf zdag lost eajh');

class LoginSystem {


    private $pdo;
    
    public function __construct() {
        try {
            $this->pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
                               DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            $this->sendResponse(false, $e->getMessage(), [], 500);
        }
    }
    private function login() {
            $data = $this->getInputData();
            
            if (empty($data['login']) || empty($data['password'])) {
                $this->sendResponse(false, 'Usuario/correo y contraseña son requeridos', [], 400);
            }
            
            try {
                // Verificar si el usuario está bloqueado
                $stmt = $this->pdo->prepare("
                    SELECT id, nickname, nombre_user, password, correo, telefono, recordar_usuario,
                        correo_verificado, telefono_verificado, estado_cuenta, intentos_login,
                        bloqueado_hasta
                    FROM usuarios 
                    WHERE (nickname = ? OR correo = ?) AND estado_cuenta != 'suspendido'
                ");
                $stmt->execute([$data['login'], $data['login']]);
                $user = $stmt->fetch();
                
                if (!$user) {
                    $this->sendResponse(false, 'Usuario no encontrado', [], 404);
                }
                
                // Verificar bloqueo
                if ($user['bloqueado_hasta'] && strtotime($user['bloqueado_hasta']) > time()) {
                    $this->sendResponse(false, 'Usuario bloqueado temporalmente', [], 423);
                }
                
                // Verificar contraseña
                if (!password_verify($data['password'], $user['password'])) {
                    $this->incrementarIntentosLogin($user['id']);
                    $this->registrarLog($user['id'], 'login_fallido', 'Contraseña incorrecta');
                    $this->sendResponse(false, 'Contraseña incorrecta', [], 401);
                }
                
                // ✅ NUEVO: Verificar estado de verificación
                $correoVerificado = (int)$user['correo_verificado'] === 1;
                $telefonoVerificado = (int)$user['telefono_verificado'] === 1;
                $estaVerificado = $correoVerificado || $telefonoVerificado; // Al menos uno verificado
                
                error_log("DEBUG LOGIN: correo_verificado={$user['correo_verificado']}, telefono_verificado={$user['telefono_verificado']}, estaVerificado=" . ($estaVerificado ? 'true' : 'false'));
                
                if (!$estaVerificado) {
                    // Usuario no verificado - devolver info para OTP
                    $this->registrarLog($user['id'], 'login_no_verificado', 'Intento de login con cuenta no verificada');
                    
                    $this->sendResponse(false, 'Tu cuenta no está verificada. Por favor verifica tu correo electrónico.', [
                        'user_id' => $user['id'],
                        'nickname' => $user['nickname'],
                        'nombre_user' => $user['nombre_user'],
                        'verification_required' => true,
                        'redirect' => 'otp_activity',
                        'verification_type' => 'correo', // o 'telefono' si prefieres SMS
                        'correo' => $user['correo'],
                        'telefono' => $user['telefono']
                    ], 403);
                }
                
                // ✅ Login exitoso - usuario verificado
                $this->resetearIntentosLogin($user['id']);
                
                // Crear sesión
                $token = $this->crearSesion($user['id']);
                
                // Actualizar último acceso
                $stmt = $this->pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                $this->registrarLog($user['id'], 'login_exitoso', 'Usuario logueado correctamente');
                
                // ✅ RESPUESTA MEJORADA - Incluir estado de verificación
                $this->sendResponse(true, 'Login exitoso', [
                    'user_id' => $user['id'],
                    'nickname' => $user['nickname'],
                    'nombre_user' => $user['nombre_user'],
                    'correo' => $user['correo'],
                    'telefono' => $user['telefono'],
                    'token' => $token,
                    'recordar_usuario' => (bool)$user['recordar_usuario'],
                    'is_verified' => true, // ✅ Siempre true aquí porque ya pasó la verificación
                    'correo_verificado' => $correoVerificado,
                    'telefono_verificado' => $telefonoVerificado,
                    'verification_type' => $correoVerificado ? 'correo' : 'telefono'
                ]);
                
            } catch (Exception $e) {
                error_log("Error en login: " . $e->getMessage());
                $this->sendResponse(false, 'Error en el login: ' . $e->getMessage(), [], 500);
            }
}

// ✅ FUNCIÓN ADICIONAL - Para reenviar código desde login
private function reenviarCodigoDesdeLogin() {
    $data = $this->getInputData();
    
    if (empty($data['user_id']) || empty($data['tipo'])) {
        $this->sendResponse(false, 'Datos incompletos', [], 400);
    }
    
    try {
        // Obtener datos del usuario
        $stmt = $this->pdo->prepare("
            SELECT correo, telefono, nickname, nombre_user 
            FROM usuarios 
            WHERE id = ? AND estado_cuenta != 'suspendido'
        ");
        $stmt->execute([$data['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $this->sendResponse(false, 'Usuario no encontrado', [], 404);
        }
        
        // Invalidar códigos anteriores
        $stmt = $this->pdo->prepare("
            UPDATE codigos_verificacion 
            SET usado = TRUE 
            WHERE usuario_id = ? AND tipo_verificacion = ?
        ");
        $stmt->execute([$data['user_id'], $data['tipo']]);
        
        // Generar nuevo código
        $codigo = $this->generarCodigoVerificacion($data['user_id'], $data['tipo']);
        
        $codigoEnviado = false;
        
        // Enviar código según el tipo
        if ($data['tipo'] === 'correo') {
            $codigoEnviado = $this->enviarCodigoCorreo($user['correo'], $codigo);
        } elseif ($data['tipo'] === 'telefono' || $data['tipo'] === 'whatsapp') {
            if (!empty($user['telefono'])) {
                $codigoEnviado = $this->enviarCodigoWhatsApp($user['telefono'], $codigo);
            }
        }
        
        $this->registrarLog($data['user_id'], 'codigo_reenviado', "Código reenviado por {$data['tipo']}");
        
        $this->sendResponse(true, 'Código reenviado exitosamente', [
            'codigo_enviado' => $codigoEnviado,
            'tipo' => $data['tipo'],
            'destino' => $data['tipo'] === 'correo' ? $user['correo'] : $user['telefono']
        ]);
        
    } catch (Exception $e) {
        error_log("Error al reenviar código: " . $e->getMessage());
        $this->sendResponse(false, 'Error al reenviar código: ' . $e->getMessage(), [], 500);
    }
}

// ✅ ACTUALIZAR handleRequest() para incluir la nueva acción
public function handleRequest() {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'registro':
            $this->registro();
            break;
        case 'login':
            $this->login();
            break;
        case 'verificar_codigo':
            $this->verificarCodigo();
            break;
        case 'reenviar_codigo':
            $this->reenviarCodigo();
            break;
        case 'reenviar_codigo_login': // ✅ NUEVA ACCIÓN
            $this->reenviarCodigoDesdeLogin();
            break;
        case 'verificar_sesion':
            $this->verificarSesion();
            break;
        case 'logout':
            $this->logout();
            break;
        case 'reset_password':
            $this->resetPassword();
            break;
        case 'verificar_codigo_reset':
            $this->verificarCodigoReset();
            break;
        case 'cambiar_password':
            $this->cambiarPassword();
            break;
        case 'paquetes':
            $this->listarPaquetes();
            break;
        case 'clientes':
            $this->listarClientes();
            break;
        case 'contratos':
            $this->listarContratos();
            break;
            case 'buscaradmin':
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (empty($id) || !is_numeric($id)) {
        $this->sendResponse(false, 'ID de administrador inválido o faltante', [], 400);
        break;
    }
    $this->useradmin((int)$id); // Usa (int) para asegurar que sea un entero
    break;
    case 'contrato':
             $id = $_GET['id'] ?? $_POST['id'] ?? '';
               if (empty($id) || !is_numeric($id)) {
        $this->sendResponse(false, 'ID de administrador inválido o faltante', [], 400);
        break;
    }
    $this->buscarcontrato((int)$id);
       break;
       case 'promociones':
        $this->listarpromociones();
        break;
        case 'paquete':
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (empty($id) || !is_numeric($id)) {
        $this->sendResponse(false, 'ID de paquete inválido o faltante', [], 400);
        break;
    }
    $this->paqueteid((int)$id); // Usa (int) para asegurar que sea un entero
    break;



    case 'promocion':
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (empty($id) || !is_numeric($id)) {
        $this->sendResponse(false, 'ID de promocion inválido o faltante', [], 400);
        break;
    }
    $this->promocionid((int)$id); // Usa (int) para asegurar que sea un entero
    break;


    case 'registrar_contrato':
        $input = json_decode(file_get_contents("php://input"), true);
       // Convertimos inmediatamente a INT o FLOAT para seguridad en la base de datos.
    $idusuario        = (int) ($input['id_usuario'] ?? 0);
    $idadministrador  = (int) ($input['id_administrador'] ?? 0);
    $idpaquete        = (int) ($input['id_paquete'] ?? 0);
    $idpromocion      = (int) ($input['id_promocion'] ?? 0); // Si no se envía, es 0 (Sin promo)
    $duracion         = (int) ($input['duracion'] ?? 0);

    $clausulas        = $input['clausulas'] ?? ''; 
    $tipopago         =  $input['tipopago'] ?? ''; 
    $montototal       = (float) ($input['monto_total'] ?? 0.00); // CRÍTICO: Convertir a float
    
 //$monto_paquete=(float)();
   // $monto_descuento =(float)();

    // 2. LLAMAMOS A LA FUNCIÓN DE REGISTRO PASANDO TODOS LOS DATOS
    // ESTA ES LA MANERA CORRECTA DE QUE LA FUNCIÓN TENGA ACCESO A LOS DATOS.
    $this->registrocontrato(
        $idusuario,
        $idadministrador,
        $idpaquete,
        $idpromocion,
        $duracion,
        $clausulas,
        $tipopago,
        $montototal
    );
        break;
          
        default:
            $this->sendResponse(false, 'Acción no válida', [], 400);
    }
}
public function handleRequest__2() {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        
        switch ($action) {
            case 'registro':
                $this->registro();
                break;
            case 'login':
                $this->login();
                break;
            case 'verificar_codigo':
                $this->verificarCodigo();
                break;
            case 'reenviar_codigo':
                $this->reenviarCodigo();
                break;
            case 'verificar_sesion':
                $this->verificarSesion();
                break;
            case 'logout':
                $this->logout();
                break;
            case 'reset_password':
                $this->resetPassword();
                break;
            case 'verificar_codigo_reset':
                $this->verificarCodigoReset();
                break;
            case 'cambiar_password':
                $this->cambiarPassword();
                break;
            default:
                $this->sendResponse(false, 'Acción no válida', [], 400);
        }
    }

    /*
    private function registro() {
        $data = $this->getInputData();
        
        // Validar datos requeridos
        $required = ['nickname', 'nombre_user', 'password', 'correo'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->sendResponse(false, "El campo {$field} es requerido", [], 400);
            }
        }
        
        // Validar formato de correo
        if (!filter_var($data['correo'], FILTER_VALIDATE_EMAIL)) {
            $this->sendResponse(false, 'Formato de correo inválido', [], 400);
        }
        
        // Verificar si el usuario ya existe
        $stmt = $this->pdo->prepare("SELECT id FROM usuarios WHERE nickname = ? OR correo = ?");
        $stmt->execute([$data['nickname'], $data['correo']]);
        if ($stmt->fetch()) {
            $this->sendResponse(false, 'El nickname o correo ya están en uso', [], 409);
        }
        
        // Crear usuario
        try {
            $this->pdo->beginTransaction();
            
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO usuarios (nickname, nombre_user, password, correo, telefono, recordar_usuario) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['nickname'],
                $data['nombre_user'],
                $hashedPassword,
                $data['correo'],
                $data['telefono'] ?? null,
                isset($data['recordar_usuario']) ? (bool)$data['recordar_usuario'] : false
            ]);
            
            $userId = $this->pdo->lastInsertId();
            
            // Generar código de verificación para correo
            $codigoCorreo = $this->generarCodigoVerificacion($userId, 'correo');
            
            // Generar código de verificación para WhatsApp
            $codigoWhatsApp = null;
            if (!empty($data['telefono'])) {
                $codigoWhatsApp = $this->generarCodigoVerificacion($userId, 'whatsapp');
            }
            
            $this->pdo->commit();
            
            // Enviar códigos de verificación
            //$this->enviarCodigoCorreo($data['correo'], $codigoCorreo);

            $correoEnviado = $this->enviarCodigoCorreo($data['correo'], $codigoCorreo);

            
            if ($codigoWhatsApp && !empty($data['telefono'])) {
                $this->enviarCodigoWhatsApp($data['telefono'], $codigoWhatsApp);
            }
            
            // Registrar log
            $this->registrarLog($userId, 'registro', 'Usuario registrado exitosamente');
            
            $this->sendResponse(true, 'Usuario registrado exitosamente', [
                'user_id' => $userId,
                'verification_required' => true,
                'correo_enviado' => $correoEnviado,
                'whatsapp_enviado' => !empty($data['telefono'])
            ]);

            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(false, 'Error al registrar usuario: ' . $e->getMessage(), [], 500);
        }
    }

    */

    private function registro_2() {
        $data = $this->getInputData();

        // Validar campos obligatorios
        $required = ['nickname', 'nombre_user', 'password', 'correo'];
        foreach ($required as $field) {
            if (empty($data[$field]) || trim($data[$field]) === '') {
                $this->sendResponse(false, "El campo {$field} es requerido", [], 400);
            }
        }

        $nickname = trim($data['nickname']);
        $nombre_user = trim($data['nombre_user']);
        $password = $data['password']; // no trim por seguridad
        $correo = trim($data['correo']);
        $telefono = !empty($data['telefono']) ? trim($data['telefono']) : null;
        $recordar = isset($data['recordar_usuario']) ? (bool)$data['recordar_usuario'] : false;

        // Validar correo
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $this->sendResponse(false, 'Formato de correo inválido', [], 400);
        }

        // Debug
        error_log("DEBUG registro: nickname='$nickname', correo='$correo', telefono='$telefono'");

        // Verificar si existe usuario o correo o teléfono
        $sql = "SELECT id, nickname, correo, telefono FROM usuarios WHERE nickname = ? OR correo = ?";
        $params = [$nickname, $correo];
        if ($telefono !== null) {
            $sql .= " OR telefono = ?";
            $params[] = $telefono;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $existing = $stmt->fetchAll();


            /*
            if ($existing) {
                $msg = [];
                $userId = null;
                
                foreach ($existing as $row) {
                    if ($row['nickname'] === $nickname) $msg[] = "nickname ya en uso";
                    if ($row['correo'] === $correo) {
                        $msg[] = "correo ya en uso";
                        $userId = $row['id']; // Capturar el user_id del usuario existente
                    }
                    if ($telefono !== null && $row['telefono'] === $telefono) $msg[] = "teléfono ya en uso";
                }
                
                // AMBIO IMPORTANTE: Devolver un objeto con user_id, no un array vacío
                $responseData = [];
                if ($userId) {
                    $stmt2 = $this->pdo->prepare("SELECT correo_verificado, nickname, nombre_user FROM usuarios WHERE id = ?");
                    $stmt2->execute([$userId]);
                    $userData = $stmt2->fetch();

                    $responseData = [
                        "user_id" => $userId,
                        "verification_required" => !$userData['correo_verificado'], // true si necesita OTP
                        "nickname" => $userData['nickname'],
                        "nombre_user" => $userData['nombre_user']
                    ];
                }

                
                $this->sendResponse(false, implode(", ", $msg), $responseData, 409);
            }

            */

            // Insertar usuario
            try {
                $this->pdo->beginTransaction();

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $this->pdo->prepare("
                    INSERT INTO usuarios (nickname, nombre_user, password, correo, telefono, recordar_usuario) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $nickname,
                    $nombre_user,
                    $hashedPassword,
                    $correo,
                    $telefono,
                    $recordar
                ]);

                $userId = $this->pdo->lastInsertId();

                // Generar códigos de verificación
                $codigoCorreo = $this->generarCodigoVerificacion($userId, 'correo');
                $codigoWhatsApp = $telefono ? $this->generarCodigoVerificacion($userId, 'whatsapp') : null;

                $this->pdo->commit();

                // Enviar códigos
                $correoEnviado = $this->enviarCodigoCorreo($correo, $codigoCorreo);
                if ($codigoWhatsApp) $this->enviarCodigoWhatsApp($telefono, $codigoWhatsApp);

                $this->registrarLog($userId, 'registro', 'Usuario registrado exitosamente');

                $this->sendResponse(true, 'Usuario registrado exitosamente', [
                    'user_id' => $userId,
                    'verification_required' => true,
                    'correo_enviado' => $correoEnviado,
                    'whatsapp_enviado' => $telefono ? true : false
                ]);

            } catch (Exception $e) {
                $this->pdo->rollBack();
                $this->sendResponse(false, 'Error al registrar usuario: ' . $e->getMessage(), [], 500);
            }
}

private function registro() {
    $data = $this->getInputData();

    // Validar campos obligatorios
    $required = ['nickname', 'nombre_user', 'password', 'correo'];
    foreach ($required as $field) {
        if (empty($data[$field]) || trim($data[$field]) === '') {
            $this->sendResponse(false, "El campo {$field} es requerido", [], 400);
        }
    }

    $nickname = trim($data['nickname']);
    $nombre_user = trim($data['nombre_user']);
    $password = $data['password'];
    $correo = trim($data['correo']);
    $telefono = !empty($data['telefono']) ? trim($data['telefono']) : null;
    $recordar = isset($data['recordar_usuario']) ? (bool)$data['recordar_usuario'] : false;

    // Validar correo
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $this->sendResponse(false, 'Formato de correo inválido', [], 400);
    }

    // ✅ RESTAURAR: Verificar si existe usuario, correo o teléfono
    $sql = "SELECT id, nickname, correo, telefono, correo_verificado, telefono_verificado FROM usuarios WHERE nickname = ? OR correo = ?";
    $params = [$nickname, $correo];
    if ($telefono !== null) {
        $sql .= " OR telefono = ?";
        $params[] = $telefono;
    }

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetchAll();

    // ✅ Si el usuario ya existe, verificar su estado
    if ($existing) {
        $usuarioExistente = null;
        $conflictos = [];
        
        foreach ($existing as $row) {
            if ($row['nickname'] === $nickname) {
                $conflictos[] = "nickname ya en uso";
            }
            if ($row['correo'] === $correo) {
                $conflictos[] = "correo ya en uso";
                $usuarioExistente = $row; // Usuario con el mismo correo
            }
            if ($telefono !== null && $row['telefono'] === $telefono) {
                $conflictos[] = "teléfono ya en uso";
                if (!$usuarioExistente) $usuarioExistente = $row;
            }
        }

        // Si existe un usuario con el mismo correo, verificar su estado de verificación
        if ($usuarioExistente && $row['correo'] === $correo) {
            $correoVerificado = (int)$usuarioExistente['correo_verificado'] === 1;
            $telefonoVerificado = (int)$usuarioExistente['telefono_verificado'] === 1;
            $estaVerificado = $correoVerificado || $telefonoVerificado;

            if (!$estaVerificado) {
                // Usuario existe pero NO está verificado - ir a OTP
                $this->sendResponse(false, 'El correo ya está registrado pero no verificado', [
                    'user_id' => $usuarioExistente['id'],
                    'nickname' => $usuarioExistente['nickname'],
                    'verification_required' => true,
                    'redirect' => 'otp_activity',
                    'correo_verificado' => $correoVerificado,
                    'telefono_verificado' => $telefonoVerificado
                ], 409);
            } else {
                // Usuario existe y SÍ está verificado - ir a Home (o login)
                $this->sendResponse(false, 'El correo ya está registrado y verificado', [
                    'user_id' => $usuarioExistente['id'],
                    'nickname' => $usuarioExistente['nickname'],
                    'verification_required' => false,
                    'redirect' => 'login_activity', // O 'home_activity' si quieres login automático
                    'correo_verificado' => $correoVerificado,
                    'telefono_verificado' => $telefonoVerificado
                ], 409);
            }
        } else {
            // Otros conflictos (nickname, teléfono)
            $this->sendResponse(false, implode(", ", $conflictos), [], 409);
        }
    }

    // ✅ Usuario nuevo - Proceder con registro normal
    try {
        $this->pdo->beginTransaction();

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare("
            INSERT INTO usuarios (nickname, nombre_user, password, correo, telefono, recordar_usuario) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $nickname,
            $nombre_user,
            $hashedPassword,
            $correo,
            $telefono,
            $recordar
        ]);

        $userId = $this->pdo->lastInsertId();

        // Generar códigos de verificación
        $codigoCorreo = $this->generarCodigoVerificacion($userId, 'correo');
        $codigoWhatsApp = $telefono ? $this->generarCodigoVerificacion($userId, 'whatsapp') : null;

        $this->pdo->commit();

        // Enviar códigos
        $correoEnviado = $this->enviarCodigoCorreo($correo, $codigoCorreo);
        if ($codigoWhatsApp) $this->enviarCodigoWhatsApp($telefono, $codigoWhatsApp);

        $this->registrarLog($userId, 'registro', 'Usuario registrado exitosamente');

        // ✅ RESPUESTA PARA USUARIO NUEVO - Siempre necesita verificación
        $this->sendResponse(true, 'Usuario registrado exitosamente. Verifica tu correo.', [
            'user_id' => $userId,
            'nickname' => $nickname,
            'nombre_user' => $nombre_user,
            'verification_required' => true, // ✅ Siempre true para nuevos usuarios
            'redirect' => 'otp_activity',
            'correo_enviado' => $correoEnviado,
            'whatsapp_enviado' => $telefono ? true : false
        ]);

    } catch (Exception $e) {
        $this->pdo->rollBack();
        $this->sendResponse(false, 'Error al registrar usuario: ' . $e->getMessage(), [], 500);
    }
}
    
    private function login__2() {
        $data = $this->getInputData();
        
        if (empty($data['login']) || empty($data['password'])) {
            $this->sendResponse(false, 'Usuario/correo y contraseña son requeridos', [], 400);
        }
        
        try {
            // Verificar si el usuario está bloqueado
            $stmt = $this->pdo->prepare("
                SELECT id, nickname, nombre_user, password, correo, telefono, recordar_usuario,
                       correo_verificado, telefono_verificado, estado_cuenta, intentos_login,
                       bloqueado_hasta
                FROM usuarios 
                WHERE (nickname = ? OR correo = ?) AND estado_cuenta != 'suspendido'
            ");
            $stmt->execute([$data['login'], $data['login']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->sendResponse(false, 'Usuario no encontrado', [], 404);
            }
            
            // Verificar bloqueo
            if ($user['bloqueado_hasta'] && strtotime($user['bloqueado_hasta']) > time()) {
                $this->sendResponse(false, 'Usuario bloqueado temporalmente', [], 423);
            }
            
            // Verificar contraseña
            if (!password_verify($data['password'], $user['password'])) {
                $this->incrementarIntentosLogin($user['id']);
                $this->registrarLog($user['id'], 'login_fallido', 'Contraseña incorrecta');
                $this->sendResponse(false, 'Contraseña incorrecta', [], 401);
            }
            
            // Verificar si la cuenta está verificada
            if (!$user['correo_verificado'] && !$user['telefono_verificado']) {
                $this->sendResponse(false, 'Cuenta no verificada', [
                    'user_id' => $user['id'],
                    'verification_required' => true
                ], 403);
            }
            
            // Login exitoso
            $this->resetearIntentosLogin($user['id']);
            
            // Crear sesión
            $token = $this->crearSesion($user['id']);
            
            // Actualizar último acceso
            $stmt = $this->pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            $this->registrarLog($user['id'], 'login_exitoso', 'Usuario logueado correctamente');
            
            $this->sendResponse(true, 'Login exitoso', [
                'user_id' => $user['id'],
                'nickname' => $user['nickname'],
                'nombre_user' => $user['nombre_user'],
                'correo' => $user['correo'],
                'token' => $token,
                'recordar_usuario' => (bool)$user['recordar_usuario']
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error en el login: ' . $e->getMessage(), [], 500);
        }
    }
    
    private function verificarCodigo() {
        $data = $this->getInputData();
        
        if (empty($data['user_id']) || empty($data['codigo']) || empty($data['tipo'])) {
            $this->sendResponse(false, 'Datos incompletos', [], 400);
        }
        
        try {
            $this->pdo->beginTransaction();
            
            // Verificar código
            $stmt = $this->pdo->prepare("
                SELECT id, intentos FROM codigos_verificacion 
                WHERE usuario_id = ? AND codigo = ? AND tipo_verificacion = ? 
                AND usado = FALSE AND fecha_expiracion > NOW()
            ");
            $stmt->execute([$data['user_id'], $data['codigo'], $data['tipo']]);
            $codigoData = $stmt->fetch();
            
            if (!$codigoData) {
                $this->sendResponse(false, 'Código inválido o expirado', [], 400);
            }
            
            if ($codigoData['intentos'] >= 3) {
                $this->sendResponse(false, 'Demasiados intentos', [], 429);
            }
            
            // Marcar código como usado
            $stmt = $this->pdo->prepare("UPDATE codigos_verificacion SET usado = TRUE WHERE id = ?");
            $stmt->execute([$codigoData['id']]);
            
            // Actualizar estado de verificación del usuario
            $campo = $data['tipo'] === 'correo' ? 'correo_verificado' : 'telefono_verificado';
            $stmt = $this->pdo->prepare("UPDATE usuarios SET {$campo} = TRUE, estado_cuenta = 'activo' WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            
            $this->pdo->commit();
            
            $this->registrarLog($data['user_id'], 'verificacion_exitosa', "Verificación por {$data['tipo']} completada");
            
            $this->sendResponse(true, 'Verificación exitosa', [
                'verified' => true,
                'tipo' => $data['tipo']
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            // Incrementar intentos
            if (isset($codigoData['id'])) {
                $stmt = $this->pdo->prepare("UPDATE codigos_verificacion SET intentos = intentos + 1 WHERE id = ?");
                $stmt->execute([$codigoData['id']]);
            }
            
            $this->sendResponse(false, 'Error en la verificación: ' . $e->getMessage(), [], 500);
        }
    }
    
    private function reenviarCodigo() {
        $data = $this->getInputData();
        
        if (empty($data['user_id']) || empty($data['tipo'])) {
            $this->sendResponse(false, 'Datos incompletos', [], 400);
        }
        
        try {
            // Obtener datos del usuario
            $stmt = $this->pdo->prepare("SELECT correo, telefono FROM usuarios WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->sendResponse(false, 'Usuario no encontrado', [], 404);
            }
            
            // Invalidar códigos anteriores
            $stmt = $this->pdo->prepare("UPDATE codigos_verificacion SET usado = TRUE WHERE usuario_id = ? AND tipo_verificacion = ?");
            $stmt->execute([$data['user_id'], $data['tipo']]);
            
            // Generar nuevo código
            $codigo = $this->generarCodigoVerificacion($data['user_id'], $data['tipo']);
            
            // Enviar código
            if ($data['tipo'] === 'correo') {
                $this->enviarCodigoCorreo($user['correo'], $codigo);
            } else {
                $this->enviarCodigoWhatsApp($user['telefono'], $codigo);
            }
            
            $this->sendResponse(true, 'Código reenviado exitosamente', [
                'codigo_enviado' => true,
                'tipo' => $data['tipo']
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error al reenviar código: ' . $e->getMessage(), [], 500);
        }
    }
    
    private function verificarSesion() {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['token'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        if (empty($token)) {
            $this->sendResponse(false, 'Token requerido', [], 401);
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT s.usuario_id, u.nickname, u.nombre_user, u.correo 
                FROM sesiones s
                JOIN usuarios u ON s.usuario_id = u.id
                WHERE s.token_sesion = ? AND s.activa = TRUE AND s.fecha_expiracion > NOW()
            ");
            $stmt->execute([$token]);
            $sesion = $stmt->fetch();
            
            if (!$sesion) {
                $this->sendResponse(false, 'Sesión inválida o expirada', [], 401);
            }
            
            $this->sendResponse(true, 'Sesión válida', [
                'user_id' => $sesion['usuario_id'],
                'nickname' => $sesion['nickname'],
                'nombre_user' => $sesion['nombre_user'],
                'correo' => $sesion['correo']
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error al verificar sesión: ' . $e->getMessage(), [], 500);
        }
    }
    
    private function logout() {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['token'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        if (!empty($token)) {
            $stmt = $this->pdo->prepare("UPDATE sesiones SET activa = FALSE WHERE token_sesion = ?");
            $stmt->execute([$token]);
        }
        
        $this->sendResponse(true, 'Logout exitoso', []);
    }
    
    /*
    private function generarCodigoVerificacion($userId, $tipo) {
        $codigo = sprintf('%06d', rand(0, 999999));
        
        $stmt = $this->pdo->prepare("
            INSERT INTO codigos_verificacion (usuario_id, codigo, tipo_verificacion, fecha_expiracion) 
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
        ");
        $stmt->execute([$userId, $codigo, $tipo]);
        
        return $codigo;
    }
    */

    // También necesitas actualizar el método generarCodigoVerificacion para soportar 'reset_password'
    private function generarCodigoVerificacion($userId, $tipo) {

        $codigo = sprintf('%06d', rand(0, 999999));
        
        $stmt = $this->pdo->prepare("
            INSERT INTO codigos_verificacion (usuario_id, codigo, tipo_verificacion, fecha_expiracion) 
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
        ");
        $stmt->execute([$userId, $codigo, $tipo]);
        
        return $codigo;
    }
    
    private function enviarCodigoCorreo($correo, $codigo) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('noreply@tuapp.com', 'Keyli'); 
            $mail->addAddress($correo);

            $mail->isHTML(true);
            $mail->Subject = 'Código de verificación - Keyli';
            $mail->Body = "Tu código de verificación es: <b>{$codigo}</b>";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Error enviando correo: " . $mail->ErrorInfo);
            return false;
        }
    }

    private function enviarCodigoWhatsApp($telefono, $codigo) {

        
        // Para este caso usamos Ultramsg
        $token = "ajdhwu215arqoj25";
        $instance_id = "instance143219";
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.ultramsg.com/{$instance_id}/messages/chat",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => "token={$token}&to={$telefono}&body=Tu código de verificación es: {$codigo}",
            CURLOPT_HTTPHEADER => [
                "content-type: application/x-www-form-urlencoded"
            ],
        ]);
        
        $response = curl_exec($curl);
        curl_close($curl);
        
        return $response;
    }
    
    private function crearSesion($userId) {
        $token = bin2hex(random_bytes(32));
        $expiracion = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        $stmt = $this->pdo->prepare("
            INSERT INTO sesiones (usuario_id, token_sesion, ip_address, user_agent, fecha_expiracion) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId, 
            $token, 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $expiracion
        ]);
        
        return $token;
    }
    
    private function incrementarIntentosLogin($userId) {
        $stmt = $this->pdo->prepare("UPDATE usuarios SET intentos_login = intentos_login + 1 WHERE id = ?");
        $stmt->execute([$userId]);
        
        // Bloquear después de 5 intentos
        $stmt = $this->pdo->prepare("
            UPDATE usuarios 
            SET bloqueado_hasta = DATE_ADD(NOW(), INTERVAL 15 MINUTE) 
            WHERE id = ? AND intentos_login >= 5
        ");
        $stmt->execute([$userId]);
    }
    
    private function resetearIntentosLogin($userId) {
        $stmt = $this->pdo->prepare("UPDATE usuarios SET intentos_login = 0, bloqueado_hasta = NULL WHERE id = ?");
        $stmt->execute([$userId]);
    }
    
    private function registrarLog($userId, $accion, $detalles) {
        $stmt = $this->pdo->prepare("
            INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $accion,
            $detalles,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    }
    
    private function getInputData() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true) ?? [];
        return array_merge($data, $_POST);
    }
    
    private function sendResponse_test($success, $message, $data = [], $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Agregar estos métodos a tu clase LoginSystem

private function resetPassword() {
    $data = $this->getInputData();
    
    if (empty($data['correo'])) {
        $this->sendResponse(false, 'Correo es requerido', [], 400);
    }
    
    try {
        // Buscar usuario por correo
        $stmt = $this->pdo->prepare("SELECT id, nombre_user FROM usuarios WHERE correo = ? AND estado_cuenta != 'suspendido'");
        $stmt->execute([$data['correo']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $this->sendResponse(false, 'No se encontró una cuenta con este correo', [], 404);
        }
        
        // Invalidar códigos anteriores
        $stmt = $this->pdo->prepare("UPDATE codigos_verificacion SET usado = TRUE WHERE usuario_id = ? AND tipo_verificacion = 'reset_password'");
        $stmt->execute([$user['id']]);
        
        // Generar código para reset
        $codigo = $this->generarCodigoVerificacion($user['id'], 'reset_password');
        
        // Enviar código por correo
        $correoEnviado = $this->enviarCodigoCorreo($data['correo'], $codigo);
        
        $this->registrarLog($user['id'], 'reset_password_requested', 'Solicitud de reset de password');
        
        $this->sendResponse(true, 'Código enviado a tu correo', [
            'user_id' => $user['id'],
            'correo_enviado' => $correoEnviado
        ]);
        
    } catch (Exception $e) {
        $this->sendResponse(false, 'Error al procesar solicitud: ' . $e->getMessage(), [], 500);
    }
}

private function verificarCodigoReset() {
    $data = $this->getInputData();
    
    if (empty($data['user_id']) || empty($data['codigo'])) {
        $this->sendResponse(false, 'Datos incompletos', [], 400);
    }
    
    try {
        $this->pdo->beginTransaction();
        
        // Verificar código
        $stmt = $this->pdo->prepare("
            SELECT id, intentos FROM codigos_verificacion 
            WHERE usuario_id = ? AND codigo = ? AND tipo_verificacion = 'reset_password' 
            AND usado = FALSE AND fecha_expiracion > NOW()
        ");
        $stmt->execute([$data['user_id'], $data['codigo']]);
        $codigoData = $stmt->fetch();
        
        if (!$codigoData) {
            $this->sendResponse(false, 'Código inválido o expirado', [], 400);
        }
        
        if ($codigoData['intentos'] >= 3) {
            $this->sendResponse(false, 'Demasiados intentos', [], 429);
        }
        
        // Marcar código como usado
        $stmt = $this->pdo->prepare("UPDATE codigos_verificacion SET usado = TRUE WHERE id = ?");
        $stmt->execute([$codigoData['id']]);
        
        // Generar token de reset
        $resetToken = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare("UPDATE usuarios SET token_verificacion = ?, token_expiracion = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?");
        $stmt->execute([$resetToken, $data['user_id']]);
        
        $this->pdo->commit();
        
        $this->registrarLog($data['user_id'], 'codigo_reset_verificado', 'Código de reset verificado exitosamente');
        
        $this->sendResponse(true, 'Código verificado', [
            'reset_token' => $resetToken,
            'user_id' => $data['user_id']
        ]);
        
    } catch (Exception $e) {
        $this->pdo->rollBack();
        
        // Incrementar intentos
        if (isset($codigoData['id'])) {
            $stmt = $this->pdo->prepare("UPDATE codigos_verificacion SET intentos = intentos + 1 WHERE id = ?");
            $stmt->execute([$codigoData['id']]);
        }
        
        $this->sendResponse(false, 'Error en la verificación: ' . $e->getMessage(), [], 500);
    }
}

private function cambiarPassword() {
    $data = $this->getInputData();
    
    if (empty($data['user_id']) || empty($data['reset_token']) || empty($data['nueva_password'])) {
        $this->sendResponse(false, 'Datos incompletos', [], 400);
    }
    
    try {
        $this->pdo->beginTransaction();
        
        // Verificar token de reset
        $stmt = $this->pdo->prepare("
            SELECT id FROM usuarios 
            WHERE id = ? AND token_verificacion = ? AND token_expiracion > NOW()
        ");
        $stmt->execute([$data['user_id'], $data['reset_token']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $this->sendResponse(false, 'Token inválido o expirado', [], 400);
        }
        
        // Actualizar contraseña
        $hashedPassword = password_hash($data['nueva_password'], PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare("
            UPDATE usuarios 
            SET password = ?, token_verificacion = NULL, token_expiracion = NULL, intentos_login = 0, bloqueado_hasta = NULL
            WHERE id = ?
        ");
        $stmt->execute([$hashedPassword, $data['user_id']]);
        
        $this->pdo->commit();
        
        $this->registrarLog($data['user_id'], 'password_cambiado', 'Contraseña cambiada exitosamente');
        
        $this->sendResponse(true, 'Contraseña cambiada exitosamente', []);
        
    } catch (Exception $e) {
        $this->pdo->rollBack();
        $this->sendResponse(false, 'Error al cambiar contraseña: ' . $e->getMessage(), [], 500);
    }
}


private function sendResponse($success, $message, $data = [], $code = 200) {
    // Limpiar cualquier output anterior
    if (ob_get_level()) {
        ob_clean();
    }
    
    http_response_code($code);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Log de debug
    error_log("API Response: " . json_encode($response));
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}


private function listarPaquetes(){
    try {
        // 1. Preparamos la consulta SQL
        // Seleccionamos las columnas necesarias. * se usa por simplicidad,
        // pero es mejor listar solo las columnas que vas a usar.
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM paquetes_internet
            ORDER BY id DESC
        ");
        
        // 2. Ejecutamos la consulta
        $stmt->execute();
        
        // 3. Obtenemos todos los resultados como un array asociativo
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Verificamos si hay registros
        if (empty($clientes)) {
            // Si no hay clientes, respondemos con éxito pero con data vacía
            $this->sendResponse(true, 'No se encontraron clientes', ['data' => []], 200);
            return;
        }

        // 5. Devolvemos la respuesta exitosa con los datos
        // Usamos el formato 'data' para facilitar la lectura del frontend
        $this->sendResponse(true, 'Clientes obtenidos correctamente', ['data' => $clientes], 200);
        
    } catch (Exception $e) {
        // Manejo de errores de la base de datos
        error_log("Error al listar clientes: " . $e->getMessage());
        $this->sendResponse(false, 'Error interno del servidor al obtener clientes.', [], 500);
    }
}
private function listarClientes(){
    try {
        // 1. Preparamos la consulta SQL
        // Seleccionamos las columnas necesarias. * se usa por simplicidad,
        // pero es mejor listar solo las columnas que vas a usar.
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM usuarios
            ORDER BY id DESC
        ");
        
        // 2. Ejecutamos la consulta
        $stmt->execute();
        
        // 3. Obtenemos todos los resultados como un array asociativo
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Verificamos si hay registros
        if (empty($clientes)) {
            // Si no hay clientes, respondemos con éxito pero con data vacía
            $this->sendResponse(true, 'No se encontraron clientes', ['data' => []], 200);
            return;
        }

        // 5. Devolvemos la respuesta exitosa con los datos
        // Usamos el formato 'data' para facilitar la lectura del frontend
        $this->sendResponse(true, 'Clientes obtenidos correctamente', ['data' => $clientes], 200);
        
    } catch (Exception $e) {
        // Manejo de errores de la base de datos
        error_log("Error al listar clientes: " . $e->getMessage());
        $this->sendResponse(false, 'Error interno del servidor al obtener clientes.', [], 500);
    }
}

private function listarContratos(){
    try {
        // 1. Preparamos la consulta SQL
        // Seleccionamos las columnas necesarias. * se usa por simplicidad,
        // pero es mejor listar solo las columnas que vas a usar.
        $stmt = $this->pdo->prepare("
SELECT
    c.id AS contrato_id,
    u.nombre_user AS cliente,
    u.correo AS correo_cliente,
    a.usuario AS administrador,
    p.nombre AS paquete_contratado,
    c.fecha_contrato ,
    c.fecha_cobro AS siguiente_fecha_cobro,
    c.estado AS estado_contrato,
    c.duracion AS duracion_meses
FROM
    contratos c
JOIN
    usuarios u ON c.id_usuario = u.id       
JOIN
    administradores a ON c.id_administrador = a.id 
JOIN
    paquetes_internet p ON c.id_paquete = p.id     
LEFT JOIN
    promociones_temporales pr ON c.id_promocion = pr.id 
ORDER BY
    contrato_id ASC
        ");
        
        // 2. Ejecutamos la consulta
        $stmt->execute();
        
        // 3. Obtenemos todos los resultados como un array asociativo
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Verificamos si hay registros
        if (empty($clientes)) {
            // Si no hay clientes, respondemos con éxito pero con data vacía
            $this->sendResponse(true, 'No se encontraron clientes', ['data' => []], 200);
            return;
        }

        // 5. Devolvemos la respuesta exitosa con los datos
        // Usamos el formato 'data' para facilitar la lectura del frontend
        $this->sendResponse(true, 'Clientes obtenidos correctamente', ['data' => $clientes], 200);
        
    } catch (Exception $e) {
        // Manejo de errores de la base de datos
        error_log("Error al listar clientes: " . $e->getMessage());
        $this->sendResponse(false, 'Error interno del servidor al obtener clientes.', [], 500);
    }
}


private function useradmin($idadmin){
    try{
        // 1. Usa un marcador de posición (el signo '?')
        $sql = "SELECT * FROM administradores WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        
        // 2. Pasa el valor a execute() como un array. ¡Esto previene la Inyección SQL!
        $stmt->execute([$idadmin]); 
        
        $admin = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($admin)) {
            // Si no se encuentra, la respuesta es clara.
            $this->sendResponse(true, 'No se encontró al admin', ['data' => []], 200);
            return;
        }

        // 3. (OPCIONAL) Si se encuentra, asegúrate de enviar una respuesta con los datos.
        // Falta la línea para devolver el administrador encontrado.
        $this->sendResponse(true, 'Admin encontrado', ['data' => $admin], 200); 

    } catch(Exception $e){
        // ... (El manejo de errores está bien)
        error_log("Error al encontrar el admin: " . $e->getMessage());
        $this->sendResponse(false, 'Error interno del servidor al obtener admin', [], 500);
    }
}

private function registrocontrato(
    // 1. La función debe recibir todos los argumentos
    $idusuario,
    $idadministrador,
    $idpaquete,
    $idpromocion,
    $duracion,
    $clausulas,
    $tipopago,
    $montototal,
    
) {
    // 2. Definición del detalle del pago. Usaremos las variables recibidas
    $detallepago = "Pago inicial: Instalación , Paquete ( y Descuento";

    try {
        $this->pdo->beginTransaction();

        // 3. El SP debe recibir TODOS los datos necesarios.
        // Asumiendo que el SP tiene 9 campos. Ajusta el número (?) y el nombre del SP si es incorrecto.
        // Si el SP fuera más completo, podrías enviarle 10+ variables.
        $stmt = $this->pdo->prepare("
            CALL CrearNuevoContratoConPago(
                ?, 
                ?, 
                ?, 
                ?, 
                ?, 
                ?, 
                ?,
                ?,
                ?
            )");
       

        // Vincula en el mismo orden que el SP espera.
        $stmt->bindParam(1, $idusuario, PDO::PARAM_INT);
        $stmt->bindParam(2, $idadministrador, PDO::PARAM_INT);
        $stmt->bindParam(3, $idpaquete, PDO::PARAM_INT);
        $stmt->bindParam(4, $idpromocion, PDO::PARAM_INT);
        $stmt->bindParam(5, $duracion, PDO::PARAM_INT);

        // Si clausulas es texto (por ejemplo JSON o texto), usar STR
        $stmt->bindParam(6, $clausulas, PDO::PARAM_STR);

        // tipopago probablemente es int o string según tu diseño
        

        // montototal: si tiene decimales, pásalo como string o float; PDO no tiene PARAM_DECIMAL
        // puedes usar bindValue si quieres mayor seguridad para tipos no referenciables
        $stmt->bindParam(7, $montototal, PDO::PARAM_INT);
$stmt->bindParam(8, $tipopago, PDO::PARAM_STR);
        // detallepago es texto
        $stmt->bindParam(9, $detallepago, PDO::PARAM_STR);


         $stmt->execute();

     

echo($idusuario);
echo($idadministrador);

    

       // $contratoId = $this->pdo->lastInsertId();

        // 5. ¡CRÍTICO! Confirmar la transacción
       // $this->pdo->commit(); 

        // 6. Enviar la respuesta de éxito
        $this->sendResponse(true, 'Registro guardado con éxito.', ['contrato_id' => "listo"], 200);

    }  catch (PDOException $e) {
        $this->pdo->rollBack();

        // 👇 Aquí mostramos el error exacto
        echo json_encode([
            "error" => true,
            "message" => $e->getMessage(),
            "trace" => $e->getTraceAsString()
        ]);
        exit;
    }
}
private function buscarcontrato($id){
        try{
        // 1. Usa un marcador de posición (el signo '?')
        $sql = "
SELECT
    c.id AS contrato_id,
    u.nombre_user AS cliente,
    u.correo AS correo_cliente,
    a.usuario AS administrador,
    p.nombre AS paquete_contratado,
    c.fecha_contrato ,
    c.fecha_cobro AS siguiente_fecha_cobro,
    c.estado AS estado_contrato,
    c.duracion AS duracion_meses
FROM
    contratos c
JOIN
    usuarios u ON c.id_usuario = u.id       
JOIN
    administradores a ON c.id_administrador = a.id 
JOIN
    paquetes_internet p ON c.id_paquete = p.id     
LEFT JOIN
    promociones_temporales pr ON c.id_promocion = pr.id 
 WHERE c.id= ?";
        $stmt = $this->pdo->prepare($sql);
        
        // 2. Pasa el valor a execute() como un array. ¡Esto previene la Inyección SQL!
        $stmt->execute([$id]); 
        
        $admin = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // 3. (OPCIONAL) Si se encuentra, asegúrate de enviar una respuesta con los datos.
        // Falta la línea para devolver el administrador encontrado.
        $this->sendResponse(true, 'contrato encontrado', ['data' => $admin], 200); 

    } catch(Exception $e){
        // ... (El manejo de errores está bien)
        error_log("Error al encontrar el contrato: " . $e->getMessage());
        $this->sendResponse(false, 'Error interno del servidor al obtener admin', [], 500);
    }
}



private function listarpromociones(){
     try {
        // 1. Preparamos la consulta SQL
        // Seleccionamos las columnas necesarias. * se usa por simplicidad,
        // pero es mejor listar solo las columnas que vas a usar.
        $stmt = $this->pdo->prepare("
SELECT * FROM promociones_temporales");
        
        // 2. Ejecutamos la consulta
        $stmt->execute();
        
        // 3. Obtenemos todos los resultados como un array asociativo
        $promociones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Verificamos si hay registros
        if (empty($promociones)) {
            // Si no hay clientes, respondemos con éxito pero con data vacía
            $this->sendResponse(true, 'No se encontraron promociones', ['data' => []], 200);
            return;
        }

        // 5. Devolvemos la respuesta exitosa con los datos
        // Usamos el formato 'data' para facilitar la lectura del frontend
        $this->sendResponse(true, 'Promociones obtenidas correctamente', ['data' => $promociones], 200);
        
    } catch (Exception $e) {
        // Manejo de errores de la base de datos
        error_log("Error al listar promociones: " . $e->getMessage());
        $this->sendResponse(false, 'Error interno del servidor al obtener promociones.', [], 500);
    }
}

private function paqueteid($idpack){
    try{
        // 1. Usa un marcador de posición (el signo '?')
        $sql = "SELECT * FROM paquetes_internet WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        
        // 2. Pasa el valor a execute() como un array. ¡Esto previene la Inyección SQL!
        $stmt->execute([$idpack]); 
        
        $admin = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($admin)) {
            // Si no se encuentra, la respuesta es clara.
            $this->sendResponse(true, 'No se encontró al paquete', ['data' => []], 200);
            return;
        }


        $this->sendResponse(true, 'paquete encontrado', ['data' => $admin], 200); 

    } catch(Exception $e){
        // ... (El manejo de errores está bien)
        error_log("Error al encontrar al paquete: " . $e->getMessage());
        $this->sendResponse(false, 'Error interno del servidor al obtener al paquete', [], 500);
    }
}
private function promocionid($idpack){
    try{
        // 1. Usa un marcador de posición (el signo '?')
        $sql = "SELECT * FROM promociones_temporales WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        
        // 2. Pasa el valor a execute() como un array. ¡Esto previene la Inyección SQL!
        $stmt->execute([$idpack]); 
        
        $admin = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($admin)) {
            // Si no se encuentra, la respuesta es clara.
            $this->sendResponse(true, 'No se encontró al paquete', ['data' => []], 200);
            return;
        }


        $this->sendResponse(true, 'paquete encontrado', ['data' => $admin], 200); 

    } catch(Exception $e){
        // ... (El manejo de errores está bien)
        error_log("Error al encontrar al paquete: " . $e->getMessage());
        $this->sendResponse(false, 'Error interno del servidor al obtener al paquete', [], 500);
    }
}
}






// Inicializar y manejar la petición
try {
    $loginSystem = new LoginSystem();
    $loginSystem->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}



?>