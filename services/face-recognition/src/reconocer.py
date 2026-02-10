# reconocer.py
import os
import cv2
import dlib
import numpy as np
import mysql.connector
from datetime import datetime, timedelta
import sys
import json

# Configuración
REGISTRO_PATH = "rostros/"
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
shape_predictor = dlib.shape_predictor("shape_predictor_68_face_landmarks.dat")
face_recognizer = dlib.face_recognition_model_v1("dlib_face_recognition_resnet_model_v1.dat")
log_message("Detectores de Dlib inicializados correctamente")

# Conexión a MySQL
log_message("Estableciendo conexión con MySQL")
conn = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",
    database="control_facial"
)
cursor = conn.cursor()
log_message("Conexión a MySQL establecida correctamente")

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
    """Carga los rostros registrados desde la base de datos"""
    log_message("Cargando rostros conocidos desde la base de datos")
    cursor.execute("SELECT id, nombre, foto FROM personas WHERE activo = 1")
    personas = cursor.fetchall()
    log_message(f"Personas encontradas en la base de datos: {len(personas)}")
    
    descriptores = []
    ids = []
    nombres = []
    
    for persona in personas:
        try:
            nombre_archivo_foto = persona[2]
            log_message(f"Procesando persona: {persona[1]} con foto: {nombre_archivo_foto}")
            
            if not (nombre_archivo_foto.lower().endswith('.jpg') or \
                    nombre_archivo_foto.lower().endswith('.jpeg') or \
                    nombre_archivo_foto.lower().endswith('.png')):
                nombre_archivo_foto += '.jpg'
                log_message(f"Extensión ajustada para: {nombre_archivo_foto}")
            
            img_path = os.path.join(REGISTRO_PATH, nombre_archivo_foto)
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
    """Verifica el último evento de la persona y determina la acción apropiada"""
    try:
        cursor.execute(
            "SELECT tipo, fecha FROM eventos WHERE persona_id = %s  AND tipo ='entrada' OR tipo ='SALIDA' ORDER BY fecha DESC LIMIT 1",
            (persona_id,)
        )
        ultimo_evento = cursor.fetchone()
        
        if not ultimo_evento:
            return 'entrada'  # No hay eventos previos, registrar entrada
            
        tipo_ultimo, fecha_ultimo = ultimo_evento
        ahora = datetime.now()
        diferencia = ahora - fecha_ultimo
        
        if tipo_ultimo == 'entrada':
            if diferencia >= TIEMPO_MINIMO_ENTRE_EVENTOS:
                return 'salida'  # Ha pasado suficiente tiempo, permitir salida
            else:
                tiempo_restante = TIEMPO_MINIMO_ENTRE_EVENTOS - diferencia
                return f'salida_no_valida_{tiempo_restante.total_seconds()}'
        elif tipo_ultimo == 'salida':
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
    """Registra un evento en la base de datos con verificación de tiempo mínimo"""
    try:
        if persona_id is not None:
            accion = verificar_ultimo_evento(persona_id)
            
            if accion.startswith('salida_no_valida'):
                tiempo_restante = float(accion.split('_')[-1])
                minutos = int(tiempo_restante // 60)
                segundos = int(tiempo_restante % 60)
                mensaje = f"Salida no válida. Tiempo mínimo no cumplido. Espere {minutos} min {segundos} seg"
                log_message(mensaje)
                
                
                
                
                cursor.execute(
                    "INSERT INTO eventos (persona_id, tipo, fecha, detalles) VALUES (%s, %s, %s, %s)",
                    (persona_id, 'salida_no_valida', datetime.now(), mensaje)
                )
                conn.commit()
                
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
                
                cursor.execute(
                    "INSERT INTO eventos (persona_id, tipo, fecha, detalles) VALUES (%s, %s, %s, %s)",
                    (persona_id, 'entrada_no_valida', datetime.now(), mensaje)
                )
                conn.commit()
                
                return {
                    'error': mensaje,
                    'error_code': 'MIN_TIME_NOT_MET',
                    'tiempo_restante': tiempo_restante,
                    'accion_solicitada': 'entrada',
                    'accion_permitida': 'salida'
                }
            
            tipo = accion  # Usar el tipo determinado por la verificación
        
        log_message(f"Registrando evento - Tipo: {tipo}, Persona ID: {persona_id}, Detalles: {detalles}")
        cursor.execute(
            "INSERT INTO eventos (persona_id, tipo, fecha, detalles) VALUES (%s, %s, %s, %s)",
            (persona_id, tipo, datetime.now(), detalles)
        )
        conn.commit()
        log_message("Evento registrado correctamente en la base de datos")
        return {'success': True, 'tipo': tipo}
        
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