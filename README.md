# 📌 Sistema de Login con Verificación por Correo/WhatsApp

Este proyecto implementa un sistema de autenticación seguro utilizando **PHP, MySQL y PHPMailer**.  
Incluye verificación de usuarios mediante **códigos OTP** enviados por **correo electrónico o WhatsApp**.

---

## 🚀 Características principales

- Registro de usuarios con **hash de contraseña**
- Inicio de sesión con verificación de:
  - Intentos fallidos
  - Bloqueo temporal
  - Estado de verificación (correo/teléfono)
- Envío de códigos OTP:
  - ✅ Correo electrónico (PHPMailer + SMTP Gmail)
  - ✅ WhatsApp (se debe implementar con una API externa)
- Reenvío de código desde el login
- Logs de seguridad (intentos, fallos, accesos)
- Manejo de sesiones mediante token
- Respuestas JSON para integración móvil (Android, Flutter, React Native, etc.)

---

## 📂 Archivos principales

| Archivo | Descripción |
|--------|-------------|
| `api.php` | Controlador backend con todas las acciones del sistema |
| `vendor/` | PHPMailer (instalado con Composer) |
| `db.sql` | Tablas recomendadas para la base de datos (incluye OTP y logs) |

---

## ⚙️ Requisitos

- PHP 7.4+
- MySQL/MariaDB
- Composer para instalar PHPMailer:

```bash
composer require phpmailer/phpmailer
