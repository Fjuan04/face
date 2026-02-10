# FACE (Flujo de Ambientes Controlados Electrónicamente)

![Status](https://img.shields.io/badge/Status-En_Desarrollo-yellow)
![Laravel](https://img.shields.io/badge/Backend-Laravel_11-red)
![React](https://img.shields.io/badge/Frontend-React_18-blue)
![Python](https://img.shields.io/badge/AI-Python_3-3776AB)
![IoT](https://img.shields.io/badge/Hardware-ESP32-green)

> **Modernización del control de acceso institucional mediante validación biométrica y gestión en tiempo real.**

## 📖 Descripción

**FACE** es un ecosistema integral de hardware y software diseñado para automatizar la gestión de ambientes de formación. El proyecto elimina la dependencia de llaves físicas, sustituyéndolas por un sistema de **Reconocimiento Facial** impulsado por Inteligencia Artificial.

El sistema no solo controla la apertura física de puertas mediante dispositivos IoT, sino que ofrece una interfaz de administración basada en una **vista aérea (tipo dron)** para monitorear en tiempo real la ocupación y disponibilidad de los salones, integrándose directamente con la plataforma institucional **Cronode**.

### 🚀 Características Principales
* **Acceso Biométrico:** Validación de identidad mediante visión artificial (Python + OpenCV/Dlib) con detección de prueba de vida.
* **Gestión Visual de Ambientes:** Dashboard en React con mapa interactivo aéreo para visualizar el estado de las aulas.
* **Integración Hardware IoT:** Comunicación con microcontroladores ESP32 y servomotores para el accionamiento físico de cerraduras.
* **Conexión con Cronode:** Validación de permisos basada en la programación académica trimestral oficial.
* **Logs y Trazabilidad:** Registro histórico inmutable de ingresos y salidas.
* **Infraestructura Híbrida:** Despliegue en servidor virtualizado (Proxmox) con acceso seguro vía Cloudflare Tunnel.

---

## 🛠️ Arquitectura del Sistema

El sistema opera bajo una arquitectura de microservicios híbrida:

1.  **Frontend (React):** Interfaz de usuario para la captura de fotos y visualización del mapa.
2.  **Backend (Laravel API):** Orquestador central. Maneja la autenticación (Sanctum), base de datos y reglas de negocio.
3.  **Microservicio AI (Python):** Scripting ejecutado desde el backend para procesar vectores biométricos.
4.  **Hardware (IoT):** Dispositivos ESP32 que reciben órdenes de apertura vía HTTP/WebSocket.

```mermaid
graph TD
    User[Usuario/Cámara] -->|HTTPS| Frontend[React App]
    Frontend -->|API REST| Backend[Laravel API]
    Backend -->|Consulta| Cronode[API Externa Cronode]
    Backend -->|Ejecuta| Python[Script Python AI]
    Python -->|Valida| Models[Modelos Biométricos]
    Backend -->|Orden Apertura| ESP32[Módulo IoT]
    ESP32 -->|Acciona| Servo[Cerradura Puerta]
