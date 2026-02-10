# FACE (Flujo de Ambientes Controlados Electrónicamente)

![Status](https://img.shields.io/badge/Status-En_Desarrollo-yellow)
![Laravel](https://img.shields.io/badge/Backend-Laravel_12-red)
![React](https://img.shields.io/badge/Frontend-React_19-blue)
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


## 🔄 Flujo de Datos y Arquitectura

El sistema sigue un flujo de control centralizado en el Backend, donde el dispositivo IoT actúa como cliente y el servidor procesa la lógica pesada.

1.  **Captura:** El **ESP32-CAM** toma la foto en la puerta y la envía vía HTTP POST a la API de Laravel.
2.  **Pre-procesamiento:** **Laravel** recibe la imagen, la procesa (convertir a Base64/Almacenamiento temporal) y prepara los argumentos para el análisis.
3.  **Reconocimiento (AI):** Laravel invoca un subproceso ejecutando el script de **Python**. Este script recibe la imagen, realiza la comparación biométrica y devuelve el ID del usuario identificado.
4.  **Validación Administrativa:** Simultáneamente (o secuencialmente), Laravel consulta la API externa **Cronode** para realizar el cambio de estado del ambiente.
5.  **Decisión:** Laravel cruza los datos (¿Es quien dice ser?).
6.  **Respuesta:** Laravel envía una respuesta JSON (`{access: true}`) al ESP32.
7.  **Acción:** El ESP32 procesa la respuesta y activa el servomotor si el acceso es concedido.

```mermaid
sequenceDiagram
    participant Hardware as ESP32-CAM (Puerta)
    participant Backend as Laravel API
    participant AI as Script Python
    participant Cronode as API Cronode
    participant DB as Base de Datos

    Note over Hardware: Detecta persona<br/>y toma foto
    Hardware->>Backend: POST /api/access-request (Imagen)
    activate Backend
    
    Note right of Backend: Procesa Imagen<br/>(Base64/Temp)
    
    Backend->>AI: Ejecuta Script (Argumentos)
    activate AI
    AI-->>Backend: Retorna: ID Usuario / Match
    deactivate AI

    alt Usuario No Reconocido
        Backend-->>Hardware: Respuesta: {access: false, msg: "Desconocido"}
    else Usuario Reconocido
        Backend->>Cronode: GET /validate-schedule (User, Room)
        activate Cronode
        Cronode-->>Backend: Retorna: Autorizado / Denegado
        deactivate Cronode
        
        Backend->>DB: Registra Intento de Acceso (Log)
        
        alt Permiso Válido
            Backend-->>Hardware: Respuesta: {access: true}
            Hardware->>Hardware: Mover Servo (Abrir)
        else Permiso Inválido
            Backend-->>Hardware: Respuesta: {access: false, msg: "Sin clase"}
        end
    end
    deactivate Backend
