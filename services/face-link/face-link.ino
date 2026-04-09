#include "esp_camera.h"
#include <HTTPClient.h>
#include <WiFi.h>


/**
 * ╔════════════════════════════════════════════════════════════════╗
 * ║       ESP32-CAM RECONOCIMIENTO FACIAL - SENA v2.0              ║
 * ║       Optimizado para Laravel y Script Python                  ║
 * ╚════════════════════════════════════════════════════════════════╝
 */

// --- CONFIGURACIÓN WIFI ---
const char *ssid = "TU_SSID";
const char *password = "TU_PASSWORD";

// --- CONFIGURACIÓN SERVIDOR ---
const char *serverUrl = "http://10.251.250.104/api/recognize";
const String ambient_id = "1"; // ID del ambiente asignado a esta cámara

// --- CONFIGURACIÓN DE HARDWARE ---
#define USE_FLASH true        // ¿Usar el flash durante la captura?
#define BOTON_HABILITADO true // ¿Hay un botón físico conectado?
#define BOTON_PIN 12          // GPIO para botón (INPUT_PULLUP)
#define LED_FLASH 4           // Flash LED
#define LED_STATUS 33         // LED rojo integrado (LOW=ON)

// --- PINES ESP32-CAM (AI-THINKER) ---
#define PWDN_GPIO_NUM 32
#define RESET_GPIO_NUM -1
#define XCLK_GPIO_NUM 0
#define SIOD_GPIO_NUM 26
#define SIOC_GPIO_NUM 27
#define Y9_GPIO_NUM 35
#define Y8_GPIO_NUM 34
#define Y7_GPIO_NUM 39
#define Y6_GPIO_NUM 36
#define Y5_GPIO_NUM 21
#define Y4_GPIO_NUM 19
#define Y3_GPIO_NUM 18
#define Y2_GPIO_NUM 5
#define VSYNC_GPIO_NUM 25
#define HREF_GPIO_NUM 23
#define PCLK_GPIO_NUM 22

// --- VARIABLES GLOBALES ---
unsigned long ultimaPulsacion = 0;
const int debounceTime = 500;

// --- PROTOTIPOS ---
bool initCamera();
void conectarWiFi();
void capturarYEnviar();
void blinkLED(int veces, int ms);

void setup() {
  Serial.begin(115200);
  Serial.println("\n\n--- INICIANDO SISTEMA SENA v2.0 ---");

  pinMode(LED_FLASH, OUTPUT);
  pinMode(LED_STATUS, OUTPUT);
  digitalWrite(LED_FLASH, LOW);
  digitalWrite(LED_STATUS, HIGH); // Apagado (lógica invertida)

  if (BOTON_HABILITADO) {
    pinMode(BOTON_PIN, INPUT_PULLUP);
    Serial.println("✓ Botón habilitado en GPIO " + String(BOTON_PIN));
  }

  if (!initCamera()) {
    Serial.println("ERROR CRÍTICO: Fallo al inicializar cámara");
    while (true)
      blinkLED(1, 100);
  }
  Serial.println("✓ Cámara OK");

  conectarWiFi();

  Serial.println("--- SISTEMA LISTO ---");
  Serial.println(
      "Acciones: Presiona el botón o escribe 'FOTO' en el Monitor Serial.");
}

void loop() {
  // 1. Mantener WiFi conectado
  if (WiFi.status() != WL_CONNECTED) {
    conectarWiFi();
  }

  // 2. Escuchar comandos por Serial
  if (Serial.available() > 0) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();
    cmd.toUpperCase();
    if (cmd == "FOTO" || cmd == "1" || cmd == "SCAN") {
      capturarYEnviar();
    }
  }

  // 3. Lógica de botón físico
  if (BOTON_HABILITADO && digitalRead(BOTON_PIN) == LOW) {
    if (millis() - ultimaPulsacion > debounceTime) {
      ultimaPulsacion = millis();
      capturarYEnviar();
    }
  }
}

bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  // Ajustar según la RAM disponible
  if (psramFound()) {
    config.frame_size = FRAMESIZE_VGA;
    config.jpeg_quality = 10;
    config.fb_count = 2;
  } else {
    config.frame_size = FRAMESIZE_CIF;
    config.jpeg_quality = 12;
    config.fb_count = 1;
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK)
    return false;

  sensor_t *s = esp_camera_sensor_get();
  s->set_vflip(s, 1);   // Volteo vertical (necesario en muchos soportes)
  s->set_hmirror(s, 1); // Espejo horizontal
  return true;
}

void conectarWiFi() {
  if (WiFi.status() == WL_CONNECTED)
    return;

  Serial.print("Conectando WiFi...");
  WiFi.begin(ssid, password);

  int i = 0;
  while (WiFi.status() != WL_CONNECTED && i < 30) {
    delay(500);
    Serial.print(".");
    i++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n✓ Conectado. IP: " + WiFi.localIP().toString());
    blinkLED(2, 200);
  } else {
    Serial.println("\n✗ Error de conexión WiFi");
  }
}

void capturarYEnviar() {
  Serial.println("\n--- INICIANDO CAPTURA ---");

  if (USE_FLASH)
    digitalWrite(LED_FLASH, HIGH);
  delay(200); // Dar tiempo al sensor para ajustar la luz

  camera_fb_t *fb = esp_camera_fb_get();
  if (USE_FLASH)
    digitalWrite(LED_FLASH, LOW);

  if (!fb) {
    Serial.println("ERROR: Captura fallida");
    blinkLED(1, 1000);
    return;
  }

  Serial.printf("Imagen: %d bytes. Enviando...\n", fb->len);
  digitalWrite(LED_STATUS, LOW); // LED encendido durante el envío

  HTTPClient http;
  http.begin(serverUrl);
  http.setTimeout(30000);

  String boundary = "----ESP32Boundary" + String(random(1000, 9999));
  http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);

  // Construir cuerpo multipart
  String head =
      "--" + boundary +
      "\r\nContent-Disposition: form-data; name=\"ambient_id\"\r\n\r\n" +
      ambient_id + "\r\n";
  head += "--" + boundary +
          "\r\nContent-Disposition: form-data; name=\"imagen\"; "
          "filename=\"face.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n";
  String tail = "\r\n--" + boundary + "--\r\n";

  uint32_t totalLen = head.length() + fb->len + tail.length();
  http.addHeader("Content-Length", String(totalLen));

  int httpCode =
      http.sendRequest("POST", (uint8_t *)head.c_str(), head.length());

  // Si el envío por cabecera falla, usamos streaming
  if (httpCode == 0) {
    WiFiClient *client = http.getStreamPtr();
    client->write(fb->buf, fb->len);
    client->print(tail);
    httpCode = http.GET();
  }

  if (httpCode > 0) {
    String payload = http.getString();
    Serial.println("Servidor respondió (" + String(httpCode) + "):");
    Serial.println(payload);

    if (httpCode == 200) {
      if (payload.indexOf("\"coincidencia\":true") > 0) {
        Serial.println("✓ ACCESO CONCEDIDO");

        // Extraer nombre si existe
        int idx = payload.indexOf("\"nombre\":\"");
        if (idx > 0) {
          String nombre =
              payload.substring(idx + 10, payload.indexOf("\"", idx + 10));
          Serial.println("Bienvenido/a: " + nombre);
        }

        blinkLED(3, 100);
      } else if (payload.indexOf("MIN_TIME_NOT_MET") > 0) {
        Serial.println("⚠ ESPERA: Debes esperar un momento entre intentos.");
        blinkLED(2, 500);
      } else {
        Serial.println("✗ ACCESO DENEGADO");
        blinkLED(1, 1000);
      }
    } else {
      Serial.println("! Error HTTP en servidor");
      blinkLED(1, 2000);
    }
  } else {
    Serial.printf("✗ Error de red: %s\n", http.errorToString(httpCode).c_str());
  }

  http.end();
  esp_camera_fb_return(fb);
  digitalWrite(LED_STATUS, HIGH); // Apagar LED
}

void blinkLED(int veces, int ms) {
  for (int i = 0; i < veces; i++) {
    digitalWrite(LED_STATUS, LOW);
    delay(ms);
    digitalWrite(LED_STATUS, HIGH);
    delay(ms);
  }
}
