# reconocer.py
import os
import cv2
import dlib
import numpy as np
import mysql.connector
from datetime import datetime, timedelta
import sys
import json
from dotenv import load_dotenv

# Configuración de rutas y parámetros
# BASE_DIR apunta a la raíz del proyecto Laravel.
# Estructura:
#   face/
#     app/Services/face-recognition/src/reconocer.py  (este archivo)
# Subimos cuatro niveles: src -> face-recognition -> Services -> app -> face
BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "..", ".."))

# Cargar variables de entorno desde el .env de Laravel (ubicado en la raíz del proyecto)
ENV_PATH = os.path.join(BASE_DIR, ".env")
load_dotenv(ENV_PATH)

# Carpeta donde Laravel guarda las fotos de docentes (disco local privado)
# => face/storage/app/private/faces
REGISTRO_PATH = os.path.join(BASE_DIR, "storage", "app", "private", "faces")

DISPOSITIVO_ID = 1  # ID del dispositivo ESP32-CAM en tu tabla
UMBRAL_SIMILITUD = 0.6  # Ajusta según necesidades (menor = más estricto)
TIEMPO_MINIMO_ENTRE_EVENTOS = timedelta(minutes=1)  # 1 minuto mínimo entre entrada y salida

# Configuración de logs
LOG_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.logs')
LOG_FILE = os.path.join(LOG_DIR, f"reconocer_{datetime.now().strftime('%Y-%m-%d')}.log")

# Crear directorio de logs si no existe
if not os.path.exists(LOG_DIR):
    os.makedirs(LOG_DIR)

def log_message(message):
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]
    log_entry = f"---- PROCESO INICIADO ----\n[{timestamp}] {message}\n"
    with open(LOG_FILE, 'a') as f:
        f.write(log_entry)

def log_end(message):
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]
    log_entry = f"[{timestamp}] {message}\n---- PROCESO FINALIZADO ----\n\n"
    with open(LOG_FILE, 'a') as f:
        f.write(log_entry)

# Inicializar detectores de Dlib
log_message("Inicializando detectores de Dlib")
detector = dlib.get_frontal_face_detector()

# Rutas a los modelos de Dlib (asumimos que están en services/face-recognition/models)
MODELS_DIR = os.path.join(os.path.dirname(__file__), "..", "models")
SHAPE_PREDICTOR_PATH = os.path.join(MODELS_DIR, "shape_predictor_68_face_landmarks.dat")
FACE_RECOGNIZER_PATH = os.path.join(MODELS_DIR, "dlib_face_recognition_resnet_model_v1.dat")

log_message(f"Usando SHAPE_PREDICTOR_PATH: {SHAPE_PREDICTOR_PATH}")
log_message(f"Usando FACE_RECOGNIZER_PATH: {FACE_RECOGNIZER_PATH}")

shape_predictor = dlib.shape_predictor(SHAPE_PREDICTOR_PATH)
face_recognizer = dlib.face_recognition_model_v1(FACE_RECOGNIZER_PATH)
log_message("Detectores de Dlib inicializados correctamente")

# Conexión a MySQL (leyendo de variables de entorno, alineadas con el .env de Laravel)
log_message("Estableciendo conexión con MySQL usando variables de entorno")

db_host = os.environ.get("DB_HOST", '127.0.0.1')
db_user = os.environ.get("DB_USERNAME", 'root')
db_password = os.environ.get("DB_PASSWORD", '')
db_name = os.environ.get("DB_DATABASE",'face')
db_port = int(os.environ.get("DB_PORT", 3306))

log_message(f"DB_HOST={db_host}, DB_NAME={db_name}, DB_USER={db_user}, DB_PORT={db_port}")

try:
    conn = mysql.connector.connect(
        host=db_host,
        user=db_user,
        password=db_password,
        database=db_name,
        port=db_port,
    )
    cursor = conn.cursor()
    log_message("Conexión a MySQL establecida correctamente")
except Exception as e:
    log_message(f"ERROR al conectar a MySQL: {e}")
    raise

