#include <WiFi.h>
#include <HTTPClient.h>
#include "esp_camera.h"
#include <base64.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h> // LiquidCrystal I2C de Frank de Brabander

// ╔════════════════════════════════════════════════════════════════╗
// ║       ESP32-CAM RECONOCIMIENTO FACIAL v3.0                     ║
// ║       SENA - Sistema de Control de Acceso                      ║
// ╚════════════════════════════════════════════════════════════════╝

// ════════════════════════════════════════════════════════════════
//  SWITCHES DE ACTIVACIÓN — cambia a false para deshabilitar
// ════════════════════════════════════════════════════════════════
#define LCD_HABILITADO    false   // Pantalla LCD 16x2 I2C (SDA=14, SCL=15)
#define BOTON_HABILITADO  true   // Botón físico / touch en GPIO 12

// ════════════════════════════════════════════════════════════════
//  MODO DEL BOTÓN
//    true  → Touch capacitivo: touchRead(pin) < TOUCH_UMBRAL
//    false → Botón físico con INPUT_PULLUP (LOW = pulsado)
// ════════════════════════════════════════════════════════════════
#define BOTON_TOUCH_MODE  true
#define TOUCH_UMBRAL      40    // Ajusta según tu hardware (usa "TOUCH TEST" por serial)

// --- CONFIGURACIÓN WIFI ---
const char* ssid     = "oppoa54";
const char* password = "12345678";

// --- CONFIGURACIÓN SERVIDOR ---
const char* serverUrl = "http://10.251.250.104/api/reconocer";

// --- INFORMACIÓN DEL SISTEMA ---
const char* VERSION       = "3.0";
const char* FECHA_VERSION = "12/03/2026";
const char* AUTOR         = "SENA - Centro de Procesos Industriales y Construccion";

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

// --- PINES LCD I2C ---
// SDA → GPIO 14  |  SCL → GPIO 15
#define LCD_SDA_PIN       14
#define LCD_SCL_PIN       15
#define LCD_I2C_ADDR      0x27  // Prueba 0x3F si no enciende
#define LCD_COLS          16
#define LCD_ROWS           2

// --- PIN BOTÓN / TOUCH ---
#define BOTON_PIN         12   // GPIO12 = Touch T5
#define BOTON_DEBOUNCE    300  // ms mínimos entre pulsaciones

// --- CONFIGURACIÓN DEL SISTEMA ---
#define WIFI_MIN_SIGNAL   -85
#define HTTP_TIMEOUT      30000
#define MAX_RETRY_ENVIO    3
#define WIFI_MAX_INTENTOS 30

// ════════════════════════════════════════════════════════════════
//  INSTANCIA LCD (compilada solo si LCD_HABILITADO)
// ════════════════════════════════════════════════════════════════
#if LCD_HABILITADO
  LiquidCrystal_I2C lcd(LCD_I2C_ADDR, LCD_COLS, LCD_ROWS);
#endif

// --- VARIABLES GLOBALES ---
bool flashHabilitado          = false;
unsigned long tiempoInicio    = 0;
int capturasTotales           = 0;
int capturasExitosas          = 0;
unsigned long ultimaPulsacion = 0;

// --- DECLARACIÓN DE FUNCIONES ---
bool  initCamera();
void  conectarWiFi();
void  capturarYEnviar();
void  mostrarAyuda();
void  mostrarStatus();
void  procesarComando(String cmd);
void  ejecutarCaptura();
void  testFlash();
void  testLED();
void  testCamara();
void  pingServidor();
void  cambiarCalidad(int calidad);
void  cambiarResolucion(framesize_t tamanio);
void  mostrarMemoria();
void  mostrarUptime();
void  modoContinuo(int intervalo);
void  limpiarBufferCamara();
bool  verificarConexion();
void  diagnosticoRed();
bool  testConexionServidor();
void  mostrarBanner();
void  mostrarEstadisticas();
void  lcdMensaje(const char* linea1, const char* linea2 = "");
void  lcdLimpiar();
bool  botonPresionado();

