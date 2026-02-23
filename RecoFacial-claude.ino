#include <WiFi.h>
#include <HTTPClient.h>
#include "esp_camera.h"

// ╔════════════════════════════════════════════════════════════════╗
// ║       ESP32-CAM RECONOCIMIENTO FACIAL v2.0                     ║
// ║       FACE - Sistema de Control de Acceso Biométrico           ║
// ║       Adaptado para Laravel con Reconocimiento Facial          ║
// ╚════════════════════════════════════════════════════════════════╝

// ╔════════════════════════════════════════════════════════════════╗
// ║              VARIABLES CONFIGURABLES                           ║
// ║         ⚠ EDITA ESTOS VALORES SEGÚN TU ENTORNO ⚠             ║
// ╚════════════════════════════════════════════════════════════════╝

// --- CONFIGURACIÓN WIFI ---
const char* WIFI_SSID = "Funcionarios";           // Tu SSID WiFi
const char* WIFI_PASSWORD = "SomosSena_2025";     // Tu contraseña WiFi

// --- CONFIGURACIÓN SERVIDOR LARAVEL ---
const char* SERVER_IP = "192.168.1.100";          // IP del servidor Laravel
const int SERVER_PORT = 8000;                     // Puerto del servidor (8000 para php artisan serve, 80 para producción)
const char* SERVER_URL = "/api/recognize";        // Endpoint API (sin trailing slash)

// --- INFORMACIÓN DEL SISTEMA ---
const char* VERSION = "2.0";
const char* FECHA_VERSION = "23/02/2026";
const char* AUTOR = "FACE - Sistema de Control de Acceso Biométrico";

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