def obtener_descriptor_rostro(imagen_path):
    """Extrae el descriptor facial (vector de características) de una imagen.
    Retorna el descriptor o un diccionario de error."""
    try:
        log_message(f"Intentando leer imagen: {imagen_path}")
        img = cv2.imread(imagen_path)
        if img is None:
            error_msg = f"No se pudo leer la imagen: '{imagen_path}'. Verifique la ruta y los permisos."
            log_message(f"ERROR: {error_msg}")
            return {'error_code': 'IMAGE_READ_ERROR', 'message': error_msg}
            
        log_message(f"Imagen leída correctamente: {imagen_path}")
        img_rgb = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
        log_message("Convertida imagen a RGB")
        
        caras = detector(img_rgb)
        log_message(f"Caras detectadas: {len(caras)}")
        
        if not caras:
            error_msg = f"No se detectaron rostros en la imagen: '{imagen_path}'."
            log_message(f"ERROR: {error_msg}")
            return {'error_code': 'NO_FACE_DETECTED_IN_IMAGE', 'message': error_msg}
            
        log_message("Procesando primera cara detectada")
        shape = shape_predictor(img_rgb, caras[0])
        descriptor = face_recognizer.compute_face_descriptor(img_rgb, shape)
        log_message("Descriptor facial generado correctamente")
        
        return np.array(descriptor)
    except Exception as e:
        error_msg = f"Error inesperado al procesar '{imagen_path}': {e}"
        log_message(f"ERROR: {error_msg}")
        return {'error_code': 'PROCESSING_ERROR', 'message': error_msg}

def cargar_rostros_conocidos():
    """Carga los rostros registrados desde la base de datos (tabla users de Laravel)"""
    log_message("Cargando rostros conocidos desde la tabla users")
    # Mapeo a esquema Laravel:
    # - id       -> id
    # - nombre   -> name
    # - foto     -> photo (ruta relativa en storage/app/private/faces)
    # - activo   -> is_active
    cursor.execute("SELECT id, fullname, photo FROM users WHERE is_active = 1")
    personas = cursor.fetchall()
    log_message(f"Usuarios activos encontrados en la base de datos: {len(personas)}")
    
    descriptores = []
    ids = []
    nombres = []
    
    for persona in personas:
        try:
            nombre_archivo_foto = persona[2]  # users.photo
            log_message(f"Procesando usuario: {persona[1]} con foto(ruta): {nombre_archivo_foto}")
            
            # En Laravel guardamos la ruta relativa (por ejemplo: "faces/archivo.jpg") en users.photo.
            # Si la ruta ya es relativa, la combinamos con REGISTRO_PATH, que apunta a storage/app/private/faces.
            # Si la ruta incluye "faces/", evitamos duplicarlo.
            rel_path = nombre_archivo_foto.lstrip("/\\")
            if rel_path.startswith("faces/") or rel_path.startswith("faces\\"):
                rel_path = rel_path.split("/", 1)[-1].split("\\", 1)[-1]

            img_path = os.path.join(REGISTRO_PATH, rel_path)
            log_message(f"Ruta completa de la imagen: {img_path}")
            
            descriptor_result = obtener_descriptor_rostro(img_path)
            
            if isinstance(descriptor_result, dict) and 'error_code' in descriptor_result:
                log_message(f"Advertencia al cargar rostro {persona[1]}: {descriptor_result['message']}")
            else:
                descriptores.append(descriptor_result)
                ids.append(persona[0])
                nombres.append(persona[1])
                log_message(f"Rostro {persona[1]} cargado correctamente")
        except Exception as e:
            log_message(f"Error general al cargar rostro {persona[1]}: {e}")
    
    log_message(f"Rostros cargados exitosamente: {len(descriptores)}")
    return descriptores, ids, nombres

def verificar_ultimo_evento(persona_id):
    """Verifica el último evento del usuario en la tabla events (Laravel) y determina la acción apropiada"""
    try:
        # events: id, user_id, device_id, ambient_id, event_type, timestamps
        cursor.execute(
            """
            SELECT event_type, created_at
            FROM events
            WHERE user_id = %s
              AND device_id = %s
              AND event_type IN ('entry', 'exit')
            ORDER BY created_at DESC
            LIMIT 1
            """,
            (persona_id, DISPOSITIVO_ID),
        )
        ultimo_evento = cursor.fetchone()
        
        if not ultimo_evento:
            return 'entrada'  # No hay eventos previos, registrar entrada
            
        tipo_ultimo, fecha_ultimo = ultimo_evento
        ahora = datetime.now()
        diferencia = ahora - fecha_ultimo
        
        if tipo_ultimo == 'entry':
            if diferencia >= TIEMPO_MINIMO_ENTRE_EVENTOS:
                return 'salida'  # Ha pasado suficiente tiempo, permitir salida (mantenemos etiqueta en español)
            else:
                tiempo_restante = TIEMPO_MINIMO_ENTRE_EVENTOS - diferencia
                return f'salida_no_valida_{tiempo_restante.total_seconds()}'
        elif tipo_ultimo == 'exit':
            if diferencia >= TIEMPO_MINIMO_ENTRE_EVENTOS:
                return 'entrada'  # Ha pasado suficiente tiempo, permitir entrada
            else:
                tiempo_restante = TIEMPO_MINIMO_ENTRE_EVENTOS - diferencia
                return f'entrada_no_valida_{tiempo_restante.total_seconds()}'
        else:
            return 'entrada'  # Por defecto si el último evento no es entrada/salida
            
    except Exception as e:
        log_message(f"Error al verificar último evento: {e}")
        return 'entrada'  # Por defecto registrar entrada si hay error