// ════════════════════════════════════════════════════════════════
//  SETUP
// ════════════════════════════════════════════════════════════════
void setup() {
  Serial.begin(115200);
  delay(1000);

  mostrarBanner();
  tiempoInicio = millis();

  pinMode(LED_FLASH, OUTPUT);
  pinMode(LED_STATUS, OUTPUT);
  digitalWrite(LED_FLASH, LOW);
  digitalWrite(LED_STATUS, LOW);

  // ── LCD ──────────────────────────────────────────────────────
#if LCD_HABILITADO
  Wire.begin(LCD_SDA_PIN, LCD_SCL_PIN);
  lcd.init();
  lcd.backlight();
  lcdMensaje("  SENA v3.0", " Iniciando...");
  Serial.println("✓ LCD iniciado (SDA=" + String(LCD_SDA_PIN) + " SCL=" + String(LCD_SCL_PIN) + ")");
#else
  Serial.println("i LCD deshabilitado (LCD_HABILITADO = false)");
#endif

  // ── BOTON ─────────────────────────────────────────────────────
#if BOTON_HABILITADO
  #if BOTON_TOUCH_MODE
    Serial.println("✓ Boton: TOUCH capacitivo en pin " + String(BOTON_PIN)
                   + " (umbral=" + String(TOUCH_UMBRAL) + ")");
  #else
    pinMode(BOTON_PIN, INPUT_PULLUP);
    Serial.println("✓ Boton: INPUT_PULLUP en pin " + String(BOTON_PIN));
  #endif
#else
  Serial.println("i Boton deshabilitado (BOTON_HABILITADO = false)");
#endif

  // ── CAMARA ───────────────────────────────────────────────────
  Serial.println("Inicializando camara...");
  lcdMensaje("Iniciando", "camara...");
  if (!initCamera()) {
    Serial.println("ERROR CRITICO: Fallo camara");
    lcdMensaje("ERROR CRITICO", "Fallo camara");
    while (true) {
      digitalWrite(LED_STATUS, HIGH); delay(200);
      digitalWrite(LED_STATUS, LOW);  delay(200);
    }
  }
  Serial.println("✓ Camara OK");

  // ── WIFI ─────────────────────────────────────────────────────
  lcdMensaje("Conectando", "WiFi...");
  conectarWiFi();

  // ── SERVIDOR ─────────────────────────────────────────────────
  lcdMensaje("Verificando", "servidor...");
  if (testConexionServidor()) {
    Serial.println("✓ Servidor accesible");
    lcdMensaje("Servidor OK", "");
  } else {
    Serial.println("ADVERTENCIA: Servidor no alcanzable");
    lcdMensaje("Servidor N/D", "Continua...");
    delay(2000);
  }

  mostrarAyuda();
  digitalWrite(LED_STATUS, HIGH);
  lcdMensaje("  Listo!", "Presiona boton");

  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║    SISTEMA LISTO PARA OPERAR           ║");
  Serial.println("╚════════════════════════════════════════╝\n");
}

// ════════════════════════════════════════════════════════════════
//  LOOP
// ════════════════════════════════════════════════════════════════
void loop() {
  // Reconexion WiFi cada 30 s
  static unsigned long ultimaVerif = 0;
  if (millis() - ultimaVerif > 30000) {
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("\nWiFi caido. Reconectando...");
      lcdMensaje("WiFi caido", "Reconectando..");
      conectarWiFi();
    }
    ultimaVerif = millis();
  }

  // Boton fisico o touch
#if BOTON_HABILITADO
  if (botonPresionado()) {
    Serial.println("\n[BOTON] Captura activada por hardware");
    ejecutarCaptura();
  }
#endif

  // Comandos seriales
  if (Serial.available() > 0) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();
    cmd.toUpperCase();
    Serial.println("\n> " + cmd);
    procesarComando(cmd);
  }
}

// ════════════════════════════════════════════════════════════════
//  LCD HELPERS
// ════════════════════════════════════════════════════════════════
void lcdMensaje(const char* linea1, const char* linea2) {
#if LCD_HABILITADO
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(linea1);
  if (linea2 && linea2[0] != '\0') {
    lcd.setCursor(0, 1);
    lcd.print(linea2);
  }
#endif
}

void lcdLimpiar() {
#if LCD_HABILITADO
  lcd.clear();
#endif
}

