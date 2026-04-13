#!/usr/bin/env bash
# Descarga los modelos de dlib en esta carpeta (ejecutar tras clonar el repo).
set -euo pipefail
cd "$(dirname "$0")"

fetch() {
  local url="$1"
  local out="${2:-$(basename "$url")}"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL -o "$out" "$url"
  else
    wget -q -O "$out" "$url"
  fi
}

echo "Descargando shape_predictor_68_face_landmarks.dat.bz2 ..."
fetch "https://github.com/davisking/dlib-models/raw/master/shape_predictor_68_face_landmarks.dat.bz2"
echo "Descargando dlib_face_recognition_resnet_model_v1.dat.bz2 ..."
fetch "https://github.com/davisking/dlib-models/raw/master/dlib_face_recognition_resnet_model_v1.dat.bz2"

echo "Descomprimiendo..."
bunzip2 -f shape_predictor_68_face_landmarks.dat.bz2
bunzip2 -f dlib_face_recognition_resnet_model_v1.dat.bz2

echo "Listo. Archivos:"
ls -lh ./*.dat