void setup() {
  Serial.begin(115200);
  delay(1000);
  
  mostrarBanner();
  
  tiempoInicio = millis();

  // Configurar pines
  pinMode(LED_FLASH, OUTPUT);
  pinMode(LED_STATUS, OUTPUT);
  digitalWrite(LED_FLASH, LOW);
  digitalWrite(LED_STATUS, LOW);

  // Inicializar cámara
  Serial.println("Inicializando cámara...");
  if (!initCamera()) {
    Serial.println("✗ ERROR CRÍTICO: Fallo al inicializar la cámara");
    Serial.println("  Verifica las conexiones y reinicia el dispositivo");
    while (true) {
      digitalWrite(LED_STATUS, HIGH);
      delay(200);
      digitalWrite(LED_STATUS, LOW);
      delay(200);
    }
  }
  Serial.println("✓ Cámara inicializada correctamente");

  // Conectar WiFi
  conectarWiFi();
  
  // Verificar servidor
  Serial.println("\nVerificando conexión con servidor...");
  if (testConexionServidor()) {
    Serial.println("✓ Servidor accesible y funcionando");
  } else {
    Serial.println("⚠ ADVERTENCIA: No se puede alcanzar el servidor");
    Serial.println("  El sistema continuará, pero las capturas pueden fallar");
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

void mostrarBanner() {
  Serial.println("\n\n");
  Serial.println("╔════════════════════════════════════════════════════════════╗");
  Serial.println("║                                                            ║");
  Serial.println("║     ESP32-CAM SISTEMA DE RECONOCIMIENTO FACIAL v1.0        ║");
  Serial.println("║                                                            ║");
  Serial.println("║     SENA - Centro de Procesos Instrudiares y Construccion  ║");
  Serial.println("║     Sistema de Control de Acceso Biométrico                ║");
  Serial.println("║                                                            ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║  Versión: " + String(VERSION) + "                                             ║");
  Serial.println("║  Fecha: " + String(FECHA_VERSION) + "                                     ║");
  Serial.println("║  Red: Funcionarios                                         ║");
  Serial.println("╚════════════════════════════════════════════════════════════╝");
  Serial.println();
}

void procesarComando(String cmd) {
  // ===== CAPTURA DE IMAGEN =====
  if (cmd == "CAPTURAR" || cmd == "FOTO" || cmd == "SCAN" || cmd == "1") {
    ejecutarCaptura();
  }
  
  // ===== INFORMACIÓN DEL SISTEMA =====
  else if (cmd == "STATUS" || cmd == "INFO") {
    mostrarStatus();
  }
  else if (cmd == "STATS" || cmd == "ESTADISTICAS") {
    mostrarEstadisticas();
  }
  else if (cmd == "DIAGNOSTICO" || cmd == "DIAG" || cmd == "NET") {
    diagnosticoRed();
  }
  
  // ===== AYUDA =====
  else if (cmd == "HELP" || cmd == "AYUDA" || cmd == "?") {
    mostrarAyuda();
  }
  else if (cmd == "VERSION" || cmd == "VER") {
    Serial.println("Sistema de Reconocimiento Facial v" + String(VERSION));
    Serial.println("Fecha: " + String(FECHA_VERSION));
    Serial.println("Autor: " + String(AUTOR));
  }
  
  // ===== CONTROL DEL SISTEMA =====
  else if (cmd == "REBOOT" || cmd == "REINICIAR" || cmd == "RESET") {
    Serial.println("⚠ Reiniciando sistema...");
    delay(1000);
    ESP.restart();
  }
  else if (cmd == "WIFI" || cmd == "RECONECTAR") {
    Serial.println("Reconectando WiFi...");
    WiFi.disconnect();
    delay(1000);
    conectarWiFi();
  }
  
  // ===== CONTROL DE HARDWARE =====
  else if (cmd == "FLASH ON" || cmd == "FLASH_ON") {
    flashHabilitado = true;
    Serial.println("✓ Flash habilitado");
  }
  else if (cmd == "FLASH OFF" || cmd == "FLASH_OFF") {
    flashHabilitado = false;
    Serial.println("✓ Flash deshabilitado");
  }
  else if (cmd == "FLASH TEST" || cmd == "FLASH_TEST") {
    testFlash();
  }
  else if (cmd == "LED ON" || cmd == "LED_ON") {
    digitalWrite(LED_STATUS, HIGH);
    Serial.println("✓ LED de estado encendido");
  }
  else if (cmd == "LED OFF" || cmd == "LED_OFF") {
    digitalWrite(LED_STATUS, LOW);
    Serial.println("✓ LED de estado apagado");
  }
  else if (cmd == "LED BLINK" || cmd == "LED_BLINK" || cmd == "LED TEST") {
    testLED();
  }
  
  // ===== PRUEBAS =====
  else if (cmd == "CAM TEST" || cmd == "CAM_TEST" || cmd == "TEST") {
    testCamara();
  }
  else if (cmd == "PING") {
    pingServidor();
  }
  
  // ===== CONFIGURACIÓN DE CALIDAD =====
  else if (cmd == "CALIDAD BAJA" || cmd == "CALIDAD_BAJA" || cmd == "LOW") {
    cambiarCalidad(25);
  }
  else if (cmd == "CALIDAD MEDIA" || cmd == "CALIDAD_MEDIA" || cmd == "MED") {
    cambiarCalidad(15);
  }
  else if (cmd == "CALIDAD ALTA" || cmd == "CALIDAD_ALTA" || cmd == "HIGH") {
    cambiarCalidad(10);
  }
  
  // ===== CONFIGURACIÓN DE RESOLUCIÓN =====
  else if (cmd == "SIZE VGA" || cmd == "VGA") {
    cambiarResolucion(FRAMESIZE_VGA);
  }
  else if (cmd == "SIZE SVGA" || cmd == "SVGA") {
    cambiarResolucion(FRAMESIZE_SVGA);
  }
  else if (cmd == "SIZE HD" || cmd == "HD") {
    cambiarResolucion(FRAMESIZE_HD);
  }
  else if (cmd == "SIZE UXGA" || cmd == "UXGA") {
    cambiarResolucion(FRAMESIZE_UXGA);
  }
  
  // ===== INFORMACIÓN DEL SISTEMA =====
  else if (cmd == "MEM" || cmd == "MEMORIA" || cmd == "RAM") {
    mostrarMemoria();
  }
  else if (cmd == "UPTIME" || cmd == "TIEMPO") {
    mostrarUptime();
  }
  
  // ===== UTILIDADES =====
  else if (cmd == "CLEAR" || cmd == "CLS") {
    for(int i = 0; i < 50; i++) Serial.println();
    mostrarBanner();
    mostrarAyuda();
  }
  
  // ===== MODO CONTINUO =====
  else if (cmd.startsWith("AUTO ")) {
    int intervalo = cmd.substring(5).toInt();
    if (intervalo > 0) {
      modoContinuo(intervalo);
    } else {
      Serial.println("✗ ERROR: Intervalo inválido");
      Serial.println("  Uso correcto: AUTO 5 (captura cada 5 segundos)");
    }
  }
  
  // ===== COMANDO NO RECONOCIDO =====
  else {
    Serial.println("✗ Comando no reconocido: '" + cmd + "'");
    Serial.println("  Escribe HELP para ver todos los comandos disponibles");
  }
}

bool testConexionServidor() {
  if (WiFi.status() != WL_CONNECTED) {
    return false;
  }
  
  HTTPClient http;
  http.begin(serverUrl);
  http.setTimeout(5000);
  
  int codigo = http.GET();
  http.end();
  
  return (codigo > 0 && codigo < 400);
}

void limpiarBufferCamara() {
  Serial.println("  → Limpiando buffer de cámara...");
  for(int i = 0; i < 3; i++) {
    camera_fb_t* fb = esp_camera_fb_get();
    if (fb) {
      esp_camera_fb_return(fb);
    }
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
    Serial.printf("  ⚠ Señal WiFi débil: %d dBm (mínimo recomendado: %d dBm)\n", rssi, WIFI_MIN_SIGNAL);
    Serial.println("  → Sugerencia: Acerca la ESP32-CAM al router");
  }
  
  return true;
}

void diagnosticoRed() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║              DIAGNÓSTICO COMPLETO DE RED                   ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  
  // Estado WiFi
  Serial.print("║ WiFi: ");
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("✓ CONECTADO                                   ║");
  } else {
    Serial.println("✗ DESCONECTADO                                ║");
  }
  
  // SSID
  Serial.println("║ SSID: Funcionarios                                         ║");
  
  // IP de la ESP32
  Serial.print("║ IP ESP32: ");
  String ip = WiFi.localIP().toString();
  Serial.print(ip);
  for(int i = ip.length(); i < 43; i++) Serial.print(" ");
  Serial.println("║");
  
  // Gateway
  Serial.print("║ Gateway: ");
  String gw = WiFi.gatewayIP().toString();
  Serial.print(gw);
  for(int i = gw.length(); i < 44; i++) Serial.print(" ");
  Serial.println("║");
  
  // DNS
  Serial.print("║ DNS: ");
  String dns = WiFi.dnsIP().toString();
  Serial.print(dns);
  for(int i = dns.length(); i < 48; i++) Serial.print(" ");
  Serial.println("║");
  
  // Señal WiFi
  int rssi = WiFi.RSSI();
  Serial.print("║ Señal: ");
  Serial.print(rssi);
  Serial.print(" dBm ");
  if (rssi > -50) Serial.print("(Excelente)");
  else if (rssi > -70) Serial.print("(Buena)    ");
  else if (rssi > -85) Serial.print("(Regular)  ");
  else Serial.print("(Mala)     ");
  Serial.println("                              ║");
  
  // Canal WiFi
  Serial.print("║ Canal WiFi: ");
  Serial.print(WiFi.channel());
  Serial.println("                                              ║");
  
  // MAC Address
  Serial.print("║ MAC: ");
  Serial.print(WiFi.macAddress());
  Serial.println("                                  ║");
  
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  
  // Información del servidor
  Serial.println("║ Servidor: 10.2.13.193                                      ║");
  Serial.println("║ Ruta: /face-vanilla-v2/server/index.php                    ║");
  
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║ Probando conexión con servidor...                         ║");
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
  
  pingServidor();
}