// ════════════════════════════════════════════════════════════════
//  BOTON — devuelve true una vez por pulsacion (debounce incluido)
// ════════════════════════════════════════════════════════════════
bool botonPresionado() {
#if !BOTON_HABILITADO
  return false;
#endif
  if (millis() - ultimaPulsacion < BOTON_DEBOUNCE) return false;

#if BOTON_TOUCH_MODE
  uint16_t val = touchRead(BOTON_PIN);
  if (val < TOUCH_UMBRAL) {
    ultimaPulsacion = millis();
    Serial.printf("[TOUCH] val=%d umbral=%d\n", val, TOUCH_UMBRAL);
    return true;
  }
#else
  if (digitalRead(BOTON_PIN) == LOW) {
    ultimaPulsacion = millis();
    return true;
  }
#endif
  return false;
}

// ════════════════════════════════════════════════════════════════
//  CAPTURA Y ENVIO
// ════════════════════════════════════════════════════════════════
void capturarYEnviar() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("ERROR: WiFi desconectado");
    lcdMensaje("Error WiFi", "Sin conexion");
    return;
  }

  Serial.println("  -> Capturando imagen...");
  lcdMensaje("Capturando...", "");
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("ERROR: Fallo captura");
    lcdMensaje("Error camara", "Fallo captura");
    return;
  }
  Serial.printf("  -> %d bytes (%dx%d)\n", fb->len, fb->width, fb->height);

  lcdMensaje("Procesando...", "Codificando");
  String imagenBase64 = base64::encode(fb->buf, fb->len);
  esp_camera_fb_return(fb);

  if (imagenBase64.isEmpty()) {
    Serial.println("ERROR: Fallo Base64");
    lcdMensaje("Error Base64", "");
    return;
  }

  String ipESP32  = WiFi.localIP().toString();
  String jsonBody = "{\"ip\":\"" + ipESP32 + "\","
                    "\"imagen\":\"data:image/jpeg;base64," + imagenBase64 + "\"}";

  Serial.printf("  -> JSON %d bytes. Enviando...\n", jsonBody.length());
  lcdMensaje("Enviando...", "");

  bool enviado = false;

  for (int intento = 1; intento <= MAX_RETRY_ENVIO && !enviado; intento++) {
    if (intento > 1) {
      Serial.printf("  -> Reintento %d/%d\n", intento, MAX_RETRY_ENVIO);
      lcdMensaje("Reintentando", ("Intento " + String(intento)).c_str());
      delay(2000);
    }

    HTTPClient http;
    http.begin(serverUrl);
    http.setTimeout(HTTP_TIMEOUT);
    http.setReuse(false);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept",       "application/json");
    http.addHeader("Connection",   "close");

    int httpCode = http.POST(jsonBody);

    if (httpCode > 0) {
      Serial.printf("  HTTP %d\n", httpCode);
      String resp = http.getString();
      Serial.println("  === RESPUESTA ===\n  " + resp);

      if (httpCode == 200) {

        // ACCESO CONCEDIDO
        if (resp.indexOf("\"coincidencia\":true") >= 0) {
          String nombre = "";
          int idx = resp.indexOf("\"nombre\":\"");
          if (idx >= 0) { idx += 10; nombre = resp.substring(idx, resp.indexOf("\"", idx)); }

          String tipo = "";
          idx = resp.indexOf("\"tipo_evento\":\"");
          if (idx >= 0) { idx += 15; tipo = resp.substring(idx, resp.indexOf("\"", idx)); }
          String etiqueta = (tipo == "entry") ? "ENTRADA" : (tipo == "exit" ? "SALIDA" : "OK");

          Serial.println("\n  ╔════════════════════════════════════╗");
          Serial.println("  ║  ACCESO CONCEDIDO                  ║");
          if (nombre.length() > 0) Serial.println("  ║  " + nombre);
          Serial.println("  ║  Evento: " + etiqueta);
          Serial.println("  ╚════════════════════════════════════╝");

          lcdMensaje("Bienvenido/a", nombre.length() > 0 ? nombre.c_str() : "Acceso OK");
          delay(1500);
          lcdMensaje(etiqueta.c_str(), "Registrado OK");

          for (int i = 0; i < 6; i++) {
            digitalWrite(LED_STATUS, HIGH); delay(100);
            digitalWrite(LED_STATUS, LOW);  delay(100);
          }
          capturasExitosas++;

        // TIEMPO MINIMO NO CUMPLIDO
        } else if (resp.indexOf("MIN_TIME_NOT_MET") >= 0) {
          String msgErr = "";
          int idx = resp.indexOf("\"error\":\"");
          if (idx >= 0) { idx += 9; msgErr = resp.substring(idx, resp.indexOf("\"", idx)); }
          Serial.println("  ADVERTENCIA: Tiempo minimo no cumplido");
          Serial.println("  " + msgErr);
          lcdMensaje("Espera...", "Tiempo minimo");
          for (int i = 0; i < 4; i++) {
            digitalWrite(LED_STATUS, HIGH); delay(400);
            digitalWrite(LED_STATUS, LOW);  delay(400);
          }

        // ACCESO DENEGADO
        } else {
          Serial.println("  ACCESO DENEGADO");
          if (resp.indexOf("NO_FACE_DETECTED") >= 0) {
            Serial.println("  Causa: sin rostro");
            lcdMensaje("DENEGADO", "Sin rostro");
          } else if (resp.indexOf("\"coincidencia\":false") >= 0) {
            Serial.println("  Causa: rostro no registrado");
            lcdMensaje("DENEGADO", "No registrado");
          } else {
            lcdMensaje("DENEGADO", "");
          }
          for (int i = 0; i < 3; i++) {
            digitalWrite(LED_STATUS, HIGH); delay(300);
            digitalWrite(LED_STATUS, LOW);  delay(300);
          }
        }

        enviado = true;

      } else if (httpCode == 500) {
        int idx = resp.indexOf("\"error\":\"");
        if (idx >= 0) { idx += 9; Serial.println("  Error 500: " + resp.substring(idx, resp.indexOf("\"", idx))); }
        lcdMensaje("Error 500", "Servidor");
      } else {
        Serial.printf("  HTTP inesperado: %d\n", httpCode);
        lcdMensaje("Error HTTP", String(httpCode).c_str());
      }
    } else {
      Serial.printf("  Fallo HTTP: %s\n", http.errorToString(httpCode).c_str());
      lcdMensaje("Sin respuesta", "Reintentando");
    }

    http.end();
  }

  if (!enviado) {
    Serial.println("  NO SE PUDO ENVIAR. URL: " + String(serverUrl));
    lcdMensaje("FALLO ENVIO", "Ver Serial");
    delay(2000);
  }

  // Restaurar pantalla de espera
  lcdMensaje("  Listo!", "Presiona boton");
}

