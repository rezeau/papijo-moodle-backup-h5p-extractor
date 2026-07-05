$ErrorActionPreference = "Stop"

$python = if ($env:PYTHON) { $env:PYTHON } else { "python" }

& $python -m PyInstaller `
    --onefile `
    --windowed `
    --clean `
    --name PapiJo-Moodle-Backup-H5P-Extractor `
    .\src\papijo_moodle_backup_h5p_extractor.py
