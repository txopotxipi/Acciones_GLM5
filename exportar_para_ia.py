import os
import sys
import time
from pathlib import Path
from datetime import datetime

# ==========================================
# CONFIGURACIÓN DE EXCLUSIONES INTELIGENTES
# ==========================================

# Carpetas de dependencias, caché, builds y sistema que NUNCA deben leerse
IGNORE_DIRS = {
    '.git', '.svn', '.hg', '.idea', '.vscode', '.vs', '.eclipse',
    'node_modules', 'bower_components', 'vendor', 'venv', '.venv', 'env', '.env', '.tox',
    '__pycache__', '.pytest_cache', '.mypy_cache', '.ruff_cache', 'htmlcov', '.coverage',
    'dist', 'build', 'out', 'target', 'bin', 'obj', 'debug', 'release',
    '.next', '.nuxt', '.svelte-kit', '.astro', '.cache', '.parcel-cache', '.turbo',
    'migrations', 'uploads', 'tmp', 'temp', 'logs', 'storage',
    '.gradle', '.mvn', 'Pods', '.dart_tool', '.pub-cache'
}

# Archivos específicos que no aportan lógica de negocio y ensucian el contexto de la IA
IGNORE_FILES = {
    'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'composer.lock', 
    'Pipfile.lock', 'poetry.lock', 'Cargo.lock', 'Gemfile.lock',
    '.DS_Store', 'Thumbs.db', 'desktop.ini', 
    'npm-debug.log', 'yarn-debug.log', 'yarn-error.log', 'pnpm-debug.log'
}

# Extensiones binarias, multimedia, compiladas o de fuentes
IGNORE_EXTS = {
    # Imágenes
    '.png', '.jpg', '.jpeg', '.gif', '.bmp', '.tiff', '.ico', '.webp', '.avif', '.heic', '.psd', '.ai',
    # Fuentes tipográficas
    '.woff', '.woff2', '.ttf', '.eot', '.otf',
    # Audio / Video
    '.mp3', '.mp4', '.wav', '.ogg', '.webm', '.mov', '.avi', '.flac', '.mkv',
    # Documentos / Archivos comprimidos
    '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', 
    '.zip', '.rar', '.7z', '.tar', '.gz', '.bz2',
    # Ejecutables / Binarios / Bases de Datos
    '.exe', '.dll', '.so', '.dylib', '.bin', '.dat', '.db', '.sqlite', '.sqlite3', '.mdb', 
    '.pyc', '.pyo', '.class', '.o', '.a', '.lib', '.map'
}

def is_binary(file_path, block_size=2048):
    """
    Detecta si un archivo es binario buscando bytes nulos (\x00).
    Si no tiene bytes nulos en los primeros 2KB, se considera texto.
    """
    try:
        with open(file_path, 'rb') as f:
            block = f.read(block_size)
            if b'\0' in block:
                return True
            return False
    except Exception:
        return True

def format_size(size_bytes):
    for unit in ['B', 'KB', 'MB', 'GB']:
        if size_bytes < 1024:
            return f"{size_bytes:.2f} {unit}"
        size_bytes /= 1024
    return f"{size_bytes:.2f} TB"