// ════════════════════════════════════════════════════════════════
//  COMANDOS SERIALES
// ════════════════════════════════════════════════════════════════
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
    Serial.println("v" + String(VERSION) + " | " + String(FECHA_VERSION));
    Serial.println("LCD:" + String(LCD_HABILITADO ? "ON" : "OFF")
                 + " Boton:" + String(BOTON_HABILITADO ? "ON" : "OFF")
                 + " Touch:" + String(BOTON_TOUCH_MODE ? "SI" : "NO"));
  } else if (cmd == "TOUCH TEST" || cmd == "TOUCH") {
    Serial.println("Touch en pin " + String(BOTON_PIN) + " por 5s (Ctrl+C para salir):");
    for (int i = 0; i < 50; i++) {
      Serial.printf("  T[%d] = %d\n", BOTON_PIN, touchRead(BOTON_PIN));
      delay(100);
    }
  } else if (cmd == "REBOOT" || cmd == "REINICIAR" || cmd == "RESET") {
    lcdMensaje("Reiniciando...", ""); delay(1000); ESP.restart();
  } else if (cmd == "WIFI" || cmd == "RECONECTAR") {
    lcdMensaje("Reconectando", "WiFi...");
    WiFi.disconnect(); delay(1000); conectarWiFi();
  } else if (cmd == "FLASH ON"  || cmd == "FLASH_ON")   { flashHabilitado = true;  Serial.println("✓ Flash ON");  }
  else if   (cmd == "FLASH OFF" || cmd == "FLASH_OFF")  { flashHabilitado = false; Serial.println("✓ Flash OFF"); }
  else if   (cmd == "FLASH TEST"|| cmd == "FLASH_TEST") { testFlash(); }
  else if   (cmd == "LED ON"    || cmd == "LED_ON")     { digitalWrite(LED_STATUS, HIGH); Serial.println("✓ LED ON"); }
  else if   (cmd == "LED OFF"   || cmd == "LED_OFF")    { digitalWrite(LED_STATUS, LOW);  Serial.println("✓ LED OFF");}
  else if   (cmd == "LED TEST"  || cmd == "LED BLINK")  { testLED(); }
  else if   (cmd == "CAM TEST"  || cmd == "TEST")       { testCamara(); }
  else if   (cmd == "PING")                             { pingServidor(); }
  else if   (cmd == "CALIDAD BAJA"  || cmd == "LOW")    { cambiarCalidad(25); }
  else if   (cmd == "CALIDAD MEDIA" || cmd == "MED")    { cambiarCalidad(15); }
  else if   (cmd == "CALIDAD ALTA"  || cmd == "HIGH")   { cambiarCalidad(10); }
  else if   (cmd == "VGA")  { cambiarResolucion(FRAMESIZE_VGA);  }
  else if   (cmd == "SVGA") { cambiarResolucion(FRAMESIZE_SVGA); }
  else if   (cmd == "HD")   { cambiarResolucion(FRAMESIZE_HD);   }
  else if   (cmd == "UXGA") { cambiarResolucion(FRAMESIZE_UXGA); }
  else if   (cmd == "MEM"   || cmd == "RAM")    { mostrarMemoria(); }
  else if   (cmd == "UPTIME")                   { mostrarUptime();  }
  else if   (cmd == "CLEAR" || cmd == "CLS") {
    for (int i = 0; i < 50; i++) Serial.println();
    mostrarBanner(); mostrarAyuda();
  } else if (cmd.startsWith("AUTO ")) {
    int iv = cmd.substring(5).toInt();
    if (iv > 0) modoContinuo(iv);
    else Serial.println("Uso: AUTO 5 (cada 5 segundos)");
  } else {
    Serial.println("Comando no reconocido: '" + cmd + "'. Escribe HELP.");
  }
}

