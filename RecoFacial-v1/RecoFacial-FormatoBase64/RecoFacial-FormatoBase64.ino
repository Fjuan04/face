#include <WiFi.h>
#include <HTTPClient.h>
#include "esp_camera.h"
#include <base64.h>

// ╔════════════════════════════════════════════════════════════════╗
// ║       ESP32-CAM RECONOCIMIENTO FACIAL v2.0                     ║
// ║       SENA - Sistema de Control de Acceso                      ║
// ╚════════════════════════════════════════════════════════════════╝

// --- CONFIGURACIÓN WIFI ---
const char* ssid     = "SSID";
const char* password = "PASSWORD";

// --- CONFIGURACIÓN SERVIDOR ---
// Apunta al endpoint POST de FaceRecognitionController@process
// Ejemplo: http://10.2.13.193/api/reconocer  (ajusta la IP y ruta de tu Laravel)
const char* serverUrl = "http://10.2.13.193/api/reconocer";

// --- INFORMACIÓN DEL SISTEMA ---
const char* VERSION      = "2.0";
const char* FECHA_VERSION = "12/03/2026";
const char* AUTOR        = "SENA - Centro de Procesos Industriales y Construccion";

// --- PINES ESP32-CAM (AI-THINKER) ---
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

// --- PINES DE CONTROL ---
#define LED_FLASH          4   // LED flash integrado
#define LED_STATUS        33   // LED de estado

// --- CONFIGURACIÓN DEL SISTEMA ---
#define WIFI_MIN_SIGNAL    -85     // Señal mínima aceptable (dBm)
#define HTTP_TIMEOUT       30000   // Timeout HTTP en ms (30 segundos)
#define MAX_RETRY_ENVIO    3       // Número máximo de reintentos
#define WIFI_MAX_INTENTOS  30      // Intentos de conexión WiFi

// --- VARIABLES GLOBALES ---
bool flashHabilitado = false;
unsigned long tiempoInicio = 0;
int capturasTotales = 0;
int capturasExitosas = 0;

// --- DECLARACIÓN DE FUNCIONES ---
bool initCamera();
void conectarWiFi();
void capturarYEnviar();
void mostrarAyuda();
void mostrarStatus();
void procesarComando(String cmd);
void ejecutarCaptura();
void testFlash();
void testLED();
void testCamara();
void pingServidor();
void cambiarCalidad(int calidad);
void cambiarResolucion(framesize_t tamaño);
void mostrarMemoria();
void mostrarUptime();
void modoContinuo(int intervalo);
void limpiarBufferCamara();
bool verificarConexion();
void diagnosticoRed();
bool testConexionServidor();
void mostrarBanner();
void mostrarEstadisticas();

// ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(1000);

  mostrarBanner();

  tiempoInicio = millis();

  pinMode(LED_FLASH, OUTPUT);
  pinMode(LED_STATUS, OUTPUT);
  digitalWrite(LED_FLASH, LOW);
  digitalWrite(LED_STATUS, LOW);

  Serial.println("Inicializando cámara...");
  if (!initCamera()) {
    Serial.println("✗ ERROR CRÍTICO: Fallo al inicializar la cámara");
    while (true) {
      digitalWrite(LED_STATUS, HIGH); delay(200);
      digitalWrite(LED_STATUS, LOW);  delay(200);
    }
  }
  Serial.println("✓ Cámara inicializada correctamente");

  conectarWiFi();

  Serial.println("\nVerificando conexión con servidor...");
  if (testConexionServidor()) {
    Serial.println("✓ Servidor accesible y funcionando");
  } else {
    Serial.println("⚠ ADVERTENCIA: No se puede alcanzar el servidor");
    Serial.println("  Verifica que el servidor esté corriendo en: " + String(serverUrl));
  }

  mostrarAyuda();
  digitalWrite(LED_STATUS, HIGH);

  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║    SISTEMA LISTO PARA OPERAR           ║");
  Serial.println("╚════════════════════════════════════════╝\n");
}

void loop() {
  // Verificación periódica de WiFi (cada 30 segundos)
  static unsigned long ultimaVerificacion = 0;
  if (millis() - ultimaVerificacion > 30000) {
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("\n⚠ WiFi desconectado. Reconectando...");
      conectarWiFi();
    }
    ultimaVerificacion = millis();
  }

  // Procesar comandos seriales
  if (Serial.available() > 0) {
    String comando = Serial.readStringUntil('\n');
    comando.trim();
    comando.toUpperCase();
    Serial.println("\n> " + comando);
    procesarComando(comando);
  }
}