void ejecutarCaptura() {
  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║      INICIANDO CAPTURA                 ║");
  Serial.println("╚════════════════════════════════════════╝");
  
  capturasTotales++;
  digitalWrite(LED_STATUS, LOW);
  
  // Verificar conexión WiFi
  if (!verificarConexion()) {
    Serial.println("  → Intentando reconectar WiFi...");
    conectarWiFi();
    if (!verificarConexion()) {
      Serial.println("\n✗ CAPTURA CANCELADA: Sin conexión WiFi");
      digitalWrite(LED_STATUS, HIGH);
      return;
    }
  }
  
  // Limpiar buffer de la cámara
  limpiarBufferCamara();
  
  // Activar flash si está habilitado
  if (flashHabilitado) {
    Serial.println("  → Activando flash...");
    digitalWrite(LED_FLASH, HIGH);
    delay(150);
  }
  
  // Pequeña pausa para estabilizar la imagen
  delay(100);
  
  // Capturar y enviar imagen
  capturarYEnviar();
  
  // Apagar flash
  digitalWrite(LED_FLASH, LOW);
  
  digitalWrite(LED_STATUS, HIGH);
  Serial.println("\n╔════════════════════════════════════════╗");
  Serial.println("║      PROCESO COMPLETADO                ║");
  Serial.println("╚════════════════════════════════════════╝\n");
}