// ════════════════════════════════════════════════════════════════
//  FUNCIONES DE SOPORTE
// ════════════════════════════════════════════════════════════════

bool testConexionServidor() {
  if (WiFi.status() != WL_CONNECTED) return false;
  HTTPClient http;
  http.begin(serverUrl); http.setTimeout(5000);
  int c = http.GET(); http.end();
  return (c > 0);
}

void limpiarBufferCamara() {
  for (int i = 0; i < 3; i++) {
    camera_fb_t* fb = esp_camera_fb_get();
    if (fb) esp_camera_fb_return(fb);
    delay(50);
  }
}

bool verificarConexion() {
  if (WiFi.status() != WL_CONNECTED) return false;
  int rssi = WiFi.RSSI();
  if (rssi < WIFI_MIN_SIGNAL) Serial.printf("  Senal debil: %d dBm\n", rssi);
  return true;
}

void diagnosticoRed() {
  Serial.println("\n=== DIAGNOSTICO DE RED ===");
  Serial.println("WiFi:    " + String(WiFi.status() == WL_CONNECTED ? "CONECTADO" : "DESCONECTADO"));
  Serial.println("IP:      " + WiFi.localIP().toString());
  Serial.printf( "Senal:   %d dBm\n", WiFi.RSSI());
  Serial.println("Endpoint:" + String(serverUrl));
  Serial.println("LCD:     " + String(LCD_HABILITADO   ? "ON" : "OFF"));
  Serial.println("Boton:   " + String(BOTON_HABILITADO  ? "ON" : "OFF"));
  Serial.println("Touch:   " + String(BOTON_TOUCH_MODE  ? "SI" : "NO"));
  pingServidor();
}

void ejecutarCaptura() {
  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║      INICIANDO CAPTURA                 ║");
  Serial.println("╚════════════════════════════════════════╝");
  capturasTotales++;
  digitalWrite(LED_STATUS, LOW);

  if (!verificarConexion()) {
    lcdMensaje("Sin WiFi...", "Reconectando");
    conectarWiFi();
    if (!verificarConexion()) {
      Serial.println("CAPTURA CANCELADA: sin WiFi");
      lcdMensaje("CANCELADO", "Sin WiFi");
      digitalWrite(LED_STATUS, HIGH);
      return;
    }
  }

  limpiarBufferCamara();
  if (flashHabilitado) { digitalWrite(LED_FLASH, HIGH); delay(150); }
  delay(100);
  capturarYEnviar();
  digitalWrite(LED_FLASH, LOW);
  digitalWrite(LED_STATUS, HIGH);

  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║      PROCESO COMPLETADO                ║");
  Serial.println("╚════════════════════════════════════════╝\n");
}