// ─────────────────────────────────────────────────────────────────
// FUNCIÓN PRINCIPAL DE CAPTURA Y ENVÍO
// Envía JSON: { "ip": "<ip_esp32>", "imagen": "data:image/jpeg;base64,..." }
// Compatible con FaceRecognitionController@process de Laravel
// ─────────────────────────────────────────────────────────────────
void capturarYEnviar() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("✗ ERROR: WiFi desconectado");
    return;
  }

  Serial.println("  → Capturando imagen...");
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("✗ ERROR: Fallo al capturar imagen");
    return;
  }
  Serial.printf("  → Imagen capturada: %d bytes (%dx%d)\n", fb->len, fb->width, fb->height);

  // Codificar imagen a Base64
  Serial.println("  → Codificando imagen a Base64...");
  String imagenBase64 = base64::encode(fb->buf, fb->len);
  esp_camera_fb_return(fb);  // Liberar buffer de cámara lo antes posible

  if (imagenBase64.isEmpty()) {
    Serial.println("✗ ERROR: Fallo al codificar imagen en Base64");
    return;
  }
  Serial.printf("  → Base64 generado: %d caracteres\n", imagenBase64.length());

  // Construir JSON con el formato que espera el controlador PHP:
  // { "ip": "x.x.x.x", "imagen": "data:image/jpeg;base64,<datos>" }
  String ipESP32  = WiFi.localIP().toString();
  String jsonBody = "{\"ip\":\"" + ipESP32 + "\","
                    "\"imagen\":\"data:image/jpeg;base64," + imagenBase64 + "\"}";

  Serial.printf("  → JSON preparado (%d bytes). Enviando...\n", jsonBody.length());

  bool enviado = false;

  for (int intento = 1; intento <= MAX_RETRY_ENVIO && !enviado; intento++) {
    if (intento > 1) {
      Serial.printf("  → Reintento %d de %d...\n", intento, MAX_RETRY_ENVIO);
      delay(2000);
    }

    HTTPClient http;
    http.begin(serverUrl);
    http.setTimeout(HTTP_TIMEOUT);
    http.setReuse(false);

    // El controlador PHP espera Content-Type: application/json
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("Connection", "close");

    int httpCode = http.POST(jsonBody);

    if (httpCode > 0) {
      Serial.printf("  ✓ Respuesta HTTP: %d\n", httpCode);

      String respuesta = http.getString();
      Serial.println("\n  ═══ RESPUESTA DEL SERVIDOR ═══");
      Serial.println("  " + respuesta);

      if (httpCode == 200) {
        // ── Acceso concedido ──────────────────────────────────────
        if (respuesta.indexOf("\"coincidencia\":true") >= 0) {
          Serial.println("\n  ╔════════════════════════════════════════╗");
          Serial.println("  ║    ✓✓✓ ACCESO CONCEDIDO ✓✓✓            ║");

          // Extraer nombre si está disponible
          int idxNombre = respuesta.indexOf("\"nombre\":\"");
          if (idxNombre >= 0) {
            idxNombre += 10;
            int idxFin = respuesta.indexOf("\"", idxNombre);
            String nombre = respuesta.substring(idxNombre, idxFin);
            Serial.println("  ║    Bienvenido/a: " + nombre);
          }

          // Mostrar tipo de evento (entry / exit)
          int idxTipo = respuesta.indexOf("\"tipo_evento\":\"");
          if (idxTipo >= 0) {
            idxTipo += 15;
            int idxFin = respuesta.indexOf("\"", idxTipo);
            String tipoEvento = respuesta.substring(idxTipo, idxFin);
            String etiqueta = (tipoEvento == "entry") ? "ENTRADA" : "SALIDA";
            Serial.println("  ║    Evento registrado: " + etiqueta);
          }

          Serial.println("  ╚════════════════════════════════════════╝");

          // Indicación visual de éxito (parpadeo rápido)
          for (int i = 0; i < 6; i++) {
            digitalWrite(LED_STATUS, HIGH); delay(100);
            digitalWrite(LED_STATUS, LOW);  delay(100);
          }
          capturasExitosas++;

        // ── Tiempo mínimo no cumplido ─────────────────────────────
        } else if (respuesta.indexOf("MIN_TIME_NOT_MET") >= 0) {
          Serial.println("\n  ╔════════════════════════════════════════╗");
          Serial.println("  ║    ⚠ TIEMPO MÍNIMO NO CUMPLIDO         ║");

          int idxErr = respuesta.indexOf("\"error\":\"");
          if (idxErr >= 0) {
            idxErr += 9;
            int idxFin = respuesta.indexOf("\"", idxErr);
            Serial.println("  ║    " + respuesta.substring(idxErr, idxFin));
          }
          Serial.println("  ╚════════════════════════════════════════╝");

          // Parpadeo lento doble
          for (int i = 0; i < 4; i++) {
            digitalWrite(LED_STATUS, HIGH); delay(400);
            digitalWrite(LED_STATUS, LOW);  delay(400);
          }

        // ── Acceso denegado (no coincide) ─────────────────────────
        } else {
          Serial.println("\n  ╔════════════════════════════════════════╗");
          Serial.println("  ║    ✗✗✗ ACCESO DENEGADO ✗✗✗             ║");

          if (respuesta.indexOf("NO_FACE_DETECTED") >= 0) {
            Serial.println("  ║    Causa: No se detectó rostro          ║");
          } else if (respuesta.indexOf("\"coincidencia\":false") >= 0) {
            Serial.println("  ║    Causa: Rostro no registrado          ║");
          }
          Serial.println("  ╚════════════════════════════════════════╝");

          // Parpadeo lento
          for (int i = 0; i < 3; i++) {
            digitalWrite(LED_STATUS, HIGH); delay(300);
            digitalWrite(LED_STATUS, LOW);  delay(300);
          }
        }

        enviado = true;

      } else if (httpCode == 500) {
        // El controlador devuelve 500 con JSON de error detallado
        Serial.println("  ⚠ Error del servidor (500). Detalle:");
        int idxErr = respuesta.indexOf("\"error\":\"");
        if (idxErr >= 0) {
          idxErr += 9;
          int idxFin = respuesta.indexOf("\"", idxErr);
          Serial.println("  → " + respuesta.substring(idxErr, idxFin));
        }
      } else {
        Serial.printf("  ⚠ Código HTTP inesperado: %d\n", httpCode);
      }

    } else {
      Serial.printf("  ✗ Error HTTP: %s\n", http.errorToString(httpCode).c_str());
    }

    http.end();
  }

  if (!enviado) {
    Serial.println("\n  ╔════════════════════════════════════════╗");
    Serial.println("  ║  ⚠ NO SE PUDO ENVIAR LA IMAGEN         ║");
    Serial.println("  ╚════════════════════════════════════════╝");
    Serial.println("  Recomendaciones:");
    Serial.println("  1. Verifica que el servidor Laravel esté corriendo");
    Serial.println("  2. Confirma la URL en serverUrl: " + String(serverUrl));
    Serial.println("  3. Ejecuta PING para probar conectividad");
    Serial.println("  4. Ejecuta DIAG para diagnóstico completo");
    Serial.println("  5. Verifica que el Firewall no esté bloqueando");
  }
}