def export_project_to_text(project_path, output_file):
    root_dir = Path(project_path).resolve()
    if not root_dir.exists() or not root_dir.is_dir():
        print(f"❌ ERROR: La ruta '{root_dir}' no existe o no es un directorio.")
        sys.exit(1)

    out_path = Path(output_file).resolve()
    
    print(f"\n🚀 Iniciando escaneo de: {root_dir}")
    print(f"💾 Guardando en: {out_path}\n")
    print("-" * 60)

    stats = {
        'files_read': 0,
        'files_skipped_bin': 0,
        'files_skipped_ignored': 0,
        'total_size': 0,
        'skipped_large': 0
    }

    try:
        with open(out_path, 'w', encoding='utf-8') as out_f:
            # Cabecera
            header = f"""
{'='*80}
PROYECTO: {root_dir.name}
RUTA COMPLETA: {root_dir}
FECHA DE EXPORTACIÓN: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
{'='*80}
"""
            out_f.write(header)
            
            # Recorrido del árbol de directorios
            for dirpath, dirnames, filenames in os.walk(root_dir):
                # Filtrar carpetas in-situ para evitar que os.walk entre en ellas
                dirnames[:] = [d for d in dirnames if d not in IGNORE_DIRS]
                
                # Ordenar para que la salida sea predecible y ordenada alfabéticamente
                dirnames.sort()
                filenames.sort()
                
                for filename in filenames:
                    file_path = Path(dirpath) / filename
                    
                    # 1. Ignorar archivos específicos
                    if filename in IGNORE_FILES:
                        stats['files_skipped_ignored'] += 1
                        continue
                        
                    # 2. Ignorar extensiones binarias/conocidas
                    if file_path.suffix.lower() in IGNORE_EXTS:
                        stats['files_skipped_bin'] += 1
                        continue
                        
                    # 3. Ignorar archivos minificados (suelen ser gigantes e ilegibles para la IA)
                    if filename.endswith('.min.js') or filename.endswith('.min.css'):
                        stats['files_skipped_ignored'] += 1
                        continue

                    # 4. Detectar si es binario (por si coló alguna extensión rara)
                    if is_binary(file_path):
                        stats['files_skipped_bin'] += 1
                        continue
                        
                    # 5. Leer el archivo
                    try:
                        file_size = file_path.stat().st_size
                        
                        # Advertencia de archivos gigantes (> 1.5 MB)
                        if file_size > 1.5 * 1024 * 1024:
                            print(f"⚠️  OMISO (Demasiado grande {format_size(file_size)}): {file_path.relative_to(root_dir)}")
                            stats['skipped_large'] += 1
                            continue

                        with open(file_path, 'r', encoding='utf-8', errors='replace') as in_f:
                            content = in_f.read()
                            
                        rel_path = file_path.relative_to(root_dir)
                        
                        # Escribir en el TXT
                        out_f.write(f"\n{'='*80}\n")
                        out_f.write(f"ARCHIVO: {rel_path}\n")
                        out_f.write(f"{'='*80}\n")
                        out_f.write(content)
                        out_f.write("\n")
                        
                        stats['files_read'] += 1
                        stats['total_size'] += file_size
                        
                        print(f"✅ {rel_path}")
                        
                    except Exception as e:
                        print(f"❌ Error leyendo {file_path.relative_to(root_dir)}: {e}")
                        stats['files_skipped_ignored'] += 1

            # Resumen final
            summary = f"""
\n{'='*80}
RESUMEN DE EXPORTACIÓN
{'='*80}
✅ Archivos de código leídos: {stats['files_read']}
⏭️  Archivos ignorados (config/lock/min): {stats['files_skipped_ignored']}
🚫 Archivos binarios/multimedia omitidos: {stats['files_skipped_bin']}
⚠️  Archivos gigantes (>1.5MB) omitidos: {stats['skipped_large']}
📦 Tamaño total del texto generado: {format_size(stats['total_size'])}
{'='*80}
"""
            out_f.write(summary)
            
    except Exception as e:
        print(f"\n💥 Error fatal al crear el archivo de salida: {e}")
        sys.exit(1)

    print("-" * 60)
    print(f"\n🎉 ¡PROCESO COMPLETADO!")
    print(f"📄 Se han extraído {stats['files_read']} archivos.")
    print(f"📂 Archivo generado: {out_path}")
    print(f"⚖️  Tamaño final: {format_size(stats['total_size'])}")
    
    if stats['total_size'] > 3 * 1024 * 1024:
        print("\n⚠️  ADVERTENCIA: El archivo pesa más de 3 MB.")
        print("   Es posible que supere el límite de tokens de algunas IAs.")
        print("   Considera revisar el código o subirlo por partes si da error.")

if __name__ == "__main__":
    print("="*60)
    print("  📦 EXPORTADOR UNIVERSAL DE PROYECTOS A TEXTO (IA-READY)")
    print("="*60)
    print("\nEste script copiará TODO tu código fuente en un solo archivo.")
    print("Ignorará automáticamente imágenes, binarios, node_modules, .git,")
    print("archivos .lock y minificados para optimizar el contexto de la IA.\n")
    
    # Solicitar ruta
    while True:
        ruta = input("📁 Escribe la ruta de tu proyecto (o arrastra la carpeta aquí): ").strip()
        # Limpiar comillas si el usuario arrastró la carpeta
        ruta = ruta.strip('"').strip("'")
        
        if not ruta:
            print("❌ La ruta no puede estar vacía.")
            continue
            
        if os.path.exists(ruta) and os.path.isdir(ruta):
            break
        else:
            print(f"❌ ERROR: La ruta '{ruta}' no existe o no es una carpeta. Inténtalo de nuevo.\n")
            
    # Nombre del archivo de salida
    nombre_proyecto = os.path.basename(os.path.normpath(ruta))
    sugerencia = f"{nombre_proyecto}_codigo_ia.txt"
    print(f"\n💾 El archivo se guardará como: {sugerencia}")
    cambiar = input("¿Quieres cambiar el nombre? (s/n) [n]: ").strip().lower()
    
    if cambiar == 's':
        archivo_salida = input("Escribe el nuevo nombre (con .txt al final): ").strip()
        if not archivo_salida.endswith('.txt'):
            archivo_salida += '.txt'
    else:
        archivo_salida = sugerencia
        
    export_project_to_text(ruta, archivo_salida)
    
    # Pausa final
    print("\nPresiona ENTER para salir...")
    input()