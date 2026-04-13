Modelos de dlib (no versionados en git por tamaño).

Opción rápida (macOS/Linux, con curl o wget y bunzip2):

  cd app/Services/face-recognition/models
  chmod +x download-models.sh
  ./download-models.sh

Manual: descargar en esta carpeta y descomprimir los .bz2:

1) shape_predictor_68_face_landmarks.dat
   https://github.com/davisking/dlib-models/raw/master/shape_predictor_68_face_landmarks.dat.bz2
   (o desde http://dlib.net/files/shape_predictor_68_face_landmarks.dat.bz2)
   bunzip2 shape_predictor_68_face_landmarks.dat.bz2

2) dlib_face_recognition_resnet_model_v1.dat
   https://github.com/davisking/dlib-models/raw/master/dlib_face_recognition_resnet_model_v1.dat.bz2
   bunzip2 dlib_face_recognition_resnet_model_v1.dat.bz2