// ─────────────────────────────────────────────────────────────────
// RESTO DE FUNCIONES (sin cambios funcionales)
// ─────────────────────────────────────────────────────────────────

void mostrarBanner() {
  Serial.println("\n\n");
  Serial.println("╔════════════════════════════════════════════════════════════╗");
  Serial.println("║                                                            ║");
  Serial.println("║     ESP32-CAM SISTEMA DE RECONOCIMIENTO FACIAL v2.0        ║");
  Serial.println("║                                                            ║");
  Serial.println("║     SENA - Centro de Procesos Industriales y Construccion  ║");
  Serial.println("║     Sistema de Control de Acceso Biométrico                ║");
  Serial.println("║                                                            ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║  Versión: " + String(VERSION) + "                                             ║");
  Serial.println("║  Fecha:   " + String(FECHA_VERSION) + "                                   ║");
  Serial.println("║  Protocolo: JSON + Base64 (Laravel compatible)             ║");
  Serial.println("╚════════════════════════════════════════════════════════════╝");
  Serial.println();
}

void procesarComando(String cmd) {
  if (cmd == "CAPTURAR" || cmd == "FOTO" || cmd == "SCAN" || cmd == "1") {
    ejecutarCaptura();
  } else if (cmd == "STATUS" || cmd == "INFO") {
    mostrarStatus();
  } else if (cmd == "STATS" || cmd == "ESTADISTICAS") {
    mostrarEstadisticas();
  } else if (cmd == "DIAGNOSTICO" || cmd == "DIAG" || cmd == "NET") {
    diagnosticoRed();
  } else if (cmd == "HELP" || cmd == "AYUDA" || cmd == "?") {
    mostrarAyuda();
  } else if (cmd == "VERSION" || cmd == "VER") {
    Serial.println("Sistema de Reconocimiento Facial v" + String(VERSION));
    Serial.println("Fecha: " + String(FECHA_VERSION));
    Serial.println("Autor: " + String(AUTOR));
  } else if (cmd == "REBOOT" || cmd == "REINICIAR" || cmd == "RESET") {
    Serial.println("⚠ Reiniciando sistema...");
    delay(1000);
    ESP.restart();
  } else if (cmd == "WIFI" || cmd == "RECONECTAR") {
    Serial.println("Reconectando WiFi...");
    WiFi.disconnect();
    delay(1000);
    conectarWiFi();
  } else if (cmd == "FLASH ON" || cmd == "FLASH_ON") {
    flashHabilitado = true;
    Serial.println("✓ Flash habilitado");
  } else if (cmd == "FLASH OFF" || cmd == "FLASH_OFF") {
    flashHabilitado = false;
    Serial.println("✓ Flash deshabilitado");
  } else if (cmd == "FLASH TEST" || cmd == "FLASH_TEST") {
    testFlash();
  } else if (cmd == "LED ON" || cmd == "LED_ON") {
    digitalWrite(LED_STATUS, HIGH);
    Serial.println("✓ LED de estado encendido");
  } else if (cmd == "LED OFF" || cmd == "LED_OFF") {
    digitalWrite(LED_STATUS, LOW);
    Serial.println("✓ LED de estado apagado");
  } else if (cmd == "LED BLINK" || cmd == "LED_BLINK" || cmd == "LED TEST") {
    testLED();
  } else if (cmd == "CAM TEST" || cmd == "CAM_TEST" || cmd == "TEST") {
    testCamara();
  } else if (cmd == "PING") {
    pingServidor();
  } else if (cmd == "CALIDAD BAJA" || cmd == "CALIDAD_BAJA" || cmd == "LOW") {
    cambiarCalidad(25);
  } else if (cmd == "CALIDAD MEDIA" || cmd == "CALIDAD_MEDIA" || cmd == "MED") {
    cambiarCalidad(15);
  } else if (cmd == "CALIDAD ALTA" || cmd == "CALIDAD_ALTA" || cmd == "HIGH") {
    cambiarCalidad(10);
  } else if (cmd == "SIZE VGA" || cmd == "VGA") {
    cambiarResolucion(FRAMESIZE_VGA);
  } else if (cmd == "SIZE SVGA" || cmd == "SVGA") {
    cambiarResolucion(FRAMESIZE_SVGA);
  } else if (cmd == "SIZE HD" || cmd == "HD") {
    cambiarResolucion(FRAMESIZE_HD);
  } else if (cmd == "SIZE UXGA" || cmd == "UXGA") {
    cambiarResolucion(FRAMESIZE_UXGA);
  } else if (cmd == "MEM" || cmd == "MEMORIA" || cmd == "RAM") {
    mostrarMemoria();
  } else if (cmd == "UPTIME" || cmd == "TIEMPO") {
    mostrarUptime();
  } else if (cmd == "CLEAR" || cmd == "CLS") {
    for (int i = 0; i < 50; i++) Serial.println();
    mostrarBanner();
    mostrarAyuda();
  } else if (cmd.startsWith("AUTO ")) {
    int intervalo = cmd.substring(5).toInt();
    if (intervalo > 0) {
      modoContinuo(intervalo);
    } else {
      Serial.println("✗ ERROR: Intervalo inválido");
      Serial.println("  Uso correcto: AUTO 5 (captura cada 5 segundos)");
    }
  } else {
    Serial.println("✗ Comando no reconocido: '" + cmd + "'");
    Serial.println("  Escribe HELP para ver todos los comandos disponibles");
  }
}

