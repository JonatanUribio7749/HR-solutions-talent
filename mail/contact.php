<?php
// contact.php — HR Solutions & Talent (postulaciones con adjunto)
// Requisitos del form (names):
// name, email, message (opcional), subject, puesto, rubro, ubicacion, cv(file)

header("Content-Type: application/json; charset=utf-8");

// ---------- CONFIG ----------
$RECIPIENT  = "info@hrsolutions.com.ar";        // <-- Cambiar si querés
$FROM_ADDR  = "webform@hrsolutions.com.ar";     // <-- Casilla creada en DonWeb
$SUBJECT_PREFIX = "[Postulación] ";
$MAX_MB     = 5;                                 // Máximo 5 MB
$ALLOWED    = ["pdf","doc","docx"];              // Extensiones permitidas
$RATE_LIMIT_WINDOW = 300; // 5 min
$RATE_LIMIT_MAX    = 5;   // 5 envíos por IP/5min

// ---------- helpers ----------
function bad($code=400, $msg="Solicitud inválida") {
  http_response_code($code);
  echo json_encode(["ok"=>false, "error"=>$msg]);
  exit();
}
function ok($payload=[]) {
  echo json_encode(["ok"=>true] + $payload);
  exit();
}
function clean($v) {
  $v = trim((string)($v ?? ""));
  $v = preg_replace("/[\r\n]+/"," ", $v); // evitar header-injection
  return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, "UTF-8");
}

// ---------- método ----------
if ($_SERVER["REQUEST_METHOD"] !== "POST") bad(400, "Método no permitido");

// ---------- rate-limit por IP ----------
$ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";
$bucket = sys_get_temp_dir()."/hrst_rate_".md5($ip);
$hits = 0;
if (file_exists($bucket)) {
  $data = json_decode(@file_get_contents($bucket), true);
  if ($data && time() - ($data["ts"] ?? 0) <= $RATE_LIMIT_WINDOW) {
    $hits = (int)($data["hits"] ?? 0);
  }
}
$hits++;
if ($hits > $RATE_LIMIT_MAX) bad(429, "Demasiadas solicitudes, intentá más tarde");
@file_put_contents($bucket, json_encode(["ts"=>time(), "hits"=>$hits]));

// ---------- honeypot opcional ----------
if (!empty($_POST["website"] ?? "")) bad(400, "Bot detectado");

// ---------- inputs ----------
$name     = clean($_POST["name"]     ?? "");
$email    = clean($_POST["email"]    ?? "");
$message  = clean($_POST["message"]  ?? "");
$subject  = clean($_POST["subject"]  ?? "Postulación");
$puesto   = clean($_POST["puesto"]   ?? "");
$rubro    = clean($_POST["rubro"]    ?? "");
$ubic     = clean($_POST["ubicacion"]?? "");

// validación mínima
if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  bad(400, "Nombre o email inválidos");
}

// ---------- arma asunto y cuerpo ----------
$subject_line = $SUBJECT_PREFIX . $subject . " — " . $name;
$body_txt = "Nueva postulación desde el sitio web\n\n"
          . "Nombre:     $name\n"
          . "Email:      $email\n"
          . "Puesto:     $puesto\n"
          . "Rubro:      $rubro\n"
          . "Ubicación:  $ubic\n"
          . "Asunto:     $subject\n\n"
          . "Mensaje:\n$message\n";

// ---------- adjunto (opcional) ----------
$hasFile = isset($_FILES["cv"]) && ($_FILES["cv"]["error"] === UPLOAD_ERR_OK);
$headers = [];
$headers[] = "From: HR Solutions & Talent <{$FROM_ADDR}>";
$headers[] = "Reply-To: {$email}";
$headers[] = "MIME-Version: 1.0";

if ($hasFile) {
  $tmp  = $_FILES["cv"]["tmp_name"];
  $nameFile = basename($_FILES["cv"]["name"]);
  $size = (int)$_FILES["cv"]["size"];

  // tamaño
  if ($size > $MAX_MB * 1024 * 1024) bad(400, "El archivo supera {$MAX_MB}MB");

  // extensión
  $ext = strtolower(pathinfo($nameFile, PATHINFO_EXTENSION));
  if (!in_array($ext, $ALLOWED, true)) bad(400, "Formato no permitido (PDF, DOC, DOCX)");

  // lee binario
  $file_data = file_get_contents($tmp);
  if ($file_data === false) bad(400, "Adjunto inválido");

  // MIME simple por extensión
  $mime = "application/octet-stream";
  if ($ext === "pdf")  $mime = "application/pdf";
  if ($ext === "doc")  $mime = "application/msword";
  if ($ext === "docx") $mime = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";

  // boundary
  $boundary = "HRST-".md5(uniqid((string)mt_rand(), true));

  $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

  // construye mensaje MIME
  $body  = "--{$boundary}\r\n";
  $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
  $body .= $body_txt . "\r\n";
  $body .= "--{$boundary}\r\n";
  $body .= "Content-Type: {$mime}; name=\"{$nameFile}\"\r\n";
  $body .= "Content-Transfer-Encoding: base64\r\n";
  $body .= "Content-Disposition: attachment; filename=\"{$nameFile}\"\r\n\r\n";
  $body .= chunk_split(base64_encode($file_data)) . "\r\n";
  $body .= "--{$boundary}--";

} else {
  // sin adjunto (texto plano)
  $headers[] = "Content-Type: text/plain; charset=UTF-8";
  $body = $body_txt;
}

// ---------- envía ----------
$headers_str = implode("\r\n", $headers);
$ok = @mail($RECIPIENT, $subject_line, $body, $headers_str);

if (!$ok) bad(500, "No se pudo enviar el correo");

ok();
