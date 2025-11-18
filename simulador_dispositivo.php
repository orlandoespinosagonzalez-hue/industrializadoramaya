<?php
/**
 * Simulador de dispositivo biométrico (Kiosko Virtual)
 * Envía marcajes automáticos (in/out) a la API punch.php
 * 
 * Autor: Alejandra / ChatGPT
 */

date_default_timezone_set('America/Mexico_City');

// 🔑 CONFIGURACIÓN PRINCIPAL
$api_url = "http://localhost/MAYA/api/punch.php"; // Cambia la ruta si está en hosting
$api_key = "632a5aa0b807813dfeccd50dce4cebad";  // Usa la API key de tu dispositivo real
$fp_uid  = "FP001";                               // UID asignado al empleado
$CvPerson = 4;                                    // ID del empleado (mapeado)
$device_ip = "192.168.1.50";                      // IP simulada del dispositivo

// 🕒 MARCAJES AUTOMÁTICOS
$marcajes = [
  ['kind' => 'in',  'hora' => '08:00:00', 'nota' => 'Inicio de turno'],
  ['kind' => 'out', 'hora' => '16:00:00', 'nota' => 'Fin de turno']
];

foreach ($marcajes as $m) {
  $fechaHora = date('Y-m-d') . ' ' . $m['hora'];

  $payload = [
    "api_key"  => $api_key,
    "fp_uid"   => $fp_uid,
    "CvPerson" => $CvPerson,
    "kind"     => $m['kind'],
    "ts"       => $fechaHora,
    "ip"       => $device_ip,
    "notes"    => $m['nota']
  ];

  echo "\n=============================\n";
  echo " Enviando marcaje: " . strtoupper($m['kind']) . " — $fechaHora\n";

  $ch = curl_init($api_url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload)
  ]);

  $response = curl_exec($ch);
  $error = curl_error($ch);
  curl_close($ch);

  if ($error) {
    echo "❌ Error CURL: $error\n";
  } else {
    echo "📡 Respuesta del servidor:\n$response\n";
  }

  sleep(2); // Espera 2 segundos entre marcajes
}

echo "\n✅ Simulación completa.\n";