bool testConexionServidor() {
  if (WiFi.status() != WL_CONNECTED) return false;
  HTTPClient http;
  http.begin(serverUrl);
  http.setTimeout(5000);
  int codigo = http.GET();
  http.end();
  // El endpoint POST devuelve 405 en GET, lo cual confirma que el servidor responde
  return (codigo > 0);
}

void limpiarBufferCamara() {
  Serial.println("  → Limpiando buffer de cámara...");
  for (int i = 0; i < 3; i++) {
    camera_fb_t* fb = esp_camera_fb_get();
    if (fb) esp_camera_fb_return(fb);
    delay(50);
  }
}

bool verificarConexion() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("  ✗ WiFi desconectado");
    return false;
  }
  int rssi = WiFi.RSSI();
  if (rssi < WIFI_MIN_SIGNAL) {
    Serial.printf("  ⚠ Señal WiFi débil: %d dBm\n", rssi);
  }
  return true;
}

void diagnosticoRed() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║              DIAGNÓSTICO COMPLETO DE RED                   ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");

  Serial.print("║ WiFi: ");
  Serial.println(WiFi.status() == WL_CONNECTED
    ? "✓ CONECTADO                                   ║"
    : "✗ DESCONECTADO                                ║");

  Serial.print("║ IP ESP32: ");
  String ip = WiFi.localIP().toString();
  Serial.print(ip);
  for (int i = ip.length(); i < 43; i++) Serial.print(" ");
  Serial.println("║");

  Serial.print("║ Gateway: ");
  String gw = WiFi.gatewayIP().toString();
  Serial.print(gw);
  for (int i = gw.length(); i < 44; i++) Serial.print(" ");
  Serial.println("║");

  int rssi = WiFi.RSSI();
  Serial.printf("║ Señal: %d dBm", rssi);
  if (rssi > -50) Serial.print(" (Excelente)");
  else if (rssi > -70) Serial.print(" (Buena)    ");
  else if (rssi > -85) Serial.print(" (Regular)  ");
  else Serial.print(" (Mala)     ");
  Serial.println("                              ║");

  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║ Endpoint: " + String(serverUrl));
  Serial.println("║ Protocolo: POST / application/json                         ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║ Probando conexión con servidor...                          ║");
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");

  pingServidor();
}