void mostrarAyuda() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║                 COMANDOS DISPONIBLES v1.0                  ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.println("║                                                            ║");
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
  Serial.println("║  • SIZE VGA/SVGA   → Resolución (VGA recomendado)         ║");
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
  Serial.println("║                                                            ║");
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void mostrarStatus() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║                 ESTADO DEL SISTEMA v1.0                    ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  
  // Estado WiFi
  Serial.print("║ WiFi: ");
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("✓ Conectado a 'Funcionarios'                  ║");
    
    // IP
    Serial.print("║ IP: ");
    String ip = WiFi.localIP().toString();
    Serial.print(ip);
    for(int i = ip.length(); i < 48; i++) Serial.print(" ");
    Serial.println("║");
    
    // Señal
    int rssi = WiFi.RSSI();
    Serial.print("║ Señal: ");
    Serial.print(rssi);
    Serial.print(" dBm");
    for(int i = String(rssi).length(); i < 44; i++) Serial.print(" ");
    Serial.println("║");
  } else {
    Serial.println("✗ Desconectado                                   ║");
  }
  
  // Estado del flash
  Serial.print("║ Flash: ");
  Serial.println(flashHabilitado ? "Habilitado                                        ║" : "Deshabilitado                                     ║");
  
  // Memoria
  Serial.print("║ RAM Libre: ");
  Serial.print(ESP.getFreeHeap() / 1024);
  Serial.println(" KB                                        ║");
  
  // Servidor
  Serial.println("║                                                            ║");
  Serial.println("║ Servidor: 10.2.13.193                                      ║");
  Serial.println("║ Puerto: 80                                                 ║");
  
  // Uptime
  unsigned long segundos = (millis() - tiempoInicio) / 1000;
  unsigned long minutos = segundos / 60;
  unsigned long horas = minutos / 60;
  Serial.print("║ Uptime: ");
  Serial.print(horas);
  Serial.print("h ");
  Serial.print(minutos % 60);
  Serial.print("m ");
  Serial.print(segundos % 60);
  Serial.println("s                                          ║");
  
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void mostrarEstadisticas() {
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║              ESTADÍSTICAS DE OPERACIÓN                     ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.printf("║ Capturas Totales: %-40d ║\n", capturasTotales);
  Serial.printf("║ Capturas Exitosas: %-39d ║\n", capturasExitosas);
  
  if (capturasTotales > 0) {
    float tasa = (capturasExitosas * 100.0) / capturasTotales;
    Serial.printf("║ Tasa de Éxito: %.1f%%                                       ║\n", tasa);
  } else {
    Serial.println("║ Tasa de Éxito: N/A                                        ║");
  }
  
  unsigned long segundos = (millis() - tiempoInicio) / 1000;
  unsigned long horas = segundos / 3600;
  Serial.printf("║ Tiempo de Operación: %lu horas                              ║\n", horas);
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void testFlash() {
  Serial.println("\nProbando flash LED...");
  for(int i = 0; i < 5; i++) {
    digitalWrite(LED_FLASH, HIGH);
    Serial.print("■");
    delay(200);
    digitalWrite(LED_FLASH, LOW);
    Serial.print(" ");
    delay(200);
  }
  Serial.println("\n✓ Test completado");
}

void testLED() {
  Serial.println("\nProbando LED de estado...");
  for(int i = 0; i < 5; i++) {
    digitalWrite(LED_STATUS, HIGH);
    Serial.print("●");
    delay(200);
    digitalWrite(LED_STATUS, LOW);
    Serial.print(" ");
    delay(200);
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
    if (codigo == 200) {
      Serial.println("  Estado: Servidor funcionando correctamente");
    }
  } else {
    Serial.printf("✗ Error de conexión: %s\n", http.errorToString(codigo).c_str());
    Serial.println("\n  Posibles causas:");
    Serial.println("  1. Servidor PHP no está corriendo en 10.2.13.193");
    Serial.println("  2. Firewall bloqueando el puerto 80");
    Serial.println("  3. ESP32 y servidor en diferentes subredes");
    Serial.println("  4. Problema de red entre dispositivos");
  }
  
  http.end();
}

void cambiarCalidad(int calidad) {
  sensor_t* s = esp_camera_sensor_get();
  if (s != NULL) {
    s->set_quality(s, calidad);
    Serial.printf("✓ Calidad de imagen ajustada a %d\n", calidad);
    Serial.println("  (0 = mejor calidad, 63 = peor calidad)");
  } else {
    Serial.println("✗ ERROR: No se pudo acceder al sensor de la cámara");
  }
}

void cambiarResolucion(framesize_t tamaño) {
  sensor_t* s = esp_camera_sensor_get();
  if (s != NULL) {
    s->set_framesize(s, tamaño);
    String nombreTamaño;
    switch(tamaño) {
      case FRAMESIZE_VGA:  nombreTamaño = "VGA (640x480)";    break;
      case FRAMESIZE_SVGA: nombreTamaño = "SVGA (800x600)";   break;
      case FRAMESIZE_HD:   nombreTamaño = "HD (1280x720)";    break;
      case FRAMESIZE_UXGA: nombreTamaño = "UXGA (1600x1200)"; break;
      default: nombreTamaño = "Desconocido";
    }
    Serial.println("✓ Resolución cambiada a: " + nombreTamaño);
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
  float porcentajeUso = (ramUsada * 100.0) / ramTotal;
  
  Serial.printf("║ RAM Total: %lu KB                                          ║\n", ramTotal);
  Serial.printf("║ RAM Libre: %lu KB                                          ║\n", ramLibre);
  Serial.printf("║ RAM Usada: %lu KB (%.1f%%)                                  ║\n", ramUsada, porcentajeUso);
  Serial.println("║                                                            ║");
  Serial.print("║ PSRAM: ");
  if (psramFound()) {
    uint32_t psramLibre = ESP.getFreePsram() / 1024;
    Serial.printf("Disponible (%lu KB libres)                    ║\n", psramLibre);
  } else {
    Serial.println("No disponible                                     ║");
  }
  
  Serial.println("╚════════════════════════════════════════════════════════════╝\n");
}

void mostrarUptime() {
  unsigned long segundos = (millis() - tiempoInicio) / 1000;
  unsigned long minutos = segundos / 60;
  unsigned long horas = minutos / 60;
  unsigned long dias = horas / 24;
  
  Serial.println("\n╔════════════════════════════════════════════════════════════╗");
  Serial.println("║              TIEMPO DE OPERACIÓN                           ║");
  Serial.println("╠════════════════════════════════════════════════════════════╣");
  Serial.printf("║ Días: %-52lu ║\n", dias);
  Serial.printf("║ Horas: %-51lu ║\n", horas % 24);
  Serial.printf("║ Minutos: %-49lu ║\n", minutos % 60);
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
    
    // Esperar el intervalo, verificando entrada serial
    for(int i = 0; i < intervalo * 10; i++) {
      if (Serial.available() > 0) {
        Serial.read(); // Limpiar buffer
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
  
  // Configuración de pines
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
  
  // Configuración de imagen
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  
  // Configuración según disponibilidad de PSRAM
  if (psramFound()) {
    config.frame_size = FRAMESIZE_VGA;  // 640x480 - Óptimo para señal débil
    config.jpeg_quality = 15;           // Calidad media
    config.fb_count = 2;                // 2 buffers con PSRAM
    Serial.println("  PSRAM detectado - Usando configuración optimizada");
  } else {
    config.frame_size = FRAMESIZE_VGA;
    config.jpeg_quality = 20;
    config.fb_count = 1;
    Serial.println("  Sin PSRAM - Usando configuración básica");
  }

  // Inicializar cámara
  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("  Error al inicializar cámara: 0x%x\n", err);
    return false;
  }

  // Configurar sensor
  sensor_t* s = esp_camera_sensor_get();
  if (s != NULL) {
    s->set_brightness(s, 0);      // -2 a 2
    s->set_contrast(s, 0);        // -2 a 2
    s->set_saturation(s, 0);      // -2 a 2
    s->set_special_effect(s, 0);  // 0 = sin efecto
    s->set_whitebal(s, 1);        // Balance de blancos automático
    s->set_awb_gain(s, 1);        // Ganancia AWB
    s->set_wb_mode(s, 0);         // Modo balance blancos
    s->set_exposure_ctrl(s, 1);   // Control exposición automático
    s->set_aec2(s, 0);            // AEC DSP
    s->set_ae_level(s, 0);        // -2 a 2
    s->set_aec_value(s, 300);     // 0 a 1200
    s->set_gain_ctrl(s, 1);       // Control ganancia automático
    s->set_agc_gain(s, 0);        // 0 a 30
    s->set_gainceiling(s, (gainceiling_t)0);
    s->set_bpc(s, 0);             // Black pixel correction
    s->set_wpc(s, 1);             // White pixel correction
    s->set_raw_gma(s, 1);         // Gamma
    s->set_lenc(s, 1);            // Lens correction
    s->set_hmirror(s, 0);         // Espejo horizontal
    s->set_vflip(s, 0);           // Volteo vertical
    s->set_dcw(s, 1);             // Downsize
    s->set_colorbar(s, 0);        // Barra de color (0 = desactivado)
  }

  return true;
}

void conectarWiFi() {
  Serial.println("\nConectando a WiFi 'Funcionarios'...");
  
  WiFi.mode(WIFI_STA);
  WiFi.setTxPower(WIFI_POWER_19_5dBm); // Máxima potencia de transmisión
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
    
    // Clasificación de señal
    if (rssi > -50) {
      Serial.println("(Excelente)");
    } else if (rssi > -70) {
      Serial.println("(Buena)");
    } else if (rssi > -85) {
      Serial.println("(Regular - Considere acercar al router)");
    } else {
      Serial.println("(Mala - ACERQUE LA ESP32 AL ROUTER)");
      Serial.println("  ⚠ ADVERTENCIA: La señal débil puede causar fallos en la transmisión");
    }
    
    Serial.print("  Canal WiFi: ");
    Serial.println(WiFi.channel());
    Serial.print("  Gateway: ");
    Serial.println(WiFi.gatewayIP());
    
  } else {
    Serial.println("✗ ERROR: No se pudo conectar al WiFi");
    Serial.println("  Verifica que la red 'Funcionarios' esté disponible");
    Serial.println("  y que la contraseña sea correcta");
  }
}

void capturarYEnviar() {
  // Verificar WiFi
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("✗ ERROR: WiFi desconectado");
    return;
  }

  // Capturar imagen
  Serial.println("  → Capturando imagen...");
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("✗ ERROR: Fallo al capturar imagen");
    return;
  }

  Serial.printf("  → Imagen capturada: %d bytes (%dx%d)\n", fb->len, fb->width, fb->height);

  // Intentar enviar con reintentos
  bool enviado = false;
  for(int intento = 1; intento <= MAX_RETRY_ENVIO && !enviado; intento++) {
    if (intento > 1) {
      Serial.printf("  → Reintento %d de %d...\n", intento, MAX_RETRY_ENVIO);
      delay(2000); // Espera entre reintentos
    }
    
    HTTPClient http;
    http.begin(serverUrl);
    http.setTimeout(HTTP_TIMEOUT);
    http.setReuse(false);

    // Generar boundary único
    String boundary = "----ESP32CAM" + String(random(10000, 99999));
    http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);
    http.addHeader("Connection", "close");

    // Construir cuerpo de la petición
    String bodyStart = "--" + boundary + "\r\n";
    bodyStart += "Content-Disposition: form-data; name=\"imagen\"; filename=\"esp32cam.jpg\"\r\n";
    bodyStart += "Content-Type: image/jpeg\r\n\r\n";
    
    String bodyEnd = "\r\n--" + boundary + "--\r\n";

    int totalLen = bodyStart.length() + fb->len + bodyEnd.length();
    http.addHeader("Content-Length", String(totalLen));

    // Crear buffer completo
    uint8_t* buffer = (uint8_t*)malloc(totalLen);
    if (buffer == NULL) {
      Serial.println("✗ ERROR: Memoria insuficiente para crear el buffer");
      esp_camera_fb_return(fb);
      return;
    }

    // Copiar datos al buffer
    memcpy(buffer, bodyStart.c_str(), bodyStart.length());
    memcpy(buffer + bodyStart.length(), fb->buf, fb->len);
    memcpy(buffer + bodyStart.length() + fb->len, bodyEnd.c_str(), bodyEnd.length());

    // Enviar
    Serial.println("  → Enviando al servidor...");
    int httpCode = http.POST(buffer, totalLen);

    // Liberar buffer
    free(buffer);

    // Procesar respuesta
    if (httpCode > 0) {
      Serial.printf("  ✓ Respuesta HTTP: %d\n", httpCode);
      
      if (httpCode == 200) {
        String respuesta = http.getString();
        Serial.println("\n  ═══ RESPUESTA DEL SERVIDOR ═══");
        Serial.println("  " + respuesta);
        
        // Analizar respuesta JSON
        if (respuesta.indexOf("\"coincidencia\":true") > 0) {
          Serial.println("\n  ╔════════════════════════════════════════╗");
          Serial.println("  ║    ✓✓✓ ACCESO CONCEDIDO ✓✓✓            ║");
          Serial.println("  ╚════════════════════════════════════════╝");
          
          // Indicación visual de éxito
          for (int i = 0; i < 6; i++) {
            digitalWrite(LED_STATUS, HIGH);
            delay(100);
            digitalWrite(LED_STATUS, LOW);
            delay(100);
          }
          
          capturasExitosas++;
          
        } else {
          Serial.println("\n  ╔════════════════════════════════════════╗");
          Serial.println("  ║    ✗✗✗ ACCESO DENEGADO ✗✗✗             ║");
          Serial.println("  ╚════════════════════════════════════════╝");
          
          // Indicación visual de rechazo
          for (int i = 0; i < 3; i++) {
            digitalWrite(LED_STATUS, HIGH);
            delay(300);
            digitalWrite(LED_STATUS, LOW);
            delay(300);
          }
        }
        
        enviado = true;
        
      } else {
        Serial.printf("  ⚠ Código HTTP inesperado: %d\n", httpCode);
      }
      
    } else {
      Serial.printf("  ✗ Error HTTP: %s\n", http.errorToString(httpCode).c_str());
    }

    http.end();
  }

  // Liberar frame buffer
  esp_camera_fb_return(fb);
  
  // Mensaje final si no se pudo enviar
  if (!enviado) {
    Serial.println("\n  ╔════════════════════════════════════════╗");
    Serial.println("  ║  ⚠ NO SE PUDO ENVIAR LA IMAGEN         ║");
    Serial.println("  ╚════════════════════════════════════════╝");
    Serial.println("\n  Recomendaciones:");
    Serial.println("  1. Verifica que el servidor esté corriendo: 10.2.13.193");
    Serial.println("  2. Ejecuta: PING para probar conectividad");
    Serial.println("  3. Ejecuta: DIAG para diagnóstico completo");
    Serial.println("  4. Verifica que el Firewall no esté bloqueando");
    Serial.println("  5. Acerca la ESP32-CAM al router WiFi");
  }
}
// ```

// ## 📋 **CARACTERÍSTICAS DE LA VERSIÓN 1.0:**

// ✅ **Banner profesional** con información del sistema
// ✅ **Comandos organizados** y documentados
// ✅ **Sistema de estadísticas** (capturas totales, exitosas, tasa de éxito)
// ✅ **Manejo robusto de errores** con mensajes claros
// ✅ **Diagnóstico completo** de red y sistema
// ✅ **Código limpio** y bien comentado
// ✅ **Optimizado** para red "Funcionarios" del SENA
// ✅ **Sistema de reintentos** para envío de imágenes
// ✅ **Indicadores visuales** (LED) para estado
// ✅ **Limpieza de buffer** para imágenes frescas
// ✅ **Versión documentada** con fecha y autor

// ## 🎯 **COMANDOS PRINCIPALES v1.0:**
// ```
// 1         → Capturar imagen
// STATUS    → Ver estado completo
// STATS     → Ver estadísticas
// DIAG      → Diagnóstico de red
// PING      → Probar servidor
// HELP      → Ver todos los comandos
// VERSION   → Ver versión del sistema