void mostrarBanner() {
  Serial.println("\n\n");
  Serial.println("╔════════════════════════════════════════════════════════════╗");
  Serial.println("║   ESP32-CAM RECONOCIMIENTO FACIAL v3.0                     ║");
  Serial.println("║   SENA - Centro de Procesos Industriales y Construccion    ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║  LCD:   SDA=GPIO14  SCL=GPIO15  Addr=0x27                  ║");
  Serial.println("║  Boton: GPIO12  (Touch T5 / INPUT_PULLUP)                  ║");
  Serial.println("║  Switches: LCD=" + String(LCD_HABILITADO ? "ON " : "OFF")
               + "  BOTON=" + String(BOTON_HABILITADO ? "ON " : "OFF")
               + "  TOUCH=" + String(BOTON_TOUCH_MODE ? "SI" : "NO") + "                    ║");
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void mostrarAyuda() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║                 COMANDOS v3.0                              ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║  CAPTURAR / FOTO / SCAN / 1  → Capturar imagen             ║");
  Serial.println("║  STATUS | STATS | DIAG | PING | MEM | UPTIME | VERSION     ║");
  Serial.println("║  FLASH ON/OFF | LED ON/OFF | CALIDAD BAJA/MEDIA/ALTA        ║");
  Serial.println("║  VGA | SVGA | HD | UXGA  → Cambiar resolucion              ║");
  Serial.println("║  TOUCH TEST  → Leer valor capacitivo del pin 12             ║");
  Serial.println("║  AUTO [seg]  → Modo continuo  |  WIFI  |  REBOOT           ║");
  Serial.println("║  HELP / ?    → Esta ayuda                                  ║");
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void mostrarStatus() {
  Serial.println("\n=== STATUS v3.0 ===");
  Serial.println("WiFi:  " + String(WiFi.status() == WL_CONNECTED ? "OK - " + WiFi.localIP().toString() : "DESCONECTADO"));
  Serial.printf( "Senal: %d dBm | RAM libre: %lu KB\n", WiFi.RSSI(), ESP.getFreeHeap() / 1024);
  Serial.println("Flash: " + String(flashHabilitado ? "ON" : "OFF")
               + "  LCD: "  + String(LCD_HABILITADO  ? "ON" : "OFF")
               + "  Boton:" + String(BOTON_HABILITADO ? "ON" : "OFF")
               + "  Touch:" + String(BOTON_TOUCH_MODE ? "SI" : "NO"));
  unsigned long s = (millis() - tiempoInicio) / 1000;
  Serial.printf("Uptime: %luh %lum %lus\n", s/3600, (s%3600)/60, s%60);
}

void mostrarEstadisticas() {
  Serial.println("\n=== ESTADISTICAS ===");
  Serial.printf("Totales: %d | Exitosas: %d", capturasTotales, capturasExitosas);
  if (capturasTotales > 0)
    Serial.printf(" | Tasa: %.1f%%", (capturasExitosas * 100.0) / capturasTotales);
  Serial.println();
}

void testFlash() {
  Serial.println("Test flash...");
  for (int i = 0; i < 5; i++) {
    digitalWrite(LED_FLASH, HIGH); delay(200);
    digitalWrite(LED_FLASH, LOW);  delay(200);
  }
  Serial.println("✓ OK");
}

void testLED() {
  Serial.println("Test LED...");
  for (int i = 0; i < 5; i++) {
    digitalWrite(LED_STATUS, HIGH); delay(200);
    digitalWrite(LED_STATUS, LOW);  delay(200);
  }
  digitalWrite(LED_STATUS, HIGH);
  Serial.println("✓ OK");
}

void testCamara() {
  limpiarBufferCamara();
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) { Serial.println("ERROR camara"); return; }
  Serial.printf("✓ Camara OK: %d bytes, %dx%d\n", fb->len, fb->width, fb->height);
  esp_camera_fb_return(fb);
}

void pingServidor() {
  if (WiFi.status() != WL_CONNECTED) { Serial.println("Sin WiFi"); return; }
  HTTPClient http;
  http.begin(serverUrl); http.setTimeout(5000);
  unsigned long t = millis();
  int c = http.GET();
  t = millis() - t;
  if (c > 0) Serial.printf("Servidor: HTTP %d en %lu ms%s\n", c, t,
    c == 405 ? " (405=OK, endpoint solo-POST)" : "");
  else Serial.printf("Sin respuesta: %s\n", http.errorToString(c).c_str());
  http.end();
}