void ejecutarCaptura() {
  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║      INICIANDO CAPTURA                 ║");
  Serial.println("╚════════════════════════════════════════╝");

  capturasTotales++;
  digitalWrite(LED_STATUS, LOW);

  if (!verificarConexion()) {
    Serial.println("  → Intentando reconectar WiFi...");
    conectarWiFi();
    if (!verificarConexion()) {
      Serial.println("\n✗ CAPTURA CANCELADA: Sin conexión WiFi");
      digitalWrite(LED_STATUS, HIGH);
      return;
    }
  }

  limpiarBufferCamara();

  if (flashHabilitado) {
    Serial.println("  → Activando flash...");
    digitalWrite(LED_FLASH, HIGH);
    delay(150);
  }

  delay(100);
  capturarYEnviar();

  digitalWrite(LED_FLASH, LOW);
  digitalWrite(LED_STATUS, HIGH);

  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║      PROCESO COMPLETADO                ║");
  Serial.println("╚════════════════════════════════════════╝\n");
}

void mostrarAyuda() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║                 COMANDOS DISPONIBLES v2.0                  ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║  CAPTURA Y RECONOCIMIENTO                                  ║");
  Serial.println("║  • CAPTURAR, FOTO, SCAN, 1 → Capturar imagen               ║");
  Serial.println("║                                                            ║");
  Serial.println("║  INFORMACIÓN                                               ║");
  Serial.println("║  • STATUS, INFO    → Estado del sistema                    ║");
  Serial.println("║  • STATS           → Estadísticas de uso                   ║");
  Serial.println("║  • DIAG            → Diagnóstico completo de red           ║");
  Serial.println("║  • PING            → Probar conexión con servidor          ║");
  Serial.println("║  • MEM             → Información de memoria                ║");
  Serial.println("║  • UPTIME          → Tiempo de funcionamiento              ║");
  Serial.println("║  • VERSION         → Versión del sistema                   ║");
  Serial.println("║                                                            ║");
  Serial.println("║  CONFIGURACIÓN                                             ║");
  Serial.println("║  • FLASH ON/OFF    → Control del flash                     ║");
  Serial.println("║  • LED ON/OFF      → Control del LED de estado             ║");
  Serial.println("║  • CALIDAD BAJA/MEDIA/ALTA → Calidad de imagen            ║");
  Serial.println("║  • SIZE VGA/SVGA/HD/UXGA   → Resolución de imagen         ║");
  Serial.println("║                                                            ║");
  Serial.println("║  PRUEBAS                                                   ║");
  Serial.println("║  • CAM TEST        → Probar cámara                         ║");
  Serial.println("║  • FLASH TEST      → Probar flash                          ║");
  Serial.println("║  • LED TEST        → Probar LED de estado                  ║");
  Serial.println("║                                                            ║");
  Serial.println("║  SISTEMA                                                   ║");
  Serial.println("║  • WIFI            → Reconectar WiFi                       ║");
  Serial.println("║  • REBOOT          → Reiniciar ESP32-CAM                   ║");
  Serial.println("║  • CLEAR           → Limpiar pantalla                      ║");
  Serial.println("║  • AUTO [seg]      → Modo continuo (ej: AUTO 5)            ║");
  Serial.println("║  • HELP, ?         → Mostrar esta ayuda                    ║");
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void mostrarStatus() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║                 ESTADO DEL SISTEMA v2.0                    ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");

  Serial.print("║ WiFi: ");
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("✓ Conectado                                      ║");
    Serial.print("║ IP: ");
    String ip = WiFi.localIP().toString();
    Serial.print(ip);
    for (int i = ip.length(); i < 48; i++) Serial.print(" ");
    Serial.println("║");
    Serial.print("║ Señal: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm                                        ║");
  } else {
    Serial.println("✗ Desconectado                                   ║");
  }

  Serial.print("║ Flash: ");
  Serial.println(flashHabilitado
    ? "Habilitado                                        ║"
    : "Deshabilitado                                     ║");

  Serial.print("║ RAM Libre: ");
  Serial.print(ESP.getFreeHeap() / 1024);
  Serial.println(" KB                                        ║");

  Serial.println("║                                                            ║");
  Serial.println("║ Endpoint: " + String(serverUrl));
  Serial.println("║ Protocolo: POST / application/json + Base64                ║");

  unsigned long segundos = (millis() - tiempoInicio) / 1000;
  Serial.printf("║ Uptime: %luh %lum %lus                                    ║\n",
    segundos / 3600, (segundos % 3600) / 60, segundos % 60);

  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void mostrarEstadisticas() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║              ESTADÍSTICAS DE OPERACIÓN                     ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.printf("║ Capturas Totales:  %-39d ║\n", capturasTotales);
  Serial.printf("║ Capturas Exitosas: %-39d ║\n", capturasExitosas);
  if (capturasTotales > 0) {
    float tasa = (capturasExitosas * 100.0) / capturasTotales;
    Serial.printf("║ Tasa de Éxito: %.1f%%                                       ║\n", tasa);
  } else {
    Serial.println("║ Tasa de Éxito: N/A                                        ║");
  }
  unsigned long segundos = (millis() - tiempoInicio) / 1000;
  Serial.printf("║ Tiempo de Operación: %lu horas                              ║\n", segundos / 3600);
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void testFlash() {
  Serial.println("\nProbando flash LED...");
  for (int i = 0; i < 5; i++) {
    digitalWrite(LED_FLASH, HIGH); Serial.print("■"); delay(200);
    digitalWrite(LED_FLASH, LOW);  Serial.print(" "); delay(200);
  }
  Serial.println("\n✓ Test completado");
}