def registrar_evento(persona_id, tipo, detalles=None):
    """Registra un evento en la tabla events de Laravel con verificación de tiempo mínimo"""
    try:
        if persona_id is not None:
            accion = verificar_ultimo_evento(persona_id)
            
            if accion.startswith('salida_no_valida'):
                tiempo_restante = float(accion.split('_')[-1])
                minutos = int(tiempo_restante // 60)
                segundos = int(tiempo_restante % 60)
                mensaje = f"Salida no válida. Tiempo mínimo no cumplido. Espere {minutos} min {segundos} seg"
                log_message(mensaje)
                
                
                
                
                # En el esquema Laravel no tenemos tipos de evento 'salida_no_valida',
                # así que solo devolvemos el error sin insertar nada en la tabla events.
                return {
                    'error': mensaje,
                    'error_code': 'MIN_TIME_NOT_MET',
                    'tiempo_restante': tiempo_restante,
                    'accion_solicitada': 'salida',
                    'accion_permitida': 'entrada'
                }
            
            if accion.startswith('entrada_no_valida'):
                tiempo_restante = float(accion.split('_')[-1])
                minutos = int(tiempo_restante // 60)
                segundos = int(tiempo_restante % 60)
                mensaje = f"Entrada no válida. Tiempo mínimo no cumplido. Espere {minutos} min {segundos} seg"
                log_message(mensaje)
                
                # Igual que arriba, no insertamos en events para este caso.
                return {
                    'error': mensaje,
                    'error_code': 'MIN_TIME_NOT_MET',
                    'tiempo_restante': tiempo_restante,
                    'accion_solicitada': 'entrada',
                    'accion_permitida': 'salida'
                }
            
            tipo = accion  # Usar el tipo determinado por la verificación ('entrada' / 'salida')
        
        # Mapear el tipo usado en el script ('entrada' / 'salida') a event_type de Laravel ('entry' / 'exit')
        if tipo == 'entrada':
            event_type = 'entry'
        elif tipo == 'salida':
            event_type = 'exit'
        else:
            # Por defecto, si llega algo distinto, registramos como entry.
            event_type = 'entry'

        # Ya no insertamos en la tabla events desde Python, 
        # esto se manejara ahora en el Laravel Controller
        log_message(f"Evento validado correctamente (sin inserción en DB a petición de Laravel) - event_type: {event_type}, user_id: {persona_id}, detalles: {detalles}")
        return {'success': True, 'tipo': event_type}
        
    except Exception as e:
        log_message(f"Error al registrar evento: {e}")
        return {'error': str(e), 'error_code': 'EVENT_REGISTRATION_ERROR'}

def comparar_rostros(descriptor1, descriptor2):
    """Calcula la distancia entre dos descriptores faciales"""
    log_message("Comparando descriptores faciales")
    distancia = np.linalg.norm(descriptor1 - descriptor2)
    log_message(f"Distancia calculada: {distancia}")
    return distancia

def procesar_imagen(imagen_path):
    """Procesa una imagen y devuelve los resultados en formato JSON"""
    try:
        log_message(f"Inicio del procesamiento de imagen: {imagen_path}")
        
        # Cargar rostros conocidos
        conocidos_descriptores, conocidos_ids, conocidos_nombres = cargar_rostros_conocidos()
        
        if not conocidos_descriptores:
            error_msg = 'No hay rostros registrados válidos en la base de datos o no se pudieron cargar.'
            log_message(f"ERROR: {error_msg}")
            return json.dumps({'error': error_msg, 'error_code': 'NO_VALID_REGISTERED_FACES'})
        
        # Obtener descriptor de la imagen desconocida
        unknown_descriptor_result = obtener_descriptor_rostro(imagen_path)
        
        if isinstance(unknown_descriptor_result, dict) and 'error_code' in unknown_descriptor_result:
            log_message(f"Error al obtener descriptor: {unknown_descriptor_result['message']}")
            registrar_evento(None, "denegado", unknown_descriptor_result['message'])
            return json.dumps({'error': unknown_descriptor_result['message'], 'error_code': unknown_descriptor_result['error_code']})
        
        unknown_descriptor = unknown_descriptor_result
        log_message("Descriptor de la imagen desconocida obtenido correctamente")
            
        # Comparar con rostros conocidos
        log_message("Iniciando comparación con rostros conocidos")
        distancias = []
        for i, conocido in enumerate(conocidos_descriptores):
            dist = comparar_rostros(conocido, unknown_descriptor)
            distancias.append(dist)
            log_message(f"Distancia con rostro {conocidos_nombres[i]}: {dist}")
        
        mejor_idx = np.argmin(distancias)
        mejor_distancia = distancias[mejor_idx]
        log_message(f"Mejor distancia encontrada: {mejor_distancia} (índice: {mejor_idx})")
        
        if mejor_distancia <= UMBRAL_SIMILITUD:
            persona_id = conocidos_ids[mejor_idx]
            nombre = conocidos_nombres[mejor_idx]
            
            log_message(f"Coincidencia encontrada: {nombre} (ID: {persona_id})")
            
            # Registrar evento con verificación de tiempo mínimo
            resultado_evento = registrar_evento(persona_id, "entrada", f"Distancia: {mejor_distancia:.4f}")
            
            if 'error' in resultado_evento:
                # Hubo un error al registrar (tiempo mínimo no cumplido)
                resultado = {
                    'coincidencia': True,
                    'id': persona_id,
                    'nombre': nombre,
                    'distancia': float(mejor_distancia),
                    'umbral': float(UMBRAL_SIMILITUD),
                    'error': resultado_evento['error'],
                    'error_code': resultado_evento['error_code'],
                    'tiempo_restante': resultado_evento.get('tiempo_restante', 0),
                    'accion_solicitada': resultado_evento.get('accion_solicitada'),
                    'accion_permitida': resultado_evento.get('accion_permitida')
                }
                return json.dumps(resultado)
            
            # Registro exitoso
            resultado = {
                'coincidencia': True,
                'id': persona_id,
                'nombre': nombre,
                'distancia': float(mejor_distancia),
                'umbral': float(UMBRAL_SIMILITUD),
                'tipo_evento': resultado_evento['tipo']
            }
            log_message(f"Resultado de coincidencia: {json.dumps(resultado)}")
            return json.dumps(resultado)
        else:
            log_message(f"No se encontró coincidencia válida. Mejor distancia: {mejor_distancia} (umbral: {UMBRAL_SIMILITUD})")
            registrar_evento(None, "denegado", f"Mejor distancia: {mejor_distancia:.4f}")
            
            resultado = {
                'coincidencia': False,
                'distancia': float(mejor_distancia),
                'umbral': float(UMBRAL_SIMILITUD)
            }
            log_message(f"Resultado sin coincidencia: {json.dumps(resultado)}")
            return json.dumps(resultado)
            
    except Exception as e:
        error_msg = f"Error: {str(e)}"
        log_message(f"ERROR: {error_msg}")
        registrar_evento(None, "denegado", error_msg)
        return json.dumps({'error': error_msg, 'error_code': 'GENERAL_PROCESSING_ERROR'})

if __name__ == "__main__":
    try:
        log_message("Script reconocer.py iniciado")
        if len(sys.argv) > 1:
            log_message(f"Parámetro recibido: {sys.argv[1]}")
            resultado = procesar_imagen(sys.argv[1])
            log_message("Resultado del procesamiento listo para imprimir")
            print(resultado)
            log_end("Proceso completado exitosamente")
        else:
            error_msg = 'No se proporcionó ruta de imagen'
            log_message(f"ERROR: {error_msg}")
            print(json.dumps({'error': error_msg, 'error_code': 'NO_IMAGE_PATH_PROVIDED'}))
            log_end("Proceso finalizado con errores")
    except Exception as e:
        error_msg = f'Error en ejecución: {str(e)}'
        log_message(f"ERROR: {error_msg}")
        print(json.dumps({'error': error_msg, 'error_code': 'PYTHON_EXECUTION_ERROR'}))
        log_end("Proceso finalizado con errores")
    finally:
        try:
            log_message("Cerrando conexión a MySQL")
            cursor.close()
            conn.close()
            log_message("Conexión a MySQL cerrada correctamente")
        except Exception as e:
            log_message(f"Error al cerrar la conexión/cursor: {e}")