void cambiarCalidad(int q) {
  sensor_t* s = esp_camera_sensor_get();
  if (s) { s->set_quality(s, q); Serial.printf("✓ Calidad: %d\n", q); }
}

void cambiarResolucion(framesize_t t) {
  sensor_t* s = esp_camera_sensor_get();
  if (!s) return;
  s->set_framesize(s, t);
  const char* n;
  switch (t) {
    case FRAMESIZE_VGA:  n = "VGA 640x480";    break;
    case FRAMESIZE_SVGA: n = "SVGA 800x600";   break;
    case FRAMESIZE_HD:   n = "HD 1280x720";    break;
    case FRAMESIZE_UXGA: n = "UXGA 1600x1200"; break;
    default:             n = "Desconocida";
  }
  Serial.printf("✓ Resolucion: %s\n", n);
}

void mostrarMemoria() {
  Serial.printf("RAM: %lu/%lu KB | PSRAM: %s\n",
    ESP.getFreeHeap() / 1024, ESP.getHeapSize() / 1024,
    psramFound() ? (String(ESP.getFreePsram() / 1024) + " KB libres").c_str() : "no disponible");
}

void mostrarUptime() {
  unsigned long s = (millis() - tiempoInicio) / 1000;
  Serial.printf("Uptime: %lu dias %luh %lum %lus\n", s/86400, (s%86400)/3600, (s%3600)/60, s%60);
}

void modoContinuo(int intervalo) {
  Serial.printf("MODO CONTINUO: cada %d s. Cualquier tecla detiene.\n", intervalo);
  lcdMensaje("Modo continuo", (String(intervalo) + "s").c_str());
  int n = 1;
  while (true) {
    Serial.printf("\n=== Auto #%d ===\n", n++);
    ejecutarCaptura();
    for (int i = 0; i < intervalo * 10; i++) {
      if (Serial.available() > 0) {
        Serial.read();
        Serial.println("MODO CONTINUO DETENIDO");
        lcdMensaje("  Listo!", "Presiona boton");
        return;
      }
      delay(100);
    }
  }
}

bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0; config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM; config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM; config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM; config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM; config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM; config.pin_pclk  = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM; config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM; config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM; config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  if (psramFound()) { config.frame_size = FRAMESIZE_VGA; config.jpeg_quality = 15; config.fb_count = 2; }
  else              { config.frame_size = FRAMESIZE_VGA; config.jpeg_quality = 20; config.fb_count = 1; }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) { Serial.printf("Error camara: 0x%x\n", err); return false; }

  sensor_t* s = esp_camera_sensor_get();
  if (s) {
    s->set_brightness(s,0); s->set_contrast(s,0);    s->set_saturation(s,0);
    s->set_special_effect(s,0); s->set_whitebal(s,1); s->set_awb_gain(s,1);
    s->set_wb_mode(s,0); s->set_exposure_ctrl(s,1);   s->set_aec2(s,0);
    s->set_ae_level(s,0); s->set_aec_value(s,300);    s->set_gain_ctrl(s,1);
    s->set_agc_gain(s,0); s->set_gainceiling(s,(gainceiling_t)0);
    s->set_bpc(s,0); s->set_wpc(s,1); s->set_raw_gma(s,1); s->set_lenc(s,1);
    s->set_hmirror(s,0); s->set_vflip(s,0); s->set_dcw(s,1); s->set_colorbar(s,0);
  }
  return true;
}

void conectarWiFi() {
  Serial.println("\nConectando WiFi...");
  WiFi.mode(WIFI_STA);
  WiFi.setTxPower(WIFI_POWER_19_5dBm);
  WiFi.begin(ssid, password);
  int intentos = 0;
  while (WiFi.status() != WL_CONNECTED && intentos < WIFI_MAX_INTENTOS) {
    delay(500); Serial.print("."); intentos++;
  }
  Serial.println();
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("✓ WiFi OK: " + WiFi.localIP().toString());
    lcdMensaje("WiFi OK", WiFi.localIP().toString().c_str());
  } else {
    Serial.println("ERROR WiFi. SSID: " + String(ssid));
    lcdMensaje("WiFi FALLO", "Sin conexion");
  }
  delay(800);
}