void testLED() {
  Serial.println("\nProbando LED de estado...");
  for (int i = 0; i < 5; i++) {
    digitalWrite(LED_STATUS, HIGH); Serial.print("●"); delay(200);
    digitalWrite(LED_STATUS, LOW);  Serial.print(" "); delay(200);
  }
  digitalWrite(LED_STATUS, HIGH);
  Serial.println("\n✓ Test completado");
}

void testCamara() {
  Serial.println("\nProbando cámara...");
  limpiarBufferCamara();
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("✗ ERROR: Fallo al capturar imagen de prueba");
    return;
  }
  Serial.printf("✓ Cámara funcionando correctamente\n");
  Serial.printf("  Tamaño: %d bytes\n", fb->len);
  Serial.printf("  Resolución: %dx%d\n", fb->width, fb->height);
  Serial.printf("  Formato: %s\n", fb->format == PIXFORMAT_JPEG ? "JPEG" : "Otro");
  esp_camera_fb_return(fb);
}

void pingServidor() {
  Serial.println("Probando conexión con servidor...");
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("✗ WiFi desconectado - No se puede hacer ping");
    return;
  }
  HTTPClient http;
  http.begin(serverUrl);
  http.setTimeout(5000);
  unsigned long inicio = millis();
  int codigo = http.GET();
  unsigned long tiempo = millis() - inicio;
  if (codigo > 0) {
    Serial.printf("✓ Servidor alcanzable: HTTP %d (Tiempo: %lu ms)\n", codigo, tiempo);
    // 405 = Method Not Allowed es esperado en un endpoint solo POST, confirma que el servidor responde
    if (codigo == 405) {
      Serial.println("  Estado: Endpoint activo (405 esperado en GET sobre ruta POST)");
    } else if (codigo == 200) {
      Serial.println("  Estado: Servidor respondiendo OK");
    }
  } else {
    Serial.printf("✗ Error de conexión: %s\n", http.errorToString(codigo).c_str());
    Serial.println("  Posibles causas:");
    Serial.println("  1. Servidor Laravel no está corriendo");
    Serial.println("  2. Firewall bloqueando el puerto");
    Serial.println("  3. URL incorrecta: " + String(serverUrl));
  }
  http.end();
}

void cambiarCalidad(int calidad) {
  sensor_t* s = esp_camera_sensor_get();
  if (s != NULL) {
    s->set_quality(s, calidad);
    Serial.printf("✓ Calidad de imagen ajustada a %d\n", calidad);
  } else {
    Serial.println("✗ ERROR: No se pudo acceder al sensor de la cámara");
  }
}

void cambiarResolucion(framesize_t tamaño) {
  sensor_t* s = esp_camera_sensor_get();
  if (s != NULL) {
    s->set_framesize(s, tamaño);
    String nombre;
    switch (tamaño) {
      case FRAMESIZE_VGA:  nombre = "VGA (640x480)";    break;
      case FRAMESIZE_SVGA: nombre = "SVGA (800x600)";   break;
      case FRAMESIZE_HD:   nombre = "HD (1280x720)";    break;
      case FRAMESIZE_UXGA: nombre = "UXGA (1600x1200)"; break;
      default:             nombre = "Desconocido";
    }
    Serial.println("✓ Resolución cambiada a: " + nombre);
  } else {
    Serial.println("✗ ERROR: No se pudo acceder al sensor de la cámara");
  }
}

void mostrarMemoria() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║              INFORMACIÓN DE MEMORIA                        ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  uint32_t ramTotal = ESP.getHeapSize() / 1024;
  uint32_t ramLibre = ESP.getFreeHeap() / 1024;
  uint32_t ramUsada = ramTotal - ramLibre;
  Serial.printf("║ RAM Total: %lu KB                                          ║\n", ramTotal);
  Serial.printf("║ RAM Libre: %lu KB                                          ║\n", ramLibre);
  Serial.printf("║ RAM Usada: %lu KB (%.1f%%)                                  ║\n",
    ramUsada, (ramUsada * 100.0) / ramTotal);
  Serial.print("║ PSRAM: ");
  if (psramFound()) {
    Serial.printf("Disponible (%lu KB libres)                    ║\n", ESP.getFreePsram() / 1024);
  } else {
    Serial.println("No disponible                                     ║");
  }
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void mostrarUptime() {
  unsigned long segundos = (millis() - tiempoInicio) / 1000;
  unsigned long minutos  = segundos / 60;
  unsigned long horas    = minutos / 60;
  unsigned long dias     = horas / 24;
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║              TIEMPO DE OPERACIÓN                           ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.printf("║ Días:     %-48lu ║\n", dias);
  Serial.printf("║ Horas:    %-48lu ║\n", horas % 24);
  Serial.printf("║ Minutos:  %-48lu ║\n", minutos % 60);
  Serial.printf("║ Segundos: %-48lu ║\n", segundos % 60);
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void modoContinuo(int intervalo) {
  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║      MODO CONTINUO ACTIVADO            ║");
  Serial.println("╚════════════════════════════════════════╝");
  Serial.printf("\nIntervalo: %d segundos\n", intervalo);
  Serial.println("Presiona CUALQUIER TECLA para detener\n");

  int contador = 1;
  while (true) {
    Serial.printf("\n═══ Captura Automática #%d ═══\n", contador);
    ejecutarCaptura();
    for (int i = 0; i < intervalo * 10; i++) {
      if (Serial.available() > 0) {
        Serial.read();
        Serial.println("\n╔════════════════════════════════════════╗");
        Serial.println("║      MODO CONTINUO DETENIDO            ║");
        Serial.println("╚════════════════════════════════════════╝\n");
        return;
      }
      delay(100);
    }
    contador++;
  }
}

bool initCamera() {
  camera_config_t config;

  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer   = LEDC_TIMER_0;
  config.pin_d0       = Y2_GPIO_NUM;
  config.pin_d1       = Y3_GPIO_NUM;
  config.pin_d2       = Y4_GPIO_NUM;
  config.pin_d3       = Y5_GPIO_NUM;
  config.pin_d4       = Y6_GPIO_NUM;
  config.pin_d5       = Y7_GPIO_NUM;
  config.pin_d6       = Y8_GPIO_NUM;
  config.pin_d7       = Y9_GPIO_NUM;
  config.pin_xclk     = XCLK_GPIO_NUM;
  config.pin_pclk     = PCLK_GPIO_NUM;
  config.pin_vsync    = VSYNC_GPIO_NUM;
  config.pin_href     = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn     = PWDN_GPIO_NUM;
  config.pin_reset    = RESET_GPIO_NUM;

  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  if (psramFound()) {
    config.frame_size   = FRAMESIZE_VGA;
    config.jpeg_quality = 15;
    config.fb_count     = 2;
    Serial.println("  PSRAM detectado - Usando configuración optimizada");
  } else {
    config.frame_size   = FRAMESIZE_VGA;
    config.jpeg_quality = 20;
    config.fb_count     = 1;
    Serial.println("  Sin PSRAM - Usando configuración básica");
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("  Error al inicializar cámara: 0x%x\n", err);
    return false;
  }

  sensor_t* s = esp_camera_sensor_get();
  if (s != NULL) {
    s->set_brightness(s, 0);
    s->set_contrast(s, 0);
    s->set_saturation(s, 0);
    s->set_special_effect(s, 0);
    s->set_whitebal(s, 1);
    s->set_awb_gain(s, 1);
    s->set_wb_mode(s, 0);
    s->set_exposure_ctrl(s, 1);
    s->set_aec2(s, 0);
    s->set_ae_level(s, 0);
    s->set_aec_value(s, 300);
    s->set_gain_ctrl(s, 1);
    s->set_agc_gain(s, 0);
    s->set_gainceiling(s, (gainceiling_t)0);
    s->set_bpc(s, 0);
    s->set_wpc(s, 1);
    s->set_raw_gma(s, 1);
    s->set_lenc(s, 1);
    s->set_hmirror(s, 0);
    s->set_vflip(s, 0);
    s->set_dcw(s, 1);
    s->set_colorbar(s, 0);
  }

  return true;
}

void conectarWiFi() {
  Serial.println("\nConectando a WiFi...");

  WiFi.mode(WIFI_STA);
  WiFi.setTxPower(WIFI_POWER_19_5dBm);
  WiFi.begin(ssid, password);

  int intentos = 0;
  while (WiFi.status() != WL_CONNECTED && intentos < WIFI_MAX_INTENTOS) {
    delay(500);
    Serial.print(".");
    intentos++;
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("✓ WiFi conectado exitosamente");
    Serial.print("  IP asignada: ");
    Serial.println(WiFi.localIP());
    int rssi = WiFi.RSSI();
    Serial.print("  Intensidad de señal: ");
    Serial.print(rssi);
    Serial.print(" dBm ");
    if (rssi > -50)      Serial.println("(Excelente)");
    else if (rssi > -70) Serial.println("(Buena)");
    else if (rssi > -85) Serial.println("(Regular)");
    else                 Serial.println("(Mala)");
  } else {
    Serial.println("✗ ERROR: No se pudo conectar al WiFi");
    Serial.print("  Verifica las credenciales para la red: ");
    Serial.println(ssid);
  }
}
